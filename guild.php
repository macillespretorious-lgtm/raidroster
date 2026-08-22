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

$startItems = array_values(array_filter(nav_items_for_role($tenant, $role), fn($i) => $i['key'] !== 'home'));
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
    .banner-hero {
      width: 100%; max-height: 200px; object-fit: contain; object-position: center; display: block;
      margin-top: 20px;
    }
    h1 { font-size: 24px; margin-bottom: 6px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 32px; }

    h2.section-title { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #7f8bad; margin-bottom: 12px; }
    .start-grid { display: flex; flex-direction: column; gap: 10px; }
    .start-card {
      display: flex; align-items: center; gap: 14px; background: #111827;
      border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 14px 16px;
      text-decoration: none; color: inherit;
    }
    .start-card:hover { border-color: rgba(88,101,242,0.4); background: #141d33; }
    .start-icon {
      width: 36px; height: 36px; border-radius: 9px; flex-shrink: 0;
      background: rgba(88,101,242,0.15); color: #a3adfa;
      display: flex; align-items: center; justify-content: center;
    }
    .start-text { min-width: 0; }
    .start-label { font-size: 14px; font-weight: 700; color: #e8ecff; }
    .start-desc { font-size: 12.5px; color: #8892b0; margin-top: 2px; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'home'); ?>
  <?php if (!empty($tenant['banner_path'])): ?>
    <img class="banner-hero" src="/<?= h($tenant['banner_path']) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $tenant['banner_path']) ?>" alt="">
  <?php endif; ?>
  <div class="wrap">
    <h1>Welcome back, <?= h($user['username']) ?></h1>
    <p class="sub"><?= h($tenant['name']) ?> &middot; role: <?= h(str_replace('_', ' ', $role)) ?></p>

    <?php if ($startItems): ?>
      <h2 class="section-title">Getting started</h2>
      <div class="start-grid">
        <?php foreach ($startItems as $item): ?>
          <a class="start-card" href="<?= h($item['href']) ?>">
            <span class="start-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= nav_icon_svg($item['icon']) ?></svg>
            </span>
            <span class="start-text">
              <span class="start-label"><?= h($item['label']) ?></span>
              <span class="start-desc"><?= h($item['desc'] ?? '') ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
