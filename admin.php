<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/discord_bot.php';
require_once __DIR__ . '/includes/nav.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

$role    = require_role($tenant, 'admin');
$user    = auth_user();
$isOwner = ($role === 'owner');
$pdo     = db_connect();

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'map_role') {
        $roleId      = trim($_POST['discord_role_id'] ?? '');
        $roleName    = trim($_POST['discord_role_name'] ?? '');
        $permission  = $_POST['permission'] ?? '';
        $validPerms  = ['roster_management', 'raid_management', 'admin'];

        if (!$roleId || !$roleName || !in_array($permission, $validPerms, true)) {
            $error = 'Invalid role mapping submission.';
        } elseif ($permission === 'admin' && !$isOwner) {
            $error = 'Only the owner can assign the admin tier.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO guild_role_permissions (guild_id, discord_role_id, discord_role_name, permission)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE discord_role_name = VALUES(discord_role_name), permission = VALUES(permission)'
            );
            $stmt->execute([$tenant['id'], $roleId, $roleName, $permission]);
            $notice = "Mapped \"$roleName\" to $permission.";
        }
    }

    if ($action === 'unmap_role') {
        $roleId = trim($_POST['discord_role_id'] ?? '');
        $stmt   = $pdo->prepare('SELECT permission FROM guild_role_permissions WHERE guild_id = ? AND discord_role_id = ?');
        $stmt->execute([$tenant['id'], $roleId]);
        $existing = $stmt->fetchColumn();

        if ($existing === false) {
            $error = 'That mapping no longer exists.';
        } elseif ($existing === 'admin' && !$isOwner) {
            $error = 'Only the owner can remove an admin-tier mapping.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM guild_role_permissions WHERE guild_id = ? AND discord_role_id = ?');
            $stmt->execute([$tenant['id'], $roleId]);
            $notice = 'Mapping removed.';
        }
    }

    if ($action === 'add_owner') {
        if (!$isOwner) {
            $error = 'Only the owner can add other owners.';
        } else {
            $newId   = trim($_POST['discord_user_id'] ?? '');
            $newName = trim($_POST['discord_username'] ?? '');
            if (!$newId || !$newName) {
                $error = 'Invalid owner submission.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO guild_users (guild_id, discord_user_id, discord_username, role)
                     VALUES (?, ?, ?, \'owner\')
                     ON DUPLICATE KEY UPDATE discord_username = VALUES(discord_username), role = \'owner\''
                );
                $stmt->execute([$tenant['id'], $newId, $newName]);
                $notice = "$newName is now an owner.";
            }
        }
    }
}

if (!$notice && !$error && ($_GET['bot'] ?? '') === 'added') {
    $notice = 'Bot added to your server!';
}

$botInGuild = discord_bot_in_guild($tenant['discord_guild_id']);

$stmt = $pdo->prepare('SELECT discord_role_id, discord_role_name, permission FROM guild_role_permissions WHERE guild_id = ? ORDER BY permission, discord_role_name');
$stmt->execute([$tenant['id']]);
$mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mappedRoleIds = array_column($mappings, 'discord_role_id');

$stmt = $pdo->prepare("SELECT discord_user_id, discord_username FROM guild_users WHERE guild_id = ? AND role = 'owner' ORDER BY discord_username");
$stmt->execute([$tenant['id']]);
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

$guildRoles = $botInGuild ? (discord_bot_guild_roles($tenant['discord_guild_id']) ?? []) : [];
$assignableRoles = array_filter($guildRoles, fn($r) => !in_array($r['id'], $mappedRoleIds, true));

$searchResults = [];
$searchQuery = trim($_GET['search'] ?? '');
if ($searchQuery !== '' && $isOwner && $botInGuild) {
    $searchResults = discord_bot_search_members($tenant['discord_guild_id'], $searchQuery) ?? [];
}

$WEBHOOK_DAY_DEFAULTS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'Misc'];
$stmt = $pdo->prepare('SELECT name, webhook_url FROM webhooks WHERE guild_id = ? ORDER BY sort_order');
$stmt->execute([$tenant['id']]);
$webhookDays = array_map(fn($r) => ['name' => $r['name'], 'webhook' => $r['webhook_url']], $stmt->fetchAll(PDO::FETCH_ASSOC));
if (!$webhookDays) {
    $webhookDays = array_map(fn($n) => ['name' => $n, 'webhook' => ''], $WEBHOOK_DAY_DEFAULTS);
}

