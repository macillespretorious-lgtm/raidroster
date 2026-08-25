<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/nav.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

$role = require_role($tenant, 'admin');
$user = auth_user();
$pdo  = db_connect();

$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM raid_templates WHERE id = ? AND guild_id = ?');
$stmt->execute([$templateId, $tenant['id']]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$template) {
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

function fetch_table_full($pdo, $tb) {
    $stmt3 = $pdo->prepare('SELECT id, label, kind, width, header_color, bg_color, group_id, header_colspan FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmt3->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'bgColor' => $c['bg_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmt3->fetchAll(PDO::FETCH_ASSOC));

    $stmt4 = $pdo->prepare('SELECT id, label, kind, height, bg_color FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmt4->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'height' => $r['height'] !== null ? (int)$r['height'] : null, 'bgColor' => $r['bg_color']], $stmt4->fetchAll(PDO::FETCH_ASSOC));

    $stmt5 = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmt5->execute([$tb['id']]);
    $groupRows = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    $columnGroups = [];
    foreach ($groupRows as $g) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$g['id']]);
        $childTables = array_map(fn($ctb) => fetch_table_full($pdo, $ctb), $stmtGT->fetchAll(PDO::FETCH_ASSOC));
        $columnGroups[] = [
            'id' => (int)$g['id'],
            'parentGroupId' => $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null,
            'title' => $g['title'], 'color' => $g['color'],
            'tables' => $childTables,
        ];
    }

    $stmt6 = $pdo->prepare('SELECT row_id, column_id, colspan, rowspan FROM raid_template_cell_merges WHERE table_id = ?');
    $stmt6->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'], 'columnId' => (int)$m['column_id'], 'colspan' => (int)$m['colspan'], 'rowspan' => (int)$m['rowspan'],
    ], $stmt6->fetchAll(PDO::FETCH_ASSOC));

    $stmt7 = $pdo->prepare('SELECT row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override FROM raid_template_cells WHERE table_id = ?');
    $stmt7->execute([$tb['id']]);
    $cells = array_map(fn($c) => [
        'rowId' => (int)$c['row_id'], 'columnId' => (int)$c['column_id'],
        'textContent' => $c['text_content'], 'bgColor' => $c['bg_color'], 'textColor' => $c['text_color'],
        'bold' => (bool)$c['bold'], 'font' => $c['font'], 'icon' => $c['icon'],
        'kindOverride' => $c['kind_override'],
    ], $stmt7->fetchAll(PDO::FETCH_ASSOC));

    $stmt8 = $pdo->prepare('SELECT id, rule_type, scope, classes, max_count, label, sort_order FROM raid_template_rules WHERE table_id = ? ORDER BY sort_order, id');
    $stmt8->execute([$tb['id']]);
    $ruleRows = $stmt8->fetchAll(PDO::FETCH_ASSOC);
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
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'bgColor' => $tb['bg_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups,
        'cellMerges' => $cellMerges, 'cells' => $cells, 'rules' => $rules,
    ];
}

function fetch_structure($pdo, $templateId) {
    $out = [];
    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmt2 = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmt2->execute([$sec['id']]);
        $tables = array_map(fn($tb) => fetch_table_full($pdo, $tb), $stmt2->fetchAll(PDO::FETCH_ASSOC));
        $out[] = [
            'id' => (int)$sec['id'],
            'kind' => $sec['kind'],
            'title' => $sec['title'],
            'color' => $sec['color'],
            'bgColor' => $sec['bg_color'],
            'tables' => $tables,
            'noteEnabled' => (bool)$sec['note_enabled'],
            'noteText' => $sec['note_text'],
            'mrtExportEnabled' => (bool)$sec['mrt_export_enabled'],
        ];
    }
    return $out;
}
$sections = fetch_structure($pdo, $templateId);

