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

if ($action !== 'fetch') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$input = trim((string)($body['eventUrl'] ?? ''));
if (!preg_match('/(\d{15,})/', $input, $m)) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not find an event ID in that URL']);
    exit;
}
$eventId = $m[1];

$ch = curl_init("https://raid-helper.xyz/api/event/$eventId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$rhBody  = curl_exec($ch);
$rhErrno = curl_errno($ch);
$rhError = curl_error($ch);
$rhCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($rhBody === false || $rhErrno) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach Raid-Helper: ' . $rhError]);
    exit;
}
if ($rhCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => "Raid-Helper returned HTTP $rhCode"]);
    exit;
}
$data = json_decode($rhBody, true);
if ($data === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Raid-Helper returned unexpected data']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, main_name, class, discord_name, discord_id FROM toons WHERE guild_id = ? ORDER BY main_name');
$stmt->execute([$tenant['id']]);
$mainsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT id, main_id, name, class, discord_id FROM toon_alts WHERE guild_id = ?');
$stmt->execute([$tenant['id']]);
$altsByMain = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $altsByMain[$a['main_id']][] = $a;
}

$mains = array_map(fn($t) => [
    'id' => $t['id'], 'name' => $t['main_name'], 'class' => $t['class'],
    'discordName' => $t['discord_name'], 'discordId' => $t['discord_id'],
    'alts' => $altsByMain[$t['id']] ?? [],
], $mainsRaw);

const RH_SKIP_CLASSES = ['Absence'];
const RH_CLS_MAP = [
    'Warrior' => 'Warrior', 'Paladin' => 'Paladin', 'Druid' => 'Druid', 'Priest' => 'Priest',
    'Mage' => 'Mage', 'Warlock' => 'Warlock', 'Hunter' => 'Hunter', 'Rogue' => 'Rogue',
    'Shaman' => 'Shaman', 'Tank' => 'Warrior',
];

function find_match($mains, $userid, $discordName) {
    foreach ($mains as $m) {
        if ($m['discordId'] !== '' && (string)$m['discordId'] === (string)$userid) return ['main' => $m, 'directAlt' => null];
        foreach ($m['alts'] as $a) {
            if ($a['discord_id'] !== '' && (string)$a['discord_id'] === (string)$userid) return ['main' => $m, 'directAlt' => $a];
        }
    }
    foreach ($mains as $m) {
        if ($m['discordName'] !== '' && strtolower($m['discordName']) === $discordName) return ['main' => $m, 'directAlt' => null];
    }
    foreach ($mains as $m) {
        if (strtolower($m['name']) === $discordName) return ['main' => $m, 'directAlt' => null];
    }
    foreach ($mains as $m) {
        foreach ($m['alts'] as $a) {
            if (strtolower($a['name']) === $discordName) return ['main' => $m, 'directAlt' => null];
        }
    }
    $tokens = array_filter(preg_split('/\s+/', trim(preg_replace('/[^a-z0-9]/', ' ', $discordName))), fn($t) => strlen($t) >= 4);
    foreach ($mains as $m) {
        $mn    = strtolower($m['name']);
        $discN = preg_replace('/[^a-z0-9]/', '', strtolower($m['discordName']));
        if (strlen($mn) >= 4 && str_starts_with($discordName, $mn)) return ['main' => $m, 'directAlt' => null];
        foreach ($tokens as $tok) {
            if ($mn === $tok || str_starts_with($mn, $tok)) return ['main' => $m, 'directAlt' => null];
            if ($discN !== '' && $discN === $tok) return ['main' => $m, 'directAlt' => null];
            foreach ($m['alts'] as $a) {
                if (strtolower($a['name']) === $tok) return ['main' => $m, 'directAlt' => null];
            }
        }
    }
    return null;
}

$rows = [];
foreach (($data['signups'] ?? []) as $su) {
    $rawClass = $su['class'] ?? '';
    if (in_array($rawClass, RH_SKIP_CLASSES, true)) continue;

    $name          = $su['name'] ?? '';
    $userid        = $su['userid'] ?? '';
    $discordName   = mb_strtolower($name);
    $suClass       = RH_CLS_MAP[$rawClass] ?? $rawClass;

    $match = find_match($mains, $userid, $discordName);
    $row = [
        'name' => $name, 'userid' => $userid, 'rawClass' => $rawClass, 'spec' => $su['spec'] ?? '', 'role' => $su['role'] ?? '',
        'matched' => false, 'toonKind' => null, 'toonId' => null, 'toonName' => null, 'toonClass' => null,
        'suggestedPugClass' => RH_CLS_MAP[$rawClass] ?? null,
    ];

    if ($match) {
        $main = $match['main'];
        if ($match['directAlt']) {
            $chosen = ['kind' => 'alt', 'id' => $match['directAlt']['id'], 'name' => $match['directAlt']['name'], 'class' => $match['directAlt']['class']];
        } elseif ($main['class'] === $suClass) {
            $chosen = ['kind' => 'main', 'id' => $main['id'], 'name' => $main['name'], 'class' => $main['class']];
        } else {
            $altMatch = null;
            foreach ($main['alts'] as $a) { if ($a['class'] === $suClass) { $altMatch = $a; break; } }
            $chosen = $altMatch
                ? ['kind' => 'alt', 'id' => $altMatch['id'], 'name' => $altMatch['name'], 'class' => $altMatch['class']]
                : ['kind' => 'main', 'id' => $main['id'], 'name' => $main['name'], 'class' => $main['class']];
        }
        $row['matched']   = true;
        $row['toonKind']  = $chosen['kind'];
        $row['toonId']    = $chosen['id'];
        $row['toonName']  = $chosen['name'];
        $row['toonClass'] = $chosen['class'];
    }

    $rows[] = $row;
}

echo json_encode(['success' => true, 'rows' => $rows]);
