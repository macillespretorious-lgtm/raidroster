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

$role = require_role($tenant, 'readonly');
$user = auth_user();
$pdo  = db_connect();

$raidId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM raids WHERE id = ? AND guild_id = ?');
$stmt->execute([$raidId, $tenant['id']]);
$raid = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$raid) {
    http_response_code(404);
    echo 'Raid not found.';
    exit;
}

$canManage = role_at_least($role, 'raid_management');

function fetch_raid_structure($pdo, $raidId) {
    $out = [];
    $stmt = $pdo->prepare('SELECT * FROM raid_sections WHERE raid_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmtT = $pdo->prepare('SELECT * FROM raid_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        $tables = [];
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $tb) {
            $stmtC = $pdo->prepare('SELECT id, label FROM raid_columns WHERE table_id = ? ORDER BY sort_order, id');
            $stmtC->execute([$tb['id']]);
            $columns = array_map(fn($c) => ['id' => (int)$c['id'], 'label' => $c['label']], $stmtC->fetchAll(PDO::FETCH_ASSOC));

            $stmtR = $pdo->prepare('SELECT id, label FROM raid_rows WHERE table_id = ? ORDER BY sort_order, id');
            $stmtR->execute([$tb['id']]);
            $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label']], $stmtR->fetchAll(PDO::FETCH_ASSOC));

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

            $tables[] = ['id' => (int)$tb['id'], 'title' => $tb['title'], 'columns' => $columns, 'rows' => $rows, 'cells' => $cells];
        }
        $out[] = ['id' => (int)$sec['id'], 'kind' => $sec['kind'], 'title' => $sec['title'], 'tables' => $tables];
    }
    return $out;
}
$sections = fetch_raid_structure($pdo, $raidId);

