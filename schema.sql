-- RaidRoster.net multi-tenant schema

CREATE TABLE guilds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discord_guild_id VARCHAR(32) NOT NULL UNIQUE,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    owner_discord_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE kv_store (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id INT NOT NULL,
    k VARCHAR(100) NOT NULL,
    v LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_guild_key (guild_id, k),
    FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
