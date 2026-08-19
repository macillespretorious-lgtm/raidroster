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
    $stmt3 = $pdo->prepare('SELECT id, label, kind, width, header_color, group_id FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmt3->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
    ], $stmt3->fetchAll(PDO::FETCH_ASSOC));

    $stmt4 = $pdo->prepare('SELECT id, label, kind FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmt4->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind']], $stmt4->fetchAll(PDO::FETCH_ASSOC));

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

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'rowLabelWidth' => $tb['row_label_width'] !== null ? (int)$tb['row_label_width'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups,
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
        $out[] = ['id' => (int)$sec['id'], 'kind' => $sec['kind'], 'title' => $sec['title'], 'tables' => $tables];
    }
    return $out;
}
$sections = fetch_structure($pdo, $templateId);

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
    h1 { font-size: 20px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 13px; margin-bottom: 24px; }
    .tag { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; padding: 3px 9px; border-radius: 999px; font-weight: 700; background: rgba(255,255,255,0.08); color: #a8b4d0; }

    .tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .tab-btn { background: none; border: none; font: inherit; cursor: pointer; color: #7f8bad; font-size: 13px; font-weight: 600; padding: 10px 16px; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .tab-btn:hover { color: #c7cef2; }
    .tab-btn.active { color: #e8ecff; border-bottom-color: #5865f2; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    .section-card { border-radius: 10px; overflow: hidden; margin-bottom: 18px; border: 1px solid rgba(255,255,255,0.08); }
    .section-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; }
    .section-head .title-input { background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); color: #fff; font: inherit; font-size: 14px; font-weight: 700; padding: 5px 9px; border-radius: 6px; flex: 1; min-width: 0; }
    .section-body { background: #111827; padding: 14px 16px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 14px; }
    .icon-btn { background: rgba(255,255,255,0.12); border: none; color: #fff; width: 26px; height: 26px; border-radius: 6px; cursor: pointer; font-size: 13px; line-height: 1; flex-shrink: 0; }
    .icon-btn:hover { background: rgba(255,255,255,0.22); }
    .icon-btn.danger:hover { background: rgba(224,85,85,0.7); }

    .tbl-card { border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px; min-width: 0; max-width: 100%; }
    .tbl-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
    .tbl-head input.tbl-title { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 13px; font-weight: 600; padding: 6px 9px; border-radius: 6px; flex: 1; min-width: 0; }
    .grid-scroll { overflow-x: auto; }
    .grid-scroll + .grid-scroll { margin-top: 2px; }
    table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12px; }
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 4px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    table.grid th { background: rgba(255,255,255,0.04); }
    .lbl-input { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 12px; padding: 4px 6px; border-radius: 5px; width: 90px; }
    .cell-actions { display: flex; gap: 3px; margin-top: 2px; }
    .cell-actions .icon-btn { width: 20px; height: 20px; font-size: 10px; }
    .add-row-btn { background: none; border: 1px dashed rgba(255,255,255,0.2); color: #a8b4d0; border-radius: 6px; padding: 5px 10px; font: inherit; font-size: 12px; cursor: pointer; }
    .add-row-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .tbl-actions-row { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }

    input[type=color].swatch { -webkit-appearance: none; appearance: none; width: 24px; height: 24px; padding: 0; border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; background: none; cursor: pointer; flex-shrink: 0; }
    input[type=color].swatch::-webkit-color-swatch-wrapper { padding: 2px; }
    input[type=color].swatch::-webkit-color-swatch { border: none; border-radius: 3px; }
    .width-input { width: 52px; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 11px; padding: 4px 5px; border-radius: 5px; }
    .tbl-sizing { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #7f8bad; text-transform: uppercase; letter-spacing: .04em; }
    .col-th-inner { display: flex; flex-direction: column; gap: 3px; align-items: center; }
    .col-th-row2 { display: flex; gap: 3px; align-items: center; }
    .group-strip { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; align-items: center; }
    .group-pill { display: flex; align-items: center; gap: 5px; padding: 3px 4px 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .group-pill input.group-title { background: transparent; border: none; color: inherit; font: inherit; font-weight: 700; width: auto; max-width: 110px; }
    .group-pill .icon-btn { width: 18px; height: 18px; font-size: 10px; background: rgba(0,0,0,0.25); }
    .add-group-btn { background: none; border: 1px dashed rgba(255,255,255,0.25); color: #a8b4d0; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 11px; cursor: pointer; }
    .add-group-btn:hover { border-color: rgba(255,255,255,0.5); color: #e8ecff; }
    .group-tables { display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 14px; margin: 6px 0 12px 4px; padding-left: 10px; border-left: 3px solid rgba(255,255,255,0.15); }
    td.spacer-cell, th.spacer-th { background: repeating-linear-gradient(135deg, rgba(255,255,255,0.03) 0 6px, transparent 6px 12px); border-style: dashed; }
    .spacer-label { font-size: 9px; color: #55618a; text-transform: uppercase; letter-spacing: .05em; }

    .drag-handle { cursor: grab; display: inline-block; color: #6b7595; font-size: 13px; line-height: 1; user-select: none; }
    .drag-handle:active { cursor: grabbing; }
    [data-drop-kind].drag-over { outline: 2px dashed #5865f2; outline-offset: -2px; }

    button.btn { display: inline-block; padding: 7px 16px; font: inherit; background: #5865f2; border: none; border-radius: 999px; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    button.btn:hover { background: #4752c4; }

    .add-section-bar { display: flex; gap: 8px; align-items: center; margin-top: 6px; }
    .add-section-bar select { background: #111827; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 13px; padding: 8px 10px; border-radius: 6px; }
    .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <a class="back" href="/<?= h($slug) ?>/design">&larr; Back to templates</a>
    <h1><?= h($template['name']) ?></h1>
    <p class="sub"><span class="tag"><?= h($template['assignment_style']) ?></span> &middot; structure only &mdash; toon assignments happen per-raid</p>

    <div class="tabs" id="tabsEl"></div>
    <div id="panelsEl"></div>
  </div>

<script>
const SLUG = <?= json_encode($slug) ?>;
const TEMPLATE_ID = <?= json_encode($templateId) ?>;
const STYLE = <?= json_encode($template['assignment_style']) ?>;
const SAVE_URL = <?= json_encode('/raids/template-structure-save.php?slug=' . $slug) ?>;
let sections = <?= json_encode($sections) ?>;

const KIND_META = {
  roster:      { label: 'Roster',            color: '#5865f2' },
  tank:        { label: 'Tank Assignments',  color: '#e05555' },
  healer:      { label: 'Healer Assignments',color: '#4caf6a' },
  misc:        { label: 'Misc Assignments',  color: '#9482c9' },
  assignments: { label: 'Assignments',       color: '#4ecdc4' },
};
const ALLOWED_KINDS = STYLE === 'combined' ? ['roster', 'assignments'] : ['roster', 'tank', 'healer', 'misc'];

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

function chunkColumns(columns) {
  const chunks = [];
  let current = [];
  let dataCount = 0;
  for (const c of columns) {
    if (c.kind === 'data' && dataCount >= MAX_DATA_COLS) {
      chunks.push(current);
      current = [];
      dataCount = 0;
    }
    current.push(c);
    if (c.kind === 'data') dataCount++;
  }
  chunks.push(current);
  return chunks;
}

function colWidthPx(c, tb) {
  if (c.kind === 'spacer') {
    const base = c.width || tb.defaultColumnWidth || 30;
    return Math.max(8, Math.round(base / 3));
  }
  return c.width || tb.defaultColumnWidth || null;
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
function findGroup(id) {
  for (const tb of allTables()) for (const g of tb.columnGroups) if (g.id === id) return g;
  return null;
}

let dragData = null;

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

function reorderList(kind, parentKind, parentId, movedId, targetId, before) {
  const ids = getSiblingList(kind, parentKind, parentId).map(x => x.id).filter(id => id !== movedId);
  let idx = ids.indexOf(targetId);
  if (idx === -1) idx = ids.length - 1;
  const insertAt = before ? idx : idx + 1;
  ids.splice(insertAt, 0, movedId);
  const payload = { action: 'reorder', kind, parentId, orderedIds: ids };
  if (kind === 'table') payload.parentKind = parentKind;
  call(payload);
}

function call(payload) {
  return fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(d => { if (!d.success) throw new Error(d.error || 'Failed'); sections = d.sections; render(); return d; })
    .catch(e => alert(e.message));
}

let activeTab = ALLOWED_KINDS.includes(location.hash.slice(1)) ? location.hash.slice(1) : ALLOWED_KINDS[0];

function render() {
  const tabsEl = document.getElementById('tabsEl');
  tabsEl.innerHTML = ALLOWED_KINDS.map(k => `<button type="button" class="tab-btn ${k === activeTab ? 'active' : ''}" data-tab="${k}">${KIND_META[k].label}</button>`).join('');
  tabsEl.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      activeTab = btn.dataset.tab;
      history.replaceState(null, '', '#' + activeTab);
      render();
    });
  });

  const el = document.getElementById('panelsEl');
  el.innerHTML = ALLOWED_KINDS.map(k => {
    const secs = sections.filter(s => s.kind === k);
    const body = secs.length
      ? secs.map(sec => renderSection(sec)).join('')
      : '<p class="empty">No section yet — add one below to start building this tab.</p>';
    return `<div class="tab-panel ${k === activeTab ? 'active' : ''}" data-panel="${k}">
      ${body}
      <div class="add-section-bar">
        <button class="btn" data-action="add-section-for-kind" data-kind="${k}">+ Add ${KIND_META[k].label} section</button>
      </div>
    </div>`;
  }).join('');

  el.querySelectorAll('[data-action]').forEach(node => {
    const evt = (node.tagName === 'INPUT' || node.tagName === 'SELECT') ? 'change' : 'click';
    node.addEventListener(evt, () => {
      const act = node.dataset.action;
      const id = node.dataset.id ? parseInt(node.dataset.id, 10) : null;
      if (act === 'rename-section') call({ action: 'update_section', id, title: node.value.trim() });
      if (act === 'delete-section') { if (confirm('Delete this section and everything in it?')) call({ action: 'delete_section', id }); }
      if (act === 'move-section-up') call({ action: 'move_section', id, direction: 'up' });
      if (act === 'move-section-down') call({ action: 'move_section', id, direction: 'down' });
      if (act === 'add-table-to-section') call({ action: 'add_table', sectionId: id });
      if (act === 'add-section-for-kind') { const k = node.dataset.kind; call({ action: 'add_section', templateId: TEMPLATE_ID, kind: k, title: KIND_META[k].label }); }
      if (act === 'add-table-to-group') {
        const title = prompt('Table name, e.g. a boss name:');
        if (title && title.trim()) call({ action: 'add_table', groupId: id, title: title.trim() });
      }
      if (act === 'rename-table') call({ action: 'update_table', id, title: node.value.trim() });
      if (act === 'delete-table') { if (confirm('Delete this table?')) call({ action: 'delete_table', id }); }
      if (act === 'move-table-up') call({ action: 'move_table', id, direction: 'up' });
      if (act === 'move-table-down') call({ action: 'move_table', id, direction: 'down' });
      if (act === 'rename-col') call({ action: 'update_column', id, label: node.value.trim() });
      if (act === 'delete-col') call({ action: 'delete_column', id });
      if (act === 'move-col-left') call({ action: 'move_column', id, direction: 'up' });
      if (act === 'move-col-right') call({ action: 'move_column', id, direction: 'down' });
      if (act === 'rename-row') call({ action: 'update_row', id, label: node.value.trim() });
      if (act === 'delete-row') call({ action: 'delete_row', id });
      if (act === 'move-row-up') call({ action: 'move_row', id, direction: 'up' });
      if (act === 'move-row-down') call({ action: 'move_row', id, direction: 'down' });
      if (act === 'add-col') {
        const label = prompt('Column label:');
        if (label && label.trim()) call({ action: 'add_column', tableId: id, label: label.trim() });
      }
      if (act === 'add-row') {
        const label = prompt('Row label:');
        if (label && label.trim()) call({ action: 'add_row', tableId: id, label: label.trim() });
      }
      if (act === 'add-spacer-col') call({ action: 'add_column', tableId: id, kind: 'spacer', label: '' });
      if (act === 'add-spacer-row') call({ action: 'add_row', tableId: id, kind: 'spacer', label: '' });
      if (act === 'table-header-color') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, headerColor: node.value }); }
      if (act === 'table-col-width') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, defaultColumnWidth: node.value ? parseInt(node.value, 10) : null }); }
      if (act === 'table-label-width') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, rowLabelWidth: node.value ? parseInt(node.value, 10) : null }); }
      if (act === 'col-header-color') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, headerColor: node.value }); }
      if (act === 'col-width') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, width: node.value ? parseInt(node.value, 10) : null }); }
      if (act === 'col-group') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, groupId: node.value ? parseInt(node.value, 10) : null }); }
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
      dragData = {
        kind: handle.dataset.dragKind,
        id: parseInt(handle.dataset.dragId, 10),
        parentId: parseInt(handle.dataset.dragParent, 10),
        parentKind: handle.dataset.dragParentKind || 'table',
      };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(dragData.id));
    });
  });

  el.querySelectorAll('[data-drop-kind]').forEach(zone => {
    const dropKind = zone.dataset.dropKind;
    const dropId = parseInt(zone.dataset.dropId, 10);
    const dropParent = parseInt(zone.dataset.dropParent, 10);
    const dropParentKind = zone.dataset.dropParentKind || 'table';
    zone.addEventListener('dragover', e => {
      if (!dragData) return;
      const acceptsColumnOntoGroup = dropKind === 'group' && dragData.kind === 'column' && dragData.parentId === dropParent;
      const sameKind = dragData.kind === dropKind;
      if (!acceptsColumnOntoGroup && !sameKind) return;
      e.preventDefault();
      zone.classList.add('drag-over');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      if (!dragData) return;
      if (dragData.kind === 'column' && dropKind === 'group' && dragData.parentId === dropParent) {
        const c = findColumn(dragData.id);
        call({ action: 'update_column', id: dragData.id, label: c.label, groupId: dropId });
        dragData = null;
        return;
      }
      if (dragData.kind !== dropKind || dragData.id === dropId) { dragData = null; return; }
      const rect = zone.getBoundingClientRect();
      const before = dropKind === 'row'
        ? (e.clientY - rect.top) < rect.height / 2
        : (e.clientX - rect.left) < rect.width / 2;
      reorderList(dropKind, dropParentKind, dropParent, dragData.id, dropId, before);
      dragData = null;
    });
  });
}