// AngryERA export config is per-tab (per distinct section `kind` on this template), not
// global — a template may have zero, one, or several kinds with export configured.
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
$tabExports = fetch_tab_exports($pdo, $templateId);

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit structure &mdash; <?= h($template['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif; color: #e8ecff; }
    .wrap { max-width: 100%; margin: 0; padding: 32px 32px 110px; }
    .back { color: #7f8bad; font-size: 12px; text-decoration: none; }
    .back:hover { color: #a3adfa; }
    h1 { font-size: 20px; }
    p.sub { color: #a8b4d0; font-size: 13px; margin-bottom: 24px; }
    .tag { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; padding: 3px 9px; border-radius: 999px; font-weight: 700; background: rgba(255,255,255,0.08); color: #a8b4d0; }

    .page-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 10px 0 4px; flex-wrap: wrap; }

    /* Sticky so it stays available while the page scrolls -- only the sections/tables
       below scroll out of view. Pinned just under the site topbar (.rr-topbar, 56px). */
    .controls-bar { position: sticky; top: 56px; z-index: 15; background: #0a0f1e; padding: 8px 0; margin: 0 0 14px; display: flex; flex-direction: column; gap: 6px; border-bottom: 1px solid rgba(255,255,255,0.08); }

    .tabs-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .angry-inline { display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: #7f8bad; white-space: nowrap; }
    .angry-inline .lock-toggle { font-size: 11.5px; font-weight: 700; color: #a8b4d0; }
    .angry-inline-meta { color: #7f8bad; }
    .angry-inline button.btn { padding: 5px 12px; font-size: 11.5px; }

    .tabs { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
    .tab-add-btn { background: none; border: 1px dashed rgba(255,255,255,0.25); color: #8fe0a8; border-radius: 999px; padding: 5px 12px; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; margin: 0 0 8px 8px; }
    .tab-add-btn:hover { border-color: rgba(143,224,168,0.6); background: rgba(143,224,168,0.08); }
    .tab-delete-btn { background: rgba(224,85,85,0.12); border: 1px solid rgba(224,85,85,0.3); color: #e88585; }
    .tab-delete-btn:hover { background: rgba(224,85,85,0.25); }
    .tab-btn { background: none; border: none; font: inherit; cursor: pointer; color: #7f8bad; font-size: 13px; font-weight: 600; padding: 7px 14px; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .tab-btn:hover { color: #c7cef2; }
    .tab-btn.active { color: #e8ecff; border-bottom-color: #5865f2; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    .section-card { border-radius: 10px; overflow: hidden; margin-bottom: 18px; border: 1px solid rgba(255,255,255,0.08); }
    .section-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; }
    .section-head .title-input { background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); color: #fff; font: inherit; font-size: 14px; font-weight: 700; padding: 5px 9px; border-radius: 6px; flex: 1; min-width: 0; }
    .section-body { background: #111827; padding: 14px 16px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 14px; }
    .section-note-bar { display: flex; align-items: center; gap: 12px; padding: 8px 16px; background: #161f36; border-top: 1px solid rgba(255,255,255,0.06); }
    .note-toggle-label { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #a8b4d0; white-space: nowrap; cursor: pointer; }
    .note-text-input { flex: 1; min-width: 0; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); color: #fff; font: inherit; font-size: 12.5px; padding: 5px 9px; border-radius: 6px; }
    .icon-btn { background: rgba(255,255,255,0.12); border: none; color: #fff; width: 26px; height: 26px; border-radius: 6px; cursor: pointer; font-size: 13px; line-height: 1; flex-shrink: 0; }
    .icon-btn:hover { background: rgba(255,255,255,0.22); }
    .icon-btn.danger:hover { background: rgba(224,85,85,0.7); }

    .tbl-card { border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px; min-width: 0; max-width: 100%; }
    .tbl-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; cursor: grab; }
    .tbl-head:active { cursor: grabbing; }
    .tbl-head input.tbl-title { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 13px; font-weight: 600; padding: 6px 9px; border-radius: 6px; flex: 1; min-width: 0; }
    .grid-scroll { overflow-x: auto; }
    .grid-scroll + .grid-scroll { margin-top: 2px; }
    table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12px; }
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 4px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
    table.grid th { background: rgba(255,255,255,0.04); }
    table.grid th.row-th { background: transparent; border-right-color: rgba(255,255,255,0.16); }
    .lbl-input { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 12px; padding: 4px 6px; border-radius: 5px; width: 100%; min-width: 0; box-sizing: border-box; }
    .cell-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 3px; margin-top: 2px; }
    .cell-actions .icon-btn { width: 20px; height: 20px; font-size: 10px; }
    td.data-td { position: relative; min-width: 24px; min-height: 24px; background: rgba(255,255,255,0.04); }
    /* Real in-flow content, not just a min-height on the (otherwise entirely
       absolutely-positioned-content) <td> — table cell min-height is applied
       inconsistently across browsers when the cell has no in-flow content at all. */
    .cell-height-spacer { height: 16px; }
    .add-row-btn { background: none; border: 1px dashed rgba(255,255,255,0.2); color: #a8b4d0; border-radius: 6px; padding: 5px 10px; font: inherit; font-size: 12px; cursor: pointer; }
    .add-row-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .tbl-actions-row { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }

    input[type=color].swatch { -webkit-appearance: none; appearance: none; width: 24px; height: 24px; padding: 0; border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; background: none; cursor: pointer; flex-shrink: 0; }
    input[type=color].swatch::-webkit-color-swatch-wrapper { padding: 2px; }
    input[type=color].swatch::-webkit-color-swatch { border: none; border-radius: 3px; }
    .paint-custom-swatch { position: relative; width: 22px; height: 22px; border-radius: 6px; border: 2px dashed rgba(255,255,255,0.4); cursor: pointer; display: inline-block; overflow: hidden; flex-shrink: 0; }
    .paint-custom-swatch:hover { border-color: rgba(255,255,255,0.7); }
    .paint-custom-swatch.active { border-style: solid; border-color: #fff; box-shadow: 0 0 0 2px #5865f2; }
    .paint-custom-swatch input[type=color] { position: absolute; inset: 0; width: 100%; height: 100%; padding: 0; border: none; opacity: 0; cursor: pointer; }
    .width-input { width: 52px; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 11px; padding: 4px 5px; border-radius: 5px; }
    .tbl-sizing { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #7f8bad; text-transform: uppercase; letter-spacing: .04em; }
    .col-th-inner { display: flex; flex-direction: column; gap: 3px; align-items: center; min-width: 0; }
    .row-th-inner { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .row-th-top { display: flex; align-items: center; justify-content: center; gap: 3px; min-width: 0; cursor: grab; }
    .row-th-top:active { cursor: grabbing; }
    .row-th-top .icon-btn { width: 18px; height: 18px; font-size: 10px; }
    .col-th-top { cursor: grab; display: flex; align-items: center; justify-content: center; gap: 3px; padding: 2px 0; }
    .col-th-top:active { cursor: grabbing; }
    .col-th-top .icon-btn { width: 18px; height: 18px; font-size: 10px; }
    .group-strip { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; align-items: center; }
    .group-pill { display: flex; align-items: center; gap: 5px; padding: 3px 4px 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; cursor: grab; }
    .group-pill:active { cursor: grabbing; }
    .group-pill input.group-title { background: transparent; border: none; color: inherit; font: inherit; font-weight: 700; width: auto; max-width: 110px; }
    .group-pill .icon-btn { width: 18px; height: 18px; font-size: 10px; background: rgba(0,0,0,0.25); }
    .add-group-btn { background: none; border: 1px dashed rgba(255,255,255,0.25); color: #a8b4d0; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 11px; cursor: pointer; }
    .add-group-btn:hover { border-color: rgba(255,255,255,0.5); color: #e8ecff; }
    .group-tables { display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 14px; margin: 6px 0 12px 4px; padding-left: 10px; border-left: 3px solid rgba(255,255,255,0.15); }
    td.spacer-cell, th.spacer-th { background: repeating-linear-gradient(135deg, rgba(255,255,255,0.03) 0 6px, transparent 6px 12px); border-style: dashed; }

    .kind-picker-wrap { position: relative; }
    /* position: fixed (with top/left set in JS from the trigger button's rect) so the
       popover escapes .section-card's overflow:hidden — that rule exists only to clip the
       section header's square corners behind its rounded border. */
    .kind-picker { position: fixed; background: #1a2338; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px; z-index: 20; min-width: 140px; box-shadow: 0 6px 20px rgba(0,0,0,0.4); }
    .kind-picker[hidden] { display: none; }
    .kind-picker button { background: none; border: none; color: #e8ecff; text-align: left; padding: 6px 10px; border-radius: 5px; font: inherit; font-size: 12px; cursor: pointer; }
    .kind-picker button:hover { background: rgba(255,255,255,0.1); }

    td.text-td { text-align: left; }
    .cell-bold-btn { width: 20px; height: 18px; padding: 0; border-radius: 4px; border: 1px solid rgba(255,255,255,0.15); background: #0a0f1e; color: #a8b4d0; font-weight: 800; font-size: 11px; line-height: 1; cursor: pointer; }
    .cell-bold-btn.active { background: #5865f2; border-color: #5865f2; color: #fff; }
    .cell-font-select { flex: 1; min-width: 0; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font-size: 10.5px; border-radius: 4px; padding: 2px 2px; }

    .cell-text-display { min-height: 20px; cursor: pointer; border-radius: 4px; padding: 3px 5px; margin: -3px -5px; }
    .cell-text-display:hover { background: rgba(255,255,255,0.06); }
    .cell-text-placeholder { color: #5c6785; font-style: italic; font-size: 11px; }
    .inline-raid-icon { margin: 0 1px; }

    td.icon-td { text-align: center; }
    .cell-icon-wrap { display: inline-flex; }
    .icon-pick-btn { width: 30px; height: 30px; border-radius: 6px; border: 1px dashed rgba(255,255,255,0.25); background: rgba(255,255,255,0.03); color: #a8b4d0; font-size: 16px; line-height: 1; cursor: pointer; padding: 0; }
    .icon-pick-btn:not(.empty) { border-style: solid; border-color: rgba(255,255,255,0.15); }
    .icon-pick-btn:hover { border-color: rgba(255,255,255,0.5); }
    .icon-picker { flex-direction: row !important; flex-wrap: wrap; max-width: 150px; }
    .icon-swatch-btn { border: 1px solid rgba(255,255,255,0.15); border-radius: 5px; background: rgba(255,255,255,0.03); cursor: pointer; padding: 0; }
    .icon-swatch-btn:hover { border-color: #5865f2; }
    .raid-icon-cell { display: inline-block; }

    .drag-handle { cursor: grab; display: inline-block; color: #6b7595; font-size: 13px; line-height: 1; user-select: none; }
    .drag-handle:active { cursor: grabbing; }
    [data-drop-kind].drag-over { outline: 2px dashed #5865f2; outline-offset: -2px; }

    .lock-bar { margin: 0; flex-shrink: 0; }
    .lock-toggle { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: #a8b4d0; cursor: pointer; user-select: none; }
    .lock-toggle input { display: none; }
    .lock-switch { width: 34px; height: 19px; border-radius: 999px; background: rgba(255,255,255,0.15); position: relative; transition: background .15s; flex-shrink: 0; }
    .lock-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 15px; height: 15px; border-radius: 50%; background: #e8ecff; transition: transform .15s; }
    .lock-toggle input:checked + .lock-switch { background: #4caf6a; }
    .lock-toggle input:checked + .lock-switch::after { transform: translateX(15px); }
    .lock-banner { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 8px; background: rgba(240,128,48,0.1); border: 1px solid rgba(240,128,48,0.3); color: #f0a030; font-size: 12px; }
    .lock-banner button.btn { background: #e05555; padding: 5px 12px; font-size: 11px; }
    .lock-banner button.btn:hover { background: #c94444; }
    .lock-status { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #8fe0a8; }
    .lock-dot { width: 8px; height: 8px; border-radius: 50%; background: #4caf6a; flex-shrink: 0; }
    .lock-release-btn { background: none; border: 1px solid rgba(255,255,255,0.15); color: #a8b4d0; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 11px; cursor: pointer; }
    .lock-release-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }

    button.btn { display: inline-block; padding: 7px 16px; font: inherit; background: #5865f2; border: none; border-radius: 999px; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    button.btn:hover { background: #4752c4; }

    /* Preview modal: read-only rendering ported from raids/view.php, scoped under
       .preview-modal so its class names (section-card, tbl-wrap, table.grid, ...) don't
       collide with this editor's own same-named editing styles. */
    .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    .modal-backdrop.open { display: flex; }
    .modal.preview-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 960px; max-height: 90vh; overflow-y: auto; }
    .preview-modal .preview-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
    .preview-modal h2 { font-size: 16px; }
    .preview-modal .preview-note { font-size: 12px; color: #7f8bad; margin-bottom: 16px; }
    .modal.export-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; }
    .modal.cell-edit-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 480px; display: flex; flex-direction: column; gap: 10px; }
    #cellEditTextarea { width: 100%; min-height: 90px; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; color: #e8ecff; font: inherit; font-size: 13px; padding: 10px; resize: vertical; }
    .cell-edit-icon-palette { display: flex; flex-wrap: wrap; gap: 6px; }
    .cell-edit-format-row { display: flex; align-items: center; gap: 8px; }
    .cell-edit-format-row .cell-font-select { flex: 1; padding: 6px; font-size: 12px; }
    .cell-edit-format-row .cell-bold-btn { width: 28px; height: 28px; font-size: 13px; }
    .export-modal .preview-note code { background: rgba(255,255,255,0.08); padding: 1px 5px; border-radius: 4px; font-size: 11px; }
    .export-tokens { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
    .export-tokens span { background: rgba(76,175,106,0.15); border: 1px solid rgba(76,175,106,0.35); color: #8fe0a8; font-size: 11px; padding: 3px 8px; border-radius: 999px; font-family: 'Courier New', monospace; }
    .angry-page-pane textarea { width: 100%; min-height: 260px; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; color: #e8ecff; font: 12.5px/1.5 'Courier New', monospace; padding: 10px; resize: vertical; }
    .export-status { font-size: 12px; color: #4caf6a; margin-top: 8px; min-height: 16px; }
    .export-modal .modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
    .preview-modal .section-card { border-radius: 12px; overflow: hidden; margin: 0; border: 1px solid rgba(255,255,255,0.08); }
    .preview-modal .section-head { display: flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .preview-modal .section-note { margin: 6px 18px 0; font-size: 12px; font-weight: 700; color: #f0c04a; }
    .preview-modal .section-body { background: #111827; padding: 16px 18px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; }
    .preview-modal .tbl-wrap { min-width: 0; max-width: 100%; }
    .preview-modal .grid-scroll + .grid-scroll { margin-top: 2px; }
    .preview-modal .tbl-title { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #a8b4d0; background: rgba(255,255,255,0.04); padding: 8px 14px; border-radius: 6px 6px 0 0; }
    .preview-modal .group-tables { display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; margin: 8px 0 4px 4px; padding-left: 10px; border-left: 3px solid rgba(255,255,255,0.15); }
    .preview-modal .grid-scroll { overflow-x: auto; }
    .preview-modal table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    .preview-modal table.grid th, .preview-modal table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    .preview-modal table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 800; white-space: nowrap; }
    .preview-modal td.cell { min-width: 90px; background: rgba(255,255,255,0.04); }
    .preview-modal th.spacer-th, .preview-modal td.spacer-cell { background: none; border-color: transparent; padding: 8px 4px; }
    .preview-modal .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .preview-modal .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

    /* Discord mode: read-only rendering (same rationale/porting as .preview-modal above),
       plus "post" grouping boxes that show which tables would land in the same Discord image. */
    .discord-panel { display: flex; flex-direction: column; gap: 16px; }
    .discord-post { border: 1px dashed rgba(88,101,242,0.4); border-radius: 10px; padding: 12px; background: #111827; }
    .discord-post-label { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #a3adfa; margin-bottom: 10px; display: flex; align-items: baseline; gap: 8px; }
    .discord-post-meta { font-size: 11px; font-weight: 600; text-transform: none; letter-spacing: 0; color: #7f8bad; }
    .discord-post-row { display: flex; flex-direction: row; flex-wrap: nowrap; align-items: flex-start; gap: 18px; overflow-x: auto; padding-bottom: 4px; }
    .discord-mode .tbl-wrap { min-width: 0; flex-shrink: 0; }
    .discord-sec-tag { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #fff; padding: 3px 10px; border-radius: 6px 6px 0 0; }
    .discord-mode .tbl-title { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #a8b4d0; background: rgba(255,255,255,0.04); padding: 8px 14px; }
    .discord-mode table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    .discord-mode table.grid th, .discord-mode table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    .discord-mode td.cell { min-width: 90px; background: rgba(255,255,255,0.04); }
    .discord-mode td.spacer-cell { background: none; border-color: transparent; padding: 8px 4px; }
    .discord-mode .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .discord-mode .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

    .angry-page-pane { display: flex; gap: 14px; }
    .angry-page-list { display: flex; flex-direction: column; gap: 4px; width: 160px; flex-shrink: 0; }
    .angry-page-btn { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #c7cef2; text-align: left; padding: 6px 10px; border-radius: 6px; font: inherit; font-size: 12px; cursor: pointer; }
    .angry-page-btn.active { background: rgba(88,101,242,0.15); border-color: rgba(88,101,242,0.4); color: #a3adfa; }
    .angry-page-btn:hover { border-color: rgba(255,255,255,0.3); }
    .angry-meta-row { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
    .angry-meta-row input[type=text] { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 12.5px; padding: 7px 9px; border-radius: 6px; }
    #angryPageName { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 12.5px; padding: 7px 9px; border-radius: 6px; }

    .add-section-bar { display: flex; gap: 8px; align-items: center; margin-top: 6px; }
    .add-section-bar select { background: #111827; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 13px; padding: 8px 10px; border-radius: 6px; }
    .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

    .mode-switcher { display: flex; gap: 4px; background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 3px; width: fit-content; }
    .undo-bar { display: flex; width: fit-content; }
    .undo-bar #undoBtn:disabled { opacity: 0.4; cursor: default; }
    .mode-btn { background: none; border: none; color: #a8b4d0; font: inherit; font-size: 12.5px; font-weight: 700; padding: 6px 14px; border-radius: 6px; cursor: pointer; }
    .mode-btn:hover { color: #e8ecff; }
    .mode-btn.active { background: #5865f2; color: #fff; }
    .mode-placeholder { background: #111827; border: 1px dashed rgba(255,255,255,0.15); border-radius: 12px; padding: 60px 20px; text-align: center; color: #7f8bad; }
    .mode-placeholder h2 { color: #e8ecff; font-size: 18px; margin-bottom: 6px; }
    .mode-placeholder p { font-size: 13.5px; }

    /* Colour/Merge mode: read-only rendering ported from raids/view.php, scoped under
       .colour-mode so its class names don't collide with the Layout-mode editing styles
       (same rationale as .preview-modal above). Tables render "as they will appear on a
       raid page" -- no drag handles, rename inputs, or add/delete controls -- with a thin
       row/column header strip added purely as paint-target affordances for this tool. */
    .paint-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding-top: 2px; }
    .paint-bar[hidden] { display: none; }
    .paint-bar-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #7f8bad; }
    .paint-swatches { display: flex; gap: 5px; flex-wrap: wrap; }
    .paint-swatch { width: 22px; height: 22px; border-radius: 6px; border: 2px solid rgba(255,255,255,0.15); padding: 0; cursor: pointer; }
    .paint-swatch:hover { border-color: rgba(255,255,255,0.4); }
    .paint-swatch.active { border-color: #fff; box-shadow: 0 0 0 2px #5865f2; }
    .paint-swatch-transparent {
      background-color: #1a2338;
      background-image:
        linear-gradient(45deg, rgba(255,255,255,0.28) 25%, transparent 25%, transparent 75%, rgba(255,255,255,0.28) 75%),
        linear-gradient(45deg, rgba(255,255,255,0.28) 25%, transparent 25%, transparent 75%, rgba(255,255,255,0.28) 75%);
      background-size: 8px 8px;
      background-position: 0 0, 4px 4px;
    }
    .paint-tool-btn { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #c7cef2; border-radius: 6px; padding: 5px 12px; font: inherit; font-size: 11.5px; font-weight: 700; cursor: pointer; }
    .paint-tool-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .paint-tool-btn.active { background: #e05555; border-color: #e05555; color: #fff; }
    .paint-bar-hint, .paint-armed-hint { font-size: 11.5px; color: #7f8bad; }
    .paint-stop-btn { background: none; border: 1px solid rgba(255,255,255,0.25); color: #e8ecff; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 11px; cursor: pointer; margin-left: 4px; }
    .paint-stop-btn:hover { border-color: rgba(255,255,255,0.5); }

    .colour-mode .section-card { border-radius: 12px; overflow: hidden; margin-bottom: 18px; border: 1px solid rgba(255,255,255,0.08); }
    .colour-mode .section-head { display: flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .colour-mode .section-head-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .colour-mode .section-note { margin: 6px 18px 0; font-size: 12px; font-weight: 700; color: #f0c04a; }
    .colour-mode .section-body { background: #111827; padding: 16px 18px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; }
    .colour-mode .tbl-wrap { min-width: 0; max-width: 100%; }
    .colour-mode .tbl-title { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #a8b4d0; background: rgba(255,255,255,0.04); padding: 8px 14px; border-radius: 6px 6px 0 0; }
    .colour-mode .grid-scroll { overflow-x: auto; }
    .colour-mode .grid-scroll + .grid-scroll { margin-top: 2px; }
    .colour-mode table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    .colour-mode table.grid th, .colour-mode table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    .colour-mode table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 800; white-space: nowrap; }
    .colour-mode td.cell { min-width: 90px; user-select: none; }
    .colour-mode td.cell:not(.spacer-cell) { background: rgba(255,255,255,0.04); }
    .colour-mode td.spacer-cell { background: none; border-color: transparent; padding: 8px 4px; }
    .colour-mode td.spacer-cell.paint-spacer-row, .colour-mode td.spacer-cell.paint-spacer-col { border-color: rgba(255,255,255,0.15); border-style: dashed; }
    body.paint-mode-active .colour-mode td.spacer-cell.paint-spacer-row, body.paint-mode-active .colour-mode td.spacer-cell.paint-spacer-col { cursor: crosshair !important; }
    body.paint-mode-active .colour-mode td.spacer-cell.paint-spacer-row:hover, body.paint-mode-active .colour-mode td.spacer-cell.paint-spacer-col:hover { outline: 2px solid #f0c04a; outline-offset: -2px; }
    .colour-mode .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .colour-mode .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }
    .colour-mode th.paint-corner { background: none; border-color: transparent; }
    .colour-mode th.paint-row-th, .colour-mode th.paint-col-th { color: #6b7595; font-size: 12px; }
    .colour-mode th.paint-row-th { width: 22px; padding: 8px 4px; }
    body.paint-mode-active .colour-mode th.paint-row-th, body.paint-mode-active .colour-mode th.paint-col-th { cursor: pointer; }
    body.paint-mode-active .colour-mode th.paint-row-th:hover, body.paint-mode-active .colour-mode th.paint-col-th:hover { background: rgba(88,101,242,0.18); color: #c7cef2; }
    body.paint-mode-active .colour-mode td.cell, body.paint-mode-active .colour-mode th.paint-row-th, body.paint-mode-active .colour-mode th.paint-col-th {
      cursor: crosshair !important;
    }
    body.paint-mode-active .colour-mode td.cell:hover { outline: 2px solid #f0c04a; outline-offset: -2px; }
    body.paint-mode-active .colour-mode .paint-section-head { cursor: crosshair; }
    body.paint-mode-active .colour-mode .paint-section-head:hover { outline: 2px solid #f0c04a; outline-offset: -2px; }
    body.paint-mode-active .colour-mode .paint-section-body { cursor: crosshair; }
    body.paint-mode-active .colour-mode .paint-table-title { cursor: crosshair; }
    body.paint-mode-active .colour-mode .paint-table-title:hover { outline: 2px solid #f0c04a; outline-offset: -2px; }
    body.paint-mode-active .colour-mode th.paint-corner { cursor: crosshair; }
    body.paint-mode-active .colour-mode th.paint-corner:hover { background: rgba(240,192,74,0.25); }
    body.merge-mode-active .colour-mode td.cell { cursor: col-resize !important; }
    body.merge-mode-active .colour-mode td.cell:hover { outline: 2px solid #7bd88f; outline-offset: -2px; }
    .colour-mode td.cell.merge-touched { outline: 2px solid #7bd88f; outline-offset: -2px; background: rgba(123,216,143,0.28) !important; }

    /* Logic mode: class-restriction / max-count assignment rules, authored per table against
       a read-only grid (same "as it will appear on a raid page" rendering approach as
       Colour/Merge above), scoped under .logic-mode / #modePlaceholderEl.logic-mode. */
    #modePlaceholderEl.logic-mode { text-align: left; padding: 18px; }
    .logic-header h2 { color: #e8ecff; font-size: 18px; margin-bottom: 4px; }
    .logic-hint { color: #7f8bad; font-size: 12.5px; margin: 0 0 12px; max-width: 640px; }
    .logic-section { margin-bottom: 28px; }
    .logic-section-title { color: #a8b4d0; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .logic-table-card { border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; background: rgba(255,255,255,0.015); padding: 14px; margin-bottom: 16px; }
    .logic-table-card:last-child { margin-bottom: 0; }
    .logic-table-heading { color: #e8ecff; font-size: 13.5px; font-weight: 700; margin: 0 0 12px; }
    .logic-body { display: flex; gap: 22px; align-items: flex-start; flex-wrap: wrap; }
    .logic-rules-col { flex: 0 0 300px; min-width: 260px; }
    .logic-grid-col { flex: 1 1 420px; min-width: 320px; }
    .logic-rules-list { list-style: none; padding: 0; margin: 0 0 12px; display: flex; flex-direction: column; gap: 6px; }
    .logic-rule-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; background: #161f33; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 8px 10px; }
    .logic-rule-summary { font-size: 12.5px; color: #e8ecff; }
    .logic-rule-summary em { color: #7f8bad; font-style: normal; }
    .logic-rule-actions { display: flex; gap: 2px; flex: 0 0 auto; }
    .logic-rule-actions button { background: none; border: none; color: #a8b4d0; cursor: pointer; padding: 2px 7px; border-radius: 4px; font-size: 13px; }
    .logic-rule-actions button:hover { background: rgba(255,255,255,0.1); color: #e8ecff; }
    .logic-draft-form { background: #161f33; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
    .logic-draft-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .logic-draft-row label, .logic-maxcount-field, .logic-label-field { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; color: #a8b4d0; flex: 1; min-width: 130px; }
    .logic-draft-row select, .logic-maxcount-field input, .logic-label-field input { background: #0d1420; color: #e8ecff; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; padding: 6px 8px; font: inherit; font-size: 12.5px; }
    .logic-class-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .logic-class-check { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #e8ecff; }
    .logic-picker-hint { font-size: 11.5px; color: #f0c04a; margin: 0; }
    .logic-draft-actions { display: flex; gap: 8px; }
    .logic-mode .tbl-wrap { min-width: 0; max-width: 100%; }
    .logic-mode .grid-scroll { overflow-x: auto; }
    .logic-mode table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    .logic-mode table.grid th, .logic-mode table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    /* Rectangular merges (rowspan>1) read better top-aligned than vertically centered. */
    table.grid td[rowspan], .preview-modal table.grid td[rowspan], .colour-mode table.grid td[rowspan], .logic-mode table.grid td[rowspan] { vertical-align: top; }
    .colour-mode td.cell { position: relative; }
    .cell-split-btn { display: none; position: absolute; top: 2px; right: 2px; width: 16px; height: 16px; line-height: 14px; padding: 0; border: 1px solid rgba(224,85,85,0.5); border-radius: 4px; background: rgba(20,22,36,0.9); color: #e88585; font-size: 12px; cursor: pointer; z-index: 2; }
    .colour-mode td.cell:hover .cell-split-btn { display: block; }
    .cell-split-btn:hover { background: rgba(224,85,85,0.25); }
    .logic-mode table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 800; white-space: nowrap; }
    .logic-mode .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .logic-mode td.logic-cell-disabled { background: rgba(255,255,255,0.02); color: #3a4260; }
    .logic-mode td.logic-cell.logic-picking { cursor: pointer; }
    .logic-mode td.logic-cell.logic-picking:hover { outline: 2px solid #f0c04a; outline-offset: -2px; }
    .logic-mode td.logic-cell-selected { background: rgba(88,101,242,0.45) !important; }
    .logic-mode td.logic-cell-selected .empty-slot { color: #fff; }
    .logic-mode td.logic-cell-in-rule { background: rgba(240,192,74,0.16); }

    .stamp-badge { position: fixed; z-index: 4000; pointer-events: none; background: #f0c04a; color: #1a1400; font-size: 11.5px; font-weight: 800; padding: 4px 9px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.4); white-space: nowrap; }
    body.stamp-mode-active .data-td, body.stamp-mode-active .spacer-cell, body.stamp-mode-active .text-td {
      cursor: crosshair !important;
    }
    body.stamp-mode-active .data-td:hover, body.stamp-mode-active .spacer-cell:hover, body.stamp-mode-active .text-td:hover {
      outline: 2px solid #f0c04a; outline-offset: -2px;
    }
    body.stamp-mode-active .cell-text-display, body.stamp-mode-active .icon-pick-btn, body.stamp-mode-active .swatch, body.stamp-mode-active .cell-bold-btn, body.stamp-mode-active .cell-font-select { pointer-events: none; }
    body.stamp-mode-active .kind-override-tag { pointer-events: none; }
    .cell-override-wrap { position: absolute; top: 1px; left: 2px; z-index: 3; }
    .kind-override-tag { background: none; border: none; padding: 0; font: inherit; font-size: 8.5px; font-weight: 800; letter-spacing: .03em; color: #f0c04a; text-transform: uppercase; cursor: pointer; }
    .kind-override-tag:hover { color: #ffd876; text-decoration: underline; }
    .kind-picker-remove { color: #e88585 !important; }
    .kind-picker-remove:hover { background: rgba(224,85,85,0.15) !important; }
    .data-td, .text-td, .spacer-cell { position: relative; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <a class="back" href="/<?= h($slug) ?>/design">&larr; Back to templates</a>
    <div class="page-header">
      <h1><?= h($template['name']) ?></h1>
      <div class="lock-bar" id="lockBar"></div>
    </div>

    <div class="controls-bar" id="controlsBar">
      <div class="mode-switcher" id="modeSwitcherEl"></div>
      <div class="undo-bar" id="undoBarEl"></div>
      <div class="tabs-row" id="tabsRowEl">
        <div class="tabs" id="tabsEl"></div>
        <div class="angry-inline" id="angryInlineEl"></div>
      </div>
      <div class="paint-bar" id="paintBarEl" hidden></div>
    </div>

    <div id="modeBodyEl">
      <div id="panelsEl"></div>
      <div class="mode-placeholder" id="modePlaceholderEl" hidden></div>
    </div>
  </div>

  <div class="stamp-badge" id="stampBadge" hidden></div>

  <div class="modal-backdrop" id="previewBackdrop">
    <div class="modal preview-modal">
      <div class="preview-head">
        <h2 id="previewTitle">Preview</h2>
        <button class="icon-btn" id="previewClose" type="button" title="Close">&times;</button>
      </div>
      <p class="preview-note">Shown as it will appear on a raid page &mdash; no assignment data exists at the template level.</p>
      <div id="previewBody"></div>
    </div>
  </div>

  <div class="modal-backdrop" id="angryModalBackdrop">
    <div class="modal export-modal">
      <div class="preview-head">
        <h2 id="angryModalTitle">AngryERA export</h2>
        <button class="icon-btn" id="angryModalClose" type="button" title="Close">&times;</button>
      </div>
      <p class="preview-note">Use <code>{{Row Label}}</code> to insert a slot named by its row label (or <code>{{Row Label|Column Label}}</code> when that row has more than one data column). <code>{{*a,b,c}}</code> joins several slots with commas, skipping empty ones; <code>{{#a,b,c}}</code> numbers them. This resolves against real assignments on the raid page &mdash; here it just lists the available tokens.</p>
      <div class="export-tokens" id="angryTokensList"></div>
      <div class="angry-meta-row">
        <label class="lock-toggle" style="gap:6px;">
          <input type="checkbox" id="angrySinglePage">
          <span class="lock-switch"></span>
          Single page mode
        </label>
        <input type="text" id="angryExportName" placeholder="Export name (JSON &quot;name&quot; field)" style="flex:1; min-width:160px;">
      </div>
      <div class="angry-page-pane" id="angryPagePane">
        <div class="angry-page-list" id="angryPageList"></div>
        <div style="flex:1; min-width:0; display:flex; flex-direction:column; gap:6px;">
          <input type="text" id="angryPageName" placeholder="Page name">
          <textarea id="angryPageTemplate" placeholder="e.g. MT Healing:&#10;- {{*Healer 1,Healer 2,Healer 3}}"></textarea>
        </div>
      </div>
      <div class="export-status" id="angryStatus"></div>
      <div class="modal-actions">
        <button class="btn btn-cancel-tpl" type="button" id="angryExportJsonBtn">Copy JSON export</button>
        <button class="btn btn-cancel-tpl" type="button" id="angryDeletePageBtn">Delete page</button>
        <button class="btn" type="button" id="angryAddPageBtn">+ Add page</button>
      </div>
    </div>
  </div>

  <div class="modal-backdrop" id="cellEditBackdrop">
    <div class="modal cell-edit-modal">
      <div class="preview-head">
        <h2>Edit text</h2>
        <button class="icon-btn" id="cellEditClose" type="button" title="Close">&times;</button>
      </div>
      <textarea id="cellEditTextarea" placeholder="Cell text" maxlength="500"></textarea>
      <div class="cell-edit-icon-palette" id="cellEditIconPalette"></div>
      <div class="cell-edit-format-row">
        <button type="button" class="cell-bold-btn" id="cellEditBold" title="Bold">B</button>
        <select class="cell-font-select" id="cellEditFont" title="Font"></select>
        <input type="color" class="swatch" id="cellEditColor" title="Text color">
      </div>
      <div class="modal-actions">
        <button class="btn btn-cancel-tpl" type="button" id="cellEditCancel">Cancel</button>
        <button class="btn" type="button" id="cellEditSave">Save</button>
      </div>
    </div>
  </div>

<script>
const SLUG = <?= json_encode($slug) ?>;
const TEMPLATE_ID = <?= json_encode($templateId) ?>;
const SAVE_URL = <?= json_encode('/raids/template-structure-save.php?slug=' . $slug) ?>;
const USER_ID = <?= json_encode($user['id']) ?>;
let tabExports = <?= json_encode($tabExports) ?>;
let sections = <?= json_encode($sections) ?>;

// Layout / Colour-Merge / Logic mode switcher -- selecting a mode swaps the whole
// editor body for that mode's tools. Persisted per-template so a refresh reopens the
// same mode instead of always falling back to Layout.
const EDIT_MODE_KEY = `raidroster_editMode_${TEMPLATE_ID}`;
const EDIT_MODE_VALUES = ['layout', 'colourMerge', 'logic', 'discord'];
let editMode = EDIT_MODE_VALUES.includes(localStorage.getItem(EDIT_MODE_KEY)) ? localStorage.getItem(EDIT_MODE_KEY) : 'layout';

// Logic mode: the rule currently being added/edited (null when just browsing the rules
// lists). All tables are shown at once now, so the draft tracks which table it belongs to
// via tableId. classes/cellRefs are Sets for cheap toggle-on-click; cellRefs entries are
// "rowId_columnId" strings, matching the server's cellRefs shape once split back apart in
// the save payload.
let logicDraft = null;

// "Add cell Override" stamp tool: armed from the same +Row/+Column popover mechanism
// (a "+ Cell Override" button per table, see renderTable()/openKindPicker). Once a kind
// is picked, cellOverrideStamp holds it and every click on a template grid cell applies
// it (multi-select), until a right-click clears the stamp and exits the tool.
let cellOverrideStamp = null;

// Colour/Merge mode's paint tool: once armed (a palette swatch or the eraser picked),
// every click/drag on a read-only grid cell, or click on a row/column paint header, applies
// paintColor (or clears it, when paintErase) via the paint_cells action. paintErase and
// paintColor are independent so the last-picked color is remembered across an erase.
let paintArmed = false;
let paintColor = '#e05555';
let paintErase = false;
let paintDragging = false;
let paintDragTouched = null; // Set of "tableId_rowId_colId" strings touched this drag gesture

// Colour/Merge mode's merge tool: once armed, dragging across two or more cells in the
// same row merges them into one (colspan = every real column the drag's cells span,
// including any already-merged cell dragged over); a plain click with no drag on an
// already-merged cell splits it back apart. Mutually exclusive with the paint tool, since
// both drive the same grid mousedown/mousemove/mouseup gesture.
let mergeArmed = false;
let mergeDragging = false;
let mergeDragTds = null; // <td> elements touched this drag gesture, in touch order

// Editing lock: purely advisory (everyone on this page already passed the admin
// role check), it exists to warn concurrent admins off each other's edits, not
// to gate the current user's own actions.
let lockHeldByMe = false;
let lockedByOther = null;
let lockHeartbeatTimer = null;

function lockCall(action) {
  return fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, templateId: TEMPLATE_ID }) })
    .then(r => r.json());
}
function startHeartbeat() {
  stopHeartbeat();
  lockHeartbeatTimer = setInterval(() => {
    lockCall('lock_heartbeat').then(d => { if (!d.success) { lockHeldByMe = false; stopHeartbeat(); checkLock(); } });
  }, 30000);
}
function stopHeartbeat() {
  if (lockHeartbeatTimer) { clearInterval(lockHeartbeatTimer); lockHeartbeatTimer = null; }
}
window.addEventListener('beforeunload', () => {
  if (lockHeldByMe) {
    navigator.sendBeacon(SAVE_URL, new Blob([JSON.stringify({ action: 'lock_release', templateId: TEMPLATE_ID })], { type: 'application/json' }));
  }
});
// Auto-claims the lock for whoever loads this page first (no held lock yet) rather than
// requiring a manual "claim" click -- the lock stays purely advisory (see note above), so
// there's no real downside to acquiring it eagerly on behalf of the first viewer.
function checkLock() {
  return lockCall('lock_status').then(d => {
    const holder = d.holder;
    if (holder && holder.discordUserId === USER_ID) {
      lockHeldByMe = true; lockedByOther = null;
      if (!lockHeartbeatTimer) startHeartbeat();
      renderLockBar(); render();
    } else if (holder) {
      lockedByOther = holder; lockHeldByMe = false;
      renderLockBar(); render();
    } else {
      lockCall('lock_acquire').then(d2 => {
        if (d2.success) { lockHeldByMe = true; lockedByOther = null; startHeartbeat(); }
        else { lockedByOther = d2.holder; lockHeldByMe = false; }
        renderLockBar(); render();
      });
    }
  });
}
function renderLockBar() {
  const el = document.getElementById('lockBar');
  if (lockedByOther) {
    const since = new Date(lockedByOther.lockedAt.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    el.innerHTML = `<div class="lock-banner">
      Locked by <strong>${esc(lockedByOther.username)}</strong> since ${since} &mdash; view only.
      <button class="btn" data-action="force-unlock" type="button">Force unlock</button>
    </div>`;
    el.querySelector('[data-action="force-unlock"]').addEventListener('click', () => {
      if (!confirm(`Force unlock? ${lockedByOther.username} may still be editing.`)) return;
      lockCall('lock_force_release').then(() => checkLock());
    });
  } else if (lockHeldByMe) {
    el.innerHTML = `<div class="lock-status">
      <span class="lock-dot"></span> Editing (locked to you)
      <button class="lock-release-btn" type="button" data-action="release-lock">Release</button>
    </div>`;
    el.querySelector('[data-action="release-lock"]').addEventListener('click', () => {
      lockCall('lock_release').then(() => { lockHeldByMe = false; lockedByOther = null; stopHeartbeat(); renderLockBar(); render(); });
    });
  } else {
    el.innerHTML = '';
  }
}
function applyLockGate() {
  if (!lockedByOther) return;
  document.querySelectorAll('#panelsEl input, #panelsEl select, #panelsEl button').forEach(n => n.disabled = true);
  document.querySelectorAll('#panelsEl [draggable="true"]').forEach(n => n.setAttribute('draggable', 'false'));
}

const KIND_META = {
  roster:      { label: 'Roster',            color: '#5865f2' },
  tank:        { label: 'Tank Assignments',  color: '#e05555' },
  healer:      { label: 'Healer Assignments',color: '#4caf6a' },
  misc:        { label: 'Misc Assignments',  color: '#9482c9' },
  assignments: { label: 'Assignments',       color: '#4ecdc4' },
};
// Tabs are freeform now — a tab is just whatever distinct `kind` values exist among this
// template's sections, in the order they first appear. KIND_META only supplies a nicer
// label/color for the handful of legacy kind values still lying around in older templates;
// anything else falls back to showing the kind string itself (see tabLabel/tabColor).
function tabLabel(k) { return (KIND_META[k] && KIND_META[k].label) || k; }
function tabColor(k) { return (KIND_META[k] && KIND_META[k].color) || '#5865f2'; }
function currentTabs() {
  const seen = [];
  for (const s of sections) if (!seen.includes(s.kind)) seen.push(s.kind);
  return seen;
}

function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return (s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function contrastText(hex) {
  if (!hex) return '#e8ecff';
  const h = hex.replace('#', '');
  const r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return lum > 0.6 ? '#111827' : '#ffffff';
}

const MAX_DATA_COLS = 10;

// Column widths are stored in px, but the width inputs show "units" instead so non-technical
// admins don't have to think in pixels — 1 unit = 60px, defaulting to 2 units (120px).
const COL_UNIT_PX = 60;
const DEFAULT_COL_UNITS = 2;
// Icon, text, and general (assignment-slot) columns all hold compact per-player content, so
// they're allowed to shrink further than the normal floor -- half of it, matching the halved
// min-width CSS on those cells.
const NARROW_MIN_COL_PX = COL_UNIT_PX / 2;
function pxToUnits(px) { return (px === null || px === undefined) ? '' : Math.round(px / COL_UNIT_PX); }
function unitsToPx(units) { return (units === '' || units === null || units === undefined) ? null : parseInt(units, 10) * COL_UNIT_PX; }

// Text-cell font choices, kept to a small preset of generic web-safe stacks so the HTML page
// and the Discord canvas export (raids/view.php, drawn client-side, no custom font loading)
// render identically. null/'' means "default" -- the page's own inherited font.
const CELL_FONT_STACKS = {
  serif: 'Georgia, "Times New Roman", serif',
  mono: 'Consolas, "Courier New", monospace',
  display: 'Impact, "Arial Black", sans-serif',
};
const CELL_FONTS = [
  { key: '', label: 'Default' },
  { key: 'serif', label: 'Serif' },
  { key: 'mono', label: 'Monospace' },
  { key: 'display', label: 'Display' },
];
function cellTextStyle(cell) {
  const weight = cell && cell.bold ? 'font-weight:700;' : '';
  const family = cell && CELL_FONT_STACKS[cell.font] ? `font-family:${CELL_FONT_STACKS[cell.font]};` : '';
  return weight + family;
}

// Raid-target icon sprite (assets/img/raid-icons.png, 8 x 64px frames in a single row).
// Used both for the standalone Icon row/column/cell kind and for :key: shortcode tokens
// inserted inline into Text-cell content. Mirrored in raids/view.php and includes/raid_icons.php.
const RAID_ICON_KEYS = ['skull', 'cross', 'square', 'moon', 'triangle', 'diamond', 'circle', 'star'];
function raidIconStyle(key, sizePx) {
  const idx = RAID_ICON_KEYS.indexOf(key);
  if (idx < 0) return '';
  const pct = (idx / (RAID_ICON_KEYS.length - 1)) * 100;
  const size = sizePx || 20;
  return `width:${size}px;height:${size}px;background-image:url('/assets/img/raid-icons.png');background-position:${pct}% 0;background-size:${RAID_ICON_KEYS.length * 100}% 100%;background-repeat:no-repeat;display:inline-block;vertical-align:middle;`;
}
const ICON_TOKEN_RE = /:(skull|cross|square|moon|triangle|diamond|circle|star):/g;
function parseCellText(text) {
  const parts = [];
  if (!text) return parts;
  let last = 0, m;
  ICON_TOKEN_RE.lastIndex = 0;
  while ((m = ICON_TOKEN_RE.exec(text))) {
    if (m.index > last) parts.push({ type: 'text', value: text.slice(last, m.index) });
    parts.push({ type: 'icon', key: m[1] });
    last = m.index + m[0].length;
  }
  if (last < text.length) parts.push({ type: 'text', value: text.slice(last) });
  return parts;
}
function renderCellTextHtml(text) {
  return parseCellText(text).map(p => p.type === 'icon'
    ? `<span class="inline-raid-icon" style="${raidIconStyle(p.key, 14)}" title="${p.key}"></span>`
    : esc(p.value)).join('');
}
function kindOverrideLabel(k) { return k === 'spacer' ? 'Filler' : k === 'text' ? 'Text' : k === 'icon' ? 'Icon' : 'General'; }
function kindOverrideBadge(k) { return k === 'spacer' ? 'F' : k === 'text' ? 'T' : k === 'icon' ? 'I' : 'G'; }

function chunkColumns(columns) {
  const chunks = [];
  let current = [];
  let dataCount = 0;
  for (const c of columns) {
    if (c.kind === 'general' && dataCount >= MAX_DATA_COLS) {
      chunks.push(current);
      current = [];
      dataCount = 0;
    }
    current.push(c);
    if (c.kind === 'general') dataCount++;
  }
  chunks.push(current);
  return chunks;
}

// Priority rule when a row and column of different kinds intersect: Spacer > Text > General.
// A cell is only a draggable toon slot when both its row and column are General. A cell-level
// kindOverride (set via "Add cell Override") takes priority over all of that.
function effectiveKind(row, col, cell) {
  if (cell && cell.kindOverride) return cell.kindOverride;
  if (row.kind === 'spacer' || col.kind === 'spacer') return 'spacer';
  if (row.kind === 'icon' || col.kind === 'icon') return 'icon';
  if (row.kind === 'text' || col.kind === 'text') return 'text';
  return 'general';
}

let _measureCtx = null;
function measureTextPx(text, font) {
  if (!_measureCtx) _measureCtx = document.createElement('canvas').getContext('2d');
  _measureCtx.font = font || '600 12px system-ui, -apple-system, sans-serif';
  return _measureCtx.measureText(text || '').width;
}

function cellFor(tb, rowId, colId) {
  return (tb && tb.cells.find(c => c.rowId === rowId && c.columnId === colId)) || { textContent: '', bgColor: null, textColor: null, bold: false, font: null, icon: null, kindOverride: null };
}
function tableForCell(rowId, colId) {
  return allTables().find(tb => tb.rows.some(r => r.id === rowId) && tb.columns.some(c => c.id === colId)) || null;
}

function autoTextColWidth(c, tb) {
  let longest = c.label || '';
  for (const r of tb.rows) {
    if (r.kind === 'spacer') continue;
    const cell = cellFor(tb, r.id, c.id);
    if (cell.textContent && cell.textContent.length > longest.length) longest = cell.textContent;
  }
  return Math.max(40, Math.round(measureTextPx(longest) + 24));
}

function colWidthPx(c, tb) {
  if (c.kind === 'spacer') {
    const base = c.width || tb.defaultColumnWidth || 30;
    return Math.max(8, Math.round(base / 3));
  }
  const effWidth = (c.width !== null && c.width !== undefined) ? c.width : tb.defaultColumnWidth;
  if (effWidth === 0) {
    return c.kind === 'text' ? autoTextColWidth(c, tb) : Math.max(60, Math.round(measureTextPx(c.label || '') + 24));
  }
  // Always resolve to a real pixel width (never null) so every <col> in the colgroup is
  // explicit — table-layout:fixed only sums a colspan cell's width from its spanned <col>
  // widths correctly when all of them are explicit; an "auto" column breaks that sum and
  // lets merged headers/cells collapse toward a single column's width instead.
  return effWidth || (DEFAULT_COL_UNITS * COL_UNIT_PX);
}

// Every table anywhere in the tree (top-level or nested inside a group), flattened for lookup.
function allTables() {
  const out = [];
  const walk = tables => { for (const tb of tables) { out.push(tb); for (const g of tb.columnGroups) walk(g.tables); } };
  for (const sec of sections) walk(sec.tables);
  return out;
}
function findTable(id) { return allTables().find(tb => tb.id === id) || null; }
function findColumn(id) {
  for (const tb of allTables()) for (const c of tb.columns) if (c.id === id) return c;
  return null;
}
function tableForColumn(id) { return allTables().find(tb => tb.columns.some(c => c.id === id)) || null; }
function findGroup(id) {
  for (const tb of allTables()) for (const g of tb.columnGroups) if (g.id === id) return g;
  return null;
}
function findRow(id) {
  for (const tb of allTables()) for (const r of tb.rows) if (r.id === id) return r;
  return null;
}

// Whole-entity drag with FLIP-animated live reflow, vanilla JS (no library). dragData
// tracks the entity being dragged; dragSnapshot is a pre-drag JSON snapshot of `sections`
// restored on cancel (drop outside any valid zone); dragCompleted marks a successful drop so
// dragend knows not to restore; dragOverKey debounces re-render to only fire when the
// candidate landing slot actually changes (dragover fires continuously on mousemove).
let dragData = null;
let dragSnapshot = null;
let dragCompleted = false;
let dragOverKey = null;

// Which "+ Row"/"+ Column" kind-picker popover is open, e.g. "row-42" or "column-42"; null
// when none. Re-derived on every render() rather than tracked in the DOM since render()
// fully replaces #panelsEl's innerHTML each time.
let openKindPicker = null;

// First-Last-Invert-Play: snapshot every draggable entity's position, run the DOM mutation,
// then animate each entity from its old position to its new one via a transform (cheaper and
// smoother than animating layout properties directly).
function withFlip(mutateFn) {
  const before = new Map();
  document.querySelectorAll('[data-flip-id]').forEach(el => before.set(el.dataset.flipId, el.getBoundingClientRect()));
  mutateFn();
  document.querySelectorAll('[data-flip-id]').forEach(el => {
    const b = before.get(el.dataset.flipId);
    if (!b) return;
    const a = el.getBoundingClientRect();
    const dx = b.left - a.left, dy = b.top - a.top;
    if (!dx && !dy) return;
    el.style.transition = 'none';
    el.style.transform = `translate(${dx}px,${dy}px)`;
    requestAnimationFrame(() => { el.style.transition = 'transform 180ms ease'; el.style.transform = ''; });
  });
}

function getSiblingList(kind, parentKind, parentId) {
  if (kind === 'table') {
    if (parentKind === 'group') { const g = findGroup(parentId); return g ? g.tables : []; }
    const sec = sections.find(s => s.id === parentId); return sec ? sec.tables : [];
  }
  const tb = findTable(parentId);
  if (!tb) return [];
  if (kind === 'column') return tb.columns;
  if (kind === 'row') return tb.rows;
  if (kind === 'group') return tb.columnGroups;
  return [];
}

// Applies a candidate reorder/reparent to the in-memory `sections` tree so the live drag
// preview (siblings sliding apart to open a landing gap) reflects it immediately, ahead of
// any server round-trip. targetId === null means "drop at the end of this container" (used
// for the table-container catch-all zones — empty containers, or dropping past the last
// item). Tables are the only kind that can cross containers (section <-> group, or between
// groups); when parentKind/parentId differ from dragData's current parent, the dragged
// table is spliced out of its old sibling array and into the new one first ("phantom
// reparent") before computing its position within the new list — dragData's parent is then
// updated so subsequent dragover ticks compute siblings against the new container.
function previewMove(kind, parentKind, parentId, movedId, targetId, before) {
  if (kind === 'table' && (parentKind !== dragData.parentKind || parentId !== dragData.parentId)) {
    const srcList = getSiblingList('table', dragData.parentKind, dragData.parentId);
    const idx = srcList.findIndex(t => t.id === movedId);
    if (idx === -1) return;
    const [tb] = srcList.splice(idx, 1);
    const destList = getSiblingList('table', parentKind, parentId);
    let insertAt = destList.length;
    if (targetId !== null) {
      const ti = destList.findIndex(t => t.id === targetId);
      if (ti !== -1) insertAt = before ? ti : ti + 1;
    }
    destList.splice(insertAt, 0, tb);
    dragData.parentKind = parentKind;
    dragData.parentId = parentId;
    withFlip(() => render());
    return;
  }
  const list = getSiblingList(kind, parentKind, parentId);
  const fromIdx = list.findIndex(x => x.id === movedId);
  if (fromIdx === -1) return;
  if (targetId === null) {
    if (fromIdx === list.length - 1) return;
    const [item] = list.splice(fromIdx, 1);
    list.push(item);
    withFlip(() => render());
    return;
  }
  let toIdx = list.findIndex(x => x.id === targetId);
  if (toIdx === -1) return;
  if (!before) toIdx++;
  if (fromIdx < toIdx) toIdx--;
  if (fromIdx === toIdx) return;
  const [item] = list.splice(fromIdx, 1);
  list.splice(toIdx, 0, item);
  withFlip(() => render());
}

// The in-memory `sections` tree already reflects the final landing arrangement (every
// dragover tick applied it via previewMove), so finalizing a drop just persists the current
// sibling order for whichever container the dragged entity ended up in.
function finalizeDrop() {
  if (!dragData) return;
  dragCompleted = true;
  const { kind, parentId, parentKind } = dragData;
  const ids = getSiblingList(kind, parentKind, parentId).map(x => x.id);
  const payload = { action: 'reorder', kind, parentId, orderedIds: ids };
  if (kind === 'table') payload.parentKind = parentKind;
  call(payload);
  dragData = null; dragSnapshot = null; dragOverKey = null;
}

// Undo: an in-memory stack of full {sections, tabExports} snapshots, captured just before
// every structural call() -- sections/tabExports are already a complete, self-sufficient
// snapshot of the whole template (every mutating action ends by returning the full tree),
// so this is a straightforward client-side capture. Cleared on reload; capped so a long
// editing session doesn't grow this unbounded; no Redo. Lock actions and restore_snapshot
// itself are excluded so clicking Undo repeatedly steps back one edit at a time.
let undoStack = [];
const UNDO_STACK_LIMIT = 25;
const UNDO_EXCLUDED_ACTIONS = new Set([
  'lock_status', 'lock_acquire', 'lock_heartbeat', 'lock_release', 'lock_force_release',
  'restore_snapshot',
]);

const UNDO_ACTION_LABELS = {
  add_section: 'Added tab section', delete_tab: 'Deleted tab', update_section: 'Edited section',
  set_section_mrt_export: 'Toggled MRT export', paint_section: 'Painted section', paint_table: 'Painted table',
  add_table: 'Added table', update_table: 'Renamed table', delete_table: 'Deleted table',
  add_column: 'Added column', add_row: 'Added row', update_column: 'Edited column', update_row: 'Edited row',
  delete_column: 'Deleted column', delete_row: 'Deleted row', update_cell: 'Edited cell',
  set_cell_kind_override: 'Set cell override', add_rule: 'Added rule', update_rule: 'Edited rule',
  delete_rule: 'Deleted rule', paint_cells: 'Painted cells', merge_cell: 'Merged cell',
  split_cell: 'Split cell', set_cell_merge: 'Merged cells', add_column_group: 'Added column group',
  update_column_group: 'Edited column group', delete_column_group: 'Deleted column group',
  reorder: 'Reordered items', set_tab_export_enabled: 'Toggled AngryERA export',
  set_tab_export_meta: 'Edited AngryERA export', add_export_page: 'Added export page',
  update_export_page: 'Edited export page', delete_export_page: 'Deleted export page',
  move_export_page: 'Moved export page',
};

function describeAction(payload) {
  return UNDO_ACTION_LABELS[payload.action] || 'Edit';
}

function renderUndoButton() {
  const el = document.getElementById('undoBarEl');
  if (!el) return;
  const top = undoStack[undoStack.length - 1];
  el.innerHTML = `<button type="button" class="btn" id="undoBtn" ${top ? '' : 'disabled'} title="${top ? 'Undo: ' + escAttr(top.label) : 'Nothing to undo'}">&#8630; Undo</button>`;
  const btn = document.getElementById('undoBtn');
  if (btn) btn.addEventListener('click', undoLastEdit);
}

function undoLastEdit() {
  const snap = undoStack.pop();
  if (!snap) return;
  call({ action: 'restore_snapshot', templateId: TEMPLATE_ID, sections: snap.sections, tabExports: snap.tabExports }).then(() => renderUndoButton());
}

function call(payload) {
  if (!UNDO_EXCLUDED_ACTIONS.has(payload.action)) {
    undoStack.push({
      sections: JSON.parse(JSON.stringify(sections)),
      tabExports: JSON.parse(JSON.stringify(tabExports)),
      label: describeAction(payload),
    });
    if (undoStack.length > UNDO_STACK_LIMIT) undoStack.shift();
  }
  return fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(d => { if (!d.success) throw new Error(d.error || 'Failed'); sections = d.sections; if (d.tabExports) tabExports = d.tabExports; render(); return d; })
    .catch(e => alert(e.message));
}

let activeTab = decodeURIComponent(location.hash.slice(1)) || null;

const EDIT_MODES = [
  { key: 'layout', label: 'Layout' },
  { key: 'colourMerge', label: 'Colour/Merge' },
  { key: 'logic', label: 'Logic' },
  { key: 'discord', label: 'Discord' },
];

function setMode(m) {
  if (editMode === m) return;
  editMode = m;
  try { localStorage.setItem(EDIT_MODE_KEY, editMode); } catch (e) {}
  cellOverrideStamp = null;
  updateStampBadge();
  paintArmed = false;
  mergeArmed = false;
  document.body.classList.remove('paint-mode-active');
  document.body.classList.remove('merge-mode-active');
  render();
}

function renderModeSwitcher() {
  const el = document.getElementById('modeSwitcherEl');
  el.innerHTML = EDIT_MODES.map(m =>
    `<button type="button" class="mode-btn ${m.key === editMode ? 'active' : ''}" data-mode="${m.key}">${esc(m.label)}</button>`
  ).join('');
  el.querySelectorAll('.mode-btn').forEach(btn => {
    btn.addEventListener('click', () => setMode(btn.dataset.mode));
  });
}

function updateStampBadge() {
  const badge = document.getElementById('stampBadge');
  document.body.classList.toggle('stamp-mode-active', !!cellOverrideStamp);
  if (!cellOverrideStamp) { badge.hidden = true; return; }
  const label = kindOverrideLabel(cellOverrideStamp);
  badge.textContent = `Cell override: ${label} — click cells to apply, right-click to stop`;
  badge.hidden = false;
}

function renderAngryInline() {
  const el = document.getElementById('angryInlineEl');
  if (!activeTab) { el.innerHTML = ''; return; }
  const te = tabExportFor(activeTab);
  const pageCount = te.pages.length;
  el.innerHTML = `
    <label class="lock-toggle">
      <input type="checkbox" data-action="toggle-tab-export" data-kind="${escAttr(activeTab)}" ${te.enabled ? 'checked' : ''}>
      <span class="lock-switch"></span>
    </label>
    <span class="angry-inline-meta">AngryERA &middot; ${pageCount} page${pageCount === 1 ? '' : 's'} &middot; ${te.singlePage ? 'single' : 'multi'}${te.exportName ? ' &middot; "' + esc(te.exportName) + '"' : ''}</span>
    <button class="btn" type="button" data-action="edit-tab-export" data-kind="${escAttr(activeTab)}" ${te.enabled ? '' : 'disabled'}>Edit</button>`;
  el.querySelectorAll('[data-action]').forEach(node => {
    const evt = node.tagName === 'INPUT' ? 'change' : 'click';
    node.addEventListener(evt, () => {
      const act = node.dataset.action;
      if (act === 'toggle-tab-export') call({ action: 'set_tab_export_enabled', templateId: TEMPLATE_ID, kind: node.dataset.kind, enabled: node.checked });
      if (act === 'edit-tab-export') openAngryEditor(node.dataset.kind);
    });
  });
}

function render() {
  renderModeSwitcher();
  renderUndoButton();

  const tabsRowEl = document.getElementById('tabsRowEl');
  const panelsEl0 = document.getElementById('panelsEl');
  const placeholderEl = document.getElementById('modePlaceholderEl');
  const paintBarEl = document.getElementById('paintBarEl');

  if (editMode === 'logic') {
    panelsEl0.hidden = true; paintBarEl.hidden = true;
    placeholderEl.hidden = false;
    placeholderEl.classList.add('logic-mode');

    const TABS = currentTabs();
    if (!TABS.includes(activeTab)) activeTab = TABS[0] || null;
    tabsRowEl.hidden = false;
    document.getElementById('angryInlineEl').innerHTML = '';
    const tabsEl = document.getElementById('tabsEl');
    tabsEl.innerHTML = TABS.map(k => `<button type="button" class="tab-btn ${k === activeTab ? 'active' : ''}" data-tab="${escAttr(k)}">${esc(tabLabel(k))}</button>`).join('');
    tabsEl.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        activeTab = btn.dataset.tab;
        history.replaceState(null, '', '#' + encodeURIComponent(activeTab));
        render();
      });
    });

    renderLogicMode(placeholderEl);
    return;
  }
  placeholderEl.classList.remove('logic-mode');

  if (editMode === 'colourMerge') {
    placeholderEl.hidden = true;
    paintBarEl.hidden = false;
    renderPaintBar();

    const TABS = currentTabs();
    if (!TABS.includes(activeTab)) activeTab = TABS[0] || null;

    tabsRowEl.hidden = false;
    document.getElementById('angryInlineEl').innerHTML = '';
    const tabsEl = document.getElementById('tabsEl');
    tabsEl.innerHTML = TABS.map(k => `<button type="button" class="tab-btn ${k === activeTab ? 'active' : ''}" data-tab="${escAttr(k)}">${esc(tabLabel(k))}</button>`).join('');
    tabsEl.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        activeTab = btn.dataset.tab;
        history.replaceState(null, '', '#' + encodeURIComponent(activeTab));
        render();
      });
    });

    panelsEl0.hidden = false;
    panelsEl0.innerHTML = TABS.length ? TABS.map(k => {
      const secs = sections.filter(s => s.kind === k);
      const body = secs.length ? secs.map(sec => renderColourSection(sec)).join('') : '<p class="empty">No sections in this tab.</p>';
      return `<div class="tab-panel colour-mode ${k === activeTab ? 'active' : ''}" data-panel="${escAttr(k)}">${body}</div>`;
    }).join('') : '<p class="empty">No tabs yet &mdash; switch to Layout mode to start building this template.</p>';

    wireColourPaint(panelsEl0);
    return;
  }

  if (editMode === 'discord') {
    placeholderEl.hidden = true;
    paintBarEl.hidden = true;

    const TABS = currentTabs();
    if (!TABS.includes(activeTab)) activeTab = TABS[0] || null;

    tabsRowEl.hidden = false;
    document.getElementById('angryInlineEl').innerHTML = '';
    const tabsEl = document.getElementById('tabsEl');
    tabsEl.innerHTML = TABS.map(k => `<button type="button" class="tab-btn ${k === activeTab ? 'active' : ''}" data-tab="${escAttr(k)}">${esc(tabLabel(k))}</button>`).join('');
    tabsEl.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        activeTab = btn.dataset.tab;
        history.replaceState(null, '', '#' + encodeURIComponent(activeTab));
        render();
      });
    });

    panelsEl0.hidden = false;
    panelsEl0.innerHTML = TABS.length ? TABS.map(k => {
      const secs = sections.filter(s => s.kind === k);
      const body = renderDiscordTab(secs);
      return `<div class="tab-panel discord-mode ${k === activeTab ? 'active' : ''}" data-panel="${escAttr(k)}">${body}</div>`;
    }).join('') : '<p class="empty">No tabs yet &mdash; switch to Layout mode to start building this template.</p>';
    return;
  }

  paintBarEl.hidden = true;
  tabsRowEl.hidden = false; panelsEl0.hidden = false; placeholderEl.hidden = true;

  const TABS = currentTabs();
  if (!TABS.includes(activeTab)) activeTab = TABS[0] || null;

  const tabsEl = document.getElementById('tabsEl');
  tabsEl.innerHTML = TABS.map(k => `<button type="button" class="tab-btn ${k === activeTab ? 'active' : ''}" data-tab="${escAttr(k)}">${esc(tabLabel(k))}</button>`).join('')
    + `<button type="button" class="tab-add-btn" id="addTabBtn">+ Add tab</button>`;
  tabsEl.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      activeTab = btn.dataset.tab;
      history.replaceState(null, '', '#' + encodeURIComponent(activeTab));
      render();
    });
  });
  document.getElementById('addTabBtn').addEventListener('click', () => {
    const name = (prompt('Name this new tab, e.g. "Consumables":') || '').trim().slice(0, 50);
    if (!name) return;
    call({ action: 'add_section', templateId: TEMPLATE_ID, kind: name, title: name }).then(() => {
      activeTab = name;
      history.replaceState(null, '', '#' + encodeURIComponent(activeTab));
      render();
    });
  });

  renderAngryInline();

  const el = document.getElementById('panelsEl');
  el.innerHTML = TABS.length ? TABS.map(k => {
    const secs = sections.filter(s => s.kind === k);
    const body = secs.length
      ? secs.map(sec => renderSection(sec)).join('')
      : '<p class="empty">No section yet — add one below to start building this tab.</p>';
    return `<div class="tab-panel ${k === activeTab ? 'active' : ''}" data-panel="${escAttr(k)}">
      ${body}
      <div class="add-section-bar">
        <button class="btn" data-action="add-section-for-kind" data-kind="${escAttr(k)}">+ Add section to this tab</button>
        <button class="btn tab-delete-btn" data-action="delete-tab" data-kind="${escAttr(k)}">Delete this tab&hellip;</button>
      </div>
    </div>`;
  }).join('') : '<p class="empty">No tabs yet &mdash; click "+ Add tab" above to start building this template.</p>';

  el.querySelectorAll('[data-action]').forEach(node => {
    const evt = (node.tagName === 'INPUT' || node.tagName === 'SELECT') ? 'change' : 'click';
    node.addEventListener(evt, () => {
      const act = node.dataset.action;
      const id = node.dataset.id ? parseInt(node.dataset.id, 10) : null;
      if (act === 'rename-section') call({ action: 'update_section', id, title: node.value.trim() });
      if (act === 'toggle-section-note') { const sec = sections.find(s => s.id === id); call({ action: 'update_section', id, title: sec.title, noteEnabled: node.checked }); }
      if (act === 'section-note-text') { const sec = sections.find(s => s.id === id); call({ action: 'update_section', id, title: sec.title, noteText: node.value }); }
      if (act === 'toggle-section-mrt-export') { call({ action: 'set_section_mrt_export', id, enabled: node.checked }); }
      if (act === 'preview-section') openPreview(id);
      if (act === 'delete-section') { if (confirm('Delete this section and everything in it?')) call({ action: 'delete_section', id }); }
      if (act === 'move-section-up') call({ action: 'move_section', id, direction: 'up' });
      if (act === 'move-section-down') call({ action: 'move_section', id, direction: 'down' });
      if (act === 'add-table-to-section') call({ action: 'add_table', sectionId: id });
      if (act === 'add-section-for-kind') { const k = node.dataset.kind; call({ action: 'add_section', templateId: TEMPLATE_ID, kind: k, title: tabLabel(k) }); }
      if (act === 'delete-tab') {
        const k = node.dataset.kind;
        const count = sections.filter(s => s.kind === k).length;
        const msg = `⚠ PERMANENTLY DELETE the "${tabLabel(k)}" tab?\n\nThis removes ${count} section${count === 1 ? '' : 's'} and everything inside — every table, column, row and cell. This cannot be undone.`;
        if (confirm(msg)) call({ action: 'delete_tab', templateId: TEMPLATE_ID, kind: k });
      }
      if (act === 'toggle-tab-export') call({ action: 'set_tab_export_enabled', templateId: TEMPLATE_ID, kind: node.dataset.kind, enabled: node.checked });
      if (act === 'edit-tab-export') openAngryEditor(node.dataset.kind);
      if (act === 'add-table-to-group') {
        const title = prompt('Table name, e.g. a boss name:');
        if (title && title.trim()) call({ action: 'add_table', groupId: id, title: title.trim() });
      }
      if (act === 'rename-table') call({ action: 'update_table', id, title: node.value.trim() });
      if (act === 'delete-table') { if (confirm('Delete this table?')) call({ action: 'delete_table', id }); }
      if (act === 'delete-col') call({ action: 'delete_column', id });
      if (act === 'rename-row') call({ action: 'update_row', id, label: node.value.trim() });
      if (act === 'delete-row') call({ action: 'delete_row', id });
      if (act === 'open-kind-picker') {
        const key = `${node.dataset.kind}-${id}`;
        const wasOpen = openKindPicker === key;
        const rect = node.getBoundingClientRect();
        openKindPicker = wasOpen ? null : key;
        render();
        if (!wasOpen) {
          const picker = el.querySelector('.kind-picker:not([hidden])');
          if (picker) {
            picker.style.top = `${rect.bottom + 4}px`;
            picker.style.left = `${rect.left}px`;
          }
        }
        return;
      }
      if (act === 'add-col') { openKindPicker = null; call({ action: 'add_column', tableId: id, kind: node.dataset.kind, label: '' }); }
      if (act === 'add-row') { openKindPicker = null; call({ action: 'add_row', tableId: id, kind: node.dataset.kind, label: '' }); }
      if (act === 'pick-cell-override') { openKindPicker = null; cellOverrideStamp = node.dataset.kind; updateStampBadge(); render(); }
      if (act === 'open-cell-override-menu') {
        const key = `cell-override-${node.dataset.rowId}_${node.dataset.colId}`;
        const wasOpen = openKindPicker === key;
        const rect = node.getBoundingClientRect();
        openKindPicker = wasOpen ? null : key;
        render();
        if (!wasOpen) {
          const picker = el.querySelector('.kind-picker:not([hidden])');
          if (picker) {
            picker.style.top = `${rect.bottom + 4}px`;
            picker.style.left = `${rect.left}px`;
          }
        }
        return;
      }
      if (act === 'pick-cell-override-single') {
        openKindPicker = null;
        call({ action: 'set_cell_kind_override', rowId: parseInt(node.dataset.rowId, 10), columnId: parseInt(node.dataset.colId, 10), kindOverride: node.dataset.kind });
      }
      if (act === 'remove-cell-override') {
        openKindPicker = null;
        call({ action: 'set_cell_kind_override', rowId: parseInt(node.dataset.rowId, 10), columnId: parseInt(node.dataset.colId, 10), kindOverride: null });
      }
      if (act === 'col-width-dec') {
        const c = findColumn(id);
        if (c.kind === 'spacer') { call({ action: 'update_column', id, label: c.label, width: Math.max(20, (c.width || 20) - 20) }); }
        else {
          const tb = tableForColumn(id);
          const base = (c.width !== null && c.width !== undefined) ? c.width : (tb.defaultColumnWidth || DEFAULT_COL_UNITS * COL_UNIT_PX);
          const step = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? COL_UNIT_PX / 2 : COL_UNIT_PX;
          const min = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? NARROW_MIN_COL_PX : COL_UNIT_PX;
          call({ action: 'update_column', id, label: c.label, width: Math.max(min, base - step) });
        }
      }
      if (act === 'col-width-inc') {
        const c = findColumn(id);
        if (c.kind === 'spacer') { call({ action: 'update_column', id, label: c.label, width: (c.width || 20) + 20 }); }
        else {
          const tb = tableForColumn(id);
          const base = (c.width !== null && c.width !== undefined) ? c.width : (tb.defaultColumnWidth || DEFAULT_COL_UNITS * COL_UNIT_PX);
          const step = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? COL_UNIT_PX / 2 : COL_UNIT_PX;
          call({ action: 'update_column', id, label: c.label, width: base + step });
        }
      }
      if (act === 'row-height-dec') { const r = findRow(id); call({ action: 'update_row', id, label: r.label, height: Math.max(20, (r.height || 20) - 20) }); }
      if (act === 'row-height-inc') { const r = findRow(id); call({ action: 'update_row', id, label: r.label, height: (r.height || 20) + 20 }); }
      if (act === 'table-col-width') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, defaultColumnWidth: unitsToPx(node.value) }); }
      if (act === 'open-cell-editor') { openKindPicker = null; openCellEditor(parseInt(node.dataset.rowId, 10), parseInt(node.dataset.colId, 10)); return; }
      if (act === 'open-cell-icon-menu') {
        const key = `cell-icon-${node.dataset.rowId}_${node.dataset.colId}`;
        const wasOpen = openKindPicker === key;
        const rect = node.getBoundingClientRect();
        openKindPicker = wasOpen ? null : key;
        render();
        if (!wasOpen) {
          const picker = el.querySelector('.kind-picker:not([hidden])');
          if (picker) {
            picker.style.top = `${rect.bottom + 4}px`;
            picker.style.left = `${rect.left}px`;
          }
        }
        return;
      }
      if (act === 'cell-icon') { const rowId = parseInt(node.dataset.rowId, 10), colId = parseInt(node.dataset.colId, 10); const cell = cellFor(tableForCell(rowId, colId), rowId, colId); openKindPicker = null; call({ action: 'update_cell', rowId, columnId: colId, textContent: cell.textContent, bgColor: cell.bgColor, textColor: cell.textColor, bold: cell.bold, font: cell.font, icon: node.dataset.icon || null }); }
      if (act === 'col-group') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, groupId: node.value ? parseInt(node.value, 10) : null }); }
      if (act === 'merge-header') call({ action: 'merge_header', id });
      if (act === 'split-header') call({ action: 'split_header', id });
      if (act === 'add-group') {
        const title = prompt('Group header title, e.g. Spider Wing:');
        if (title && title.trim()) call({ action: 'add_column_group', tableId: id, title: title.trim() });
      }
      if (act === 'rename-group') call({ action: 'update_column_group', id, title: node.value.trim() });
      if (act === 'recolor-group') call({ action: 'update_column_group', id, color: node.value });
      if (act === 'delete-group') {
        const g = findGroup(id);
        const msg = (g && g.tables.length)
          ? 'Delete this group AND all its nested tables? This cannot be undone.'
          : 'Delete this group header? Columns stay, they just lose the grouping.';
        if (confirm(msg)) call({ action: 'delete_column_group', id });
      }
    });
  });

  el.querySelectorAll('[data-drag-kind]').forEach(handle => {
    handle.addEventListener('dragstart', e => {
      if (lockedByOther) { e.preventDefault(); return; }
      dragData = {
        kind: handle.dataset.dragKind,
        id: parseInt(handle.dataset.dragId, 10),
        parentId: parseInt(handle.dataset.dragParent, 10),
        parentKind: handle.dataset.dragParentKind || 'table',
      };
      dragSnapshot = JSON.stringify(sections);
      dragCompleted = false;
      dragOverKey = null;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(dragData.id));
      // Snapshot the real entity element (its nearest data-flip-id ancestor) as the drag
      // image so the ghost under the cursor looks like the thing being moved, not a
      // generic browser default.
      const ghost = handle.closest('[data-flip-id]') || handle;
      const r = ghost.getBoundingClientRect();
      e.dataTransfer.setDragImage(ghost, e.clientX - r.left, e.clientY - r.top);
    });
    handle.addEventListener('dragend', () => {
      if (!dragCompleted && dragSnapshot) {
        const snap = JSON.parse(dragSnapshot);
        withFlip(() => { sections = snap; render(); });
      }
      dragData = null; dragSnapshot = null; dragCompleted = false; dragOverKey = null;
    });
  });

  el.querySelectorAll('[data-drop-kind]').forEach(zone => {
    const dropKind = zone.dataset.dropKind;
    const dropId = zone.dataset.dropId !== undefined ? parseInt(zone.dataset.dropId, 10) : null;
    const dropParent = parseInt(zone.dataset.dropParent, 10);
    const dropParentKind = zone.dataset.dropParentKind || 'table';
    zone.addEventListener('dragover', e => {
      if (!dragData) return;
      // Catch-all container zone: lets a table be dropped into an empty section/group, or
      // past the last table in one — per-card drop zones only cover reordering next to an
      // existing table.
      if (dropKind === 'table-container') {
        if (dragData.kind !== 'table') return;
        e.preventDefault();
        e.stopPropagation();
        zone.classList.add('drag-over');
        // Tables commit their move once, on drop (see the drop listener below) rather than
        // live-reflowing on every dragover tick — a table drag can jump between containers
        // (section <-> group) whose nested layouts differ in width/indent, and FLIP-animating
        // that mid-drag caused the reported overlapping/"nesting" visual glitches. A static
        // dashed-outline highlight (.drag-over) is the only in-drag feedback for tables now.
        return;
      }
      const acceptsColumnOntoGroup = dropKind === 'group' && dragData.kind === 'column' && dragData.parentId === dropParent;
      const sameKind = dragData.kind === dropKind;
      if (!acceptsColumnOntoGroup && !sameKind) return;
      e.preventDefault();
      e.stopPropagation();
      zone.classList.add('drag-over');
      if (acceptsColumnOntoGroup) return;
      if (dragData.kind === 'table') return; // committed once, on drop — see above
      const rect = zone.getBoundingClientRect();
      const before = dropKind === 'row'
        ? (e.clientY - rect.top) < rect.height / 2
        : (e.clientX - rect.left) < rect.width / 2;
      const key = `${dropKind}:${dropParentKind}:${dropParent}:${dropId}:${before}`;
      if (dragOverKey === key) return;
      dragOverKey = key;
      previewMove(dropKind, dropParentKind, dropParent, dragData.id, dropId, before);
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      e.stopPropagation();
      zone.classList.remove('drag-over');
      if (!dragData) return;
      if (dropKind === 'group' && dragData.kind === 'column' && dragData.parentId === dropParent) {
        const c = findColumn(dragData.id);
        dragCompleted = true;
        call({ action: 'update_column', id: dragData.id, label: c.label, groupId: dropId });
        dragData = null; dragSnapshot = null; dragOverKey = null;
        return;
      }
      if (dragData.kind === 'table') {
        let targetId = null, before = true;
        if (dropKind === 'table') {
          targetId = dropId;
          const rect = zone.getBoundingClientRect();
          before = (e.clientX - rect.left) < rect.width / 2;
        }
        previewMove('table', dropParentKind, dropParent, dragData.id, targetId, before);
      }
      finalizeDrop();
    });
  });

  applyLockGate();
}

function colHeaderCell(c, tb, groupsEnabled, span) {
  const colspanAttr = span > 1 ? ` colspan="${span}"` : '';
  const dragAttrs = `draggable="true" data-drag-kind="column" data-drag-id="${c.id}" data-drag-parent="${tb.id}" title="Drag to reorder"`;
  const controls = `<div class="col-th-top" ${dragAttrs}>
        <span class="drag-handle">&#10021;</span>
        <button class="icon-btn" data-action="col-width-dec" data-id="${c.id}" title="Narrower">&minus;</button>
        <button class="icon-btn" data-action="col-width-inc" data-id="${c.id}" title="Wider">+</button>
        <button class="icon-btn danger" data-action="delete-col" data-id="${c.id}" title="Delete">&times;</button>
      </div>`;
  if (c.kind === 'spacer') {
    return `<th class="spacer-th" data-drop-kind="column" data-drop-id="${c.id}" data-drop-parent="${tb.id}">
      <div class="col-th-inner" data-flip-id="column:${c.id}">
        ${controls}
      </div>
    </th>`;
  }
  const groupOptions = ['<option value="">— No group —</option>']
    .concat(tb.columnGroups.map(g => `<option value="${g.id}" ${c.groupId === g.id ? 'selected' : ''}>${escAttr(g.title)}</option>`));
  const groupSelect = groupsEnabled
    ? `<select class="width-input" style="width:100%;" data-action="col-group" data-id="${c.id}" title="Column group">${groupOptions.join('')}</select>`
    : '';
  return `<th${colspanAttr} data-drop-kind="column" data-drop-id="${c.id}" data-drop-parent="${tb.id}">
      <div class="col-th-inner" data-flip-id="column:${c.id}">
        ${controls}
        ${groupSelect}
      </div>
    </th>`;
}

// singleRow=true means this group row is the table's only header row (the preview, which
// has no second row of column controls beneath it to rowspan into) — leadCol/group filler
// cells render without a rowspan in that case.
function groupHeaderRow(cols, columnGroups, leadCol = true, singleRow = false) {
  if (!columnGroups.length) return '';
  const fillerCell = singleRow ? `<th></th>` : `<th rowspan="2"></th>`;
  const cells = leadCol ? [fillerCell] : [];
  let i = 0;
  while (i < cols.length) {
    const gid = cols[i].groupId;
    if (!gid) { cells.push(fillerCell); i++; continue; }
    let span = 1;
    while (i + span < cols.length && cols[i + span].groupId === gid) span++;
    const grp = columnGroups.find(g => g.id === gid);
    if (grp) {
      cells.push(`<th colspan="${span}" style="background:${grp.color};color:${contrastText(grp.color)};">${esc(grp.title)}</th>`);
    } else {
      cells.push(`<th colspan="${span}"></th>`);
    }
    i += span;
  }
  return `<tr>${cells.join('')}</tr>`;
}

function groupStrip(tb) {
  const pills = tb.columnGroups.map(g => `
    <div class="group-pill" data-flip-id="group:${g.id}" draggable="true" data-drag-kind="group" data-drag-id="${g.id}" data-drag-parent="${tb.id}" data-drop-kind="group" data-drop-id="${g.id}" data-drop-parent="${tb.id}" title="Drag to reorder, or drop a column here to assign it" style="background:${g.color};color:${contrastText(g.color)};">
      <span class="drag-handle" style="color:inherit;opacity:.75;">&#10021;</span>
      <input class="group-title" draggable="false" data-action="rename-group" data-id="${g.id}" value="${escAttr(g.title)}">
      <input type="color" class="swatch" draggable="false" data-action="recolor-group" data-id="${g.id}" value="${g.color}" title="Group color">
      <button class="icon-btn" data-action="add-table-to-group" data-id="${g.id}" title="Add a table to this group">+T</button>
      <button class="icon-btn danger" data-action="delete-group" data-id="${g.id}" title="Delete group">&times;</button>
    </div>`).join('');
  return `<div class="group-strip">${pills}<button class="add-group-btn" data-action="add-group" data-id="${tb.id}">+ Group header</button></div>`;
}

function renderRowHeader(r, tb) {
  const dragHandle = `<span class="drag-handle">&#10021;</span>`;
  const heightDec = `<button class="icon-btn" data-action="row-height-dec" data-id="${r.id}" title="Shorter">&minus;</button>`;
  const heightInc = `<button class="icon-btn" data-action="row-height-inc" data-id="${r.id}" title="Taller">+</button>`;
  const deleteBtn = `<button class="icon-btn danger" data-action="delete-row" data-id="${r.id}" title="Delete">&times;</button>`;
  const dragAttrs = `draggable="true" data-drag-kind="row" data-drag-id="${r.id}" data-drag-parent="${tb.id}" title="Drag to reorder"`;
  const cls = r.kind === 'spacer' ? 'spacer-th row-th' : 'row-th';
  return `<th class="${cls}" data-drop-kind="row" data-drop-id="${r.id}" data-drop-parent="${tb.id}">
    <div class="row-th-inner" data-flip-id="row:${r.id}">
      <div class="row-th-top" ${dragAttrs}>${dragHandle}${heightDec}${heightInc}${deleteBtn}</div>
    </div>
  </th>`;
}

// Header colspans are stored per-column and consumed left-to-right: a column with
// headerColspan > 1 renders one <th> spanning N columns, and the next N-1 columns are
// skipped entirely (purely positional — no bookkeeping needed when columns move/delete).
function headerCellsForChunk(chunkCols, tb, groupsEnabled) {
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    const span = c.kind === 'spacer' ? 1 : Math.min(c.headerColspan || 1, chunkCols.length - i);
    out.push(colHeaderCell(c, tb, groupsEnabled, span));
    i += span;
  }
  return out.join('');
}

// Same walk-and-consume pattern as headers, but per-row: cellMerges is a (rowId, columnId)
// -> colspan lookup, independent of header merges.
// Computed once per table by each *ColumnBlock caller (not per row -- rows are never
// chunked, so this only needs to run once per render pass). Returns which (rowId,columnId)
// positions must render nothing at all (covered by an earlier row's rowspan), and the
// render-time-clamped {colspan, rowspan} for each anchor cell -- clamped to the table's
// actual remaining rows so a stale DB value (e.g. after rows were deleted) degrades
// gracefully instead of overrunning tb.rows, matching how colspan is already clamped to
// chunkCols.length at render time.
function computeMergeCoverage(tb) {
  const covered = new Set();
  const spans = {};
  tb.cellMerges.forEach(m => {
    const rowIdx = tb.rows.findIndex(r => r.id === m.rowId);
    const colIdx = tb.columns.findIndex(c => c.id === m.columnId);
    if (rowIdx < 0 || colIdx < 0) return;
    const rowspan = Math.min(m.rowspan || 1, tb.rows.length - rowIdx);
    spans[`${m.rowId}_${m.columnId}`] = { colspan: m.colspan || 1, rowspan };
    for (let dr = 1; dr < rowspan; dr++) {
      const coveredRow = tb.rows[rowIdx + dr];
      for (let dc = 0; dc < (m.colspan || 1); dc++) {
        const coveredCol = tb.columns[colIdx + dc];
        if (coveredCol) covered.add(`${coveredRow.id}_${coveredCol.id}`);
      }
    }
  });
  return { covered, spans };
}

function bodyCellsForRow(r, chunkCols, tb, coverage, sectionBg) {
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (coverage.covered.has(`${r.id}_${c.id}`)) { i++; continue; }
    const cell = cellFor(tb, r.id, c.id);
    const eff = effectiveKind(r, c, cell);
    const overrideKey = `cell-override-${r.id}_${c.id}`;
    const overrideTag = cell.kindOverride
      ? `<div class="cell-override-wrap kind-picker-wrap">
          <button type="button" class="kind-override-tag" data-action="open-cell-override-menu" data-row-id="${r.id}" data-col-id="${c.id}" title="Cell override: ${kindOverrideLabel(cell.kindOverride)} — click to change or remove">${kindOverrideBadge(cell.kindOverride)}</button>
          <div class="kind-picker" ${openKindPicker === overrideKey ? '' : 'hidden'}>
            <button type="button" data-action="pick-cell-override-single" data-kind="general" data-row-id="${r.id}" data-col-id="${c.id}">General</button>
            <button type="button" data-action="pick-cell-override-single" data-kind="text" data-row-id="${r.id}" data-col-id="${c.id}">Text</button>
            <button type="button" data-action="pick-cell-override-single" data-kind="icon" data-row-id="${r.id}" data-col-id="${c.id}">Icon</button>
            <button type="button" data-action="pick-cell-override-single" data-kind="spacer" data-row-id="${r.id}" data-col-id="${c.id}">Filler</button>
            <button type="button" class="kind-picker-remove" data-action="remove-cell-override" data-row-id="${r.id}" data-col-id="${c.id}">Remove override</button>
          </div>
        </div>`
      : '';
    const span = coverage.spans[`${r.id}_${c.id}`] || { colspan: 1, rowspan: 1 };
    const colspan = Math.min(span.colspan, chunkCols.length - i);
    const colspanAttr = colspan > 1 ? ` colspan="${colspan}"` : '';
    const rowspanAttr = span.rowspan > 1 ? ` rowspan="${span.rowspan}"` : '';
    if (eff === 'spacer') {
      const spacerBg = (cell && cell.bgColor) || c.bgColor || sectionBg;
      out.push(`<td${colspanAttr}${rowspanAttr} class="spacer-cell" data-row-id="${r.id}" data-col-id="${c.id}" style="background:${spacerBg};">${overrideTag}</td>`);
      i += colspan; continue;
    }
    if (eff === 'text') {
      const display = cell.textContent
        ? renderCellTextHtml(cell.textContent)
        : '<span class="cell-text-placeholder">Click to edit&hellip;</span>';
      const tdStyle = cell.bgColor ? ` style="background:${cell.bgColor};"` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="data-td text-td" data-row-id="${r.id}" data-col-id="${c.id}"${tdStyle}>
        ${overrideTag}
        <div class="cell-text-display" data-action="open-cell-editor" data-row-id="${r.id}" data-col-id="${c.id}" title="Click to edit text" style="${cellTextStyle(cell)}color:${cell.textColor || '#e8ecff'};">${display}</div>
      </td>`);
    } else if (eff === 'icon') {
      const iconKey = `cell-icon-${r.id}_${c.id}`;
      const swatches = RAID_ICON_KEYS.map(k => `<button type="button" class="icon-swatch-btn" data-action="cell-icon" data-icon="${k}" data-row-id="${r.id}" data-col-id="${c.id}" style="${raidIconStyle(k, 22)}" title="${k}"></button>`).join('');
      const tdStyle = cell.bgColor ? ` style="background:${cell.bgColor};"` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="data-td icon-td" data-row-id="${r.id}" data-col-id="${c.id}"${tdStyle}>
        ${overrideTag}
        <div class="cell-icon-wrap kind-picker-wrap">
          <button type="button" class="icon-pick-btn${cell.icon ? '' : ' empty'}" data-action="open-cell-icon-menu" data-row-id="${r.id}" data-col-id="${c.id}" style="${cell.icon ? raidIconStyle(cell.icon, 26) : ''}" title="Pick raid icon">${cell.icon ? '' : '+'}</button>
          <div class="kind-picker icon-picker" ${openKindPicker === iconKey ? '' : 'hidden'}>
            ${swatches}
            <button type="button" class="kind-picker-remove" data-action="cell-icon" data-icon="" data-row-id="${r.id}" data-col-id="${c.id}">Clear</button>
          </div>
        </div>
      </td>`);
    } else {
      const tdStyle = cell.bgColor ? ` style="background:${cell.bgColor};"` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="data-td" data-row-id="${r.id}" data-col-id="${c.id}"${tdStyle}>${overrideTag}<div class="cell-height-spacer"></div></td>`);
    }
    i += colspan;
  }
  return out.join('');
}

function renderColumnBlock(chunkCols, tb, groupsEnabled, sectionBg) {
  const coverage = computeMergeCoverage(tb);
  const colHeaders = headerCellsForChunk(chunkCols, tb, groupsEnabled);
  const groupRow = groupsEnabled ? groupHeaderRow(chunkCols, tb.columnGroups) : '';

  // 88px fits the drag handle + 3 row-action buttons (18px each + gaps) on one line —
  // narrower and they wrap onto stacked rows, which forces the whole body row taller since
  // a <tr>'s height is driven by its tallest cell.
  const rowHeaderColWidth = 88;
  const colgroup = `<colgroup><col style="width:${rowHeaderColWidth}px;">` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    const rowHeader = renderRowHeader(r, tb);
    if (r.kind === 'spacer') {
      const bg = r.bgColor || sectionBg;
      const spacerCells = chunkCols.map(() => `<td class="spacer-cell" style="background:${bg};"></td>`).join('');
      return `<tr style="height:${r.height || 20}px;">${rowHeader}${spacerCells}</tr>`;
    }
    const heightAttr = r.height ? ` style="height:${r.height}px;"` : '';
    return `<tr${heightAttr}>${rowHeader}${bodyCellsForRow(r, chunkCols, tb, coverage, sectionBg)}</tr>`;
  }).join('');

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${groupRow}
        <tr>${groupRow ? '' : '<th></th>'}${colHeaders}</tr>
        ${bodyRows || ''}
      </table>
    </div>`;
}

// parentKind/parentId identify where tb hangs off (a section, top-level, or a group, nested).
// groupsEnabled is false for roster-kind sections, which don't use column-groups/nested
// boss-tables at all — only tank/healer/misc assignment sections do.
function renderTable(tb, parentKind, parentId, groupsEnabled, sectionBg) {
  const groupsWithTables = groupsEnabled ? tb.columnGroups.filter(g => g.tables.length > 0) : [];
  const isContainerOnly = tb.columns.length === 0 && groupsWithTables.length > 0;

  const blocks = isContainerOnly ? '' : chunkColumns(tb.columns).map(chunkCols => renderColumnBlock(chunkCols, tb, groupsEnabled, sectionBg)).join('');

  const headerBg = tb.headerColor || '';
  const headerStyle = headerBg ? `background:${headerBg};` : '';
  const titleColor = headerBg ? contrastText(headerBg) : '#e8ecff';
  const cardStyle = tb.bgColor ? ` style="background:${tb.bgColor};"` : '';

  const titleHtml = `<input class="tbl-title" draggable="false" data-action="rename-table" data-id="${tb.id}" placeholder="Table name (optional)" value="${escAttr(tb.title)}" style="color:${titleColor};">`;

  const nestedGroupsHtml = groupsWithTables.map(g => `
    <div class="group-tables" data-drop-kind="table-container" data-drop-parent="${g.id}" data-drop-parent-kind="group">
      ${g.tables.map(ctb => renderTable(ctb, 'group', g.id, groupsEnabled, sectionBg)).join('')}
    </div>`).join('');

  return `<div class="tbl-card" data-drop-kind="table" data-drop-id="${tb.id}" data-drop-parent="${parentId}" data-drop-parent-kind="${parentKind}" data-flip-id="table:${tb.id}"${cardStyle}>
    <div class="tbl-head" draggable="true" data-drag-kind="table" data-drag-id="${tb.id}" data-drag-parent="${parentId}" data-drag-parent-kind="${parentKind}" title="Drag to reorder/reposition" style="${headerStyle}">
      <span class="drag-handle" style="color:${titleColor};opacity:.75;">&#10021;</span>
      ${titleHtml}
      <div class="tbl-sizing">Col w<input type="number" class="width-input" draggable="false" data-action="table-col-width" data-id="${tb.id}" value="${pxToUnits(tb.defaultColumnWidth)}" placeholder="${DEFAULT_COL_UNITS}" min="0" title="Default column width in units (1 unit = ${COL_UNIT_PX}px). 0 = shrink to longest content."></div>
      <button class="icon-btn danger" data-action="delete-table" data-id="${tb.id}" title="Delete table">&times;</button>
    </div>
    ${groupsEnabled ? groupStrip(tb) : ''}
    ${blocks}
    ${!isContainerOnly ? `<div class="tbl-actions-row">
      <div class="kind-picker-wrap">
        <button class="add-row-btn" data-action="open-kind-picker" data-kind="row" data-id="${tb.id}">+ Row</button>
        <div class="kind-picker" ${openKindPicker === `row-${tb.id}` ? '' : 'hidden'}>
          <button data-action="add-row" data-kind="text" data-id="${tb.id}">Text Row</button>
          <button data-action="add-row" data-kind="general" data-id="${tb.id}">General Row</button>
          <button data-action="add-row" data-kind="icon" data-id="${tb.id}">Icon Row</button>
          <button data-action="add-row" data-kind="spacer" data-id="${tb.id}">Spacer Row</button>
        </div>
      </div>
      <div class="kind-picker-wrap">
        <button class="add-row-btn" data-action="open-kind-picker" data-kind="column" data-id="${tb.id}">+ Column</button>
        <div class="kind-picker" ${openKindPicker === `column-${tb.id}` ? '' : 'hidden'}>
          <button data-action="add-col" data-kind="spacer" data-id="${tb.id}">Spacer</button>
          <button data-action="add-col" data-kind="text" data-id="${tb.id}">Text</button>
          <button data-action="add-col" data-kind="icon" data-id="${tb.id}">Icon</button>
          <button data-action="add-col" data-kind="general" data-id="${tb.id}">General</button>
        </div>
      </div>
      <div class="kind-picker-wrap">
        <button class="add-row-btn" data-action="open-kind-picker" data-kind="cell-override" data-id="${tb.id}">+ Cell Override</button>
        <div class="kind-picker" ${openKindPicker === `cell-override-${tb.id}` ? '' : 'hidden'}>
          <button data-action="pick-cell-override" data-kind="general" data-id="${tb.id}">General</button>
          <button data-action="pick-cell-override" data-kind="text" data-id="${tb.id}">Text</button>
          <button data-action="pick-cell-override" data-kind="icon" data-id="${tb.id}">Icon</button>
          <button data-action="pick-cell-override" data-kind="spacer" data-id="${tb.id}">Filler</button>
        </div>
      </div>
    </div>` : ''}
    ${nestedGroupsHtml}
  </div>`;
}

function renderSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  const headColor = sec.color || meta.color;
  const groupsEnabled = false;
  const sectionBg = sec.bgColor || '#111827';
  const bodyStyle = sec.bgColor ? ` style="background:${sec.bgColor};"` : '';
  return `<div class="section-card">
    <div class="section-head" style="background:${headColor};">
      <input class="title-input" data-action="rename-section" data-id="${sec.id}" value="${escAttr(sec.title)}">
      <button class="icon-btn" data-action="preview-section" data-id="${sec.id}" title="Preview as it will look on a raid page">&#128065;</button>
      <button class="icon-btn" data-action="move-section-up" data-id="${sec.id}" title="Move up">&uarr;</button>
      <button class="icon-btn" data-action="move-section-down" data-id="${sec.id}" title="Move down">&darr;</button>
      <button class="icon-btn danger" data-action="delete-section" data-id="${sec.id}" title="Delete section">&times;</button>
    </div>
    <div class="section-note-bar">
      <label class="note-toggle-label">
        <input type="checkbox" data-action="toggle-section-note" data-id="${sec.id}" ${sec.noteEnabled ? 'checked' : ''}>
        Cell markers (*)
      </label>
      ${sec.noteEnabled ? `<input type="text" class="note-text-input" data-action="section-note-text" data-id="${sec.id}" placeholder="Note shown under the section header" value="${escAttr(sec.noteText || '')}" maxlength="255">` : ''}
      <label class="note-toggle-label">
        <input type="checkbox" data-action="toggle-section-mrt-export" data-id="${sec.id}" ${sec.mrtExportEnabled ? 'checked' : ''}>
        MRT export
      </label>
    </div>
    <div class="section-body" data-drop-kind="table-container" data-drop-parent="${sec.id}" data-drop-parent-kind="section"${bodyStyle}>
      ${sec.tables.map(tb => renderTable(tb, 'section', sec.id, groupsEnabled, sectionBg)).join('') || '<p class="empty">No tables yet.</p>'}
      <button class="btn" data-action="add-table-to-section" data-id="${sec.id}">+ Table</button>
    </div>
  </div>`;
}

// Preview modal: a read-only render of a section as it will actually look on a raid
// page. Mirrors raids/view.php's rendering (same chunking/width helpers, same column-
// group header row, same effectiveKind cell-kind resolution), but every General cell
// always shows the empty-slot placeholder since no toon assignments exist at the
// template level; Text cells render their real authored content/colors.
function previewBodyCellsForRow(r, chunkCols, tb, coverage, sectionBg) {
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (coverage.covered.has(`${r.id}_${c.id}`)) { i++; continue; }
    const cell = cellFor(tb, r.id, c.id);
    const eff = effectiveKind(r, c, cell);
    const span = coverage.spans[`${r.id}_${c.id}`] || { colspan: 1, rowspan: 1 };
    const colspan = Math.min(span.colspan, chunkCols.length - i);
    const colspanAttr = colspan > 1 ? ` colspan="${colspan}"` : '';
    const rowspanAttr = span.rowspan > 1 ? ` rowspan="${span.rowspan}"` : '';
    // Column-kind min-width, not the cell's effective kind -- the width-adjustment buttons key
    // off c.kind too, so an icon/text column stays shrinkable to its lower floor regardless of a
    // cell's own kind_override.
    const minWidthStyle = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? `min-width:${NARROW_MIN_COL_PX}px;` : '';
    if (eff === 'spacer') {
      const spacerColor = (cell && cell.bgColor) || (r.kind === 'spacer' && r.bgColor) || c.bgColor || sectionBg;
      out.push(`<td${colspanAttr}${rowspanAttr} class="spacer-cell" style="${minWidthStyle}background:${spacerColor};"></td>`);
      i += colspan; continue;
    }
    if (eff === 'text') {
      const style = `${minWidthStyle}${cell.bgColor ? `background:${cell.bgColor};` : ''}color:${cell.textColor || 'inherit'};${cellTextStyle(cell)}`;
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell text-td" style="${style}">${renderCellTextHtml(cell.textContent)}</td>`);
    } else if (eff === 'icon') {
      const style = minWidthStyle + (cell.bgColor ? `background:${cell.bgColor};` : '');
      const icon = cell.icon ? `<span class="raid-icon-cell" style="${raidIconStyle(cell.icon, 26)}"></span>` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell icon-td" style="${style}">${icon}</td>`);
    } else {
      const style = minWidthStyle ? ` style="${minWidthStyle}"` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell"${style}><span class="empty-slot">+</span></td>`);
    }
    i += colspan;
  }
  return out.join('');
}

function previewColumnBlock(chunkCols, tb, groupsEnabled, sectionBg) {
  const coverage = computeMergeCoverage(tb);
  const groupRow = groupsEnabled ? groupHeaderRow(chunkCols, tb.columnGroups, false, true) : '';

  const colgroup = `<colgroup>` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      const bg = r.bgColor || sectionBg;
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell" colspan="${chunkCols.length}" style="background:${bg};"></td></tr>`;
    }
    const heightAttr = r.height ? ` style="height:${r.height}px;"` : '';
    return `<tr${heightAttr}>${previewBodyCellsForRow(r, chunkCols, tb, coverage, sectionBg)}</tr>`;
  }).join('');

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${groupRow}
        ${bodyRows}
      </table>
    </div>`;
}

function previewRenderTable(tb, groupsEnabled, sectionBg) {
  const groupsWithTables = groupsEnabled ? tb.columnGroups.filter(g => g.tables.length > 0) : [];
  const isContainerOnly = tb.columns.length === 0 && groupsWithTables.length > 0;
  const blocks = isContainerOnly ? '' : chunkColumns(tb.columns).map(chunkCols => previewColumnBlock(chunkCols, tb, groupsEnabled, sectionBg)).join('');
  const titleStyle = tb.headerColor ? ` style="background:${tb.headerColor};color:${contrastText(tb.headerColor)};"` : '';
  const wrapStyle = tb.bgColor ? ` style="background:${tb.bgColor};"` : '';

  const nestedGroupsHtml = groupsWithTables.map(g => `
    <div class="group-tables">
      ${g.tables.map(ctb => previewRenderTable(ctb, groupsEnabled, sectionBg)).join('')}
    </div>`).join('');

  return `<div class="tbl-wrap"${wrapStyle}>
    ${tb.title ? `<div class="tbl-title"${titleStyle}>${esc(tb.title)}</div>` : ''}
    ${blocks}
    ${nestedGroupsHtml}
  </div>`;
}

function renderPreviewSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  const headColor = sec.color || meta.color;
  const groupsEnabled = false;
  const noteBar = sec.noteEnabled && sec.noteText ? `<p class="section-note">* ${esc(sec.noteText)}</p>` : '';
  const sectionBg = sec.bgColor || '#111827';
  return `<div class="section-card">
    <div class="section-head" style="background:${headColor};">${esc(sec.title)}</div>
    ${noteBar}
    <div class="section-body"${sec.bgColor ? ` style="background:${sec.bgColor};"` : ''}>
      ${sec.tables.map(tb => previewRenderTable(tb, groupsEnabled, sectionBg)).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
}

// Discord post preview: a first-pass "how will this raid split into Discord messages" tool
// for template designers. Not wired to actual posting yet (raids/view.php's canvas export is
// the only thing that really posts) -- this just visualizes the grouping so designers can see
// it before that machinery is taught to draw multiple images. Nested column-group tables are
// flattened alongside their parent (mirroring raids/view.php's flattenSectionTables, since the
// real export will eventually need to consider them too), and each table renders at full width
// (no MAX_DATA_COLS chunking) since a real Discord image draws one continuous table, not a
// wrapped on-screen grid.
const DISCORD_MAX_COLS = 9;
const DISCORD_MAX_COL_PX = DEFAULT_COL_UNITS * COL_UNIT_PX;
const DISCORD_MAX_TOTAL_PX = DISCORD_MAX_COLS * DISCORD_MAX_COL_PX;
function discordColWidthPx(c, tb) {
  return Math.min(colWidthPx(c, tb), DISCORD_MAX_COL_PX);
}
// A raw column count is a poor proxy for how much horizontal room a table actually needs --
// a table full of wide text columns eats the budget far faster than one of narrow icon/spacer
// columns. Budget by actual rendered pixel width instead (post cap = 9 columns' worth of the
// default general-cell width, 1080px), matching the same discordColWidthPx cap already used
// to render each column.
function tableWidthPx(tb) {
  return tb.columns.reduce((sum, c) => sum + discordColWidthPx(c, tb), 0);
}
function flattenSectionTables(tables) {
  const out = [];
  for (const tb of tables) {
    out.push(tb);
    for (const g of tb.columnGroups) {
      if (g.tables.length) out.push(...flattenSectionTables(g.tables));
    }
  }
  return out;
}
// Greedily packs tables side-by-side into "posts" up to a combined DISCORD_MAX_TOTAL_PX width
// budget (9 columns' worth of the default general-cell width, 1080px). Two spacer columns
// meeting at a table-to-table boundary within the same post count as one combined column, not
// two -- so only the narrower of the pair's widths is added, not both -- since they'd visually
// merge into a single gap in the image. A table that alone exceeds the budget still gets its
// own post -- it can't be split further. Packing runs across the whole tab, not reset at each
// section boundary: most sections here hold exactly one table each, so a per-section budget
// would almost never combine anything -- the live Discord canvas export (raids/view.php)
// already stacks every section into one image regardless of section boundaries, so packing
// should be free to do the same. Returns [{ entries, width }, ...].
function groupEntriesIntoPosts(entries) {
  const posts = [];
  let current = [];
  let currentWidth = 0;
  const flush = () => { if (current.length) posts.push({ entries: current, width: currentWidth }); };
  for (const entry of entries) {
    const tb = entry.tb;
    const width = tableWidthPx(tb);
    let addWidth = width;
    if (current.length) {
      const prevTb = current[current.length - 1].tb;
      const prevLast = prevTb.columns[prevTb.columns.length - 1];
      const nextFirst = tb.columns[0];
      if (prevLast && nextFirst && prevLast.kind === 'spacer' && nextFirst.kind === 'spacer') {
        const prevW = discordColWidthPx(prevLast, prevTb);
        const nextW = discordColWidthPx(nextFirst, tb);
        addWidth = Math.max(0, addWidth - Math.min(prevW, nextW));
      }
    }
    if (current.length && currentWidth + addWidth > DISCORD_MAX_TOTAL_PX) {
      flush();
      current = [entry];
      currentWidth = width;
    } else {
      current.push(entry);
      currentWidth += addWidth;
    }
  }
  flush();
  return posts;
}
function discordColumnBlock(tb, sectionBg) {
  const coverage = computeMergeCoverage(tb);
  const chunkCols = tb.columns;
  const colgroup = `<colgroup>` +
    chunkCols.map(c => {
      const w = discordColWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      const bg = r.bgColor || sectionBg;
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell" colspan="${chunkCols.length}" style="background:${bg};"></td></tr>`;
    }
    const heightAttr = r.height ? ` style="height:${r.height}px;"` : '';
    return `<tr${heightAttr}>${previewBodyCellsForRow(r, chunkCols, tb, coverage, sectionBg)}</tr>`;
  }).join('');

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${bodyRows}
      </table>
    </div>`;
}
function discordRenderTable(tb, sec) {
  const sectionBg = sec.bgColor || '#111827';
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  const secColor = sec.color || meta.color;
  const block = tb.columns.length ? discordColumnBlock(tb, sectionBg) : '';
  const titleStyle = tb.headerColor ? ` style="background:${tb.headerColor};color:${contrastText(tb.headerColor)};"` : '';
  const wrapStyle = tb.bgColor ? ` style="background:${tb.bgColor};"` : '';
  return `<div class="tbl-wrap"${wrapStyle}>
    <div class="discord-sec-tag" style="background:${secColor};">${esc(sec.title)}</div>
    ${tb.title ? `<div class="tbl-title"${titleStyle}>${esc(tb.title)}</div>` : ''}
    ${block}
  </div>`;
}
// Renders one whole tab's worth of sections as a flat sequence of Discord "posts" -- packing
// spans section boundaries (see groupEntriesIntoPosts above), so each table carries its own
// section-name tag (discord-sec-tag) since a single post can now mix tables from more than
// one section.
function renderDiscordTab(secs) {
  const flat = [];
  for (const sec of secs) {
    for (const tb of flattenSectionTables(sec.tables)) flat.push({ tb, sec });
  }
  if (!flat.length) return '<p class="empty">No tables in this tab.</p>';
  const posts = groupEntriesIntoPosts(flat);
  return `<div class="discord-panel">` + posts.map((post, idx) => {
    const { entries, width } = post;
    return `<div class="discord-post">
      <div class="discord-post-label">Post ${idx + 1}<span class="discord-post-meta">${entries.length} table${entries.length === 1 ? '' : 's'} &middot; ~${Math.round(width)}px / ${DISCORD_MAX_TOTAL_PX}px</span></div>
      <div class="discord-post-row">
        ${entries.map(e => discordRenderTable(e.tb, e.sec)).join('')}
      </div>
    </div>`;
  }).join('') + `</div>`;
}

// Colour/Merge mode: same read-only rendering as the preview above (tables "as they will
// appear on a raid page", no editing controls), but with data-row-id/data-col-id/data-table-id
// kept on every paintable cell, plus a thin row/column header strip whose only job is to be
// a click target for "paint this whole row/column" -- an affordance that doesn't exist on the
// raid page itself, added here purely for the paint tool.
function colourBodyCellsForRow(r, chunkCols, tb, coverage, sectionBg) {
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (coverage.covered.has(`${r.id}_${c.id}`)) { i++; continue; }
    if (c.kind === 'spacer') {
      // Unpainted spacer columns resolve explicitly to the section's own background (not
      // just left unset), so they show through to the section colour even when the table
      // they sit in has its own painted background.
      const bg = c.bgColor || sectionBg;
      out.push(`<td class="spacer-cell paint-spacer-col" data-table-id="${tb.id}" data-spacer-col-id="${c.id}" style="background:${bg};" title="Paint this spacer column"></td>`);
      i++; continue;
    }
    const cell = cellFor(tb, r.id, c.id);
    const eff = effectiveKind(r, c, cell);
    const span = coverage.spans[`${r.id}_${c.id}`] || { colspan: 1, rowspan: 1 };
    const colspan = Math.min(span.colspan, chunkCols.length - i);
    const colspanAttr = colspan > 1 ? ` colspan="${colspan}"` : '';
    const rowspanAttr = span.rowspan > 1 ? ` rowspan="${span.rowspan}"` : '';
    const removeMergeBtn = (span.colspan > 1 || span.rowspan > 1)
      ? `<button type="button" class="cell-split-btn" data-action="remove-merge" data-row-id="${r.id}" data-col-id="${c.id}" title="Remove merge">&times;</button>`
      : '';
    if (eff === 'spacer') {
      // A cell-level "Filler" override (row/column themselves aren't spacer-kind) — it
      // still owns a real raid_template_cells row, so it stays paintable and mergeable like
      // any other cell; carries the same colspan/rowspan/remove-merge as a normal cell so a
      // merge anchored here doesn't leave the row's <td> count short.
      const bg = cell.bgColor || sectionBg;
      const minWidthStyle = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? `min-width:${NARROW_MIN_COL_PX}px;` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell spacer-cell" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}" style="${minWidthStyle}background:${bg};" title="Paint this filler cell">${removeMergeBtn}</td>`);
      i += colspan; continue;
    }
    const bg = cell.bgColor || null;
    // Column-kind min-width, not the cell's effective kind -- the width-adjustment buttons key
    // off c.kind too, so an icon column stays shrinkable to its lower floor even where a cell's
    // own kind_override makes it render as text/general, matching the column's actual width.
    const minWidthStyle = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? `min-width:${NARROW_MIN_COL_PX}px;` : '';
    const style = minWidthStyle + (eff === 'text'
      ? `${bg ? `background:${bg};` : ''}color:${cell.textColor || 'inherit'};${cellTextStyle(cell)}`
      : (bg ? `background:${bg};` : ''));
    const content = eff === 'text'
      ? renderCellTextHtml(cell.textContent)
      : (eff === 'icon'
        ? (cell.icon ? `<span class="raid-icon-cell" style="${raidIconStyle(cell.icon, 26)}"></span>` : '<span class="empty-slot">+</span>')
        : '<span class="empty-slot">+</span>');
    out.push(`<td${colspanAttr}${rowspanAttr} class="cell" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}" style="${style}">${content}${removeMergeBtn}</td>`);
    i += colspan;
  }
  return out.join('');
}

function colourColumnBlock(chunkCols, tb, sectionBg) {
  const coverage = computeMergeCoverage(tb);
  const colgroup = `<colgroup><col style="width:22px;">` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const headerRow = `<tr><th class="paint-corner" data-action="paint-table-bg" data-table-id="${tb.id}" title="Paint this table's background"></th>` + chunkCols.map(c =>
    `<th class="paint-col-th" data-action="paint-column" data-table-id="${tb.id}" data-col-id="${c.id}" title="Paint this ${c.kind === 'spacer' ? 'spacer column' : 'whole column'}">&#9632;</th>`
  ).join('') + `</tr>`;

  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      const bg = r.bgColor || sectionBg;
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell"></td><td class="spacer-cell paint-spacer-row" colspan="${chunkCols.length}" data-table-id="${tb.id}" data-spacer-row-id="${r.id}" style="background:${bg};" title="Paint this spacer row"></td></tr>`;
    }
    const heightAttr = r.height ? ` style="height:${r.height}px;"` : '';
    const rowHeader = `<th class="paint-row-th" data-action="paint-row" data-table-id="${tb.id}" data-row-id="${r.id}" title="Paint this whole row">&#9632;</th>`;
    return `<tr${heightAttr}>${rowHeader}${colourBodyCellsForRow(r, chunkCols, tb, coverage, sectionBg)}</tr>`;
  }).join('');

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${headerRow}
        ${bodyRows}
      </table>
    </div>`;
}

function colourRenderTable(tb, sectionBg) {
  const blocks = chunkColumns(tb.columns).map(chunkCols => colourColumnBlock(chunkCols, tb, sectionBg)).join('');
  const titleStyle = tb.headerColor ? ` style="background:${tb.headerColor};color:${contrastText(tb.headerColor)};"` : '';
  const wrapStyle = tb.bgColor ? ` style="background:${tb.bgColor};"` : '';
  return `<div class="tbl-wrap"${wrapStyle}>
    ${tb.title ? `<div class="tbl-title paint-table-title" data-action="paint-table-header" data-table-id="${tb.id}"${titleStyle} title="Click to paint this table's title bar">${esc(tb.title)}</div>` : ''}
    ${blocks}
  </div>`;
}

function renderColourSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  const headColor = sec.color || meta.color;
  const noteBar = sec.noteEnabled && sec.noteText ? `<p class="section-note">* ${esc(sec.noteText)}</p>` : '';
  const sectionBg = sec.bgColor || '#111827';
  const bodyStyle = sec.bgColor ? ` style="background:${sec.bgColor};"` : '';
  return `<div class="section-card">
    <div class="section-head paint-section-head" data-action="paint-section" data-section-id="${sec.id}" style="background:${headColor};" title="Click to paint this section header">
      <span class="section-head-title">${esc(sec.title)}</span>
      <button type="button" class="icon-btn" data-action="preview-section" data-id="${sec.id}" title="Preview as it will look on a raid page">&#128065;</button>
    </div>
    ${noteBar}
    <div class="section-body paint-section-body" data-action="paint-section-bg" data-section-id="${sec.id}"${bodyStyle} title="Click to paint this section's background">
      ${sec.tables.map(tb => colourRenderTable(tb, sectionBg)).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
}

const PAINT_PRESETS = ['#e05555', '#f0a030', '#f0c04a', '#4caf6a', '#4ac0c0', '#5865f2', '#9b59d0', '#e86ec2', '#c7cef2', '#1a2338'];

function renderPaintBar() {
  const el = document.getElementById('paintBarEl');
  const transparentSwatch = `<button type="button" class="paint-swatch paint-swatch-transparent ${paintArmed && paintErase ? 'active' : ''}" data-action="pick-paint-erase" title="Transparent (clear color)"></button>`;
  const swatches = transparentSwatch + PAINT_PRESETS.map(c =>
    `<button type="button" class="paint-swatch ${paintArmed && !paintErase && paintColor.toLowerCase() === c ? 'active' : ''}" data-action="pick-paint-color" data-color="${c}" style="background:${c};" title="${c}"></button>`
  ).join('');
  const isCustomActive = paintArmed && !paintErase && !PAINT_PRESETS.some(p => p.toLowerCase() === paintColor.toLowerCase());
  const hint = mergeArmed
    ? `<span class="paint-armed-hint">Drag across 2+ cells to merge them, or click an already-merged cell to split it apart. <button type="button" class="paint-stop-btn" data-action="stop-merge">Stop</button></span>`
    : paintArmed
      ? `<span class="paint-armed-hint">${paintErase ? 'Erasing' : 'Painting'} — click, drag, or click a row/column header below. <button type="button" class="paint-stop-btn" data-action="stop-paint">Stop</button></span>`
      : `<span class="paint-bar-hint">Pick a color, then click, drag, or click a row/column header on the tables below.</span>`;
  el.innerHTML = `
    <span class="paint-bar-label">Paint</span>
    <div class="paint-swatches">${swatches}</div>
    <label class="paint-custom-swatch ${isCustomActive ? 'active' : ''}" id="paintCustomSwatch" style="background:${isCustomActive ? paintColor : '#1a2338'};" title="Custom color">
      <input type="color" id="paintCustomColor" value="${paintColor}">
    </label>
    <button type="button" class="paint-tool-btn ${paintArmed && paintErase ? 'active' : ''}" data-action="pick-paint-erase" title="Clear color from painted cells">Eraser</button>
    <button type="button" class="paint-tool-btn ${mergeArmed ? 'active' : ''}" data-action="toggle-merge" title="Drag across 2+ cells to merge them">Merge cells</button>
    ${hint}`;
  el.querySelectorAll('[data-action]').forEach(node => {
    node.addEventListener('click', () => {
      const act = node.dataset.action;
      if (act === 'pick-paint-color') { paintColor = node.dataset.color; paintErase = false; paintArmed = true; mergeArmed = false; document.body.classList.add('paint-mode-active'); document.body.classList.remove('merge-mode-active'); renderPaintBar(); }
      if (act === 'pick-paint-erase') { paintErase = true; paintArmed = true; mergeArmed = false; document.body.classList.add('paint-mode-active'); document.body.classList.remove('merge-mode-active'); renderPaintBar(); }
      if (act === 'stop-paint') { paintArmed = false; document.body.classList.remove('paint-mode-active'); renderPaintBar(); }
      if (act === 'toggle-merge') {
        mergeArmed = !mergeArmed;
        paintArmed = false;
        document.body.classList.remove('paint-mode-active');
        document.body.classList.toggle('merge-mode-active', mergeArmed);
        renderPaintBar();
      }
      if (act === 'stop-merge') { mergeArmed = false; document.body.classList.remove('merge-mode-active'); renderPaintBar(); }
    });
  });
  document.getElementById('paintCustomColor').addEventListener('input', e => {
    // Only update state while the native picker is open -- re-rendering (which rebuilds
    // this very input) is deferred to 'change' below so it doesn't interrupt the picker.
    paintColor = e.target.value; paintErase = false; paintArmed = true; mergeArmed = false; document.body.classList.add('paint-mode-active'); document.body.classList.remove('merge-mode-active');
    const swatchLabel = document.getElementById('paintCustomSwatch');
    if (swatchLabel) { swatchLabel.style.background = paintColor; swatchLabel.classList.add('active'); }
  });
  document.getElementById('paintCustomColor').addEventListener('change', e => {
    // Some browsers (notably Safari) only fire 'change' on a color input, never 'input' --
    // so 'change' must set the arm state itself rather than assume 'input' already did.
    paintColor = e.target.value; paintErase = false; paintArmed = true; mergeArmed = false; document.body.classList.add('paint-mode-active'); document.body.classList.remove('merge-mode-active');
    renderPaintBar();
  });
}

// Paint tool wiring for the read-only Colour/Merge grid: row/column headers apply on a
// single click (no drag); individual cells support both a plain click and a click-drag
// gesture that accumulates every cell the cursor passes over, applying color to all of them
// in one batched paint_cells call per table on mouseup (see the document-level mousemove/
// mouseup listeners below, registered once outside render() since this element is rebuilt
// on every render()).
function wireColourPaint(el) {
  // Always-available un-merge button on any merged anchor cell -- independent of whether
  // the merge tool is armed, unlike the click-with-no-drag un-merge gesture below.
  el.querySelectorAll('[data-action="remove-merge"]').forEach(node => {
    node.addEventListener('mousedown', e => e.stopPropagation());
    node.addEventListener('click', e => {
      e.stopPropagation();
      const rowId = parseInt(node.dataset.rowId, 10);
      const columnId = parseInt(node.dataset.colId, 10);
      call({ action: 'split_cell', rowId, columnId });
    });
  });

  el.querySelectorAll('[data-action="preview-section"]').forEach(node => {
    node.addEventListener('mousedown', e => e.stopPropagation());
    node.addEventListener('click', e => {
      e.stopPropagation();
      openPreview(parseInt(node.dataset.id, 10));
    });
  });

  el.querySelectorAll('[data-action="paint-section"]').forEach(node => {
    node.addEventListener('click', () => {
      if (!paintArmed) return;
      const sectionId = parseInt(node.dataset.sectionId, 10);
      const color = paintErase ? null : paintColor;
      call({ action: 'paint_section', id: sectionId, color, field: 'color' });
    });
  });

  el.querySelectorAll('[data-action="paint-section-bg"]').forEach(node => {
    node.addEventListener('click', e => {
      // Guard against the section-body's own listener firing on a click that bubbled up
      // from a child table/cell, which has already handled the click itself.
      if (e.target !== node) return;
      if (!paintArmed) return;
      const sectionId = parseInt(node.dataset.sectionId, 10);
      const color = paintErase ? null : paintColor;
      call({ action: 'paint_section', id: sectionId, color, field: 'bgColor' });
    });
  });

  el.querySelectorAll('[data-action="paint-table-header"]').forEach(node => {
    node.addEventListener('click', () => {
      if (!paintArmed) return;
      const tableId = parseInt(node.dataset.tableId, 10);
      const color = paintErase ? null : paintColor;
      call({ action: 'paint_table', id: tableId, color, field: 'headerColor' });
    });
  });

  el.querySelectorAll('[data-action="paint-table-bg"]').forEach(node => {
    node.addEventListener('click', () => {
      if (!paintArmed) return;
      const tableId = parseInt(node.dataset.tableId, 10);
      const color = paintErase ? null : paintColor;
      call({ action: 'paint_table', id: tableId, color, field: 'bgColor' });
    });
  });

  el.querySelectorAll('[data-action="paint-row"], [data-action="paint-column"]').forEach(node => {
    node.addEventListener('click', () => {
      if (!paintArmed) return;
      const tb = findTable(parseInt(node.dataset.tableId, 10));
      if (!tb) return;
      const color = paintErase ? null : paintColor;
      if (node.dataset.action === 'paint-row') {
        const rowId = parseInt(node.dataset.rowId, 10);
        const row = tb.rows.find(r => r.id === rowId);
        if (row && row.kind === 'spacer') {
          call({ action: 'paint_cells', tableId: tb.id, color, spacerRows: [rowId] });
        } else {
          const cells = tb.columns.filter(c => c.kind !== 'spacer').map(c => ({ rowId, columnId: c.id }));
          if (cells.length) call({ action: 'paint_cells', tableId: tb.id, color, cells });
        }
      } else {
        const columnId = parseInt(node.dataset.colId, 10);
        const col = tb.columns.find(c => c.id === columnId);
        if (col && col.kind === 'spacer') {
          call({ action: 'paint_cells', tableId: tb.id, color, spacerColumns: [columnId] });
        } else {
          const cells = tb.rows.filter(r => r.kind !== 'spacer').map(r => ({ rowId: r.id, columnId }));
          if (cells.length) call({ action: 'paint_cells', tableId: tb.id, color, cells });
        }
      }
    });
  });

  el.querySelectorAll('td.cell[data-row-id][data-col-id], td.paint-spacer-row[data-spacer-row-id], td.paint-spacer-col[data-spacer-col-id]').forEach(td => {
    td.addEventListener('mousedown', e => {
      if (e.button !== 0) return;
      if (mergeArmed) {
        // Merge targets any real (row,column) cell -- including filler-override cells, which
        // still own a real raid_template_cells row -- but not whole-row/whole-column spacer
        // strips (class "spacer-cell" without "cell"), which have no per-cell identity to merge.
        if (!td.classList.contains('cell')) return;
        e.preventDefault();
        mergeDragging = true;
        mergeDragTds = [];
        touchMergeCell(td);
        return;
      }
      if (!paintArmed) return;
      e.preventDefault();
      paintDragging = true;
      paintDragTouched = new Set();
      paintCell(td);
    });
  });
}

// Keys off the first cell touched this drag to keep every subsequent cell scoped to the
// same table -- rectangular merges can span multiple rows and columns, so only the table
// needs to match.
function touchMergeCell(td) {
  if (mergeDragTds.length) {
    const first = mergeDragTds[0];
    if (td.dataset.tableId !== first.dataset.tableId) return;
  }
  if (mergeDragTds.includes(td)) return;
  mergeDragTds.push(td);
  td.classList.add('merge-touched');
}

// Called on mouseup with every <td> the drag touched (in touch order). A single cell with
// no drag either splits an already-merged cell apart (colSpan/rowSpan > 1) or does nothing;
// two or more cells compute the merge's rectangle from the full row/column-index range they
// cover, including any already-merged cell dragged over, then set it directly in one call.
function mergeCellsFromDrag(tds) {
  tds.forEach(td => td.classList.remove('merge-touched'));
  const tableId = parseInt(tds[0].dataset.tableId, 10);
  const tb = findTable(tableId);
  if (!tb) return;
  if (tds.length === 1) {
    const td = tds[0];
    if (td.colSpan > 1 || td.rowSpan > 1) {
      call({ action: 'split_cell', rowId: parseInt(td.dataset.rowId, 10), columnId: parseInt(td.dataset.colId, 10) });
    }
    return;
  }
  let minColIdx = Infinity, maxColIdx = -Infinity, minRowIdx = Infinity, maxRowIdx = -Infinity;
  tds.forEach(td => {
    const colId = parseInt(td.dataset.colId, 10);
    const rowId = parseInt(td.dataset.rowId, 10);
    const colIdx = tb.columns.findIndex(c => c.id === colId);
    const rowIdx = tb.rows.findIndex(r => r.id === rowId);
    if (colIdx < 0 || rowIdx < 0) return;
    minColIdx = Math.min(minColIdx, colIdx);
    maxColIdx = Math.max(maxColIdx, colIdx + (td.colSpan || 1) - 1);
    minRowIdx = Math.min(minRowIdx, rowIdx);
    maxRowIdx = Math.max(maxRowIdx, rowIdx + (td.rowSpan || 1) - 1);
  });
  if (!isFinite(minColIdx) || !isFinite(maxColIdx) || !isFinite(minRowIdx) || !isFinite(maxRowIdx)) return;
  if (maxColIdx <= minColIdx && maxRowIdx <= minRowIdx) return;
  const anchorCol = tb.columns[minColIdx];
  const anchorRow = tb.rows[minRowIdx];
  // HTML colspan/rowspan can only extend right/down, so the merge's structural anchor must
  // stay top-left -- but the cell the user actually clicked (tds[0], the drag's start) is
  // what they expect to see survive as the merged cell's content, which may be a different
  // cell. The backend swaps the two positions' data so the clicked cell's text/color/icon
  // ends up at the anchor position instead of whatever was already top-left.
  const primaryTd = tds[0];
  call({
    action: 'set_cell_merge', tableId: tb.id, rowId: anchorRow.id, columnId: anchorCol.id,
    colspan: maxColIdx - minColIdx + 1, rowspan: maxRowIdx - minRowIdx + 1,
    primaryRowId: parseInt(primaryTd.dataset.rowId, 10), primaryColumnId: parseInt(primaryTd.dataset.colId, 10),
  });
}

// Keys are prefixed by target kind so the mouseup handler below can split one drag gesture
// back into the three distinct payload shapes paint_cells accepts: ordinary (row,column)
// cells, whole spacer rows, and whole spacer columns.
function paintCell(td) {
  let key;
  if (td.dataset.spacerRowId !== undefined) key = `sr_${td.dataset.tableId}_${td.dataset.spacerRowId}`;
  else if (td.dataset.spacerColId !== undefined) key = `sc_${td.dataset.tableId}_${td.dataset.spacerColId}`;
  else key = `c_${td.dataset.tableId}_${td.dataset.rowId}_${td.dataset.colId}`;
  if (paintDragTouched.has(key)) return;
  paintDragTouched.add(key);
  td.style.background = paintErase ? 'transparent' : paintColor;
}

document.addEventListener('mousemove', e => {
  if (paintDragging) {
    const td = e.target.closest('td.cell[data-row-id][data-col-id], td.paint-spacer-row[data-spacer-row-id], td.paint-spacer-col[data-spacer-col-id]');
    if (td && document.getElementById('panelsEl').contains(td)) paintCell(td);
  } else if (mergeDragging) {
    const td = e.target.closest('td.cell[data-row-id][data-col-id]');
    if (td && document.getElementById('panelsEl').contains(td)) touchMergeCell(td);
  }
});

document.addEventListener('mouseup', () => {
  if (paintDragging) {
    paintDragging = false;
    const touched = paintDragTouched;
    paintDragTouched = null;
    if (!touched || !touched.size) return;
    const byTable = new Map();
    const ensure = tableId => {
      if (!byTable.has(tableId)) byTable.set(tableId, { cells: [], spacerRows: [], spacerColumns: [] });
      return byTable.get(tableId);
    };
    touched.forEach(key => {
      const parts = key.split('_');
      if (parts[0] === 'sr') ensure(Number(parts[1])).spacerRows.push(Number(parts[2]));
      else if (parts[0] === 'sc') ensure(Number(parts[1])).spacerColumns.push(Number(parts[2]));
      else ensure(Number(parts[1])).cells.push({ rowId: Number(parts[2]), columnId: Number(parts[3]) });
    });
    const color = paintErase ? null : paintColor;
    byTable.forEach((payload, tableId) => call({ action: 'paint_cells', tableId, color, ...payload }));
  } else if (mergeDragging) {
    mergeDragging = false;
    const touched = mergeDragTds;
    mergeDragTds = null;
    if (touched && touched.length) mergeCellsFromDrag(touched);
  }
});

// Logic mode: class-restriction / max-count assignment rules, authored per table. A rule's
// cell scope is picked directly on a read-only grid (same rendering approach as Colour/Merge
// above) -- clicking a general-kind cell toggles it in/out of the in-progress draft's
// cellRefs. Only general-kind cells are pickable since only those ever hold a toon
// assignment; text/icon/spacer cells render dimmed and inert.
const RULE_CLASSES = ['Warrior', 'Paladin', 'Priest', 'Druid', 'Rogue', 'Mage', 'Warlock', 'Shaman', 'Hunter'];

function ruleSummary(rule) {
  const scopeLabel = rule.scope === 'table' ? 'whole table' : `${rule.cellRefs.length} cell${rule.cellRefs.length === 1 ? '' : 's'}`;
  if (rule.ruleType === 'class_restrict') {
    const classes = (rule.classes || '').split(',').filter(Boolean).join(', ');
    return `${classes || '(no classes picked)'} only — ${scopeLabel}`;
  }
  const max = rule.maxCount || 1;
  return `Max ${max} assignment${max === 1 ? '' : 's'} — ${scopeLabel}`;
}

function renderRulesList(tb) {
  if (!tb.rules.length) return '<p class="empty">No rules on this table yet.</p>';
  return `<ul class="logic-rules-list">` + tb.rules.map(r => `
    <li class="logic-rule-row">
      <span class="logic-rule-summary">${esc(ruleSummary(r))}${r.label ? ` <em>(${esc(r.label)})</em>` : ''}</span>
      <span class="logic-rule-actions">
        <button type="button" data-action="logic-edit-rule" data-rule-id="${r.id}" title="Edit rule">&#9998;</button>
        <button type="button" data-action="logic-delete-rule" data-rule-id="${r.id}" title="Delete rule">&times;</button>
      </span>
    </li>`).join('') + `</ul>`;
}

function renderRuleDraftForm() {
  const d = logicDraft;
  const classChecks = RULE_CLASSES.map(c => `
    <label class="logic-class-check">
      <input type="checkbox" data-action="logic-toggle-class" value="${escAttr(c)}" ${d.classes.has(c) ? 'checked' : ''}>
      ${esc(c)}
    </label>`).join('');

  const typeFields = d.ruleType === 'class_restrict'
    ? `<div class="logic-class-grid">${classChecks}</div>`
    : `<label class="logic-maxcount-field">Max assignments<input type="number" id="logicMaxCount" min="1" value="${d.maxCount}"></label>`;

  const pickHint = d.scope === 'cells'
    ? `<p class="logic-picker-hint">${d.cellRefs.size} cell${d.cellRefs.size === 1 ? '' : 's'} selected — click cells on the grid to add or remove them.</p>`
    : '';

  return `<div class="logic-draft-form">
    <div class="logic-draft-row">
      <label>Rule type
        <select id="logicRuleType">
          <option value="class_restrict" ${d.ruleType === 'class_restrict' ? 'selected' : ''}>Class restriction</option>
          <option value="max_count" ${d.ruleType === 'max_count' ? 'selected' : ''}>Max assignments</option>
        </select>
      </label>
      <label>Scope
        <select id="logicScope">
          <option value="cells" ${d.scope === 'cells' ? 'selected' : ''}>Specific cells</option>
          <option value="table" ${d.scope === 'table' ? 'selected' : ''}>Whole table</option>
        </select>
      </label>
    </div>
    ${typeFields}
    ${pickHint}
    <label class="logic-label-field">Message shown when blocked (optional)<input type="text" id="logicLabel" maxlength="120" value="${escAttr(d.label)}" placeholder="e.g. Paladins only"></label>
    <div class="logic-draft-actions">
      <button type="button" class="btn" data-action="logic-save-rule">${d.id ? 'Save changes' : 'Add rule'}</button>
      <button type="button" class="btn" data-action="logic-cancel-rule" style="background:rgba(255,255,255,0.08);">Cancel</button>
    </div>
  </div>`;
}

function logicBodyCellsForRow(r, chunkCols, tb, coverage) {
  const picking = !!(logicDraft && logicDraft.scope === 'cells' && logicDraft.tableId === tb.id);
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (coverage.covered.has(`${r.id}_${c.id}`)) { i++; continue; }
    if (c.kind === 'spacer') { out.push(`<td class="spacer-cell"></td>`); i++; continue; }
    const span = coverage.spans[`${r.id}_${c.id}`] || { colspan: 1, rowspan: 1 };
    const colspan = Math.min(span.colspan, chunkCols.length - i);
    const colspanAttr = colspan > 1 ? ` colspan="${colspan}"` : '';
    const rowspanAttr = span.rowspan > 1 ? ` rowspan="${span.rowspan}"` : '';
    const cell = cellFor(tb, r.id, c.id);
    const eff = effectiveKind(r, c, cell);
    if (eff !== 'general') {
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell logic-cell-disabled" title="Rules only apply to general (assignable) cells"></td>`);
      i += colspan; continue;
    }
    const key = `${r.id}_${c.id}`;
    const selected = picking && logicDraft.cellRefs.has(key);
    const inOtherRule = !picking && tb.rules.some(rule => rule.scope === 'cells' && rule.cellRefs.some(cr => cr.rowId === r.id && cr.columnId === c.id));
    const cls = ['cell', 'logic-cell', picking ? 'logic-picking' : '', selected ? 'logic-cell-selected' : '', inOtherRule ? 'logic-cell-in-rule' : ''].filter(Boolean).join(' ');
    out.push(`<td${colspanAttr}${rowspanAttr} class="${cls}" data-row-id="${r.id}" data-col-id="${c.id}" title="${escAttr((c.label || '') + (r.label ? ' / ' + r.label : ''))}"><span class="empty-slot">${selected ? '&#10003;' : '+'}</span></td>`);
    i += colspan;
  }
  return out.join('');
}

function logicColumnBlock(chunkCols, tb) {
  const coverage = computeMergeCoverage(tb);
  const colgroup = `<colgroup>` + chunkCols.map(c => {
    const w = colWidthPx(c, tb);
    return `<col${w ? ` style="width:${w}px;"` : ''}>`;
  }).join('') + `</colgroup>`;
  const headerRow = `<tr>` + chunkCols.map(c => `<th>${c.kind === 'spacer' ? '' : esc(c.label || '')}</th>`).join('') + `</tr>`;
  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell" colspan="${chunkCols.length}"></td></tr>`;
    }
    const heightAttr = r.height ? ` style="height:${r.height}px;"` : '';
    return `<tr${heightAttr}>${logicBodyCellsForRow(r, chunkCols, tb, coverage)}</tr>`;
  }).join('');
  return `<div class="grid-scroll"><table class="grid">${colgroup}${headerRow}${bodyRows}</table></div>`;
}

function logicRenderTable(tb) {
  const blocks = chunkColumns(tb.columns).map(chunkCols => logicColumnBlock(chunkCols, tb)).join('');
  return `<div class="tbl-wrap">${blocks}</div>`;
}

// Groups every table in the active tab (including nested boss-tables inside column
// groups, same traversal order as allTables()) under its parent section, so Logic mode can
// list every table in the current tab at once with a section heading for context instead
// of hiding them behind a picker.
function tablesGroupedForLogic() {
  const groups = [];
  for (const sec of sections) {
    if (sec.kind !== activeTab) continue;
    const list = [];
    const walk = tables => { for (const tb of tables) { list.push(tb); for (const g of tb.columnGroups) walk(g.tables); } };
    walk(sec.tables);
    if (list.length) groups.push({ sec, list });
  }
  return groups;
}

// Untitled tables (e.g. roster tables, which commonly have no title set) fall back to
// their section's title, disambiguated with a "table N" suffix if the section holds more
// than one -- so every table is identifiable without needing its own name.
function logicTableLabel(tb, sec, idx, total) {
  if (tb.title) return tb.title;
  return total > 1 ? `${sec.title} — table ${idx + 1}` : sec.title;
}

function renderLogicTableCard(tb, label) {
  if (!tb.rules) tb.rules = []; // defensive: older cached data shapes may lack this field
  const isDrafting = !!(logicDraft && logicDraft.tableId === tb.id);
  const draftHtml = isDrafting ? renderRuleDraftForm() : `<button type="button" class="btn" data-action="logic-add-rule" data-table-id="${tb.id}">+ Add Rule</button>`;
  return `<div class="logic-table-card" data-table-id="${tb.id}">
    <h4 class="logic-table-heading">${esc(label)}</h4>
    <div class="logic-body">
      <div class="logic-rules-col">
        ${renderRulesList(tb)}
        ${draftHtml}
      </div>
      <div class="logic-grid-col">${logicRenderTable(tb)}</div>
    </div>
  </div>`;
}

function renderLogicMode(el) {
  if (logicDraft && !findTable(logicDraft.tableId)) logicDraft = null; // table was deleted mid-edit

  const groups = tablesGroupedForLogic();
  const sectionsHtml = groups.map(({ sec, list }) => `
    <div class="logic-section">
      <h3 class="logic-section-title">${esc(sec.title)}</h3>
      ${list.map((tb, idx) => renderLogicTableCard(tb, logicTableLabel(tb, sec, idx, list.length))).join('')}
    </div>`).join('');

  el.innerHTML = `
    <div class="logic-header">
      <h2>Logic</h2>
      <p class="logic-hint">Restrict which classes can be assigned to a cell, or cap how many cells the same toon can occupy. Rules are enforced when toons are assigned on the raid page.</p>
    </div>
    ${sectionsHtml || '<p class="empty">No tables in this template yet &mdash; switch to Layout mode to add one.</p>'}`;

  wireLogicMode(el);
}

function wireLogicMode(el) {
  el.querySelectorAll('[data-action="logic-add-rule"]').forEach(btn => btn.addEventListener('click', () => {
    logicDraft = { tableId: parseInt(btn.dataset.tableId, 10), id: null, ruleType: 'class_restrict', scope: 'cells', classes: new Set(), maxCount: 1, label: '', cellRefs: new Set() };
    renderLogicMode(el);
  }));

  el.querySelectorAll('[data-action="logic-edit-rule"]').forEach(btn => btn.addEventListener('click', () => {
    const card = btn.closest('.logic-table-card');
    const tb = card ? findTable(parseInt(card.dataset.tableId, 10)) : null;
    const rule = tb && tb.rules.find(r => r.id === parseInt(btn.dataset.ruleId, 10));
    if (!rule) return;
    logicDraft = {
      tableId: tb.id,
      id: rule.id,
      ruleType: rule.ruleType,
      scope: rule.scope,
      classes: new Set((rule.classes || '').split(',').filter(Boolean)),
      maxCount: rule.maxCount || 1,
      label: rule.label || '',
      cellRefs: new Set(rule.cellRefs.map(cr => `${cr.rowId}_${cr.columnId}`)),
    };
    renderLogicMode(el);
  }));

  el.querySelectorAll('[data-action="logic-delete-rule"]').forEach(btn => btn.addEventListener('click', () => {
    if (!confirm('Delete this rule?')) return;
    logicDraft = null;
    call({ action: 'delete_rule', ruleId: parseInt(btn.dataset.ruleId, 10) });
  }));

  if (!logicDraft) return;
  const d = logicDraft;

  const typeSel = document.getElementById('logicRuleType');
  if (typeSel) typeSel.addEventListener('change', () => { d.ruleType = typeSel.value; renderLogicMode(el); });

  const scopeSel = document.getElementById('logicScope');
  if (scopeSel) scopeSel.addEventListener('change', () => { d.scope = scopeSel.value; renderLogicMode(el); });

  el.querySelectorAll('[data-action="logic-toggle-class"]').forEach(cb => cb.addEventListener('change', () => {
    if (cb.checked) d.classes.add(cb.value); else d.classes.delete(cb.value);
  }));

  const maxInput = document.getElementById('logicMaxCount');
  if (maxInput) maxInput.addEventListener('input', () => { d.maxCount = Math.max(1, parseInt(maxInput.value, 10) || 1); });

  const labelInput = document.getElementById('logicLabel');
  if (labelInput) labelInput.addEventListener('input', () => { d.label = labelInput.value; });

  el.querySelectorAll('[data-action="logic-save-rule"]').forEach(btn => btn.addEventListener('click', () => {
    if (d.ruleType === 'class_restrict' && !d.classes.size) { alert('Pick at least one class.'); return; }
    if (d.scope === 'cells' && !d.cellRefs.size) { alert('Pick at least one cell on the grid.'); return; }
    const payload = {
      action: d.id ? 'update_rule' : 'add_rule',
      tableId: d.tableId,
      ruleType: d.ruleType,
      scope: d.scope,
      classes: d.ruleType === 'class_restrict' ? Array.from(d.classes) : [],
      maxCount: d.ruleType === 'max_count' ? d.maxCount : null,
      label: d.label,
      cellRefs: d.scope === 'cells' ? Array.from(d.cellRefs).map(k => { const [rowId, columnId] = k.split('_').map(Number); return { rowId, columnId }; }) : [],
    };
    if (d.id) payload.ruleId = d.id;
    logicDraft = null;
    call(payload);
  }));

  el.querySelectorAll('[data-action="logic-cancel-rule"]').forEach(btn => btn.addEventListener('click', () => { logicDraft = null; renderLogicMode(el); }));

  if (d.scope === 'cells') {
    el.querySelectorAll('td.logic-cell.logic-picking').forEach(td => td.addEventListener('click', () => {
      const key = `${td.dataset.rowId}_${td.dataset.colId}`;
      if (d.cellRefs.has(key)) d.cellRefs.delete(key); else d.cellRefs.add(key);
      renderLogicMode(el);
    }));
  }
}

function openPreview(secId) {
  const sec = sections.find(s => s.id === secId);
  if (!sec) return;
  document.getElementById('previewTitle').textContent = sec.title || (KIND_META[sec.kind] || {}).label || 'Preview';
  document.getElementById('previewBody').innerHTML = renderPreviewSection(sec);
  document.getElementById('previewBackdrop').classList.add('open');
}
function closePreview() {
  document.getElementById('previewBackdrop').classList.remove('open');
}
document.getElementById('previewClose').addEventListener('click', closePreview);
document.getElementById('previewBackdrop').addEventListener('click', e => {
  if (e.target.id === 'previewBackdrop') closePreview();
});

// Text-cell WYSIWYG popup: opened by clicking a Text-kind cell (see bodyCellsForRow's
// "open-cell-editor" action). A static modal, not part of the panelsEl render tree, so it
// only needs its fields synced on open/save rather than on every render() pass.
let cellEditRowId = null;
let cellEditColId = null;

function openCellEditor(rowId, colId) {
  const tb = tableForCell(rowId, colId);
  const cell = cellFor(tb, rowId, colId);
  cellEditRowId = rowId;
  cellEditColId = colId;
  document.getElementById('cellEditTextarea').value = cell.textContent || '';
  document.getElementById('cellEditBold').classList.toggle('active', !!cell.bold);
  document.getElementById('cellEditFont').value = cell.font || '';
  document.getElementById('cellEditColor').value = cell.textColor || '#e8ecff';
  document.getElementById('cellEditBackdrop').classList.add('open');
  document.getElementById('cellEditTextarea').focus();
}
function closeCellEditor() {
  document.getElementById('cellEditBackdrop').classList.remove('open');
  cellEditRowId = null;
  cellEditColId = null;
}
document.getElementById('cellEditFont').innerHTML = CELL_FONTS.map(f => `<option value="${f.key}">${f.label}</option>`).join('');
document.getElementById('cellEditIconPalette').innerHTML = RAID_ICON_KEYS.map(k => `<button type="button" class="icon-swatch-btn" data-icon="${k}" style="${raidIconStyle(k, 22)}" title="Insert :${k}:"></button>`).join('');
document.getElementById('cellEditIconPalette').querySelectorAll('[data-icon]').forEach(btn => {
  btn.addEventListener('click', () => {
    const ta = document.getElementById('cellEditTextarea');
    const token = `:${btn.dataset.icon}:`;
    const start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    const pos = start + token.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
  });
});
document.getElementById('cellEditBold').addEventListener('click', () => {
  document.getElementById('cellEditBold').classList.toggle('active');
});
document.getElementById('cellEditClose').addEventListener('click', closeCellEditor);
document.getElementById('cellEditCancel').addEventListener('click', closeCellEditor);
document.getElementById('cellEditBackdrop').addEventListener('click', e => {
  if (e.target.id === 'cellEditBackdrop') closeCellEditor();
});
document.getElementById('cellEditSave').addEventListener('click', () => {
  if (cellEditRowId === null || cellEditColId === null) return;
  const cell = cellFor(tableForCell(cellEditRowId, cellEditColId), cellEditRowId, cellEditColId);
  const rowId = cellEditRowId, colId = cellEditColId;
  call({
    action: 'update_cell',
    rowId,
    columnId: colId,
    textContent: document.getElementById('cellEditTextarea').value,
    bgColor: cell.bgColor,
    textColor: document.getElementById('cellEditColor').value,
    bold: document.getElementById('cellEditBold').classList.contains('active'),
    font: document.getElementById('cellEditFont').value || null,
    icon: cell.icon,
  }).then(() => closeCellEditor());
});

// AngryERA export: a {{token}} text template resolved against every row on a given tab
// that has a label — row, or row|column when its table has more than one General column.
// Kind is freeform now, so export config is per-tab (per distinct kind), not global — each
// tab has its own enabled flag, singlePage flag, exportName, and list of named pages.
// Editing here only ever sees the template's own labels as placeholder values; the raid
// page (view.php) resolves the same grammar against that raid's real assignments.
function walkHealerSlots(secs, cb) {
  function walk(tables) {
    for (const tb of tables) {
      for (const r of tb.rows) {
        if (r.kind === 'spacer' || !r.label) continue;
        const dataCols = tb.columns.filter(c => effectiveKind(r, c, cellFor(tb, r.id, c.id)) === 'general');
        if (dataCols.length === 1) {
          cb(r.label, null, r, dataCols[0]);
        } else if (dataCols.length > 1) {
          for (const c of dataCols) { if (c.label) cb(r.label, c.label, r, c); }
        }
      }
      for (const g of tb.columnGroups) walk(g.tables);
    }
  }
  walk(secs.flatMap(s => s.tables));
}

function healerSlotMap(secs, valueFn) {
  const map = {};
  walkHealerSlots(secs, (rowLabel, colLabel, r, c) => {
    const key = (colLabel ? rowLabel + '|' + colLabel : rowLabel).trim().toLowerCase();
    map[key] = valueFn(r, c);
  });
  return map;
}

function applyExportTemplate(tmpl, resolveFn, flagUnknown) {
  return (tmpl || '').replace(/\{\{([^}]+)\}\}/g, (_, expr) => {
    if (expr.charAt(0) === '*') {
      const names = expr.slice(1).split(',').map(k => resolveFn(k.trim())).filter(Boolean);
      return names.join(', ') || '—';
    }
    if (expr.charAt(0) === '#') {
      return expr.slice(1).split(',').map((k, i) => { const nm = resolveFn(k.trim()); return nm ? nm + ' (' + (i + 1) + ')' : ''; }).filter(Boolean).join(', ') || '—';
    }
    const nm = resolveFn(expr.trim());
    if (nm) return nm;
    return flagUnknown ? ('⚠' + expr.trim()) : '—';
  });
}

function tabExportFor(k) {
  return tabExports[k] || { enabled: false, singlePage: false, exportName: '', pages: [] };
}

let angryKind = null;
let angrySelectedPageId = null;

function renderAngryTokens() {
  const el = document.getElementById('angryTokensList');
  const secs = sections.filter(s => s.kind === angryKind);
  const keys = [];
  walkHealerSlots(secs, (rowLabel, colLabel) => keys.push(colLabel ? `${rowLabel}|${colLabel}` : rowLabel));
  el.innerHTML = keys.length
    ? keys.map(k => `<span>{{${esc(k)}}}</span>`).join('')
    : '<span style="background:none;border-style:dashed;color:#7f8bad;">No labeled rows yet on this tab.</span>';
}

function renderAngryPageList() {
  const te = tabExportFor(angryKind);
  const single = document.getElementById('angrySinglePage').checked;
  document.getElementById('angryPageList').style.display = single ? 'none' : 'flex';
  document.getElementById('angryPageName').style.display = single ? 'none' : '';
  document.getElementById('angryAddPageBtn').style.display = single ? 'none' : '';
  document.getElementById('angryDeletePageBtn').style.display = single ? 'none' : '';
  if (single) return;

  const el = document.getElementById('angryPageList');
  el.innerHTML = te.pages.length
    ? te.pages.map(p => `<button type="button" class="angry-page-btn ${p.id === angrySelectedPageId ? 'active' : ''}" data-page-id="${p.id}">${esc(p.name)}</button>`).join('')
    : '<span class="hint">No pages yet.</span>';
  el.querySelectorAll('[data-page-id]').forEach(pbtn => {
    pbtn.addEventListener('click', () => {
      angrySelectedPageId = parseInt(pbtn.dataset.pageId, 10);
      renderAngryPageList();
      loadAngrySelectedPage();
    });
  });
}

function loadAngrySelectedPage() {
  const te = tabExportFor(angryKind);
  const single = document.getElementById('angrySinglePage').checked;
  let page = single ? te.pages[0] : te.pages.find(p => p.id === angrySelectedPageId);
  if (single && !page) {
    call({ action: 'add_export_page', templateId: TEMPLATE_ID, kind: angryKind, name: 'Export' }).then(() => {
      const te2 = tabExportFor(angryKind);
      angrySelectedPageId = te2.pages[0] ? te2.pages[0].id : null;
      loadAngrySelectedPage();
    });
    return;
  }
  angrySelectedPageId = page ? page.id : null;
  document.getElementById('angryPageName').value = page ? page.name : '';
  document.getElementById('angryPageTemplate').value = page ? (page.template || '') : '';
}

function openAngryEditor(k) {
  angryKind = k;
  const te = tabExportFor(k);
  angrySelectedPageId = te.pages.length ? te.pages[0].id : null;
  document.getElementById('angryModalTitle').textContent = 'AngryERA export — ' + tabLabel(k);
  document.getElementById('angrySinglePage').checked = te.singlePage;
  document.getElementById('angryExportName').value = te.exportName || tabLabel(k);
  document.getElementById('angryStatus').textContent = '';
  renderAngryTokens();
  renderAngryPageList();
  loadAngrySelectedPage();
  document.getElementById('angryModalBackdrop').classList.add('open');
}
function closeAngryEditor() {
  document.getElementById('angryModalBackdrop').classList.remove('open');
  angryKind = null;
}

function angryBuildJSON(k) {
  const te = tabExportFor(k);
  const secs = sections.filter(s => s.kind === k);
  const map = healerSlotMap(secs, (r, c) => c ? `${r.label} (${c.label})` : r.label);
  const resolve = tmpl => applyExportTemplate(tmpl, key => map[key.trim().toLowerCase()] ?? null);
  const name = te.exportName || tabLabel(k);
  if (te.singlePage) {
    const p = te.pages[0];
    return { content: resolve(p ? p.template : ''), name };
  }
  return { name, pages: te.pages.map(p => ({ name: p.name, content: resolve(p.template) })) };
}

function wireAngryEditor() {
  const backdrop = document.getElementById('angryModalBackdrop');
  document.getElementById('angryModalClose').addEventListener('click', closeAngryEditor);
  backdrop.addEventListener('click', e => { if (e.target === backdrop) closeAngryEditor(); });

  document.getElementById('angrySinglePage').addEventListener('change', e => {
    call({ action: 'set_tab_export_meta', templateId: TEMPLATE_ID, kind: angryKind, singlePage: e.target.checked, exportName: document.getElementById('angryExportName').value.trim() })
      .then(() => { renderAngryPageList(); loadAngrySelectedPage(); });
  });
  document.getElementById('angryExportName').addEventListener('change', e => {
    call({ action: 'set_tab_export_meta', templateId: TEMPLATE_ID, kind: angryKind, singlePage: document.getElementById('angrySinglePage').checked, exportName: e.target.value.trim() });
  });
  document.getElementById('angryPageName').addEventListener('change', e => {
    if (!angrySelectedPageId) return;
    call({ action: 'update_export_page', id: angrySelectedPageId, name: e.target.value.trim() }).then(() => renderAngryPageList());
  });
  document.getElementById('angryPageTemplate').addEventListener('change', e => {
    if (!angrySelectedPageId) return;
    call({ action: 'update_export_page', id: angrySelectedPageId, template: e.target.value });
  });
  document.getElementById('angryAddPageBtn').addEventListener('click', () => {
    call({ action: 'add_export_page', templateId: TEMPLATE_ID, kind: angryKind, name: 'New page' }).then(() => {
      const te = tabExportFor(angryKind);
      angrySelectedPageId = te.pages.length ? te.pages[te.pages.length - 1].id : null;
      renderAngryPageList();
      loadAngrySelectedPage();
    });
  });
  document.getElementById('angryDeletePageBtn').addEventListener('click', () => {
    if (!angrySelectedPageId) return;
    if (!confirm('Delete this page?')) return;
    call({ action: 'delete_export_page', id: angrySelectedPageId }).then(() => {
      const te = tabExportFor(angryKind);
      angrySelectedPageId = te.pages.length ? te.pages[0].id : null;
      renderAngryPageList();
      loadAngrySelectedPage();
    });
  });
  document.getElementById('angryExportJsonBtn').addEventListener('click', () => {
    const json = angryBuildJSON(angryKind);
    const statusEl = document.getElementById('angryStatus');
    navigator.clipboard.writeText(JSON.stringify(json, null, 2)).then(() => {
      statusEl.textContent = 'Copied!';
      setTimeout(() => { statusEl.textContent = ''; }, 2000);
    }).catch(() => { statusEl.textContent = 'Copy failed — select and copy manually.'; });
  });
}
wireAngryEditor();

document.addEventListener('click', e => {
  if (!openKindPicker || e.target.closest('.kind-picker-wrap')) return;
  openKindPicker = null;
  render();
});

// Stamp tool: while a cell-override kind is picked, every click on a grid cell inside
// the live editor (panelsEl) applies it instead of that cell's normal action, and stays
// active for further clicks (multi-select) until a right-click clears the stamp.
document.addEventListener('click', e => {
  if (!cellOverrideStamp) return;
  const td = e.target.closest('[data-row-id][data-col-id]');
  if (!td || !document.getElementById('panelsEl').contains(td)) return;
  e.preventDefault();
  e.stopPropagation();
  const rowId = parseInt(td.dataset.rowId, 10);
  const columnId = parseInt(td.dataset.colId, 10);
  call({ action: 'set_cell_kind_override', rowId, columnId, kindOverride: cellOverrideStamp });
}, true);

document.addEventListener('contextmenu', e => {
  if (!cellOverrideStamp) return;
  e.preventDefault();
  cellOverrideStamp = null;
  updateStampBadge();
});

document.addEventListener('contextmenu', e => {
  if (!paintArmed) return;
  e.preventDefault();
  paintArmed = false;
  paintDragging = false;
  paintDragTouched = null;
  document.body.classList.remove('paint-mode-active');
  renderPaintBar();
});

document.addEventListener('contextmenu', e => {
  if (!mergeArmed) return;
  e.preventDefault();
  mergeArmed = false;
  mergeDragging = false;
  if (mergeDragTds) mergeDragTds.forEach(td => td.classList.remove('merge-touched'));
  mergeDragTds = null;
  document.body.classList.remove('merge-mode-active');
  renderPaintBar();
});

document.addEventListener('mousemove', e => {
  const badge = document.getElementById('stampBadge');
  if (badge.hidden) return;
  badge.style.left = (e.clientX + 16) + 'px';
  badge.style.top = (e.clientY + 16) + 'px';
});

render();
checkLock();
</script>
</body>
</html>
