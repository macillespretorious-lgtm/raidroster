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
    $stmt3 = $pdo->prepare('SELECT id, label, kind, width, header_color, group_id, header_colspan FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmt3->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmt3->fetchAll(PDO::FETCH_ASSOC));

    $stmt4 = $pdo->prepare('SELECT id, label, kind, height FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmt4->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'height' => $r['height'] !== null ? (int)$r['height'] : null], $stmt4->fetchAll(PDO::FETCH_ASSOC));

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

    $stmt6 = $pdo->prepare('SELECT row_id, column_id, colspan FROM raid_template_cell_merges WHERE table_id = ?');
    $stmt6->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'], 'columnId' => (int)$m['column_id'], 'colspan' => (int)$m['colspan'],
    ], $stmt6->fetchAll(PDO::FETCH_ASSOC));

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'rowLabelWidth' => $tb['row_label_width'] !== null ? (int)$tb['row_label_width'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups,
        'cellMerges' => $cellMerges,
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
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 4px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
    table.grid th { background: rgba(255,255,255,0.04); }
    .lbl-input { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 12px; padding: 4px 6px; border-radius: 5px; width: 100%; min-width: 0; box-sizing: border-box; }
    .cell-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 3px; margin-top: 2px; }
    .cell-actions .icon-btn { width: 20px; height: 20px; font-size: 10px; }
    td.data-td { position: relative; min-width: 24px; }
    .cell-merge-actions { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: 2px; opacity: 0; pointer-events: none; transition: opacity .15s; }
    .cell-merge-actions .icon-btn { width: 16px; height: 16px; font-size: 9px; padding: 0; pointer-events: auto; }
    td.data-td:hover .cell-merge-actions { opacity: 1; }
    .add-row-btn { background: none; border: 1px dashed rgba(255,255,255,0.2); color: #a8b4d0; border-radius: 6px; padding: 5px 10px; font: inherit; font-size: 12px; cursor: pointer; }
    .add-row-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .tbl-actions-row { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }

    input[type=color].swatch { -webkit-appearance: none; appearance: none; width: 24px; height: 24px; padding: 0; border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; background: none; cursor: pointer; flex-shrink: 0; }
    input[type=color].swatch::-webkit-color-swatch-wrapper { padding: 2px; }
    input[type=color].swatch::-webkit-color-swatch { border: none; border-radius: 3px; }
    .width-input { width: 52px; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff; font: inherit; font-size: 11px; padding: 4px 5px; border-radius: 5px; }
    .tbl-sizing { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #7f8bad; text-transform: uppercase; letter-spacing: .04em; }
    .col-th-inner { display: flex; flex-direction: column; gap: 3px; align-items: center; min-width: 0; }
    .row-th-inner { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .row-th-top { display: flex; align-items: center; justify-content: space-between; gap: 4px; min-width: 0; }
    .col-th-row2 { display: flex; gap: 3px; align-items: center; width: 100%; min-width: 0; }
    .col-th-row2 .swatch { flex-shrink: 0; }
    .col-th-row2 .width-input { flex: 1 1 0; min-width: 0; width: 0; }
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

    .lock-bar { margin: 8px 0 4px; }
    .lock-toggle { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: #a8b4d0; cursor: pointer; user-select: none; }
    .lock-toggle input { display: none; }
    .lock-switch { width: 34px; height: 19px; border-radius: 999px; background: rgba(255,255,255,0.15); position: relative; transition: background .15s; flex-shrink: 0; }
    .lock-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 15px; height: 15px; border-radius: 50%; background: #e8ecff; transition: transform .15s; }
    .lock-toggle input:checked + .lock-switch { background: #4caf6a; }
    .lock-toggle input:checked + .lock-switch::after { transform: translateX(15px); }
    .lock-banner { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 8px; background: rgba(240,128,48,0.1); border: 1px solid rgba(240,128,48,0.3); color: #f0a030; font-size: 12px; }
    .lock-banner button.btn { background: #e05555; padding: 5px 12px; font-size: 11px; }
    .lock-banner button.btn:hover { background: #c94444; }

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
    .preview-modal .section-card { border-radius: 12px; overflow: hidden; margin: 0; border: 1px solid rgba(255,255,255,0.08); }
    .preview-modal .section-head { display: flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .preview-modal .section-body { background: #111827; padding: 16px 18px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; }
    .preview-modal .tbl-wrap { min-width: 0; max-width: 100%; }
    .preview-modal .grid-scroll + .grid-scroll { margin-top: 2px; }
    .preview-modal .tbl-title { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #a8b4d0; background: rgba(255,255,255,0.04); padding: 8px 14px; border-radius: 6px 6px 0 0; }
    .preview-modal .group-tables { display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; margin: 8px 0 4px 4px; padding-left: 10px; border-left: 3px solid rgba(255,255,255,0.15); }
    .preview-modal .grid-scroll { overflow-x: auto; }
    .preview-modal table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    .preview-modal table.grid th, .preview-modal table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    .preview-modal table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 800; white-space: nowrap; }
    .preview-modal table.grid th.row-label { text-align: left; white-space: nowrap; }
    .preview-modal td.cell { min-width: 90px; }
    .preview-modal th.spacer-th, .preview-modal td.spacer-cell { background: none; border-color: transparent; padding: 8px 4px; }
    .preview-modal .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .preview-modal .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

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
    <div class="lock-bar" id="lockBar"></div>
    <p class="sub"><span class="tag"><?= h($template['assignment_style']) ?></span> &middot; structure only &mdash; toon assignments happen per-raid</p>

    <div class="tabs" id="tabsEl"></div>
    <div id="panelsEl"></div>
  </div>

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

<script>
const SLUG = <?= json_encode($slug) ?>;
const TEMPLATE_ID = <?= json_encode($templateId) ?>;
const STYLE = <?= json_encode($template['assignment_style']) ?>;
const SAVE_URL = <?= json_encode('/raids/template-structure-save.php?slug=' . $slug) ?>;
const USER_ID = <?= json_encode($user['id']) ?>;
let sections = <?= json_encode($sections) ?>;

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
function checkLock() {
  return lockCall('lock_status').then(d => {
    const holder = d.holder;
    if (holder && holder.discordUserId === USER_ID) {
      lockHeldByMe = true; lockedByOther = null;
      if (!lockHeartbeatTimer) startHeartbeat();
    } else if (holder) {
      lockedByOther = holder; lockHeldByMe = false;
    } else {
      lockedByOther = null; lockHeldByMe = false;
    }
    renderLockBar();
    render();
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
  } else {
    el.innerHTML = `<label class="lock-toggle">
      <input type="checkbox" id="lockToggle" ${lockHeldByMe ? 'checked' : ''}>
      <span class="lock-switch"></span>
      ${lockHeldByMe ? 'Editing (locked to you)' : 'Claim edit lock'}
    </label>`;
    document.getElementById('lockToggle').addEventListener('change', e => {
      if (e.target.checked) {
        lockCall('lock_acquire').then(d => {
          if (d.success) { lockHeldByMe = true; lockedByOther = null; startHeartbeat(); }
          else { lockedByOther = d.holder; lockHeldByMe = false; }
          renderLockBar(); render();
        });
      } else {
        lockCall('lock_release').then(() => { lockHeldByMe = false; stopHeartbeat(); renderLockBar(); render(); });
      }
    });
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

// Column widths are stored in px, but the width inputs show "units" instead so non-technical
// admins don't have to think in pixels — 1 unit = 60px, defaulting to 2 units (120px).
const COL_UNIT_PX = 60;
const DEFAULT_COL_UNITS = 2;
function pxToUnits(px) { return px ? Math.round(px / COL_UNIT_PX) : ''; }
function unitsToPx(units) { return units ? parseInt(units, 10) * COL_UNIT_PX : null; }

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
  // Always resolve to a real pixel width (never null) so every <col> in the colgroup is
  // explicit — table-layout:fixed only sums a colspan cell's width from its spanned <col>
  // widths correctly when all of them are explicit; an "auto" column breaks that sum and
  // lets merged headers/cells collapse toward a single column's width instead.
  return c.width || tb.defaultColumnWidth || (DEFAULT_COL_UNITS * COL_UNIT_PX);
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
function findRow(id) {
  for (const tb of allTables()) for (const r of tb.rows) if (r.id === id) return r;
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
      if (act === 'preview-section') openPreview(id);
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
      if (act === 'rename-col') call({ action: 'update_column', id, label: node.value.trim() });
      if (act === 'delete-col') call({ action: 'delete_column', id });
      if (act === 'rename-row') call({ action: 'update_row', id, label: node.value.trim() });
      if (act === 'delete-row') call({ action: 'delete_row', id });
      if (act === 'add-col') call({ action: 'add_column', tableId: id, label: '' });
      if (act === 'add-row') call({ action: 'add_row', tableId: id, label: '' });
      if (act === 'add-spacer-col') call({ action: 'add_column', tableId: id, kind: 'spacer', label: '' });
      if (act === 'add-spacer-row') call({ action: 'add_row', tableId: id, kind: 'spacer', label: '' });
      if (act === 'spacer-col-width-dec') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, width: Math.max(20, (c.width || 20) - 20) }); }
      if (act === 'spacer-col-width-inc') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, width: (c.width || 20) + 20 }); }
      if (act === 'spacer-row-height-dec') { const r = findRow(id); call({ action: 'update_row', id, label: r.label, height: Math.max(20, (r.height || 20) - 20) }); }
      if (act === 'spacer-row-height-inc') { const r = findRow(id); call({ action: 'update_row', id, label: r.label, height: (r.height || 20) + 20 }); }
      if (act === 'toggle-row-header') { const tb = findTable(id); const showing = tb.rowLabelWidth !== 0; call({ action: 'update_table', id, title: tb.title, rowLabelWidth: showing ? 0 : null }); }
      if (act === 'table-header-color') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, headerColor: node.value }); }
      if (act === 'table-col-width') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, defaultColumnWidth: unitsToPx(node.value) }); }
      if (act === 'table-label-width') { const tb = findTable(id); call({ action: 'update_table', id, title: tb.title, rowLabelWidth: unitsToPx(node.value) }); }
      if (act === 'col-header-color') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, headerColor: node.value }); }
      if (act === 'col-width') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, width: unitsToPx(node.value) }); }
      if (act === 'col-group') { const c = findColumn(id); call({ action: 'update_column', id, label: c.label, groupId: node.value ? parseInt(node.value, 10) : null }); }
      if (act === 'merge-header') call({ action: 'merge_header', id });
      if (act === 'split-header') call({ action: 'split_header', id });
      if (act === 'merge-cell') call({ action: 'merge_cell', rowId: parseInt(node.dataset.rowId, 10), columnId: parseInt(node.dataset.colId, 10) });
      if (act === 'split-cell') call({ action: 'split_cell', rowId: parseInt(node.dataset.rowId, 10), columnId: parseInt(node.dataset.colId, 10) });
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

  applyLockGate();
}

