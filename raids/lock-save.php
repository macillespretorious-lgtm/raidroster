<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/edit_lock.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['error' => 'No such guild']);
    exit;
}

auth_session_start();
if (!auth_is_authed()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user = auth_user();
$role = resolve_guild_role($tenant, $user['id']);
if (!role_at_least($role, 'raid_management')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';
$pdo    = db_connect();

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function fetch_raid_owned($pdo, $guildId, $id) {
    $stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$id, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$raidId = (int)($body['raidId'] ?? 0);
$raid = fetch_raid_owned($pdo, $tenant['id'], $raidId);
if (!$raid) fail(404, 'Raid not found');

if ($action === 'lock_status') {
    echo json_encode(['success' => true, 'holder' => check_lock($pdo, 'raid', $raidId)]);
    exit;
}

if ($action === 'lock_acquire') {
    $result = acquire_lock($pdo, 'raid', $raidId, $user['id'], $user['username']);
    echo json_encode(['success' => $result['ok'], 'holder' => $result['holder'] ?? null]);
    exit;
}

if ($action === 'lock_heartbeat') {
    echo json_encode(['success' => heartbeat_lock($pdo, 'raid', $raidId, $user['id'])]);
    exit;
}

if ($action === 'lock_release') {
    release_lock($pdo, 'raid', $raidId, $user['id']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'lock_force_release') {
    if (!role_at_least($role, 'admin')) fail(403, 'Forbidden');
    force_unlock($pdo, 'raid', $raidId);
    echo json_encode(['success' => true]);
    exit;
}

fail(400, 'Unknown action');
