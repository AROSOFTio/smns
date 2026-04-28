<?php
/**
 * Finance Dashboard
 */
require_once '../../config.php';

$session = new Session('finance');
$auth = new Auth('finance');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=session_expired&role=finance');
    exit;
}

$currentUser = $auth->getCurrentUser();
$financeProfile = $currentUser['profile'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$financeStaffId = (int)($financeProfile['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
$paymentGatewayService = new MobileMoneyGatewayService($conn);
$paymentGatewayService->ensureSchema();
$financeMessagingService = null;
try {
    $financeMessagingService = new FinanceMessagingService($conn);
    $financeMessagingService->ensureSchema();
} catch (Exception $e) {
    $financeMessagingService = null;
}

if (!function_exists('financeGenerateCode')) {
    function financeGenerateCode($prefix) {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(2)));
        } catch (Exception $e) {
            $suffix = strtoupper(dechex(mt_rand(4096, 65535)));
        }
        return $prefix . '-' . date('YmdHis') . '-' . $suffix;
    }
}

if (!function_exists('financeEnsurePaymentWorkflowSchema')) {
    function financeEnsurePaymentWorkflowSchema(PDO $conn) {
        try {
            $colStmt = $conn->query("SHOW COLUMNS FROM payments LIKE 'verification_status'");
            $hasVerificationStatus = (bool)($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
            if (!$hasVerificationStatus) {
                $conn->exec("
                    ALTER TABLE payments
                    ADD COLUMN verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified' AFTER notes,
                    ADD COLUMN verified_by INT NULL AFTER verification_status,
                    ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
                    ADD COLUMN verification_notes TEXT NULL AFTER verified_at
                ");
            } else {
                $colStmt = $conn->query("SHOW COLUMNS FROM payments LIKE 'verified_by'");
                if (!($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC))) {
                    $conn->exec("ALTER TABLE payments ADD COLUMN verified_by INT NULL AFTER verification_status");
                }

                $colStmt = $conn->query("SHOW COLUMNS FROM payments LIKE 'verified_at'");
                if (!($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC))) {
                    $conn->exec("ALTER TABLE payments ADD COLUMN verified_at DATETIME NULL AFTER verified_by");
                }

                $colStmt = $conn->query("SHOW COLUMNS FROM payments LIKE 'verification_notes'");
                if (!($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC))) {
                    $conn->exec("ALTER TABLE payments ADD COLUMN verification_notes TEXT NULL AFTER verified_at");
                }
            }

            $existingIndexes = [];
            $idxStmt = $conn->query("SHOW INDEX FROM payments");
            if ($idxStmt) {
                foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idxRow) {
                    $existingIndexes[(string)($idxRow['Key_name'] ?? '')] = true;
                }
            }

            if (!isset($existingIndexes['idx_payment_verification_status'])) {
                $conn->exec("ALTER TABLE payments ADD INDEX idx_payment_verification_status (verification_status)");
            }

            if (!isset($existingIndexes['idx_payment_verified_by'])) {
                $conn->exec("ALTER TABLE payments ADD INDEX idx_payment_verified_by (verified_by)");
            }

            $conn->exec("UPDATE payments SET verification_status = 'verified' WHERE verification_status IS NULL OR verification_status = ''");
        } catch (Exception $e) {
            // Keep dashboard operational even if migration fails.
        }
    }
}

financeEnsurePaymentWorkflowSchema($conn);
$hasPaymentVerificationColumns = (strpos(getVerifiedPaymentsPredicate($conn), 'verification_status') !== false);

if (!function_exists('financeSyncStudentBalance')) {
    function financeSyncStudentBalance(PDO $conn, $studentId, $semesterId) {
        $studentId = (int)$studentId;
        $semesterId = (int)$semesterId;
        if ($studentId <= 0 || $semesterId <= 0) {
            return;
        }

        $snapshot = getStudentFinancialSnapshot($conn, $studentId, $semesterId);
        $totalFees = (float)($snapshot['approved_total_fees'] ?? $snapshot['total_fees'] ?? 0);
        $totalPaid = (float)($snapshot['total_paid'] ?? 0);

        $verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn);
        $lastStmt = $conn->prepare("
            SELECT MAX(payment_date)
            FROM payments
            WHERE student_id = :student_id
              AND semester_id = :semester_id
              AND {$verifiedPaymentsPredicate}
        ");
        $lastStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
        $lastPaymentDate = $lastStmt->fetchColumn();
        if ($lastPaymentDate === false || $lastPaymentDate === '') {
            $lastPaymentDate = null;
        }

        $balance = (float)($snapshot['balance_due'] ?? max($totalFees - $totalPaid, 0));

        $existsStmt = $conn->prepare("SELECT id FROM student_balances WHERE student_id = :student_id AND semester_id = :semester_id LIMIT 1");
        $existsStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
        $balanceId = (int)$existsStmt->fetchColumn();

        if ($balanceId > 0) {
            $updateStmt = $conn->prepare("
                UPDATE student_balances
                SET total_fees = :total_fees,
                    total_paid = :total_paid,
                    balance = :balance,
                    last_payment_date = :last_payment_date,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                'total_fees' => $totalFees,
                'total_paid' => $totalPaid,
                'balance' => $balance,
                'last_payment_date' => $lastPaymentDate,
                'id' => $balanceId
            ]);
            return;
        }

        $insertStmt = $conn->prepare("
            INSERT INTO student_balances (student_id, semester_id, total_fees, total_paid, balance, last_payment_date, updated_at)
            VALUES (:student_id, :semester_id, :total_fees, :total_paid, :balance, :last_payment_date, NOW())
        ");
        $insertStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $balance,
            'last_payment_date' => $lastPaymentDate
        ]);
    }
}

if (!function_exists('financeNotifyUser')) {
    function financeNotifyUser(PDO $conn, $userId, $title, $message, $type = 'info', $link = null) {
        $userId = (int)$userId;
        if ($userId <= 0) return;
        try {
            $notifyStmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, read_status, link, created_at)
                VALUES (:user_id, :title, :message, :type, 'unread', :link, NOW())
            ");
            $notifyStmt->execute([
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'link' => $link
            ]);
        } catch (Exception $e) {
        }
    }
}

$currentSemester = Helper::getCurrentSemester();
$currentSemesterId = (int)($currentSemester['id'] ?? 0);
$currentAcademicYearLabel = 'N/A';
if (!empty($currentSemester['academic_year_id'])) {
    $ayStmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => (int)$currentSemester['academic_year_id']]);
    $currentAcademicYearLabel = (string)($ayStmt->fetchColumn() ?: 'N/A');
}

if ($financeStaffId <= 0 && $currentUserId > 0) {
    try {
        $staffStmt = $conn->prepare("SELECT id FROM finance_staff WHERE user_id = :user_id LIMIT 1");
        $staffStmt->execute(['user_id' => $currentUserId]);
        $financeStaffId = (int)$staffStmt->fetchColumn();
    } catch (Exception $e) {
        $financeStaffId = 0;
    }
}

