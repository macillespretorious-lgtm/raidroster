<?php require_once __DIR__ . '/includes/auth.php'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RaidRoster.net</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff; display: flex; align-items: center; justify-content: center;
    }
    .card { text-align: center; }
    h1 { font-size: 28px; margin-bottom: 8px; }
    p { color: #a8b4d0; font-size: 14px; margin-bottom: 24px; }
    a.btn {
      display: inline-block; padding: 10px 24px;
      background: #5865f2; border-radius: 999px; color: #fff;
      font-size: 14px; font-weight: 600; text-decoration: none;
    }
    a.btn:hover { background: #4752c4; }
  </style>
</head>
<body>
  <div class="card">
    <h1>RaidRoster.net</h1>
    <p>Multi-tenant WoW guild raid roster and assignment tool.</p>
    <a class="btn" href="/auth/login.php">Log in with Discord</a>
  </div>
</body>
</html>
