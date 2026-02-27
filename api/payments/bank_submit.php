<?php
require_once '../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$csrfToken = (string)($_POST['csrf_token'] ?? ($jsonBody['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
if ($csrfToken !== '' && !Security::verifyCSRFToken($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$referenceNumber = strtoupper(trim((string)($_POST['reference_number'] ?? ($_POST['prn'] ?? ($jsonBody['reference_number'] ?? ($jsonBody['prn'] ?? ''))))));
$transferReference = strtoupper(trim((string)($_POST['transfer_reference'] ?? ($_POST['bank_reference'] ?? ($jsonBody['transfer_reference'] ?? ($jsonBody['bank_reference'] ?? ''))))));
$paymentMethodLabel = strtolower(trim((string)($_POST['payment_method'] ?? ($_POST['payment_method_label'] ?? ($jsonBody['payment_method'] ?? ($jsonBody['payment_method_label'] ?? 'bank_agent'))))));
$bankName = trim((string)($_POST['bank_name'] ?? ($jsonBody['bank_name'] ?? '')));
$depositorName = trim((string)($_POST['depositor_name'] ?? ($jsonBody['depositor_name'] ?? '')));
$amountSubmittedRaw = (string)($_POST['amount_submitted'] ?? ($_POST['amount'] ?? ($jsonBody['amount_submitted'] ?? ($jsonBody['amount'] ?? '0'))));
$amountSubmitted = (float)str_replace(',', '', $amountSubmittedRaw);
$notes = trim((string)($_POST['notes'] ?? ($_POST['proof_note'] ?? ($jsonBody['notes'] ?? ($jsonBody['proof_note'] ?? '')))));

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

if ($referenceNumber === '' || $transferReference === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'PRN and bank transfer reference are required.']);
    exit;
}

try {
    $db = isset($db) && ($db instanceof Database) ? $db : new Database();
    $conn = isset($conn) && ($conn instanceof PDO) ? $conn : $db->getConnection();
    $service = new MobileMoneyGatewayService($conn);
    $result = $service->submitBankTransferProof($studentId, $referenceNumber, [
        'bank_name' => $bankName,
        'transfer_reference' => $transferReference,
        'payment_method_label' => $paymentMethodLabel,
        'depositor_name' => $depositorName,
        'amount_submitted' => $amountSubmitted,
        'notes' => $notes
    ]);

    http_response_code(!empty($result['success']) ? 200 : 400);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to submit bank transfer proof.',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ]);
}
