<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/select-guild.php');
    exit;
}

$user           = auth_user();
$discordGuildId = $_POST['discord_guild_id'] ?? '';
$manageable     = $_SESSION['discord_manageable_guilds'] ?? [];

$match = null;
foreach ($manageable as $g) {
    if ($g['id'] === $discordGuildId) {
        $match = $g;
        break;
    }
}

if (!$match) {
    http_response_code(403);
    echo 'You do not have permission to create a tenant for that server.';
    exit;
}

$existing = guild_by_discord_id($discordGuildId);
if ($existing) {
    header('Location: /' . $existing['slug'] . '/');
    exit;
}

$slug = unique_slug(slugify($match['name']));

$pdo = db_connect();
$pdo->beginTransaction();

$stmt = $pdo->prepare(
    'INSERT INTO guilds (discord_guild_id, slug, name, owner_discord_id) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$discordGuildId, $slug, $match['name'], $user['id']]);
$guildId = $pdo->lastInsertId();

$stmt = $pdo->prepare(
    'INSERT INTO guild_users (guild_id, discord_user_id, discord_username, role) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$guildId, $user['id'], $user['username'], 'owner']);

$pdo->commit();

header('Location: /' . $slug . '/');
exit;
