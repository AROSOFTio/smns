<?php
require_once '../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$auth = new Auth('finance');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$studentId = (int)($_GET['msg_student_id'] ?? ($_GET['student_id'] ?? 0));
$prnReference = strtoupper(trim((string)($_GET['msg_prn'] ?? ($_GET['prn_ref'] ?? ($_GET['prn'] ?? '')))));
$transactionRef = strtoupper(trim((string)($_GET['msg_tx'] ?? ($_GET['tx_ref'] ?? ($_GET['tx'] ?? ($_GET['transaction_ref'] ?? ''))))));
$threadQ = trim((string)($_GET['thread_q'] ?? ($_GET['q'] ?? '')));
$unreadOnly = in_array(strtolower(trim((string)($_GET['unread_only'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
$threadLimit = (int)($_GET['thread_limit'] ?? 30);
if ($threadLimit < 1) {
    $threadLimit = 30;
}
if ($threadLimit > 100) {
    $threadLimit = 100;
}
$threadOffset = (int)($_GET['thread_offset'] ?? 0);
if ($threadOffset < 0) {
    $threadOffset = 0;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $service = new FinanceMessagingService($conn);
    $service->ensureSchema();

    $threadsRaw = $service->getFinanceThreadSummariesPaged($threadLimit + 1, $threadOffset, $threadQ, $unreadOnly);
    $threadsHasMore = count($threadsRaw) > $threadLimit;
    if ($threadsHasMore) {
        $threadsRaw = array_slice($threadsRaw, 0, $threadLimit);
    }
    $selectedSummary = null;
    $unreadTotal = $service->getFinanceUnreadTotal();

    if ($studentId <= 0 && !empty($threadsRaw)) {
        $selectedSummary = $threadsRaw[0];
        $studentId = (int)($selectedSummary['student_id'] ?? 0);
        if ($prnReference === '') {
            $prnReference = strtoupper(trim((string)($selectedSummary['prn_reference'] ?? '')));
        }
        if ($transactionRef === '') {
            $transactionRef = strtoupper(trim((string)($selectedSummary['transaction_ref'] ?? '')));
        }
    }

    if ($studentId > 0) {
        foreach ($threadsRaw as $threadRow) {
            $rowStudentId = (int)($threadRow['student_id'] ?? 0);
            $rowPrn = strtoupper(trim((string)($threadRow['prn_reference'] ?? '')));
            $rowTx = strtoupper(trim((string)($threadRow['transaction_ref'] ?? '')));
            if ($rowStudentId !== $studentId) {
                continue;
            }
            if ($prnReference !== '' && $rowPrn === $prnReference) {
                $selectedSummary = $threadRow;
                break;
            }
            if ($transactionRef !== '' && $rowTx === $transactionRef) {
                $selectedSummary = $threadRow;
                break;
            }
            if ($selectedSummary === null) {
                $selectedSummary = $threadRow;
            }
        }
    }

    if (is_array($selectedSummary)) {
        if ($prnReference === '') {
            $prnReference = strtoupper(trim((string)($selectedSummary['prn_reference'] ?? '')));
        }
        if ($transactionRef === '') {
            $transactionRef = strtoupper(trim((string)($selectedSummary['transaction_ref'] ?? '')));
        }
    }

    $messagesRaw = [];
    if ($studentId > 0) {
        $messagesRaw = $service->getFinanceThreadMessages($studentId, $prnReference, $transactionRef, 120);
    }

    $messages = [];
    $lastMessageId = 0;
    foreach ($messagesRaw as $msgRow) {
        $id = (int)($msgRow['id'] ?? 0);
        if ($id > $lastMessageId) {
            $lastMessageId = $id;
        }
        $createdAt = (string)($msgRow['created_at'] ?? '');
        $messages[] = [
            'id' => $id,
            'sender_role' => strtolower((string)($msgRow['sender_role'] ?? 'student')),
            'message_text' => (string)($msgRow['message_text'] ?? ''),
            'created_at' => $createdAt,
            'created_at_label' => $createdAt !== '' ? date('d M Y, h:i A', strtotime($createdAt)) : '-'
        ];
    }

    $threads = [];
    $dashboardBase = BASE_URL . '/views/finance/dashboard.php';
    foreach ($threadsRaw as $threadRow) {
        $rowStudentId = (int)($threadRow['student_id'] ?? 0);
        $rowPrn = strtoupper(trim((string)($threadRow['prn_reference'] ?? '')));
        $rowTx = strtoupper(trim((string)($threadRow['transaction_ref'] ?? '')));
        $rowName = trim((string)($threadRow['first_name'] ?? '') . ' ' . (string)($threadRow['last_name'] ?? ''));
        $rowRegNo = trim((string)($threadRow['registration_number'] ?? ''));
        $rowContext = $rowPrn !== '' ? ('PRN ' . $rowPrn) : ($rowTx !== '' ? ('TX ' . $rowTx) : ('Student #' . $rowStudentId));
        $rowPreview = trim((string)($threadRow['last_message'] ?? ''));
        if (strlen($rowPreview) > 90) {
            $rowPreview = substr($rowPreview, 0, 87) . '...';
        }

        $query = [
            'section' => 'messages',
            'msg_student_id' => $rowStudentId
        ];
        if ($rowPrn !== '') {
            $query['msg_prn'] = $rowPrn;
        }
        if ($rowTx !== '') {
            $query['msg_tx'] = $rowTx;
        }

        $threads[] = [
            'student_id' => $rowStudentId,
            'student_name' => $rowName !== '' ? $rowName : ('Student #' . $rowStudentId),
            'registration_number' => $rowRegNo,
            'prn_reference' => $rowPrn,
            'transaction_ref' => $rowTx,
            'context_label' => $rowContext,
            'last_message_preview' => $rowPreview !== '' ? $rowPreview : 'No message body',
            'unread_for_finance' => (int)($threadRow['unread_for_finance'] ?? 0),
            'thread_url' => $dashboardBase . '?' . http_build_query($query) . '#finance-messages-section'
        ];
    }

    $selectedName = '';
    $selectedRegNo = '';
    if (is_array($selectedSummary)) {
        $selectedName = trim((string)($selectedSummary['first_name'] ?? '') . ' ' . (string)($selectedSummary['last_name'] ?? ''));
        $selectedRegNo = trim((string)($selectedSummary['registration_number'] ?? ''));
    } elseif ($studentId > 0) {
        $studentStmt = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE id = :id LIMIT 1");
        $studentStmt->execute(['id' => $studentId]);
        $studentRow = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $selectedName = trim((string)($studentRow['first_name'] ?? '') . ' ' . (string)($studentRow['last_name'] ?? ''));
        $selectedRegNo = trim((string)($studentRow['student_id'] ?? ''));
    }

    $selectedContext = 'No thread selected';
    if ($studentId > 0) {
        $selectedContext = $prnReference !== '' ? ('PRN ' . $prnReference) : ($transactionRef !== '' ? ('TX ' . $transactionRef) : ('Student #' . $studentId));
    }

    echo json_encode([
        'success' => true,
        'unread_total' => $unreadTotal,
        'threads' => $threads,
        'threads_offset' => $threadOffset,
        'threads_limit' => $threadLimit,
        'threads_has_more' => $threadsHasMore,
        'thread_q' => $threadQ,
        'unread_only' => $unreadOnly ? 1 : 0,
        'selected' => [
            'student_id' => $studentId,
            'student_name' => $selectedName !== '' ? $selectedName : ($studentId > 0 ? ('Student #' . $studentId) : ''),
            'registration_number' => $selectedRegNo,
            'prn_reference' => $prnReference,
            'transaction_ref' => $transactionRef,
            'context_label' => $selectedContext
        ],
        'last_message_id' => $lastMessageId,
        'message_count' => count($messages),
        'messages' => $messages
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to poll finance message thread.',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ]);
}