function colHeaderCell(c, tb, groupsEnabled) {
  const dragHandle = `<span class="drag-handle" draggable="true" data-drag-kind="column" data-drag-id="${c.id}" data-drag-parent="${tb.id}" title="Drag to reorder">&#10021;</span>`;
  if (c.kind === 'spacer') {
    return `<th class="spacer-th" data-drop-kind="column" data-drop-id="${c.id}" data-drop-parent="${tb.id}">
      <div class="col-th-inner">
        ${dragHandle}
        <span class="spacer-label">spacer</span>
        <div class="cell-actions">
          <button class="icon-btn" data-action="move-col-left" data-id="${c.id}" title="Move left">&larr;</button>
          <button class="icon-btn" data-action="move-col-right" data-id="${c.id}" title="Move right">&rarr;</button>
          <button class="icon-btn danger" data-action="delete-col" data-id="${c.id}" title="Delete">&times;</button>
        </div>
      </div>
    </th>`;
  }
  const groupOptions = ['<option value="">— No group —</option>']
    .concat(tb.columnGroups.map(g => `<option value="${g.id}" ${c.groupId === g.id ? 'selected' : ''}>${escAttr(g.title)}</option>`));
  const groupSelect = groupsEnabled
    ? `<select class="width-input" style="width:100%;" data-action="col-group" data-id="${c.id}" title="Column group">${groupOptions.join('')}</select>`
    : '';
  return `<th data-drop-kind="column" data-drop-id="${c.id}" data-drop-parent="${tb.id}">
      <div class="col-th-inner">
        ${dragHandle}
        <input class="lbl-input" data-action="rename-col" data-id="${c.id}" value="${escAttr(c.label)}">
        <div class="col-th-row2">
          <input type="color" class="swatch" data-action="col-header-color" data-id="${c.id}" value="${c.headerColor || '#1a2338'}" title="Header color">
          <input type="number" class="width-input" data-action="col-width" data-id="${c.id}" value="${c.width || ''}" placeholder="w" min="0" title="Width (px)">
        </div>
        ${groupSelect}
        <div class="cell-actions">
          <button class="icon-btn" data-action="move-col-left" data-id="${c.id}" title="Move left">&larr;</button>
          <button class="icon-btn" data-action="move-col-right" data-id="${c.id}" title="Move right">&rarr;</button>
          <button class="icon-btn danger" data-action="delete-col" data-id="${c.id}" title="Delete">&times;</button>
        </div>
      </div>
    </th>`;
}

