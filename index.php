<?php
require_once __DIR__ . '/includes/auth.php';
$authed = auth_is_authed();
$user   = $authed ? auth_user() : null;
function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RaidRoster.net</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Multi-tenant WoW guild raid roster and assignment tool, built around your Discord server.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: radial-gradient(ellipse at 50% -10%, #2a1f14 0%, #0a0f1e 55%);
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      color: #e8ecff; display: flex; align-items: center; justify-content: center;
      padding: 32px 20px;
    }
    .card { text-align: center; max-width: 480px; width: 100%; }
    .logo { margin-bottom: 20px; filter: drop-shadow(0 10px 22px rgba(209,69,28,0.35)); }
    .wordmark {
      font-family: 'Cinzel', serif; font-size: 30px; font-weight: 800;
      letter-spacing: 0.02em; margin-bottom: 6px; color: #e8c477;
      text-shadow: 0 0 18px rgba(232,196,119,0.25);
    }
    .wordmark .dim { color: #7f8bad; font-weight: 600; }
    h1 {
      font-size: 17px; font-weight: 600; color: #c7cef2; margin: 18px 0 10px;
    }
    p.sub {
      color: #8b96bd; font-size: 14px; line-height: 1.6; margin-bottom: 30px;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 10px;
      padding: 11px 26px; background: #5865f2; border-radius: 999px;
      color: #fff; font-size: 14px; font-weight: 600; text-decoration: none;
      transition: background .15s, transform .15s;
    }
    .btn:hover { background: #4752c4; transform: translateY(-1px); }
    .btn svg { width: 18px; height: 18px; flex-shrink: 0; }
    .authed-note { font-size: 13px; color: #7f8bad; margin-top: 16px; }
    .authed-note a { color: #a3adfa; }
  </style>
</head>
<body>
  <div class="card">
    <svg class="logo" width="84" height="84" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <radialGradient id="medallion" cx="35%" cy="30%" r="75%">
          <stop offset="0%" stop-color="#3a2a1a"/>
          <stop offset="100%" stop-color="#160f09"/>
        </radialGradient>
        <linearGradient id="rim" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#e8c477"/>
          <stop offset="100%" stop-color="#8a5a2b"/>
        </linearGradient>
        <linearGradient id="bladeA" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#e6ecf5"/>
          <stop offset="100%" stop-color="#8b96a8"/>
        </linearGradient>
        <linearGradient id="bladeB" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#f0c987"/>
          <stop offset="100%" stop-color="#a06a2e"/>
        </linearGradient>
        <radialGradient id="ember" cx="35%" cy="30%" r="70%">
          <stop offset="0%" stop-color="#ffcf8a"/>
          <stop offset="100%" stop-color="#d1451c"/>
        </radialGradient>
      </defs>

      <circle cx="60" cy="60" r="53" fill="url(#medallion)" stroke="url(#rim)" stroke-width="3.5"/>
      <circle cx="60" cy="60" r="45" fill="none" stroke="url(#rim)" stroke-width="1.2" opacity="0.55"/>

      <g transform="rotate(-42 60 60)">
        <polygon points="60,10 67,64 53,64" fill="url(#bladeA)"/>
        <rect x="47" y="64" width="26" height="6" rx="1.5" fill="url(#rim)"/>
        <rect x="55" y="70" width="10" height="21" rx="2" fill="#241a10"/>
        <circle cx="60" cy="95" r="6" fill="url(#rim)"/>
      </g>
      <g transform="rotate(42 60 60)">
        <polygon points="60,10 67,64 53,64" fill="url(#bladeB)"/>
        <rect x="47" y="64" width="26" height="6" rx="1.5" fill="url(#rim)"/>
        <rect x="55" y="70" width="10" height="21" rx="2" fill="#241a10"/>
        <circle cx="60" cy="95" r="6" fill="url(#rim)"/>
      </g>

      <polygon points="60,52 68,60 60,68 52,60" fill="url(#ember)" stroke="#3a1206" stroke-width="1"/>
    </svg>

    <div class="wordmark">RaidRoster<span class="dim">.net</span></div>

    <h1>Run your raid roster where your guild already lives.</h1>
    <p class="sub">
      Sign in with your Discord server to build rosters, plan assignments,
      and post straight back to your raid channel &mdash; no spreadsheets, no separate login.
    </p>

    <?php if ($authed): ?>
      <a class="btn" href="/auth/select-guild.php">
        Continue as <?= h($user['username']) ?>
      </a>
      <p class="authed-note">Not you? <a href="/auth/logout.php">Log out</a></p>
    <?php else: ?>
      <a class="btn" href="/auth/login.php">
        <svg viewBox="0 0 127.14 96.36" fill="currentColor"><path d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z"/></svg>
        Log in with Discord
      </a>
    <?php endif; ?>
  </div>
</body>
</html>
