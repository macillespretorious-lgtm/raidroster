<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/raid_bosses.php';

// Public, unauthenticated cross-guild calendar -- any raid any tenant has opted in to
// (raids.advertise_pug) shows up here so a PUG can find it without a RaidRoster account.
// No realm filter: per the user, all RR-hosted guilds' toons live on one merged environment,
// so faction is the only scoping that matters for whether a PUG could actually join.

$pdo = db_connect();
$stmt = $pdo->prepare(
    "SELECT r.id, r.raid_date, r.start_time, r.duration_minutes, r.name, r.description, r.size, r.zone, r.pug_signup_url,
            g.name AS guild_name, g.faction
     FROM raids r
     JOIN guilds g ON g.id = r.guild_id
     WHERE r.advertise_pug = 1 AND r.status = 'scheduled' AND r.raid_date >= CURDATE()
     ORDER BY r.raid_date, r.start_time"
);
$stmt->execute();
$raids = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byFaction = ['Alliance' => [], 'Horde' => []];
foreach ($raids as $r) {
    $byFaction[$r['faction']][] = $r;
}

function h($s) { return htmlspecialchars($s ?? ''); }
function fmt_date($d) {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('D, M j') : $d;
}
function fmt_time($t) {
    if (!$t) return '';
    [$h, $m] = explode(':', $t);
    $hh = (((int)$h + 11) % 12) + 1;
    return $hh . ':' . $m . ((int)$h < 12 ? 'am' : 'pm');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>PUG Calendar &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff;
    }
    .wrap { max-width: 900px; margin: 0 auto; padding: 32px 20px 80px; }
    h1 { font-size: 24px; margin-bottom: 6px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 28px; line-height: 1.5; }

    .factions { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 700px) { .factions { grid-template-columns: 1fr; } }

    .faction-col h2 { font-size: 15px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .faction-col.alliance h2 { color: #7ea6ff; }
    .faction-col.horde h2 { color: #ff8a7a; }

    .raid-card {
      background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px;
      padding: 14px 16px; margin-bottom: 10px;
    }
    .raid-guild { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #7f8bad; margin-bottom: 4px; }
    .raid-name { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
    .raid-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 12px; color: #a8b4d0; margin-bottom: 10px; }
    .raid-meta span { display: inline-flex; align-items: center; gap: 4px; }
    .raid-desc { font-size: 12.5px; color: #a8b4d0; line-height: 1.4; margin-bottom: 10px; white-space: pre-wrap; }
    .signup-btn {
      display: inline-block; padding: 7px 16px; font-size: 12.5px; font-weight: 600;
      border-radius: 999px; text-decoration: none; background: #5865f2; color: #fff;
    }
    .signup-btn:hover { background: #4752c4; }
    .no-signup { font-size: 12px; color: #55607a; font-style: italic; }
    .empty { color: #55607a; font-size: 13px; padding: 10px 0; }
    footer { text-align: center; font-size: 12px; color: #55607a; margin-top: 40px; }
    footer a { color: #7f8bad; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>PUG Calendar</h1>
    <p class="sub">Upcoming raids from RaidRoster.net guilds that are open to pick-up players. No account needed &mdash; find one that fits and hit Sign up, which opens the guild's Discord (joining you if you're not already a member) and drops you in the raid's signup channel to sign up via its Raid-Helper post.</p>

    <div class="factions">
      <div class="faction-col alliance">
        <h2>Alliance</h2>
        <?php if (!$byFaction['Alliance']): ?>
          <p class="empty">No raids currently advertised.</p>
        <?php else: foreach ($byFaction['Alliance'] as $r): ?>
          <div class="raid-card">
            <div class="raid-guild"><?= h($r['guild_name']) ?></div>
            <div class="raid-name"><?= h($r['name']) ?></div>
            <div class="raid-meta">
              <span><?= h(fmt_date($r['raid_date'])) ?><?= $r['start_time'] ? ' &middot; ' . h(fmt_time($r['start_time'])) : '' ?></span>
              <?php if ($r['zone'] && isset(RAID_ZONE_LABELS[$r['zone']])): ?><span><?= h(RAID_ZONE_LABELS[$r['zone']]) ?></span><?php endif; ?>
              <span><?= h($r['size']) ?>-man</span>
            </div>
            <?php if ($r['description']): ?><div class="raid-desc"><?= h($r['description']) ?></div><?php endif; ?>
            <?php if ($r['pug_signup_url']): ?>
              <a class="signup-btn" href="<?= h($r['pug_signup_url']) ?>" target="_blank" rel="noopener">Sign up</a>
            <?php else: ?>
              <span class="no-signup">No signup link provided yet</span>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="faction-col horde">
        <h2>Horde</h2>
        <?php if (!$byFaction['Horde']): ?>
          <p class="empty">No raids currently advertised.</p>
        <?php else: foreach ($byFaction['Horde'] as $r): ?>
          <div class="raid-card">
            <div class="raid-guild"><?= h($r['guild_name']) ?></div>
            <div class="raid-name"><?= h($r['name']) ?></div>
            <div class="raid-meta">
              <span><?= h(fmt_date($r['raid_date'])) ?><?= $r['start_time'] ? ' &middot; ' . h(fmt_time($r['start_time'])) : '' ?></span>
              <?php if ($r['zone'] && isset(RAID_ZONE_LABELS[$r['zone']])): ?><span><?= h(RAID_ZONE_LABELS[$r['zone']]) ?></span><?php endif; ?>
              <span><?= h($r['size']) ?>-man</span>
            </div>
            <?php if ($r['description']): ?><div class="raid-desc"><?= h($r['description']) ?></div><?php endif; ?>
            <?php if ($r['pug_signup_url']): ?>
              <a class="signup-btn" href="<?= h($r['pug_signup_url']) ?>" target="_blank" rel="noopener">Sign up</a>
            <?php else: ?>
              <span class="no-signup">No signup link provided yet</span>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <footer>Run a WoW Classic guild? <a href="/">Get your own roster on RaidRoster.net</a></footer>
  </div>
</body>
</html>