$dashboardUrl = BASE_URL . '/views/finance/dashboard.php';
$messageStudentId = (int)($_GET['msg_student_id'] ?? 0);
$messagePrn = strtoupper(trim((string)($_GET['msg_prn'] ?? '')));
$messageTx = strtoupper(trim((string)($_GET['msg_tx'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token. Please retry.');
        header('Location: ' . $dashboardUrl);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'finance_reply_student_message') {
        $replyStudentId = (int)($_POST['student_id'] ?? 0);
        $replyPrn = strtoupper(trim((string)($_POST['prn_reference'] ?? '')));
        $replyTx = strtoupper(trim((string)($_POST['transaction_ref'] ?? '')));
        $replyMessage = trim((string)($_POST['message_text'] ?? ''));

        $replyParams = [
            'section' => 'messages',
            'msg_student_id' => $replyStudentId
        ];
        if ($replyPrn !== '') {
            $replyParams['msg_prn'] = $replyPrn;
        }
        if ($replyTx !== '') {
            $replyParams['msg_tx'] = $replyTx;
        }
        $replyRedirect = $dashboardUrl . '?' . http_build_query($replyParams) . '#finance-messages-section';

        if ($currentUserId <= 0) {
            $session->setFlash('error', 'Finance user session is missing.');
            header('Location: ' . $replyRedirect);
            exit;
        }
        if (!$financeMessagingService) {
            $session->setFlash('error', 'Messaging service is not available right now.');
            header('Location: ' . $replyRedirect);
            exit;
        }

        $replyResult = $financeMessagingService->sendFinanceReply(
            $currentUserId,
            $replyStudentId,
            $replyPrn,
            $replyTx,
            $replyMessage
        );
        if (!empty($replyResult['success'])) {
            $session->setFlash('success', (string)($replyResult['message'] ?? 'Reply sent to student.'));
        } else {
            $session->setFlash('error', (string)($replyResult['message'] ?? 'Unable to send reply right now.'));
        }

        header('Location: ' . $replyRedirect);
        exit;
    }

    if ($action === 'verify_bank_transaction') {
        $bankTransactionId = (int)($_POST['bank_transaction_id'] ?? 0);
        $decision = strtolower(trim((string)($_POST['decision'] ?? 'verify')));
        if (!in_array($decision, ['verify', 'fail'], true)) {
            $decision = 'verify';
        }
        $verificationNotes = trim((string)($_POST['verification_notes'] ?? ''));
        $verifiedAmountInput = trim((string)($_POST['verified_amount'] ?? ''));
        $verifiedAmount = null;
        if ($verifiedAmountInput !== '') {
            $parsedAmount = (float)str_replace(',', '', $verifiedAmountInput);
            if ($parsedAmount > 0) {
                $verifiedAmount = $parsedAmount;
            }
        }

        if ($financeStaffId <= 0) {
            $session->setFlash('error', 'Finance staff profile is missing.');
            header('Location: ' . $dashboardUrl . '?section=bank-verification#bank-verification-section');
            exit;
        }

        $result = $paymentGatewayService->verifyBankTransaction(
            $bankTransactionId,
            $financeStaffId,
            $decision,
            $verificationNotes,
            $verifiedAmount
        );

        if (!empty($result['success'])) {
            $session->setFlash('success', (string)($result['message'] ?? 'Bank transaction processed.'));
        } else {
            $session->setFlash('error', (string)($result['message'] ?? 'Unable to process bank transaction.'));
        }

        header('Location: ' . $dashboardUrl . '?section=bank-verification#bank-verification-section');
        exit;
    }

    if ($action === 'record_payment') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $paymentDateInput = trim((string)($_POST['payment_date'] ?? ''));
        $referenceNumber = trim((string)($_POST['reference_number'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $allowedMethods = ['cash', 'bank_transfer', 'bank_agent', 'cente_agent', 'mobile_money', 'cheque', 'card'];
        $errors = [];

        if ($currentSemesterId <= 0) $errors[] = 'No active semester was found.';
        if ($financeStaffId <= 0) $errors[] = 'Finance staff profile is missing.';
        if ($studentId <= 0) $errors[] = 'Select a student.';
        if ($amount <= 0) $errors[] = 'Payment amount must be greater than zero.';
        if (!in_array($paymentMethod, $allowedMethods, true)) $errors[] = 'Select a valid payment method.';

        $paymentDate = date('Y-m-d');
        if ($paymentDateInput !== '') {
            $parsedDate = strtotime($paymentDateInput);
            if ($parsedDate === false) $errors[] = 'Invalid payment date.';
            else $paymentDate = date('Y-m-d', $parsedDate);
        }

        if (!empty($errors)) {
            $session->setFlash('error', implode(' ', $errors));
            header('Location: ' . $dashboardUrl . '?section=record-payment#record-payment-section');
            exit;
        }

        try {
            $conn->beginTransaction();

            $studentStmt = $conn->prepare("SELECT id, user_id FROM students WHERE id = :id LIMIT 1");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) throw new Exception('Selected student was not found.');

            $targetSemesterId = $currentSemesterId;
            $invoiceRow = null;
            if ($invoiceId > 0) {
                $invoiceStmt = $conn->prepare("SELECT id, semester_id, total_amount, amount_paid FROM invoices WHERE id = :id AND student_id = :student_id LIMIT 1");
                $invoiceStmt->execute(['id' => $invoiceId, 'student_id' => $studentId]);
                $invoiceRow = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$invoiceRow) throw new Exception('Selected invoice does not belong to the student.');
                $targetSemesterId = (int)($invoiceRow['semester_id'] ?? $currentSemesterId);
            }

            $previousSnapshot = getStudentFinancialSnapshot($conn, $studentId, $targetSemesterId);
            $previousOutstandingBalance = (float)($previousSnapshot['balance_due'] ?? 0);

            $paymentId = financeGenerateCode('PAY');
            $receiptNumber = financeGenerateCode('RCP');

            if ($hasPaymentVerificationColumns) {
                $insertPaymentStmt = $conn->prepare("
                    INSERT INTO payments (
                        payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number,
                        received_by, semester_id, notes, verification_status, verified_by, verified_at, verification_notes,
                        receipt_number, created_at, updated_at
                    )
                    VALUES (
                        :payment_id, :student_id, :invoice_id, :amount, :payment_date, :payment_method, :reference_number,
                        :received_by, :semester_id, :notes, :verification_status, :verified_by, :verified_at, :verification_notes,
                        :receipt_number, NOW(), NOW()
                    )
                ");
                $insertPaymentStmt->execute([
                    'payment_id' => $paymentId,
                    'student_id' => $studentId,
                    'invoice_id' => $invoiceId > 0 ? $invoiceId : null,
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                    'payment_method' => $paymentMethod,
                    'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                    'received_by' => $financeStaffId,
                    'semester_id' => $targetSemesterId,
                    'notes' => $notes !== '' ? $notes : null,
                    'verification_status' => 'verified',
                    'verified_by' => $financeStaffId,
                    'verified_at' => date('Y-m-d H:i:s'),
                    'verification_notes' => 'Verified at capture by finance office.',
                    'receipt_number' => $receiptNumber
                ]);
            } else {
                $insertPaymentStmt = $conn->prepare("
                    INSERT INTO payments (
                        payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number,
                        received_by, semester_id, notes, receipt_number, created_at, updated_at
                    )
                    VALUES (
                        :payment_id, :student_id, :invoice_id, :amount, :payment_date, :payment_method, :reference_number,
                        :received_by, :semester_id, :notes, :receipt_number, NOW(), NOW()
                    )
                ");
                $insertPaymentStmt->execute([
                    'payment_id' => $paymentId,
                    'student_id' => $studentId,
                    'invoice_id' => $invoiceId > 0 ? $invoiceId : null,
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                    'payment_method' => $paymentMethod,
                    'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                    'received_by' => $financeStaffId,
                    'semester_id' => $targetSemesterId,
                    'notes' => $notes !== '' ? $notes : null,
                    'receipt_number' => $receiptNumber
                ]);
            }

            if ($invoiceRow) {
                $invoiceTotal = (float)($invoiceRow['total_amount'] ?? 0);
                $invoicePaid = (float)($invoiceRow['amount_paid'] ?? 0);
                $newPaid = min($invoiceTotal, $invoicePaid + $amount);
                $newBalance = max($invoiceTotal - $newPaid, 0);
                $newStatus = $newBalance <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'pending');

                $updateInvoiceStmt = $conn->prepare("UPDATE invoices SET amount_paid = :amount_paid, balance = :balance, status = :status, updated_at = NOW() WHERE id = :id");
                $updateInvoiceStmt->execute([
                    'amount_paid' => $newPaid,
                    'balance' => $newBalance,
                    'status' => $newStatus,
                    'id' => (int)$invoiceRow['id']
                ]);
            }

            financeSyncStudentBalance($conn, $studentId, $targetSemesterId);
            $updatedSnapshot = getStudentFinancialSnapshot($conn, $studentId, $targetSemesterId);
            $newOutstandingBalance = (float)($updatedSnapshot['balance_due'] ?? 0);

            financeNotifyUser(
                $conn,
                (int)($student['user_id'] ?? 0),
                'Payment Verified and Posted',
                'Your payment of ' . Helper::formatCurrency($amount, 'UGX', 0) . ' was verified and posted to your ledger. Receipt: ' . $receiptNumber,
                'success',
                BASE_URL . '/views/student/payments.php'
            );

            $conn->commit();
            $session->setFlash(
                'success',
                'Payment recorded, verified, and posted successfully. Receipt: ' . $receiptNumber .
                '. Outstanding balance moved from ' .
                Helper::formatCurrency($previousOutstandingBalance, 'UGX', 0) . ' to ' .
                Helper::formatCurrency($newOutstandingBalance, 'UGX', 0) . '.'
            );

            try {
                $logger = new Logger();
                $logger->log(
                    $currentUserId,
                    'verify_payment',
                    'finance',
                    'Payment ' . $paymentId . ' verified and posted. Receipt: ' . $receiptNumber .
                    '. Student ID: ' . $studentId . '. Amount: ' . Helper::formatCurrency($amount, 'UGX', 0),
                    [
                        'part' => 'finance payment workflow',
                        'where' => 'finance dashboard',
                        'target' => 'payments:' . $paymentId
                    ]
                );
            } catch (Exception $e) {
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $session->setFlash('error', 'Failed to record payment: ' . $e->getMessage());
        }

        header('Location: ' . $dashboardUrl . '?section=payments#payments-section');
        exit;
    }

    if ($action === 'generate_invoice') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $totalAmount = (float)str_replace(',', '', (string)($_POST['total_amount'] ?? '0'));
        $dueDateInput = trim((string)($_POST['due_date'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $errors = [];

        if ($currentSemesterId <= 0) $errors[] = 'No active semester was found.';
        if ($studentId <= 0) $errors[] = 'Select a student.';
        if ($totalAmount <= 0) $errors[] = 'Invoice amount must be greater than zero.';

        $dueDate = null;
        if ($dueDateInput !== '') {
            $parsedDueDate = strtotime($dueDateInput);
            if ($parsedDueDate === false) $errors[] = 'Invalid due date.';
            else $dueDate = date('Y-m-d', $parsedDueDate);
        }

        if (!empty($errors)) {
            $session->setFlash('error', implode(' ', $errors));
            header('Location: ' . $dashboardUrl . '?section=invoice-create#invoice-create-section');
            exit;
        }

        try {
            $conn->beginTransaction();

            $studentStmt = $conn->prepare("SELECT id, user_id FROM students WHERE id = :id LIMIT 1");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) throw new Exception('Selected student was not found.');

            $invoiceNumber = financeGenerateCode('INV');
            $createInvoiceStmt = $conn->prepare("
                INSERT INTO invoices (invoice_number, student_id, semester_id, total_amount, amount_paid, balance, due_date, status, created_at, updated_at)
                VALUES (:invoice_number, :student_id, :semester_id, :total_amount, 0, :balance, :due_date, 'pending', NOW(), NOW())
            ");
            $createInvoiceStmt->execute([
                'invoice_number' => $invoiceNumber,
                'student_id' => $studentId,
                'semester_id' => $currentSemesterId,
                'total_amount' => $totalAmount,
                'balance' => $totalAmount,
                'due_date' => $dueDate
            ]);
            $invoiceId = (int)$conn->lastInsertId();

            if ($invoiceId > 0) {
                $itemDescription = $description !== '' ? $description : 'Semester Tuition Invoice';
                $itemStmt = $conn->prepare("
                    INSERT INTO invoice_items (invoice_id, fee_structure_id, description, amount, created_at)
                    VALUES (:invoice_id, NULL, :description, :amount, NOW())
                ");
                $itemStmt->execute([
                    'invoice_id' => $invoiceId,
                    'description' => $itemDescription,
                    'amount' => $totalAmount
                ]);
            }

            financeSyncStudentBalance($conn, $studentId, $currentSemesterId);
            financeNotifyUser(
                $conn,
                (int)($student['user_id'] ?? 0),
                'New Invoice Generated',
                'A new invoice (' . $invoiceNumber . ') was created for your account.',
                'info',
                BASE_URL . '/views/student/payments.php'
            );

            $conn->commit();
            $session->setFlash('success', 'Invoice ' . $invoiceNumber . ' created successfully.');
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $session->setFlash('error', 'Failed to generate invoice: ' . $e->getMessage());
        }

        header('Location: ' . $dashboardUrl . '?section=invoices#invoices-section');
        exit;
    }
}

$totalCollections = 0.0;
$outstandingBalance = 0.0;
$paymentsToday = 0.0;
$totalInvoices = 0;
$studentsWithBalance = 0;
$studentBalances = [];
$collectionExpectedFees = 0.0;
$collectionTowardsFees = 0.0;
$collectionOverpayment = 0.0;
$usdUgxRate = (float)Helper::getUsdUgxRate();
if ($usdUgxRate <= 0) {
    $usdUgxRate = 3700.0;
}

if ($currentSemesterId > 0) {
    $verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn);
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM payments
        WHERE semester_id = :semester_id
          AND {$verifiedPaymentsPredicate}
    ");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $totalCollections = (float)($stmt->fetch()['total'] ?? 0);

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM invoices WHERE semester_id = :semester_id AND status != 'paid'");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $totalInvoices = (int)($stmt->fetch()['total'] ?? 0);
}

$verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn);
$stmt = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM payments
    WHERE payment_date = CURDATE()
      AND {$verifiedPaymentsPredicate}
");
$paymentsToday = (float)($stmt->fetch()['total'] ?? 0);

$verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn, 'p');
$stmt = $conn->prepare("
    SELECT p.*, s.id AS student_db_id, s.student_id, s.first_name, s.last_name, s.academic_status, i.invoice_number
    FROM payments p
    INNER JOIN students s ON p.student_id = s.id
    LEFT JOIN invoices i ON i.id = p.invoice_id
    WHERE {$verifiedPaymentsPredicate}
    ORDER BY p.created_at DESC LIMIT 10
");
$stmt->execute();
$recentPayments = $stmt->fetchAll();

$recentInvoices = [];
if ($currentSemesterId > 0) {
    $stmt = $conn->prepare("
        SELECT i.*, s.student_id, s.first_name, s.last_name
        FROM invoices i
        INNER JOIN students s ON s.id = i.student_id
        WHERE i.semester_id = :semester_id
        ORDER BY i.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $recentInvoices = $stmt->fetchAll();
}

$collectionsByMonth = [];
$verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn);
$stmt = $conn->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS period, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE YEAR(payment_date) = YEAR(CURDATE())
      AND {$verifiedPaymentsPredicate}
    GROUP BY period
    ORDER BY period DESC
    LIMIT 12
");
$collectionsByMonth = $stmt->fetchAll();

$activeStudents = [];
$stmt = $conn->query("
    SELECT s.id, s.student_id, s.first_name, s.last_name, s.academic_status, p.program_name
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    LEFT JOIN programs p ON p.id = s.program_id
    WHERE s.status = 'active' AND u.status = 'active'
    ORDER BY s.first_name ASC, s.last_name ASC
");
$activeStudents = $stmt->fetchAll();

if ($currentSemesterId > 0) {
    $balanceMonitor = getActiveStudentBalanceMonitor($conn, $currentSemesterId);
    $studentBalances = (array)($balanceMonitor['rows'] ?? []);
    $outstandingBalance = (float)($balanceMonitor['outstanding_total_ugx'] ?? 0.0);
    $studentsWithBalance = (int)($balanceMonitor['students_with_balance'] ?? 0);
    $collectionExpectedFees = (float)($balanceMonitor['expected_total_fees_ugx'] ?? 0.0);
    $collectionTowardsFees = (float)($balanceMonitor['collected_toward_fees_ugx'] ?? 0.0);
    $collectionOverpayment = (float)($balanceMonitor['overpayment_total_ugx'] ?? 0.0);
}

$openInvoices = [];
if ($currentSemesterId > 0) {
    $stmt = $conn->prepare("
        SELECT i.id, i.student_id, i.invoice_number, i.balance, s.student_id AS registration_number, s.first_name, s.last_name
        FROM invoices i
        INNER JOIN students s ON s.id = i.student_id
        WHERE i.semester_id = :semester_id
          AND i.balance > 0
          AND i.status IN ('pending','partial','overdue')
        ORDER BY i.created_at DESC
    ");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $openInvoices = $stmt->fetchAll();
}

$bankVerificationRows = [];
$bankStatusTotals = [
    'received' => 0,
    'verified' => 0,
    'posted' => 0,
    'failed' => 0
];
try {
    $bankStmt = $conn->query("
        SELECT
            b.id,
            b.transaction_ref,
            b.reference_number,
            b.payment_method_label,
            b.bank_name,
            b.depositor_name,
            b.transfer_reference,
            b.amount_expected,
            b.amount_submitted,
            b.status,
            b.submitted_notes,
            b.verification_notes,
            b.failure_reason,
            b.verified_at,
            b.posted_at,
            b.created_at,
            b.posted_payment_id,
            s.id AS student_db_id,
            s.student_id,
            s.first_name,
            s.last_name,
            spr.reference_type,
            p.payment_id,
            p.receipt_number
        FROM bank_transactions b
        INNER JOIN students s ON s.id = b.student_id
        LEFT JOIN student_payment_references spr ON spr.reference_number = b.reference_number
        LEFT JOIN payments p ON p.id = b.posted_payment_id
        ORDER BY
            FIELD(b.status, 'received', 'verified', 'failed', 'posted'),
            b.created_at DESC
        LIMIT 500
    ");
    $bankVerificationRows = $bankStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($bankVerificationRows as $bankRow) {
        $statusKey = strtolower((string)($bankRow['status'] ?? ''));
        if (isset($bankStatusTotals[$statusKey])) {
            $bankStatusTotals[$statusKey] += 1;
        }
    }
} catch (Exception $e) {
    $bankVerificationRows = [];
}

$financeMessageThreads = [];
$financeMessageRows = [];
$financeMessageUnreadCount = 0;
$selectedMessageSummary = null;
$selectedMessageStudentName = '';
$selectedMessageStudentRegNo = '';

if ($financeMessagingService) {
    $financeMessageThreads = $financeMessagingService->getFinanceThreadSummaries(60);
    foreach ($financeMessageThreads as $threadSummary) {
        $financeMessageUnreadCount += (int)($threadSummary['unread_for_finance'] ?? 0);
    }

    if ($messageStudentId <= 0 && !empty($financeMessageThreads)) {
        $selectedMessageSummary = $financeMessageThreads[0];
        $messageStudentId = (int)($selectedMessageSummary['student_id'] ?? 0);
        $messagePrn = strtoupper(trim((string)($selectedMessageSummary['prn_reference'] ?? '')));
        $messageTx = strtoupper(trim((string)($selectedMessageSummary['transaction_ref'] ?? '')));
    } elseif ($messageStudentId > 0 && ($messagePrn === '' && $messageTx === '')) {
        foreach ($financeMessageThreads as $threadSummary) {
            if ((int)($threadSummary['student_id'] ?? 0) === $messageStudentId) {
                $selectedMessageSummary = $threadSummary;
                $messagePrn = strtoupper(trim((string)($threadSummary['prn_reference'] ?? '')));
                $messageTx = strtoupper(trim((string)($threadSummary['transaction_ref'] ?? '')));
                break;
            }
        }
    }

    if ($selectedMessageSummary === null && $messageStudentId > 0) {
        foreach ($financeMessageThreads as $threadSummary) {
            $threadStudentId = (int)($threadSummary['student_id'] ?? 0);
            $threadPrn = strtoupper(trim((string)($threadSummary['prn_reference'] ?? '')));
            $threadTx = strtoupper(trim((string)($threadSummary['transaction_ref'] ?? '')));
            if ($threadStudentId !== $messageStudentId) {
                continue;
            }
            $prnMatch = ($messagePrn !== '' && $threadPrn === $messagePrn);
            $txMatch = ($messageTx !== '' && $threadTx === $messageTx);
            if ($prnMatch || $txMatch) {
                $selectedMessageSummary = $threadSummary;
                break;
            }
        }
    }

    if ($messageStudentId > 0) {
        $financeMessageRows = $financeMessagingService->getFinanceThreadMessages(
            $messageStudentId,
            $messagePrn,
            $messageTx,
            40
        );
    }
}

if (is_array($selectedMessageSummary)) {
    $selectedMessageStudentName = trim((string)($selectedMessageSummary['first_name'] ?? '') . ' ' . (string)($selectedMessageSummary['last_name'] ?? ''));
    $selectedMessageStudentRegNo = trim((string)($selectedMessageSummary['registration_number'] ?? ''));
} elseif ($messageStudentId > 0) {
    try {
        $msgStudentStmt = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE id = :id LIMIT 1");
        $msgStudentStmt->execute(['id' => $messageStudentId]);
        $msgStudentRow = $msgStudentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $selectedMessageStudentName = trim((string)($msgStudentRow['first_name'] ?? '') . ' ' . (string)($msgStudentRow['last_name'] ?? ''));
        $selectedMessageStudentRegNo = trim((string)($msgStudentRow['student_id'] ?? ''));
    } catch (Exception $e) {
    }
}
$financeMessagePollParams = [];
if ($messageStudentId > 0) {
    $financeMessagePollParams['msg_student_id'] = (int)$messageStudentId;
}
if ($messagePrn !== '') {
    $financeMessagePollParams['msg_prn'] = $messagePrn;
}
if ($messageTx !== '') {
    $financeMessagePollParams['msg_tx'] = $messageTx;
}
$financeMessagePollUrl = BASE_URL . '/api/messages/finance_thread.php';
if (!empty($financeMessagePollParams)) {
    $financeMessagePollUrl .= '?' . http_build_query($financeMessagePollParams);
}
$paymentAlertsCsrfToken = Security::generateCSRFToken();
$paymentAlertsApiUrl = BASE_URL . '/api/finance/payment_alerts.php';
$paymentAlerts = [];
$paymentAlertsUnreadCount = 0;
if ($currentUserId > 0) {
    try {
        $paymentAlertStmt = $conn->prepare("
            SELECT id, title, message, type, link, created_at
            FROM notifications
            WHERE user_id = :user_id
              AND COALESCE(read_status, 'unread') <> 'read'
              AND (
                    title IN ('PRN Payment Posted', 'Bank Proof Submitted')
                    OR message LIKE :msg_prn
                    OR link LIKE :link_pay
                    OR link LIKE :link_bank
              )
            ORDER BY created_at DESC, id DESC
            LIMIT 8
        ");
        $paymentAlertStmt->execute([
            'user_id' => $currentUserId,
            'msg_prn' => '%PRN %',
            'link_pay' => '%/views/finance/dashboard.php?section=payments%',
            'link_bank' => '%#bank-verification-section%'
        ]);
        $paymentAlerts = $paymentAlertStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $paymentAlertCountStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :user_id
              AND COALESCE(read_status, 'unread') <> 'read'
              AND (
                    title IN ('PRN Payment Posted', 'Bank Proof Submitted')
                    OR message LIKE :msg_prn
                    OR link LIKE :link_pay
                    OR link LIKE :link_bank
              )
        ");
        $paymentAlertCountStmt->execute([
            'user_id' => $currentUserId,
            'msg_prn' => '%PRN %',
            'link_pay' => '%/views/finance/dashboard.php?section=payments%',
            'link_bank' => '%#bank-verification-section%'
        ]);
        $paymentAlertsUnreadCount = (int)$paymentAlertCountStmt->fetchColumn();
    } catch (Exception $e) {
        $paymentAlerts = [];
        $paymentAlertsUnreadCount = 0;
    }
}

$unreadNotifications = fetchUnreadNotificationsForUser($currentUserId, 10);
$financeSavedNotifications = [];
$financeSavedNotificationCount = 0;
if ($currentUserId > 0) {
    try {
        $savedNotifStmt = $conn->prepare("
            SELECT
                na.id AS archive_id,
                na.notification_id,
                na.title,
                na.message,
                na.link,
                na.archived_at,
                COALESCE(NULLIF(n.type, ''), 'info') AS type,
                n.created_at AS original_created_at
            FROM notification_archive na
            LEFT JOIN notifications n ON n.id = na.notification_id
            WHERE na.user_id = :user_id
            ORDER BY na.archived_at DESC, na.id DESC
            LIMIT 500
        ");
        $savedNotifStmt->execute(['user_id' => $currentUserId]);
        $financeSavedNotifications = $savedNotifStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $financeSavedNotificationCount = count($financeSavedNotifications);
    } catch (Exception $e) {
        $financeSavedNotifications = [];
        $financeSavedNotificationCount = 0;
    }
}
$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');

$pageTitle = 'Finance Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/finance/sidebar.php'; ?>

<div class="main-content finance-dashboard" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Finance Dashboard</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr((string)($financeProfile['first_name'] ?? 'F'), 0, 1) . substr((string)($financeProfile['last_name'] ?? 'S'), 0, 1)); ?>
                        </div>
                        <div>
                            <strong><?php echo e((string)($financeProfile['first_name'] ?? '')); ?> <?php echo e((string)($financeProfile['last_name'] ?? '')); ?></strong>
                            <br><small>Finance Staff</small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="<?php echo BASE_URL; ?>/views/finance/change-password.php" class="dropdown-item">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/finance/logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success">
                <?php echo e($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger">
                <?php echo e($flashError); ?>
            </div>
        <?php endif; ?>

        <?php if ($currentSemesterId <= 0): ?>
            <div class="alert alert-warning">
                No active semester is configured. Activate a semester to enable finance operations.
            </div>
        <?php endif; ?>
        
        <!-- Welcome Section -->
        <div class="welcome-section mb-3">
            <h5>Welcome, <?php echo e((string)($financeProfile['first_name'] ?? 'Finance')); ?> - Finance Dashboard</h5>
            <p class="text-muted mb-0" style="font-size:13px;">
                <strong>Semester:</strong> <?php echo e((string)($currentSemester['semester_name'] ?? 'N/A')); ?> &nbsp;|&nbsp; <?php echo date('l, M d, Y'); ?>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap;">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentAcademicYearLabel); ?></span></span>
                <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap;">CURRENT SEM. <span style="color:#2563eb;"><?php echo e((string)($currentSemester['semester_name'] ?? 'N/A')); ?></span></span>
            </div>
        </div>

        <div class="stats-grid finance-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-details">
                    <h3><?php echo e(Helper::formatCurrency($paymentsToday, 'UGX', 0)); ?></h3>
                    <p>Today Collections</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-details">
                    <h3><?php echo e(Helper::formatCurrency($totalCollections, 'UGX', 0)); ?></h3>
                    <p>Semester Collections</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
                <div class="stat-details">
                    <h3><?php echo e(Helper::formatCurrency($outstandingBalance, 'UGX', 0)); ?></h3>
                    <p>Outstanding Balance</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalInvoices); ?></h3>
                    <p>Open Invoices</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($studentsWithBalance); ?></h3>
                    <p>Students with Balance</p>
                </div>
            </div>
        </div>

        <?php
            // Use fee-target progress for active students to avoid overpayment inflating the bar.
            $collectionRateRaw = $collectionExpectedFees > 0
                ? (($collectionTowardsFees / $collectionExpectedFees) * 100)
                : 0;
            $collectionRate = max(0.0, min(100.0, (float)$collectionRateRaw));
            $collectionRateBarWidth = number_format($collectionRate, 1, '.', '');
        ?>
        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small class="font-weight-bold">Collection Rate</small>
                    <small class="text-muted"><?php echo number_format($collectionRate, 1); ?>%</small>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success collection-rate-bar" role="progressbar" style="width: <?php echo e($collectionRateBarWidth); ?>%;"></div>
                </div>
                <small class="text-muted d-block mt-1" style="font-size:11px;">
                    Target: <?php echo e(Helper::formatCurrency($collectionExpectedFees, 'UGX', 0)); ?>
                    |
                    Collected toward target: <?php echo e(Helper::formatCurrency($collectionTowardsFees, 'UGX', 0)); ?>
                    <?php if ($collectionOverpayment > 0): ?>
                        | Overpayment: <?php echo e(Helper::formatCurrency($collectionOverpayment, 'UGX', 0)); ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <div class="quick-actions-section">
            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            <div class="action-grid">
                <a href="<?php echo e($dashboardUrl); ?>?section=record-payment#record-payment-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                    <h4>Record Payment</h4>
                    <p>Capture a student payment entry.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=invoice-create#invoice-create-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <h4>Generate Invoice</h4>
                    <p>Create billing records for students.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=balances#balances-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-balance-scale"></i></div>
                    <h4>Student Balances</h4>
                    <p>Review registered students, including no-payment records.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=reports#reports-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-chart-bar"></i></div>
                    <h4>Collections Report</h4>
                    <p>Monthly total collections summary.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=bank-verification#bank-verification-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-university"></i></div>
                    <h4>Bank Verification</h4>
                    <p>Verify submitted bank transfers and auto-post to ledger.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=messages#finance-messages-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-comments-dollar"></i></div>
                    <h4>Student Messages</h4>
                    <p>Reply to PRN-linked student chats<?php echo $financeMessageUnreadCount > 0 ? ' (' . (int)$financeMessageUnreadCount . ' unread)' : ''; ?>.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=saved-notifications#finance-saved-notifications-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-archive"></i></div>
                    <h4>Saved Notifications</h4>
                    <p>Stored alerts for follow-up<?php echo $financeSavedNotificationCount > 0 ? ' (' . number_format((int)$financeSavedNotificationCount) . ')' : ''; ?>.</p>
                </a>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="card" id="record-payment-section">
                    <div class="card-header">Record Payment</div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo e($dashboardUrl); ?>?section=payments#payments-section">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="record_payment">
                            <div class="form-group">
                                <label>Filter Students</label>
                                <input type="text" class="form-control" id="payment_student_filter" placeholder="Type student number, name, programme, or status" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>Student</label>
                                <select class="form-control" id="payment_student_id" name="student_id" required data-student-filter-source="payment_student_filter" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                    <option value="">Select student</option>
                                    <?php foreach ($activeStudents as $studentOption): ?>
                                        <option value="<?php echo (int)$studentOption['id']; ?>" data-search-text="<?php echo e(strtolower(trim(resolveDisplayedStudentRegistrationNumberFromRow($conn, $studentOption) . ' ' . (string)$studentOption['first_name'] . ' ' . (string)$studentOption['last_name'] . ' ' . (string)($studentOption['program_name'] ?? '') . ' ' . (string)($studentOption['academic_status'] ?? '')))); ?>">
                                            <?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $studentOption)); ?> - <?php echo e(trim((string)$studentOption['first_name'] . ' ' . (string)$studentOption['last_name'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Amount (UGX)</label>
                                        <input type="number" step="0.01" min="1" class="form-control" name="amount" required <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Payment Date</label>
                                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Payment Method</label>
                                        <select class="form-control" name="payment_method" required <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                            <option value="">Select method</option>
                                            <option value="cash">Cash</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                            <option value="bank_agent">Bank Agent (All Agents)</option>
                                            <option value="cente_agent">CenteAgent</option>
                                            <option value="mobile_money">Mobile Money</option>
                                            <option value="cheque">Cheque</option>
                                            <option value="card">Card</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Invoice (Optional)</label>
                                        <select class="form-control" id="invoice_id" name="invoice_id" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                            <option value="">No linked invoice</option>
                                            <?php foreach ($openInvoices as $invoiceOption): ?>
                                                <option value="<?php echo (int)$invoiceOption['id']; ?>" data-student="<?php echo (int)$invoiceOption['student_id']; ?>">
                                                    <?php echo e((string)$invoiceOption['invoice_number']); ?> - <?php echo e(trim(resolveDisplayedStudentRegistrationNumberFromRow($conn, $invoiceOption) . ' ' . (string)$invoiceOption['first_name'] . ' ' . (string)$invoiceOption['last_name'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Reference Number (Optional)</label>
                                <input type="text" class="form-control" name="reference_number" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>Notes (Optional)</label>
                                <textarea class="form-control" name="notes" rows="2" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block" <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                <i class="fas fa-save"></i> Save Payment
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card" id="invoice-create-section">
                    <div class="card-header">Generate Invoice</div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo e($dashboardUrl); ?>?section=invoices#invoices-section">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="generate_invoice">
                            <div class="form-group">
                                <label>Filter Students</label>
                                <input type="text" class="form-control" id="invoice_student_filter" placeholder="Type student number, name, programme, or status" <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>Student</label>
                                <select class="form-control" id="invoice_student_id" name="student_id" required data-student-filter-source="invoice_student_filter" <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>>
                                    <option value="">Select student</option>
                                    <?php foreach ($activeStudents as $studentOption): ?>
                                        <option value="<?php echo (int)$studentOption['id']; ?>" data-search-text="<?php echo e(strtolower(trim(resolveDisplayedStudentRegistrationNumberFromRow($conn, $studentOption) . ' ' . (string)$studentOption['first_name'] . ' ' . (string)$studentOption['last_name'] . ' ' . (string)($studentOption['program_name'] ?? '') . ' ' . (string)($studentOption['academic_status'] ?? '')))); ?>">
                                            <?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $studentOption)); ?> - <?php echo e(trim((string)$studentOption['first_name'] . ' ' . (string)$studentOption['last_name'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Total Amount (UGX)</label>
                                        <input type="number" step="0.01" min="1" class="form-control" name="total_amount" required <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Due Date (Optional)</label>
                                        <input type="date" class="form-control" name="due_date" <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description (Optional)</label>
                                <textarea class="form-control" name="description" rows="3" <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>></textarea>
                            </div>
                            <button type="submit" class="btn btn-success btn-block" <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-file-medical"></i> Create Invoice
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" id="payments-section">
            <div class="card-header">Recent Payments</div>
            <div class="card-body">
                <?php if (!empty($recentPayments)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Student</th>
                                    <th>Invoice</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Verification</th>
                                    <th>Date</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentPayments as $payment): ?>
                                    <?php
                                        $verificationStatus = strtolower((string)($payment['verification_status'] ?? 'verified'));
                                        $verificationClass = $verificationStatus === 'verified'
                                            ? 'success'
                                            : ($verificationStatus === 'pending' ? 'warning' : 'danger');
                                    ?>
                                    <tr>
                                        <td><?php echo e((string)$payment['payment_id']); ?></td>
                                        <td><?php echo e((string)$payment['first_name'] . ' ' . (string)$payment['last_name']); ?><br><small><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $payment)); ?></small></td>
                                        <td><?php echo e((string)($payment['invoice_number'] ?? '-')); ?></td>
                                        <td><strong><?php echo e(Helper::formatCurrency((float)$payment['amount'], 'UGX', 0)); ?></strong></td>
                                        <td><?php echo e(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td>
                                        <td><span class="badge badge-<?php echo e($verificationClass); ?>"><?php echo e(ucfirst($verificationStatus)); ?></span></td>
                                        <td><?php echo e(Helper::formatDate((string)$payment['payment_date'])); ?></td>
                                        <td><?php echo e((string)($payment['reference_number'] ?: ($payment['receipt_number'] ?: '-'))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No payments recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="bank-verification-section">
            <div class="card-header">Bank Transfer Verification Queue</div>
            <div class="card-body">
                <div class="mb-2" style="font-size:12px; color:#334155;">
                    <strong>Received:</strong> <?php echo number_format((int)$bankStatusTotals['received']); ?>
                    &nbsp;|&nbsp;
                    <strong>Verified:</strong> <?php echo number_format((int)$bankStatusTotals['verified']); ?>
                    &nbsp;|&nbsp;
                    <strong>Posted:</strong> <?php echo number_format((int)$bankStatusTotals['posted']); ?>
                    &nbsp;|&nbsp;
                    <strong>Failed:</strong> <?php echo number_format((int)$bankStatusTotals['failed']); ?>
                </div>
                <?php if (!empty($bankVerificationRows)): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2" style="gap:8px;">
                        <div class="d-flex align-items-center" style="gap:8px;">
                            <input
                                type="text"
                                id="financeBankSearch"
                                class="form-control form-control-sm"
                                placeholder="Search PRN, student, bank ref..."
                                style="min-width:240px; max-width:320px;"
                            >
                            <select id="financeBankMethodFilter" class="form-control form-control-sm" style="width:auto;">
                                <option value="" selected>All methods</option>
                                <option value="bank_agent">Bank Agent</option>
                                <option value="cente_agent">CenteAgent</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                            <select id="financeBankPageSize" class="form-control form-control-sm" style="width:auto;">
                                <option value="10" selected>10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                        </div>
                        <small id="financeBankCountInfo" class="text-muted"></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm" id="financeBankTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>PRN</th>
                                    <th>Channel</th>
                                    <th>Bank Details</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="financeBankTableBody">
                                <?php foreach ($bankVerificationRows as $bankRow): ?>
                                    <?php
                                        $status = strtolower((string)($bankRow['status'] ?? 'received'));
                                        $statusClass = $status === 'posted'
                                            ? 'success'
                                            : ($status === 'failed' ? 'danger' : ($status === 'verified' ? 'info' : 'warning'));
                                        $amountExpected = (float)($bankRow['amount_expected'] ?? 0);
                                        $amountSubmitted = $bankRow['amount_submitted'] !== null ? (float)$bankRow['amount_submitted'] : 0;
                                        $amountDisplay = $amountSubmitted > 0 ? $amountSubmitted : $amountExpected;
                                    ?>
                                    <tr data-method="<?php echo e((string)($bankRow['payment_method_label'] ?? 'bank_agent')); ?>">
                                        <td>
                                            <?php echo e((string)$bankRow['first_name'] . ' ' . (string)$bankRow['last_name']); ?><br>
                                            <small><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $bankRow)); ?></small>
                                        </td>
                                        <td>
                                            <?php echo e((string)$bankRow['reference_number']); ?><br>
                                            <small><?php echo e(strtoupper((string)($bankRow['reference_type'] ?? '-'))); ?></small>
                                        </td>
                                        <td><?php echo e(ucwords(str_replace('_', ' ', (string)($bankRow['payment_method_label'] ?? 'bank_agent')))); ?></td>
                                        <td>
                                            <strong><?php echo e((string)($bankRow['bank_name'] ?: 'Bank Transfer')); ?></strong><br>
                                            <small>Ref: <?php echo e((string)$bankRow['transfer_reference']); ?></small>
                                            <?php if (!empty($bankRow['depositor_name'])): ?>
                                                <br><small>By: <?php echo e((string)$bankRow['depositor_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo e(Helper::formatCurrency($amountDisplay, 'UGX', 0)); ?></strong>
                                            <?php if ($amountExpected > 0): ?>
                                                <br><small>Expected: <?php echo e(Helper::formatCurrency($amountExpected, 'UGX', 0)); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo e($statusClass); ?>"><?php echo e(strtoupper($status)); ?></span>
                                            <?php if (!empty($bankRow['failure_reason'])): ?>
                                                <br><small style="color:#b91c1c;"><?php echo e((string)$bankRow['failure_reason']); ?></small>
                                            <?php elseif (!empty($bankRow['verification_notes'])): ?>
                                                <br><small><?php echo e((string)$bankRow['verification_notes']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo !empty($bankRow['created_at']) ? e(date('d M Y, h:i A', strtotime((string)$bankRow['created_at']))) : '-'; ?>
                                            <?php if (!empty($bankRow['posted_at'])): ?>
                                                <br><small>Posted: <?php echo e(date('d M Y, h:i A', strtotime((string)$bankRow['posted_at']))); ?></small>
                                            <?php elseif (!empty($bankRow['verified_at'])): ?>
                                                <br><small>Verified: <?php echo e(date('d M Y, h:i A', strtotime((string)$bankRow['verified_at']))); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (in_array($status, ['received', 'verified'], true)): ?>
                                                <form method="POST" action="<?php echo e($dashboardUrl); ?>?section=bank-verification#bank-verification-section" style="display:flex; flex-direction:column; gap:6px; min-width:220px;">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="verify_bank_transaction">
                                                    <input type="hidden" name="bank_transaction_id" value="<?php echo (int)$bankRow['id']; ?>">
                                                    <input type="text" class="form-control form-control-sm" name="verified_amount" placeholder="Amount to post (optional)">
                                                    <textarea class="form-control form-control-sm" name="verification_notes" rows="2" placeholder="Verification note (optional)"></textarea>
                                                    <div style="display:flex; gap:6px;">
                                                        <button type="submit" name="decision" value="verify" class="btn btn-sm btn-success">Verify & Post</button>
                                                        <button type="submit" name="decision" value="fail" class="btn btn-sm btn-outline-danger">Mark Failed</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <?php if ($status === 'posted'): ?>
                                                    <small>Posted: <?php echo e((string)($bankRow['payment_id'] ?: ($bankRow['receipt_number'] ?: '-'))); ?></small>
                                                <?php else: ?>
                                                    <small>No action required</small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end align-items-center mt-2" style="gap:8px;">
                        <button type="button" id="financeBankPrev" class="btn btn-sm btn-outline-secondary">Previous</button>
                        <small id="financeBankPageInfo" class="text-muted">Page 1 of 1</small>
                        <button type="button" id="financeBankNext" class="btn btn-sm btn-outline-secondary">Next</button>
                    </div>
                <?php else: ?>
                    <p class="text-center mb-0">No bank transfer submissions yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card finance-payment-alerts-card" id="finance-payment-alerts-section" data-api-url="<?php echo e($paymentAlertsApiUrl); ?>" data-csrf-token="<?php echo e($paymentAlertsCsrfToken); ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-bell mr-1"></i>Payment Alerts</span>
                <div class="d-flex align-items-center" style="gap:8px;">
                    <small id="financePaymentAlertCount" class="text-muted">Unread: <?php echo number_format((int)$paymentAlertsUnreadCount); ?></small>
                    <button type="button" id="financePaymentAlertMarkAll" class="btn btn-sm btn-outline-secondary">Mark all read</button>
                </div>
            </div>
            <div class="card-body">
                <div class="finance-payment-alert-list" id="financePaymentAlertList">
                    <?php if (!empty($paymentAlerts)): ?>
                        <?php foreach ($paymentAlerts as $paymentAlert): ?>
                            <?php
                                $alertType = strtolower(trim((string)($paymentAlert['type'] ?? 'info')));
                                if (!in_array($alertType, ['info', 'success', 'warning', 'error'], true)) {
                                    $alertType = 'info';
                                }
                                $alertBadgeClass = $alertType === 'error' ? 'danger' : $alertType;
                                $alertLinkRaw = trim((string)($paymentAlert['link'] ?? ''));
                                $alertLinkSafe = '#';
                                if (
                                    $alertLinkRaw !== '' &&
                                    stripos($alertLinkRaw, 'javascript:') !== 0 &&
                                    stripos($alertLinkRaw, 'data:') !== 0 &&
                                    stripos($alertLinkRaw, 'vbscript:') !== 0 &&
                                    !preg_match('#/views/(admin|student|lecturer|finance)/logout\.php#i', $alertLinkRaw)
                                ) {
                                    $parsedAlertLink = @parse_url($alertLinkRaw);
                                    if (is_array($parsedAlertLink) && !empty($parsedAlertLink['path'])) {
                                        $alertLinkSafe = (string)$parsedAlertLink['path'];
                                        if (isset($parsedAlertLink['query']) && $parsedAlertLink['query'] !== '') {
                                            $alertLinkSafe .= '?' . $parsedAlertLink['query'];
                                        }
                                        $alertFragment = isset($parsedAlertLink['fragment']) ? (string)$parsedAlertLink['fragment'] : '';
                                        if ($alertFragment === '' && stripos((string)$parsedAlertLink['path'], '/views/finance/dashboard.php') !== false) {
                                            $alertQuery = (string)($parsedAlertLink['query'] ?? '');
                                            if (stripos($alertQuery, 'section=payments') !== false) {
                                                $alertFragment = 'payments-section';
                                            } elseif (stripos($alertQuery, 'section=bank-verification') !== false) {
                                                $alertFragment = 'bank-verification-section';
                                            }
                                        }
                                        if ($alertFragment !== '') {
                                            $alertLinkSafe .= '#' . $alertFragment;
                                        }
                                    } else {
                                        $alertLinkSafe = $alertLinkRaw;
                                    }
                                }
                            ?>
                            <div class="finance-payment-alert-item">
                                <div class="d-flex justify-content-between align-items-start" style="gap:8px;">
                                    <div>
                                        <strong><?php echo e((string)($paymentAlert['title'] ?? 'Payment Alert')); ?></strong>
                                        <div class="text-muted" style="font-size:11px;">
                                            <?php echo !empty($paymentAlert['created_at']) ? e(Helper::timeAgo((string)$paymentAlert['created_at'])) : '-'; ?>
                                        </div>
                                    </div>
                                    <span class="badge badge-<?php echo e($alertBadgeClass); ?>"><?php echo e(strtoupper($alertType)); ?></span>
                                </div>
                                <div class="finance-payment-alert-message"><?php echo e((string)($paymentAlert['message'] ?? '-')); ?></div>
                                <?php if ($alertLinkSafe !== '#'): ?>
                                    <div class="text-right mt-1">
                                        <a href="<?php echo e($alertLinkSafe); ?>" class="btn btn-sm btn-outline-primary">Open</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="finance-payment-alert-empty" id="financePaymentAlertEmpty">No unread payment alerts right now.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
            $financeChatLastId = 0;
            if (!empty($financeMessageRows)) {
                $lastFinanceChatMessage = end($financeMessageRows);
                $financeChatLastId = (int)($lastFinanceChatMessage['id'] ?? 0);
            }
        ?>
        <div class="card finance-msg-card" id="finance-messages-section" data-poll-url="<?php echo e($financeMessagePollUrl); ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-comments mr-1"></i>Student-Finance Messages</span>
                <small id="financeMsgUnreadBadge" class="text-muted">Unread: <?php echo number_format((int)$financeMessageUnreadCount); ?></small>
            </div>
            <div class="card-body">
                <?php if (!$financeMessagingService): ?>
                    <p class="text-center mb-0">Messaging service is currently unavailable.</p>
                <?php elseif (empty($financeMessageThreads)): ?>
                    <p class="text-center mb-0">No student messages yet.</p>
                <?php else: ?>
                    <div class="finance-msg-toolbar mb-2">
                        <input type="text" id="financeMsgThreadSearch" class="form-control form-control-sm" placeholder="Search student, reg no, PRN, TX, message...">
                        <label class="finance-msg-unread-toggle mb-0">
                            <input type="checkbox" id="financeMsgUnreadOnly">
                            <span>Unread only</span>
                        </label>
                        <button type="button" id="financeMsgLoadMore" class="btn btn-sm btn-outline-secondary">Load more</button>
                        <small id="financeMsgThreadMeta" class="text-muted"></small>
                    </div>
                    <div class="row finance-msg-layout">
                        <div class="col-lg-4 mb-3 mb-lg-0">
                            <div class="list-group finance-msg-thread-list" id="financeMsgThreadList">
                                <?php foreach ($financeMessageThreads as $threadSummary): ?>
                                    <?php
                                        $threadStudentId = (int)($threadSummary['student_id'] ?? 0);
                                        $threadPrn = strtoupper(trim((string)($threadSummary['prn_reference'] ?? '')));
                                        $threadTx = strtoupper(trim((string)($threadSummary['transaction_ref'] ?? '')));
                                        $threadUnread = (int)($threadSummary['unread_for_finance'] ?? 0);
                                        $threadLinkParams = [
                                            'section' => 'messages',
                                            'msg_student_id' => $threadStudentId
                                        ];
                                        if ($threadPrn !== '') {
                                            $threadLinkParams['msg_prn'] = $threadPrn;
                                        }
                                        if ($threadTx !== '') {
                                            $threadLinkParams['msg_tx'] = $threadTx;
                                        }
                                        $threadLink = $dashboardUrl . '?' . http_build_query($threadLinkParams) . '#finance-messages-section';
                                        $isActiveThread = $threadStudentId === (int)$messageStudentId;
                                        if ($isActiveThread && $messagePrn !== '') {
                                            $isActiveThread = ($threadPrn === $messagePrn);
                                        } elseif ($isActiveThread && $messageTx !== '') {
                                            $isActiveThread = ($threadTx === $messageTx);
                                        }
                                        $threadStudentName = trim((string)($threadSummary['first_name'] ?? '') . ' ' . (string)($threadSummary['last_name'] ?? ''));
                                        $threadStudentRegNo = trim((string)($threadSummary['registration_number'] ?? ''));
                                        $threadContext = $threadPrn !== '' ? ('PRN ' . $threadPrn) : ($threadTx !== '' ? ('TX ' . $threadTx) : ('Student #' . $threadStudentId));
                                        $threadLastMessage = trim((string)($threadSummary['last_message'] ?? ''));
                                        $threadPreview = $threadLastMessage;
                                        if (strlen($threadPreview) > 90) {
                                            $threadPreview = substr($threadPreview, 0, 87) . '...';
                                        }
                                    ?>
                                    <a href="<?php echo e($threadLink); ?>" class="list-group-item list-group-item-action <?php echo $isActiveThread ? 'active' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <strong><?php echo e($threadStudentName !== '' ? $threadStudentName : ('Student #' . $threadStudentId)); ?></strong>
                                            <?php if ($threadUnread > 0): ?>
                                                <span class="badge badge-danger"><?php echo (int)$threadUnread; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small>
                                            <?php echo e($threadStudentRegNo !== '' ? ($threadStudentRegNo . ' | ') : ''); ?>
                                            <?php echo e($threadContext); ?>
                                        </small><br>
                                        <small class="text-muted">
                                            <?php echo e($threadPreview !== '' ? $threadPreview : 'No message body'); ?>
                                        </small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <?php if ((int)$messageStudentId <= 0): ?>
                                <p class="mb-0">Select a thread to view and reply.</p>
                            <?php else: ?>
                                <?php
                                    $selectedContext = $messagePrn !== '' ? ('PRN ' . $messagePrn) : ($messageTx !== '' ? ('TX ' . $messageTx) : ('Student #' . (int)$messageStudentId));
                                    $financeReplyParams = [
                                        'section' => 'messages',
                                        'msg_student_id' => (int)$messageStudentId
                                    ];
                                    if ($messagePrn !== '') {
                                        $financeReplyParams['msg_prn'] = $messagePrn;
                                    }
                                    if ($messageTx !== '') {
                                        $financeReplyParams['msg_tx'] = $messageTx;
                                    }
                                    $financeReplyAction = $dashboardUrl . '?' . http_build_query($financeReplyParams) . '#finance-messages-section';
                                ?>
                                <div class="finance-msg-context" id="financeMsgContext">
                                    <strong id="financeMsgStudentName"><?php echo e($selectedMessageStudentName !== '' ? $selectedMessageStudentName : ('Student #' . (int)$messageStudentId)); ?></strong>
                                    <span class="text-muted" id="financeMsgStudentRegWrap" style="<?php echo $selectedMessageStudentRegNo !== '' ? '' : 'display:none;'; ?>">
                                        (<span id="financeMsgStudentReg"><?php echo e($selectedMessageStudentRegNo); ?></span>)
                                    </span>
                                    <br>
                                    <small class="text-muted" id="financeMsgContextLabel">Context: <?php echo e($selectedContext); ?></small>
                                </div>

                                <div class="alert alert-info" id="financeMsgEmptyAlert" style="<?php echo !empty($financeMessageRows) ? 'display:none;' : ''; ?>">No messages in this thread yet.</div>
                                <div class="finance-msg-thread" id="financeMsgThread" data-last-id="<?php echo (int)$financeChatLastId; ?>" data-message-count="<?php echo (int)count($financeMessageRows); ?>" style="<?php echo empty($financeMessageRows) ? 'display:none;' : ''; ?>">
                                    <?php foreach ($financeMessageRows as $msgRow): ?>
                                        <?php
                                            $msgRole = strtolower((string)($msgRow['sender_role'] ?? 'student'));
                                            $msgIsFinance = $msgRole === 'finance';
                                        ?>
                                        <div class="finance-msg-item <?php echo $msgIsFinance ? 'finance' : 'student'; ?>">
                                            <div class="finance-msg-meta">
                                                <?php echo $msgIsFinance ? 'Finance' : 'Student'; ?>
                                                -
                                                <?php echo !empty($msgRow['created_at']) ? e(date('d M Y, h:i A', strtotime((string)$msgRow['created_at']))) : '-'; ?>
                                            </div>
                                            <div class="finance-msg-bubble"><?php echo nl2br(e((string)($msgRow['message_text'] ?? ''))); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <form method="POST" action="<?php echo e($financeReplyAction); ?>" class="finance-msg-reply-form" id="financeMsgReplyForm">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="finance_reply_student_message">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$messageStudentId; ?>">
                                    <input type="hidden" name="prn_reference" value="<?php echo e($messagePrn); ?>">
                                    <input type="hidden" name="transaction_ref" value="<?php echo e($messageTx); ?>">
                                    <textarea class="form-control mb-2" name="message_text" rows="2" maxlength="2000" placeholder="Reply..." required></textarea>
                                    <div class="text-right">
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i> Send Reply</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="finance-saved-notifications-section">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Saved Notifications (Finance Archive)</span>
                <small class="text-muted">Stored: <?php echo number_format((int)$financeSavedNotificationCount); ?></small>
            </div>
            <div class="card-body">
                <?php if (!empty($financeSavedNotifications)): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2" style="gap:8px;">
                        <div class="d-flex align-items-center" style="gap:8px;">
                            <input
                                type="text"
                                id="financeSavedNotifSearch"
                                class="form-control form-control-sm"
                                placeholder="Search title, message, type..."
                                style="min-width:240px; max-width:320px;"
                            >
                            <select id="financeSavedNotifPageSize" class="form-control form-control-sm" style="width:auto;">
                                <option value="10" selected>10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                        </div>
                        <small id="financeSavedNotifCountInfo" class="text-muted"></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm" id="financeSavedNotifTable">
                            <thead>
                                <tr>
                                    <th>Saved At</th>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Message</th>
                                    <th>Original Time</th>
                                    <th>Link</th>
                                </tr>
                            </thead>
                            <tbody id="financeSavedNotifTableBody">
                                <?php foreach ($financeSavedNotifications as $savedNotif): ?>
                                    <?php
                                        $savedType = strtolower(trim((string)($savedNotif['type'] ?? 'info')));
                                        if (!in_array($savedType, ['info', 'success', 'warning', 'error'], true)) {
                                            $savedType = 'info';
                                        }
                                        $badgeClass = $savedType === 'success'
                                            ? 'success'
                                            : ($savedType === 'warning' ? 'warning' : ($savedType === 'error' ? 'danger' : 'info'));
                                        $linkRaw = trim((string)($savedNotif['link'] ?? ''));
                                        $linkSafe = '#';
                                        if (
                                            $linkRaw !== '' &&
                                            stripos($linkRaw, 'javascript:') !== 0 &&
                                            stripos($linkRaw, 'data:') !== 0 &&
                                            stripos($linkRaw, 'vbscript:') !== 0 &&
                                            !preg_match('#/views/(admin|student|lecturer|finance)/logout\.php#i', $linkRaw)
                                        ) {
                                            $parsedOpenLink = @parse_url($linkRaw);
                                            if (is_array($parsedOpenLink) && !empty($parsedOpenLink['path'])) {
                                                $linkSafe = (string)$parsedOpenLink['path'];
                                                if (isset($parsedOpenLink['query']) && $parsedOpenLink['query'] !== '') {
                                                    $linkSafe .= '?' . $parsedOpenLink['query'];
                                                }
                                                if (isset($parsedOpenLink['fragment']) && $parsedOpenLink['fragment'] !== '') {
                                                    $linkSafe .= '#' . $parsedOpenLink['fragment'];
                                                }
                                            } else {
                                                $linkSafe = $linkRaw;
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo !empty($savedNotif['archived_at']) ? e(date('d M Y, h:i A', strtotime((string)$savedNotif['archived_at']))) : '-'; ?></td>
                                        <td><span class="badge badge-<?php echo e($badgeClass); ?>"><?php echo e(strtoupper($savedType)); ?></span></td>
                                        <td><?php echo e((string)($savedNotif['title'] ?? '-')); ?></td>
                                        <td><?php echo e((string)($savedNotif['message'] ?? '-')); ?></td>
                                        <td><?php echo !empty($savedNotif['original_created_at']) ? e(date('d M Y, h:i A', strtotime((string)$savedNotif['original_created_at']))) : '-'; ?></td>
                                        <td>
                                            <?php if ($linkSafe !== '#'): ?>
                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo e($linkSafe); ?>">Open</a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end align-items-center mt-2" style="gap:8px;">
                        <button type="button" id="financeSavedNotifPrev" class="btn btn-sm btn-outline-secondary">Previous</button>
                        <small id="financeSavedNotifPageInfo" class="text-muted">Page 1 of 1</small>
                        <button type="button" id="financeSavedNotifNext" class="btn btn-sm btn-outline-secondary">Next</button>
                    </div>
                <?php else: ?>
                    <p class="mb-2">No saved notifications yet.</p>
                    <small class="text-muted">Use the <strong>Save</strong> button in the notification bell dropdown to store alerts here.</small>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="invoices-section">
            <div class="card-header">Recent Invoices</div>
            <div class="card-body">
                <?php if (!empty($recentInvoices)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Student</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentInvoices as $invoice): ?>
                                    <?php
                                        $status = strtolower((string)($invoice['status'] ?? 'pending'));
                                        $statusClass = $status === 'paid' ? 'success' : ($status === 'partial' ? 'warning' : ($status === 'overdue' ? 'danger' : 'info'));
                                    ?>
                                    <tr>
                                        <td><?php echo e((string)$invoice['invoice_number']); ?></td>
                                        <td><?php echo e((string)$invoice['first_name'] . ' ' . (string)$invoice['last_name']); ?><br><small><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $invoice)); ?></small></td>
                                        <td><?php echo e(Helper::formatCurrency((float)$invoice['total_amount'], 'UGX', 0)); ?></td>
                                        <td><?php echo e(Helper::formatCurrency((float)$invoice['amount_paid'], 'UGX', 0)); ?></td>
                                        <td><?php echo e(Helper::formatCurrency((float)$invoice['balance'], 'UGX', 0)); ?></td>
                                        <td><span class="badge badge-<?php echo e($statusClass); ?>"><?php echo e(ucfirst($status)); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No invoices available for the current semester.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="balances-section">
            <div class="card-header">Student Balances (Registered Students - Current Semester)</div>
            <div class="card-body">
                <?php if (!empty($studentBalances)): ?>
                    <div class="mb-2" style="font-size:12px; color:#334155;">
                        <strong>Total Outstanding (UGX base):</strong>
                        <?php echo e(Helper::formatCurrency($outstandingBalance, 'UGX', 0)); ?>
                        &nbsp;|&nbsp;
                        <strong>Students with Outstanding:</strong>
                        <?php echo number_format($studentsWithBalance); ?>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2" style="gap:8px;">
                        <div class="d-flex align-items-center" style="gap:8px;">
                            <input
                                type="text"
                                id="financeBalanceSearch"
                                class="form-control form-control-sm"
                                placeholder="Search student, ID, currency..."
                                style="min-width:240px; max-width:320px;"
                            >
                            <select id="financeBalancePageSize" class="form-control form-control-sm" style="width:auto;">
                                <option value="10" selected>10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                        </div>
                        <small id="financeBalanceCountInfo" class="text-muted"></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm" id="financeBalanceTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Currency</th>
                                    <th>Total Fees</th>
                                    <th>Total Paid</th>
                                    <th>Outstanding Balance</th>
                                </tr>
                            </thead>
                            <tbody id="financeBalanceTableBody">
                                <?php foreach ($studentBalances as $balance): ?>
                                    <?php $displayCurrency = (string)($balance['display_currency'] ?? 'UGX'); ?>
                                    <tr>
                                        <td><?php echo e((string)$balance['first_name'] . ' ' . (string)$balance['last_name']); ?><br><small><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $balance)); ?></small></td>
                                        <td><?php echo e($displayCurrency); ?></td>
                                        <td><?php echo e(formatAmountFromUgxForDisplayCurrency((float)$balance['total_fees'], $displayCurrency, $usdUgxRate)); ?></td>
                                        <td><?php echo e(formatAmountFromUgxForDisplayCurrency((float)$balance['total_paid'], $displayCurrency, $usdUgxRate)); ?></td>
                                        <td><strong><?php echo e(formatAmountFromUgxForDisplayCurrency((float)$balance['balance'], $displayCurrency, $usdUgxRate)); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end align-items-center mt-2" style="gap:8px;">
                        <button type="button" id="financeBalancePrev" class="btn btn-sm btn-outline-secondary">Previous</button>
                        <small id="financeBalancePageInfo" class="text-muted">Page 1 of 1</small>
                        <button type="button" id="financeBalanceNext" class="btn btn-sm btn-outline-secondary">Next</button>
                    </div>
                <?php else: ?>
                    <p class="text-center">No active student balance records for the current semester.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="reports-section">
            <div class="card-header">Collections by Month</div>
            <div class="card-body">
                <?php if (!empty($collectionsByMonth)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Total Collections</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($collectionsByMonth as $monthRow): ?>
                                    <tr>
                                        <td><?php echo e(date('F Y', strtotime((string)$monthRow['period'] . '-01'))); ?></td>
                                        <td><?php echo e(Helper::formatCurrency((float)$monthRow['total'], 'UGX', 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No collection records found for this year.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.main-content.finance-dashboard,
.finance-dashboard .content-area {
    min-width: 0;
    overflow-x: clip;
}

.finance-dashboard .stats-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}

.finance-dashboard .stats-grid .stat-card {
    min-width: 0;
    min-height: 86px;
    padding: 10px 12px;
    gap: 10px;
}

.finance-dashboard .stats-grid .stat-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    margin: 0;
    border-radius: 9px;
    font-size: 14px;
}

.finance-dashboard .stats-grid .stat-details h3 {
    font-size: 17px;
    line-height: 1.1;
    margin: 0;
}

.finance-dashboard .stats-grid .stat-details p {
    font-size: 11px;
    margin: 2px 0 0;
}

.finance-dashboard .action-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}

.finance-dashboard .action-grid .action-card {
    min-width: 0;
    min-height: 88px;
    padding: 10px 12px;
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr);
    grid-template-areas:
        "icon title"
        "desc desc";
    column-gap: 10px;
    row-gap: 4px;
    align-items: center;
}

.finance-dashboard .action-grid .action-card .action-icon {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    margin: 0;
    border-radius: 9px;
    grid-area: icon;
}

.finance-dashboard .action-grid .action-card h4 {
    margin: 0;
    font-size: 14px;
    grid-area: title;
    line-height: 1.2;
}

.finance-dashboard .action-grid .action-card p {
    margin: 0;
    font-size: 11px;
    line-height: 1.3;
    grid-area: desc;
    overflow-wrap: anywhere;
}

.finance-dashboard .table-responsive {
    width: 100%;
    overflow-x: auto;
}

.finance-dashboard table th,
.finance-dashboard table td {
    font-size: 12px;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
    vertical-align: middle;
}

.finance-dashboard .collection-rate-bar {
    transition: none !important;
}

.finance-payment-alerts-card .card-body {
    padding: 10px;
}

.finance-payment-alert-list {
    display: grid;
    gap: 8px;
    max-height: 240px;
    overflow-y: auto;
}

.finance-payment-alert-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    padding: 8px;
}

.finance-payment-alert-message {
    margin-top: 4px;
    font-size: 12px;
    color: #334155;
    line-height: 1.35;
}

.finance-payment-alert-empty {
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px;
    color: #64748b;
    font-size: 12px;
}

.finance-msg-thread-list {
    max-height: 300px;
    overflow-y: auto;
}

.finance-msg-card .card-body {
    padding: 10px;
}

.finance-msg-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) auto auto auto;
    gap: 8px;
    align-items: center;
}

.finance-msg-unread-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #334155;
    white-space: nowrap;
}

.finance-msg-unread-toggle input[type="checkbox"] {
    margin: 0;
}

.finance-msg-layout {
    align-items: stretch;
}

.finance-msg-context {
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 8px;
    padding: 6px 8px;
    margin-bottom: 8px;
}

.finance-msg-thread {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    max-height: 230px;
    overflow-y: auto;
    padding: 8px;
    margin-bottom: 8px;
}

.finance-msg-item {
    margin-bottom: 7px;
}

.finance-msg-item:last-child {
    margin-bottom: 0;
}

.finance-msg-meta {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 2px;
}

.finance-msg-bubble {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 8px;
    font-size: 12px;
    line-height: 1.3;
}

.finance-msg-item.student .finance-msg-bubble {
    background: #f8fafc;
}

.finance-msg-item.finance .finance-msg-bubble {
    background: #e0f2fe;
    border-color: #93c5fd;
}

html[data-theme='dark'] #finance-messages-section {
    background: #0f172a;
    border-color: #334155;
}

html[data-theme='dark'] #finance-messages-section .card-header {
    background: #111827;
    border-bottom-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-messages-section .card-body {
    background: #0f172a;
}

html[data-theme='dark'] #finance-messages-section .text-muted {
    color: #94a3b8 !important;
}

html[data-theme='dark'] #finance-payment-alerts-section {
    background: #0f172a;
    border-color: #334155;
}

html[data-theme='dark'] #finance-payment-alerts-section .card-header {
    background: #111827;
    border-bottom-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-payment-alerts-section .card-body {
    background: #0f172a;
}

html[data-theme='dark'] .finance-payment-alert-item {
    background: #111827;
    border-color: #334155;
}

html[data-theme='dark'] .finance-payment-alert-message {
    color: #cbd5e1;
}

html[data-theme='dark'] .finance-payment-alert-empty {
    border-color: #334155;
    color: #94a3b8;
}

html[data-theme='dark'] .finance-msg-unread-toggle {
    color: #cbd5e1;
}

html[data-theme='dark'] #finance-messages-section .list-group-item {
    background: #111827;
    border-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-messages-section .list-group-item:hover {
    background: #1e293b;
}

html[data-theme='dark'] #finance-messages-section .list-group-item.active {
    background: #1d4ed8;
    border-color: #2563eb;
    color: #ffffff;
}

html[data-theme='dark'] #finance-messages-section .list-group-item.active small,
html[data-theme='dark'] #finance-messages-section .list-group-item.active .text-muted {
    color: #dbeafe !important;
}

html[data-theme='dark'] .finance-msg-context {
    background: #111827;
    border-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] .finance-msg-thread {
    background: #0b1220;
    border-color: #334155;
}

html[data-theme='dark'] .finance-msg-meta {
    color: #94a3b8;
}

html[data-theme='dark'] .finance-msg-bubble {
    background: #1e293b;
    border-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] .finance-msg-item.student .finance-msg-bubble {
    background: #172554;
    border-color: #1d4ed8;
    color: #dbeafe;
}

html[data-theme='dark'] .finance-msg-item.finance .finance-msg-bubble {
    background: #0c4a6e;
    border-color: #075985;
    color: #e0f2fe;
}

html[data-theme='dark'] .finance-msg-reply-form textarea.form-control {
    background: #111827;
    border-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] .finance-msg-reply-form textarea.form-control::placeholder {
    color: #94a3b8;
}

html[data-theme='dark'] #finance-saved-notifications-section {
    background: #0f172a;
    border-color: #334155;
}

html[data-theme='dark'] #finance-saved-notifications-section .card-header {
    background: #111827;
    border-bottom-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-saved-notifications-section .card-body {
    background: #0f172a;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-saved-notifications-section .table th,
html[data-theme='dark'] #finance-saved-notifications-section .table td {
    border-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-saved-notifications-section .table-hover tbody tr:hover {
    background: #1e293b;
}

html[data-theme='dark'] #finance-saved-notifications-section .form-control {
    background: #111827;
    border-color: #334155;
    color: #e2e8f0;
}

html[data-theme='dark'] #finance-saved-notifications-section .form-control::placeholder {
    color: #94a3b8;
}

html[data-theme='dark'] #finance-saved-notifications-section .btn-outline-primary {
    color: #93c5fd;
    border-color: #3b82f6;
}

