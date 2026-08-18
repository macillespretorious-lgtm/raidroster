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

function fetch_structure($pdo, $templateId) {
    $out = [];
    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmt2 = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmt2->execute([$sec['id']]);
        $tables = [];
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $tb) {
            $stmt3 = $pdo->prepare('SELECT id, label FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
            $stmt3->execute([$tb['id']]);
            $columns = array_map(fn($c) => ['id' => (int)$c['id'], 'label' => $c['label']], $stmt3->fetchAll(PDO::FETCH_ASSOC));
            $stmt4 = $pdo->prepare('SELECT id, label FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
            $stmt4->execute([$tb['id']]);
            $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label']], $stmt4->fetchAll(PDO::FETCH_ASSOC));
            $tables[] = ['id' => (int)$tb['id'], 'title' => $tb['title'], 'columns' => $columns, 'rows' => $rows];
        }
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
    .wrap { max-width: 900px; margin: 0 auto; padding: 32px 24px 110px; }
    .back { color: #7f8bad; font-size: 12px; text-decoration: none; }
    .back:hover { color: #a3adfa; }
    h1 { font-size: 20px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 13px; margin-bottom: 24px; }
    .tag { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; padding: 3px 9px; border-radius: 999px; font-weight: 700; background: rgba(255,255,255,0.08); color: #a8b4d0; }

    .section-card { border-radius: 10px; overflow: hidden; margin-bottom: 18px; border: 1px solid rgba(255,255,255,0.08); }
    .section-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; }
    .section-head .title-input { background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); color: #fff; font: inherit; font-size: 14px; font-weight: 700; padding: 5px 9px; border-radius: 6px; flex: 1; min-width: 0; }
    .section-body { background: #111827; padding: 14px 16px; display: flex; flex-direction: column; gap: 14px; }
    .icon-btn { background: rgba(255,255,255,0.12); border: none; color: #fff; width: 26px; height: 26px; border-radius: 6px; cursor: pointer; font-size: 13px; line-height: 1; flex-shrink: 0; }
    .icon-btn:hover { background: rgba(255,255,255,0.22); }
    .icon-btn.danger:hover { background: rgba(224,85,85,0.7); }

    .tbl-card { border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px; }
    .tbl-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
    .tbl-head input.tbl-title { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 13px; font-weight: 600; padding: 6px 9px; border-radius: 6px; flex: 1; min-width: 0; }
    .grid-scroll { overflow-x: auto; }
    table.grid { border-collapse: collapse; font-size: 12px; }
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 4px 6px; white-space: nowrap; }
    table.grid th { background: rgba(255,255,255,0.04); }
    .lbl-input { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 12px; padding: 4px 6px; border-radius: 5px; width: 90px; }
    .cell-actions { display: flex; gap: 3px; margin-top: 2px; }
    .cell-actions .icon-btn { width: 20px; height: 20px; font-size: 10px; }
    .add-row-btn { background: none; border: 1px dashed rgba(255,255,255,0.2); color: #a8b4d0; border-radius: 6px; padding: 5px 10px; font: inherit; font-size: 12px; cursor: pointer; }
    .add-row-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .tbl-actions-row { display: flex; gap: 8px; margin-top: 8px; }

    .add-table-form { display: flex; gap: 8px; }
    .add-table-form input { flex: 1; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 13px; padding: 8px 10px; border-radius: 6px; }
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
    <a class="back" href="/<?= h($slug) ?>/admin#raids">&larr; Back to admin</a>
    <h1><?= h($template['name']) ?></h1>
    <p class="sub"><span class="tag"><?= h($template['assignment_style']) ?></span> &middot; structure only &mdash; toon assignments happen per-raid</p>

    <div id="sectionsEl"></div>

    <div class="add-section-bar">
      <select id="newSectionKind"></select>
      <button class="btn" id="addSectionBtn">+ Add section</button>
    </div>
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

function call(payload) {
  return fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(d => { if (!d.success) throw new Error(d.error || 'Failed'); sections = d.sections; render(); return d; })
    .catch(e => alert(e.message));
}

function render() {
  const el = document.getElementById('sectionsEl');
  if (!sections.length) {
    el.innerHTML = '<p class="empty">No sections yet — add one below to start building this template.</p>';
  } else {
    el.innerHTML = sections.map(sec => renderSection(sec)).join('');
  }

  const kindSel = document.getElementById('newSectionKind');
  kindSel.innerHTML = ALLOWED_KINDS.map(k => `<option value="${k}">${KIND_META[k].label}</option>`).join('');

  el.querySelectorAll('[data-action]').forEach(node => {
    node.addEventListener(node.tagName === 'INPUT' ? 'change' : 'click', () => {
      const act = node.dataset.action;
      const id = node.dataset.id ? parseInt(node.dataset.id, 10) : null;
      if (act === 'rename-section') call({ action: 'update_section', id, title: node.value.trim() });
      if (act === 'delete-section') { if (confirm('Delete this section and everything in it?')) call({ action: 'delete_section', id }); }
      if (act === 'move-section-up') call({ action: 'move_section', id, direction: 'up' });
      if (act === 'move-section-down') call({ action: 'move_section', id, direction: 'down' });
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
    });
  });

  el.querySelectorAll('.add-table-form').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const input = form.querySelector('input');
      const title = input.value.trim();
      if (!title) return;
      call({ action: 'add_table', sectionId: parseInt(form.dataset.sectionId, 10), title }).then(() => { input.value = ''; });
    });
  });
}

function renderTable(tb) {
  const colHeaders = tb.columns.map(c => `<th>
      <input class="lbl-input" data-action="rename-col" data-id="${c.id}" value="${escAttr(c.label)}">
      <div class="cell-actions">
        <button class="icon-btn" data-action="move-col-left" data-id="${c.id}" title="Move left">&larr;</button>
        <button class="icon-btn" data-action="move-col-right" data-id="${c.id}" title="Move right">&rarr;</button>
        <button class="icon-btn danger" data-action="delete-col" data-id="${c.id}" title="Delete">&times;</button>
      </div>
    </th>`).join('');

  const bodyRows = tb.rows.map(r => {
    const rowCells = tb.columns.map(() => `<td></td>`).join('');
    return `<tr>
      <th style="text-align:left;">
        <input class="lbl-input" data-action="rename-row" data-id="${r.id}" value="${escAttr(r.label)}">
        <div class="cell-actions">
          <button class="icon-btn" data-action="move-row-up" data-id="${r.id}" title="Move up">&uarr;</button>
          <button class="icon-btn" data-action="move-row-down" data-id="${r.id}" title="Move down">&darr;</button>
          <button class="icon-btn danger" data-action="delete-row" data-id="${r.id}" title="Delete">&times;</button>
        </div>
      </th>
      ${rowCells}
    </tr>`;
  }).join('');

  return `<div class="tbl-card">
    <div class="tbl-head">
      <input class="tbl-title" data-action="rename-table" data-id="${tb.id}" value="${escAttr(tb.title)}">
      <button class="icon-btn" data-action="move-table-up" data-id="${tb.id}" title="Move up">&uarr;</button>
      <button class="icon-btn" data-action="move-table-down" data-id="${tb.id}" title="Move down">&darr;</button>
      <button class="icon-btn danger" data-action="delete-table" data-id="${tb.id}" title="Delete table">&times;</button>
    </div>
    <div class="grid-scroll">
      <table class="grid">
        <tr><th></th>${colHeaders}</tr>
        ${bodyRows || ''}
      </table>
    </div>
    <div class="tbl-actions-row">
      <button class="add-row-btn" data-action="add-col" data-id="${tb.id}">+ Column</button>
      <button class="add-row-btn" data-action="add-row" data-id="${tb.id}">+ Row</button>
    </div>
  </div>`;
}

function renderSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  return `<div class="section-card">
    <div class="section-head" style="background:${meta.color};">
      <input class="title-input" data-action="rename-section" data-id="${sec.id}" value="${escAttr(sec.title)}">
      <button class="icon-btn" data-action="move-section-up" data-id="${sec.id}" title="Move up">&uarr;</button>
      <button class="icon-btn" data-action="move-section-down" data-id="${sec.id}" title="Move down">&darr;</button>
      <button class="icon-btn danger" data-action="delete-section" data-id="${sec.id}" title="Delete section">&times;</button>
    </div>
    <div class="section-body">
      ${sec.tables.map(renderTable).join('') || '<p class="empty">No tables yet.</p>'}
      <form class="add-table-form" data-section-id="${sec.id}">
        <input type="text" placeholder="New table name, e.g. Spider Wing">
        <button class="btn" type="submit">+ Table</button>
      </form>
    </div>
  </div>`;
}

document.getElementById('addSectionBtn').addEventListener('click', () => {
  const kind = document.getElementById('newSectionKind').value;
  call({ action: 'add_section', templateId: TEMPLATE_ID, kind, title: KIND_META[kind].label });
});

render();
</script>
</body>
</html>
