<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';

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
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff; display: flex; align-items: center; justify-content: center;
    }
    .card { text-align: center; }
    h1 { font-size: 24px; margin-bottom: 8px; }
    p { color: #a8b4d0; font-size: 14px; }
    a { color: #5865f2; }
  </style>
</head>
<body>
  <div class="card">
    <h1><?= h($tenant['name']) ?></h1>
    <p>Signed in as <?= h($user['username']) ?> &middot; role: <?= h($role) ?></p>
    <?php if (role_at_least($role, 'admin')): ?>
      <p><a href="/<?= h($tenant['slug']) ?>/admin">Admin settings</a></p>
    <?php endif; ?>
    <p><a href="/auth/logout.php">Log out</a></p>
  </div>
</body>
</html>
