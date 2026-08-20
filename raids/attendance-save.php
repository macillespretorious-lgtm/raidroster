<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
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
$pdo    = db_connect();
$action = $body['action'] ?? 'status';

$raidId = isset($body['raidId']) ? (int)$body['raidId'] : 0;
$stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
$stmt->execute([$raidId, $tenant['id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Raid not found']);
    exit;
}

function fetch_attendance_status($pdo, $raidId) {
    $stmt = $pdo->prepare('SELECT locked_at, COUNT(*) AS cnt FROM raid_attendance WHERE raid_id = ? GROUP BY locked_at ORDER BY locked_at DESC LIMIT 1');
    $stmt->execute([$raidId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['lockedAt' => null, 'count' => 0];
    return ['lockedAt' => $row['locked_at'], 'count' => (int)$row['cnt']];
}

if ($action === 'lock') {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.toon_kind, c.toon_id, c.pug_name
         FROM raid_cells c
         JOIN raid_tables tb ON tb.id = c.table_id
         JOIN raid_sections s ON s.id = tb.section_id
         WHERE s.raid_id = ?
           AND ((c.toon_kind IN (\'main\', \'alt\') AND c.toon_id IS NOT NULL)
                OR (c.toon_kind = \'pug\' AND c.pug_name IS NOT NULL))'
    );
    $stmt->execute([$raidId]);
    $present = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    $del = $pdo->prepare('DELETE FROM raid_attendance WHERE raid_id = ?');
    $del->execute([$raidId]);

    $ins = $pdo->prepare(
        'INSERT INTO raid_attendance (raid_id, toon_kind, toon_id, pug_name, locked_by_discord_user_id) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($present as $p) {
        $ins->execute([$raidId, $p['toon_kind'], $p['toon_id'], $p['pug_name'], $user['id']]);
    }
    $pdo->commit();

    echo json_encode(['success' => true] + fetch_attendance_status($pdo, $raidId));
    exit;
}

// default: status
echo json_encode(['success' => true] + fetch_attendance_status($pdo, $raidId));
