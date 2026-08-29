<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/raid_structure.php';

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

// 4-week window: Monday of last week -> Sunday of "next two weeks" (28 days total).
$today = new DateTime('today');
$dow   = (int)$today->format('N'); // 1=Mon .. 7=Sun
$thisMonday = (clone $today)->modify('-' . ($dow - 1) . ' days');
$rangeStart = (clone $thisMonday)->modify('-7 days');
$rangeEnd   = (clone $rangeStart)->modify('+27 days'); // inclusive, 28 days total

$startStr = $rangeStart->format('Y-m-d');
$endStr   = $rangeEnd->format('Y-m-d');

// Lazy auto-population: for each date in range whose day-of-week has an active
// recurring slot and has no existing raid row at all, insert one from the template.
$stmt = $pdo->prepare('SELECT rs.day_of_week, rs.template_id, rs.start_time, rs.duration_minutes, t.name, t.description, t.size
                        FROM raid_recurring_slots rs
                        JOIN raid_templates t ON t.id = rs.template_id
                        WHERE rs.guild_id = ? AND rs.active = 1');
$stmt->execute([$tenant['id']]);
$slotsByDow = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $slotsByDow[(int)$s['day_of_week']] = $s;
}

if ($slotsByDow) {
    $stmt = $pdo->prepare('SELECT raid_date FROM raids WHERE guild_id = ? AND raid_date BETWEEN ? AND ?');
    $stmt->execute([$tenant['id'], $startStr, $endStr]);
    $existingDates = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $insAuto = $pdo->prepare(
        'INSERT INTO raids (guild_id, raid_date, start_time, duration_minutes, template_id, name, description, status, created_via, size)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'scheduled\', \'auto\', ?)'
    );

    $cursor = clone $rangeStart;
    while ($cursor <= $rangeEnd) {
        $dateStr = $cursor->format('Y-m-d');
        $phpDow  = (int)$cursor->format('w'); // 0=Sun .. 6=Sat, matches day_of_week convention
        if (isset($slotsByDow[$phpDow]) && !isset($existingDates[$dateStr])) {
            $s = $slotsByDow[$phpDow];
            $insAuto->execute([
                $tenant['id'],
                $dateStr,
                $s['start_time'],
                $s['duration_minutes'],
                $s['template_id'],
                $s['name'],
                $s['description'],
                $s['size'],
            ]);
            $newRaidId = (int)$pdo->lastInsertId();
            copy_template_structure_to_raid($pdo, $s['template_id'], $newRaidId);
            ensure_starting_roster($pdo, $newRaidId, $s['size']);
        }
        $cursor->modify('+1 day');
    }
}

$stmt = $pdo->prepare('SELECT * FROM raids WHERE guild_id = ? AND raid_date BETWEEN ? AND ? ORDER BY raid_date, start_time');
$stmt->execute([$tenant['id'], $startStr, $endStr]);
$raidsByDate = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $raidsByDate[$r['raid_date']][] = [
        'id'              => (int)$r['id'],
        'date'            => $r['raid_date'],
        'startTime'       => $r['start_time'],
        'durationMinutes' => $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
        'templateId'      => $r['template_id'] !== null ? (int)$r['template_id'] : null,
        'name'            => $r['name'],
        'description'     => $r['description'],
        'status'          => $r['status'],
        'createdVia'      => $r['created_via'],
        'size'            => $r['size'],
    ];
}

