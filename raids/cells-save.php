<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/raid_fetch.php';
require_once __DIR__ . '/../includes/class_roles.php';
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
$pdo    = db_connect();
$action = $body['action'] ?? 'assign';

function fetch_cell_owned($pdo, $cellId, $guildId) {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.table_id, c.row_id, c.column_id FROM raid_cells c
         JOIN raid_tables tb ON tb.id = c.table_id
         JOIN raid_sections s ON s.id = tb.section_id
         JOIN raids r ON r.id = s.raid_id
         WHERE c.id = ? AND r.guild_id = ?'
    );
    $stmt->execute([$cellId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_toon_class($pdo, $guildId, $toonKind, $toonId) {
    if ($toonKind === 'main' && $toonId) {
        $stmt = $pdo->prepare('SELECT class FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['class'] : null;
    }
    if ($toonKind === 'alt' && $toonId) {
        $stmt = $pdo->prepare('SELECT class FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['class'] : null;
    }
    return null;
}

function toon_class_for($pdo, $guildId, $toonKind, $toonId, $pugClass) {
    if ($toonKind === 'pug') return $pugClass;
    return fetch_toon_class($pdo, $guildId, $toonKind, $toonId);
}

function toon_spec_for($pdo, $guildId, $toonKind, $toonId) {
    if ($toonKind === 'main' && $toonId) {
        $stmt = $pdo->prepare('SELECT main_spec FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['main_spec'] : null;
    }
    if ($toonKind === 'alt' && $toonId) {
        $stmt = $pdo->prepare('SELECT main_spec FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['main_spec'] : null;
    }
    return null;
}

// Checks class_restrict/max_count raid_rules scoped to (tableId, rowId, columnId) against the
// toon about to occupy that cell. $excludeCellIds keeps a toon's own current cell(s) -- the one
// being reassigned, or both sides of a move -- out of its own max_count tally.
function rule_violation($pdo, $tableId, $rowId, $columnId, $toonKind, $toonId, $toonClass, array $excludeCellIds, $pugName = null) {
    if (!$toonKind || (!$toonId && $toonKind !== 'pug')) return null; // clearing a cell never violates a rule

    $stmt = $pdo->prepare(
        "SELECT r.* FROM raid_rules r
         WHERE r.table_id = ?
           AND (r.scope = 'table' OR EXISTS (
               SELECT 1 FROM raid_rule_cells rc WHERE rc.rule_id = r.id AND rc.row_id = ? AND rc.column_id = ?
           ))"
    );
    $stmt->execute([$tableId, $rowId, $columnId]);
    $excludePlaceholders = implode(',', array_fill(0, count($excludeCellIds), '?'));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        if ($rule['rule_type'] === 'class_restrict') {
            $allowed = array_map('strtolower', array_map('trim', explode(',', (string)$rule['classes'])));
            $cls = strtolower(trim((string)$toonClass));
            if ($cls === '' || !in_array($cls, $allowed, true)) {
                return $rule['label'] ?: ('Only ' . $rule['classes'] . ' may be assigned here');
            }
        } elseif ($rule['rule_type'] === 'max_count') {
            $max = $rule['max_count'] !== null ? (int)$rule['max_count'] : 1;
            if ($toonKind === 'pug') {
                // PUGs have no toon_id, so identity for this check is their (trimmed,
                // case-insensitive) pug_name instead -- an unnamed PUG has no identity to
                // count against and can't be checked.
                $name = trim((string)$pugName);
                if ($name === '') continue;
                if ($rule['scope'] === 'table') {
                    $sql = "SELECT COUNT(*) FROM raid_cells WHERE table_id = ? AND toon_kind = 'pug' AND LOWER(TRIM(pug_name)) = LOWER(?) AND id NOT IN ($excludePlaceholders)";
                    $params = array_merge([$tableId, $name], $excludeCellIds);
                } else {
                    $sql = "SELECT COUNT(*) FROM raid_cells c
                            JOIN raid_rule_cells rc ON rc.row_id = c.row_id AND rc.column_id = c.column_id
                            WHERE rc.rule_id = ? AND c.table_id = ? AND c.toon_kind = 'pug' AND LOWER(TRIM(c.pug_name)) = LOWER(?) AND c.id NOT IN ($excludePlaceholders)";
                    $params = array_merge([$rule['id'], $tableId, $name], $excludeCellIds);
                }
            } else {
                if ($rule['scope'] === 'table') {
                    $sql = "SELECT COUNT(*) FROM raid_cells WHERE table_id = ? AND toon_kind = ? AND toon_id = ? AND id NOT IN ($excludePlaceholders)";
                    $params = array_merge([$tableId, $toonKind, $toonId], $excludeCellIds);
                } else {
                    $sql = "SELECT COUNT(*) FROM raid_cells c
                            JOIN raid_rule_cells rc ON rc.row_id = c.row_id AND rc.column_id = c.column_id
                            WHERE rc.rule_id = ? AND c.table_id = ? AND c.toon_kind = ? AND c.toon_id = ? AND c.id NOT IN ($excludePlaceholders)";
                    $params = array_merge([$rule['id'], $tableId, $toonKind, $toonId], $excludeCellIds);
                }
            }
            $stmtCnt = $pdo->prepare($sql);
            $stmtCnt->execute($params);
            $count = (int)$stmtCnt->fetchColumn();
            if ($count >= $max) {
                return $rule['label'] ?: ('This toon can only be assigned ' . $max . ' time' . ($max === 1 ? '' : 's') . ' here');
            }
        }
    }
    return null;
}

// Resolves a client-supplied toon reference against the guild's own tables so a raid_cells
// row can never be stamped with another guild's toon via a forged id. Returns null-fields
// (an empty slot) for anything that doesn't resolve, including an explicit clear.
function resolve_toon_fields($pdo, $guildId, $toonKind, $toonId, $pugName, $pugClass) {
    if ($toonKind === 'main' && $toonId) {
        $stmt = $pdo->prepare('SELECT id FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        if ($stmt->fetch()) return ['toon_kind' => 'main', 'toon_id' => $toonId, 'pug_name' => null, 'pug_class' => null];
    } elseif ($toonKind === 'alt' && $toonId) {
        $stmt = $pdo->prepare('SELECT id FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        if ($stmt->fetch()) return ['toon_kind' => 'alt', 'toon_id' => $toonId, 'pug_name' => null, 'pug_class' => null];
    } elseif ($toonKind === 'pug') {
        $name = trim((string)$pugName);
        if ($name !== '') return ['toon_kind' => 'pug', 'toon_id' => null, 'pug_name' => substr($name, 0, 60), 'pug_class' => $pugClass ? substr(trim($pugClass), 0, 30) : null];
    }
    return ['toon_kind' => 'main', 'toon_id' => null, 'pug_name' => null, 'pug_class' => null];
}

function fetch_cell_out($pdo, $cellId) {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.toon_id, c.toon_kind, c.pug_name, c.pug_class, c.role, c.marked,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class,
                COALESCE(t.main_spec, a.main_spec) AS toon_spec
         FROM raid_cells c
         LEFT JOIN toons t ON c.toon_kind = \'main\' AND t.id = c.toon_id
         LEFT JOIN toon_alts a ON c.toon_kind = \'alt\' AND a.id = c.toon_id
         WHERE c.id = ?'
    );
    $stmt->execute([$cellId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $isPug = $c['toon_kind'] === 'pug';
    $class = $isPug ? $c['pug_class'] : $c['toon_class'];
    $spec  = $isPug ? null : $c['toon_spec'];
    return [
        'id'            => (int)$c['id'],
        'toonKind'      => $c['toon_kind'],
        'toonId'        => $c['toon_id'],
        'pugName'       => $c['pug_name'],
        'pugClass'      => $c['pug_class'],
        'name'          => $isPug ? $c['pug_name'] : $c['toon_name'],
        'class'         => $class,
        'role'          => $class ? ($c['role'] ?: default_role_for_class($class, $spec)) : null,
        'roleConfirmed' => $c['role'] !== null,
        'marked'        => (bool)$c['marked'],
    ];
}

// Benched tables auto-grow: whenever the LAST general-kind row is fully occupied, one more row
// (and its cells) is appended so there's always exactly one spare trailing row. Deliberately
// only looks at the last row, not "every cell in the table" -- a bench filled out of order
// (some earlier slot left empty) must still grow once its bottom row fills, otherwise it can
// permanently get stuck one spare short. "Occupied"/general-only matches shrink_benched_table's
// own definition, so the two stay symmetric. "Empty" matches resolve_toon_fields()'s own
// definition of a cleared cell (main/null/null/null).
function last_general_row_order($pdo, $tableId) {
    $stmt = $pdo->prepare("SELECT MAX(sort_order) FROM raid_rows WHERE table_id = ? AND kind = 'general'");
    $stmt->execute([$tableId]);
    $v = $stmt->fetchColumn();
    return $v === null ? null : (int)$v;
}

function benched_table_full($pdo, $tableId) {
    $lastOrder = last_general_row_order($pdo, $tableId);
    if ($lastOrder === null) return false;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM raid_cells c JOIN raid_rows r ON r.id = c.row_id
         WHERE c.table_id = ? AND r.kind = 'general' AND r.sort_order = ?
           AND c.toon_kind = 'main' AND c.toon_id IS NULL"
    );
    $stmt->execute([$tableId, $lastOrder]);
    return (int)$stmt->fetchColumn() === 0;
}

function grow_benched_table($pdo, $tableId) {
    $stmt = $pdo->prepare("SELECT id FROM raid_columns WHERE table_id = ? AND kind != 'spacer' ORDER BY sort_order, id");
    $stmt->execute([$tableId]);
    $colIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (!$colIds) return;

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM raid_rows WHERE table_id = ?');
    $stmt->execute([$tableId]);
    $order = (int)$stmt->fetchColumn();
    $insR = $pdo->prepare("INSERT INTO raid_rows (table_id, label, sort_order, kind) VALUES (?, '', ?, 'general')");
    $insR->execute([$tableId, $order]);
    $newRowId = (int)$pdo->lastInsertId();

    $insC = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id) VALUES (?, ?, ?)');
    foreach ($colIds as $colId) $insC->execute([$tableId, $newRowId, $colId]);
}

// Mirror of grow_benched_table: when a player is cleared off the bench, trim any trailing
// empty rows back down to a single spare (never below 1 row total, and never touching a row
// that isn't part of the unbroken empty run at the very end -- an emptied middle slot stays
// put, since it's still a usable bench position, not excess capacity).
function shrink_benched_table($pdo, $tableId) {
    $stmt = $pdo->prepare(
        "SELECT r.id, SUM(CASE WHEN c.toon_kind = 'main' AND c.toon_id IS NULL THEN 0 ELSE 1 END) AS occupied
         FROM raid_rows r JOIN raid_cells c ON c.row_id = r.id
         WHERE r.table_id = ? AND r.kind = 'general' GROUP BY r.id ORDER BY r.sort_order DESC"
    );
    $stmt->execute([$tableId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) <= 1) return false;

    $trailingEmptyIds = [];
    foreach ($rows as $r) {
        if ((int)$r['occupied'] !== 0) break;
        $trailingEmptyIds[] = $r['id'];
    }
    $toDelete = array_slice($trailingEmptyIds, 1); // keep the last trailing-empty row as the one spare
    if (!$toDelete) return false;

    $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
    $pdo->prepare("DELETE FROM raid_rows WHERE id IN ($placeholders)")->execute($toDelete);
    return true;
}

function ensure_benched_capacity($pdo, $tableId) {
    $stmt = $pdo->prepare('SELECT kind FROM raid_tables WHERE id = ?');
    $stmt->execute([$tableId]);
    if ($stmt->fetchColumn() !== 'benched') return false;
    if (benched_table_full($pdo, $tableId)) {
        grow_benched_table($pdo, $tableId);
        return true;
    }
    return shrink_benched_table($pdo, $tableId);
}

function raid_id_for_table($pdo, $tableId) {
    $stmt = $pdo->prepare('SELECT s.raid_id FROM raid_tables tb JOIN raid_sections s ON s.id = tb.section_id WHERE tb.id = ?');
    $stmt->execute([$tableId]);
    return (int)$stmt->fetchColumn();
}

if ($action === 'bench_import') {
    $raidId = isset($body['raidId']) ? (int)$body['raidId'] : 0;
    $stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$raidId, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Raid not found']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT rt.id FROM raid_tables rt JOIN raid_sections s ON s.id = rt.section_id WHERE s.raid_id = ? AND rt.kind = 'benched' ORDER BY rt.id LIMIT 1");
    $stmt->execute([$raidId]);
    $tableId = $stmt->fetchColumn();
    if (!$tableId) {
        http_response_code(400);
        echo json_encode(['error' => "This raid's template has no Benched table"]);
        exit;
    }
    $tableId = (int)$tableId;

    $stmtEmpty = $pdo->prepare("SELECT id FROM raid_cells WHERE table_id = ? AND toon_kind = 'main' AND toon_id IS NULL ORDER BY row_id LIMIT 1");
    $stmtEmpty->execute([$tableId]);
    $cellId = $stmtEmpty->fetchColumn();
    if (!$cellId) {
        grow_benched_table($pdo, $tableId);
        $stmtEmpty->execute([$tableId]);
        $cellId = $stmtEmpty->fetchColumn();
    }
    if (!$cellId) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not create a bench slot']);
        exit;
    }
    $cellId = (int)$cellId;

    $resolved = resolve_toon_fields($pdo, $tenant['id'], $body['toonKind'] ?? null, $body['toonId'] ?? null, $body['pugName'] ?? null, $body['pugClass'] ?? null);
    $toonClass = toon_class_for($pdo, $tenant['id'], $resolved['toon_kind'], $resolved['toon_id'], $resolved['pug_class']);
    $role = array_key_exists('role', $body) ? $body['role'] : null;
    if (!valid_role_for_class($toonClass, $role)) $role = null;

    $stmt = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ?, role = ? WHERE id = ?');
    $stmt->execute([$resolved['toon_id'], $resolved['toon_kind'], $resolved['pug_name'], $resolved['pug_class'], $role, $cellId]);

    ensure_benched_capacity($pdo, $tableId);

    // A grown table means new rows/cells exist that the client's in-memory copy doesn't have
    // yet, so return the whole raid tree (same shape clear_section/clear_all already return)
    // rather than just the one cell.
    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $raidId)]);
    exit;
}

if ($action === 'move') {
    $fromCellId = isset($body['fromCellId']) ? (int)$body['fromCellId'] : 0;
    $toCellId   = isset($body['toCellId']) ? (int)$body['toCellId'] : 0;
    $fromCell = fetch_cell_owned($pdo, $fromCellId, $tenant['id']);
    $toCell   = fetch_cell_owned($pdo, $toCellId, $tenant['id']);
    if (!$fromCell || !$toCell) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT toon_id, toon_kind, pug_name, pug_class, role FROM raid_cells WHERE id = ? FOR UPDATE');
    $stmt->execute([$fromCellId]);
    $from = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->execute([$toCellId]);
    $to = $stmt->fetch(PDO::FETCH_ASSOC);

    // 'to's occupant is moving into fromCell, and vice versa -- check each destination against
    // the toon that's actually landing there, excluding both cells from the max_count tally.
    $toClass = toon_class_for($pdo, $tenant['id'], $to['toon_kind'], $to['toon_id'], $to['pug_class']);
    $fromClass = toon_class_for($pdo, $tenant['id'], $from['toon_kind'], $from['toon_id'], $from['pug_class']);

    $violation = rule_violation($pdo, $fromCell['table_id'], $fromCell['row_id'], $fromCell['column_id'], $to['toon_kind'], $to['toon_id'], $toClass, [$fromCellId, $toCellId], $to['pug_name'])
        ?? rule_violation($pdo, $toCell['table_id'], $toCell['row_id'], $toCell['column_id'], $from['toon_kind'], $from['toon_id'], $fromClass, [$fromCellId, $toCellId], $from['pug_name']);
    if ($violation) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['error' => $violation]);
        exit;
    }

    $upd = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ?, role = ? WHERE id = ?');
    $upd->execute([$to['toon_id'], $to['toon_kind'], $to['pug_name'], $to['pug_class'], $to['role'], $fromCellId]);
    $upd->execute([$from['toon_id'], $from['toon_kind'], $from['pug_name'], $from['pug_class'], $from['role'], $toCellId]);
    $pdo->commit();

    $grew = ensure_benched_capacity($pdo, $fromCell['table_id']);
    $grew = ensure_benched_capacity($pdo, $toCell['table_id']) || $grew;

    $response = ['success' => true, 'from' => fetch_cell_out($pdo, $fromCellId), 'to' => fetch_cell_out($pdo, $toCellId)];
    if ($grew) $response['sections'] = fetch_raid_structure($pdo, raid_id_for_table($pdo, $fromCell['table_id']));
    echo json_encode($response);
    exit;
}

