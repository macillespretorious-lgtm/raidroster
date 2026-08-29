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

$body   = json_decode(file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';
$pdo    = db_connect();

if ($action === 'save') {
    $dow    = isset($body['dayOfWeek']) ? (int)$body['dayOfWeek'] : -1;
    $tmplId = isset($body['templateId']) && $body['templateId'] ? (int)$body['templateId'] : null;
    $start  = $body['startTime'] ?? null;
    $dur    = isset($body['durationMinutes']) && $body['durationMinutes'] !== null ? (int)$body['durationMinutes'] : null;
    $active = !empty($body['active']);

    if ($start !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) $start = null;

    if ($dow < 0 || $dow > 6 || !$tmplId || !$start || !$dur) {
        http_response_code(400);
        echo json_encode(['error' => 'A recurring slot needs a template, start time, and duration']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM raid_templates WHERE id = ? AND guild_id = ?');
    $stmt->execute([$tmplId, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Template not found']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO raid_recurring_slots (guild_id, day_of_week, template_id, start_time, duration_minutes, active)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), start_time = VALUES(start_time), duration_minutes = VALUES(duration_minutes), active = VALUES(active)'
    );
    $stmt->execute([$tenant['id'], $dow, $tmplId, $start, $dur, $active ? 1 : 0]);

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete') {
    $dow = isset($body['dayOfWeek']) ? (int)$body['dayOfWeek'] : -1;
    $stmt = $pdo->prepare('DELETE FROM raid_recurring_slots WHERE guild_id = ? AND day_of_week = ?');
    $stmt->execute([$tenant['id'], $dow]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