$stmt = $pdo->prepare('SELECT id, name, description, assignment_style, default_start_time, default_duration_minutes FROM raid_templates WHERE guild_id = ? ORDER BY name');
$stmt->execute([$tenant['id']]);
$raidTemplates = array_map(function ($t) {
    return [
        'id'                     => (int)$t['id'],
        'name'                   => $t['name'],
        'description'            => $t['description'],
        'assignmentStyle'        => $t['assignment_style'],
        'defaultStartTime'       => $t['default_start_time'],
        'defaultDurationMinutes' => $t['default_duration_minutes'] !== null ? (int)$t['default_duration_minutes'] : null,
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT day_of_week, template_id, start_time_override, active FROM raid_recurring_slots WHERE guild_id = ?');
$stmt->execute([$tenant['id']]);
$recurringByDow = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $recurringByDow[(int)$r['day_of_week']] = [
        'templateId'        => (int)$r['template_id'],
        'startTimeOverride' => $r['start_time_override'],
        'active'            => (bool)$r['active'],
    ];
}
$WEEKDAY_LABELS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff;
    }
    .wrap { max-width: 640px; width: 100%; margin: 0 auto; padding: 48px 24px 110px; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 28px; }
    h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .05em; color: #7f8bad; margin: 32px 0 12px; }
    .card {
      background: #111827; border: 1px solid rgba(255,255,255,0.08);
      border-radius: 10px; padding: 16px 18px; margin-bottom: 10px;
    }
    .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .name { font-size: 14px; font-weight: 600; }
    .tag {
      font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
      padding: 3px 9px; border-radius: 999px; font-weight: 700;
      background: rgba(255,255,255,0.08); color: #a8b4d0;
    }
    .tag.roster_management { background: rgba(88,101,242,0.15); color: #a3adfa; }
    .tag.raid_management   { background: rgba(240,128,48,0.15); color: #f0a878; }
    .tag.admin             { background: rgba(232,196,119,0.15); color: #e8c477; }
    .tag.owner             { background: rgba(224,85,85,0.15); color: #e88585; }
    form { margin: 0; }
    .inline-form { display: flex; gap: 8px; align-items: center; }
    select, input[type=text] {
      background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff;
      border-radius: 6px; padding: 7px 10px; font-size: 13px; font: inherit;
    }
    button.btn, a.btn {
      display: inline-block; padding: 7px 16px; font: inherit;
      background: #5865f2; border: none; border-radius: 999px; color: #fff;
      font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer;
    }
    button.btn:hover, a.btn:hover { background: #4752c4; }
    button.btn.danger { background: rgba(224,85,85,0.15); color: #e88585; }
    button.btn.danger:hover { background: rgba(224,85,85,0.3); }
    button.btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .empty { color: #7f8bad; font-size: 14px; padding: 8px 0; }
    .notice, .error {
      border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 20px;
    }
    .notice { background: rgba(88,196,120,0.1); color: #8fd6a8; border: 1px solid rgba(88,196,120,0.25); }
    .error   { background: rgba(224,85,85,0.1); color: #e88585; border: 1px solid rgba(224,85,85,0.25); }
    .bot-cta {
      text-align: center; padding: 28px 20px; background: #111827;
      border: 1px dashed rgba(255,255,255,0.15); border-radius: 10px;
    }
    .bot-cta p { color: #a8b4d0; font-size: 13px; margin-bottom: 14px; }
    .search-form { display: flex; gap: 8px; margin-bottom: 14px; }
    .search-form input { flex: 1; }
    .hint { color: #7f8bad; font-size: 12px; margin-top: 4px; }
    .role-list { list-style: none; }
    .role-list li {
      display: flex; align-items: center; gap: 10px;
      font-size: 13px; color: #c7cef2; padding: 6px 0;
    }
    .role-list li .tag { flex-shrink: 0; min-width: 108px; text-align: center; }

    .tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .tab-btn {
      background: none; border: none; font: inherit; cursor: pointer;
      color: #7f8bad; font-size: 13px; font-weight: 600; padding: 10px 16px;
      border-bottom: 2px solid transparent; margin-bottom: -1px;
    }
    .tab-btn:hover { color: #c7cef2; }
    .tab-btn.active { color: #e8ecff; border-bottom-color: #5865f2; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    .day-row {
      display: flex; align-items: center; gap: 8px; padding: 10px 0;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .day-row:last-child { border-bottom: none; }
    .day-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
      background: rgba(255,255,255,0.15);
    }
    .day-dot.has-webhook { background: #8fd6a8; }
    .day-name-input { width: 110px; flex-shrink: 0; }
    .day-url-input { flex: 1; min-width: 0; }
    .day-status { font-size: 11px; color: #7f8bad; width: 46px; flex-shrink: 0; text-align: center; }
    .btn-add-row {
      background: none; border: 1px dashed rgba(255,255,255,0.2); color: #a8b4d0;
      border-radius: 8px; padding: 8px 14px; font: inherit; font-size: 13px;
      cursor: pointer; width: 100%; margin-top: 10px;
    }
    .btn-add-row:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .btn-icon {
      background: none; border: none; color: #7f8bad; cursor: pointer;
      font-size: 15px; line-height: 1; padding: 4px 6px; flex-shrink: 0;
    }
    .btn-icon:hover { color: #e88585; }
    .btn-icon:disabled { opacity: 0.25; cursor: not-allowed; }
    .instr-box {
      background: rgba(88,101,242,0.08); border: 1px solid rgba(88,101,242,0.2);
      border-radius: 8px; padding: 12px 14px; font-size: 12px; color: #a8b4d0;
      line-height: 1.6; margin-top: 14px;
    }
    .instr-box code {
      background: rgba(255,255,255,0.08); padding: 1px 5px; border-radius: 4px; font-size: 11px;
    }

    .hidden { display: none !important; }

    .recurring-row {
      display: flex; align-items: center; gap: 10px; padding: 10px 0;
      border-bottom: 1px solid rgba(255,255,255,0.06); flex-wrap: wrap;
    }
    .recurring-row:last-child { border-bottom: none; }
    .recurring-day { width: 90px; flex-shrink: 0; font-size: 13px; font-weight: 600; }
    .recurring-row select { flex: 1; min-width: 140px; }
    .recurring-row input[type=time] {
      background: #0a0f1e; border: 1px solid rgba(255,255,255,0.12); color: #e8ecff;
      border-radius: 6px; padding: 7px 10px; font-size: 13px; font: inherit; width: 110px;
    }
    .recurring-active-label { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #a8b4d0; white-space: nowrap; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'admin'); ?>
  <div class="wrap">
    <h1>Admin settings</h1>
    <p class="sub">Signed in as <?= h($user['username']) ?> &middot; role: <?= h($role) ?></p>

    <?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <div class="tabs">
      <button type="button" class="tab-btn active" data-tab="roles">Roles</button>
      <button type="button" class="tab-btn" data-tab="webhooks">Webhooks</button>
      <button type="button" class="tab-btn" data-tab="raids">Schedule</button>
    </div>

    <div class="tab-panel active" id="tab-roles">
    <?php if (!$botInGuild): ?>
      <h2>Bot setup</h2>
      <div class="bot-cta">
        <p>RaidRoster's bot needs to be in your Discord server to read its roles and members for role mapping and owner search.</p>
        <a class="btn" href="<?= h(discord_bot_invite_url($tenant['discord_guild_id'])) ?>">Add bot to server</a>
      </div>
    <?php else: ?>

      <h2>How roles work</h2>
      <div class="card">
        <p class="hint" style="margin-top:0;">Each tier includes everything the tier below it can do. Every tier except owner is tied to a Discord role and updates automatically if someone's Discord roles change.</p>
        <ul class="role-list">
          <li><span class="tag">readonly</span> Any member of the Discord server. View-only, no mapping needed.</li>
          <li><span class="tag roster_management">roster management</span> Manage toons, rosters, and assignments.</li>
          <li><span class="tag raid_management">raid management</span> Everything above, plus edit raid templates.</li>
          <li><span class="tag admin">admin</span> Everything above, plus map Discord roles to the tiers below (mapping the admin tier itself is owner-only).</li>
          <li><span class="tag owner">owner</span> Everything above, plus add other owners. Not tied to a Discord role — granted directly, below.</li>
        </ul>
      </div>

      <h2>Role mapping</h2>
      <?php if (!$mappings): ?>
        <p class="empty">No Discord roles mapped yet — any server member currently has read-only access.</p>
      <?php else: foreach ($mappings as $m): ?>
        <div class="card row">
          <div>
            <div class="name"><?= h($m['discord_role_name']) ?></div>
          </div>
          <div style="display:flex; align-items:center; gap:10px;">
            <span class="tag <?= h($m['permission']) ?>"><?= h(str_replace('_', ' ', $m['permission'])) ?></span>
            <form method="post" onsubmit="return confirm('Remove this role mapping?');">
              <input type="hidden" name="action" value="unmap_role">
              <input type="hidden" name="discord_role_id" value="<?= h($m['discord_role_id']) ?>">
              <button class="btn danger" type="submit" <?= ($m['permission'] === 'admin' && !$isOwner) ? 'disabled title="Owner only"' : '' ?>>Remove</button>
            </form>
          </div>
        </div>
      <?php endforeach; endif; ?>

      <?php if ($assignableRoles): ?>
        <form method="post" class="inline-form" style="margin-top:14px;">
          <input type="hidden" name="action" value="map_role">
          <select name="discord_role_id" required onchange="this.form.discord_role_name.value = this.options[this.selectedIndex].text">
            <option value="" disabled selected>Choose a Discord role&hellip;</option>
            <?php foreach ($assignableRoles as $r): ?>
              <option value="<?= h($r['id']) ?>"><?= h($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="discord_role_name" value="">
          <select name="permission" required>
            <option value="roster_management">Roster management</option>
            <option value="raid_management">Raid management</option>
            <?php if ($isOwner): ?><option value="admin">Admin</option><?php endif; ?>
          </select>
          <button class="btn" type="submit">Map role</button>
        </form>
      <?php elseif ($guildRoles): ?>
        <p class="hint">All assignable Discord roles are already mapped.</p>
      <?php else: ?>
        <p class="hint">Couldn't load this server's roles — check the bot still has access.</p>
      <?php endif; ?>

      <h2>Owners</h2>
      <?php foreach ($owners as $o): ?>
        <div class="card row">
          <div class="name"><?= h($o['discord_username']) ?></div>
          <span class="tag owner">owner</span>
        </div>
      <?php endforeach; ?>

      <?php if ($isOwner): ?>
        <form method="get" class="search-form" style="margin-top:14px;">
          <input type="hidden" name="slug" value="<?= h($slug) ?>">
          <input type="text" name="search" placeholder="Search server members by name&hellip;" value="<?= h($searchQuery) ?>">
          <button class="btn" type="submit">Search</button>
        </form>

        <?php if ($searchQuery !== ''): ?>
          <?php if (!$searchResults): ?>
            <p class="empty">No members found matching "<?= h($searchQuery) ?>".</p>
          <?php else: foreach ($searchResults as $m): ?>
            <div class="card row">
              <div class="name"><?= h($m['username']) ?></div>
              <form method="post" onsubmit="return confirm('Make <?= h(addslashes($m['username'])) ?> an owner?');">
                <input type="hidden" name="action" value="add_owner">
                <input type="hidden" name="discord_user_id" value="<?= h($m['id']) ?>">
                <input type="hidden" name="discord_username" value="<?= h($m['username']) ?>">
                <button class="btn" type="submit">Add as owner</button>
              </form>
            </div>
          <?php endforeach; endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <p class="hint">Only the owner can add other owners.</p>
      <?php endif; ?>

    <?php endif; ?>
    </div>

    <div class="tab-panel" id="tab-webhooks">
      <h2>Channel webhooks</h2>
      <div class="card" id="webhook-card">
        <div id="webhook-rows"></div>
        <button type="button" class="btn-add-row" id="webhook-add-row">+ Add webhook</button>
      </div>
      <div class="instr-box">
        In Discord, go to <strong>Server Settings &rarr; Integrations &rarr; Webhooks</strong> to create a webhook for a channel, then paste its URL here. Each row's Save button stores that row only.
      </div>
    </div>

    <div class="tab-panel" id="tab-raids">
      <h2>Recurring schedule</h2>
      <div class="card" id="recurringCard">
        <div id="recurringRows"></div>
      </div>
      <div class="instr-box">
        Map a weekday to a template to auto-populate that day's raid on the calendar as weeks come into view. Toggle a row off to stop generating new instances without deleting past ones.
        Templates themselves (name, default time, and roster/assignment layout) are built in <a href="/<?= h($slug) ?>/design" style="color:#a3adfa;">Design</a>.
      </div>
    </div>
  </div>

  <script>
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabPanels  = { roles: document.getElementById('tab-roles'), webhooks: document.getElementById('tab-webhooks'), raids: document.getElementById('tab-raids') };
    tabButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        tabButtons.forEach(b => b.classList.remove('active'));
        Object.values(tabPanels).forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        tabPanels[btn.dataset.tab].classList.add('active');
        if (btn.dataset.tab !== 'roles') history.replaceState(null, '', '#' + btn.dataset.tab);
      });
    });
    if (location.hash === '#webhooks' || location.hash === '#raids') {
      document.querySelector('.tab-btn[data-tab="' + location.hash.slice(1) + '"]').click();
    }

    const SAVE_URL = <?= json_encode('/admin/save-webhooks.php?slug=' . $slug) ?>;
    let days = <?= json_encode(array_values($webhookDays)) ?>;
    const rowsEl = document.getElementById('webhook-rows');

    function renderDays() {
      rowsEl.innerHTML = '';
      days.forEach((d, i) => {
        const row = document.createElement('div');
        row.className = 'day-row';
        row.innerHTML = `
          <span class="day-dot ${d.webhook ? 'has-webhook' : ''}"></span>
          <input type="text" class="day-name-input" value="${escAttr(d.name)}" placeholder="Name">
          <input type="text" class="day-url-input" value="${escAttr(d.webhook)}" placeholder="https://discord.com/api/webhooks/...">
          <span class="day-status"></span>
          <button type="button" class="btn btn-save" style="padding:6px 12px;">Save</button>
          <button type="button" class="btn-icon btn-remove" title="Remove">&times;</button>
        `;
        row.querySelector('.btn-save').addEventListener('click', () => saveRow(i, row));
        row.querySelector('.btn-remove').addEventListener('click', () => removeRow(i));
        rowsEl.appendChild(row);
      });
    }

    function escAttr(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function saveRow(i, row) {
      days[i] = {
        name: row.querySelector('.day-name-input').value.trim(),
        webhook: row.querySelector('.day-url-input').value.trim(),
      };
      const status = row.querySelector('.day-status');
      status.textContent = 'Saving…';
      persist().then(ok => {
        status.textContent = ok ? 'Saved' : 'Error';
        row.querySelector('.day-dot').classList.toggle('has-webhook', !!days[i].webhook);
        setTimeout(() => { status.textContent = ''; }, 2000);
      });
    }

    function removeRow(i) {
      days.splice(i, 1);
      persist().then(() => renderDays());
    }

    document.getElementById('webhook-add-row').addEventListener('click', () => {
      days.push({ name: '', webhook: '' });
      renderDays();
    });

    function persist() {
      return fetch(SAVE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ days }),
      }).then(r => r.ok).catch(() => false);
    }

    renderDays();

    // --- Raids tab: recurring schedule ---
    const SLUG = <?= json_encode($slug) ?>;
    const RECUR_SAVE_URL = <?= json_encode('/raids/recurring-save.php?slug=' . $slug) ?>;
    let templates = <?= json_encode($raidTemplates) ?>;
    let recurring = <?= json_encode($recurringByDow, JSON_FORCE_OBJECT) ?>;
    const WEEKDAYS = <?= json_encode(array_map(fn($k, $v) => ['dow' => $k, 'label' => $v], array_keys($WEEKDAY_LABELS), array_values($WEEKDAY_LABELS))) ?>;

    function escH(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function renderRecurringRows() {
      const el = document.getElementById('recurringRows');
      el.innerHTML = WEEKDAYS.map(w => {
        const slot = recurring[w.dow];
        const tplOptions = templates.map(t => `<option value="${t.id}" ${slot && slot.templateId === t.id ? 'selected' : ''}>${escH(t.name)}</option>`).join('');
        return `<div class="recurring-row" data-dow="${w.dow}">
          <span class="recurring-day">${w.label}</span>
          <select class="recur-template">
            <option value="">— None —</option>
            ${tplOptions}
          </select>
          <input type="time" class="recur-time" value="${slot && slot.startTimeOverride ? slot.startTimeOverride.slice(0,5) : ''}" title="Override start time">
          <label class="recurring-active-label"><input type="checkbox" class="recur-active" ${!slot || slot.active ? 'checked' : ''}> Active</label>
        </div>`;
      }).join('');
      el.querySelectorAll('.recurring-row').forEach(row => {
        const dow = row.dataset.dow;
        row.querySelector('.recur-template').addEventListener('change', () => saveRecurringRow(dow, row));
        row.querySelector('.recur-time').addEventListener('change', () => saveRecurringRow(dow, row));
        row.querySelector('.recur-active').addEventListener('change', () => saveRecurringRow(dow, row));
      });
    }

    function saveRecurringRow(dow, row) {
      const templateId = row.querySelector('.recur-template').value;
      if (!templateId) {
        fetch(RECUR_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', dayOfWeek: parseInt(dow, 10) }) })
          .then(r => r.json()).then(d => { if (d.success) { delete recurring[dow]; } });
        return;
      }
      const payload = {
        action: 'save',
        dayOfWeek: parseInt(dow, 10),
        templateId: parseInt(templateId, 10),
        startTimeOverride: row.querySelector('.recur-time').value || null,
        active: row.querySelector('.recur-active').checked,
      };
      fetch(RECUR_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json()).then(d => {
          if (d.success) recurring[dow] = { templateId: payload.templateId, startTimeOverride: payload.startTimeOverride, active: payload.active };
        });
    }

    renderRecurringRows();
  </script>
</body>
</html>
