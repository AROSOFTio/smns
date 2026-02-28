<?php
require_once '../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$studentId = (int)($studentProfile['id'] ?? 0);

$prnReference = strtoupper(trim((string)($_GET['prn_ref'] ?? ($_GET['prn'] ?? ''))));
$transactionRef = strtoupper(trim((string)($_GET['tx_ref'] ?? ($_GET['transaction_ref'] ?? ($_GET['tx'] ?? '')))));

try {
    $db = new Database();
    $conn = $db->getConnection();

    if ($studentId <= 0) {
        $studentStmt = $conn->prepare("SELECT id FROM students WHERE user_id = :user_id LIMIT 1");
        $studentStmt->execute(['user_id' => (int)($currentUser['id'] ?? 0)]);
        $studentId = (int)$studentStmt->fetchColumn();
    }

    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Student profile was not found.']);
        exit;
    }

    $service = new FinanceMessagingService($conn);
    $service->ensureSchema();
    $rows = $service->getStudentThreadMessages($studentId, $prnReference, $transactionRef, 120);

    $messages = [];
    $lastMessageId = 0;
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > $lastMessageId) {
            $lastMessageId = $id;
        }
        $createdAt = (string)($row['created_at'] ?? '');
        $messages[] = [
            'id' => $id,
            'sender_role' => strtolower((string)($row['sender_role'] ?? 'student')),
            'message_text' => (string)($row['message_text'] ?? ''),
            'created_at' => $createdAt,
            'created_at_label' => $createdAt !== '' ? date('d M Y, h:i A', strtotime($createdAt)) : '-'
        ];
    }

    $contextLabel = 'General account support';
    if ($prnReference !== '') {
        $contextLabel = 'PRN ' . $prnReference;
    } elseif ($transactionRef !== '') {
        $contextLabel = 'TX ' . $transactionRef;
    }

    echo json_encode([
        'success' => true,
        'thread' => [
            'prn_reference' => $prnReference,
            'transaction_ref' => $transactionRef,
            'context_label' => $contextLabel
        ],
        'last_message_id' => $lastMessageId,
        'message_count' => count($messages),
        'messages' => $messages
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to poll student message thread.',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ]);
}

