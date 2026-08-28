<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/raid_fetch.php';
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
$action = $body['action'] ?? 'assign';

function fetch_cell_owned($pdo, $cellId, $guildId) {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.table_id, c.row_id, c.column_id FROM raid_cells c
         JOIN raid_tables tb ON tb.id = c.table_id
         JOIN raid_sections s ON s.id = tb.section_id
         JOIN raids r ON r.id = s.raid_id
         WHERE c.id = ? AND r.guild_id = ?'
    );
    $stmt->execute([$cellId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_toon_class($pdo, $guildId, $toonKind, $toonId) {
    if ($toonKind === 'main' && $toonId) {
        $stmt = $pdo->prepare('SELECT class FROM toons WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['class'] : null;
    }
    if ($toonKind === 'alt' && $toonId) {
        $stmt = $pdo->prepare('SELECT class FROM toon_alts WHERE id = ? AND guild_id = ?');
        $stmt->execute([$toonId, $guildId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['class'] : null;
    }
    return null;
}

function toon_class_for($pdo, $guildId, $toonKind, $toonId, $pugClass) {
    if ($toonKind === 'pug') return $pugClass;
    return fetch_toon_class($pdo, $guildId, $toonKind, $toonId);
}

// Checks class_restrict/max_count raid_rules scoped to (tableId, rowId, columnId) against the
// toon about to occupy that cell. $excludeCellIds keeps a toon's own current cell(s) -- the one
// being reassigned, or both sides of a move -- out of its own max_count tally.
function rule_violation($pdo, $tableId, $rowId, $columnId, $toonKind, $toonId, $toonClass, array $excludeCellIds) {
    if (!$toonKind || (!$toonId && $toonKind !== 'pug')) return null; // clearing a cell never violates a rule

    $stmt = $pdo->prepare(
        "SELECT r.* FROM raid_rules r
         WHERE r.table_id = ?
           AND (r.scope = 'table' OR EXISTS (
               SELECT 1 FROM raid_rule_cells rc WHERE rc.rule_id = r.id AND rc.row_id = ? AND rc.column_id = ?
           ))"
    );
    $stmt->execute([$tableId, $rowId, $columnId]);
    $excludePlaceholders = implode(',', array_fill(0, count($excludeCellIds), '?'));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        if ($rule['rule_type'] === 'class_restrict') {
            $allowed = array_map('strtolower', array_map('trim', explode(',', (string)$rule['classes'])));
            $cls = strtolower(trim((string)$toonClass));
            if ($cls === '' || !in_array($cls, $allowed, true)) {
                return $rule['label'] ?: ('Only ' . $rule['classes'] . ' may be assigned here');
            }
        } elseif ($rule['rule_type'] === 'max_count') {
            if ($toonKind === 'pug') continue; // no stable identity to count against
            $max = $rule['max_count'] !== null ? (int)$rule['max_count'] : 1;
            if ($rule['scope'] === 'table') {
                $sql = "SELECT COUNT(*) FROM raid_cells WHERE table_id = ? AND toon_kind = ? AND toon_id = ? AND id NOT IN ($excludePlaceholders)";
                $params = array_merge([$tableId, $toonKind, $toonId], $excludeCellIds);
            } else {
                $sql = "SELECT COUNT(*) FROM raid_cells c
                        JOIN raid_rule_cells rc ON rc.row_id = c.row_id AND rc.column_id = c.column_id
                        WHERE rc.rule_id = ? AND c.table_id = ? AND c.toon_kind = ? AND c.toon_id = ? AND c.id NOT IN ($excludePlaceholders)";
                $params = array_merge([$rule['id'], $tableId, $toonKind, $toonId], $excludeCellIds);
            }
            $stmtCnt = $pdo->prepare($sql);
            $stmtCnt->execute($params);
            $count = (int)$stmtCnt->fetchColumn();
            if ($count >= $max) {
                return $rule['label'] ?: ('This toon can only be assigned ' . $max . ' time' . ($max === 1 ? '' : 's') . ' here');
            }
        }
    }
    return null;
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
        'SELECT c.id, c.toon_id, c.toon_kind, c.pug_name, c.pug_class, c.role, c.marked,
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
    $class = $isPug ? $c['pug_class'] : $c['toon_class'];
    return [
        'id'            => (int)$c['id'],
        'toonKind'      => $c['toon_kind'],
        'toonId'        => $c['toon_id'],
        'pugName'       => $c['pug_name'],
        'pugClass'      => $c['pug_class'],
        'name'          => $isPug ? $c['pug_name'] : $c['toon_name'],
        'class'         => $class,
        'role'          => $class ? ($c['role'] ?: default_role_for_class($class)) : null,
        'roleConfirmed' => $c['role'] !== null,
        'marked'        => (bool)$c['marked'],
    ];
}

if ($action === 'move') {
    $fromCellId = isset($body['fromCellId']) ? (int)$body['fromCellId'] : 0;
    $toCellId   = isset($body['toCellId']) ? (int)$body['toCellId'] : 0;
    $fromCell = fetch_cell_owned($pdo, $fromCellId, $tenant['id']);
    $toCell   = fetch_cell_owned($pdo, $toCellId, $tenant['id']);
    if (!$fromCell || !$toCell) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT toon_id, toon_kind, pug_name, pug_class, role FROM raid_cells WHERE id = ? FOR UPDATE');
    $stmt->execute([$fromCellId]);
    $from = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->execute([$toCellId]);
    $to = $stmt->fetch(PDO::FETCH_ASSOC);

    // 'to's occupant is moving into fromCell, and vice versa -- check each destination against
    // the toon that's actually landing there, excluding both cells from the max_count tally.
    $toClass = toon_class_for($pdo, $tenant['id'], $to['toon_kind'], $to['toon_id'], $to['pug_class']);
    $fromClass = toon_class_for($pdo, $tenant['id'], $from['toon_kind'], $from['toon_id'], $from['pug_class']);

    $violation = rule_violation($pdo, $fromCell['table_id'], $fromCell['row_id'], $fromCell['column_id'], $to['toon_kind'], $to['toon_id'], $toClass, [$fromCellId, $toCellId])
        ?? rule_violation($pdo, $toCell['table_id'], $toCell['row_id'], $toCell['column_id'], $from['toon_kind'], $from['toon_id'], $fromClass, [$fromCellId, $toCellId]);
    if ($violation) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['error' => $violation]);
        exit;
    }

    $upd = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ?, role = ? WHERE id = ?');
    $upd->execute([$to['toon_id'], $to['toon_kind'], $to['pug_name'], $to['pug_class'], $to['role'], $fromCellId]);
    $upd->execute([$from['toon_id'], $from['toon_kind'], $from['pug_name'], $from['pug_class'], $from['role'], $toCellId]);
    $pdo->commit();

    echo json_encode(['success' => true, 'from' => fetch_cell_out($pdo, $fromCellId), 'to' => fetch_cell_out($pdo, $toCellId)]);
    exit;
}

if ($action === 'clear_section') {
    $sectionId = isset($body['sectionId']) ? (int)$body['sectionId'] : 0;
    $stmt = $pdo->prepare(
        'SELECT s.id, s.raid_id FROM raid_sections s JOIN raids r ON r.id = s.raid_id WHERE s.id = ? AND r.guild_id = ?'
    );
    $stmt->execute([$sectionId, $tenant['id']]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$section) {
        http_response_code(404);
        echo json_encode(['error' => 'Section not found']);
        exit;
    }

    $stmtT = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id = ?');
    $stmtT->execute([$sectionId]);
    $tableIds = [];
    foreach ($stmtT->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        collect_table_ids($pdo, $tid, $tableIds);
    }
    clear_cells_for_tables($pdo, $tableIds);

    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $section['raid_id'])]);
    exit;
}

