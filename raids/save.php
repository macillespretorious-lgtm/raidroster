<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/raid_structure.php';
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

function raid_to_json($r) {
    return [
        'id'              => (int)$r['id'],
        'date'            => $r['raid_date'],
        'startTime'       => $r['start_time'],
        'durationMinutes' => $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
        'templateId'      => $r['template_id'] !== null ? (int)$r['template_id'] : null,
        'name'            => $r['name'],
        'description'     => $r['description'],
        'status'          => $r['status'],
        'createdVia'      => $r['created_via'],
        'size'            => $r['size'],
    ];
}

function fetch_raid($pdo, $guildId, $id) {
    $stmt = $pdo->prepare('SELECT * FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$id, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($action === 'save') {
    $id     = isset($body['id']) && $body['id'] ? (int)$body['id'] : null;
    $date   = $body['date'] ?? '';
    $name   = substr(trim($body['name'] ?? ''), 0, 100);
    $start  = $body['startTime'] ?? null;
    $dur    = isset($body['durationMinutes']) && $body['durationMinutes'] !== null ? (int)$body['durationMinutes'] : null;
    $desc   = isset($body['description']) ? substr(trim($body['description']), 0, 65000) : null;
    $tmplId = isset($body['templateId']) && $body['templateId'] ? (int)$body['templateId'] : null;
    $size   = ($body['size'] ?? '40') === '20' ? '20' : '40';

    if ($start !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) $start = null;
    if ($desc === '') $desc = null;

    if (!$name || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid raid submission']);
        exit;
    }

    if ($tmplId !== null) {
        // The template's own stored size is authoritative once a template is picked -- never
        // trust the client's separately-sent size for the numCols math below.
        $stmt = $pdo->prepare('SELECT id, size FROM raid_templates WHERE id = ? AND guild_id = ?');
        $stmt->execute([$tmplId, $tenant['id']]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) { $tmplId = null; } else { $size = $tpl['size']; }
    }

    if ($id) {
        $existing = fetch_raid($pdo, $tenant['id'], $id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Raid not found']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE raids SET name = ?, start_time = ?, duration_minutes = ?, description = ? WHERE id = ? AND guild_id = ?');
        $stmt->execute([$name, $start, $dur, $desc, $id, $tenant['id']]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO raids (guild_id, raid_date, start_time, duration_minutes, template_id, name, description, status, created_via, size)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'scheduled\', \'manual\', ?)'
        );
        $stmt->execute([$tenant['id'], $date, $start, $dur, $tmplId, $name, $desc, $size]);
        $id = (int)$pdo->lastInsertId();
        if ($tmplId) {
            copy_template_structure_to_raid($pdo, $tmplId, $id);
        }
        // Runs for template-based AND blank raids alike -- force-fills a roster/benched pair
        // if the template didn't already provide one (or provides none at all for a blank raid).
        ensure_starting_roster($pdo, $id, $size);
    }

    echo json_encode(['success' => true, 'raid' => raid_to_json(fetch_raid($pdo, $tenant['id'], $id))]);
    exit;
}

if ($action === 'cancel' || $action === 'restore') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $existing = fetch_raid($pdo, $tenant['id'], $id);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Raid not found']);
        exit;
    }
    $newStatus = $action === 'cancel' ? 'cancelled' : 'scheduled';
    $stmt = $pdo->prepare('UPDATE raids SET status = ? WHERE id = ? AND guild_id = ?');
    $stmt->execute([$newStatus, $id, $tenant['id']]);
    echo json_encode(['success' => true, 'raid' => raid_to_json(fetch_raid($pdo, $tenant['id'], $id))]);
    exit;
}

if ($action === 'delete') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $existing = fetch_raid($pdo, $tenant['id'], $id);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Raid not found']);
        exit;
    }
    if ($existing['status'] !== 'cancelled') {
        http_response_code(400);
        echo json_encode(['error' => 'Only cancelled raids can be deleted']);
        exit;
    }
    // Structural child tables (raid_sections/tables/columns/rows/column_groups/cells/
    // cell_merges) all cascade from raids.id via ON DELETE CASCADE FKs (verified live).
    $stmt = $pdo->prepare('DELETE FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$id, $tenant['id']]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