if ($action === 'clear_section') {
    $sectionId = isset($body['sectionId']) ? (int)$body['sectionId'] : 0;
    $stmt = $pdo->prepare(
        'SELECT s.id, s.raid_id FROM raid_sections s JOIN raids r ON r.id = s.raid_id WHERE s.id = ? AND r.guild_id = ?'
    );
    $stmt->execute([$sectionId, $tenant['id']]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$section) {
        http_response_code(404);
        echo json_encode(['error' => 'Section not found']);
        exit;
    }

    $stmtT = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id = ?');
    $stmtT->execute([$sectionId]);
    $tableIds = [];
    foreach ($stmtT->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        collect_table_ids($pdo, $tid, $tableIds);
    }
    clear_cells_for_tables($pdo, $tableIds);
    foreach ($tableIds as $tid) ensure_benched_capacity($pdo, $tid);

    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $section['raid_id'])]);
    exit;
}

if ($action === 'clear_all') {
    $raidId = isset($body['raidId']) ? (int)$body['raidId'] : 0;
    $stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$raidId, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Raid not found']);
        exit;
    }

    $stmtS = $pdo->prepare('SELECT id FROM raid_sections WHERE raid_id = ?');
    $stmtS->execute([$raidId]);
    $tableIds = [];
    foreach ($stmtS->fetchAll(PDO::FETCH_COLUMN) as $sectionId) {
        $stmtT = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id = ?');
        $stmtT->execute([$sectionId]);
        foreach ($stmtT->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            collect_table_ids($pdo, $tid, $tableIds);
        }
    }
    clear_cells_for_tables($pdo, $tableIds);
    foreach ($tableIds as $tid) ensure_benched_capacity($pdo, $tid);
    $pdo->prepare('DELETE FROM raid_pool WHERE raid_id = ?')->execute([$raidId]);

    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $raidId), 'pool' => []]);
    exit;
}

