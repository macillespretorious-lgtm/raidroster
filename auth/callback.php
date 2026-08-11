<?php
require_once __DIR__ . '/../includes/auth.php';
auth_session_start();

if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    auth_result_page('error', 'Invalid state parameter. Please try logging in again.', '/');
}
unset($_SESSION['oauth_state']);

if (!empty($_GET['error'])) {
    auth_result_page('error', $_GET['error_description'] ?? $_GET['error'], '/');
}

$code = $_GET['code'] ?? '';
if (!$code) {
    auth_result_page('error', 'No authorisation code returned from Discord.', '/');
}

$tokenData = discord_post('https://discord.com/api/oauth2/token', [
    'client_id'     => DISCORD_CLIENT_ID,
    'client_secret' => DISCORD_CLIENT_SECRET,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => DISCORD_REDIRECT_URI,
]);

if (!$tokenData || empty($tokenData['access_token'])) {
    auth_result_page('error', 'Token exchange with Discord failed. Please try again.', '/');
}

$accessToken = $tokenData['access_token'];

$_SESSION['discord_token'] = [
    'access_token'  => $tokenData['access_token'],
    'refresh_token' => $tokenData['refresh_token'] ?? null,
    'expires_at'    => time() + (int)($tokenData['expires_in'] ?? 604800),
];

$user = discord_get('https://discord.com/api/users/@me', $accessToken);
if (!$user || empty($user['id'])) {
    auth_result_page('error', 'Could not retrieve your Discord user info.', '/');
}

$discordGuilds = discord_get('https://discord.com/api/users/@me/guilds', $accessToken);
$manageable = [];
if (is_array($discordGuilds)) {
    foreach ($discordGuilds as $g) {
        if (discord_guild_is_manageable($g)) {
            $manageable[] = [
                'id'   => $g['id'],
                'name' => $g['name'],
                'icon' => $g['icon'] ?? null,
            ];
        }
    }
}

$_SESSION['discord_user'] = [
    'id'       => $user['id'],
    'username' => $user['global_name'] ?? $user['username'] ?? 'Unknown',
    'avatar'   => $user['avatar'] ?? null,
];
$_SESSION['discord_manageable_guilds'] = $manageable;

$return = $_SESSION['oauth_return'] ?? '/auth/select-guild.php';
unset($_SESSION['oauth_return']);

header('Location: ' . $return);
exit;
