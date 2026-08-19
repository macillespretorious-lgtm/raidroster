<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['error' => 'No such guild']);
    exit;
}

auth_session_start();
if (!auth_is_authed()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user = auth_user();
$role = resolve_guild_role($tenant, $user['id']);
if (!role_at_least($role, 'raid_management')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';
$pdo    = db_connect();

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function fetch_raid_owned($pdo, $guildId, $raidId) {
    $stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$raidId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_section_owned($pdo, $guildId, $sectionId) {
    $stmt = $pdo->prepare(
        'SELECT s.* FROM raid_sections s JOIN raids r ON r.id = s.raid_id
         WHERE s.id = ? AND r.guild_id = ?'
    );
    $stmt->execute([$sectionId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// A table's parent is either a section (top-level) or a column-group which itself lives in
// another table (nested). Walk that chain up to a section to find the owning raid, re-validating
// tenant ownership at the section hop. Depth-capped as a guard against bad data.
function resolve_table_raidId($pdo, $guildId, $tableId, $depth = 0) {
    if ($depth > 6) return null;
    $stmt = $pdo->prepare('SELECT section_id, parent_group_id FROM raid_tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $tb = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tb) return null;

    if ($tb['section_id'] !== null) {
        $sec = fetch_section_owned($pdo, $guildId, (int)$tb['section_id']);
        return $sec ? (int)$sec['raid_id'] : null;
    }

    if ($tb['parent_group_id'] !== null) {
        $stmt = $pdo->prepare('SELECT table_id FROM raid_column_groups WHERE id = ?');
        $stmt->execute([$tb['parent_group_id']]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) return null;
        return resolve_table_raidId($pdo, $guildId, (int)$grp['table_id'], $depth + 1);
    }

    return null;
}

function fetch_table_owned($pdo, $guildId, $tableId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $tb = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tb) return null;
    $raidId = resolve_table_raidId($pdo, $guildId, $tableId);
    if ($raidId === null) return null;
    $tb['raid_id'] = $raidId;
    return $tb;
}

function fetch_column_owned($pdo, $guildId, $columnId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_columns WHERE id = ?');
    $stmt->execute([$columnId]);
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$col) return null;
    $tb = fetch_table_owned($pdo, $guildId, (int)$col['table_id']);
    if (!$tb) return null;
    $col['raid_id'] = $tb['raid_id'];
    return $col;
}

function fetch_row_owned($pdo, $guildId, $rowId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_rows WHERE id = ?');
    $stmt->execute([$rowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $tb = fetch_table_owned($pdo, $guildId, (int)$row['table_id']);
    if (!$tb) return null;
    $row['raid_id'] = $tb['raid_id'];
    return $row;
}

function fetch_group_owned($pdo, $guildId, $groupId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_column_groups WHERE id = ?');
    $stmt->execute([$groupId]);
    $grp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grp) return null;
    $raidId = resolve_table_raidId($pdo, $guildId, (int)$grp['table_id']);
    if ($raidId === null) return null;
    $grp['raid_id'] = $raidId;
    return $grp;
}

// Client sends the full desired id order for a sibling group; we renumber sort_order to
// match. Ids not actually belonging to $fkVal are silently dropped rather than trusted, so a
// stale/tampered order list can't move rows cross-scope. (Reparenting, when needed, is done
// by the caller before this runs.)
function reorder_siblings($pdo, $table, $fkCol, $fkVal, $orderedIds) {
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE $fkCol = ?");
    $stmt->execute([$fkVal]);
    $valid = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $upd = $pdo->prepare("UPDATE $table SET sort_order = ? WHERE id = ?");
    $order = 0;
    foreach ($orderedIds as $id) {
        $id = (int)$id;
        if (!in_array($id, $valid, true)) continue;
        $upd->execute([$order, $id]);
        $order++;
    }
}

function fetch_table_full($pdo, $tb) {
    $stmtC = $pdo->prepare('SELECT id, label, kind, width, header_color, group_id, header_colspan FROM raid_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmtC->fetchAll(PDO::FETCH_ASSOC));

    $stmtR = $pdo->prepare('SELECT id, label, kind, height FROM raid_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'height' => $r['height'] !== null ? (int)$r['height'] : null], $stmtR->fetchAll(PDO::FETCH_ASSOC));

    $stmtG = $pdo->prepare('SELECT * FROM raid_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmtG->execute([$tb['id']]);
    $groupRows = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $columnGroups = [];
    foreach ($groupRows as $g) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$g['id']]);
        $childTables = array_map(fn($ctb) => fetch_table_full($pdo, $ctb), $stmtGT->fetchAll(PDO::FETCH_ASSOC));
        $columnGroups[] = [
            'id' => (int)$g['id'],
            'parentGroupId' => $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null,
            'title' => $g['title'], 'color' => $g['color'],
            'tables' => $childTables,
        ];
    }

    $stmtCell = $pdo->prepare(
        'SELECT c.id, c.row_id, c.column_id, c.toon_id, c.note, t.main_name, t.class
         FROM raid_cells c LEFT JOIN toons t ON t.id = c.toon_id
         WHERE c.table_id = ?'
    );
    $stmtCell->execute([$tb['id']]);
    $cells = [];
    foreach ($stmtCell->fetchAll(PDO::FETCH_ASSOC) as $cell) {
        $cells[$cell['row_id'] . '_' . $cell['column_id']] = [
            'id'     => (int)$cell['id'],
            'toonId' => $cell['toon_id'],
            'name'   => $cell['main_name'],
            'class'  => $cell['class'],
            'note'   => $cell['note'],
        ];
    }

    $stmtM = $pdo->prepare('SELECT row_id, column_id, colspan FROM raid_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'], 'columnId' => (int)$m['column_id'], 'colspan' => (int)$m['colspan'],
    ], $stmtM->fetchAll(PDO::FETCH_ASSOC));

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'rowLabelWidth' => $tb['row_label_width'] !== null ? (int)$tb['row_label_width'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups, 'cells' => $cells,
        'cellMerges' => $cellMerges,
    ];
}

function fetch_raid_structure($pdo, $raidId) {
    $out = [];
    $stmt = $pdo->prepare('SELECT * FROM raid_sections WHERE raid_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmtT = $pdo->prepare('SELECT * FROM raid_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        $tables = array_map(fn($tb) => fetch_table_full($pdo, $tb), $stmtT->fetchAll(PDO::FETCH_ASSOC));
        $out[] = ['id' => (int)$sec['id'], 'kind' => $sec['kind'], 'title' => $sec['title'], 'tables' => $tables];
    }
    return $out;
}

if ($action === 'reorder') {
    $raidId     = (int)($body['raidId'] ?? 0);
    $raid       = fetch_raid_owned($pdo, $tenant['id'], $raidId);
    if (!$raid) fail(404, 'Raid not found');

    $kind       = $body['kind'] ?? '';
    $orderedIds = is_array($body['orderedIds'] ?? null) ? $body['orderedIds'] : [];
    $parentId   = (int)($body['parentId'] ?? 0);

    if ($kind === 'table') {
        $parentKind = ($body['parentKind'] ?? 'section') === 'group' ? 'group' : 'section';

        if ($parentKind === 'group') {
            $grp = fetch_group_owned($pdo, $tenant['id'], $parentId);
            if (!$grp || (int)$grp['raid_id'] !== $raidId) fail(404, 'Group not found');
            $fkCol = 'parent_group_id';
            $fkVal = $grp['id'];
        } else {
            $sec = fetch_section_owned($pdo, $tenant['id'], $parentId);
            if (!$sec || (int)$sec['raid_id'] !== $raidId) fail(404, 'Section not found');
            $fkCol = 'section_id';
            $fkVal = $sec['id'];
        }

        $otherCol = $fkCol === 'section_id' ? 'parent_group_id' : 'section_id';
        foreach ($orderedIds as $id) {
            $tb = fetch_table_owned($pdo, $tenant['id'], (int)$id);
            if (!$tb || (int)$tb['raid_id'] !== $raidId) continue;
            $currentFkVal = $tb[$fkCol];
            if ($currentFkVal === null || (int)$currentFkVal !== (int)$fkVal) {
                $upd = $pdo->prepare("UPDATE raid_tables SET $fkCol = ?, $otherCol = NULL WHERE id = ?");
                $upd->execute([$fkVal, $tb['id']]);
            }
        }
        reorder_siblings($pdo, 'raid_tables', $fkCol, $fkVal, $orderedIds);
    } elseif ($kind === 'column' || $kind === 'row') {
        $tb = fetch_table_owned($pdo, $tenant['id'], $parentId);
        if (!$tb || (int)$tb['raid_id'] !== $raidId) fail(404, 'Table not found');
        $table = $kind === 'column' ? 'raid_columns' : 'raid_rows';

        foreach ($orderedIds as $id) {
            $item = $kind === 'column'
                ? fetch_column_owned($pdo, $tenant['id'], (int)$id)
                : fetch_row_owned($pdo, $tenant['id'], (int)$id);
            if (!$item || (int)$item['raid_id'] !== $raidId) continue;
            if ((int)$item['table_id'] !== (int)$tb['id']) {
                $upd = $pdo->prepare("UPDATE $table SET table_id = ? WHERE id = ?");
                $upd->execute([$tb['id'], $item['id']]);
            }
        }
        reorder_siblings($pdo, $table, 'table_id', $tb['id'], $orderedIds);
    } elseif ($kind === 'group') {
        $tb = fetch_table_owned($pdo, $tenant['id'], $parentId);
        if (!$tb || (int)$tb['raid_id'] !== $raidId) fail(404, 'Table not found');

        foreach ($orderedIds as $id) {
            $grp = fetch_group_owned($pdo, $tenant['id'], (int)$id);
            if (!$grp || (int)$grp['raid_id'] !== $raidId) continue;
            if ((int)$grp['table_id'] !== (int)$tb['id']) {
                $upd = $pdo->prepare('UPDATE raid_column_groups SET table_id = ? WHERE id = ?');
                $upd->execute([$tb['id'], $grp['id']]);
            }
        }
        reorder_siblings($pdo, 'raid_column_groups', 'table_id', $tb['id'], $orderedIds);
    } else {
        fail(400, 'Invalid reorder kind');
    }

    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $raidId)]);
    exit;
}

fail(400, 'Unknown action');