if ($action === 'mark') {
    $cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
    if (!fetch_cell_owned($pdo, $cellId, $tenant['id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $marked = !empty($body['marked']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE raid_cells SET marked = ? WHERE id = ?');
    $stmt->execute([$marked, $cellId]);
    echo json_encode(['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)]);
    exit;
}

if ($action === 'setRole') {
    $cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
    if (!fetch_cell_owned($pdo, $cellId, $tenant['id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT toon_kind, toon_id, pug_class, role FROM raid_cells WHERE id = ?');
    $stmt->execute([$cellId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $class = toon_class_for($pdo, $tenant['id'], $row['toon_kind'], $row['toon_id'], $row['pug_class']);
    $spec = toon_spec_for($pdo, $tenant['id'], $row['toon_kind'], $row['toon_id']);
    $current = $row['role'] ?: default_role_for_class($class, $spec);
    $next = $class ? cycle_role_for_class($class, $current) : null;
    $stmt = $pdo->prepare('UPDATE raid_cells SET role = ? WHERE id = ?');
    $stmt->execute([$next, $cellId]);
    echo json_encode(['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)]);
    exit;
}

if ($action === 'set_swap_note') {
    $raidId = isset($body['raidId']) ? (int)$body['raidId'] : 0;
    $stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$raidId, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Raid not found']);
        exit;
    }

    $tableId = isset($body['tableId']) ? (int)$body['tableId'] : 0;
    $stmt = $pdo->prepare('SELECT kind FROM raid_tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $kind = $stmt->fetchColumn();
    if ($kind !== 'swaps') {
        http_response_code(404);
        echo json_encode(['error' => 'Swaps table not found']);
        exit;
    }

    // Verify the table actually belongs to this raid (walk every top-level table's tree).
    $stmtS = $pdo->prepare('SELECT id FROM raid_sections WHERE raid_id = ?');
    $stmtS->execute([$raidId]);
    $allIds = [];
    foreach ($stmtS->fetchAll(PDO::FETCH_COLUMN) as $sectionId) {
        $stmtT = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id = ?');
        $stmtT->execute([$sectionId]);
        foreach ($stmtT->fetchAll(PDO::FETCH_COLUMN) as $tid) collect_table_ids($pdo, $tid, $allIds);
    }
    if (!in_array($tableId, $allIds, true)) {
        http_response_code(404);
        echo json_encode(['error' => 'Swaps table not found']);
        exit;
    }

    $playerMainToonId = (string)($body['playerMainToonId'] ?? '');
    if ($playerMainToonId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing player']);
        exit;
    }
    $note = isset($body['note']) ? trim((string)$body['note']) : null;
    if ($note === '') $note = null;
    if ($note !== null && !in_array($note, ['Before', 'After'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'When must be Before or After']);
        exit;
    }
    $bossLabel = isset($body['bossLabel']) ? substr(trim((string)$body['bossLabel']), 0, 60) : null;
    if ($bossLabel === '') $bossLabel = null;

    if ($note === null && $bossLabel === null) {
        $pdo->prepare('DELETE FROM raid_swap_notes WHERE table_id = ? AND player_main_toon_id = ?')->execute([$tableId, $playerMainToonId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO raid_swap_notes (table_id, player_main_toon_id, note, boss_label) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE note = VALUES(note), boss_label = VALUES(boss_label)'
        );
        $stmt->execute([$tableId, $playerMainToonId, $note, $bossLabel]);
    }

    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $raidId)]);
    exit;
}

// default: assign
$cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
$cell = fetch_cell_owned($pdo, $cellId, $tenant['id']);
if (!$cell) {
    http_response_code(404);
    echo json_encode(['error' => 'Cell not found']);
    exit;
}
$resolved = resolve_toon_fields($pdo, $tenant['id'], $body['toonKind'] ?? null, $body['toonId'] ?? null, $body['pugName'] ?? null, $body['pugClass'] ?? null);

$toonClass = toon_class_for($pdo, $tenant['id'], $resolved['toon_kind'], $resolved['toon_id'], $resolved['pug_class']);
$violation = rule_violation($pdo, $cell['table_id'], $cell['row_id'], $cell['column_id'], $resolved['toon_kind'], $resolved['toon_id'], $toonClass, [$cellId], $resolved['pug_name']);
if ($violation) {
    http_response_code(422);
    echo json_encode(['error' => $violation]);
    exit;
}

$role = array_key_exists('role', $body) ? $body['role'] : null;
if (!valid_role_for_class($toonClass, $role)) $role = null;

$stmt = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ?, role = ? WHERE id = ?');
$stmt->execute([$resolved['toon_id'], $resolved['toon_kind'], $resolved['pug_name'], $resolved['pug_class'], $role, $cellId]);

$grew = ensure_benched_capacity($pdo, $cell['table_id']);

$response = ['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)];
if ($grew) $response['sections'] = fetch_raid_structure($pdo, raid_id_for_table($pdo, $cell['table_id']));
echo json_encode($response);
