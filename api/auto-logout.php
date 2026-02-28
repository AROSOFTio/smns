<?php
/**
 * Background auto-logout endpoint.
 * Supports:
 * - page_load: marks tab as active/cancels pending close logout
 * - browser_close: delayed logout, cancelled if page_load follows quickly (refresh/navigation)
 * - inactivity_timeout: immediate logout
 */
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

$module = strtolower(trim((string)($_POST['module'] ?? '')));
$reason = strtolower(trim((string)($_POST['reason'] ?? '')));
$token = trim((string)($_POST['token'] ?? ''));
$allowedModules = ['admin', 'student', 'lecturer', 'finance'];
$allowedReasons = ['page_load', 'browser_close', 'inactivity_timeout'];

if (!in_array($module, $allowedModules, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid module'
    ]);
    exit;
}

if (!in_array($reason, $allowedReasons, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid reason'
    ]);
    exit;
}

$presenceDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'smns_presence';
if (!is_dir($presenceDir)) {
    @mkdir($presenceDir, 0777, true);
}

function presenceKey(string $module, string $token): string {
    return hash('sha256', $module . '|' . $token);
}

function writePresenceMarker(string $path, float $value): void {
    @file_put_contents($path, sprintf('%.6f', $value), LOCK_EX);
}

function readPresenceMarker(string $path): float {
    if (!is_file($path)) {
        return 0.0;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return 0.0;
    }
    return (float)$raw;
}

if ($token === '' || strlen($token) < 16) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing token'
    ]);
    exit;
}

$key = presenceKey($module, $token);
$pendingFile = $presenceDir . DIRECTORY_SEPARATOR . 'pending_' . $key . '.dat';
$cancelFile = $presenceDir . DIRECTORY_SEPARATOR . 'cancel_' . $key . '.dat';

if ($reason === 'page_load') {
    writePresenceMarker($cancelFile, microtime(true));
    echo json_encode([
        'success' => true,
        'logged_out' => false,
        'cancelled' => true
    ]);
    exit;
}

if ($reason === 'browser_close') {
    $pendingAt = microtime(true);
    writePresenceMarker($pendingFile, $pendingAt);

    // Give refresh/navigation a short window to report page_load and cancel this logout.
    usleep(3000000);

    $cancelAt = readPresenceMarker($cancelFile);
    if ($cancelAt >= $pendingAt) {
        echo json_encode([
            'success' => true,
            'logged_out' => false,
            'cancelled' => true
        ]);
        exit;
    }
}

$auth = new Auth($module);
$loggedInKey = $module . '_logged_in';
$roleKey = $module . '_role';
$tokenKey = $module . '_session_token';
$isLoggedIn = !empty($_SESSION[$loggedInKey]) && (($_SESSION[$roleKey] ?? '') === $module);
$sessionToken = (string)($_SESSION[$tokenKey] ?? '');

// Ignore stale/mismatched tokens so old tabs or cross-host pages cannot log out active sessions.
if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
    echo json_encode([
        'success' => true,
        'logged_out' => false,
        'ignored' => true
    ]);
    exit;
}

if ($isLoggedIn) {
    $auth->logout();
    session_write_close();
    echo json_encode([
        'success' => true,
        'logged_out' => true
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'logged_out' => false
]);
exit;
