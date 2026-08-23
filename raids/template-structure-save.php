<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/edit_lock.php';
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
        'SELECT s.*, t.guild_id FROM raid_template_sections s
         JOIN raid_templates t ON t.id = s.template_id
         WHERE s.id = ? AND t.guild_id = ?'
    );
    $stmt->execute([$sectionId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// A table's parent is either a section (top-level) or a column-group which itself lives in
// another table (nested). Walk that chain up to a section to find the owning template,
// re-validating tenant ownership at the section hop. Depth-capped as a guard against bad data.
function resolve_table_templateId($pdo, $guildId, $tableId, $depth = 0) {
    if ($depth > 6) return null;
    $stmt = $pdo->prepare('SELECT section_id, parent_group_id FROM raid_template_tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $tb = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tb) return null;

    if ($tb['section_id'] !== null) {
        $sec = fetch_section_owned($pdo, $guildId, (int)$tb['section_id']);
        return $sec ? (int)$sec['template_id'] : null;
    }

    if ($tb['parent_group_id'] !== null) {
        $stmt = $pdo->prepare('SELECT table_id FROM raid_template_column_groups WHERE id = ?');
        $stmt->execute([$tb['parent_group_id']]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) return null;
        return resolve_table_templateId($pdo, $guildId, (int)$grp['table_id'], $depth + 1);
    }

    return null;
}

function fetch_table_owned($pdo, $guildId, $tableId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $tb = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tb) return null;
    $templateId = resolve_table_templateId($pdo, $guildId, $tableId);
    if ($templateId === null) return null;
    $tb['template_id'] = $templateId;
    return $tb;
}

function fetch_column_owned($pdo, $guildId, $columnId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_columns WHERE id = ?');
    $stmt->execute([$columnId]);
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$col) return null;
    $tb = fetch_table_owned($pdo, $guildId, (int)$col['table_id']);
    if (!$tb) return null;
    $col['template_id'] = $tb['template_id'];
    return $col;
}

function fetch_row_owned($pdo, $guildId, $rowId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_rows WHERE id = ?');
    $stmt->execute([$rowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $tb = fetch_table_owned($pdo, $guildId, (int)$row['table_id']);
    if (!$tb) return null;
    $row['template_id'] = $tb['template_id'];
    return $row;
}

function fetch_group_owned($pdo, $guildId, $groupId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE id = ?');
    $stmt->execute([$groupId]);
    $grp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grp) return null;
    $templateId = resolve_table_templateId($pdo, $guildId, (int)$grp['table_id']);
    if ($templateId === null) return null;
    $grp['template_id'] = $templateId;
    return $grp;
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
// (Reparenting, when needed, is done by the caller before this runs.)
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
    $stmt = $pdo->prepare('SELECT id, label, sort_order, kind, width, header_color, bg_color, group_id, header_colspan FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'],
        'label' => $c['label'],
        'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'bgColor' => $c['bg_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT id, label, sort_order, kind, height, bg_color FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tb['id']]);
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'label' => $r['label'],
        'kind' => $r['kind'],
        'height' => $r['height'] !== null ? (int)$r['height'] : null,
        'bgColor' => $r['bg_color'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tb['id']]);
    $groupRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnGroups = [];
    foreach ($groupRows as $g) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$g['id']]);
        $childTables = array_map(fn($ctb) => fetch_table_full($pdo, $ctb), $stmtGT->fetchAll(PDO::FETCH_ASSOC));
        $columnGroups[] = [
            'id' => (int)$g['id'],
            'parentGroupId' => $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null,
            'title' => $g['title'],
            'color' => $g['color'],
            'tables' => $childTables,
        ];
    }

    $stmt = $pdo->prepare('SELECT row_id, column_id, colspan FROM raid_template_cell_merges WHERE table_id = ?');
    $stmt->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'],
        'columnId' => (int)$m['column_id'],
        'colspan' => (int)$m['colspan'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT row_id, column_id, text_content, bg_color, text_color, kind_override FROM raid_template_cells WHERE table_id = ?');
    $stmt->execute([$tb['id']]);
    $cells = array_map(fn($c) => [
        'rowId' => (int)$c['row_id'],
        'columnId' => (int)$c['column_id'],
        'textContent' => $c['text_content'],
        'bgColor' => $c['bg_color'],
        'textColor' => $c['text_color'],
        'kindOverride' => $c['kind_override'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        'id' => (int)$tb['id'],
        'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'columns' => $columns,
        'rows' => $rows,
        'columnGroups' => $columnGroups,
        'cellMerges' => $cellMerges,
        'cells' => $cells,
    ];
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
        $tablesOut = array_map(fn($tb) => fetch_table_full($pdo, $tb), $tables);

        $out[] = [
            'id' => (int)$sec['id'],
            'kind' => $sec['kind'],
            'title' => $sec['title'],
            'tables' => $tablesOut,
            'noteEnabled' => (bool)$sec['note_enabled'],
            'noteText' => $sec['note_text'],
            'mrtExportEnabled' => (bool)$sec['mrt_export_enabled'],
        ];
    }

    return $out;
}

// AngryERA export config is per-tab (per distinct section `kind` on this template).
function fetch_tab_exports($pdo, $templateId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_tab_exports WHERE template_id = ?');
    $stmt->execute([$templateId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $te) {
        $stmt2 = $pdo->prepare('SELECT id, name, template FROM raid_template_export_pages WHERE tab_export_id = ? ORDER BY sort_order, id');
        $stmt2->execute([$te['id']]);
        $pages = array_map(fn($p) => [
            'id' => (int)$p['id'], 'name' => $p['name'], 'template' => $p['template'],
        ], $stmt2->fetchAll(PDO::FETCH_ASSOC));
        $out[$te['kind']] = [
            'enabled'    => (bool)$te['enabled'],
            'singlePage' => (bool)$te['single_page'],
            'exportName' => $te['export_name'],
            'pages'      => $pages,
        ];
    }
    return $out;
}

// Resolves an export page id to its owning tab_export row, verifying the template it
// belongs to is owned by $guildId. Returns null if the page doesn't exist or isn't owned.
function fetch_page_owned($pdo, $guildId, $pageId) {
    $stmt = $pdo->prepare(
        'SELECT p.*, te.template_id, te.kind FROM raid_template_export_pages p
         JOIN raid_template_tab_exports te ON te.id = p.tab_export_id
         WHERE p.id = ?'
    );
    $stmt->execute([$pageId]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page) return null;
    if (!fetch_template($pdo, $guildId, (int)$page['template_id'])) return null;
    return $page;
}

function respond_structure($pdo, $templateId) {
    echo json_encode([
        'success'    => true,
        'sections'   => fetch_structure($pdo, $templateId),
        'tabExports' => fetch_tab_exports($pdo, $templateId),
    ]);
    exit;
}

// Every action on this endpoint already requires 'admin' (see the role check
// above), so "any admin can force-unlock" needs no extra check here.
if ($action === 'lock_status' || $action === 'lock_acquire' || $action === 'lock_heartbeat'
    || $action === 'lock_release' || $action === 'lock_force_release') {
    $templateId = (int)($body['templateId'] ?? 0);
    $template = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');

    if ($action === 'lock_status') {
        echo json_encode(['success' => true, 'holder' => check_lock($pdo, 'template', $templateId)]);
        exit;
    }
    if ($action === 'lock_acquire') {
        $result = acquire_lock($pdo, 'template', $templateId, $user['id'], $user['username']);
        echo json_encode(['success' => $result['ok'], 'holder' => $result['holder'] ?? null]);
        exit;
    }
    if ($action === 'lock_heartbeat') {
        echo json_encode(['success' => heartbeat_lock($pdo, 'template', $templateId, $user['id'])]);
        exit;
    }
    if ($action === 'lock_release') {
        release_lock($pdo, 'template', $templateId, $user['id']);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'lock_force_release') {
        force_unlock($pdo, 'template', $templateId);
        echo json_encode(['success' => true]);
        exit;
    }
}

// AngryERA export config is per-tab: each distinct section `kind` on a template can
// independently be enabled, run single-page or multi-page, and hold its own named pages.
if ($action === 'set_tab_export_enabled') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    $template = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    $kind = substr(trim($body['kind'] ?? ''), 0, 50);
    if (!$kind) fail(400, 'Invalid tab');
    $enabled = !empty($body['enabled']) ? 1 : 0;
    $stmt = $pdo->prepare(
        'INSERT INTO raid_template_tab_exports (template_id, kind, enabled) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
    );
    $stmt->execute([$templateId, $kind, $enabled]);
    respond_structure($pdo, $templateId);
}

if ($action === 'set_tab_export_meta') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    $template = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    $kind = substr(trim($body['kind'] ?? ''), 0, 50);
    if (!$kind) fail(400, 'Invalid tab');
    $singlePage = !empty($body['singlePage']) ? 1 : 0;
    $exportName = substr(trim($body['exportName'] ?? ''), 0, 100);
    $stmt = $pdo->prepare(
        'INSERT INTO raid_template_tab_exports (template_id, kind, single_page, export_name) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE single_page = VALUES(single_page), export_name = VALUES(export_name)'
    );
    $stmt->execute([$templateId, $kind, $singlePage, $exportName]);
    respond_structure($pdo, $templateId);
}

