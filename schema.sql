-- RaidRoster.net multi-tenant schema

CREATE TABLE guilds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discord_guild_id VARCHAR(32) NOT NULL UNIQUE,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    faction ENUM('Alliance', 'Horde') NOT NULL DEFAULT 'Alliance',
    owner_discord_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logo_path VARCHAR(255) NULL,
    banner_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- guild_users only ever stores explicit OWNER grants. Every other permission
-- tier (readonly / roster_management / raid_management / admin) is resolved
-- live from the member's current Discord roles via guild_role_permissions
-- below -- see includes/roles.php: resolve_guild_role().
CREATE TABLE guild_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id INT NOT NULL,
    discord_user_id VARCHAR(32) NOT NULL,
    discord_username VARCHAR(255) NOT NULL,
    role ENUM('owner', 'editor', 'readonly') NOT NULL DEFAULT 'readonly',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_guild_user (guild_id, discord_user_id),
    FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps a tenant's Discord roles to a permission tier. A tier can have
-- multiple Discord roles mapped to it; a single Discord role maps to
-- exactly one tier. 'admin' rows are only editable by owners (enforced
-- in application code, not the schema).
CREATE TABLE guild_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id INT NOT NULL,
    discord_role_id VARCHAR(32) NOT NULL,
    discord_role_name VARCHAR(100) NOT NULL,
    permission ENUM('roster_management', 'raid_management', 'admin') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_guild_role (guild_id, discord_role_id),
    FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE kv_store (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id INT NOT NULL,
    k VARCHAR(100) NOT NULL,
    v LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_guild_key (guild_id, k),
    FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Curated per-raid pool of available toons (mains/alts/pugs) shown in the raid
-- view's assignment panel. Not the same as the guild roster -- a raid manager
-- opts characters into this pool before dragging them onto cells. toon_id
-- references toons.id or toon_alts.id depending on toon_kind (no FK: the two
-- id spaces are independently generated and not guaranteed disjoint).
CREATE TABLE raid_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    raid_id INT NOT NULL,
    toon_kind ENUM('main', 'alt', 'pug') NOT NULL,
    toon_id VARCHAR(64) NULL,
    pug_name VARCHAR(60) NULL,
    pug_class VARCHAR(30) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_pool_toon (raid_id, toon_kind, toon_id),
    FOREIGN KEY (raid_id) REFERENCES raids(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Advisory editing lock for templates/raids (see includes/edit_lock.php).
-- Purely advisory: does not gate the holder's own writes server-side, only
-- informs/disables controls for other concurrent editors client-side.
CREATE TABLE edit_locks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('template', 'raid') NOT NULL,
    entity_id INT NOT NULL,
    locked_by_discord_user_id VARCHAR(32) NOT NULL,
    locked_by_username VARCHAR(255) NOT NULL,
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance snapshot taken at "lock-in" time: everyone assigned to at least
-- one raid_cells row in the raid, captured as a point-in-time roster. Re-locking
-- overwrites the previous snapshot (raids/attendance-save.php deletes and
-- re-inserts). Locking does not freeze raid_cells editability.
CREATE TABLE raid_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    raid_id INT NOT NULL,
    toon_kind ENUM('main', 'alt', 'pug') NOT NULL,
    toon_id VARCHAR(64) NULL,
    pug_name VARCHAR(60) NULL,
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_by_discord_user_id VARCHAR(32) NOT NULL,
    FOREIGN KEY (raid_id) REFERENCES raids(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
