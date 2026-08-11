<?php
// Copy this file to db.php and fill in real credentials.
// db.php is gitignored — upload it via FTP after each fresh clone.

define('DB_HOST', 'sdb-65.hosting.stackcp.net');
define('DB_NAME', 'raidrostermain-35303339fa8d');
define('DB_USER', 'raidrostermain-35303339fa8d');
define('DB_PASS', '');

function db_connect() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

function guild_load($guildId, $key, $default = '{}') {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT v FROM kv_store WHERE guild_id = ? AND k = ?');
    $stmt->execute([$guildId, $key]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : $default;
}

function guild_save($guildId, $key, $value) {
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        'INSERT INTO kv_store (guild_id, k, v) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $stmt->execute([$guildId, $key, $value]);
}

function guild_by_slug($slug) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM guilds WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function guild_by_discord_id($discordGuildId) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM guilds WHERE discord_guild_id = ?');
    $stmt->execute([$discordGuildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function guild_user_role($guildId, $discordUserId) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT role FROM guild_users WHERE guild_id = ? AND discord_user_id = ?');
    $stmt->execute([$guildId, $discordUserId]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}