function get_or_create_tab_export($pdo, $templateId, $kind) {
    $stmt = $pdo->prepare('SELECT id FROM raid_template_tab_exports WHERE template_id = ? AND kind = ?');
    $stmt->execute([$templateId, $kind]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $pdo->prepare('INSERT INTO raid_template_tab_exports (template_id, kind) VALUES (?, ?)');
    $stmt->execute([$templateId, $kind]);
    return (int)$pdo->lastInsertId();
}

if ($action === 'add_export_page') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    $template = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    $kind = substr(trim($body['kind'] ?? ''), 0, 50);
    if (!$kind) fail(400, 'Invalid tab');
    $name = substr(trim($body['name'] ?? ''), 0, 100);
    if (!$name) $name = 'New page';

    $tabExportId = get_or_create_tab_export($pdo, $templateId, $kind);
    $order = next_sort_order($pdo, 'raid_template_export_pages', 'tab_export_id', $tabExportId);
    $stmt = $pdo->prepare('INSERT INTO raid_template_export_pages (tab_export_id, name, template, sort_order) VALUES (?, ?, ?, ?)');
    $stmt->execute([$tabExportId, $name, '', $order]);
    respond_structure($pdo, $templateId);
}

if ($action === 'update_export_page') {
    $page = fetch_page_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$page) fail(404, 'Page not found');
    $name = array_key_exists('name', $body) ? substr(trim((string)$body['name']), 0, 100) : $page['name'];
    if (!$name) fail(400, 'Name is required');
    $tmpl = array_key_exists('template', $body) ? substr((string)$body['template'], 0, 20000) : $page['template'];
    $stmt = $pdo->prepare('UPDATE raid_template_export_pages SET name = ?, template = ? WHERE id = ?');
    $stmt->execute([$name, $tmpl, $page['id']]);
    respond_structure($pdo, $page['template_id']);
}

