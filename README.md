# RaidRoster.net

Multi-tenant WoW guild raid roster and assignment tool. Each tenant is a Discord server (guild); leaders create a tenant via Discord OAuth, then manage rosters and post assignments to Discord.

Spun off from [Infinite Oblivion Rostering](https://io.macilles.uk) (single-guild version).

## Setup
1. Copy `includes/db.example.php` to `includes/db.php` and fill in credentials.
2. Copy `includes/discord.example.php` to `includes/discord.php` and fill in credentials.
3. Import `schema.sql` into the target database.

## Schema
- `guilds` — tenant registry, keyed by Discord guild ID and a URL slug
- `guild_users` — per-tenant membership with role (`owner` / `editor` / `readonly`)
- `kv_store` — tenant-scoped data, keyed by `(guild_id, k)`
