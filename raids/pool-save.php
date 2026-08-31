<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/class_roles.php';
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
        'SELECT p.id, p.toon_kind, p.toon_id, p.pug_name, p.pug_class, p.role, p.sort_order,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class,
                COALESCE(t.main_spec, a.main_spec) AS toon_spec,
                COALESCE(t.full_t2, a.full_t2) AS toon_full_t2
         FROM raid_pool p
         LEFT JOIN toons t ON p.toon_kind = \'main\' AND t.id = p.toon_id
         LEFT JOIN toon_alts a ON p.toon_kind = \'alt\' AND a.id = p.toon_id
         WHERE p.raid_id = ?
         ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([$raidId]);
    return array_map(function ($p) {
        $isPug = $p['toon_kind'] === 'pug';
        $class = $isPug ? $p['pug_class'] : $p['toon_class'];
        $spec  = $isPug ? null : $p['toon_spec'];
        return [
            'id'            => (int)$p['id'],
            'toonKind'      => $p['toon_kind'],
            'toonId'        => $p['toon_id'],
            'pugName'       => $p['pug_name'],
            'pugClass'      => $p['pug_class'],
            'name'          => $isPug ? $p['pug_name'] : $p['toon_name'],
            'class'         => $class,
            'spec'          => $spec,
            'fullT2'        => $isPug ? false : (bool)$p['toon_full_t2'],
            'role'          => $p['role'] ?: default_role_for_class($class, $spec),
            'roleConfirmed' => $p['role'] !== null,
            'sortOrder'     => (int)$p['sort_order'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Resolves a pool row's class straight from the DB (used to validate a caller-supplied
// role at write time, independent of whatever fetch_pool() has already computed).
function pool_item_class($pdo, $tenant, $toonKind, $toonId, $pugClass) {
    if ($toonKind === 'pug') return $pugClass;
    $table = $toonKind === 'main' ? 'toons' : 'toon_alts';
    $stmt = $pdo->prepare("SELECT class FROM $table WHERE id = ? AND guild_id = ?");
    $stmt->execute([$toonId, $tenant['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['class'] : null;
}

function pool_item_spec($pdo, $tenant, $toonKind, $toonId) {
    if ($toonKind !== 'main' && $toonKind !== 'alt') return null;
    $table = $toonKind === 'main' ? 'toons' : 'toon_alts';
    $stmt = $pdo->prepare("SELECT main_spec FROM $table WHERE id = ? AND guild_id = ?");
    $stmt->execute([$toonId, $tenant['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['main_spec'] : null;
}

// Validates/inserts a single pool item. Returns null on success, or an error string.
function add_pool_item($pdo, $tenant, $raidId, $toonKind, $toonId, $pugName, $pugClass, $role = null) {
    if ($toonKind === 'main') {
        $stmt = $pdo->prepare('SELECT id FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $tenant['id']]);
        if (!$stmt->fetch()) return 'Toon not found';
        $pugName = null; $pugClass = null;
    } elseif ($toonKind === 'alt') {
        $stmt = $pdo->prepare('SELECT id FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $tenant['id']]);
        if (!$stmt->fetch()) return 'Toon not found';
        $pugName = null; $pugClass = null;
    } elseif ($toonKind === 'pug') {
        $pugName = trim((string)$pugName);
        if ($pugName === '') return 'Pug name required';
        $pugName = substr($pugName, 0, 60);
        $pugClass = !empty($pugClass) ? substr(trim($pugClass), 0, 30) : null;
        $toonId = null;
    } else {
        return 'Invalid toonKind';
    }

    $class = $toonKind === 'pug' ? $pugClass : pool_item_class($pdo, $tenant, $toonKind, $toonId, $pugClass);
    if (!valid_role_for_class($class, $role)) $role = null;

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM raid_pool WHERE raid_id = ?');
    $stmt->execute([$raidId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO raid_pool (raid_id, toon_kind, toon_id, pug_name, pug_class, role, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $stmt->execute([$raidId, $toonKind, $toonId, $pugName, $pugClass, $role, $nextOrder]);
    return null;
}

if ($action === 'add') {
    $err = add_pool_item($pdo, $tenant, $raidId, $body['toonKind'] ?? '', $body['toonId'] ?? null, $body['pugName'] ?? null, $body['pugClass'] ?? null, $body['role'] ?? null);
    if ($err) { http_response_code(400); echo json_encode(['error' => $err]); exit; }
} elseif ($action === 'bulkAdd') {
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    $results = [];
    foreach ($items as $item) {
        $err = add_pool_item($pdo, $tenant, $raidId, $item['toonKind'] ?? '', $item['toonId'] ?? null, $item['pugName'] ?? null, $item['pugClass'] ?? null, $item['role'] ?? null);
        $results[] = ['ok' => $err === null, 'error' => $err];
    }
    echo json_encode(['success' => true, 'results' => $results, 'pool' => fetch_pool($pdo, $raidId)]);
    exit;
} elseif ($action === 'remove') {
    $poolId = isset($body['poolId']) ? (int)$body['poolId'] : 0;
    $stmt = $pdo->prepare('DELETE FROM raid_pool WHERE id = ? AND raid_id = ?');
    $stmt->execute([$poolId, $raidId]);
} elseif ($action === 'reorder') {
    $orderedIds = array_map('intval', $body['orderedIds'] ?? []);
    $stmt = $pdo->prepare('UPDATE raid_pool SET sort_order = ? WHERE id = ? AND raid_id = ?');
    foreach ($orderedIds as $i => $id) $stmt->execute([$i, $id, $raidId]);
} elseif ($action === 'switchToon') {
    // Alt-click on a pool chip picks a sibling toon (same real player) -- swaps which
    // toon this pool row points at without disturbing its sort_order or removing/re-adding it.
    $poolId = isset($body['poolId']) ? (int)$body['poolId'] : 0;
    $toonKind = $body['toonKind'] ?? '';
    $toonId = $body['toonId'] ?? null;
    if ($toonKind !== 'main' && $toonKind !== 'alt') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid toonKind']);
        exit;
    }
    $table = $toonKind === 'main' ? 'toons' : 'toon_alts';
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = ? AND guild_id = ?");
    $stmt->execute([$toonId, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Toon not found']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT role FROM raid_pool WHERE id = ? AND raid_id = ?');
    $stmt->execute([$poolId, $raidId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Pool item not found']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM raid_pool WHERE raid_id = ? AND toon_kind = ? AND toon_id = ? AND id != ?');
    $stmt->execute([$raidId, $toonKind, $toonId, $poolId]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'That toon is already in the pool']);
        exit;
    }
    $class = pool_item_class($pdo, $tenant, $toonKind, $toonId, null);
    $role = valid_role_for_class($class, $row['role']) ? $row['role'] : null;
    $stmt = $pdo->prepare('UPDATE raid_pool SET toon_kind = ?, toon_id = ?, pug_name = NULL, pug_class = NULL, role = ? WHERE id = ? AND raid_id = ?');
    $stmt->execute([$toonKind, $toonId, $role, $poolId, $raidId]);
} elseif ($action === 'setRole') {
    $poolId = isset($body['poolId']) ? (int)$body['poolId'] : 0;
    $stmt = $pdo->prepare('SELECT toon_kind, toon_id, pug_class, role FROM raid_pool WHERE id = ? AND raid_id = ?');
    $stmt->execute([$poolId, $raidId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Pool item not found']);
        exit;
    }
    $class = pool_item_class($pdo, $tenant, $row['toon_kind'], $row['toon_id'], $row['pug_class']);
    $spec = pool_item_spec($pdo, $tenant, $row['toon_kind'], $row['toon_id']);
    $current = $row['role'] ?: default_role_for_class($class, $spec);
    $next = cycle_role_for_class($class, $current);
    $stmt = $pdo->prepare('UPDATE raid_pool SET role = ? WHERE id = ? AND raid_id = ?');
    $stmt->execute([$next, $poolId, $raidId]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

echo json_encode(['success' => true, 'pool' => fetch_pool($pdo, $raidId)]);