if ($action === 'delete_export_page') {
    $page = fetch_page_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$page) fail(404, 'Page not found');
    $templateId = (int)$page['template_id'];
    $stmt = $pdo->prepare('DELETE FROM raid_template_export_pages WHERE id = ?');
    $stmt->execute([$page['id']]);
    respond_structure($pdo, $templateId);
}

if ($action === 'move_export_page') {
    $page = fetch_page_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$page) fail(404, 'Page not found');
    move_sibling($pdo, 'raid_template_export_pages', 'tab_export_id', $page['tab_export_id'], $page['id'], $body['direction'] ?? '');
    respond_structure($pdo, $page['template_id']);
}

if ($action === 'add_section') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    // A tab and its "kind" are the same freeform string now — sections sharing a kind value
    // form one tab in the editor. See delete_tab below for the matching bulk-remove.
    $kind       = substr(trim($body['kind'] ?? ''), 0, 50);
    $title      = substr(trim($body['title'] ?? ''), 0, 100);
    $template   = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    if (!$kind) fail(400, 'Tab name is required');
    if (!$title) fail(400, 'Title is required');

    $order = next_sort_order($pdo, 'raid_template_sections', 'template_id', $templateId);
    $stmt = $pdo->prepare('INSERT INTO raid_template_sections (template_id, kind, title, sort_order) VALUES (?, ?, ?, ?)');
    $stmt->execute([$templateId, $kind, $title, $order]);
    respond_structure($pdo, $templateId);
}