function groupHeaderRow(cols, columnGroups) {
  if (!columnGroups.length) return '';
  const cells = [`<th rowspan="2"></th>`];
  let i = 0;
  while (i < cols.length) {
    const gid = cols[i].groupId;
    if (!gid) { cells.push(`<th rowspan="2"></th>`); i++; continue; }
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
    <div class="group-pill" data-drop-kind="group" data-drop-id="${g.id}" data-drop-parent="${tb.id}" style="background:${g.color};color:${contrastText(g.color)};">
      <span class="drag-handle" draggable="true" data-drag-kind="group" data-drag-id="${g.id}" data-drag-parent="${tb.id}" title="Drag to reorder or move to another table, or drop a column here to assign it" style="color:inherit;opacity:.75;">&#10021;</span>
      <input class="group-title" data-action="rename-group" data-id="${g.id}" value="${escAttr(g.title)}">
      <input type="color" class="swatch" data-action="recolor-group" data-id="${g.id}" value="${g.color}" title="Group color">
      <button class="icon-btn" data-action="add-table-to-group" data-id="${g.id}" title="Add a table to this group">+T</button>
      <button class="icon-btn danger" data-action="delete-group" data-id="${g.id}" title="Delete group">&times;</button>
    </div>`).join('');
  return `<div class="group-strip">${pills}<button class="add-group-btn" data-action="add-group" data-id="${tb.id}">+ Group header</button></div>`;
}

function renderRowHeader(r, tb) {
  const dragHandle = `<span class="drag-handle" draggable="true" data-drag-kind="row" data-drag-id="${r.id}" data-drag-parent="${tb.id}" title="Drag to reorder">&#10021;</span>`;
  if (r.kind === 'spacer') {
    return `<th class="spacer-th" style="text-align:left;" data-drop-kind="row" data-drop-id="${r.id}" data-drop-parent="${tb.id}">
      ${dragHandle}
      <span class="spacer-label">spacer</span>
      <div class="cell-actions">
        <button class="icon-btn" data-action="move-row-up" data-id="${r.id}" title="Move up">&uarr;</button>
        <button class="icon-btn" data-action="move-row-down" data-id="${r.id}" title="Move down">&darr;</button>
        <button class="icon-btn danger" data-action="delete-row" data-id="${r.id}" title="Delete">&times;</button>
      </div>
    </th>`;
  }
  return `<th style="text-align:left;" data-drop-kind="row" data-drop-id="${r.id}" data-drop-parent="${tb.id}">
    ${dragHandle}
    <input class="lbl-input" data-action="rename-row" data-id="${r.id}" value="${escAttr(r.label)}">
    <div class="cell-actions">
      <button class="icon-btn" data-action="move-row-up" data-id="${r.id}" title="Move up">&uarr;</button>
      <button class="icon-btn" data-action="move-row-down" data-id="${r.id}" title="Move down">&darr;</button>
      <button class="icon-btn danger" data-action="delete-row" data-id="${r.id}" title="Delete">&times;</button>
    </div>
  </th>`;
}

function renderColumnBlock(chunkCols, tb, groupsEnabled) {
  const colHeaders = chunkCols.map(c => colHeaderCell(c, tb, groupsEnabled)).join('');
  const groupRow = groupsEnabled ? groupHeaderRow(chunkCols, tb.columnGroups) : '';

  const colgroup = `<colgroup><col style="width:${tb.rowLabelWidth || 110}px;">` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    const rowHeader = renderRowHeader(r, tb);
    if (r.kind === 'spacer') {
      const spacerCells = chunkCols.map(() => `<td class="spacer-cell"></td>`).join('');
      return `<tr>${rowHeader}${spacerCells}</tr>`;
    }
    const rowCells = chunkCols.map(c => c.kind === 'spacer' ? `<td class="spacer-cell"></td>` : `<td></td>`).join('');
    return `<tr>${rowHeader}${rowCells}</tr>`;
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
function renderTable(tb, parentKind, parentId, groupsEnabled) {
  const groupsWithTables = groupsEnabled ? tb.columnGroups.filter(g => g.tables.length > 0) : [];
  const isContainerOnly = tb.columns.length === 0 && groupsWithTables.length > 0;

  const blocks = isContainerOnly ? '' : chunkColumns(tb.columns).map(chunkCols => renderColumnBlock(chunkCols, tb, groupsEnabled)).join('');

  const headerBg = tb.headerColor || '';
  const headerStyle = headerBg ? `background:${headerBg};` : '';
  const titleColor = headerBg ? contrastText(headerBg) : '#e8ecff';

  const titleHtml = `<input class="tbl-title" data-action="rename-table" data-id="${tb.id}" placeholder="Table name (optional)" value="${escAttr(tb.title)}" style="color:${titleColor};">`;

  const nestedGroupsHtml = groupsWithTables.map(g => `
    <div class="group-tables">
      ${g.tables.map(ctb => renderTable(ctb, 'group', g.id, groupsEnabled)).join('')}
    </div>`).join('');

  return `<div class="tbl-card" data-drop-kind="table" data-drop-id="${tb.id}" data-drop-parent="${parentId}" data-drop-parent-kind="${parentKind}">
    <div class="tbl-head" style="${headerStyle}">
      <span class="drag-handle" draggable="true" data-drag-kind="table" data-drag-id="${tb.id}" data-drag-parent="${parentId}" data-drag-parent-kind="${parentKind}" title="Drag to reorder/reposition" style="color:${titleColor};opacity:.75;">&#10021;</span>
      ${titleHtml}
      <input type="color" class="swatch" data-action="table-header-color" data-id="${tb.id}" value="${tb.headerColor || '#1a2338'}" title="Table header bar color">
      <div class="tbl-sizing">Col w<input type="number" class="width-input" data-action="table-col-width" data-id="${tb.id}" value="${tb.defaultColumnWidth || ''}" placeholder="auto" min="0"></div>
      <div class="tbl-sizing">Label w<input type="number" class="width-input" data-action="table-label-width" data-id="${tb.id}" value="${tb.rowLabelWidth || ''}" placeholder="auto" min="0"></div>
      <button class="icon-btn" data-action="move-table-up" data-id="${tb.id}" title="Move up">&uarr;</button>
      <button class="icon-btn" data-action="move-table-down" data-id="${tb.id}" title="Move down">&darr;</button>
      <button class="icon-btn danger" data-action="delete-table" data-id="${tb.id}" title="Delete table">&times;</button>
    </div>
    ${groupsEnabled ? groupStrip(tb) : ''}
    ${blocks}
    ${!isContainerOnly ? `<div class="tbl-actions-row">
      <button class="add-row-btn" data-action="add-col" data-id="${tb.id}">+ Column</button>
      <button class="add-row-btn" data-action="add-row" data-id="${tb.id}">+ Row</button>
      <button class="add-row-btn" data-action="add-spacer-col" data-id="${tb.id}">+ Spacer column</button>
      <button class="add-row-btn" data-action="add-spacer-row" data-id="${tb.id}">+ Spacer row</button>
    </div>` : ''}
    ${nestedGroupsHtml}
  </div>`;
}

function renderSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  const groupsEnabled = sec.kind !== 'roster';
  return `<div class="section-card">
    <div class="section-head" style="background:${meta.color};">
      <input class="title-input" data-action="rename-section" data-id="${sec.id}" value="${escAttr(sec.title)}">
      <button class="icon-btn" data-action="move-section-up" data-id="${sec.id}" title="Move up">&uarr;</button>
      <button class="icon-btn" data-action="move-section-down" data-id="${sec.id}" title="Move down">&darr;</button>
      <button class="icon-btn danger" data-action="delete-section" data-id="${sec.id}" title="Delete section">&times;</button>
    </div>
    <div class="section-body">
      ${sec.tables.map(tb => renderTable(tb, 'section', sec.id, groupsEnabled)).join('') || '<p class="empty">No tables yet.</p>'}
      <button class="btn" data-action="add-table-to-section" data-id="${sec.id}">+ Table</button>
    </div>
  </div>`;
}

render();
</script>
</body>
</html>
