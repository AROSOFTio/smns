<?php
require_once '../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$auth = new Auth('student');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = [];
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$referenceNumber = strtoupper(trim((string)(
    $_GET['reference_number']
    ?? $_GET['prn']
    ?? $_POST['reference_number']
    ?? $_POST['prn']
    ?? ($jsonBody['reference_number'] ?? ($jsonBody['prn'] ?? ''))
)));

if ($referenceNumber === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'PRN is required.']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$studentId = (int)($studentProfile['id'] ?? 0);
if ($studentId <= 0) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $studentStmt = $conn->prepare("SELECT id FROM students WHERE user_id = :user_id LIMIT 1");
        $studentStmt->execute(['user_id' => (int)($currentUser['id'] ?? 0)]);
        $studentId = (int)$studentStmt->fetchColumn();
    } catch (Exception $e) {
        $studentId = 0;
    }
}

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student profile was not found.']);
    exit;
}

try {
    $db = isset($db) && ($db instanceof Database) ? $db : new Database();
    $conn = isset($conn) && ($conn instanceof PDO) ? $conn : $db->getConnection();
    $service = new MobileMoneyGatewayService($conn);
    $result = $service->getStudentReferenceStatus($studentId, $referenceNumber);

    if (!empty($result['success'])) {
        http_response_code(200);
    } elseif (stripos((string)($result['message'] ?? ''), 'not found') !== false) {
        http_response_code(404);
    } else {
        http_response_code(400);
    }

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch PRN status.',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ]);
}

