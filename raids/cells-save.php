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
$action = $body['action'] ?? 'assign';

function fetch_cell_owned($pdo, $cellId, $guildId) {
    $stmt = $pdo->prepare(
        'SELECT c.id FROM raid_cells c
         JOIN raid_tables tb ON tb.id = c.table_id
         JOIN raid_sections s ON s.id = tb.section_id
         JOIN raids r ON r.id = s.raid_id
         WHERE c.id = ? AND r.guild_id = ?'
    );
    $stmt->execute([$cellId, $guildId]);
    return $stmt->fetch();
}

// Resolves a client-supplied toon reference against the guild's own tables so a raid_cells
// row can never be stamped with another guild's toon via a forged id. Returns null-fields
// (an empty slot) for anything that doesn't resolve, including an explicit clear.
function resolve_toon_fields($pdo, $guildId, $toonKind, $toonId, $pugName, $pugClass) {
    if ($toonKind === 'main' && $toonId) {
        $stmt = $pdo->prepare('SELECT id FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        if ($stmt->fetch()) return ['toon_kind' => 'main', 'toon_id' => $toonId, 'pug_name' => null, 'pug_class' => null];
    } elseif ($toonKind === 'alt' && $toonId) {
        $stmt = $pdo->prepare('SELECT id FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        if ($stmt->fetch()) return ['toon_kind' => 'alt', 'toon_id' => $toonId, 'pug_name' => null, 'pug_class' => null];
    } elseif ($toonKind === 'pug') {
        $name = trim((string)$pugName);
        if ($name !== '') return ['toon_kind' => 'pug', 'toon_id' => null, 'pug_name' => substr($name, 0, 60), 'pug_class' => $pugClass ? substr(trim($pugClass), 0, 30) : null];
    }
    return ['toon_kind' => 'main', 'toon_id' => null, 'pug_name' => null, 'pug_class' => null];
}

function fetch_cell_out($pdo, $cellId) {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.toon_id, c.toon_kind, c.pug_name, c.pug_class, c.note,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class
         FROM raid_cells c
         LEFT JOIN toons t ON c.toon_kind = \'main\' AND t.id = c.toon_id
         LEFT JOIN toon_alts a ON c.toon_kind = \'alt\' AND a.id = c.toon_id
         WHERE c.id = ?'
    );
    $stmt->execute([$cellId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $isPug = $c['toon_kind'] === 'pug';
    return [
        'id'       => (int)$c['id'],
        'toonKind' => $c['toon_kind'],
        'toonId'   => $c['toon_id'],
        'pugName'  => $c['pug_name'],
        'pugClass' => $c['pug_class'],
        'name'     => $isPug ? $c['pug_name'] : $c['toon_name'],
        'class'    => $isPug ? $c['pug_class'] : $c['toon_class'],
        'note'     => $c['note'],
    ];
}

if ($action === 'move') {
    $fromCellId = isset($body['fromCellId']) ? (int)$body['fromCellId'] : 0;
    $toCellId   = isset($body['toCellId']) ? (int)$body['toCellId'] : 0;
    if (!fetch_cell_owned($pdo, $fromCellId, $tenant['id']) || !fetch_cell_owned($pdo, $toCellId, $tenant['id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT toon_id, toon_kind, pug_name, pug_class FROM raid_cells WHERE id = ? FOR UPDATE');
    $stmt->execute([$fromCellId]);
    $from = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->execute([$toCellId]);
    $to = $stmt->fetch(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ? WHERE id = ?');
    $upd->execute([$to['toon_id'], $to['toon_kind'], $to['pug_name'], $to['pug_class'], $fromCellId]);
    $upd->execute([$from['toon_id'], $from['toon_kind'], $from['pug_name'], $from['pug_class'], $toCellId]);
    $pdo->commit();

    echo json_encode(['success' => true, 'from' => fetch_cell_out($pdo, $fromCellId), 'to' => fetch_cell_out($pdo, $toCellId)]);
    exit;
}

// default: assign
$cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
if (!fetch_cell_owned($pdo, $cellId, $tenant['id'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Cell not found']);
    exit;
}
$note = isset($body['note']) && $body['note'] !== '' ? substr(trim($body['note']), 0, 60) : null;
$resolved = resolve_toon_fields($pdo, $tenant['id'], $body['toonKind'] ?? null, $body['toonId'] ?? null, $body['pugName'] ?? null, $body['pugClass'] ?? null);

$stmt = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ?, note = ? WHERE id = ?');
$stmt->execute([$resolved['toon_id'], $resolved['toon_kind'], $resolved['pug_name'], $resolved['pug_class'], $note, $cellId]);

echo json_encode(['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)]);
