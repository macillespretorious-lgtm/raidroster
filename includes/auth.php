<?php
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/db.php';

const DISCORD_PERM_MANAGE_GUILD = 0x20;

function auth_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('rr_auth');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => true,
        ]);
        session_start();
    }
}

function auth_is_authed() {
    auth_session_start();
    return !empty($_SESSION['discord_user']);
}

function auth_user() {
    auth_session_start();
    return $_SESSION['discord_user'] ?? null;
}

function auth_require_login() {
    auth_session_start();
    if (!auth_is_authed()) {
        header('Location: /auth/login.php?return=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function discord_guild_is_manageable($guild) {
    if (!empty($guild['owner'])) {
        return true;
    }
    $perms = isset($guild['permissions']) ? (int)$guild['permissions'] : 0;
    return ($perms & DISCORD_PERM_MANAGE_GUILD) === DISCORD_PERM_MANAGE_GUILD;
}

function slugify($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'guild';
}

function unique_slug($base) {
    $slug = $base;
    $i = 2;
    while (guild_by_slug($slug)) {
        $slug = $base . '-' . $i;
        $i++;
    }
    return $slug;
}

function discord_post($url, $data) {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: RaidRoster/1.0\r\n",
        'content'       => http_build_query($data),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp ? json_decode($resp, true) : null;
}

function discord_get($url, $token) {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: RaidRoster/1.0\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp ? json_decode($resp, true) : null;
}

function auth_result_page($type, $message = '', $return = '/') {
    $safeReturn = htmlspecialchars($return);

    if ($type === 'error') {
        $icon    = '&#9888;';
        $color   = '#e05555';
        $bgColor = 'rgba(224,85,85,0.08)';
        $border  = 'rgba(224,85,85,0.25)';
        $heading = 'Login error';
        $body    = htmlspecialchars($message ?: 'An unexpected error occurred.');
    } else {
        $icon    = '&#10007;';
        $color   = '#f08030';
        $bgColor = 'rgba(240,128,48,0.08)';
        $border  = 'rgba(240,128,48,0.25)';
        $heading = 'Access denied';
        $body    = htmlspecialchars($message);
    }

    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RaidRoster &mdash; Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif; color: #e8ecff;
    }
    .card {
      border-radius: 14px; padding: 40px 48px; max-width: 420px; width: 90%;
      text-align: center; background: {$bgColor};
      border: 1px solid {$border};
    }
    .icon { font-size: 40px; color: {$color}; line-height: 1; margin-bottom: 16px; }
    h1 { font-size: 20px; font-weight: 700; color: {$color}; margin-bottom: 12px; }
    p  { font-size: 14px; color: #a8b4d0; line-height: 1.6; margin-bottom: 24px; }
    a.btn {
      display: inline-block; padding: 9px 24px;
      background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12);
      border-radius: 999px; color: #e8ecff; font-size: 13px; font-weight: 600;
      text-decoration: none; transition: background .15s;
    }
    a.btn:hover { background: rgba(255,255,255,0.13); }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">{$icon}</div>
    <h1>{$heading}</h1>
    <p>{$body}</p>
    <a href="{$safeReturn}" class="btn">Go back</a>
  </div>
</body>
</html>
HTML;
    exit;
}
