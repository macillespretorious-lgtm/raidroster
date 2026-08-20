<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/nav.php';
require_once __DIR__ . '/../includes/raid_fetch.php';

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

$sections = fetch_raid_structure($pdo, $raidId);

$roster = [];
$pool = [];
if ($canManage) {
    $stmt = $pdo->prepare('SELECT id, main_name, class, status FROM toons WHERE guild_id = ? ORDER BY main_name');
    $stmt->execute([$tenant['id']]);
    $mains = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT id, main_id, name, class, status FROM toon_alts WHERE guild_id = ? ORDER BY name');
    $stmt->execute([$tenant['id']]);
    $altsByMain = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $altsByMain[$a['main_id']][] = ['id' => $a['id'], 'name' => $a['name'], 'class' => $a['class'], 'status' => $a['status']];
    }

    $roster = array_map(fn($t) => [
        'id' => $t['id'], 'name' => $t['main_name'], 'class' => $t['class'], 'status' => $t['status'],
        'alts' => $altsByMain[$t['id']] ?? [],
    ], $mains);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.toon_kind, p.toon_id, p.pug_name, p.pug_class, p.sort_order,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class
         FROM raid_pool p
         LEFT JOIN toons t ON p.toon_kind = \'main\' AND t.id = p.toon_id
         LEFT JOIN toon_alts a ON p.toon_kind = \'alt\' AND a.id = p.toon_id
         WHERE p.raid_id = ?
         ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([$raidId]);
    $pool = array_map(function ($p) {
        $isPug = $p['toon_kind'] === 'pug';
        return [
            'id' => (int)$p['id'], 'toonKind' => $p['toon_kind'], 'toonId' => $p['toon_id'],
            'pugName' => $p['pug_name'], 'pugClass' => $p['pug_class'],
            'name' => $isPug ? $p['pug_name'] : $p['toon_name'],
            'class' => $isPug ? $p['pug_class'] : $p['toon_class'],
            'sortOrder' => (int)$p['sort_order'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
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
    .section-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .section-clear-btn { font-size: 10px; font-weight: 700; letter-spacing: normal; text-transform: none; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.3); color: #fff; padding: 5px 10px; border-radius: 999px; cursor: pointer; }
    .section-clear-btn:hover { background: rgba(0,0,0,0.4); }
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
      position: relative; display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 3px 10px;
      font-size: 11px; font-weight: 700; color: #000; white-space: nowrap; cursor: default;
    }
    td.cell.editable .toon-chip[draggable="true"] { cursor: grab; }
    td.cell.editable.drop-hover { background: rgba(88,101,242,0.18); }
    .chip-clear {
      display: inline-flex; align-items: center; justify-content: center; width: 13px; height: 13px;
      margin-left: 2px; border-radius: 50%; background: rgba(0,0,0,0.25); color: inherit; font-size: 11px;
      line-height: 1; cursor: pointer;
    }
    .chip-clear:hover { background: rgba(0,0,0,0.45); }
    .empty-slot { display: inline-block; color: #4a5578; font-size: 14px; padding: 3px 10px; }
    .cell-note { display: block; font-size: 10px; color: #7f8bad; margin-top: 2px; }

    .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

    .pool-toolbar { margin: 8px 0 4px; }
    .btn-pool-toggle { background: rgba(88,101,242,0.15); border: 1px solid rgba(88,101,242,0.4); color: #b9c0ff; font-size: 12px; font-weight: 600; padding: 7px 14px; border-radius: 999px; cursor: pointer; }
    .btn-pool-toggle:hover { background: rgba(88,101,242,0.28); }
    .pool-drawer {
      position: fixed; top: 0; right: 0; bottom: 0; width: 300px; max-width: 90vw; z-index: 400;
      background: #111827; border-left: 1px solid rgba(255,255,255,0.1); box-shadow: -8px 0 24px rgba(0,0,0,0.35);
      display: flex; flex-direction: column; padding: 16px; gap: 12px; overflow-y: auto;
      transform: translateX(100%); transition: transform .18s ease, border-color .18s ease;
    }
    .pool-drawer.open { transform: translateX(0); }
    .pool-drawer.stamp-mode { border-left-color: #f0c04a; box-shadow: -8px 0 24px rgba(240,192,74,0.25); }
    .pool-drawer-head { display: flex; align-items: center; justify-content: space-between; }
    .pool-drawer-head h2 { font-size: 15px; }
    .pool-drawer-close { background: none; border: none; color: #a8b4d0; font-size: 20px; cursor: pointer; line-height: 1; }
    .pool-drawer-close:hover { color: #e8ecff; }
    .pool-search-wrap { position: relative; }
    #poolSearchInput {
      width: 100%; padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px;
      background: #0a0f1e; color: #e8ecff; font-size: 12.5px; font: inherit;
    }
    .pool-search-results {
      display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 10;
      max-height: 200px; overflow-y: auto; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.15);
      border-radius: 7px; box-shadow: 0 8px 22px rgba(0,0,0,0.4);
    }
    .pool-search-results.open { display: block; }
    .pool-search-item { padding: 7px 10px; font-size: 12px; color: #c7cef2; cursor: pointer; }
    .pool-search-item:hover { background: rgba(88,101,242,0.15); color: #e8ecff; }
    .pool-search-empty { color: #7f8bad; font-style: italic; cursor: default; }
    .pool-search-empty:hover { background: none; }
    .pool-add-pug { display: flex; gap: 6px; }
    #pugNameInput { flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px; background: #0a0f1e; color: #e8ecff; font-size: 12.5px; font: inherit; }
    #pugClassInput { padding: 8px 6px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px; background: #0a0f1e; color: #e8ecff; font-size: 12px; font: inherit; }
    #pugAddBtn { padding: 8px 12px; border: none; border-radius: 7px; background: #4a63e0; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; }
    #pugAddBtn:hover { background: #3b52c4; }
    .pool-list { display: flex; flex-direction: column; gap: 6px; }
    .pool-chip-row { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    .pool-chip-row.indent { margin-left: 16px; }
    .pool-chip { flex: 1; min-width: 0; cursor: grab; justify-content: flex-start; overflow: hidden; text-overflow: ellipsis; }
    .pool-chip.stamped { outline: 2px solid #f0c04a; outline-offset: 1px; }
    .pool-tag { font-size: 9px; font-weight: 700; opacity: .75; margin-left: 4px; }
    .pool-tag.pug { color: #5a3d00; }
    .pool-remove { flex-shrink: 0; background: none; border: none; color: #7f8bad; font-size: 15px; cursor: pointer; line-height: 1; padding: 2px 4px; }
    .pool-remove:hover { color: #e88585; }
    .pool-empty { color: #7f8bad; font-size: 12px; padding: 8px 0; }
    .pool-hint { color: #55607a; font-size: 11px; line-height: 1.5; margin-top: auto; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.06); }

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
    .modal.import-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; }
    .modal.import-modal h2 { font-size: 17px; margin-bottom: 14px; }
    .import-url-row { display: flex; gap: 8px; }
    .import-url-row input { flex: 1; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; padding: 9px 12px; color: #e8ecff; font-size: 13px; }
    .import-hint { font-size: 11px; color: #7f8bad; margin: 6px 0 0; }
    .import-status { font-size: 12px; color: #b9c0ff; margin: 10px 0; }
    .import-rows { display: flex; flex-direction: column; gap: 5px; margin-top: 12px; max-height: 340px; overflow-y: auto; }
    .import-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 10px; border-radius: 8px; background: rgba(255,255,255,0.04); font-size: 12px; }
    .import-row .name { font-weight: 600; color: #e8ecff; }
    .import-row .detail { color: #9aa4c7; }
    .import-row.matched .status { color: #6fd88a; }
    .import-row.unmatched .status { color: #f0c04a; }
    .import-row.added .status { color: #6fd88a; font-weight: 700; }
    .import-row button { font-size: 11px; padding: 5px 10px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; background: #4a63e0; color: #fff; }
    .import-row button:disabled { opacity: .5; cursor: not-allowed; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <a class="back" href="/<?= h($slug) ?>/raids">&larr; Back to calendar</a>
    <h1><?= h($raid['name']) ?><?php if ($raid['status'] === 'cancelled'): ?> <span class="status-cancelled">(cancelled)</span><?php endif; ?></h1>
    <?php if ($canManage): ?><div class="lock-bar" id="lockBar"></div><?php endif; ?>
    <?php if ($isAdmin && $templateId !== null): ?><div class="sync-bar" id="syncBar"></div><?php endif; ?>
    <?php if ($canManage): ?><div class="pool-toolbar"><button type="button" class="btn-pool-toggle" id="poolToggleBtn">Available toons</button> <button type="button" class="btn-pool-toggle" id="importToggleBtn">Import Raid</button> <button type="button" class="btn-pool-toggle" id="clearAllBtn">Clear all</button></div><?php endif; ?>
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

  <?php if ($canManage): ?>
  <div class="modal-backdrop" id="importModalBackdrop">
    <div class="modal import-modal">
      <h2>Import Raid signups</h2>
      <div class="import-url-row">
        <input type="text" id="importUrlInput" placeholder="Paste Raid-Helper event URL or ID&hellip;" autocomplete="off">
        <button type="button" class="btn-confirm" id="importFetchBtn">Fetch</button>
      </div>
      <p class="import-hint">Matched signups resolve to an existing main/alt; unmatched ones can be added as a one-off PUG. Nothing is added to the pool until you confirm below.</p>
      <div class="import-status" id="importStatus"></div>
      <div class="import-rows" id="importRows"></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" id="importCloseBtn">Close</button>
        <button type="button" class="btn-confirm" id="importAllBtn" disabled>Add all to pool</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <div class="pool-drawer" id="poolDrawer">
    <div class="pool-drawer-head">
      <h2>Available toons</h2>
      <button type="button" class="pool-drawer-close" id="poolDrawerClose">&times;</button>
    </div>
    <div class="pool-search-wrap">
      <input type="text" id="poolSearchInput" placeholder="Search roster to add&hellip;" autocomplete="off">
      <div class="pool-search-results" id="poolSearchResults"></div>
    </div>
    <div class="pool-add-pug">
      <input type="text" id="pugNameInput" placeholder="PUG name">
      <select id="pugClassInput">
        <option value="">Class</option>
        <option>Warrior</option><option>Paladin</option><option>Priest</option><option>Druid</option>
        <option>Mage</option><option>Rogue</option><option>Warlock</option><option>Shaman</option><option>Hunter</option>
      </select>
      <button type="button" id="pugAddBtn">Add</button>
    </div>
    <div class="pool-list" id="poolList"></div>
    <p class="pool-hint">Drag a toon onto a slot to assign it. Alt+Click an assigned toon to cycle its alts. Ctrl/Cmd+Click a pool toon to stamp it repeatedly onto empty slots.</p>
  </div>
  <?php endif; ?>

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
let pool = <?= json_encode($pool) ?>;
const POOL_SAVE_URL = <?= json_encode('/raids/pool-save.php?slug=' . $slug) ?>;
const IMPORT_URL = <?= json_encode('/raids/import-signups.php?slug=' . $slug) ?>;
let stampToon = null;
let importRows = [];

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
  if (!cell || !cell.name) return '<span class="empty-slot">+</span>';
  const color = classColor(cell.class);
  const dragAttrs = CAN_MANAGE
    ? ` draggable="true" data-source="cell" data-cell-id="${cell.id}" data-toon-kind="${esc(cell.toonKind)}" data-toon-id="${esc(cell.toonId || '')}" data-pug-name="${esc(cell.pugName || '')}" data-pug-class="${esc(cell.pugClass || '')}"`
    : '';
  let html = `<span class="toon-chip"${dragAttrs} style="background:${color};">${esc(cell.name)}`;
  if (CAN_MANAGE) html += `<span class="chip-clear" data-action="clear" data-cell-id="${cell.id}">&times;</span>`;
  html += `</span>`;
  if (cell.note) html += `<span class="cell-note">${esc(cell.note)}</span>`;
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
      td.addEventListener('dragover', e => { e.preventDefault(); td.classList.add('drop-hover'); });
      td.addEventListener('dragleave', () => td.classList.remove('drop-hover'));
      td.addEventListener('drop', e => {
        e.preventDefault();
        td.classList.remove('drop-hover');
        handleDrop(td, e);
      });
      td.addEventListener('click', () => {
        if (!stampToon) return;
        const cellId = parseInt(td.dataset.cellId, 10);
        const cur = findCellById(cellId);
        if (cur && cur.name) return; // stamp mode only fills empty slots
        saveCellPatch(cellId, { toonKind: stampToon.toonKind, toonId: stampToon.toonId, pugName: stampToon.pugName, pugClass: stampToon.pugClass });
      });
    });
    el.querySelectorAll('.toon-chip[draggable="true"]').forEach(chip => {
      chip.addEventListener('dragstart', e => {
        const payload = {
          source: 'cell',
          cellId: parseInt(chip.dataset.cellId, 10),
          toonKind: chip.dataset.toonKind,
          toonId: chip.dataset.toonId || null,
          pugName: chip.dataset.pugName || null,
          pugClass: chip.dataset.pugClass || null,
        };
        e.dataTransfer.setData('text/plain', JSON.stringify(payload));
        e.dataTransfer.effectAllowed = 'move';
      });
      chip.addEventListener('click', e => {
        if (!e.altKey) return;
        e.stopPropagation();
        cycleAlt(chip);
      });
    });
    el.querySelectorAll('.chip-clear').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const cellId = parseInt(btn.dataset.cellId, 10);
        const cur = findCellById(cellId);
        saveCellPatch(cellId, { toonKind: null, toonId: null, pugName: null, pugClass: null, note: cur ? cur.note : null });
      });
    });
    el.querySelectorAll('.note-btn').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const cellId = parseInt(btn.dataset.cellId, 10);
        const cur = findCellById(cellId);
        const note = prompt('Short note for this slot (optional):', (cur && cur.note) || '');
        if (note === null) return;
        saveCellPatch(cellId, {
          toonKind: cur ? cur.toonKind : null, toonId: cur ? cur.toonId : null,
          pugName: cur ? cur.pugName : null, pugClass: cur ? cur.pugClass : null,
          note: note.trim() || null,
        });
      });
    });
    el.querySelectorAll('.section-clear-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!confirm('Clear every assignment in this section? This cannot be undone.')) return;
        clearCall({ action: 'clear_section', sectionId: parseInt(btn.dataset.sectionId, 10) });
      });
    });
  }
}

function clearCall(body) {
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, ...body }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Clear failed'); return; }
      sections = d.sections;
      render();
    });
}

function findCellById(cellId) {
  for (const tb of allTables()) {
    for (const key in tb.cells) if (tb.cells[key].id === cellId) return tb.cells[key];
  }
  return null;
}

function updateCellInPlace(cell) {
  if (!cell) return;
  for (const tb of allTables()) {
    for (const key in tb.cells) if (tb.cells[key].id === cell.id) { tb.cells[key] = cell; return; }
  }
}

function saveCellPatch(cellId, patch) {
  const cur = findCellById(cellId);
  const body = {
    action: 'assign', cellId,
    toonKind: patch.toonKind !== undefined ? patch.toonKind : (cur ? cur.toonKind : null),
    toonId: patch.toonId !== undefined ? patch.toonId : (cur ? cur.toonId : null),
    pugName: patch.pugName !== undefined ? patch.pugName : (cur ? cur.pugName : null),
    pugClass: patch.pugClass !== undefined ? patch.pugClass : (cur ? cur.pugClass : null),
    note: patch.note !== undefined ? patch.note : (cur ? cur.note : null),
  };
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Save failed'); return; }
      updateCellInPlace(d.cell);
      render();
    });
}

function persistMove(fromCellId, toCellId) {
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'move', fromCellId, toCellId }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Move failed'); return; }
      updateCellInPlace(d.from);
      updateCellInPlace(d.to);
      render();
    });
}

function handleDrop(td, e) {
  let payload;
  try { payload = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
  if (!payload) return;
  const toCellId = parseInt(td.dataset.cellId, 10);
  if (!toCellId) return;
  if (payload.source === 'cell') {
    if (payload.cellId === toCellId) return;
    persistMove(payload.cellId, toCellId);
  } else if (payload.source === 'pool') {
    saveCellPatch(toCellId, { toonKind: payload.toonKind, toonId: payload.toonId, pugName: payload.pugName, pugClass: payload.pugClass });
  }
}

// Ordered [main, alt1, alt2, ...] cycle list for whichever main/alt id owns this chip,
// used by Alt+Click to swap an assigned chip to the "next" character on the same person.
function siblingChain(toonKind, toonId) {
  for (const m of roster) {
    if ((toonKind === 'main' && m.id === toonId) || (toonKind === 'alt' && m.alts.some(a => a.id === toonId))) {
      return [{ toonKind: 'main', toonId: m.id, name: m.name, class: m.class }]
        .concat(m.alts.map(a => ({ toonKind: 'alt', toonId: a.id, name: a.name, class: a.class })));
    }
  }
  return [];
}

function cycleAlt(chip) {
  const toonKind = chip.dataset.toonKind;
  const toonId = chip.dataset.toonId;
  if (toonKind !== 'main' && toonKind !== 'alt') return; // pugs have no siblings
  const chain = siblingChain(toonKind, toonId);
  if (chain.length < 2) return;
  const idx = chain.findIndex(x => x.toonKind === toonKind && x.toonId === toonId);
  const next = chain[(idx + 1) % chain.length];
  const cellId = parseInt(chip.dataset.cellId, 10);
  saveCellPatch(cellId, { toonKind: next.toonKind, toonId: next.toonId, pugName: null, pugClass: null });
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
  const clearBtn = CAN_MANAGE ? `<button type="button" class="section-clear-btn" data-section-id="${sec.id}" title="Clear all assignments in this section">Clear section</button>` : '';
  return `<div class="section-card">
    <div class="section-head" style="background:${meta.color};"><span>${meta.icon} ${esc(sec.title)}</span>${clearBtn}</div>
    <div class="section-body">
      ${sec.tables.map(tb => renderTable(tb)).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
}

function poolEntryHtml(p, indent) {
  const color = classColor(p.class);
  const tagLabel = p.toonKind === 'pug' ? 'PUG' : (p.toonKind === 'alt' ? 'ALT' : '');
  const tag = tagLabel ? `<span class="pool-tag${p.toonKind === 'pug' ? ' pug' : ''}">${tagLabel}</span>` : '';
  return `<div class="pool-chip-row${indent ? ' indent' : ''}">
    <span class="toon-chip pool-chip" draggable="true" data-source="pool" data-pool-id="${p.id}"
      data-toon-kind="${esc(p.toonKind)}" data-toon-id="${esc(p.toonId || '')}"
      data-pug-name="${esc(p.pugName || '')}" data-pug-class="${esc(p.pugClass || '')}"
      style="background:${color};">${esc(p.name)}${tag}</span>
    <button type="button" class="pool-remove" data-pool-id="${p.id}" title="Remove from pool">&times;</button>
  </div>`;
}

function renderPool() {
  const listEl = document.getElementById('poolList');
  if (!listEl) return;

  const byMainId = {};
  const altEntries = [];
  const pugEntries = [];
  pool.forEach(p => {
    if (p.toonKind === 'main') byMainId[p.toonId] = p;
    else if (p.toonKind === 'alt') altEntries.push(p);
    else pugEntries.push(p);
  });
  const altIdsByMain = {};
  roster.forEach(m => { altIdsByMain[m.id] = new Set(m.alts.map(a => a.id)); });

  let html = '';
  roster.forEach(m => {
    const mainEntry = byMainId[m.id];
    const mine = altEntries.filter(a => altIdsByMain[m.id] && altIdsByMain[m.id].has(a.toonId));
    if (!mainEntry && !mine.length) return;
    if (mainEntry) html += poolEntryHtml(mainEntry, false);
    mine.forEach(a => { html += poolEntryHtml(a, true); });
  });
  pugEntries.forEach(p => { html += poolEntryHtml(p, false); });

  listEl.innerHTML = html || '<p class="pool-empty">No one in the pool yet. Search or add a PUG below.</p>';

  listEl.querySelectorAll('.pool-chip').forEach(chip => {
    chip.addEventListener('dragstart', e => {
      const payload = {
        source: 'pool',
        poolId: parseInt(chip.dataset.poolId, 10),
        toonKind: chip.dataset.toonKind,
        toonId: chip.dataset.toonId || null,
        pugName: chip.dataset.pugName || null,
        pugClass: chip.dataset.pugClass || null,
      };
      e.dataTransfer.setData('text/plain', JSON.stringify(payload));
      e.dataTransfer.effectAllowed = 'copy';
    });
    chip.addEventListener('click', e => {
      if (!e.ctrlKey && !e.metaKey) return;
      e.stopPropagation();
      const next = {
        toonKind: chip.dataset.toonKind,
        toonId: chip.dataset.toonId || null,
        pugName: chip.dataset.pugName || null,
        pugClass: chip.dataset.pugClass || null,
      };
      const same = stampToon && stampToon.toonKind === next.toonKind
        && stampToon.toonId === next.toonId && stampToon.pugName === next.pugName;
      stampToon = same ? null : next;
      updateStampVisuals();
    });
  });
  listEl.querySelectorAll('.pool-remove').forEach(btn => {
    btn.addEventListener('click', () => {
      poolCall({ action: 'remove', poolId: parseInt(btn.dataset.poolId, 10) });
    });
  });
  updateStampVisuals();
}

function updateStampVisuals() {
  const drawer = document.getElementById('poolDrawer');
  if (drawer) drawer.classList.toggle('stamp-mode', !!stampToon);
  document.querySelectorAll('.pool-chip').forEach(chip => {
    const match = stampToon && chip.dataset.toonKind === stampToon.toonKind
      && (chip.dataset.toonId || null) === (stampToon.toonId || null)
      && (chip.dataset.pugName || null) === (stampToon.pugName || null);
    chip.classList.toggle('stamped', !!match);
  });
}

function poolCall(body) {
  return fetch(POOL_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, ...body }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Pool update failed'); return; }
      pool = d.pool;
      renderPool();
    });
}

// Flattened, searchable view of the whole guild roster (mains + alts) for the pool's
// type-ahead add box; alt names are annotated so a same-named alt/main pair stays distinguishable.
function fullRosterFlat() {
  const out = [];
  roster.forEach(m => {
    out.push({ toonKind: 'main', toonId: m.id, name: m.name, class: m.class });
    m.alts.forEach(a => out.push({ toonKind: 'alt', toonId: a.id, name: `${a.name} (alt of ${m.name})`, class: a.class }));
  });
  return out;
}

function wirePoolControls() {
  const toggleBtn = document.getElementById('poolToggleBtn');
  const drawer = document.getElementById('poolDrawer');
  const closeBtn = document.getElementById('poolDrawerClose');
  if (toggleBtn && drawer) toggleBtn.addEventListener('click', () => drawer.classList.toggle('open'));
  if (closeBtn && drawer) closeBtn.addEventListener('click', () => drawer.classList.remove('open'));

  const searchInput = document.getElementById('poolSearchInput');
  const resultsEl = document.getElementById('poolSearchResults');
  if (searchInput && resultsEl) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim().toLowerCase();
      if (!q) { resultsEl.classList.remove('open'); resultsEl.innerHTML = ''; return; }
      const inPool = new Set(pool.filter(p => p.toonKind !== 'pug').map(p => p.toonKind + ':' + p.toonId));
      const matches = fullRosterFlat().filter(t => t.name.toLowerCase().includes(q) && !inPool.has(t.toonKind + ':' + t.toonId)).slice(0, 20);
      resultsEl.innerHTML = matches.length
        ? matches.map(t => `<div class="pool-search-item" data-toon-kind="${esc(t.toonKind)}" data-toon-id="${esc(t.toonId)}">${esc(t.name)}</div>`).join('')
        : '<div class="pool-search-item pool-search-empty">No matches</div>';
      resultsEl.classList.add('open');
      resultsEl.querySelectorAll('.pool-search-item[data-toon-id]').forEach(item => {
        item.addEventListener('click', () => {
          poolCall({ action: 'add', toonKind: item.dataset.toonKind, toonId: item.dataset.toonId });
          searchInput.value = '';
          resultsEl.classList.remove('open');
          resultsEl.innerHTML = '';
        });
      });
    });
    document.addEventListener('click', e => {
      if (!resultsEl.contains(e.target) && e.target !== searchInput) resultsEl.classList.remove('open');
    });
  }

  const pugAddBtn = document.getElementById('pugAddBtn');
  if (pugAddBtn) {
    pugAddBtn.addEventListener('click', () => {
      const nameInput = document.getElementById('pugNameInput');
      const classInput = document.getElementById('pugClassInput');
      const name = nameInput.value.trim();
      if (!name) return;
      poolCall({ action: 'add', toonKind: 'pug', pugName: name, pugClass: classInput.value || null });
      nameInput.value = '';
      classInput.value = '';
    });
  }

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && stampToon) { stampToon = null; updateStampVisuals(); }
  });
  if (drawer) {
    drawer.addEventListener('click', e => {
      if (e.target === drawer && stampToon) { stampToon = null; updateStampVisuals(); }
    });
  }
}

// Import Raid: fetches Raid-Helper signups (matched against toons/toon_alts server-side),
// previews match status per row, and adds confirmed rows to the pool only -- no auto-placement
// into cells, since RR templates have no fixed notion of e.g. "healer slot 2".
function importItemFor(row) {
  return row.matched
    ? { toonKind: row.toonKind, toonId: row.toonId }
    : { toonKind: 'pug', pugName: row.name, pugClass: row.suggestedPugClass || null };
}

function importRowHtml(row, idx) {
  const state = row.added ? 'added' : (row.matched ? 'matched' : 'unmatched');
  const status = row.added ? 'Added' : (row.matched ? `Matched: ${esc(row.toonName)}` : 'Not matched');
  const detail = row.matched ? esc(row.toonClass || '') : esc(row.suggestedPugClass || row.rawClass || '');
  const btnLabel = row.matched ? 'Add' : 'Add as PUG';
  return `<div class="import-row ${state}">
    <div><span class="name">${esc(row.name)}</span> <span class="detail">${detail}</span></div>
    <div class="status">${status}${row.added ? '' : `<button type="button" data-idx="${idx}">${btnLabel}</button>`}</div>
  </div>`;
}

function renderImportRows() {
  const el = document.getElementById('importRows');
  if (!el) return;
  el.innerHTML = importRows.map((r, i) => importRowHtml(r, i)).join('');
  el.querySelectorAll('button[data-idx]').forEach(btn => {
    btn.addEventListener('click', () => addImportRow(parseInt(btn.dataset.idx, 10)));
  });
  const allBtn = document.getElementById('importAllBtn');
  if (allBtn) allBtn.disabled = !importRows.some(r => !r.added);
}

function addImportRow(idx) {
  const row = importRows[idx];
  if (!row || row.added) return;
  poolCall({ action: 'bulkAdd', items: [importItemFor(row)] }).then(() => {
    row.added = true;
    renderImportRows();
  });
}

function addAllImportRows() {
  const items = importRows.filter(r => !r.added).map(importItemFor);
  if (!items.length) return;
  poolCall({ action: 'bulkAdd', items }).then(() => {
    importRows.forEach(r => { r.added = true; });
    renderImportRows();
  });
}

function fetchImportSignups() {
  const input = document.getElementById('importUrlInput');
  const statusEl = document.getElementById('importStatus');
  const url = input.value.trim();
  if (!url) return;
  statusEl.textContent = 'Fetching…';
  importRows = [];
  renderImportRows();
  fetch(IMPORT_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, action: 'fetch', eventUrl: url }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { statusEl.textContent = d.error || 'Import failed'; return; }
      importRows = d.rows.map(r => ({ ...r, added: false }));
      const matched = importRows.filter(r => r.matched).length;
      statusEl.textContent = importRows.length
        ? `${importRows.length} signup(s) found — ${matched} matched, ${importRows.length - matched} unmatched.`
        : 'No signups found on that event.';
      renderImportRows();
    })
    .catch(() => { statusEl.textContent = 'Import failed'; });
}

function wireImportControls() {
  const toggleBtn = document.getElementById('importToggleBtn');
  const backdrop = document.getElementById('importModalBackdrop');
  const closeBtn = document.getElementById('importCloseBtn');
  const fetchBtn = document.getElementById('importFetchBtn');
  const allBtn = document.getElementById('importAllBtn');
  if (toggleBtn && backdrop) toggleBtn.addEventListener('click', () => backdrop.classList.add('open'));
  if (closeBtn && backdrop) closeBtn.addEventListener('click', () => backdrop.classList.remove('open'));
  if (backdrop) backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.classList.remove('open'); });
  if (fetchBtn) fetchBtn.addEventListener('click', fetchImportSignups);
  if (allBtn) allBtn.addEventListener('click', addAllImportRows);
}

function wireClearAll() {
  const btn = document.getElementById('clearAllBtn');
  if (!btn) return;
  btn.addEventListener('click', () => {
    if (!confirm('This clears every assignment in this raid — cannot be undone. Continue?')) return;
    clearCall({ action: 'clear_all' });
  });
}

render();
if (CAN_MANAGE) { checkLock(); renderPool(); wirePoolControls(); wireImportControls(); wireClearAll(); }
</script>
</body>
</html>
