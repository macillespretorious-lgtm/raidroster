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

function template_to_json($t) {
    return [
        'id'                     => (int)$t['id'],
        'name'                   => $t['name'],
        'description'            => $t['description'],
        'assignmentStyle'        => $t['assignment_style'],
        'defaultStartTime'       => $t['default_start_time'],
        'defaultDurationMinutes' => $t['default_duration_minutes'] !== null ? (int)$t['default_duration_minutes'] : null,
    ];
}

if ($action === 'save') {
    $id     = isset($body['id']) && $body['id'] ? (int)$body['id'] : null;
    $name   = substr(trim($body['name'] ?? ''), 0, 100);
    $desc   = isset($body['description']) ? substr(trim($body['description']), 0, 65000) : null;
    $style  = ($body['assignmentStyle'] ?? '') === 'combined' ? 'combined' : 'separate';
    $start  = $body['defaultStartTime'] ?? null;
    $dur    = isset($body['defaultDurationMinutes']) && $body['defaultDurationMinutes'] !== null ? (int)$body['defaultDurationMinutes'] : null;

    if ($desc === '') $desc = null;
    if ($start !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) $start = null;

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Name is required']);
        exit;
    }

    if ($id) {
        $stmt = $pdo->prepare('SELECT id FROM raid_templates WHERE id = ? AND guild_id = ?');
        $stmt->execute([$id, $tenant['id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE raid_templates SET name = ?, description = ?, default_start_time = ?, default_duration_minutes = ? WHERE id = ? AND guild_id = ?');
        $stmt->execute([$name, $desc, $start, $dur, $id, $tenant['id']]);
        // assignment_style is intentionally not editable once a template exists,
        // since it determines which section kinds are already in use in its structure.
    } else {
        $stmt = $pdo->prepare('INSERT INTO raid_templates (guild_id, name, description, assignment_style, default_start_time, default_duration_minutes) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$tenant['id'], $name, $desc, $style, $start, $dur]);
        $id = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('SELECT * FROM raid_templates WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'template' => template_to_json($stmt->fetch(PDO::FETCH_ASSOC))]);
    exit;
}

if ($action === 'delete') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $stmt = $pdo->prepare('SELECT id FROM raid_templates WHERE id = ? AND guild_id = ?');
    $stmt->execute([$id, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found']);
        exit;
    }
    // FK ON DELETE CASCADE clears any recurring slots pointing at it;
    // raids.template_id is ON DELETE SET NULL so past instances keep their snapshotted name.
    $stmt = $pdo->prepare('DELETE FROM raid_templates WHERE id = ? AND guild_id = ?');
    $stmt->execute([$id, $tenant['id']]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
