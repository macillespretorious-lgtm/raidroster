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
if (!role_at_least($role, 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';
$pdo    = db_connect();

$SECTION_KINDS = [
    'combined' => ['roster', 'assignments'],
    'separate' => ['roster', 'tank', 'healer', 'misc'],
];

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function fetch_template($pdo, $guildId, $templateId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_templates WHERE id = ? AND guild_id = ?');
    $stmt->execute([$templateId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_section_owned($pdo, $guildId, $sectionId) {
    $stmt = $pdo->prepare(
        'SELECT s.*, t.guild_id, t.assignment_style FROM raid_template_sections s
         JOIN raid_templates t ON t.id = s.template_id
         WHERE s.id = ? AND t.guild_id = ?'
    );
    $stmt->execute([$sectionId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_table_owned($pdo, $guildId, $tableId) {
    $stmt = $pdo->prepare(
        'SELECT tb.*, t.guild_id FROM raid_template_tables tb
         JOIN raid_template_sections s ON s.id = tb.section_id
         JOIN raid_templates t ON t.id = s.template_id
         WHERE tb.id = ? AND t.guild_id = ?'
    );
    $stmt->execute([$tableId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_column_owned($pdo, $guildId, $columnId) {
    $stmt = $pdo->prepare(
        'SELECT c.*, t.guild_id FROM raid_template_columns c
         JOIN raid_template_tables tb ON tb.id = c.table_id
         JOIN raid_template_sections s ON s.id = tb.section_id
         JOIN raid_templates t ON t.id = s.template_id
         WHERE c.id = ? AND t.guild_id = ?'
    );
    $stmt->execute([$columnId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_row_owned($pdo, $guildId, $rowId) {
    $stmt = $pdo->prepare(
        'SELECT r.*, t.guild_id FROM raid_template_rows r
         JOIN raid_template_tables tb ON tb.id = r.table_id
         JOIN raid_template_sections s ON s.id = tb.section_id
         JOIN raid_templates t ON t.id = s.template_id
         WHERE r.id = ? AND t.guild_id = ?'
    );
    $stmt->execute([$rowId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_group_owned($pdo, $guildId, $groupId) {
    $stmt = $pdo->prepare(
        'SELECT g.*, t.guild_id FROM raid_template_column_groups g
         JOIN raid_template_tables tb ON tb.id = g.table_id
         JOIN raid_template_sections s ON s.id = tb.section_id
         JOIN raid_templates t ON t.id = s.template_id
         WHERE g.id = ? AND t.guild_id = ?'
    );
    $stmt->execute([$groupId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function next_sort_order($pdo, $table, $fkCol, $fkVal) {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM $table WHERE $fkCol = ?");
    $stmt->execute([$fkVal]);
    return (int)$stmt->fetchColumn();
}

function move_sibling($pdo, $table, $fkCol, $fkVal, $id, $direction) {
    $stmt = $pdo->prepare("SELECT id, sort_order FROM $table WHERE $fkCol = ? ORDER BY sort_order, id");
    $stmt->execute([$fkVal]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $idx = null;
    foreach ($rows as $i => $r) { if ((int)$r['id'] === (int)$id) { $idx = $i; break; } }
    if ($idx === null) return;
    $swapWith = $direction === 'up' ? $idx - 1 : $idx + 1;
    if ($swapWith < 0 || $swapWith >= count($rows)) return;
    $a = $rows[$idx]; $b = $rows[$swapWith];
    $upd = $pdo->prepare("UPDATE $table SET sort_order = ? WHERE id = ?");
    $upd->execute([$b['sort_order'], $a['id']]);
    $upd->execute([$a['sort_order'], $b['id']]);
}

// Drag-and-drop reordering: client sends the full desired id order for a sibling group,
// we renumber sort_order to match. Ids not actually belonging to $fkVal are silently
// dropped rather than trusted, so a stale/tampered order list can't move rows cross-scope.
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

function fetch_structure($pdo, $templateId) {
    $out = [];

    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sections as $sec) {
        $stmt = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmt->execute([$sec['id']]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tablesOut = [];
        foreach ($tables as $tb) {
            $stmt = $pdo->prepare('SELECT id, label, sort_order, kind, width, header_color, group_id FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
            $stmt->execute([$tb['id']]);
            $columns = array_map(fn($c) => [
                'id' => (int)$c['id'],
                'label' => $c['label'],
                'kind' => $c['kind'],
                'width' => $c['width'] !== null ? (int)$c['width'] : null,
                'headerColor' => $c['header_color'],
                'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            $stmt = $pdo->prepare('SELECT id, label, sort_order, kind FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
            $stmt->execute([$tb['id']]);
            $rows = array_map(fn($r) => [
                'id' => (int)$r['id'],
                'label' => $r['label'],
                'kind' => $r['kind'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            $stmt = $pdo->prepare('SELECT id, parent_group_id, title, color, sort_order FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
            $stmt->execute([$tb['id']]);
            $columnGroups = array_map(fn($g) => [
                'id' => (int)$g['id'],
                'parentGroupId' => $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null,
                'title' => $g['title'],
                'color' => $g['color'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            $tablesOut[] = [
                'id' => (int)$tb['id'],
                'title' => $tb['title'],
                'headerColor' => $tb['header_color'],
                'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
                'rowLabelWidth' => $tb['row_label_width'] !== null ? (int)$tb['row_label_width'] : null,
                'columns' => $columns,
                'rows' => $rows,
                'columnGroups' => $columnGroups,
            ];
        }

        $out[] = [
            'id' => (int)$sec['id'],
            'kind' => $sec['kind'],
            'title' => $sec['title'],
            'tables' => $tablesOut,
        ];
    }

    return $out;
}

function respond_structure($pdo, $templateId) {
    echo json_encode(['success' => true, 'sections' => fetch_structure($pdo, $templateId)]);
    exit;
}

if ($action === 'add_section') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    $kind       = $body['kind'] ?? '';
    $title      = substr(trim($body['title'] ?? ''), 0, 100);
    $template   = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    $allowed = $SECTION_KINDS[$template['assignment_style']] ?? [];
    if (!in_array($kind, $allowed, true)) fail(400, 'Invalid section kind for this template\'s assignment style');
    if (!$title) fail(400, 'Title is required');

    $order = next_sort_order($pdo, 'raid_template_sections', 'template_id', $templateId);
    $stmt = $pdo->prepare('INSERT INTO raid_template_sections (template_id, kind, title, sort_order) VALUES (?, ?, ?, ?)');
    $stmt->execute([$templateId, $kind, $title, $order]);
    respond_structure($pdo, $templateId);
}

if ($action === 'update_section') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    if (!$title) fail(400, 'Title is required');
    $stmt = $pdo->prepare('UPDATE raid_template_sections SET title = ? WHERE id = ?');
    $stmt->execute([$title, $sec['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'delete_section') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $stmt = $pdo->prepare('DELETE FROM raid_template_sections WHERE id = ?');
    $stmt->execute([$sec['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'move_section') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    move_sibling($pdo, 'raid_template_sections', 'template_id', $sec['template_id'], $sec['id'], $body['direction'] ?? '');
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'add_table') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['sectionId'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    if (!$title) fail(400, 'Title is required');
    $order = next_sort_order($pdo, 'raid_template_tables', 'section_id', $sec['id']);
    $stmt = $pdo->prepare('INSERT INTO raid_template_tables (section_id, title, sort_order) VALUES (?, ?, ?)');
    $stmt->execute([$sec['id'], $title, $order]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'update_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    if (!$title) fail(400, 'Title is required');
    $headerColor = array_key_exists('headerColor', $body) ? ($body['headerColor'] ?: null) : $tb['header_color'];
    $colWidth    = array_key_exists('defaultColumnWidth', $body) ? ($body['defaultColumnWidth'] !== null && $body['defaultColumnWidth'] !== '' ? (int)$body['defaultColumnWidth'] : null) : $tb['default_column_width'];
    $labelWidth  = array_key_exists('rowLabelWidth', $body) ? ($body['rowLabelWidth'] !== null && $body['rowLabelWidth'] !== '' ? (int)$body['rowLabelWidth'] : null) : $tb['row_label_width'];
    $stmt = $pdo->prepare('UPDATE raid_template_tables SET title = ?, header_color = ?, default_column_width = ?, row_label_width = ? WHERE id = ?');
    $stmt->execute([$title, $headerColor, $colWidth, $labelWidth, $tb['id']]);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'delete_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    $stmt = $pdo->prepare('DELETE FROM raid_template_tables WHERE id = ?');
    $stmt->execute([$tb['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'move_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    move_sibling($pdo, 'raid_template_tables', 'section_id', $tb['section_id'], $tb['id'], $body['direction'] ?? '');
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'add_column' || $action === 'add_row') {
    $isCol = $action === 'add_column';
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['tableId'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $kind = ($body['kind'] ?? 'data') === 'spacer' ? 'spacer' : 'data';
    $label = substr(trim($body['label'] ?? ''), 0, 60);
    if ($kind === 'data' && !$label) fail(400, 'Label is required');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    $order = next_sort_order($pdo, $table, 'table_id', $tb['id']);
    $stmt = $pdo->prepare("INSERT INTO $table (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)");
    $stmt->execute([$tb['id'], $label, $order, $kind]);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'update_column' || $action === 'update_row') {
    $isCol = $action === 'update_column';
    $item = $isCol
        ? fetch_column_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0))
        : fetch_row_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$item) fail(404, 'Not found');
    $label = substr(trim($body['label'] ?? ''), 0, 60);
    if ($item['kind'] === 'data' && !$label) fail(400, 'Label is required');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';

    if ($isCol) {
        $width = array_key_exists('width', $body) ? ($body['width'] !== null && $body['width'] !== '' ? (int)$body['width'] : null) : $item['width'];
        $headerColor = array_key_exists('headerColor', $body) ? ($body['headerColor'] ?: null) : $item['header_color'];
        $groupId = $item['group_id'] !== null ? (int)$item['group_id'] : null;
        if (array_key_exists('groupId', $body)) {
            if ($body['groupId'] === null || $body['groupId'] === '') {
                $groupId = null;
            } else {
                $grp = fetch_group_owned($pdo, $tenant['id'], (int)$body['groupId']);
                if (!$grp || (int)$grp['table_id'] !== (int)$item['table_id']) fail(400, 'Invalid group');
                $groupId = (int)$grp['id'];
            }
        }
        $stmt = $pdo->prepare('UPDATE raid_template_columns SET label = ?, width = ?, header_color = ?, group_id = ? WHERE id = ?');
        $stmt->execute([$label, $width, $headerColor, $groupId, $item['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE raid_template_rows SET label = ? WHERE id = ?');
        $stmt->execute([$label, $item['id']]);
    }

    $tb = fetch_table_owned($pdo, $tenant['id'], $item['table_id']);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'add_column_group') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['tableId'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    if (!$title) fail(400, 'Title is required');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#5865f2';
    $parentGroupId = null;
    if (!empty($body['parentGroupId'])) {
        $parent = fetch_group_owned($pdo, $tenant['id'], (int)$body['parentGroupId']);
        if (!$parent || (int)$parent['table_id'] !== (int)$tb['id']) fail(400, 'Invalid parent group');
        $parentGroupId = (int)$parent['id'];
    }
    $order = next_sort_order($pdo, 'raid_template_column_groups', 'table_id', $tb['id']);
    $stmt = $pdo->prepare('INSERT INTO raid_template_column_groups (table_id, parent_group_id, title, color, sort_order) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$tb['id'], $parentGroupId, $title, $color, $order]);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'update_column_group') {
    $grp = fetch_group_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$grp) fail(404, 'Group not found');
    $title = array_key_exists('title', $body) ? substr(trim($body['title'] ?? ''), 0, 100) : $grp['title'];
    if (!$title) fail(400, 'Title is required');
    $color = $grp['color'];
    if (array_key_exists('color', $body) && preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '')) {
        $color = $body['color'];
    }
    $stmt = $pdo->prepare('UPDATE raid_template_column_groups SET title = ?, color = ? WHERE id = ?');
    $stmt->execute([$title, $color, $grp['id']]);
    $tb = fetch_table_owned($pdo, $tenant['id'], $grp['table_id']);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'delete_column_group') {
    $grp = fetch_group_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$grp) fail(404, 'Group not found');
    $tb = fetch_table_owned($pdo, $tenant['id'], $grp['table_id']);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    $stmt = $pdo->prepare('DELETE FROM raid_template_column_groups WHERE id = ?');
    $stmt->execute([$grp['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'delete_column' || $action === 'delete_row') {
    $isCol = $action === 'delete_column';
    $item = $isCol
        ? fetch_column_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0))
        : fetch_row_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$item) fail(404, 'Not found');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    $tb = fetch_table_owned($pdo, $tenant['id'], $item['table_id']);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->execute([$item['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'move_column' || $action === 'move_row') {
    $isCol = $action === 'move_column';
    $item = $isCol
        ? fetch_column_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0))
        : fetch_row_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$item) fail(404, 'Not found');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    move_sibling($pdo, $table, 'table_id', $item['table_id'], $item['id'], $body['direction'] ?? '');
    $tb = fetch_table_owned($pdo, $tenant['id'], $item['table_id']);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'reorder') {
    $kind = $body['kind'] ?? '';
    $orderedIds = is_array($body['orderedIds'] ?? null) ? $body['orderedIds'] : [];

    if ($kind === 'table') {
        $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['parentId'] ?? 0));
        if (!$sec) fail(404, 'Section not found');
        reorder_siblings($pdo, 'raid_template_tables', 'section_id', $sec['id'], $orderedIds);
        respond_structure($pdo, $sec['template_id']);
    }

    if ($kind === 'column' || $kind === 'row') {
        $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['parentId'] ?? 0));
        if (!$tb) fail(404, 'Table not found');
        $table = $kind === 'column' ? 'raid_template_columns' : 'raid_template_rows';
        reorder_siblings($pdo, $table, 'table_id', $tb['id'], $orderedIds);
        $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
        respond_structure($pdo, $sec['template_id']);
    }

    if ($kind === 'group') {
        $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['parentId'] ?? 0));
        if (!$tb) fail(404, 'Table not found');
        reorder_siblings($pdo, 'raid_template_column_groups', 'table_id', $tb['id'], $orderedIds);
        $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
        respond_structure($pdo, $sec['template_id']);
    }

    fail(400, 'Invalid reorder kind');
}

fail(400, 'Unknown action');
