<?php
/**
 * Public uptime probe endpoint.
 * Returns 200 when core runtime and DB are available.
 */
require_once '../../config.php';

$startedAt = microtime(true);
$checks = [];
$httpCode = 200;
$overall = 'ok';

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->query('SELECT 1');
    $checks['database'] = ['status' => 'pass', 'message' => 'Database reachable'];
} catch (Exception $e) {
    $checks['database'] = ['status' => 'fail', 'message' => 'Database unavailable'];
    $httpCode = 503;
    $overall = 'down';
}

try {
    $uploadPath = defined('UPLOAD_PATH') ? (string)UPLOAD_PATH : (BASE_PATH . '/uploads');
    $checks['filesystem'] = [
        'status' => is_dir($uploadPath) ? 'pass' : 'warning',
        'message' => is_dir($uploadPath) ? 'Uploads directory present' : 'Uploads directory missing'
    ];
    if (!is_dir($uploadPath) && $overall === 'ok') {
        $overall = 'degraded';
    }
} catch (Exception $e) {
    $checks['filesystem'] = ['status' => 'warning', 'message' => 'Filesystem check skipped'];
    if ($overall === 'ok') {
        $overall = 'degraded';
    }
}

$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

http_response_code($httpCode);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => ($httpCode === 200),
    'status' => $overall,
    'service' => APP_NAME,
    'version' => defined('APP_VERSION') ? APP_VERSION : null,
    'timestamp' => gmdate('c'),
    'response_time_ms' => $elapsedMs,
    'checks' => $checks,
]);