html[data-theme='dark'] #finance-saved-notifications-section .btn-outline-primary:hover,
html[data-theme='dark'] #finance-saved-notifications-section .btn-outline-primary:focus {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #ffffff;
}

html[data-theme='dark'] #finance-saved-notifications-section .text-muted {
    color: #94a3b8 !important;
}

@media (max-width: 1200px) {
    .finance-dashboard .stats-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 992px) {
    .finance-msg-toolbar {
        grid-template-columns: minmax(0, 1fr) auto;
    }
}

@media (max-width: 992px) {
    .finance-dashboard .action-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .finance-dashboard .stats-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .finance-dashboard .action-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .finance-dashboard .stats-grid .stat-card,
    .finance-dashboard .action-grid .action-card {
        min-height: 76px;
        padding: 8px 10px;
        column-gap: 8px;
        row-gap: 4px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var studentSelect = document.getElementById('payment_student_id');
    var invoiceSelect = document.getElementById('invoice_id');

    function filterInvoicesByStudent() {
        if (!studentSelect || !invoiceSelect) return;
        var selectedStudent = studentSelect.value;
        var options = invoiceSelect.querySelectorAll('option');
        options.forEach(function(option, idx) {
            if (idx === 0) {
                option.hidden = false;
                return;
            }
            var optionStudent = option.getAttribute('data-student');
            option.hidden = !!selectedStudent && optionStudent !== selectedStudent;
        });

        if (invoiceSelect.selectedOptions.length > 0 && invoiceSelect.selectedOptions[0].hidden) {
            invoiceSelect.value = '';
        }
    }

    function initSearchableStudentSelect(filterInputId, selectId) {
        var filterInput = document.getElementById(filterInputId);
        var select = document.getElementById(selectId);
        if (!filterInput || !select) return;

        var options = Array.prototype.slice.call(select.querySelectorAll('option'));
        function renderStudentOptions() {
            var query = String(filterInput.value || '').toLowerCase().trim();
            options.forEach(function(option, idx) {
                if (idx === 0) {
                    option.hidden = false;
                    return;
                }
                var haystack = String(option.getAttribute('data-search-text') || option.textContent || '').toLowerCase();
                option.hidden = query !== '' && haystack.indexOf(query) === -1;
            });

            var selectedOption = select.options[select.selectedIndex] || null;
            if (selectedOption && selectedOption.hidden) {
                select.value = '';
            }
        }

        filterInput.addEventListener('input', renderStudentOptions);
        renderStudentOptions();
    }

    if (studentSelect && invoiceSelect) {
        studentSelect.addEventListener('change', filterInvoicesByStudent);
        filterInvoicesByStudent();
    }
    initSearchableStudentSelect('payment_student_filter', 'payment_student_id');
    initSearchableStudentSelect('invoice_student_filter', 'invoice_student_id');

    function initTableSearchPagination(config) {
        var tbody = document.getElementById(config.tbodyId);
        var searchInput = document.getElementById(config.searchId);
        var pageSizeSelect = document.getElementById(config.pageSizeId);
        var methodFilterSelect = config.methodFilterId ? document.getElementById(config.methodFilterId) : null;
        var prevBtn = document.getElementById(config.prevId);
        var nextBtn = document.getElementById(config.nextId);
        var pageInfo = document.getElementById(config.pageInfoId);
        var countInfo = document.getElementById(config.countInfoId);
        if (!tbody || !searchInput || !pageSizeSelect || !prevBtn || !nextBtn || !pageInfo || !countInfo) {
            return;
        }

        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var currentPage = 1;

        function render() {
            var query = (searchInput.value || '').toLowerCase().trim();
            var pageSize = parseInt(pageSizeSelect.value, 10) || 10;
            var methodFilter = methodFilterSelect ? (methodFilterSelect.value || '').toLowerCase().trim() : '';

            var filteredRows = allRows.filter(function(row) {
                if (query && (row.textContent || '').toLowerCase().indexOf(query) === -1) {
                    return false;
                }
                if (methodFilter) {
                    var rowMethod = (row.getAttribute('data-method') || '').toLowerCase().trim();
                    if (rowMethod !== methodFilter) {
                        return false;
                    }
                }
                return true;
            });

            var totalRows = filteredRows.length;
            var totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            var startIdx = (currentPage - 1) * pageSize;
            var endIdx = startIdx + pageSize;

            allRows.forEach(function(row) { row.style.display = 'none'; });
            filteredRows.slice(startIdx, endIdx).forEach(function(row) { row.style.display = ''; });

            var from = totalRows === 0 ? 0 : (startIdx + 1);
            var to = totalRows === 0 ? 0 : Math.min(endIdx, totalRows);
            countInfo.textContent = totalRows === 0
                ? 'No matching records'
                : ('Showing ' + from + '-' + to + ' of ' + totalRows);

            pageInfo.textContent = totalRows === 0
                ? 'Page 0 of 0'
                : ('Page ' + currentPage + ' of ' + totalPages);
            prevBtn.disabled = currentPage <= 1 || totalRows === 0;
            nextBtn.disabled = currentPage >= totalPages || totalRows === 0;
        }

        searchInput.addEventListener('input', function() {
            currentPage = 1;
            render();
        });
        pageSizeSelect.addEventListener('change', function() {
            currentPage = 1;
            render();
        });
        if (methodFilterSelect) {
            methodFilterSelect.addEventListener('change', function() {
                currentPage = 1;
                render();
            });
        }
        prevBtn.addEventListener('click', function() {
            currentPage -= 1;
            render();
        });
        nextBtn.addEventListener('click', function() {
            currentPage += 1;
            render();
        });

        render();
    }

    initTableSearchPagination({
        tbodyId: 'financeBalanceTableBody',
        searchId: 'financeBalanceSearch',
        pageSizeId: 'financeBalancePageSize',
        prevId: 'financeBalancePrev',
        nextId: 'financeBalanceNext',
        pageInfoId: 'financeBalancePageInfo',
        countInfoId: 'financeBalanceCountInfo'
    });

    initTableSearchPagination({
        tbodyId: 'financeBankTableBody',
        searchId: 'financeBankSearch',
        pageSizeId: 'financeBankPageSize',
        methodFilterId: 'financeBankMethodFilter',
        prevId: 'financeBankPrev',
        nextId: 'financeBankNext',
        pageInfoId: 'financeBankPageInfo',
        countInfoId: 'financeBankCountInfo'
    });

    initTableSearchPagination({
        tbodyId: 'financeSavedNotifTableBody',
        searchId: 'financeSavedNotifSearch',
        pageSizeId: 'financeSavedNotifPageSize',
        prevId: 'financeSavedNotifPrev',
        nextId: 'financeSavedNotifNext',
        pageInfoId: 'financeSavedNotifPageInfo',
        countInfoId: 'financeSavedNotifCountInfo'
    });

    function initFinancePaymentAlertsPolling() {
        var sectionEl = document.getElementById('finance-payment-alerts-section');
        var listEl = document.getElementById('financePaymentAlertList');
        var countEl = document.getElementById('financePaymentAlertCount');
        var markAllBtn = document.getElementById('financePaymentAlertMarkAll');
        if (!sectionEl || !listEl || !countEl || typeof window.fetch !== 'function') return;

        var apiUrl = sectionEl.getAttribute('data-api-url') || '';
        var csrfToken = sectionEl.getAttribute('data-csrf-token') || '';
        if (!apiUrl) return;

        var inFlight = false;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function typeBadgeClass(type) {
            var clean = String(type || 'info').toLowerCase();
            if (clean === 'error') return 'danger';
            if (clean === 'success') return 'success';
            if (clean === 'warning') return 'warning';
            return 'info';
        }

        function safeLink(link) {
            var url = String(link || '').trim();
            if (!url || url === '#' || /^javascript:/i.test(url) || /^data:/i.test(url) || /^vbscript:/i.test(url)) {
                return '#';
            }
            return url;
        }

        function renderAlerts(alerts) {
            var rows = Array.isArray(alerts) ? alerts : [];
            if (!rows.length) {
                listEl.innerHTML = '<div class="finance-payment-alert-empty" id="financePaymentAlertEmpty">No unread payment alerts right now.</div>';
                return;
            }

            var html = '';
            rows.forEach(function(alert) {
                var title = escapeHtml(alert && alert.title ? alert.title : 'Payment Alert');
                var message = escapeHtml(alert && alert.message ? alert.message : '-');
                var type = String(alert && alert.type ? alert.type : 'info').toLowerCase();
                var badgeClass = typeBadgeClass(type);
                var typeLabel = escapeHtml(type.toUpperCase());
                var timeAgo = escapeHtml(alert && alert.time_ago ? alert.time_ago : '-');
                var link = safeLink(alert && alert.link ? alert.link : '#');

                html += '<div class="finance-payment-alert-item">';
                html += '<div class="d-flex justify-content-between align-items-start" style="gap:8px;">';
                html += '<div><strong>' + title + '</strong><div class="text-muted" style="font-size:11px;">' + timeAgo + '</div></div>';
                html += '<span class="badge badge-' + badgeClass + '">' + typeLabel + '</span>';
                html += '</div>';
                html += '<div class="finance-payment-alert-message">' + message + '</div>';
                if (link !== '#') {
                    html += '<div class="text-right mt-1"><a href="' + escapeHtml(link) + '" class="btn btn-sm btn-outline-primary">Open</a></div>';
                }
                html += '</div>';
            });
            listEl.innerHTML = html;
        }

        function poll() {
            if (inFlight) return;
            inFlight = true;

            var glue = apiUrl.indexOf('?') === -1 ? '?' : '&';
            fetch(apiUrl + glue + 'limit=8', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) { return response.json(); })
            .then(function(payload) {
                if (!payload || !payload.success) return;
                var unreadCount = Number(payload.unread_count || 0);
                countEl.textContent = 'Unread: ' + String(unreadCount);
                if (markAllBtn) {
                    markAllBtn.disabled = unreadCount <= 0;
                }
                renderAlerts(payload.alerts || []);
            })
            .catch(function() {})
            .finally(function() {
                inFlight = false;
            });
        }

        if (markAllBtn) {
            markAllBtn.addEventListener('click', function() {
                if (inFlight || !csrfToken) return;
                inFlight = true;
                markAllBtn.disabled = true;
                var originalText = markAllBtn.textContent;
                markAllBtn.textContent = 'Marking...';

                fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        action: 'mark_all_read',
                        csrf_token: csrfToken
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(payload) {
                    if (!payload || !payload.success) return;
                    countEl.textContent = 'Unread: 0';
                    renderAlerts([]);
                    poll();
                })
                .catch(function() {})
                .finally(function() {
                    inFlight = false;
                    markAllBtn.textContent = originalText;
                    poll();
                });
            });
        }

        poll();
        window.setInterval(poll, 3500);
    }

    function initFinanceMessagePolling() {
        var sectionEl = document.getElementById('finance-messages-section');
        var listEl = document.getElementById('financeMsgThreadList');
        if (!sectionEl || !listEl || typeof window.fetch !== 'function') return;

        var pollUrl = sectionEl.getAttribute('data-poll-url') || '';
        if (!pollUrl) return;

        var unreadBadge = document.getElementById('financeMsgUnreadBadge');
        var threadSearchInput = document.getElementById('financeMsgThreadSearch');
        var unreadOnlyInput = document.getElementById('financeMsgUnreadOnly');
        var loadMoreBtn = document.getElementById('financeMsgLoadMore');
        var threadMetaEl = document.getElementById('financeMsgThreadMeta');
        var threadEl = document.getElementById('financeMsgThread');
        var emptyEl = document.getElementById('financeMsgEmptyAlert');
        var contextNameEl = document.getElementById('financeMsgStudentName');
        var contextRegWrapEl = document.getElementById('financeMsgStudentRegWrap');
        var contextRegEl = document.getElementById('financeMsgStudentReg');
        var contextLabelEl = document.getElementById('financeMsgContextLabel');
        var replyForm = document.getElementById('financeMsgReplyForm');
        var inFlight = false;
        var THREAD_PAGE_SIZE = 30;
        var threadState = {
            query: '',
            unreadOnly: false,
            loadedRows: [],
            hasMore: false
        };

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function selectedContext() {
            var out = {
                studentId: 0,
                prn: '',
                tx: ''
            };
            if (!replyForm) return out;
            var studentInput = replyForm.querySelector('input[name="student_id"]');
            var prnInput = replyForm.querySelector('input[name="prn_reference"]');
            var txInput = replyForm.querySelector('input[name="transaction_ref"]');
            out.studentId = studentInput ? parseInt(studentInput.value || '0', 10) || 0 : 0;
            out.prn = prnInput ? String(prnInput.value || '').toUpperCase() : '';
            out.tx = txInput ? String(txInput.value || '').toUpperCase() : '';
            return out;
        }

        function isActiveThread(thread, selected) {
            if (!thread || !selected) return false;
            var studentMatch = Number(thread.student_id || 0) === Number(selected.studentId || 0);
            if (!studentMatch) return false;
            var threadPrn = String(thread.prn_reference || '').toUpperCase();
            var threadTx = String(thread.transaction_ref || '').toUpperCase();
            if (selected.prn) return threadPrn === selected.prn;
            if (selected.tx) return threadTx === selected.tx;
            return true;
        }

        function renderThreads(threads) {
            var selected = selectedContext();
            var rows = Array.isArray(threads) ? threads : [];
            if (!rows.length) {
                listEl.innerHTML = '<div class="list-group-item">No student messages yet.</div>';
                if (threadMetaEl) {
                    threadMetaEl.textContent = 'Showing 0 threads';
                }
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = true;
                    loadMoreBtn.style.display = 'none';
                }
                return;
            }

            var html = '';
            rows.forEach(function(thread) {
                var active = isActiveThread(thread, selected);
                var unread = Number(thread.unread_for_finance || 0);
                var regNo = thread.registration_number ? String(thread.registration_number) : '';
                html += '<a href="' + escapeHtml(thread.thread_url || '#') + '" class="list-group-item list-group-item-action ' + (active ? 'active' : '') + '">';
                html += '<div class="d-flex justify-content-between align-items-start">';
                html += '<strong>' + escapeHtml(thread.student_name || ('Student #' + String(thread.student_id || ''))) + '</strong>';
                if (unread > 0) {
                    html += '<span class="badge badge-danger">' + String(unread) + '</span>';
                }
                html += '</div>';
                if (regNo) {
                    html += '<small>' + escapeHtml(regNo) + ' | </small>';
                }
                html += '<small>' + escapeHtml(thread.context_label || '') + '</small><br>';
                html += '<small class="text-muted">' + escapeHtml(thread.last_message_preview || 'No message body') + '</small>';
                html += '</a>';
            });
            listEl.innerHTML = html;
            if (threadMetaEl) {
                threadMetaEl.textContent = 'Showing ' + String(rows.length) + ' thread' + (rows.length === 1 ? '' : 's');
            }
            if (loadMoreBtn) {
                loadMoreBtn.disabled = !threadState.hasMore || inFlight;
                loadMoreBtn.style.display = threadState.hasMore ? '' : 'none';
            }
        }

        function renderMessages(messages, lastMessageId) {
            if (!threadEl || !emptyEl) return;

            var rows = Array.isArray(messages) ? messages : [];
            threadEl.setAttribute('data-last-id', String(lastMessageId || 0));
            threadEl.setAttribute('data-message-count', String(rows.length));

            if (!rows.length) {
                threadEl.innerHTML = '';
                threadEl.style.display = 'none';
                emptyEl.style.display = '';
                return;
            }

            var html = '';
            rows.forEach(function(msg) {
                var role = String(msg && msg.sender_role ? msg.sender_role : 'student').toLowerCase();
                var isFinance = role === 'finance';
                var sender = isFinance ? 'Finance' : 'Student';
                var label = msg && msg.created_at_label ? msg.created_at_label : '-';
                var text = escapeHtml(msg && msg.message_text ? msg.message_text : '').replace(/\n/g, '<br>');
                html += '<div class="finance-msg-item ' + (isFinance ? 'finance' : 'student') + '">';
                html += '<div class="finance-msg-meta">' + sender + ' - ' + escapeHtml(label) + '</div>';
                html += '<div class="finance-msg-bubble">' + text + '</div>';
                html += '</div>';
            });
            threadEl.innerHTML = html;
            threadEl.style.display = '';
            emptyEl.style.display = 'none';
            threadEl.scrollTop = threadEl.scrollHeight;
        }

        function applySelectedContext(selected) {
            if (!selected || !replyForm) return;

            var studentId = Number(selected.student_id || 0);
            var prn = String(selected.prn_reference || '').toUpperCase();
            var tx = String(selected.transaction_ref || '').toUpperCase();

            var studentInput = replyForm.querySelector('input[name="student_id"]');
            var prnInput = replyForm.querySelector('input[name="prn_reference"]');
            var txInput = replyForm.querySelector('input[name="transaction_ref"]');
            if (studentInput && studentId > 0) studentInput.value = String(studentId);
            if (prnInput) prnInput.value = prn;
            if (txInput) txInput.value = tx;

            if (contextNameEl && selected.student_name) {
                contextNameEl.textContent = String(selected.student_name);
            }
            if (contextRegWrapEl && contextRegEl) {
                var regNo = String(selected.registration_number || '');
                contextRegEl.textContent = regNo;
                contextRegWrapEl.style.display = regNo !== '' ? '' : 'none';
            }
            if (contextLabelEl && selected.context_label) {
                contextLabelEl.textContent = 'Context: ' + String(selected.context_label);
            }
        }

        function buildThreadPollUrl(offset, limit) {
            var glue = pollUrl.indexOf('?') === -1 ? '?' : '&';
            var query = threadState.query ? ('&thread_q=' + encodeURIComponent(threadState.query)) : '';
            var unreadOnly = threadState.unreadOnly ? '&unread_only=1' : '';
            return pollUrl + glue + 'thread_offset=' + String(Math.max(0, offset)) + '&thread_limit=' + String(Math.max(1, limit)) + query + unreadOnly;
        }

        function poll(options) {
            options = options || {};
            var append = !!options.append;
            if (inFlight) return;
            inFlight = true;

            var currentCount = threadState.loadedRows.length;
            var offset = append ? currentCount : 0;
            var limit = append ? THREAD_PAGE_SIZE : Math.max(THREAD_PAGE_SIZE, currentCount || THREAD_PAGE_SIZE);

            if (loadMoreBtn) {
                loadMoreBtn.disabled = true;
                if (append) {
                    loadMoreBtn.textContent = 'Loading...';
                }
            }

            fetch(buildThreadPollUrl(offset, limit), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) { return response.json(); })
            .then(function(payload) {
                if (!payload || !payload.success) return;

                if (unreadBadge) {
                    unreadBadge.textContent = 'Unread: ' + String(Number(payload.unread_total || 0));
                }
                var incomingThreads = Array.isArray(payload.threads) ? payload.threads : [];
                if (append) {
                    threadState.loadedRows = threadState.loadedRows.concat(incomingThreads);
                } else {
                    threadState.loadedRows = incomingThreads;
                }
                threadState.hasMore = !!payload.threads_has_more;
                renderThreads(threadState.loadedRows);
                applySelectedContext(payload.selected || null);

                if (threadEl) {
                    var nextLastId = Number(payload.last_message_id || 0);
                    var nextCount = Number(payload.message_count || 0);
                    var currentLastId = Number(threadEl.getAttribute('data-last-id') || 0);
                    var currentCount = Number(threadEl.getAttribute('data-message-count') || 0);
                    if (nextLastId !== currentLastId || nextCount !== currentCount) {
                        renderMessages(payload.messages || [], nextLastId);
                    }
                }
            })
            .catch(function() {})
            .finally(function() {
                inFlight = false;
                if (loadMoreBtn) {
                    loadMoreBtn.textContent = 'Load more';
                    loadMoreBtn.disabled = !threadState.hasMore;
                }
            });
        }

        if (threadSearchInput) {
            threadSearchInput.addEventListener('input', function() {
                threadState.query = String(threadSearchInput.value || '').trim();
                threadState.loadedRows = [];
                threadState.hasMore = false;
                poll({ append: false });
            });
        }

        if (unreadOnlyInput) {
            unreadOnlyInput.addEventListener('change', function() {
                threadState.unreadOnly = !!unreadOnlyInput.checked;
                threadState.loadedRows = [];
                threadState.hasMore = false;
                poll({ append: false });
            });
        }

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                if (!threadState.hasMore || inFlight) return;
                poll({ append: true });
            });
        }

        poll({ append: false });
        window.setInterval(function() { poll({ append: false }); }, 8000);
    }

    initFinancePaymentAlertsPolling();
    initFinanceMessagePolling();
});
</script>

<?php include '../../includes/footer.php'; ?>

