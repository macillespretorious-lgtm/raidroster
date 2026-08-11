<?php
require_once __DIR__ . '/../includes/auth.php';
auth_session_start();

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state']  = $state;
$_SESSION['oauth_return'] = $_GET['return'] ?? '/auth/select-guild.php';

$params = http_build_query([
    'client_id'     => DISCORD_CLIENT_ID,
    'redirect_uri'  => DISCORD_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => DISCORD_OAUTH_SCOPE,
    'state'         => $state,
    'prompt'        => 'none',
]);

header('Location: https://discord.com/oauth2/authorize?' . $params);
exit;
