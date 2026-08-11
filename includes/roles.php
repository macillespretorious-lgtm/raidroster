<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

const ROLE_RANK = [
    'readonly'          => 1,
    'roster_management' => 2,
    'raid_management'   => 3,
    'admin'             => 4,
    'owner'             => 5,
];

function role_at_least($role, $required) {
    if (!$role) {
        return false;
    }
    return (ROLE_RANK[$role] ?? 0) >= (ROLE_RANK[$required] ?? PHP_INT_MAX);
}

function discord_get_with_status($url, $token) {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: RaidRoster/1.0\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp   = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $resp ? json_decode($resp, true) : null];
}

// Resolves a Discord user's permission tier within a tenant, live.
// Returns one of: 'owner', 'admin', 'raid_management', 'roster_management',
// 'readonly', or null if the user is not (or no longer) a member of the
// tenant's Discord server.
//
// Only 'owner' is ever read from a stored row (guild_users); every other
// tier is derived on each call from the member's *current* Discord roles,
// so losing a Discord role revokes the app-level permission automatically.
function resolve_guild_role($tenant, $discordUserId) {
    $pdo  = db_connect();
    $stmt = $pdo->prepare('SELECT role FROM guild_users WHERE guild_id = ? AND discord_user_id = ?');
    $stmt->execute([$tenant['id'], $discordUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['role'] === 'owner') {
        return 'owner';
    }

    $token = auth_discord_token();
    if (!$token) {
        return null;
    }

    $result = discord_get_with_status(
        'https://discord.com/api/users/@me/guilds/' . $tenant['discord_guild_id'] . '/member',
        $token
    );

    if ($result['status'] === 404) {
        // User is confirmed no longer a member of the guild.
        return null;
    }
    if ($result['status'] !== 200 || !is_array($result['body'])) {
        // Discord API hiccup / expired session we couldn't refresh — fail closed.
        return null;
    }

    $memberRoleIds = $result['body']['roles'] ?? [];

    $stmt = $pdo->prepare('SELECT discord_role_id, permission FROM guild_role_permissions WHERE guild_id = ?');
    $stmt->execute([$tenant['id']]);
    $mappings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $best = 'readonly';
    foreach ($memberRoleIds as $roleId) {
        if (isset($mappings[$roleId]) && role_at_least($mappings[$roleId], $best)) {
            $best = $mappings[$roleId];
        }
    }

    return $best;
}

// Resolves the role and, if it doesn't meet $required, either denies (403)
// or redirects to login. Returns the resolved role on success.
function require_role($tenant, $required) {
    auth_require_login();
    $user = auth_user();
    $role = resolve_guild_role($tenant, $user['id']);

    if (!role_at_least($role, $required)) {
        http_response_code(403);
        auth_result_page('error', "You don't have permission to view this page.", '/' . $tenant['slug'] . '/');
    }

    return $role;
}
