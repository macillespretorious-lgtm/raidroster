<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

require_role($tenant, 'admin');

function branding_redirect($slug, $status) {
    header('Location: /' . $slug . '/admin?branding=' . $status . '#branding');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    branding_redirect($slug, 'error');
}

$MAX_BYTES = 2 * 1024 * 1024;
$ALLOWED = [
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_WEBP => 'webp',
];

$dir = __DIR__ . '/../uploads/guilds/' . (int)$tenant['id'];
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$pdo     = db_connect();
$updates = [];

if (isset($_POST['faction']) && in_array($_POST['faction'], ['Alliance', 'Horde'], true)) {
    $updates['faction'] = $_POST['faction'];
}

foreach (['logo', 'banner'] as $field) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        branding_redirect($slug, 'error');
    }
    if ($file['size'] > $MAX_BYTES) {
        branding_redirect($slug, 'toolarge');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !isset($ALLOWED[$info[2]])) {
        branding_redirect($slug, 'badtype');
    }
    $ext = $ALLOWED[$info[2]];

    foreach (glob($dir . '/' . $field . '.*') as $old) {
        @unlink($old);
    }

    $dest = $dir . '/' . $field . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        branding_redirect($slug, 'error');
    }

    $updates[$field . '_path'] = 'uploads/guilds/' . (int)$tenant['id'] . '/' . $field . '.' . $ext;
}

if ($updates) {
    $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($updates)));
    $stmt = $pdo->prepare("UPDATE guilds SET $set WHERE id = ?");
    $stmt->execute([...array_values($updates), $tenant['id']]);
}

branding_redirect($slug, $updates ? 'ok' : 'empty');