$roster = [];
if ($canManage) {
    $stmt = $pdo->prepare("SELECT id, main_name, class, status FROM toons WHERE guild_id = ? ORDER BY main_name");
    $stmt->execute([$tenant['id']]);
    $roster = array_map(fn($t) => ['id' => $t['id'], 'name' => $t['main_name'], 'class' => $t['class'], 'status' => $t['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function h($s) { return htmlspecialchars($s ?? ''); }
function fmtTime($t) {
    if (!$t) return '';
    [$hh, $mm] = explode(':', $t);
    $h12 = (((int)$hh + 11) % 12) + 1;
    return $h12 . ':' . $mm . ((int)$hh < 12 ? 'am' : 'pm');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($raid['name']) ?> &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif; color: #e8ecff; }
    .wrap { max-width: 980px; margin: 0 auto; padding: 32px 24px 110px; }
    .back { color: #7f8bad; font-size: 12px; text-decoration: none; }
    .back:hover { color: #a3adfa; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 13px; margin-bottom: 4px; }
    .status-cancelled { color: #e88585; font-weight: 700; }
    .readonly-note { color: #7f8bad; font-size: 12px; margin: 6px 0 20px; }

    .section-card { border-radius: 12px; overflow: hidden; margin: 22px 0; border: 1px solid rgba(255,255,255,0.08); }
    .section-head { display: flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .section-body { background: #111827; padding: 16px 18px; display: flex; flex-direction: column; gap: 18px; }
    .tbl-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #a8b4d0; margin-bottom: 8px; }
    .grid-scroll { overflow-x: auto; }
    table.grid { border-collapse: collapse; width: 100%; font-size: 12px; }
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 6px 8px; text-align: center; vertical-align: middle; }
    table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 700; white-space: nowrap; }
    table.grid th.row-label { text-align: left; white-space: nowrap; }
    td.cell { min-width: 100px; }

    .toon-chip {
      display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 3px 10px;
      font-size: 11px; font-weight: 700; color: #000; white-space: nowrap; cursor: default;
    }
    td.cell.editable .toon-chip, td.cell.editable .empty-slot { cursor: pointer; }
    .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .cell-note { display: block; font-size: 10px; color: #7f8bad; margin-top: 2px; }
    select.cell-picker { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.2); color: #e8ecff; font: inherit; font-size: 11px; padding: 3px 5px; border-radius: 5px; max-width: 130px; }

    .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <a class="back" href="/<?= h($slug) ?>/raids">&larr; Back to calendar</a>
    <h1><?= h($raid['name']) ?><?php if ($raid['status'] === 'cancelled'): ?> <span class="status-cancelled">(cancelled)</span><?php endif; ?></h1>
    <p class="sub"><?= h($raid['raid_date']) ?><?php if ($raid['start_time']): ?> &middot; <?= h(fmtTime($raid['start_time'])) ?><?php endif; ?></p>
    <?php if (!$sections): ?>
      <p class="empty">This raid has no roster/assignment structure (its template may not have one, or it was created without one).</p>
    <?php elseif (!$canManage): ?>
      <p class="readonly-note">You need raid management permission to edit assignments.</p>
    <?php endif; ?>

    <div id="sectionsEl"></div>
  </div>

<script>
const SLUG = <?= json_encode($slug) ?>;
const CAN_MANAGE = <?= json_encode($canManage) ?>;
const CELLS_SAVE_URL = <?= json_encode('/raids/cells-save.php?slug=' . $slug) ?>;
let sections = <?= json_encode($sections) ?>;
const roster = <?= json_encode($roster) ?>;

const KIND_META = {
  roster:      { label: 'Roster',             color: '#4a63e0', icon: '📋' },
  tank:        { label: 'Tank Assignments',   color: '#c94444', icon: '⚔️' },
  healer:      { label: 'Healer Assignments', color: '#3e9f5f', icon: '💚' },
  misc:        { label: 'Misc Assignments',   color: '#7c5cc4', icon: '📜' },
  assignments: { label: 'Assignments',        color: '#2fa89c', icon: '📌' },
};
const CLASS_COLORS = {
  warrior: '#c79c6e', paladin: '#f472b6', priest: '#eeeeee', druid: '#f59e0b',
  rogue: '#fff569', mage: '#69ccf0', warlock: '#9482c9', shaman: '#0070de', hunter: '#abd473',
};

function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function classColor(cls) { return CLASS_COLORS[(cls || '').toLowerCase()] || '#8892b0'; }

function chipHtml(cell) {
  if (!cell || !cell.toonId) return '<span class="empty-slot">+</span>';
  const color = classColor(cell.class);
  let html = `<span class="toon-chip" style="background:${color};">${esc(cell.name || cell.toonId)}</span>`;
  if (cell.note) html += `<span class="cell-note">${esc(cell.note)}</span>`;
  return html;
}

function rosterOptionsHtml(selectedId) {
  let html = '<option value="">— empty —</option>';
  html += roster.map(t => `<option value="${esc(t.id)}" ${t.id === selectedId ? 'selected' : ''}>${esc(t.name)} (${esc(t.class)})</option>`).join('');
  return html;
}

function render() {
  const el = document.getElementById('sectionsEl');
  el.innerHTML = sections.map(renderSection).join('');

  if (CAN_MANAGE) {
    el.querySelectorAll('td.cell.editable').forEach(td => {
      td.addEventListener('click', function onClick() {
        td.removeEventListener('click', onClick);
        const rowId = parseInt(td.dataset.rowId, 10);
        const colId = parseInt(td.dataset.colId, 10);
        const cellId = parseInt(td.dataset.cellId, 10);
        const tableId = parseInt(td.dataset.tableId, 10);
        const cur = findCell(tableId, rowId, colId);
        const sel = document.createElement('select');
        sel.className = 'cell-picker';
        sel.innerHTML = rosterOptionsHtml(cur ? cur.toonId : null);
        td.innerHTML = '';
        td.appendChild(sel);
        sel.focus();
        sel.addEventListener('change', () => {
          const toonId = sel.value || null;
          let note = cur ? cur.note : null;
          persistCell(cellId, toonId, note).then(() => { render(); });
        });
        sel.addEventListener('blur', () => { render(); });
      });
    });
    el.querySelectorAll('.note-btn').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const cellId = parseInt(btn.dataset.cellId, 10);
        const tableId = parseInt(btn.dataset.tableId, 10);
        const rowId = parseInt(btn.dataset.rowId, 10);
        const colId = parseInt(btn.dataset.colId, 10);
        const cur = findCell(tableId, rowId, colId);
        const note = prompt('Short note for this slot (optional):', (cur && cur.note) || '');
        if (note === null) return;
        persistCell(cellId, cur ? cur.toonId : null, note.trim() || null).then(() => render());
      });
    });
  }
}

function findCell(tableId, rowId, colId) {
  for (const sec of sections) for (const tb of sec.tables) if (tb.id === tableId) return tb.cells[rowId + '_' + colId] || null;
  return null;
}

function persistCell(cellId, toonId, note) {
  return fetch(CELLS_SAVE_URL, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cellId, toonId, note }),
  }).then(r => r.json()).then(d => {
    if (!d.success) { alert(d.error || 'Save failed'); return; }
    for (const sec of sections) for (const tb of sec.tables) {
      for (const key in tb.cells) if (tb.cells[key].id === cellId) { tb.cells[key] = d.cell; return; }
    }
  });
}

function renderTable(tb) {
  const colHeaders = tb.columns.map(c => `<th>${esc(c.label)}</th>`).join('');
  const bodyRows = tb.rows.map(r => {
    const rowCells = tb.columns.map(c => {
      const cell = tb.cells[r.id + '_' + c.id];
      const cellIdAttr = cell ? cell.id : '';
      const editableCls = CAN_MANAGE ? ' editable' : '';
      const noteBtn = CAN_MANAGE ? `<button type="button" class="note-btn" data-cell-id="${cellIdAttr}" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}" style="background:none;border:none;color:#55607a;cursor:pointer;font-size:9px;vertical-align:top;">✎</button>` : '';
      return `<td class="cell${editableCls}" data-cell-id="${cellIdAttr}" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}">${chipHtml(cell)}${noteBtn}</td>`;
    }).join('');
    return `<tr><th class="row-label">${esc(r.label)}</th>${rowCells}</tr>`;
  }).join('');

  return `<div>
    <div class="tbl-title">${esc(tb.title)}</div>
    <div class="grid-scroll">
      <table class="grid"><tr><th></th>${colHeaders}</tr>${bodyRows}</table>
    </div>
  </div>`;
}

function renderSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2', icon: '' };
  return `<div class="section-card">
    <div class="section-head" style="background:${meta.color};">${meta.icon} ${esc(sec.title)}</div>
    <div class="section-body">
      ${sec.tables.map(renderTable).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
}

render();
</script>
</body>
</html>