function colHeaderCell(c, tb, groupsEnabled, span) {
  const dragHandle = `<span class="drag-handle" draggable="true" data-drag-kind="column" data-drag-id="${c.id}" data-drag-parent="${tb.id}" title="Drag to reorder">&#10021;</span>`;
  const colspanAttr = span > 1 ? ` colspan="${span}"` : '';
  if (c.kind === 'spacer') {
    return `<th class="spacer-th" data-drop-kind="column" data-drop-id="${c.id}" data-drop-parent="${tb.id}">
      <div class="col-th-inner">
        ${dragHandle}
        <span class="spacer-label">spacer</span>
        <div class="cell-actions">
          <button class="icon-btn" data-action="spacer-col-width-dec" data-id="${c.id}" title="Narrower">&minus;</button>
          <button class="icon-btn" data-action="spacer-col-width-inc" data-id="${c.id}" title="Wider">+</button>
        </div>
        <div class="cell-actions">
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
  const mergeBtn = `<button class="icon-btn" data-action="merge-header" data-id="${c.id}" title="Merge header with next column">&harr;</button>`;
  const splitBtn = c.headerColspan > 1
    ? `<button class="icon-btn" data-action="split-header" data-id="${c.id}" title="Unmerge header">&#8622;</button>`
    : '';
  return `<th${colspanAttr} data-drop-kind="column" data-drop-id="${c.id}" data-drop-parent="${tb.id}">
      <div class="col-th-inner">
        ${dragHandle}
        <input class="lbl-input" data-action="rename-col" data-id="${c.id}" placeholder="Label" value="${escAttr(c.label)}">
        <div class="col-th-row2">
          <input type="color" class="swatch" data-action="col-header-color" data-id="${c.id}" value="${c.headerColor || '#1a2338'}" title="Header color">
          <input type="number" class="width-input" data-action="col-width" data-id="${c.id}" value="${pxToUnits(c.width)}" placeholder="${DEFAULT_COL_UNITS}" min="1" title="Width in units (1 unit = ${COL_UNIT_PX}px)">
        </div>
        ${groupSelect}
        <div class="cell-actions">
          ${mergeBtn}
          ${splitBtn}
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

function renderRowHeader(r, tb, showRowHeader) {
  const dragHandle = `<span class="drag-handle" draggable="true" data-drag-kind="row" data-drag-id="${r.id}" data-drag-parent="${tb.id}" title="Drag to reorder">&#10021;</span>`;
  const deleteBtn = `<button class="icon-btn danger" data-action="delete-row" data-id="${r.id}" title="Delete">&times;</button>`;
  if (r.kind === 'spacer') {
    return `<th class="spacer-th" data-drop-kind="row" data-drop-id="${r.id}" data-drop-parent="${tb.id}">
      <div class="row-th-inner">
        <div class="row-th-top">${dragHandle}${deleteBtn}</div>
        <span class="spacer-label">spacer</span>
        <div class="cell-actions">
          <button class="icon-btn" data-action="spacer-row-height-dec" data-id="${r.id}" title="Shorter">&minus;</button>
          <button class="icon-btn" data-action="spacer-row-height-inc" data-id="${r.id}" title="Taller">+</button>
        </div>
      </div>
    </th>`;
  }
  const labelInput = showRowHeader
    ? `<input class="lbl-input" data-action="rename-row" data-id="${r.id}" placeholder="Label" value="${escAttr(r.label)}">`
    : '';
  return `<th data-drop-kind="row" data-drop-id="${r.id}" data-drop-parent="${tb.id}">
    <div class="row-th-inner">
      <div class="row-th-top">${dragHandle}${deleteBtn}</div>
      ${labelInput}
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
function bodyCellsForRow(r, chunkCols, tb) {
  const mergeByCol = {};
  tb.cellMerges.forEach(m => { if (m.rowId === r.id) mergeByCol[m.columnId] = m.colspan; });
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (c.kind === 'spacer') { out.push(`<td class="spacer-cell"></td>`); i++; continue; }
    const span = Math.min(mergeByCol[c.id] || 1, chunkCols.length - i);
    const colspanAttr = span > 1 ? ` colspan="${span}"` : '';
    const splitBtn = span > 1
      ? `<button class="icon-btn" data-action="split-cell" data-row-id="${r.id}" data-col-id="${c.id}" title="Unmerge cell">&#8622;</button>`
      : '';
    out.push(`<td${colspanAttr} class="data-td">
      <div class="cell-merge-actions">
        <button class="icon-btn" data-action="merge-cell" data-row-id="${r.id}" data-col-id="${c.id}" title="Merge with next column">&harr;</button>
        ${splitBtn}
      </div>
    </td>`);
    i += span;
  }
  return out.join('');
}