// Removes an entire tab: every section (and everything nested under them — tables, columns,
// rows, cells) sharing that kind value on this template. The client shows an urgent warning
// before sending this, since it's a single irreversible bulk delete.
if ($action === 'delete_tab') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    $kind       = $body['kind'] ?? '';
    $template   = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    if ($kind === '') fail(400, 'Invalid tab');
    $stmt = $pdo->prepare('DELETE FROM raid_template_sections WHERE template_id = ? AND kind = ?');
    $stmt->execute([$templateId, $kind]);
    // Not FK-linked to sections (only by template_id+kind), so it needs its own cleanup;
    // this cascades to raid_template_export_pages via fk_export_page_tab.
    $stmt = $pdo->prepare('DELETE FROM raid_template_tab_exports WHERE template_id = ? AND kind = ?');
    $stmt->execute([$templateId, $kind]);
    respond_structure($pdo, $templateId);
}

if ($action === 'update_section') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    if (!$title) fail(400, 'Title is required');
    $noteEnabled = array_key_exists('noteEnabled', $body) ? (!empty($body['noteEnabled']) ? 1 : 0) : $sec['note_enabled'];
    $noteText = array_key_exists('noteText', $body) ? ($body['noteText'] !== null ? substr(trim((string)$body['noteText']), 0, 255) : null) : $sec['note_text'];
    if ($noteText === '') $noteText = null;
    $stmt = $pdo->prepare('UPDATE raid_template_sections SET title = ?, note_enabled = ?, note_text = ? WHERE id = ?');
    $stmt->execute([$title, $noteEnabled, $noteText, $sec['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'set_section_mrt_export') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $enabled = !empty($body['enabled']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE raid_template_sections SET mrt_export_enabled = ? WHERE id = ?');
    $stmt->execute([$enabled, $sec['id']]);
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
    $groupId = (int)($body['groupId'] ?? 0);

    if ($groupId) {
        $grp = fetch_group_owned($pdo, $tenant['id'], $groupId);
        if (!$grp) fail(404, 'Group not found');
        $title = substr(trim($body['title'] ?? ''), 0, 100);
        if (!$title) fail(400, 'Title is required');
        $order = next_sort_order($pdo, 'raid_template_tables', 'parent_group_id', $grp['id']);
        $stmt = $pdo->prepare('INSERT INTO raid_template_tables (parent_group_id, title, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$grp['id'], $title, $order]);
        respond_structure($pdo, $grp['template_id']);
    }

    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['sectionId'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    // Top-level tables are numbered automatically in the UI, so a title is optional here.
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    $order = next_sort_order($pdo, 'raid_template_tables', 'section_id', $sec['id']);
    $stmt = $pdo->prepare('INSERT INTO raid_template_tables (section_id, title, sort_order) VALUES (?, ?, ?)');
    $stmt->execute([$sec['id'], $title, $order]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'update_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $isNested = $tb['parent_group_id'] !== null;
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    if ($isNested && !$title) fail(400, 'Title is required');
    $headerColor = array_key_exists('headerColor', $body) ? ($body['headerColor'] ?: null) : $tb['header_color'];
    $colWidth    = array_key_exists('defaultColumnWidth', $body) ? ($body['defaultColumnWidth'] !== null && $body['defaultColumnWidth'] !== '' ? (int)$body['defaultColumnWidth'] : null) : $tb['default_column_width'];
    $stmt = $pdo->prepare('UPDATE raid_template_tables SET title = ?, header_color = ?, default_column_width = ? WHERE id = ?');
    $stmt->execute([$title, $headerColor, $colWidth, $tb['id']]);
    respond_structure($pdo, $tb['template_id']);
}

if ($action === 'delete_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $templateId = $tb['template_id'];
    $stmt = $pdo->prepare('DELETE FROM raid_template_tables WHERE id = ?');
    $stmt->execute([$tb['id']]);
    respond_structure($pdo, $templateId);
}

if ($action === 'add_column' || $action === 'add_row') {
    $isCol = $action === 'add_column';
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['tableId'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $kindIn = $body['kind'] ?? 'general';
    $kind = in_array($kindIn, ['text', 'general', 'spacer'], true) ? $kindIn : 'general';
    $label = substr(trim($body['label'] ?? ''), 0, 60);
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    $order = next_sort_order($pdo, $table, 'table_id', $tb['id']);
    $stmt = $pdo->prepare("INSERT INTO $table (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)");
    $stmt->execute([$tb['id'], $label, $order, $kind]);
    respond_structure($pdo, $tb['template_id']);
}

if ($action === 'update_column' || $action === 'update_row') {
    $isCol = $action === 'update_column';
    $item = $isCol
        ? fetch_column_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0))
        : fetch_row_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$item) fail(404, 'Not found');
    $label = substr(trim($body['label'] ?? ''), 0, 60);

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
        $height = array_key_exists('height', $body) ? ($body['height'] !== null && $body['height'] !== '' ? (int)$body['height'] : null) : $item['height'];
        $stmt = $pdo->prepare('UPDATE raid_template_rows SET label = ?, height = ? WHERE id = ?');
        $stmt->execute([$label, $height, $item['id']]);
    }

    respond_structure($pdo, $item['template_id']);
}

if ($action === 'update_cell') {
    $col = fetch_column_owned($pdo, $tenant['id'], (int)($body['columnId'] ?? 0));
    if (!$col) fail(404, 'Column not found');
    $row = fetch_row_owned($pdo, $tenant['id'], (int)($body['rowId'] ?? 0));
    if (!$row) fail(404, 'Row not found');
    if ((int)$col['table_id'] !== (int)$row['table_id']) fail(400, 'Row/column mismatch');

    $textContent = array_key_exists('textContent', $body) ? substr(trim((string)$body['textContent']), 0, 500) : '';
    $bgColor = !empty($body['bgColor']) ? $body['bgColor'] : null;
    $textColor = !empty($body['textColor']) ? $body['textColor'] : null;

    $stmt = $pdo->prepare(
        'INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bg_color, text_color)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE text_content = VALUES(text_content), bg_color = VALUES(bg_color), text_color = VALUES(text_color)'
    );
    $stmt->execute([$col['table_id'], $row['id'], $col['id'], $textContent, $bgColor, $textColor]);

    respond_structure($pdo, $col['template_id']);
}

if ($action === 'set_cell_kind_override') {
    $col = fetch_column_owned($pdo, $tenant['id'], (int)($body['columnId'] ?? 0));
    if (!$col) fail(404, 'Column not found');
    $row = fetch_row_owned($pdo, $tenant['id'], (int)($body['rowId'] ?? 0));
    if (!$row) fail(404, 'Row not found');
    if ((int)$col['table_id'] !== (int)$row['table_id']) fail(400, 'Row/column mismatch');

    $kindOverride = $body['kindOverride'] ?? null;
    if ($kindOverride !== null && !in_array($kindOverride, ['general', 'text', 'spacer'], true)) {
        fail(400, 'Invalid kind override');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO raid_template_cells (table_id, row_id, column_id, kind_override)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE kind_override = VALUES(kind_override)'
    );
    $stmt->execute([$col['table_id'], $row['id'], $col['id'], $kindOverride]);

    respond_structure($pdo, $col['template_id']);
}

if ($action === 'paint_cells') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['tableId'] ?? 0));
    if (!$tb) fail(404, 'Table not found');

    $color = $body['color'] ?? null;
    if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) fail(400, 'Invalid color');

    $cells = is_array($body['cells'] ?? null) ? $body['cells'] : [];
    $spacerRows = is_array($body['spacerRows'] ?? null) ? array_map('intval', $body['spacerRows']) : [];
    $spacerCols = is_array($body['spacerColumns'] ?? null) ? array_map('intval', $body['spacerColumns']) : [];

    if ($cells) {
        $stmtR = $pdo->prepare('SELECT id FROM raid_template_rows WHERE table_id = ? AND kind != \'spacer\'');
        $stmtR->execute([$tb['id']]);
        $validRows = array_flip(array_map('intval', $stmtR->fetchAll(PDO::FETCH_COLUMN)));
        $stmtC = $pdo->prepare('SELECT id FROM raid_template_columns WHERE table_id = ? AND kind != \'spacer\'');
        $stmtC->execute([$tb['id']]);
        $validCols = array_flip(array_map('intval', $stmtC->fetchAll(PDO::FETCH_COLUMN)));

        $stmt = $pdo->prepare(
            'INSERT INTO raid_template_cells (table_id, row_id, column_id, bg_color)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE bg_color = VALUES(bg_color)'
        );
        foreach ($cells as $c) {
            $rowId = (int)($c['rowId'] ?? 0);
            $colId = (int)($c['columnId'] ?? 0);
            if (!isset($validRows[$rowId]) || !isset($validCols[$colId])) continue;
            $stmt->execute([$tb['id'], $rowId, $colId, $color]);
        }
    }

    // Spacer rows/columns hold no cells at all (skipped entirely at raid-creation time), so
    // their color lives directly on raid_template_rows/columns instead of raid_template_cells.
    if ($spacerRows) {
        $stmtSR = $pdo->prepare('SELECT id FROM raid_template_rows WHERE table_id = ? AND kind = \'spacer\'');
        $stmtSR->execute([$tb['id']]);
        $validSpacerRows = array_flip(array_map('intval', $stmtSR->fetchAll(PDO::FETCH_COLUMN)));
        $updR = $pdo->prepare('UPDATE raid_template_rows SET bg_color = ? WHERE id = ?');
        foreach ($spacerRows as $rowId) {
            if (!isset($validSpacerRows[$rowId])) continue;
            $updR->execute([$color, $rowId]);
        }
    }

    if ($spacerCols) {
        $stmtSC = $pdo->prepare('SELECT id FROM raid_template_columns WHERE table_id = ? AND kind = \'spacer\'');
        $stmtSC->execute([$tb['id']]);
        $validSpacerCols = array_flip(array_map('intval', $stmtSC->fetchAll(PDO::FETCH_COLUMN)));
        $updC = $pdo->prepare('UPDATE raid_template_columns SET bg_color = ? WHERE id = ?');
        foreach ($spacerCols as $colId) {
            if (!isset($validSpacerCols[$colId])) continue;
            $updC->execute([$color, $colId]);
        }
    }

    respond_structure($pdo, $tb['template_id']);
}

