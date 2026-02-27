<?php
require_once '../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = [];
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (empty($payload) && !empty($_POST)) {
    $payload = $_POST;
}

$headers = [];
if (function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    if (is_array($allHeaders)) {
        $headers = $allHeaders;
    }
}
if (empty($headers)) {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $h = str_replace('_', '-', strtolower(substr($key, 5)));
            $headers[$h] = $value;
        }
    }
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $service = new MobileMoneyGatewayService($conn);
    $result = $service->handleWebhook((array)$payload, (array)$headers, (string)$rawBody);

    $statusCode = (int)($result['status_code'] ?? (!empty($result['success']) ? 200 : 400));
    if ($statusCode < 100 || $statusCode > 599) {
        $statusCode = !empty($result['success']) ? 200 : 400;
    }
    http_response_code($statusCode);

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Webhook endpoint failure.',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ]);
}

