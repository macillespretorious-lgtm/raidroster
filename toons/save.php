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

guild_save($tenant['id'], 'toons', json_encode($clean));
echo json_encode(['success' => true]);
