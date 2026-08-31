<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/edit_lock.php';
require_once __DIR__ . '/../includes/raid_icons.php';
require_once __DIR__ . '/../includes/class_roles.php';
require_once __DIR__ . '/../includes/template_clone.php';
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

const RULE_CLASSES = ['Warrior', 'Paladin', 'Priest', 'Druid', 'Rogue', 'Mage', 'Warlock', 'Shaman', 'Hunter'];

function fetch_rule_owned($pdo, $guildId, $ruleId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_rules WHERE id = ?');
    $stmt->execute([$ruleId]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rule) return null;
    $tb = fetch_table_owned($pdo, $guildId, (int)$rule['table_id']);
    if (!$tb) return null;
    $rule['template_id'] = $tb['template_id'];
    return $rule;
}

// Validates/normalizes the rule-type-specific fields shared by add_rule/update_rule, and
// resolves+validates the cellRefs list (each ref's row/column must actually belong to the
// rule's own table -- otherwise a rule could be wired to cross-table ids).
function parse_rule_fields($pdo, $body, $tableId) {
    $ruleType = $body['ruleType'] ?? null;
    if (!in_array($ruleType, ['class_restrict', 'max_count'], true)) fail(400, 'Invalid rule type');

    $scope = $body['scope'] ?? null;
    if (!in_array($scope, ['cells', 'table'], true)) fail(400, 'Invalid scope');

    $classes = null;
    $maxCount = null;
    if ($ruleType === 'class_restrict') {
        $picked = is_array($body['classes'] ?? null) ? $body['classes'] : [];
        $picked = array_values(array_intersect($picked, RULE_CLASSES));
        if (!$picked) fail(400, 'Pick at least one class');
        $classes = implode(',', $picked);
    } else {
        $maxCount = isset($body['maxCount']) ? (int)$body['maxCount'] : 1;
        if ($maxCount < 1) $maxCount = 1;
    }

    $label = isset($body['label']) ? substr(trim((string)$body['label']), 0, 120) : '';
    $label = $label !== '' ? $label : null;

    $cellRefs = [];
    if ($scope === 'cells') {
        $refs = is_array($body['cellRefs'] ?? null) ? $body['cellRefs'] : [];
        $stmtC = $pdo->prepare('SELECT id FROM raid_template_columns WHERE table_id = ?');
        $stmtC->execute([$tableId]);
        $validCols = array_map('intval', array_column($stmtC->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $stmtR = $pdo->prepare('SELECT id FROM raid_template_rows WHERE table_id = ?');
        $stmtR->execute([$tableId]);
        $validRows = array_map('intval', array_column($stmtR->fetchAll(PDO::FETCH_ASSOC), 'id'));
        foreach ($refs as $ref) {
            $rowId = (int)($ref['rowId'] ?? 0);
            $colId = (int)($ref['columnId'] ?? 0);
            if (in_array($rowId, $validRows, true) && in_array($colId, $validCols, true)) {
                $cellRefs[] = [$rowId, $colId];
            }
        }
        if (!$cellRefs) fail(400, 'Pick at least one cell');
    }

    return [$ruleType, $scope, $classes, $maxCount, $label, $cellRefs];
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

function next_sort_order($pdo, $table, $fkCol, $fkVal, $extraWhere = null, $extraParams = []) {
    $sql = "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM $table WHERE $fkCol = ?";
    $params = [$fkVal];
    if ($extraWhere) { $sql .= " AND $extraWhere"; $params = array_merge($params, $extraParams); }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function move_sibling($pdo, $table, $fkCol, $fkVal, $id, $direction, $extraWhere = null, $extraParams = []) {
    $sql = "SELECT id, sort_order FROM $table WHERE $fkCol = ?";
    $params = [$fkVal];
    if ($extraWhere) { $sql .= " AND $extraWhere"; $params = array_merge($params, $extraParams); }
    $sql .= " ORDER BY sort_order, id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

    $stmt = $pdo->prepare('SELECT row_id, column_id, colspan, rowspan FROM raid_template_cell_merges WHERE table_id = ?');
    $stmt->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'],
        'columnId' => (int)$m['column_id'],
        'colspan' => (int)$m['colspan'],
        'rowspan' => (int)$m['rowspan'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override, text_align FROM raid_template_cells WHERE table_id = ?');
    $stmt->execute([$tb['id']]);
    $cells = array_map(fn($c) => [
        'rowId' => (int)$c['row_id'],
        'columnId' => (int)$c['column_id'],
        'textContent' => $c['text_content'],
        'bgColor' => $c['bg_color'],
        'textColor' => $c['text_color'],
        'bold' => (bool)$c['bold'],
        'font' => $c['font'],
        'icon' => $c['icon'],
        'kindOverride' => $c['kind_override'],
        'textAlign' => $c['text_align'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT id, rule_type, scope, classes, max_count, label, sort_order FROM raid_template_rules WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tb['id']]);
    $ruleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ruleCellsByRule = [];
    if ($ruleRows) {
        $ruleIds = array_column($ruleRows, 'id');
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $stmtRC = $pdo->prepare("SELECT rule_id, row_id, column_id FROM raid_template_rule_cells WHERE rule_id IN ($placeholders)");
        $stmtRC->execute($ruleIds);
        foreach ($stmtRC->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            $ruleCellsByRule[$rc['rule_id']][] = ['rowId' => (int)$rc['row_id'], 'columnId' => (int)$rc['column_id']];
        }
    }
    $rules = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'ruleType' => $r['rule_type'],
        'scope' => $r['scope'],
        'classes' => $r['classes'],
        'maxCount' => $r['max_count'] !== null ? (int)$r['max_count'] : null,
        'label' => $r['label'],
        'cellRefs' => $ruleCellsByRule[$r['id']] ?? [],
    ], $ruleRows);

    return [
        'id' => (int)$tb['id'],
        'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'bgColor' => $tb['bg_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'kind' => $tb['kind'],
        'markEnabled' => (bool)($tb['mark_enabled'] ?? 0),
        'markStyle' => $tb['mark_style'],
        'swapBeforeTableId' => $tb['swap_before_table_id'] !== null ? (int)$tb['swap_before_table_id'] : null,
        'swapAfterTableId' => $tb['swap_after_table_id'] !== null ? (int)$tb['swap_after_table_id'] : null,
        'countSourceTableId' => $tb['count_source_table_id'] !== null ? (int)$tb['count_source_table_id'] : null,
        'countCategories' => !empty($tb['count_categories']) ? explode(',', $tb['count_categories']) : ['Tank', 'Healer'],
        'columns' => $columns,
        'rows' => $rows,
        'columnGroups' => $columnGroups,
        'cellMerges' => $cellMerges,
        'cells' => $cells,
        'rules' => $rules,
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
            'color' => $sec['color'],
            'bgColor' => $sec['bg_color'],
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

    $order = next_sort_order($pdo, 'raid_template_sections', 'template_id', $templateId, 'kind = ?', [$kind]);
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

if ($action === 'paint_section') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $field = ($body['field'] ?? 'color') === 'bgColor' ? 'bg_color' : 'color';
    $color = $body['color'] ?? null;
    if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) fail(400, 'Invalid color');
    $stmt = $pdo->prepare("UPDATE raid_template_sections SET $field = ? WHERE id = ?");
    $stmt->execute([$color, $sec['id']]);
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'paint_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $field = ($body['field'] ?? 'headerColor') === 'bgColor' ? 'bg_color' : 'header_color';
    $color = $body['color'] ?? null;
    if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) fail(400, 'Invalid color');
    $stmt = $pdo->prepare("UPDATE raid_template_tables SET $field = ? WHERE id = ?");
    $stmt->execute([$color, $tb['id']]);
    respond_structure($pdo, $tb['template_id']);
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
    move_sibling($pdo, 'raid_template_sections', 'template_id', $sec['template_id'], $sec['id'], $body['direction'] ?? '', 'kind = ?', [$sec['kind']]);
    respond_structure($pdo, $sec['template_id']);
}

// Seeds a freshly-inserted table with the fixed Benched layout: one 'general' column, a fixed
// 'text' header row reading "Benched" (row 0 -- excluded from cells-save.php's grow/shrink
// capacity math, which only ever looks at kind='general' rows), one 'general' starting row
// (real growth happens at raid-time via cells-save.php's grow-on-full logic, so it never needs
// more than one spare slot up front), and the one static rule every Benched table carries -- a
// table-scoped max_count(1), preventing the same player from occupying two bench slots. This
// rule is permanent: the Logic-mode UI and add_rule/update_rule/delete_rule below all refuse to
// let it be edited or removed, or a second rule added, on a non-standard table.
function seed_benched_table($pdo, $tableId) {
    $pdo->prepare('INSERT INTO raid_template_columns (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, 'Bench', 'general']);
    $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, '', 'text']);
    $headerRowId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, 1, ?)')
        ->execute([$tableId, '', 'general']);
    $colId = (int)$pdo->query('SELECT id FROM raid_template_columns WHERE table_id = ' . (int)$tableId . ' ORDER BY sort_order, id LIMIT 1')->fetchColumn();
    // bg_color '#1c234b' matches the roster table's own "Grp 1".."Grp 8" header row --
    // the standard header-row color admins pick via the cell background picker, not a CSS
    // default -- so a Benched table sitting next to a roster table reads as one matched pair.
    $pdo->prepare('INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bold, bg_color) VALUES (?, ?, ?, ?, 0, ?)')
        ->execute([$tableId, $headerRowId, $colId, 'BENCHED', '#1c234b']);
    $pdo->prepare('INSERT INTO raid_template_rules (table_id, rule_type, scope, classes, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, ?, 0)')
        ->execute([$tableId, 'max_count', 'table', null, 1, 'A player can only be assigned once']);
}

// Validates a Swaps table's before/after table picks: both must exist, belong to the same
// template as the Swaps table itself, and be 'standard' (never trust the client's kind label
// for another table -- it could be stale or tampered).
function resolve_swap_tables($pdo, $guildId, $templateId, $body) {
    $beforeId = (int)($body['beforeTableId'] ?? 0);
    $afterId  = (int)($body['afterTableId'] ?? 0);
    if (!$beforeId || !$afterId) fail(400, 'Pick a Before and After table');
    foreach ([$beforeId, $afterId] as $id) {
        $t = fetch_table_owned($pdo, $guildId, $id);
        if (!$t || $t['template_id'] !== $templateId) fail(404, 'Before/After table not found');
        if ($t['kind'] !== 'standard') fail(400, 'Before/After must be Standard tables');
    }
    return [$beforeId, $afterId];
}

// Validates a Counter table's single source-table pick: must exist, belong to the same
// template, and be 'standard' -- a Counter can only ever count real assignments, never
// another computed table (never trust the client's kind label for another table).
function resolve_count_source_table($pdo, $guildId, $templateId, $body) {
    $sourceId = (int)($body['countSourceTableId'] ?? 0);
    if (!$sourceId) fail(400, 'Pick a roster table to count');
    $t = fetch_table_owned($pdo, $guildId, $sourceId);
    if (!$t || $t['template_id'] !== $templateId) fail(404, 'Roster table not found');
    if ($t['kind'] !== 'standard') fail(400, 'Roster table must be a Standard table');
    return $sourceId;
}

// Validates a Counter table's chosen count columns: any of the role categories or a
// CLASS_ROLES class name, deduped, order preserved (order picks the column order too).
// Returns null when the request didn't touch countCategories at all, so callers can tell
// "not sent" (keep existing value) apart from "sent but resolved to the fallback".
function resolve_count_categories($body) {
    if (!array_key_exists('countCategories', $body)) return null;
    $valid = array_merge(['Tank', 'Healer', 'DPS'], array_keys(CLASS_ROLES));
    $picked = [];
    foreach ((array)$body['countCategories'] as $c) {
        $c = trim((string)$c);
        if (in_array($c, $valid, true) && !in_array($c, $picked, true)) $picked[] = $c;
    }
    if (!$picked) $picked = ['Tank', 'Healer'];
    return implode(',', $picked);
}

if ($action === 'add_table') {
    $groupId = (int)($body['groupId'] ?? 0);
    $kind = in_array($body['kind'] ?? 'standard', ['standard', 'benched', 'swaps', 'counter'], true) ? $body['kind'] : 'standard';

    if ($groupId) {
        $grp = fetch_group_owned($pdo, $tenant['id'], $groupId);
        if (!$grp) fail(404, 'Group not found');
        $title = substr(trim($body['title'] ?? ''), 0, 100);
        if (!$title) fail(400, 'Title is required');
        $order = next_sort_order($pdo, 'raid_template_tables', 'parent_group_id', $grp['id']);
        [$beforeId, $afterId] = $kind === 'swaps' ? resolve_swap_tables($pdo, $tenant['id'], $grp['template_id'], $body) : [null, null];
        $countSourceId = $kind === 'counter' ? resolve_count_source_table($pdo, $tenant['id'], $grp['template_id'], $body) : null;
        $countCategories = $kind === 'counter' ? (resolve_count_categories($body) ?? 'Tank,Healer') : null;
        $stmt = $pdo->prepare('INSERT INTO raid_template_tables (parent_group_id, title, sort_order, kind, swap_before_table_id, swap_after_table_id, count_source_table_id, count_categories) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$grp['id'], $title, $order, $kind, $beforeId, $afterId, $countSourceId, $countCategories]);
        if ($kind === 'benched') seed_benched_table($pdo, (int)$pdo->lastInsertId());
        respond_structure($pdo, $grp['template_id']);
    }

    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['sectionId'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    // Top-level tables are numbered automatically in the UI, so a title is optional here.
    $title = substr(trim($body['title'] ?? ''), 0, 100);
    $order = next_sort_order($pdo, 'raid_template_tables', 'section_id', $sec['id']);
    [$beforeId, $afterId] = $kind === 'swaps' ? resolve_swap_tables($pdo, $tenant['id'], $sec['template_id'], $body) : [null, null];
    $countSourceId = $kind === 'counter' ? resolve_count_source_table($pdo, $tenant['id'], $sec['template_id'], $body) : null;
    $countCategories = $kind === 'counter' ? (resolve_count_categories($body) ?? 'Tank,Healer') : null;
    $stmt = $pdo->prepare('INSERT INTO raid_template_tables (section_id, title, sort_order, kind, swap_before_table_id, swap_after_table_id, count_source_table_id, count_categories) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$sec['id'], $title, $order, $kind, $beforeId, $afterId, $countSourceId, $countCategories]);
    if ($kind === 'benched') seed_benched_table($pdo, (int)$pdo->lastInsertId());
    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'add_roster_table') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['sectionId'] ?? 0));
    if (!$sec) fail(404, 'Section not found');
    $raidSize = (int)($body['raidSize'] ?? 0);
    if ($raidSize !== 20 && $raidSize !== 40) fail(400, 'Raid size must be 20 or 40');
    $numCols = $raidSize === 40 ? 8 : 4;

    $order = next_sort_order($pdo, 'raid_template_tables', 'section_id', $sec['id']);
    $stmt = $pdo->prepare('INSERT INTO raid_template_tables (section_id, title, sort_order) VALUES (?, ?, ?)');
    $stmt->execute([$sec['id'], '', $order]);
    $tableId = (int)$pdo->lastInsertId();

    $colStmt = $pdo->prepare('INSERT INTO raid_template_columns (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    for ($i = 0; $i < $numCols; $i++) {
        $colStmt->execute([$tableId, 'Grp' . ($i + 1), $i, 'general']);
    }
    $rowStmt = $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    for ($i = 0; $i < 5; $i++) {
        $rowStmt->execute([$tableId, '', $i, 'general']);
    }
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
    $markStyle   = (array_key_exists('markStyle', $body) && in_array($body['markStyle'], ['star', 'number'], true)) ? $body['markStyle'] : $tb['mark_style'];
    $markEnabled = array_key_exists('markEnabled', $body) ? (!empty($body['markEnabled']) ? 1 : 0) : $tb['mark_enabled'];

    if ($tb['kind'] === 'swaps' && (array_key_exists('beforeTableId', $body) || array_key_exists('afterTableId', $body))) {
        $mergedBody = ['beforeTableId' => $body['beforeTableId'] ?? $tb['swap_before_table_id'], 'afterTableId' => $body['afterTableId'] ?? $tb['swap_after_table_id']];
        [$beforeId, $afterId] = resolve_swap_tables($pdo, $tenant['id'], $tb['template_id'], $mergedBody);
        $stmt = $pdo->prepare('UPDATE raid_template_tables SET title = ?, header_color = ?, default_column_width = ?, mark_style = ?, mark_enabled = ?, swap_before_table_id = ?, swap_after_table_id = ? WHERE id = ?');
        $stmt->execute([$title, $headerColor, $colWidth, $markStyle, $markEnabled, $beforeId, $afterId, $tb['id']]);
    } elseif ($tb['kind'] === 'counter' && (array_key_exists('countSourceTableId', $body) || array_key_exists('countCategories', $body))) {
        $countSourceId = array_key_exists('countSourceTableId', $body)
            ? resolve_count_source_table($pdo, $tenant['id'], $tb['template_id'], $body)
            : $tb['count_source_table_id'];
        $countCategories = resolve_count_categories($body) ?? $tb['count_categories'];
        $stmt = $pdo->prepare('UPDATE raid_template_tables SET title = ?, header_color = ?, default_column_width = ?, mark_style = ?, mark_enabled = ?, count_source_table_id = ?, count_categories = ? WHERE id = ?');
        $stmt->execute([$title, $headerColor, $colWidth, $markStyle, $markEnabled, $countSourceId, $countCategories, $tb['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE raid_template_tables SET title = ?, header_color = ?, default_column_width = ?, mark_style = ?, mark_enabled = ? WHERE id = ?');
        $stmt->execute([$title, $headerColor, $colWidth, $markStyle, $markEnabled, $tb['id']]);
    }
    respond_structure($pdo, $tb['template_id']);
}

if ($action === 'delete_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $templateId = $tb['template_id'];
    $stmt = $pdo->prepare('SELECT id, title FROM raid_template_tables WHERE swap_before_table_id = ? OR swap_after_table_id = ?');
    $stmt->execute([$tb['id'], $tb['id']]);
    if ($blocker = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fail(400, 'Used as a Swaps source by "' . ($blocker['title'] ?: 'a Swaps table') . '" — remove that link first');
    }
    $stmt = $pdo->prepare('SELECT id, title FROM raid_template_tables WHERE count_source_table_id = ?');
    $stmt->execute([$tb['id']]);
    if ($blocker = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fail(400, 'Used as a Counter source by "' . ($blocker['title'] ?: 'a Counter table') . '" — remove that link first');
    }
    $stmt = $pdo->prepare('DELETE FROM raid_template_tables WHERE id = ?');
    $stmt->execute([$tb['id']]);
    respond_structure($pdo, $templateId);
}

// Resolves swap_before_table_id/swap_after_table_id/count_source_table_id on a set of freshly
// duplicated tables. Any source table that was itself duplicated in the same operation
// (present in $tableIdMap) is repointed at its duplicate, same rationale as
// clone_template_to_guild()'s backfill pass in template_clone.php; a source table that lives
// outside the duplicated set (not in the map) keeps pointing at the original -- duplicating a
// Counter table, for instance, shouldn't also fork the roster table it counts.
function backfill_duplicate_links($pdo, array $tableIdMap, array $swapLinks, array $countLinks) {
    foreach ($swapLinks as $newTableId => $link) {
        [$beforeSrcId, $afterSrcId] = $link;
        $beforeId = $beforeSrcId !== null ? ($tableIdMap[$beforeSrcId] ?? $beforeSrcId) : null;
        $afterId  = $afterSrcId  !== null ? ($tableIdMap[$afterSrcId]  ?? $afterSrcId)  : null;
        $pdo->prepare('UPDATE raid_template_tables SET swap_before_table_id = ?, swap_after_table_id = ? WHERE id = ?')
            ->execute([$beforeId, $afterId, $newTableId]);
    }
    foreach ($countLinks as $newTableId => $srcId) {
        $countSourceId = $srcId !== null ? ($tableIdMap[$srcId] ?? $srcId) : null;
        $pdo->prepare('UPDATE raid_template_tables SET count_source_table_id = ? WHERE id = ?')
            ->execute([$countSourceId, $newTableId]);
    }
}

if ($action === 'duplicate_table') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$tb) fail(404, 'Table not found');

    $tableIdMap = []; $swapLinks = []; $countLinks = [];
    clone_template_table_recursive($pdo, $tb, $tb['section_id'], $tb['parent_group_id'], $tableIdMap, $swapLinks, $countLinks);
    backfill_duplicate_links($pdo, $tableIdMap, $swapLinks, $countLinks);

    if ($tb['title']) {
        $pdo->prepare('UPDATE raid_template_tables SET title = ? WHERE id = ?')
            ->execute([substr($tb['title'] . ' (copy)', 0, 100), $tableIdMap[$tb['id']]]);
    }
    respond_structure($pdo, $tb['template_id']);
}

if ($action === 'duplicate_section') {
    $sec = fetch_section_owned($pdo, $tenant['id'], (int)($body['id'] ?? 0));
    if (!$sec) fail(404, 'Section not found');

    $insSec = $pdo->prepare(
        'INSERT INTO raid_template_sections (template_id, kind, title, sort_order, note_enabled, note_text, mrt_export_enabled, color, bg_color)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insSec->execute([$sec['template_id'], $sec['kind'], $sec['title'] ? substr($sec['title'] . ' (copy)', 0, 100) : $sec['title'], $sec['sort_order'], $sec['note_enabled'], $sec['note_text'], $sec['mrt_export_enabled'], $sec['color'], $sec['bg_color']]);
    $newSectionId = (int)$pdo->lastInsertId();

    $tableIdMap = []; $swapLinks = []; $countLinks = [];
    $stmtT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
    $stmtT->execute([$sec['id']]);
    foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $childTb) {
        clone_template_table_recursive($pdo, $childTb, $newSectionId, null, $tableIdMap, $swapLinks, $countLinks);
    }
    backfill_duplicate_links($pdo, $tableIdMap, $swapLinks, $countLinks);

    respond_structure($pdo, $sec['template_id']);
}

if ($action === 'add_column' || $action === 'add_row') {
    $isCol = $action === 'add_column';
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['tableId'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    $kindIn = $body['kind'] ?? 'general';
    $kind = in_array($kindIn, ['text', 'general', 'spacer', 'icon'], true) ? $kindIn : 'general';
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
    $bold = !empty($body['bold']) ? 1 : 0;
    $font = in_array($body['font'] ?? null, ['serif', 'mono', 'display'], true) ? $body['font'] : null;
    $icon = in_array($body['icon'] ?? null, RAID_ICON_KEYS, true) ? $body['icon'] : null;
    $textAlign = in_array($body['textAlign'] ?? null, ['left', 'center', 'right'], true) ? $body['textAlign'] : null;

    $stmt = $pdo->prepare(
        'INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bg_color, text_color, bold, font, icon, text_align)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE text_content = VALUES(text_content), bg_color = VALUES(bg_color), text_color = VALUES(text_color), bold = VALUES(bold), font = VALUES(font), icon = VALUES(icon), text_align = VALUES(text_align)'
    );
    $stmt->execute([$col['table_id'], $row['id'], $col['id'], $textContent, $bgColor, $textColor, $bold, $font, $icon, $textAlign]);

    respond_structure($pdo, $col['template_id']);
}

if ($action === 'set_cell_kind_override') {
    $col = fetch_column_owned($pdo, $tenant['id'], (int)($body['columnId'] ?? 0));
    if (!$col) fail(404, 'Column not found');
    $row = fetch_row_owned($pdo, $tenant['id'], (int)($body['rowId'] ?? 0));
    if (!$row) fail(404, 'Row not found');
    if ((int)$col['table_id'] !== (int)$row['table_id']) fail(400, 'Row/column mismatch');

    $kindOverride = $body['kindOverride'] ?? null;
    if ($kindOverride !== null && !in_array($kindOverride, ['general', 'text', 'spacer', 'icon'], true)) {
        fail(400, 'Invalid kind override');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO raid_template_cells (table_id, row_id, column_id, kind_override, bg_color)
         VALUES (?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE kind_override = VALUES(kind_override), bg_color = IF(VALUES(kind_override) = "spacer", NULL, bg_color)'
    );
    $stmt->execute([$col['table_id'], $row['id'], $col['id'], $kindOverride]);

    respond_structure($pdo, $col['template_id']);
}

if ($action === 'add_rule') {
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)($body['tableId'] ?? 0));
    if (!$tb) fail(404, 'Table not found');
    if ($tb['kind'] !== 'standard') fail(400, 'Rules on a Benched or Swaps table are fixed and cannot be edited');

    [$ruleType, $scope, $classes, $maxCount, $label, $cellRefs] = parse_rule_fields($pdo, $body, $tb['id']);

    $sortOrder = next_sort_order($pdo, 'raid_template_rules', 'table_id', $tb['id']);
    $ins = $pdo->prepare('INSERT INTO raid_template_rules (table_id, rule_type, scope, classes, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$tb['id'], $ruleType, $scope, $classes, $maxCount, $label, $sortOrder]);
    $ruleId = (int)$pdo->lastInsertId();

    if ($cellRefs) {
        $insC = $pdo->prepare('INSERT INTO raid_template_rule_cells (rule_id, row_id, column_id) VALUES (?, ?, ?)');
        foreach ($cellRefs as [$rowId, $colId]) $insC->execute([$ruleId, $rowId, $colId]);
    }

    respond_structure($pdo, $tb['template_id']);
}

if ($action === 'update_rule') {
    $rule = fetch_rule_owned($pdo, $tenant['id'], (int)($body['ruleId'] ?? 0));
    if (!$rule) fail(404, 'Rule not found');
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)$rule['table_id']);
    if (!$tb || $tb['kind'] !== 'standard') fail(400, 'Rules on a Benched or Swaps table are fixed and cannot be edited');

    [$ruleType, $scope, $classes, $maxCount, $label, $cellRefs] = parse_rule_fields($pdo, $body, (int)$rule['table_id']);

    $upd = $pdo->prepare('UPDATE raid_template_rules SET rule_type = ?, scope = ?, classes = ?, max_count = ?, label = ? WHERE id = ?');
    $upd->execute([$ruleType, $scope, $classes, $maxCount, $label, $rule['id']]);

    $pdo->prepare('DELETE FROM raid_template_rule_cells WHERE rule_id = ?')->execute([$rule['id']]);
    if ($cellRefs) {
        $insC = $pdo->prepare('INSERT INTO raid_template_rule_cells (rule_id, row_id, column_id) VALUES (?, ?, ?)');
        foreach ($cellRefs as [$rowId, $colId]) $insC->execute([$rule['id'], $rowId, $colId]);
    }

    respond_structure($pdo, $rule['template_id']);
}

