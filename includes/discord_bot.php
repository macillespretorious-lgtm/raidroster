<?php
require_once __DIR__ . '/discord.php';

// Bot-token REST helpers. Unlike a user's OAuth token, the bot token can
// list a guild's full role list and search its members — needed for the
// admin settings page's role-mapping UI and "add owner" search.

function discord_bot_request($method, $url, $body = null) {
    $header = "Authorization: Bot " . DISCORD_BOT_TOKEN . "\r\nUser-Agent: RaidRoster/1.0\r\n";
    $opts = [
        'method'        => $method,
        'header'        => $header,
        'timeout'       => 10,
        'ignore_errors' => true,
    ];
    if ($body !== null) {
        $opts['header'] .= "Content-Type: application/json\r\n";
        $opts['content'] = json_encode($body);
    }
    $ctx    = stream_context_create(['http' => $opts]);
    $resp   = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $resp ? json_decode($resp, true) : null];
}

function discord_bot_get($url) {
    return discord_bot_request('GET', $url);
}

// True if the bot is currently a member of the given Discord guild.
function discord_bot_in_guild($discordGuildId) {
    $result = discord_bot_get('https://discord.com/api/guilds/' . $discordGuildId);
    return $result['status'] === 200;
}

// Returns the guild's assignable roles (excludes @everyone and
// bot/integration-managed roles), or null on failure.
function discord_bot_guild_roles($discordGuildId) {
    $result = discord_bot_get('https://discord.com/api/guilds/' . $discordGuildId . '/roles');
    if ($result['status'] !== 200 || !is_array($result['body'])) {
        return null;
    }

    $roles = [];
    foreach ($result['body'] as $role) {
        if ($role['id'] === $discordGuildId) {
            continue; // @everyone
        }
        if (!empty($role['managed'])) {
            continue; // bot/integration role, not assignable to humans
        }
        $roles[] = [
            'id'    => $role['id'],
            'name'  => $role['name'],
            'color' => $role['color'],
        ];
    }
    return $roles;
}

// Searches the guild's members by username/nickname prefix. Returns an
// array of ['id' => ..., 'username' => ..., 'avatar' => ...], or null on
// failure.
function discord_bot_search_members($discordGuildId, $query, $limit = 10) {
    $url = 'https://discord.com/api/guilds/' . $discordGuildId . '/members/search'
        . '?query=' . urlencode($query) . '&limit=' . (int)$limit;
    $result = discord_bot_get($url);
    if ($result['status'] !== 200 || !is_array($result['body'])) {
        return null;
    }

    $members = [];
    foreach ($result['body'] as $m) {
        $user = $m['user'] ?? null;
        if (!$user) {
            continue;
        }
        $members[] = [
            'id'       => $user['id'],
            'username' => $m['nick'] ?? $user['global_name'] ?? $user['username'],
            'avatar'   => $user['avatar'] ?? null,
        ];
    }
    return $members;
}

// Deep link that sends a guild owner/manager straight to Discord's
// authorize screen to add the bot to their own tenant guild. Discord
// enforces Manage Server itself — no OAuth code exchange is needed here,
// but a registered redirect_uri is required so the browser lands back on
// our site afterwards instead of a dead-end Discord confirmation page.
// See auth/bot-callback.php for the landing handler.
function discord_bot_invite_url($discordGuildId) {
    return 'https://discord.com/oauth2/authorize?' . http_build_query([
        'client_id'             => DISCORD_CLIENT_ID,
        'scope'                 => 'bot',
        'permissions'           => '0',
        'guild_id'              => $discordGuildId,
        'disable_guild_select'  => 'true',
        'response_type'         => 'code',
        'redirect_uri'          => 'https://raidroster.net/auth/bot-callback.php',
    ]);
}
