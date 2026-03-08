<?php
/**
 * API endpoint for admin login sessions
 */
require_once '../config.php';

// Use admin module session.
$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit <= 0) {
    $limit = 50;
}
if ($limit > 250) {
    $limit = 250;
}

$logger = new Logger();
$sessions = $logger->getLoginSessions($limit);

$formatted = [];
foreach ($sessions as $s) {
    $formatted[] = [
        'id' => (int)($s['id'] ?? 0),
        'user_id' => isset($s['user_id']) ? (int)$s['user_id'] : null,
        'username' => $s['username'] ?? 'Unknown',
        'display_name' => $s['display_name'] ?? ($s['username'] ?? 'Unknown'),
        'user_role' => $s['user_role'] ?? null,
        'login_time' => $s['login_time'] ?? null,
        'logout_time' => $s['logout_time'] ?? null,
        'session_end_time' => $s['session_end_time'] ?? null,
        'end_type' => $s['end_type'] ?? 'active',
        'session_duration_minutes' => isset($s['session_duration_minutes']) ? (int)$s['session_duration_minutes'] : null,
        'formatted_login_time' => !empty($s['login_time']) ? Helper::formatDateTime($s['login_time'], 'M d, g:i:s A') : '',
        'formatted_end_time' => !empty($s['session_end_time']) ? Helper::formatDateTime($s['session_end_time'], 'M d, g:i:s A') : ''
    ];
}

echo json_encode([
    'success' => true,
    'sessions' => $formatted
]);
