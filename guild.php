<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/nav.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

auth_require_login();
$user = auth_user();
$role = resolve_guild_role($tenant, $user['id']);

if (!$role) {
    http_response_code(403);
    auth_result_page('error', "You're not a member of this guild's Discord server, or your session has expired — try logging in again.", '/');
}

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff;
    }
    .wrap { max-width: 640px; margin: 0 auto; padding: 40px 24px 100px; }
    h1 { font-size: 24px; margin-bottom: 6px; }
    p.sub { color: #a8b4d0; font-size: 14px; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'home'); ?>
  <div class="wrap">
    <h1>Welcome back, <?= h($user['username']) ?></h1>
    <p class="sub"><?= h($tenant['name']) ?> &middot; role: <?= h(str_replace('_', ' ', $role)) ?></p>
  </div>
</body>
</html>
