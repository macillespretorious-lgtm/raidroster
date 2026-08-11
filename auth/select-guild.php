<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_login();

$user       = auth_user();
$manageable = $_SESSION['discord_manageable_guilds'] ?? [];

$pdo  = db_connect();
$stmt = $pdo->prepare(
    'SELECT g.*, gu.role FROM guilds g
     JOIN guild_users gu ON gu.guild_id = g.id
     WHERE gu.discord_user_id = ?'
);
$stmt->execute([$user['id']]);
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

$memberGuildIds = array_column($memberships, 'discord_guild_id');
$creatable = [];
foreach ($manageable as $g) {
    if (!in_array($g['id'], $memberGuildIds, true)) {
        $creatable[] = $g;
    }
}

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RaidRoster &mdash; Choose a server</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff; padding: 48px 24px; display: flex; justify-content: center;
    }
    .wrap { max-width: 560px; width: 100%; }
    h1 { font-size: 22px; margin-bottom: 8px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 32px; }
    h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .05em; color: #7f8bad; margin: 28px 0 12px; }
    .row {
      display: flex; align-items: center; justify-content: space-between;
      background: #111827; border: 1px solid rgba(255,255,255,0.08);
      border-radius: 10px; padding: 14px 18px; margin-bottom: 10px;
    }
    .row .name { font-size: 15px; font-weight: 600; }
    .row .role { font-size: 12px; color: #7f8bad; text-transform: capitalize; }
    a.btn, button.btn {
      display: inline-block; padding: 8px 18px; font: inherit;
      background: #5865f2; border: none; border-radius: 999px; color: #fff;
      font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer;
    }
    a.btn:hover, button.btn:hover { background: #4752c4; }
    .empty { color: #7f8bad; font-size: 14px; padding: 12px 0; }
    form { margin: 0; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Choose a server</h1>
    <p class="sub">Signed in as <strong><?= h($user['username']) ?></strong></p>

    <h2>Your rosters</h2>
    <?php if (!$memberships): ?>
      <p class="empty">You're not a member of any tenant yet.</p>
    <?php else: foreach ($memberships as $m): ?>
      <div class="row">
        <div>
          <div class="name"><?= h($m['name']) ?></div>
          <div class="role"><?= h($m['role']) ?></div>
        </div>
        <a class="btn" href="/<?= h($m['slug']) ?>/">Enter</a>
      </div>
    <?php endforeach; endif; ?>

    <h2>Create a new tenant</h2>
    <?php if (!$creatable): ?>
      <p class="empty">No manageable Discord servers available to set up.</p>
    <?php else: foreach ($creatable as $g): ?>
      <div class="row">
        <div class="name"><?= h($g['name']) ?></div>
        <form method="post" action="/auth/create-guild.php">
          <input type="hidden" name="discord_guild_id" value="<?= h($g['id']) ?>">
          <button class="btn" type="submit">Create tenant</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</body>
</html>
