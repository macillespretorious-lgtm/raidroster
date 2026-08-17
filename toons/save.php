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
if (!role_at_least($role, 'roster_management')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$toons = json_decode(file_get_contents('php://input'), true);
if (!is_array($toons)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

function clean_toon_entry($t, $isAlt) {
    $out = [
        'id'          => substr((string)($t['id'] ?? ''), 0, 64),
        'discordName' => substr(trim($t['discordName'] ?? ''), 0, 100),
        'discordId'   => substr(trim($t['discordId'] ?? ''), 0, 100),
        'class'       => substr(trim($t['class'] ?? ''), 0, 30),
        'mainSpec'    => substr(trim($t['mainSpec'] ?? ''), 0, 30),
        'altSpec'     => substr(trim($t['altSpec'] ?? ''), 0, 30),
        'status'      => in_array($t['status'] ?? '', ['Main', 'Alt', 'Pug'], true) ? $t['status'] : 'Main',
        'server'      => substr(trim($t['server'] ?? ''), 0, 60),
        'fullT2'      => !empty($t['fullT2']),
    ];
    if ($isAlt) {
        $out['name'] = substr(trim($t['name'] ?? ''), 0, 60);
    } else {
        $out['mainName'] = substr(trim($t['mainName'] ?? ''), 0, 60);
        $out['rank']     = in_array($t['rank'] ?? '', ['raider', 'trial', 'social', 'pug'], true) ? $t['rank'] : 'raider';
        $out['alts']     = [];
        foreach (($t['alts'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $out['alts'][] = clean_toon_entry($a, true);
        }
    }
    return $out;
}

$clean = [];
foreach ($toons as $t) {
    if (!is_array($t) || empty($t['id']) || empty($t['mainName'])) continue;
    $clean[] = clean_toon_entry($t, false);
}

$pdo = db_connect();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('DELETE FROM toon_alts WHERE guild_id = ?');
    $stmt->execute([$tenant['id']]);
    $stmt = $pdo->prepare('DELETE FROM toons WHERE guild_id = ?');
    $stmt->execute([$tenant['id']]);

    $insMain = $pdo->prepare(
        'INSERT INTO toons (id, guild_id, main_name, discord_name, discord_id, class, main_spec, alt_spec, status, server, full_t2, rank)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insAlt = $pdo->prepare(
        'INSERT INTO toon_alts (id, main_id, guild_id, name, discord_name, discord_id, class, main_spec, alt_spec, status, server, full_t2)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($clean as $t) {
        $insMain->execute([
            $t['id'], $tenant['id'], $t['mainName'], $t['discordName'], $t['discordId'],
            $t['class'], $t['mainSpec'], $t['altSpec'], $t['status'], $t['server'], $t['fullT2'] ? 1 : 0, $t['rank'],
        ]);
        foreach ($t['alts'] as $a) {
            $insAlt->execute([
                $a['id'], $t['id'], $tenant['id'], $a['name'], $a['discordName'], $a['discordId'],
                $a['class'], $a['mainSpec'], $a['altSpec'], $a['status'], $a['server'], $a['fullT2'] ? 1 : 0,
            ]);
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Save failed']);
    exit;
}

echo json_encode(['success' => true]);
