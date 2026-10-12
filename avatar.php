<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';

// Only the account owner or MSWDO staff may view a profile photo. Same
// approach as download.php: login first, and "no photo" for anything the
// viewer isn't allowed to see.
$viewer = current_user($pdo);
$staff = current_staff($pdo);
if (!$viewer && !$staff) {
    http_response_code(403);
    die('Please log in to view this photo.');
}

$userId = (int) ($_GET['user_id'] ?? 0);
if (!$staff && (int) $viewer['id'] !== $userId) {
    http_response_code(404);
    die('No photo.');
}

$stmt = $pdo->prepare('SELECT id, profile_photo FROM users WHERE id = ?');
$stmt->execute([$userId]);
$target = $stmt->fetch();
if (!$target || !$target['profile_photo']) {
    http_response_code(404);
    die('No photo.');
}

$path = AVATAR_STORAGE_PATH . '/' . $target['profile_photo'];
if (!is_file($path)) {
    http_response_code(404);
    die('Photo is missing from storage.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
