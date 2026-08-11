-- RaidRoster.net multi-tenant schema

CREATE TABLE guilds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discord_guild_id VARCHAR(32) NOT NULL UNIQUE,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    owner_discord_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