if ($action === 'merge_header' || $action === 'split_header') {
    $col = fetch_column_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$col) fail(404, 'Column not found');

    if ($action === 'split_header') {
        $stmt = $pdo->prepare('UPDATE raid_template_columns SET header_colspan = 1 WHERE id = ?');
        $stmt->execute([$col['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM raid_template_columns WHERE table_id = ? AND sort_order >= ?');
        $stmt->execute([$col['table_id'], $col['sort_order']]);
        $maxSpan = (int)$stmt->fetchColumn();
        $newSpan = min($maxSpan, (int)$col['header_colspan'] + 1);
        $stmt = $pdo->prepare('UPDATE raid_template_columns SET header_colspan = ? WHERE id = ?');
        $stmt->execute([$newSpan, $col['id']]);
    }

    respond_structure($pdo, $col['template_id']);
}

if ($action === 'merge_cell' || $action === 'split_cell') {
    $col = fetch_column_owned($pdo, $tenant['id'], (int)($body['columnId'] ?? 0));
    $row = fetch_row_owned($pdo, $tenant['id'], (int)($body['rowId'] ?? 0));
    if (!$col || !$row) fail(404, 'Not found');
    if ((int)$col['table_id'] !== (int)$row['table_id']) fail(400, 'Row/column must belong to the same table');

    if ($action === 'split_cell') {
        $stmt = $pdo->prepare('DELETE FROM raid_template_cell_merges WHERE row_id = ? AND column_id = ?');
        $stmt->execute([$row['id'], $col['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM raid_template_columns WHERE table_id = ? AND sort_order >= ?');
        $stmt->execute([$col['table_id'], $col['sort_order']]);
        $maxSpan = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT colspan FROM raid_template_cell_merges WHERE row_id = ? AND column_id = ?');
        $stmt->execute([$row['id'], $col['id']]);
        $current = (int)($stmt->fetchColumn() ?: 1);
        $newSpan = min($maxSpan, $current + 1);

        $stmt = $pdo->prepare('INSERT INTO raid_template_cell_merges (table_id, row_id, column_id, colspan) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE colspan = VALUES(colspan)');
        $stmt->execute([$col['table_id'], $row['id'], $col['id'], $newSpan]);
    }

    respond_structure($pdo, $col['template_id']);
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
    respond_structure($pdo, $tb['template_id']);
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
    respond_structure($pdo, $grp['template_id']);
}

if ($action === 'delete_column_group') {
    $grp = fetch_group_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$grp) fail(404, 'Group not found');
    $templateId = $grp['template_id'];
    $stmt = $pdo->prepare('DELETE FROM raid_template_column_groups WHERE id = ?');
    $stmt->execute([$grp['id']]);
    respond_structure($pdo, $templateId);
}

if ($action === 'delete_column' || $action === 'delete_row') {
    $isCol = $action === 'delete_column';
    $item = $isCol
        ? fetch_column_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0))
        : fetch_row_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$item) fail(404, 'Not found');
    $table = $isCol ? 'raid_template_columns' : 'raid_template_rows';
    $templateId = $item['template_id'];
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->execute([$item['id']]);
    respond_structure($pdo, $templateId);
}

