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
if (!role_at_least($role, 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$days = is_array($body['days'] ?? null) ? $body['days'] : [];

$clean = [];
foreach ($days as $d) {
    $name    = substr(trim($d['name'] ?? ''), 0, 100);
    $webhook = trim($d['webhook'] ?? '');
    if ($webhook !== ''
        && strpos($webhook, 'https://discord.com/api/webhooks/') !== 0
        && strpos($webhook, 'https://discordapp.com/api/webhooks/') !== 0) {
        continue; // ignore anything that isn't a real Discord webhook URL
    }
    $clean[] = ['name' => $name, 'webhook' => $webhook];
}

$pdo = db_connect();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('DELETE FROM webhooks WHERE guild_id = ?');
    $stmt->execute([$tenant['id']]);

    $ins = $pdo->prepare('INSERT INTO webhooks (guild_id, name, webhook_url, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($clean as $i => $d) {
        $ins->execute([$tenant['id'], $d['name'], $d['webhook'], $i]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Save failed']);
    exit;
}

echo json_encode(['success' => true]);
