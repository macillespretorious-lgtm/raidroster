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
$isAdmin = role_at_least($role, 'admin');
$templateId = $raid['template_id'] !== null ? (int)$raid['template_id'] : null;

function fetch_table_full($pdo, $tb) {
    $stmtC = $pdo->prepare('SELECT id, label, kind, width, header_color, group_id, header_colspan FROM raid_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmtC->fetchAll(PDO::FETCH_ASSOC));

    $stmtR = $pdo->prepare('SELECT id, label, kind, height FROM raid_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'height' => $r['height'] !== null ? (int)$r['height'] : null], $stmtR->fetchAll(PDO::FETCH_ASSOC));

    $stmtG = $pdo->prepare('SELECT * FROM raid_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmtG->execute([$tb['id']]);
    $groupRows = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $columnGroups = [];
    foreach ($groupRows as $g) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$g['id']]);
        $childTables = array_map(fn($ctb) => fetch_table_full($pdo, $ctb), $stmtGT->fetchAll(PDO::FETCH_ASSOC));
        $columnGroups[] = [
            'id' => (int)$g['id'],
            'parentGroupId' => $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null,
            'title' => $g['title'], 'color' => $g['color'],
            'tables' => $childTables,
        ];
    }

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

    $stmtM = $pdo->prepare('SELECT row_id, column_id, colspan FROM raid_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'], 'columnId' => (int)$m['column_id'], 'colspan' => (int)$m['colspan'],
    ], $stmtM->fetchAll(PDO::FETCH_ASSOC));

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'rowLabelWidth' => $tb['row_label_width'] !== null ? (int)$tb['row_label_width'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups, 'cells' => $cells,
        'cellMerges' => $cellMerges,
    ];
}

