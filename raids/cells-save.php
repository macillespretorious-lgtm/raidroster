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

$cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
$toonId = isset($body['toonId']) && $body['toonId'] !== '' ? (string)$body['toonId'] : null;
$note   = isset($body['note']) && $body['note'] !== '' ? substr(trim($body['note']), 0, 60) : null;

$stmt = $pdo->prepare(
    'SELECT c.id FROM raid_cells c
     JOIN raid_tables tb ON tb.id = c.table_id
     JOIN raid_sections s ON s.id = tb.section_id
     JOIN raids r ON r.id = s.raid_id
     WHERE c.id = ? AND r.guild_id = ?'
);
$stmt->execute([$cellId, $tenant['id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Cell not found']);
    exit;
}

if ($toonId !== null) {
    $stmt = $pdo->prepare('SELECT id FROM toons WHERE id = ? AND guild_id = ?');
    $stmt->execute([$toonId, $tenant['id']]);
    if (!$stmt->fetch()) $toonId = null;
}

$stmt = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, note = ? WHERE id = ?');
$stmt->execute([$toonId, $note, $cellId]);

$stmt = $pdo->prepare(
    'SELECT c.id, c.toon_id, c.note, t.main_name, t.class
     FROM raid_cells c LEFT JOIN toons t ON t.id = c.toon_id
     WHERE c.id = ?'
);
$stmt->execute([$cellId]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'cell' => [
    'id'     => (int)$c['id'],
    'toonId' => $c['toon_id'],
    'name'   => $c['main_name'],
    'class'  => $c['class'],
    'note'   => $c['note'],
]]);
