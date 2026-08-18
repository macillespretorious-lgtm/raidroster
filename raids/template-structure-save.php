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
            $stmt = $pdo->prepare('SELECT id, label, sort_order FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
            $stmt->execute([$tb['id']]);
            $columns = array_map(fn($c) => ['id' => (int)$c['id'], 'label' => $c['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));

            $stmt = $pdo->prepare('SELECT id, label, sort_order FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
            $stmt->execute([$tb['id']]);
            $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));

            $tablesOut[] = [
                'id' => (int)$tb['id'],
                'title' => $tb['title'],
                'columns' => $columns,
                'rows' => $rows,
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
    $stmt = $pdo->prepare('UPDATE raid_template_tables SET title = ? WHERE id = ?');
    $stmt->execute([$title, $tb['id']]);
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
    $label = substr(trim($body['label'] ?? ''), 0, 60);
    if (!$label) fail(400, 'Label is required');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    $order = next_sort_order($pdo, $table, 'table_id', $tb['id']);
    $stmt = $pdo->prepare("INSERT INTO $table (table_id, label, sort_order) VALUES (?, ?, ?)");
    $stmt->execute([$tb['id'], $label, $order]);
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
    if (!$label) fail(400, 'Label is required');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    $stmt = $pdo->prepare("UPDATE $table SET label = ? WHERE id = ?");
    $stmt->execute([$label, $item['id']]);
    $tb = fetch_table_owned($pdo, $tenant['id'], $item['table_id']);
    $sec = fetch_section_owned($pdo, $tenant['id'], $tb['section_id']);
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

fail(400, 'Unknown action');
