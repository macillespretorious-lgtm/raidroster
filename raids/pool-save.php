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
$action = $body['action'] ?? '';

$raidId = isset($body['raidId']) ? (int)$body['raidId'] : 0;
$stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
$stmt->execute([$raidId, $tenant['id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Raid not found']);
    exit;
}

function fetch_pool($pdo, $raidId) {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.toon_kind, p.toon_id, p.pug_name, p.pug_class, p.sort_order,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class
         FROM raid_pool p
         LEFT JOIN toons t ON p.toon_kind = \'main\' AND t.id = p.toon_id
         LEFT JOIN toon_alts a ON p.toon_kind = \'alt\' AND a.id = p.toon_id
         WHERE p.raid_id = ?
         ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([$raidId]);
    return array_map(function ($p) {
        $isPug = $p['toon_kind'] === 'pug';
        return [
            'id'        => (int)$p['id'],
            'toonKind'  => $p['toon_kind'],
            'toonId'    => $p['toon_id'],
            'pugName'   => $p['pug_name'],
            'pugClass'  => $p['pug_class'],
            'name'      => $isPug ? $p['pug_name'] : $p['toon_name'],
            'class'     => $isPug ? $p['pug_class'] : $p['toon_class'],
            'sortOrder' => (int)$p['sort_order'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($action === 'add') {
    $toonKind = $body['toonKind'] ?? '';
    $toonId   = isset($body['toonId']) ? (string)$body['toonId'] : null;

    if ($toonKind === 'main') {
        $stmt = $pdo->prepare('SELECT id FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $tenant['id']]);
        if (!$stmt->fetch()) { http_response_code(400); echo json_encode(['error' => 'Toon not found']); exit; }
        $pugName = null; $pugClass = null;
    } elseif ($toonKind === 'alt') {
        $stmt = $pdo->prepare('SELECT id FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $tenant['id']]);
        if (!$stmt->fetch()) { http_response_code(400); echo json_encode(['error' => 'Toon not found']); exit; }
        $pugName = null; $pugClass = null;
    } elseif ($toonKind === 'pug') {
        $pugName = trim((string)($body['pugName'] ?? ''));
        if ($pugName === '') { http_response_code(400); echo json_encode(['error' => 'Pug name required']); exit; }
        $pugName = substr($pugName, 0, 60);
        $pugClass = !empty($body['pugClass']) ? substr(trim($body['pugClass']), 0, 30) : null;
        $toonId = null;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid toonKind']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM raid_pool WHERE raid_id = ?');
    $stmt->execute([$raidId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO raid_pool (raid_id, toon_kind, toon_id, pug_name, pug_class, sort_order) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $stmt->execute([$raidId, $toonKind, $toonId, $pugName, $pugClass, $nextOrder]);
} elseif ($action === 'remove') {
    $poolId = isset($body['poolId']) ? (int)$body['poolId'] : 0;
    $stmt = $pdo->prepare('DELETE FROM raid_pool WHERE id = ? AND raid_id = ?');
    $stmt->execute([$poolId, $raidId]);
} elseif ($action === 'reorder') {
    $orderedIds = array_map('intval', $body['orderedIds'] ?? []);
    $stmt = $pdo->prepare('UPDATE raid_pool SET sort_order = ? WHERE id = ? AND raid_id = ?');
    foreach ($orderedIds as $i => $id) $stmt->execute([$i, $id, $raidId]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

echo json_encode(['success' => true, 'pool' => fetch_pool($pdo, $raidId)]);