function fetch_raid_structure($pdo, $raidId) {
    $out = [];
    $stmt = $pdo->prepare('SELECT * FROM raid_sections WHERE raid_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmtT = $pdo->prepare('SELECT * FROM raid_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        $tables = array_map(fn($tb) => fetch_table_full($pdo, $tb), $stmtT->fetchAll(PDO::FETCH_ASSOC));
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
    .wrap { max-width: 100%; margin: 0; padding: 32px 32px 110px; }
    .back { color: #7f8bad; font-size: 12px; text-decoration: none; }
    .back:hover { color: #a3adfa; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 13px; margin-bottom: 4px; }
    .status-cancelled { color: #e88585; font-weight: 700; }
    .readonly-note { color: #7f8bad; font-size: 12px; margin: 6px 0 20px; }

    .section-card { border-radius: 12px; overflow: hidden; margin: 22px 0; border: 1px solid rgba(255,255,255,0.08); }
    .section-head { display: flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .section-body { background: #111827; padding: 16px 18px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; }
    .tbl-wrap { min-width: 0; max-width: 100%; }
    .tbl-wrap .grid-scroll + .grid-scroll { margin-top: 2px; }
    .tbl-title {
      font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
      color: #a8b4d0; background: rgba(255,255,255,0.04); padding: 8px 14px; border-radius: 6px 6px 0 0;
    }
    .group-tables { display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; margin: 8px 0 4px 4px; padding-left: 10px; border-left: 3px solid rgba(255,255,255,0.15); }
    .grid-scroll { overflow-x: auto; }
    table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 800; white-space: nowrap; }
    table.grid th.row-label { text-align: left; white-space: nowrap; }
    table.grid th.group-th { font-size: 13px; letter-spacing: .04em; }
    td.cell { min-width: 90px; }
    th.spacer-th, td.spacer-cell { background: none; border-color: transparent; padding: 8px 4px; }

    .toon-chip {
      display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 3px 10px;
      font-size: 11px; font-weight: 700; color: #000; white-space: nowrap; cursor: default;
    }
    td.cell.editable .toon-chip, td.cell.editable .empty-slot { cursor: pointer; }
    .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .cell-note { display: block; font-size: 10px; color: #7f8bad; margin-top: 2px; }
    select.cell-picker { background: #0a0f1e; border: 1px solid rgba(255,255,255,0.2); color: #e8ecff; font: inherit; font-size: 11px; padding: 3px 5px; border-radius: 5px; max-width: 130px; }

    .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

    .lock-bar { margin: 8px 0 4px; }
    .lock-toggle { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: #a8b4d0; cursor: pointer; user-select: none; }
    .lock-toggle input { display: none; }
    .lock-switch { width: 34px; height: 19px; border-radius: 999px; background: rgba(255,255,255,0.15); position: relative; transition: background .15s; flex-shrink: 0; }
    .lock-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 15px; height: 15px; border-radius: 50%; background: #e8ecff; transition: transform .15s; }
    .lock-toggle input:checked + .lock-switch { background: #4caf6a; }
    .lock-toggle input:checked + .lock-switch::after { transform: translateX(15px); }
    .lock-banner { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 8px; background: rgba(240,128,48,0.1); border: 1px solid rgba(240,128,48,0.3); color: #f0a030; font-size: 12px; }
    .lock-banner button.btn { background: #e05555; padding: 5px 12px; font-size: 11px; border: none; border-radius: 999px; color: #fff; font-weight: 600; cursor: pointer; }
    .lock-banner button.btn:hover { background: #c94444; }

    .sync-bar { margin: 8px 0 4px; }
    .btn-sync { background: rgba(88,101,242,0.15); border: 1px solid rgba(88,101,242,0.4); color: #b9c0ff; font-size: 12px; font-weight: 600; padding: 7px 14px; border-radius: 999px; cursor: pointer; }
    .btn-sync:hover:not(:disabled) { background: rgba(88,101,242,0.28); }
    .btn-sync:disabled { opacity: .4; cursor: not-allowed; }

    .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    .modal-backdrop.open { display: flex; }
    .modal.sync-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; }
    .modal.sync-modal h2 { font-size: 17px; margin-bottom: 14px; }
    .diff-group { margin: 14px 0; }
    .diff-group h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #7f8bad; margin-bottom: 6px; }
    .diff-list { list-style: none; font-size: 13px; }
    .diff-list li { padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .diff-added { color: #6fd58a; }
    .diff-removed { color: #e88585; }
    .diff-changed { color: #f0c04a; }
    .diff-changed .fields { color: #7f8bad; font-size: 11px; }
    .diff-empty { color: #7f8bad; font-size: 13px; padding: 10px 0; }
    .removal-warning { background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.35); color: #f0a0a0; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; margin: 14px 0; }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
    .modal-actions button { font-size: 13px; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
    .btn-cancel { background: rgba(255,255,255,0.08); color: #e8ecff; }
    .btn-cancel:hover { background: rgba(255,255,255,0.15); }
    .btn-confirm { background: #4a63e0; color: #fff; }
    .btn-confirm:hover { background: #3b52c4; }
    .btn-confirm.danger { background: #c94444; }
    .btn-confirm.danger:hover { background: #b03636; }
    .btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <a class="back" href="/<?= h($slug) ?>/raids">&larr; Back to calendar</a>
    <h1><?= h($raid['name']) ?><?php if ($raid['status'] === 'cancelled'): ?> <span class="status-cancelled">(cancelled)</span><?php endif; ?></h1>
    <?php if ($canManage): ?><div class="lock-bar" id="lockBar"></div><?php endif; ?>
    <?php if ($isAdmin && $templateId !== null): ?><div class="sync-bar" id="syncBar"></div><?php endif; ?>
    <p class="sub"><?= h($raid['raid_date']) ?><?php if ($raid['start_time']): ?> &middot; <?= h(fmtTime($raid['start_time'])) ?><?php endif; ?></p>
    <?php if (!$sections): ?>
      <p class="empty">This raid has no roster/assignment structure (its template may not have one, or it was created without one).</p>
    <?php elseif (!$canManage): ?>
      <p class="readonly-note">You need raid management permission to edit assignments.</p>
    <?php endif; ?>

    <div id="sectionsEl"></div>
  </div>

  <div class="modal-backdrop" id="syncModalBackdrop">
    <div class="modal sync-modal" id="syncModalContent"></div>
  </div>

<script>
const SLUG = <?= json_encode($slug) ?>;
const CAN_MANAGE = <?= json_encode($canManage) ?>;
const CELLS_SAVE_URL = <?= json_encode('/raids/cells-save.php?slug=' . $slug) ?>;
const LOCK_URL = <?= json_encode('/raids/lock-save.php?slug=' . $slug) ?>;
const IS_ADMIN = <?= json_encode($isAdmin) ?>;
const TEMPLATE_ID = <?= json_encode($templateId) ?>;
const PUSH_TEMPLATE_URL = <?= json_encode('/raids/push-template.php?slug=' . $slug) ?>;
const RAID_ID = <?= json_encode($raidId) ?>;
const USER_ID = <?= json_encode($user['id']) ?>;
let sections = <?= json_encode($sections) ?>;
const roster = <?= json_encode($roster) ?>;

// Editing lock: advisory only, warns concurrent raid managers off each
// other's structural edits. Only relevant to users who can manage the raid.
let lockHeldByMe = false;
let lockedByOther = null;
let lockHeartbeatTimer = null;

function lockCall(action) {
  return fetch(LOCK_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, raidId: RAID_ID }) })
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
if (CAN_MANAGE) {
  window.addEventListener('beforeunload', () => {
    if (lockHeldByMe) {
      navigator.sendBeacon(LOCK_URL, new Blob([JSON.stringify({ action: 'lock_release', raidId: RAID_ID })], { type: 'application/json' }));
    }
  });
}
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
  if (!el) return;
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
  renderSyncBar();
}

// Push-template sync: structural resync of this raid from its linked template, gated at
// admin (a structural change, not ordinary cell assignment). Two-step diff/apply matching
// push-template.php's contract; a second explicit confirm is required before any removals.
function renderSyncBar() {
  const el = document.getElementById('syncBar');
  if (!el) return;
  const disabled = !!lockedByOther;
  el.innerHTML = `<button type="button" class="btn-sync" id="syncBtn"${disabled ? ' disabled title="Locked by another editor"' : ''}>&#8635; Sync from template</button>`;
  const btn = document.getElementById('syncBtn');
  if (btn && !disabled) btn.addEventListener('click', openSyncModal);
}

const DIFF_GROUP_LABELS = { sections: 'Sections', tables: 'Tables', groups: 'Column groups', columns: 'Columns', rows: 'Rows' };
const DIFF_KEYS = ['sections', 'tables', 'groups', 'columns', 'rows'];

function diffHasRemovals(diff) {
  return DIFF_KEYS.some(k => diff[k].removed.length > 0);
}

function renderDiffBody(diff) {
  let html = '';
  let hasAny = false;
  for (const k of DIFF_KEYS) {
    const d = diff[k];
    const items = [];
    d.added.forEach(x => items.push(`<li class="diff-added">+ ${esc(x.label)}</li>`));
    d.changed.forEach(x => items.push(`<li class="diff-changed">~ ${esc(x.label)} <span class="fields">(${x.changes.join(', ')})</span></li>`));
    d.removed.forEach(x => items.push(`<li class="diff-removed">&minus; ${esc(x.label)}</li>`));
    if (!items.length) continue;
    hasAny = true;
    html += `<div class="diff-group"><h3>${DIFF_GROUP_LABELS[k]}</h3><ul class="diff-list">${items.join('')}</ul></div>`;
  }
  if (!hasAny) html = '<p class="diff-empty">No differences &mdash; this raid already matches the template.</p>';
  return { html, hasAny };
}

function openSyncModal() {
  const backdrop = document.getElementById('syncModalBackdrop');
  const body = document.getElementById('syncModalContent');
  body.innerHTML = '<h2>Sync from template</h2><p class="diff-empty">Loading differences&hellip;</p>';
  backdrop.classList.add('open');
  fetch(PUSH_TEMPLATE_URL, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'diff', raidId: RAID_ID }),
  }).then(r => r.json()).then(d => {
    if (!d.success) { showSyncModalError(d.error || 'Could not compute diff.'); return; }
    renderSyncModalDiff(d.diff, false);
  }).catch(() => showSyncModalError('Network error — could not compute diff.'));
}

function showSyncModalError(msg) {
  const body = document.getElementById('syncModalContent');
  body.innerHTML = `<h2>Sync from template</h2><p class="diff-empty">${esc(msg)}</p>
    <div class="modal-actions"><button type="button" class="btn-cancel" id="syncClose">Close</button></div>`;
  document.getElementById('syncClose').addEventListener('click', closeSyncModal);
}

function closeSyncModal() {
  document.getElementById('syncModalBackdrop').classList.remove('open');
}

function renderSyncModalDiff(diff, confirmingRemovals) {
  const body = document.getElementById('syncModalContent');
  const { html, hasAny } = renderDiffBody(diff);
  const removals = diffHasRemovals(diff);
  let warning = '';
  if (removals && confirmingRemovals) {
    const n = DIFF_KEYS.reduce((sum, k) => sum + diff[k].removed.length, 0);
    warning = `<div class="removal-warning"><strong>Warning:</strong> this will permanently delete ${n} item(s) shown above in red and any toon assignments in them. This cannot be undone.</div>`;
  }
  const applyLabel = removals && !confirmingRemovals ? 'Review removals' : 'Apply sync';
  const applyClass = confirmingRemovals ? 'btn-confirm danger' : 'btn-confirm';
  body.innerHTML = `<h2>Sync from template</h2>${html}${warning}
    <div class="modal-actions">
      <button type="button" class="btn-cancel" id="syncCancel">Cancel</button>
      ${hasAny ? `<button type="button" class="${applyClass}" id="syncApply">${applyLabel}</button>` : ''}
    </div>`;
  document.getElementById('syncCancel').addEventListener('click', closeSyncModal);
  const applyBtn = document.getElementById('syncApply');
  if (applyBtn) {
    applyBtn.addEventListener('click', () => {
      if (removals && !confirmingRemovals) { renderSyncModalDiff(diff, true); return; }
      applySync(removals);
    });
  }
}

function applySync(confirmRemovals) {
  const applyBtn = document.getElementById('syncApply');
  if (applyBtn) { applyBtn.disabled = true; applyBtn.textContent = 'Applying…'; }
  fetch(PUSH_TEMPLATE_URL, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'apply', raidId: RAID_ID, confirmRemovals: !!confirmRemovals }),
  }).then(r => r.json()).then(d => {
    if (!d.success) { alert(d.error || 'Sync failed'); closeSyncModal(); return; }
    closeSyncModal();
    location.reload();
  }).catch(() => { alert('Network error — sync may not have applied.'); closeSyncModal(); });
}

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

function contrastText(hex) {
  if (!hex) return '#e8ecff';
  const h = hex.replace('#', '');
  const r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return lum > 0.6 ? '#111827' : '#ffffff';
}

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

// Every table anywhere in the tree (top-level or nested inside a group), flattened for lookup.
function allTables() {
  const out = [];
  const walk = tables => { for (const tb of tables) { out.push(tb); for (const g of tb.columnGroups) walk(g.tables); } };
  for (const sec of sections) walk(sec.tables);
  return out;
}

function findTable(id) { return allTables().find(t => t.id === id) || null; }

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
  const tb = allTables().find(t => t.id === tableId);
  return tb ? (tb.cells[rowId + '_' + colId] || null) : null;
}

function persistCell(cellId, toonId, note) {
  return fetch(CELLS_SAVE_URL, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cellId, toonId, note }),
  }).then(r => r.json()).then(d => {
    if (!d.success) { alert(d.error || 'Save failed'); return; }
    for (const tb of allTables()) {
      for (const key in tb.cells) if (tb.cells[key].id === cellId) { tb.cells[key] = d.cell; return; }
    }
  });
}

const MAX_DATA_COLS = 10;

// Tables cap at MAX_DATA_COLS data columns (spacers don't count against it); beyond that
// they split into stacked column-blocks that each repeat the full row set, rather than
// growing arbitrarily wide.
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
  // widths correctly when all of them are explicit.
  return c.width || tb.defaultColumnWidth || 120; // 2 units at 60px/unit, matches the editor's default
}

function groupHeaderRow(cols, columnGroups, tb) {
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
      cells.push(`<th class="group-th" colspan="${span}" style="background:${grp.color};color:${contrastText(grp.color)};">${esc(grp.title)}</th>`);
    } else {
      cells.push(`<th colspan="${span}"></th>`);
    }
    i += span;
  }
  return `<tr>${cells.join('')}</tr>`;
}

// Header colspans are stored per-column and consumed left-to-right, same convention as
// the template editor: a column with headerColspan > 1 renders one <th> spanning N
// columns and the next N-1 columns are skipped.
function headerCellsForChunk(chunkCols, tb) {
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

// Same walk-and-consume pattern for body cells: tb.cellMerges is a (rowId, columnId) ->
// colspan lookup, independent of header merges. The covered columns get no <td> at all.
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
    const cell = tb.cells[r.id + '_' + c.id];
    const cellIdAttr = cell ? cell.id : '';
    const editableCls = CAN_MANAGE ? ' editable' : '';
    const noteBtn = CAN_MANAGE ? `<button type="button" class="note-btn" data-cell-id="${cellIdAttr}" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}" style="background:none;border:none;color:#55607a;cursor:pointer;font-size:9px;vertical-align:top;">✎</button>` : '';
    out.push(`<td${colspanAttr} class="cell${editableCls}" data-cell-id="${cellIdAttr}" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}">${chipHtml(cell)}${noteBtn}</td>`);
    i += span;
  }
  return out.join('');
}

function renderColumnBlock(chunkCols, tb) {
  const colHeaders = headerCellsForChunk(chunkCols, tb);

  const colgroup = `<colgroup><col style="width:${tb.rowLabelWidth || 110}px;">` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell" colspan="${chunkCols.length + 1}"></td></tr>`;
    }
    return `<tr><th class="row-label">${esc(r.label)}</th>${bodyCellsForRow(r, chunkCols, tb)}</tr>`;
  }).join('');

  const groupRow = groupHeaderRow(chunkCols, tb.columnGroups, tb);

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${groupRow}
        <tr>${groupRow ? '' : '<th></th>'}${colHeaders}</tr>
        ${bodyRows}
      </table>
    </div>`;
}

function renderTable(tb) {
  const groupsWithTables = tb.columnGroups.filter(g => g.tables.length > 0);
  const isContainerOnly = tb.columns.length === 0 && groupsWithTables.length > 0;
  const blocks = isContainerOnly ? '' : chunkColumns(tb.columns).map(chunkCols => renderColumnBlock(chunkCols, tb)).join('');
  const titleStyle = tb.headerColor ? ` style="background:${tb.headerColor};color:${contrastText(tb.headerColor)};"` : '';

  const nestedGroupsHtml = groupsWithTables.map(g => `
    <div class="group-tables">
      ${g.tables.map(ctb => renderTable(ctb)).join('')}
    </div>`).join('');

  const headBar = tb.title ? `<div class="tbl-title"${titleStyle}>${esc(tb.title)}</div>` : '';

  return `<div class="tbl-wrap">
    ${headBar}
    ${blocks}
    ${nestedGroupsHtml}
  </div>`;
}

function renderSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2', icon: '' };
  return `<div class="section-card">
    <div class="section-head" style="background:${meta.color};">${meta.icon} ${esc(sec.title)}</div>
    <div class="section-body">
      ${sec.tables.map(tb => renderTable(tb)).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
}

render();
if (CAN_MANAGE) checkLock();
</script>
</body>
</html>
