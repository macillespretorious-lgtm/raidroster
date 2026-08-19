<?php
// Advisory editing lock for templates/raids. Heartbeat-based: a lock is
// considered stale (and can be silently reclaimed by anyone) once it hasn't
// been renewed in EDIT_LOCK_STALE_SECONDS, so a crashed tab/closed browser
// doesn't lock a template or raid forever.
const EDIT_LOCK_STALE_SECONDS = 600;

function edit_lock_row($pdo, $type, $id) {
    $stmt = $pdo->prepare('SELECT * FROM edit_locks WHERE entity_type = ? AND entity_id = ?');
    $stmt->execute([$type, $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function edit_lock_to_json($row) {
    if (!$row) return null;
    return [
        'discordUserId' => $row['locked_by_discord_user_id'],
        'username'      => $row['locked_by_username'],
        'lockedAt'      => $row['locked_at'],
        'heartbeatAt'   => $row['heartbeat_at'],
    ];
}

function edit_lock_is_stale($row) {
    if (!$row) return true;
    return strtotime($row['heartbeat_at']) < time() - EDIT_LOCK_STALE_SECONDS;
}

// Attempts to claim the lock for $discordUserId. Succeeds if unlocked, already
// held by the same user (re-acquire doubles as a keepalive), or stale.
// Returns ['ok'=>true] on success, or ['ok'=>false,'holder'=>{...}] if
// actively held by someone else.
function acquire_lock($pdo, $type, $id, $discordUserId, $username) {
    $row = edit_lock_row($pdo, $type, $id);
    if ($row && $row['locked_by_discord_user_id'] !== $discordUserId && !edit_lock_is_stale($row)) {
        return ['ok' => false, 'holder' => edit_lock_to_json($row)];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO edit_locks (entity_type, entity_id, locked_by_discord_user_id, locked_by_username)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE locked_by_discord_user_id = VALUES(locked_by_discord_user_id),
                                  locked_by_username = VALUES(locked_by_username),
                                  locked_at = CURRENT_TIMESTAMP,
                                  heartbeat_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$type, $id, $discordUserId, $username]);
    return ['ok' => true];
}

// Refreshes heartbeat_at. Only succeeds if still held by $discordUserId, so a
// lock reclaimed out from under a stale holder can't be silently kept alive
// by the original tab's still-running heartbeat timer.
function heartbeat_lock($pdo, $type, $id, $discordUserId) {
    $stmt = $pdo->prepare(
        'UPDATE edit_locks SET heartbeat_at = CURRENT_TIMESTAMP
         WHERE entity_type = ? AND entity_id = ? AND locked_by_discord_user_id = ?'
    );
    $stmt->execute([$type, $id, $discordUserId]);
    return $stmt->rowCount() > 0;
}

function release_lock($pdo, $type, $id, $discordUserId) {
    $stmt = $pdo->prepare(
        'DELETE FROM edit_locks WHERE entity_type = ? AND entity_id = ? AND locked_by_discord_user_id = ?'
    );
    $stmt->execute([$type, $id, $discordUserId]);
}

// Returns null if unlocked or stale (i.e. free to edit), else the holder info.
function check_lock($pdo, $type, $id) {
    $row = edit_lock_row($pdo, $type, $id);
    if (!$row || edit_lock_is_stale($row)) return null;
    return edit_lock_to_json($row);
}

// Caller must already have verified the current user is an admin.
function force_unlock($pdo, $type, $id) {
    $stmt = $pdo->prepare('DELETE FROM edit_locks WHERE entity_type = ? AND entity_id = ?');
    $stmt->execute([$type, $id]);
}