if ($action === 'reorder') {
    $kind = $body['kind'] ?? '';
    $orderedIds = is_array($body['orderedIds'] ?? null) ? $body['orderedIds'] : [];
    $parentId = (int)($body['parentId'] ?? 0);

    if ($kind === 'table') {
        $parentKind = ($body['parentKind'] ?? 'section') === 'group' ? 'group' : 'section';

        if ($parentKind === 'group') {
            $grp = fetch_group_owned($pdo, $tenant['id'], $parentId);
            if (!$grp) fail(404, 'Group not found');
            $fkCol = 'parent_group_id';
            $fkVal = $grp['id'];
            $templateId = $grp['template_id'];
        } else {
            $sec = fetch_section_owned($pdo, $tenant['id'], $parentId);
            if (!$sec) fail(404, 'Section not found');
            $fkCol = 'section_id';
            $fkVal = $sec['id'];
            $templateId = $sec['template_id'];
        }

        $otherCol = $fkCol === 'section_id' ? 'parent_group_id' : 'section_id';
        foreach ($orderedIds as $id) {
            $tb = fetch_table_owned($pdo, $tenant['id'], (int)$id);
            if (!$tb) continue;
            $currentFkVal = $tb[$fkCol];
            if ($currentFkVal === null || (int)$currentFkVal !== (int)$fkVal) {
                $upd = $pdo->prepare("UPDATE raid_template_tables SET $fkCol = ?, $otherCol = NULL WHERE id = ?");
                $upd->execute([$fkVal, $tb['id']]);
            }
        }
        reorder_siblings($pdo, 'raid_template_tables', $fkCol, $fkVal, $orderedIds);
        respond_structure($pdo, $templateId);
    }

    if ($kind === 'column' || $kind === 'row') {
        $tb = fetch_table_owned($pdo, $tenant['id'], $parentId);
        if (!$tb) fail(404, 'Table not found');
        $table = $kind === 'column' ? 'raid_template_columns' : 'raid_template_rows';

        foreach ($orderedIds as $id) {
            $item = $kind === 'column'
                ? fetch_column_owned($pdo, $tenant['id'], (int)$id)
                : fetch_row_owned($pdo, $tenant['id'], (int)$id);
            if (!$item) continue;
            if ((int)$item['table_id'] !== (int)$tb['id']) {
                $upd = $pdo->prepare("UPDATE $table SET table_id = ? WHERE id = ?");
                $upd->execute([$tb['id'], $item['id']]);
            }
        }
        reorder_siblings($pdo, $table, 'table_id', $tb['id'], $orderedIds);
        respond_structure($pdo, $tb['template_id']);
    }

    if ($kind === 'group') {
        $tb = fetch_table_owned($pdo, $tenant['id'], $parentId);
        if (!$tb) fail(404, 'Table not found');

        foreach ($orderedIds as $id) {
            $grp = fetch_group_owned($pdo, $tenant['id'], (int)$id);
            if (!$grp) continue;
            if ((int)$grp['table_id'] !== (int)$tb['id']) {
                $upd = $pdo->prepare('UPDATE raid_template_column_groups SET table_id = ? WHERE id = ?');
                $upd->execute([$tb['id'], $grp['id']]);
            }
        }
        reorder_siblings($pdo, 'raid_template_column_groups', 'table_id', $tb['id'], $orderedIds);
        respond_structure($pdo, $tb['template_id']);
    }

    fail(400, 'Invalid reorder kind');
}

fail(400, 'Unknown action');
