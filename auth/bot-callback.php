<?php
require_once __DIR__ . '/../includes/auth.php';

// Discord redirects here after a user authorizes adding the bot to a
// guild (see includes/discord_bot.php: discord_bot_invite_url()). We
// don't need the OAuth `code` — the admin page independently re-checks
// bot presence via the API — we only use `guild_id` to route the browser
// back to the right tenant's admin page instead of leaving it stranded
// on a bare Discord confirmation screen.

$guildId = $_GET['guild_id'] ?? '';
$tenant  = $guildId ? guild_by_discord_id($guildId) : null;

if (!$tenant) {
    auth_result_page('error', "Couldn't find a RaidRoster tenant for that Discord server.", '/');
}

header('Location: /' . $tenant['slug'] . '/admin?bot=added');
exit;