$stmt = $pdo->prepare('SELECT id, name, description, size FROM raid_templates WHERE guild_id = ? ORDER BY name');
$stmt->execute([$tenant['id']]);
$templates = array_map(function ($t) {
    return [
        'id'          => (int)$t['id'],
        'name'        => $t['name'],
        'description' => $t['description'],
        'size'        => $t['size'],
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$weeks = [];
$cursor = clone $rangeStart;
for ($w = 0; $w < 4; $w++) {
    $week = [];
    for ($d = 0; $d < 7; $d++) {
        $week[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
    $weeks[] = $week;
}

$canManage = role_at_least($role, 'raid_management');
$todayStr  = $today->format('Y-m-d');

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Raids &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff;
    }
    .wrap { max-width: 1040px; margin: 0 auto; padding: 32px 24px 110px; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 24px; }

    .cal { display: flex; flex-direction: column; gap: 10px; }
    .cal-dow-row { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
    .cal-dow { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #7f8bad; text-align: center; padding-bottom: 2px; }
    .cal-week { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
    .cal-cell {
      background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px;
      min-height: 92px; min-width: 0; padding: 8px; display: flex; flex-direction: column; gap: 4px; cursor: default;
    }
    .cal-cell.can-add { cursor: pointer; }
    .cal-cell.can-add:hover { border-color: rgba(88,101,242,0.4); }
    .cal-cell.today { border-color: #5865f2; box-shadow: inset 0 0 0 1px #5865f2; }
    .cal-cell.other-month { opacity: 0.55; }
    .cal-date { font-size: 12px; font-weight: 700; color: #a8b4d0; }
    .cal-cell.today .cal-date { color: #a3adfa; }
    .cal-chip {
      font-size: 11px; padding: 3px 6px; border-radius: 5px; background: rgba(88,101,242,0.15);
      color: #c7cef2; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .cal-chip.cancelled { background: rgba(255,255,255,0.05); color: #55607a; text-decoration: line-through; }
    .cal-chip.auto { border-left: 2px solid rgba(88,101,242,0.5); }

    .week-label { font-size: 11px; color: #55607a; margin-top: 6px; }

    .modal-backdrop {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 500;
      align-items: center; justify-content: center; padding: 20px;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
      background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px;
      padding: 22px; width: 100%; max-width: 420px; max-height: 90vh; overflow-y: auto;
    }
    .modal h2 { font-size: 16px; margin-bottom: 4px; }
    .modal .modal-date { font-size: 12px; color: #7f8bad; margin-bottom: 16px; }
    .mode-tabs { display: flex; gap: 4px; margin-bottom: 16px; }
    .mode-tab {
      flex: 1; background: none; border: 1px solid rgba(255,255,255,0.12); color: #7f8bad;
      font: inherit; font-size: 12px; font-weight: 600; padding: 7px; border-radius: 7px; cursor: pointer;
    }
    .mode-tab.active { background: rgba(88,101,242,0.15); border-color: rgba(88,101,242,0.4); color: #a3adfa; }
    .form-group { margin-bottom: 12px; display: flex; flex-direction: column; gap: 5px; }
    .form-group label { font-size: 11px; color: #7f8bad; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
    .form-group input, .form-group select, .form-group textarea {
      padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px;
      background: #0a0f1e; color: #e8ecff; font-size: 13px; font: inherit;
    }
    .form-group textarea { resize: vertical; min-height: 60px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-row .form-group { margin-bottom: 0; }
    .form-buttons { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
    button.btn, a.btn { display: inline-block; padding: 9px 18px; font: inherit; font-size: 13px; font-weight: 600; border-radius: 999px; cursor: pointer; border: none; text-decoration: none; }
    .btn-save { background: #5865f2; color: #fff; }
    .btn-save:hover { background: #4752c4; }
    .btn-cancel-modal { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12) !important; color: #a8b4d0; }
    .btn-cancel-modal:hover { background: rgba(255,255,255,0.1); }
    .btn-danger { background: rgba(224,85,85,0.15); color: #e88585; }
    .btn-danger:hover { background: rgba(224,85,85,0.3); }
    .btn-restore { background: rgba(88,196,120,0.15); color: #8fd6a8; }
    .btn-restore:hover { background: rgba(88,196,120,0.3); }
    .cancelled-note { font-size: 12px; color: #7f8bad; margin-bottom: 12px; }
    .form-message { margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.4; background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.25); color: #e88585; }
    .form-message.hidden { display: none; }
    .readonly-note { font-size: 12px; color: #7f8bad; text-align: center; padding: 20px; }
    .hidden { display: none !important; }

    @media (max-width: 640px) {
      .cal-dow { font-size: 9px; }
      .cal-cell { min-height: 70px; padding: 6px; }
      .cal-chip { font-size: 9px; padding: 2px 4px; }
    }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <h1>Raids</h1>
    <p class="sub">Signed in as <?= h($user['username']) ?> &middot; role: <?= h($role) ?></p>

    <div class="cal">
      <div class="cal-dow-row">
        <div class="cal-dow">Mon</div><div class="cal-dow">Tue</div><div class="cal-dow">Wed</div>
        <div class="cal-dow">Thu</div><div class="cal-dow">Fri</div><div class="cal-dow">Sat</div><div class="cal-dow">Sun</div>
      </div>
      <div id="calWeeks"></div>
    </div>
  </div>

  <div class="modal-backdrop" id="modalBackdrop">
    <div class="modal">
      <h2 id="modalTitle">Add raid</h2>
      <div class="modal-date" id="modalDate"></div>

      <div id="modalReadonlyNote" class="readonly-note hidden">You need raid management permission to edit raids.</div>

      <div id="modalForm">
        <div class="mode-tabs" id="modeTabs">
          <button type="button" class="mode-tab active" data-mode="template">From template</button>
          <button type="button" class="mode-tab" data-mode="new">New raid</button>
        </div>

        <div class="form-group" id="raidSizeGroup">
          <label for="raidSize">Raid size</label>
          <select id="raidSize">
            <option value="40">40-man</option>
            <option value="20">20-man</option>
          </select>
        </div>

        <div class="form-group" id="templatePickGroup">
          <label for="templateSelect">Template</label>
          <select id="templateSelect"></select>
        </div>

        <div class="form-group">
          <label for="raidName">Name</label>
          <input type="text" id="raidName" placeholder="Raid name">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="raidTime">Start time</label>
            <input type="time" id="raidTime">
          </div>
          <div class="form-group">
            <label for="raidDuration">Duration (min)</label>
            <input type="number" id="raidDuration" min="0" step="15" placeholder="e.g. 180">
          </div>
        </div>
        <div class="form-group">
          <label for="raidDesc">Description</label>
          <textarea id="raidDesc" placeholder="Optional notes"></textarea>
        </div>

        <div id="cancelledNote" class="cancelled-note hidden">This raid is cancelled. Restore it or leave it as-is.</div>

        <div class="form-buttons">
          <button class="btn btn-save" id="modalSaveBtn">Save</button>
          <a class="btn btn-cancel-modal hidden" id="modalViewLink" href="#">View roster &amp; assignments</a>
          <button class="btn btn-cancel-modal" id="modalCloseBtn">Close</button>
          <button class="btn btn-danger hidden" id="modalCancelRaidBtn">Cancel raid</button>
          <button class="btn btn-restore hidden" id="modalRestoreBtn">Restore</button>
          <button class="btn btn-danger hidden" id="modalDeleteRaidBtn">Delete</button>
        </div>
        <div id="modalMessage" class="form-message hidden"></div>
      </div>
    </div>
  </div>

<script>
const SLUG        = <?= json_encode($slug) ?>;
const SAVE_URL    = <?= json_encode('/raids/save.php?slug=' . $slug) ?>;
const CAN_MANAGE  = <?= json_encode($canManage) ?>;
const TODAY       = <?= json_encode($todayStr) ?>;
const WEEKS       = <?= json_encode($weeks) ?>;
let raidsByDate    = <?= json_encode($raidsByDate) ?>;
const templates    = <?= json_encode($templates) ?>;

function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtTime(t) {
  if (!t) return '';
  const [h, m] = t.split(':');
  const hh = ((+h + 11) % 12) + 1;
  return hh + ':' + m + (+h < 12 ? 'am' : 'pm');
}

function renderCalendar() {
  const el = document.getElementById('calWeeks');
  el.innerHTML = WEEKS.map(week => {
    const cells = week.map(date => {
      const dayRaids = raidsByDate[date] || [];
      const cls = ['cal-cell'];
      if (date === TODAY) cls.push('today');
      const dnum = parseInt(date.slice(8, 10), 10);
      const chips = dayRaids.map(r => {
        const chipCls = ['cal-chip'];
        if (r.status === 'cancelled') chipCls.push('cancelled');
        if (r.createdVia === 'auto') chipCls.push('auto');
        const label = (r.startTime ? fmtTime(r.startTime) + ' ' : '') + esc(r.name);
        return `<div class="${chipCls.join(' ')}" onclick="event.stopPropagation(); openRaid(${r.id})">${label}</div>`;
      }).join('');
      if (CAN_MANAGE) cls.push('can-add');
      const cellClick = CAN_MANAGE ? ` onclick="openNewRaid('${date}')"` : '';
      return `<div class="${cls.join(' ')}"${cellClick}>
        <div class="cal-date">${dnum}</div>
        ${chips}
      </div>`;
    }).join('');
    return `<div class="cal-week">${cells}</div>`;
  }).join('');
}
renderCalendar();

let modalDate = null, modalRaidId = null;

function templatesForSize(size) { return templates.filter(t => t.size === size); }

function populateTemplateSelect() {
  const sel = document.getElementById('templateSelect');
  const size = document.getElementById('raidSize').value;
  const matching = templatesForSize(size);
  sel.innerHTML = matching.length
    ? matching.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('')
    : `<option value="">No ${size}-man templates yet — add one in Design</option>`;
}
populateTemplateSelect();

function setMode(mode) {
  document.querySelectorAll('.mode-tab').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
  document.getElementById('templatePickGroup').classList.toggle('hidden', mode !== 'template');
  if (mode === 'template') {
    populateTemplateSelect();
    const sel = document.getElementById('templateSelect');
    if (sel.value) applyTemplate(sel.value);
  }
}
document.querySelectorAll('.mode-tab').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));
document.getElementById('templateSelect').addEventListener('change', function () { applyTemplate(this.value); });
document.getElementById('raidSize').addEventListener('change', function () { setMode(currentMode()); });

function applyTemplate(id) {
  const t = templates.find(x => String(x.id) === String(id));
  if (!t) return;
  document.getElementById('raidName').value = t.name;
  document.getElementById('raidDesc').value = t.description || '';
}

function clearModalMsg() { document.getElementById('modalMessage').classList.add('hidden'); }
function setModalMsg(msg) { const el = document.getElementById('modalMessage'); el.textContent = msg; el.classList.remove('hidden'); }

function openModal() { document.getElementById('modalBackdrop').classList.add('open'); }
function closeModal() { document.getElementById('modalBackdrop').classList.remove('open'); }
document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
document.getElementById('modalBackdrop').addEventListener('click', e => { if (e.target.id === 'modalBackdrop') closeModal(); });

function openNewRaid(date) {
  modalDate = date; modalRaidId = null;
  document.getElementById('modalTitle').textContent = 'Add raid';
  document.getElementById('modalDate').textContent = date;
  document.getElementById('modeTabs').classList.remove('hidden');
  document.getElementById('modalReadonlyNote').classList.toggle('hidden', CAN_MANAGE);
  document.getElementById('modalForm').classList.toggle('hidden', !CAN_MANAGE);
  document.getElementById('cancelledNote').classList.add('hidden');
  document.getElementById('modalCancelRaidBtn').classList.add('hidden');
  document.getElementById('modalRestoreBtn').classList.add('hidden');
  document.getElementById('modalDeleteRaidBtn').classList.add('hidden');
  document.getElementById('modalSaveBtn').classList.remove('hidden');
  document.getElementById('modalViewLink').classList.add('hidden');
  document.getElementById('raidSizeGroup').classList.remove('hidden');
  document.getElementById('raidSize').value = '40';
  populateTemplateSelect();
  setMode('template');
  if (!templates.length) {
    document.getElementById('raidName').value = '';
    document.getElementById('raidTime').value = '';
    document.getElementById('raidDuration').value = '';
    document.getElementById('raidDesc').value = '';
  }
  clearModalMsg();
  openModal();
}

function openRaid(id) {
  let raid = null;
  for (const d in raidsByDate) { const f = raidsByDate[d].find(r => r.id === id); if (f) { raid = f; break; } }
  if (!raid) return;
  modalDate = raid.date; modalRaidId = raid.id;
  document.getElementById('modalTitle').textContent = 'Edit raid';
  document.getElementById('modalDate').textContent = raid.date;
  document.getElementById('modeTabs').classList.add('hidden');
  document.getElementById('templatePickGroup').classList.add('hidden');
  document.getElementById('raidSizeGroup').classList.add('hidden');
  document.getElementById('modalReadonlyNote').classList.toggle('hidden', CAN_MANAGE);
  document.getElementById('modalForm').classList.toggle('hidden', !CAN_MANAGE);
  document.getElementById('raidName').value = raid.name;
  document.getElementById('raidTime').value = (raid.startTime || '').slice(0, 5);
  document.getElementById('raidDuration').value = raid.durationMinutes || '';
  document.getElementById('raidDesc').value = raid.description || '';
  const cancelled = raid.status === 'cancelled';
  document.getElementById('cancelledNote').classList.toggle('hidden', !cancelled);
  document.getElementById('modalSaveBtn').classList.toggle('hidden', cancelled);
  document.getElementById('modalCancelRaidBtn').classList.toggle('hidden', cancelled);
  document.getElementById('modalRestoreBtn').classList.toggle('hidden', !cancelled);
  document.getElementById('modalDeleteRaidBtn').classList.toggle('hidden', !cancelled);
  const viewLink = document.getElementById('modalViewLink');
  viewLink.href = '/raids/view.php?slug=' + encodeURIComponent(SLUG) + '&id=' + raid.id;
  viewLink.classList.remove('hidden');
  clearModalMsg();
  openModal();
}

function currentMode() {
  const active = document.querySelector('.mode-tab.active');
  return active ? active.dataset.mode : 'new';
}

function persist(payload) {
  return fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json().then(d => ({ ok: r.ok, d })))
    .then(({ ok, d }) => { if (!ok || !d.success) throw new Error((d && d.error) || 'Save failed'); return d; });
}

function upsertLocal(raid) {
  for (const d in raidsByDate) raidsByDate[d] = raidsByDate[d].filter(r => r.id !== raid.id);
  if (!raidsByDate[raid.date]) raidsByDate[raid.date] = [];
  raidsByDate[raid.date].push(raid);
  raidsByDate[raid.date].sort((a, b) => (a.startTime || '').localeCompare(b.startTime || ''));
}

document.getElementById('modalSaveBtn').addEventListener('click', function () {
  clearModalMsg();
  const name = document.getElementById('raidName').value.trim();
  if (!name) { setModalMsg('Name is required.'); return; }
  const payload = {
    action: 'save',
    id: modalRaidId,
    date: modalDate,
    name,
    startTime: document.getElementById('raidTime').value || null,
    durationMinutes: document.getElementById('raidDuration').value ? parseInt(document.getElementById('raidDuration').value, 10) : null,
    description: document.getElementById('raidDesc').value.trim() || null,
    templateId: (!modalRaidId && currentMode() === 'template' && document.getElementById('templateSelect').value) ? parseInt(document.getElementById('templateSelect').value, 10) : null,
    size: document.getElementById('raidSize').value,
  };
  persist(payload).then(d => { upsertLocal(d.raid); renderCalendar(); closeModal(); }).catch(e => setModalMsg(e.message));
});

document.getElementById('modalCancelRaidBtn').addEventListener('click', function () {
  if (!modalRaidId) return;
  persist({ action: 'cancel', id: modalRaidId }).then(d => { upsertLocal(d.raid); renderCalendar(); closeModal(); }).catch(e => setModalMsg(e.message));
});
document.getElementById('modalRestoreBtn').addEventListener('click', function () {
  if (!modalRaidId) return;
  persist({ action: 'restore', id: modalRaidId }).then(d => { upsertLocal(d.raid); renderCalendar(); closeModal(); }).catch(e => setModalMsg(e.message));
});
document.getElementById('modalDeleteRaidBtn').addEventListener('click', function () {
  if (!modalRaidId) return;
  if (!confirm('Permanently delete this raid? This cannot be undone.')) return;
  const id = modalRaidId;
  persist({ action: 'delete', id }).then(() => {
    for (const d in raidsByDate) raidsByDate[d] = raidsByDate[d].filter(r => r.id !== id);
    renderCalendar();
    closeModal();
  }).catch(e => setModalMsg(e.message));
});
</script>
</body>
</html>