if ($action === 'delete_rule') {
    $rule = fetch_rule_owned($pdo, $tenant['id'], (int)($body['ruleId'] ?? 0));
    if (!$rule) fail(404, 'Rule not found');
    $tb = fetch_table_owned($pdo, $tenant['id'], (int)$rule['table_id']);
    if (!$tb || $tb['kind'] !== 'standard') fail(400, 'Rules on a Benched or Swaps table are fixed and cannot be edited');

    $pdo->prepare('DELETE FROM raid_template_rule_cells WHERE rule_id = ?')->execute([$rule['id']]);
    $pdo->prepare('DELETE FROM raid_template_rules WHERE id = ?')->execute([$rule['id']]);

    respond_structure($pdo, $rule['template_id']);
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

// Colour/Merge mode's drag-to-merge tool: the client already knows the exact colspan it
// wants (computed from every cell the drag passed over), unlike merge_cell's one-column-
// at-a-time increment, so this sets it directly in one call.
if ($action === 'set_cell_merge') {
    $col = fetch_column_owned($pdo, $tenant['id'], (int)($body['columnId'] ?? 0));
    $row = fetch_row_owned($pdo, $tenant['id'], (int)($body['rowId'] ?? 0));
    if (!$col || !$row) fail(404, 'Not found');
    if ((int)$col['table_id'] !== (int)$row['table_id']) fail(400, 'Row/column must belong to the same table');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM raid_template_columns WHERE table_id = ? AND sort_order >= ?');
    $stmt->execute([$col['table_id'], $col['sort_order']]);
    $maxColSpan = (int)$stmt->fetchColumn();
    $colspan = max(1, min($maxColSpan, (int)($body['colspan'] ?? 1)));

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM raid_template_rows WHERE table_id = ? AND sort_order >= ?');
    $stmt->execute([$row['table_id'], $row['sort_order']]);
    $maxRowSpan = (int)$stmt->fetchColumn();
    $rowspan = max(1, min($maxRowSpan, (int)($body['rowspan'] ?? 1)));

    if ($colspan <= 1 && $rowspan <= 1) {
        $stmt = $pdo->prepare('DELETE FROM raid_template_cell_merges WHERE row_id = ? AND column_id = ?');
        $stmt->execute([$row['id'], $col['id']]);
    } else {
        // HTML colspan/rowspan can only extend right/down from the anchor, so the merge's
        // structural anchor (row/col above) must stay top-left of the rectangle. But the cell
        // the user actually clicked to start the drag is what they expect to see survive as
        // the merged cell's content -- so if it differs from the anchor, swap the two
        // positions' data (reversibly: splitting the merge later reveals the pre-swap values
        // at their original positions, nothing is lost).
        $primaryRowId = (int)($body['primaryRowId'] ?? 0);
        $primaryColumnId = (int)($body['primaryColumnId'] ?? 0);
        if ($primaryRowId && $primaryColumnId && ($primaryRowId !== (int)$row['id'] || $primaryColumnId !== (int)$col['id'])) {
            $primaryRow = fetch_row_owned($pdo, $tenant['id'], $primaryRowId);
            $primaryCol = fetch_column_owned($pdo, $tenant['id'], $primaryColumnId);
            if ($primaryRow && $primaryCol && (int)$primaryRow['table_id'] === (int)$col['table_id'] && (int)$primaryCol['table_id'] === (int)$col['table_id']) {
                $cellFields = ['text_content' => null, 'bg_color' => null, 'text_color' => null, 'bold' => null, 'font' => null, 'icon' => null, 'kind_override' => null, 'text_align' => null];
                $stmtCell = $pdo->prepare('SELECT text_content, bg_color, text_color, bold, font, icon, kind_override, text_align FROM raid_template_cells WHERE table_id = ? AND row_id = ? AND column_id = ?');
                $stmtCell->execute([$col['table_id'], $row['id'], $col['id']]);
                $anchorCell = $stmtCell->fetch(PDO::FETCH_ASSOC) ?: $cellFields;
                $stmtCell->execute([$col['table_id'], $primaryRow['id'], $primaryCol['id']]);
                $primaryCell = $stmtCell->fetch(PDO::FETCH_ASSOC) ?: $cellFields;

                $stmtUpsert = $pdo->prepare(
                    'INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override, text_align)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE text_content = VALUES(text_content), bg_color = VALUES(bg_color), text_color = VALUES(text_color), bold = VALUES(bold), font = VALUES(font), icon = VALUES(icon), kind_override = VALUES(kind_override), text_align = VALUES(text_align)'
                );
                $stmtUpsert->execute([$col['table_id'], $row['id'], $col['id'], $primaryCell['text_content'] ?? '', $primaryCell['bg_color'], $primaryCell['text_color'], $primaryCell['bold'] ?? 0, $primaryCell['font'], $primaryCell['icon'], $primaryCell['kind_override'], $primaryCell['text_align']]);
                $stmtUpsert->execute([$col['table_id'], $primaryRow['id'], $primaryCol['id'], $anchorCell['text_content'] ?? '', $anchorCell['bg_color'], $anchorCell['text_color'], $anchorCell['bold'] ?? 0, $anchorCell['font'], $anchorCell['icon'], $anchorCell['kind_override'], $anchorCell['text_align']]);
            }
        }

        // A smaller merge fully swallowed by this bigger rectangle would otherwise sit
        // orphaned-but-inert (its anchor is now a covered cell) and could reappear as a
        // phantom merge if this bigger merge is later split -- remove it up front instead.
        $stmt = $pdo->prepare(
            'DELETE m FROM raid_template_cell_merges m
             JOIN raid_template_rows r ON r.id = m.row_id
             JOIN raid_template_columns c ON c.id = m.column_id
             WHERE m.table_id = ?
               AND r.sort_order BETWEEN ? AND ?
               AND c.sort_order BETWEEN ? AND ?
               AND NOT (m.row_id = ? AND m.column_id = ?)'
        );
        $stmt->execute([
            $col['table_id'],
            $row['sort_order'], $row['sort_order'] + $rowspan - 1,
            $col['sort_order'], $col['sort_order'] + $colspan - 1,
            $row['id'], $col['id'],
        ]);

        $stmt = $pdo->prepare('INSERT INTO raid_template_cell_merges (table_id, row_id, column_id, colspan, rowspan) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE colspan = VALUES(colspan), rowspan = VALUES(rowspan)');
        $stmt->execute([$col['table_id'], $row['id'], $col['id'], $colspan, $rowspan]);
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

// --- Undo: id-preserving snapshot restore -------------------------------------------------
//
// push-template.php's sync_table() matches a raid's rows/columns/tables/groups/rules back to
// the template via a source_*_id FK on the raid side that points at the template entity's own
// id (see raid_structure.php's copy_table_recursive()). So Undo can't just wipe and recreate
// the template's structure -- every raid built from it would desync on the next push. Instead
// this diffs a client-held snapshot (an earlier fetch_structure()/fetch_tab_exports() response)
// against the live rows, matched by their own id: UPDATE what changed, DELETE what's live but
// missing from the snapshot, and re-INSERT what's missing live *with its original id* (MySQL
// allows an explicit-id insert into an AUTO_INCREMENT PK; the counter just advances past it).

// Diffs one table's live rows (scoped by $fkCol = $fkVal) against $snapshotList -- an ordered
// array of assoc arrays shaped like fetch_structure()'s client JSON -- by each item's own id.
// $fieldMap maps DB column => [snapshot key, cast] ('str'|'int'|'bool'); sort_order is derived
// from array position (fetch_structure() never exposes it to the client) unless an item carries
// a '__sortOrder' override (used by column-group topological reordering, see below).
// $extraFixed supplies DB columns whose value isn't in the snapshot item at all -- e.g. a
// table's own section_id/parent_group_id, which the caller fixes by scope rather than the JSON.
function restore_diff_entities($pdo, $table, $fkCol, $fkVal, array $snapshotList, array $fieldMap, array $extraFixed = []) {
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE $fkCol = ?");
    $stmt->execute([$fkVal]);
    $liveIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $dbCols = array_keys($fieldMap);
    $fixedCols = array_keys($extraFixed);
    $seen = [];

    foreach ($snapshotList as $i => $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) continue;
        $seen[] = $id;

        $values = [];
        foreach ($fieldMap as $dbCol => $spec) {
            [$key, $cast] = $spec;
            $v = $item[$key] ?? null;
            if ($cast === 'bool') $v = $v ? 1 : 0;
            elseif ($cast === 'int' && $v !== null) $v = (int)$v;
            $values[$dbCol] = $v;
        }
        $values['sort_order'] = $item['__sortOrder'] ?? $i;
        foreach ($extraFixed as $col => $val) $values[$col] = $val;

        if (in_array($id, $liveIds, true)) {
            $sets = [];
            $params = [];
            foreach (array_merge($dbCols, ['sort_order'], $fixedCols) as $col) {
                $sets[] = "$col = ?";
                $params[] = $values[$col];
            }
            $params[] = $id;
            $pdo->prepare("UPDATE $table SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        } else {
            $cols = array_merge(['id', $fkCol], $dbCols, ['sort_order'], $fixedCols);
            $params = array_merge([$id, $fkVal], array_map(fn($c) => $values[$c], array_merge($dbCols, ['sort_order'], $fixedCols)));
            $placeholders = implode(',', array_fill(0, count($params), '?'));
            $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($placeholders)")->execute($params);
        }
    }

    foreach ($liveIds as $id) {
        if (!in_array($id, $seen, true)) {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
        }
    }
}

// Column groups are self-referential (parentGroupId -> another group's own id) and DB-FK
// enforced, so a freshly re-inserted parent must exist before any child referencing it is
// inserted/updated. Reorders (stable, depth-first) so every group appears after its parent;
// each item keeps a '__sortOrder' tag carrying its original array position, since this reorder
// must not itself change sort_order.
function topological_sort_groups(array $groups) {
    $byId = [];
    foreach ($groups as $g) $byId[(int)$g['id']] = $g;
    $out = [];
    $placed = [];
    $visit = function ($g) use (&$visit, &$out, &$placed, $byId) {
        $id = (int)$g['id'];
        if (isset($placed[$id])) return;
        $parentId = $g['parentGroupId'] ?? null;
        if ($parentId !== null && isset($byId[(int)$parentId])) {
            $visit($byId[(int)$parentId]);
        }
        $placed[$id] = true;
        $out[] = $g;
    };
    foreach ($groups as $g) $visit($g);
    return $out;
}

// Restores one table's own contents (columns/rows/groups/rules id-preserving; cell_merges/
// cells/rule_cells wholesale) from $snapshotTable, then recurses into any nested boss-tables
// living inside its column groups -- mirroring fetch_table_full()'s own recursive shape.
function restore_table_recursive($pdo, $tableId, array $snapshotTable) {
    $indexedGroups = [];
    foreach ($snapshotTable['columnGroups'] ?? [] as $i => $g) {
        $g['__sortOrder'] = $i;
        $indexedGroups[] = $g;
    }
    $sortedGroups = topological_sort_groups($indexedGroups);
    // Groups before columns: raid_template_columns.group_id is FK-constrained to
    // raid_template_column_groups.id, so a re-inserted group must exist first.
    restore_diff_entities($pdo, 'raid_template_column_groups', 'table_id', $tableId, $sortedGroups, [
        'parent_group_id' => ['parentGroupId', 'int'],
        'title'           => ['title', 'str'],
        'color'           => ['color', 'str'],
    ]);

    restore_diff_entities($pdo, 'raid_template_columns', 'table_id', $tableId, $snapshotTable['columns'] ?? [], [
        'label'          => ['label', 'str'],
        'kind'           => ['kind', 'str'],
        'width'          => ['width', 'int'],
        'header_color'   => ['headerColor', 'str'],
        'bg_color'       => ['bgColor', 'str'],
        'group_id'       => ['groupId', 'int'],
        'header_colspan' => ['headerColspan', 'int'],
    ]);

    restore_diff_entities($pdo, 'raid_template_rows', 'table_id', $tableId, $snapshotTable['rows'] ?? [], [
        'label'    => ['label', 'str'],
        'kind'     => ['kind', 'str'],
        'height'   => ['height', 'int'],
        'bg_color' => ['bgColor', 'str'],
    ]);

    // raid_template_rule_cells has no FK at all, so deleting a rule (missing from the snapshot)
    // wouldn't cascade-clean its cell refs -- match delete_rule's own explicit cleanup here too,
    // before the rules diff below removes the rule row itself.
    $stmt = $pdo->prepare('SELECT id FROM raid_template_rules WHERE table_id = ?');
    $stmt->execute([$tableId]);
    $liveRuleIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $snapshotRuleIds = array_map(fn($r) => (int)($r['id'] ?? 0), $snapshotTable['rules'] ?? []);
    $deletedRuleIds = array_diff($liveRuleIds, $snapshotRuleIds);
    if ($deletedRuleIds) {
        $ph = implode(',', array_fill(0, count($deletedRuleIds), '?'));
        $pdo->prepare("DELETE FROM raid_template_rule_cells WHERE rule_id IN ($ph)")->execute(array_values($deletedRuleIds));
    }

    restore_diff_entities($pdo, 'raid_template_rules', 'table_id', $tableId, $snapshotTable['rules'] ?? [], [
        'rule_type' => ['ruleType', 'str'],
        'scope'     => ['scope', 'str'],
        'classes'   => ['classes', 'str'],
        'max_count' => ['maxCount', 'int'],
        'label'     => ['label', 'str'],
    ]);

    // cell_merges/cells are matched only by (row_id, column_id), never referenced by their own
    // id elsewhere, so a wholesale table-scoped resync is simplest -- correct now that rows/
    // columns above have their original ids back.
    $pdo->prepare('DELETE FROM raid_template_cell_merges WHERE table_id = ?')->execute([$tableId]);
    $insMerge = $pdo->prepare('INSERT INTO raid_template_cell_merges (table_id, row_id, column_id, colspan, rowspan) VALUES (?, ?, ?, ?, ?)');
    foreach ($snapshotTable['cellMerges'] ?? [] as $m) {
        $insMerge->execute([$tableId, (int)$m['rowId'], (int)$m['columnId'], (int)($m['colspan'] ?? 1), (int)($m['rowspan'] ?? 1)]);
    }

    $pdo->prepare('DELETE FROM raid_template_cells WHERE table_id = ?')->execute([$tableId]);
    $insCell = $pdo->prepare(
        'INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override, text_align)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($snapshotTable['cells'] ?? [] as $c) {
        $insCell->execute([
            $tableId, (int)$c['rowId'], (int)$c['columnId'],
            $c['textContent'] ?? '', $c['bgColor'] ?? null, $c['textColor'] ?? null,
            !empty($c['bold']) ? 1 : 0, $c['font'] ?? null, $c['icon'] ?? null, $c['kindOverride'] ?? null, $c['textAlign'] ?? null,
        ]);
    }

    // Rule-cell refs, keyed only by rule_id -- rules above kept their ids, so this can stay
    // scoped per rule rather than needing a table-wide join.
    foreach ($snapshotTable['rules'] ?? [] as $rule) {
        $ruleId = (int)($rule['id'] ?? 0);
        if (!$ruleId) continue;
        $pdo->prepare('DELETE FROM raid_template_rule_cells WHERE rule_id = ?')->execute([$ruleId]);
        $insRC = $pdo->prepare('INSERT INTO raid_template_rule_cells (rule_id, row_id, column_id) VALUES (?, ?, ?)');
        foreach ($rule['cellRefs'] ?? [] as $ref) {
            $insRC->execute([$ruleId, (int)$ref['rowId'], (int)$ref['columnId']]);
        }
    }

    // Nested boss-tables live inside column groups, recursed the same way fetch_table_full()
    // builds them.
    foreach ($sortedGroups as $g) {
        $groupId = (int)$g['id'];
        restore_diff_entities($pdo, 'raid_template_tables', 'parent_group_id', $groupId, $g['tables'] ?? [], [
            'title'                 => ['title', 'str'],
            'header_color'          => ['headerColor', 'str'],
            'bg_color'              => ['bgColor', 'str'],
            'default_column_width'  => ['defaultColumnWidth', 'int'],
        ], ['section_id' => null]);
        foreach ($g['tables'] ?? [] as $childTb) {
            $childId = (int)($childTb['id'] ?? 0);
            if ($childId) restore_table_recursive($pdo, $childId, $childTb);
        }
    }
}

function restore_snapshot($pdo, $templateId, array $snapshotSections, array $snapshotTabExports) {
    restore_diff_entities($pdo, 'raid_template_sections', 'template_id', $templateId, $snapshotSections, [
        'kind'               => ['kind', 'str'],
        'title'              => ['title', 'str'],
        'color'              => ['color', 'str'],
        'bg_color'           => ['bgColor', 'str'],
        'note_enabled'       => ['noteEnabled', 'bool'],
        'note_text'          => ['noteText', 'str'],
        'mrt_export_enabled' => ['mrtExportEnabled', 'bool'],
    ]);

    foreach ($snapshotSections as $sec) {
        $sectionId = (int)($sec['id'] ?? 0);
        if (!$sectionId) continue;
        restore_diff_entities($pdo, 'raid_template_tables', 'section_id', $sectionId, $sec['tables'] ?? [], [
            'title'                 => ['title', 'str'],
            'header_color'          => ['headerColor', 'str'],
            'bg_color'              => ['bgColor', 'str'],
            'default_column_width'  => ['defaultColumnWidth', 'int'],
        ], ['parent_group_id' => null]);
        foreach ($sec['tables'] ?? [] as $tb) {
            $tableId = (int)($tb['id'] ?? 0);
            if ($tableId) restore_table_recursive($pdo, $tableId, $tb);
        }
    }

    // Tab-export config is template-scoped and never referenced by raid-side sync at all
    // (confirmed: no source_*_id anywhere near it), so a full wholesale resync -- ignoring the
    // snapshot's own export-page ids entirely -- is simplest and correct.
    $pdo->prepare('DELETE FROM raid_template_tab_exports WHERE template_id = ?')->execute([$templateId]);
    $insTE = $pdo->prepare('INSERT INTO raid_template_tab_exports (template_id, kind, enabled, single_page, export_name) VALUES (?, ?, ?, ?, ?)');
    $insPage = $pdo->prepare('INSERT INTO raid_template_export_pages (tab_export_id, name, template, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($snapshotTabExports as $kind => $te) {
        $insTE->execute([$templateId, $kind, !empty($te['enabled']) ? 1 : 0, !empty($te['singlePage']) ? 1 : 0, $te['exportName'] ?? null]);
        $tabExportId = (int)$pdo->lastInsertId();
        foreach ($te['pages'] ?? [] as $i => $p) {
            $insPage->execute([$tabExportId, $p['name'] ?? '', $p['template'] ?? '', $i]);
        }
    }
}

if ($action === 'restore_snapshot') {
    $templateId = isset($body['templateId']) ? (int)$body['templateId'] : 0;
    $template = fetch_template($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Template not found');
    $snapshotSections = is_array($body['sections'] ?? null) ? $body['sections'] : [];
    $snapshotTabExports = is_array($body['tabExports'] ?? null) ? $body['tabExports'] : [];
    $pdo->beginTransaction();
    try {
        restore_snapshot($pdo, $templateId, $snapshotSections, $snapshotTabExports);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail(500, 'Restore failed');
    }
    respond_structure($pdo, $templateId);
}

fail(400, 'Unknown action');
