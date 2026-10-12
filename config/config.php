<?php
/**
 * Bootstrap only: output buffering, BASE_URL detection, timezone, error
 * handling, security headers, session setup. App-wide constants live in
 * constants.php, environment settings (debug, DB credentials) in app.php,
 * auth policy in auth.php.
 */

// Buffer all output for the whole request. Several pages render shared
// header/nav markup before a tab's own controller logic runs (e.g. login,
// apply) and that logic may still need to redirect() after a form post.
// Buffering means header() keeps working no matter how much HTML already
// echoed, regardless of the server's own output_buffering ini setting.
ob_start();

// e.g. http://localhost/virac-soloparent
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Strip a trailing /admin, /staff, or /applicant (the app's
    // one-level-deep script subfolders) so BASE_URL always points at the app root.
    $root = $script;
    if (in_array(basename($root), ['admin', 'staff', 'applicant'], true)) {
        $root = dirname($root);
    }
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    define('BASE_URL', $scheme . '://' . $host . $root);
}

date_default_timezone_set('Asia/Manila');

require __DIR__ . '/constants.php';
require __DIR__ . '/app.php';
require __DIR__ . '/errors.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/permissions.php';
require __DIR__ . '/settings.php';

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header_remove('X-Powered-By');
    // Framing, plugins, <base> hijacking and off-site form posts are refused outright.
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    // The camera is used only by the staff QR scanner, on this site.
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
}

// Keep PHP sessions in the application's protected storage. The XAMPP
// default C:\xampp\tmp can be unreadable to the web-server account, which
// breaks CSRF validation and logout POSTs.
$sessionPath = APP_ROOT . '/storage/sessions';
if (!is_dir($sessionPath) && !@mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create the application session directory.');
}
if (!is_writable($sessionPath) || session_save_path($sessionPath) === false) {
    throw new RuntimeException('The application session directory is not writable.');
}

// Each actor has an independent cookie, so tabs can keep all three accounts signed in.
// Folder routes select their own portal; only shared entry points accept a portal selector.
$sessionScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$sessionFolder = basename(dirname($sessionScript));
$sessionPortal = in_array($sessionFolder, ['admin', 'staff'], true) ? $sessionFolder : 'applicant';
if (in_array(basename($sessionScript), ['mswd-login.php', 'forgot-password.php', 'reset-password.php', 'slip.php', 'download.php', 'avatar.php'], true)) {
    $requestedPortal = $_GET['portal'] ?? '';
    if (is_string($requestedPortal) && in_array($requestedPortal, ['admin', 'staff'], true)) {
        $sessionPortal = $requestedPortal;
    } elseif (basename($sessionScript) === 'mswd-login.php') {
        $sessionPortal = 'staff';
    }
}
define('AUTH_PORTAL', $sessionPortal);

// 'secure' only when actually served over HTTPS -- forcing it on would
// stop the session cookie being sent at all on a plain-HTTP dev install
// (like this local XAMPP setup), silently breaking every login.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/') . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
if (session_status() === PHP_SESSION_NONE) {
    // Strict mode: PHP refuses session IDs it didn't issue itself (blocks
    // session fixation via a planted cookie); IDs only ever come from cookies.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ESPID_' . strtoupper(AUTH_PORTAL) . '_SESSION');
    session_start();
}