if ($action === 'clear_all') {
    $raidId = isset($body['raidId']) ? (int)$body['raidId'] : 0;
    $stmt = $pdo->prepare('SELECT id FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$raidId, $tenant['id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Raid not found']);
        exit;
    }

    $stmtS = $pdo->prepare('SELECT id FROM raid_sections WHERE raid_id = ?');
    $stmtS->execute([$raidId]);
    $tableIds = [];
    foreach ($stmtS->fetchAll(PDO::FETCH_COLUMN) as $sectionId) {
        $stmtT = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id = ?');
        $stmtT->execute([$sectionId]);
        foreach ($stmtT->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            collect_table_ids($pdo, $tid, $tableIds);
        }
    }
    clear_cells_for_tables($pdo, $tableIds);

    echo json_encode(['success' => true, 'sections' => fetch_raid_structure($pdo, $raidId)]);
    exit;
}

if ($action === 'mark') {
    $cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
    if (!fetch_cell_owned($pdo, $cellId, $tenant['id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $marked = !empty($body['marked']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE raid_cells SET marked = ? WHERE id = ?');
    $stmt->execute([$marked, $cellId]);
    echo json_encode(['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)]);
    exit;
}

if ($action === 'setRole') {
    $cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
    if (!fetch_cell_owned($pdo, $cellId, $tenant['id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Cell not found']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT toon_kind, toon_id, pug_class, role FROM raid_cells WHERE id = ?');
    $stmt->execute([$cellId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $class = toon_class_for($pdo, $tenant['id'], $row['toon_kind'], $row['toon_id'], $row['pug_class']);
    $current = $row['role'] ?: default_role_for_class($class);
    $next = $class ? cycle_role_for_class($class, $current) : null;
    $stmt = $pdo->prepare('UPDATE raid_cells SET role = ? WHERE id = ?');
    $stmt->execute([$next, $cellId]);
    echo json_encode(['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)]);
    exit;
}

// default: assign
$cellId = isset($body['cellId']) ? (int)$body['cellId'] : 0;
$cell = fetch_cell_owned($pdo, $cellId, $tenant['id']);
if (!$cell) {
    http_response_code(404);
    echo json_encode(['error' => 'Cell not found']);
    exit;
}
$resolved = resolve_toon_fields($pdo, $tenant['id'], $body['toonKind'] ?? null, $body['toonId'] ?? null, $body['pugName'] ?? null, $body['pugClass'] ?? null);

$toonClass = toon_class_for($pdo, $tenant['id'], $resolved['toon_kind'], $resolved['toon_id'], $resolved['pug_class']);
$violation = rule_violation($pdo, $cell['table_id'], $cell['row_id'], $cell['column_id'], $resolved['toon_kind'], $resolved['toon_id'], $toonClass, [$cellId]);
if ($violation) {
    http_response_code(422);
    echo json_encode(['error' => $violation]);
    exit;
}

$role = array_key_exists('role', $body) ? $body['role'] : null;
if (!valid_role_for_class($toonClass, $role)) $role = null;

$stmt = $pdo->prepare('UPDATE raid_cells SET toon_id = ?, toon_kind = ?, pug_name = ?, pug_class = ?, role = ? WHERE id = ?');
$stmt->execute([$resolved['toon_id'], $resolved['toon_kind'], $resolved['pug_name'], $resolved['pug_class'], $role, $cellId]);

echo json_encode(['success' => true, 'cell' => fetch_cell_out($pdo, $cellId)]);
