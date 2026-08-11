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
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: radial-gradient(ellipse at 50% -10%, #1a2352 0%, #0a0f1e 55%);
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      color: #e8ecff; display: flex; align-items: center; justify-content: center;
      padding: 32px 20px;
    }
    .card { text-align: center; max-width: 480px; width: 100%; }
    .logo { margin-bottom: 20px; filter: drop-shadow(0 8px 24px rgba(88,101,242,0.35)); }
    .wordmark {
      font-size: 30px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 6px;
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
    <svg class="logo" width="76" height="76" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="shield" x1="12" y1="6" x2="108" y2="116" gradientUnits="userSpaceOnUse">
          <stop offset="0%" stop-color="#8b97ff"/>
          <stop offset="100%" stop-color="#4752c4"/>
        </linearGradient>
        <linearGradient id="gem" x1="48" y1="30" x2="72" y2="90" gradientUnits="userSpaceOnUse">
          <stop offset="0%" stop-color="#ffe29a"/>
          <stop offset="100%" stop-color="#f0a830"/>
        </linearGradient>
      </defs>
      <path d="M60 6 L108 24 V60 C108 92 86 110 60 116 C34 110 12 92 12 60 V24 Z"
            fill="url(#shield)" stroke="#0a0f1e" stroke-width="3"/>
      <path d="M60 28 L74 58 L60 92 L46 58 Z" fill="url(#gem)"/>
      <circle cx="60" cy="58" r="10" fill="#0a0f1e" stroke="url(#gem)" stroke-width="3"/>
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
