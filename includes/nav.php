<?php
// Shared bottom-tab navigation shell for tenant pages (home / toons / admin).
// Usage: nav_asset_link() in <head>, render_nav_shell(...) right after <body>.
require_once __DIR__ . '/db.php';

function nav_asset_link() {
    return '<link rel="stylesheet" href="/assets/nav.css">';
}

function nav_items_for_role($tenant, $role) {
    $slug = $tenant['slug'];
    $items = [
        ['key' => 'home',  'label' => 'Home',  'href' => "/$slug/",       'min' => 'readonly',          'icon' => 'home'],
        ['key' => 'raids', 'label' => 'Raids', 'href' => "/$slug/raids",  'min' => 'readonly',          'icon' => 'raids'],
        ['key' => 'toons', 'label' => 'Toons', 'href' => "/$slug/toons",  'min' => 'roster_management', 'icon' => 'toons'],
        ['key' => 'design', 'label' => 'Design', 'href' => "/$slug/design", 'min' => 'admin',            'icon' => 'design'],
        ['key' => 'admin', 'label' => 'Admin', 'href' => "/$slug/admin",  'min' => 'admin',              'icon' => 'admin'],
    ];
    return array_values(array_filter($items, fn($i) => role_at_least($role, $i['min'])));
}

function nav_icon_svg($key) {
    $icons = [
        'home'  => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'toons' => '<circle cx="9" cy="8" r="3"/><path d="M2.5 20c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6"/><circle cx="17.3" cy="9.2" r="2.3"/><path d="M15.8 14.3c2.6.4 4.5 2.2 5.2 5.7"/>',
        'admin' => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="14" cy="6" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="9" cy="12" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="16" cy="18" r="2" fill="currentColor" stroke="none"/>',
        'raids' => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><line x1="3" y1="9.5" x2="21" y2="9.5"/><line x1="7.5" y1="2.5" x2="7.5" y2="6.5"/><line x1="16.5" y1="2.5" x2="16.5" y2="6.5"/>',
        'design' => '<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="9" height="7" rx="1"/><rect x="16" y="14" width="5" height="7" rx="1"/>',
    ];
    return $icons[$key] ?? '';
}

function render_nav_shell($tenant, $user, $role, $active) {
    $items   = nav_items_for_role($tenant, $role);
    $crest   = htmlspecialchars(strtoupper(substr($tenant['name'], 0, 2)));
    $slug    = htmlspecialchars($tenant['slug']);
    $name    = htmlspecialchars($tenant['name']);
    $uname   = htmlspecialchars($user['username'] ?? '');
    $initial = htmlspecialchars(strtoupper(substr($user['username'] ?? '?', 0, 1)));
    $roleTxt = htmlspecialchars(str_replace('_', ' ', $role ?? ''));

    // Live membership list (not the stale Discord-session cache from login) so a guild
    // created/joined after this user's last login still shows up in the switcher.
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        'SELECT g.slug, g.name, gu.role FROM guilds g
         JOIN guild_users gu ON gu.guild_id = g.id
         WHERE gu.discord_user_id = ? ORDER BY g.name'
    );
    $stmt->execute([$user['id']]);
    $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <header class="rr-topbar">
      <a class="rr-brand" href="/<?= $slug ?>/">
        <span class="rr-crest"><?= $crest ?></span>
      </a>
      <div class="rr-server-switch">
        <button type="button" class="rr-switch-btn" id="rrSwitchBtn" aria-expanded="false">
          <span class="rr-switch-name"><?= $name ?></span>
          <svg class="rr-switch-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="rr-switch-panel" id="rrSwitchPanel">
          <?php foreach ($memberships as $m): ?>
            <?php if ($m['slug'] === $tenant['slug']): ?>
              <span class="rr-switch-item current"><?= htmlspecialchars($m['name']) ?><span class="rr-switch-role"><?= htmlspecialchars(str_replace('_', ' ', $m['role'])) ?></span></span>
            <?php else: ?>
              <a class="rr-switch-item" href="/<?= htmlspecialchars($m['slug']) ?>/"><?= htmlspecialchars($m['name']) ?><span class="rr-switch-role"><?= htmlspecialchars(str_replace('_', ' ', $m['role'])) ?></span></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="rr-user">
        <span class="rr-who">
          <span class="rr-u-name"><?= $uname ?></span>
          <span class="rr-u-role"><?= $roleTxt ?></span>
        </span>
        <span class="rr-avatar"><?= $initial ?></span>
        <a class="rr-logout" href="/auth/logout.php" title="Log out" aria-label="Log out">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </header>

    <nav class="rr-bottomnav" aria-label="Primary">
      <?php foreach ($items as $item): ?>
        <a class="rr-nav-item<?= $item['key'] === $active ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= nav_icon_svg($item['icon']) ?></svg>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <script>
      (function () {
        var btn = document.getElementById('rrSwitchBtn');
        var panel = document.getElementById('rrSwitchPanel');
        if (!btn || !panel) return;
        function close() { panel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var open = panel.classList.toggle('open');
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
          if (!panel.contains(e.target) && e.target !== btn) close();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
      })();
    </script>
    <?php
}