function renderColumnBlock(chunkCols, tb, groupsEnabled) {
  const showRowHeader = tb.rowLabelWidth !== 0;
  const colHeaders = headerCellsForChunk(chunkCols, tb, groupsEnabled);
  const groupRow = groupsEnabled ? groupHeaderRow(chunkCols, tb.columnGroups) : '';

  // 84px keeps this collapsed to a slim rail while still fitting the row-action buttons
  // (3 x 20px + gaps) on one line — narrower and they wrap onto stacked rows, which forces
  // the whole body row taller since a <tr>'s height is driven by its tallest cell.
  const rowHeaderColWidth = showRowHeader ? (tb.rowLabelWidth || 110) : 84;
  const colgroup = `<colgroup><col style="width:${rowHeaderColWidth}px;">` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    const rowHeader = renderRowHeader(r, tb, showRowHeader);
    if (r.kind === 'spacer') {
      const spacerCells = chunkCols.map(() => `<td class="spacer-cell"></td>`).join('');
      return `<tr style="height:${r.height || 20}px;">${rowHeader}${spacerCells}</tr>`;
    }
    return `<tr>${rowHeader}${bodyCellsForRow(r, chunkCols, tb)}</tr>`;
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
      <div class="tbl-sizing">Col w<input type="number" class="width-input" data-action="table-col-width" data-id="${tb.id}" value="${pxToUnits(tb.defaultColumnWidth)}" placeholder="${DEFAULT_COL_UNITS}" min="1" title="Default column width in units (1 unit = ${COL_UNIT_PX}px)"></div>
      <div class="tbl-sizing">Label w<input type="number" class="width-input" data-action="table-label-width" data-id="${tb.id}" value="${pxToUnits(tb.rowLabelWidth)}" placeholder="auto" min="0" title="Width in units (1 unit = ${COL_UNIT_PX}px)"></div>
      <button class="icon-btn danger" data-action="delete-table" data-id="${tb.id}" title="Delete table">&times;</button>
    </div>
    ${groupsEnabled ? groupStrip(tb) : ''}
    ${blocks}
    ${!isContainerOnly ? `<div class="tbl-actions-row">
      <button class="add-row-btn" data-action="add-col" data-id="${tb.id}">+ Column</button>
      <button class="add-row-btn" data-action="add-row" data-id="${tb.id}">+ Row</button>
      <button class="add-row-btn" data-action="add-spacer-col" data-id="${tb.id}">+ Spacer column</button>
      <button class="add-row-btn" data-action="add-spacer-row" data-id="${tb.id}">+ Spacer row</button>
      <button class="add-row-btn" data-action="toggle-row-header" data-id="${tb.id}">${tb.rowLabelWidth !== 0 ? '− Row header column' : '+ Row header column'}</button>
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
      <button class="icon-btn" data-action="preview-section" data-id="${sec.id}" title="Preview as it will look on a raid page">&#128065;</button>
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

// Preview modal: a read-only render of a section as it will actually look on a raid
// page. Mirrors raids/view.php's rendering (same chunking/width helpers, same column-
// group header row), but every data cell always shows the empty-slot placeholder since
// no toon assignments exist at the template level, and the row-label column is always
// shown (matching view.php's current behavior, which doesn't honor rowLabelWidth=0).
function previewHeaderCellsForChunk(chunkCols) {
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (c.kind === 'spacer') { out.push(`<th class="spacer-th"></th>`); i++; continue; }
    const span = Math.min(c.headerColspan || 1, chunkCols.length - i);
    const colspanAttr = span > 1 ? ` colspan="${span}"` : '';
    const style = c.headerColor ? ` style="background:${c.headerColor};color:${contrastText(c.headerColor)};"` : '';
    out.push(`<th${colspanAttr}${style}>${esc(c.label)}</th>`);
    i += span;
  }
  return out.join('');
}

function previewBodyCellsForRow(r, chunkCols, tb) {
  const mergeByCol = {};
  tb.cellMerges.forEach(m => { if (m.rowId === r.id) mergeByCol[m.columnId] = m.colspan; });
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (c.kind === 'spacer') { out.push(`<td class="spacer-cell"></td>`); i++; continue; }
    const span = Math.min(mergeByCol[c.id] || 1, chunkCols.length - i);
    const colspanAttr = span > 1 ? ` colspan="${span}"` : '';
    out.push(`<td${colspanAttr} class="cell"><span class="empty-slot">+</span></td>`);
    i += span;
  }
  return out.join('');
}

function previewColumnBlock(chunkCols, tb) {
  const colHeaders = previewHeaderCellsForChunk(chunkCols);
  const groupRow = groupHeaderRow(chunkCols, tb.columnGroups);

  const colgroup = `<colgroup><col style="width:${tb.rowLabelWidth || 110}px;">` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell" colspan="${chunkCols.length + 1}"></td></tr>`;
    }
    return `<tr><th class="row-label">${esc(r.label)}</th>${previewBodyCellsForRow(r, chunkCols, tb)}</tr>`;
  }).join('');

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${groupRow}
        <tr>${groupRow ? '' : '<th></th>'}${colHeaders}</tr>
        ${bodyRows}
      </table>
    </div>`;
}

function previewRenderTable(tb, groupsEnabled) {
  const groupsWithTables = groupsEnabled ? tb.columnGroups.filter(g => g.tables.length > 0) : [];
  const isContainerOnly = tb.columns.length === 0 && groupsWithTables.length > 0;
  const blocks = isContainerOnly ? '' : chunkColumns(tb.columns).map(chunkCols => previewColumnBlock(chunkCols, tb)).join('');
  const titleStyle = tb.headerColor ? ` style="background:${tb.headerColor};color:${contrastText(tb.headerColor)};"` : '';

  const nestedGroupsHtml = groupsWithTables.map(g => `
    <div class="group-tables">
      ${g.tables.map(ctb => previewRenderTable(ctb, groupsEnabled)).join('')}
    </div>`).join('');

  return `<div class="tbl-wrap">
    ${tb.title ? `<div class="tbl-title"${titleStyle}>${esc(tb.title)}</div>` : ''}
    ${blocks}
    ${nestedGroupsHtml}
  </div>`;
}

function renderPreviewSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2' };
  const groupsEnabled = sec.kind !== 'roster';
  return `<div class="section-card">
    <div class="section-head" style="background:${meta.color};">${esc(sec.title)}</div>
    <div class="section-body">
      ${sec.tables.map(tb => previewRenderTable(tb, groupsEnabled)).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
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

render();
checkLock();
</script>
</body>
</html>
