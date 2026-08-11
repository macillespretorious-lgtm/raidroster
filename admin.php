<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/discord_bot.php';

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

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff; padding: 48px 24px; display: flex; justify-content: center;
    }
    .wrap { max-width: 640px; width: 100%; }
    .back { font-size: 13px; color: #7f8bad; text-decoration: none; }
    .back:hover { color: #a8b4d0; }
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
  </style>
</head>
<body>
  <div class="wrap">
    <a class="back" href="/<?= h($tenant['slug']) ?>/">&larr; Back to <?= h($tenant['name']) ?></a>
    <h1>Admin settings</h1>
    <p class="sub">Signed in as <?= h($user['username']) ?> &middot; role: <?= h($role) ?></p>

    <?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <?php if (!$botInGuild): ?>
      <h2>Bot setup</h2>
      <div class="bot-cta">
        <p>RaidRoster's bot needs to be in your Discord server to read its roles and members for role mapping and owner search.</p>
        <a class="btn" href="<?= h(discord_bot_invite_url($tenant['discord_guild_id'])) ?>">Add bot to server</a>
      </div>
    <?php else: ?>

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
</body>
</html>
