<?php
/**
 * Finance Dashboard
 */
require_once '../../config.php';

$session = new Session('finance');
$auth = new Auth('finance');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    header('Location: ' . BASE_URL . '/views/finance/login.php?error=session_expired');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token. Please retry.');
        header('Location: ' . $dashboardUrl);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

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
    SELECT s.id, s.student_id, s.first_name, s.last_name
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.status = 'active' AND u.status = 'active'
    ORDER BY s.first_name ASC, s.last_name ASC
");
$activeStudents = $stmt->fetchAll();

if ($currentSemesterId > 0) {
    $balanceMonitor = getActiveStudentBalanceMonitor($conn, $currentSemesterId);
    $studentBalances = (array)($balanceMonitor['rows'] ?? []);
    $outstandingBalance = (float)($balanceMonitor['outstanding_total_ugx'] ?? 0.0);
    $studentsWithBalance = (int)($balanceMonitor['students_with_balance'] ?? 0);
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

$unreadNotifications = fetchUnreadNotificationsForUser($currentUserId, 10);
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

        <?php $collectionRate = ($totalCollections + $outstandingBalance) > 0 ? (($totalCollections / ($totalCollections + $outstandingBalance)) * 100) : 0; ?>
        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small class="font-weight-bold">Collection Rate</small>
                    <small class="text-muted"><?php echo number_format($collectionRate, 1); ?>%</small>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (float)$collectionRate; ?>%"></div>
                </div>
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
                    <p>Review all active students, including no-payment records.</p>
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
                                <label>Student</label>
                                <select class="form-control" id="payment_student_id" name="student_id" required <?php echo ($currentSemesterId <= 0 || $financeStaffId <= 0) ? 'disabled' : ''; ?>>
                                    <option value="">Select student</option>
                                    <?php foreach ($activeStudents as $studentOption): ?>
                                        <option value="<?php echo (int)$studentOption['id']; ?>">
                                            <?php echo e((string)$studentOption['student_id']); ?> - <?php echo e(trim((string)$studentOption['first_name'] . ' ' . (string)$studentOption['last_name'])); ?>
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
                                                    <?php echo e((string)$invoiceOption['invoice_number']); ?> - <?php echo e(trim((string)$invoiceOption['registration_number'] . ' ' . (string)$invoiceOption['first_name'] . ' ' . (string)$invoiceOption['last_name'])); ?>
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
                                <label>Student</label>
                                <select class="form-control" name="student_id" required <?php echo $currentSemesterId <= 0 ? 'disabled' : ''; ?>>
                                    <option value="">Select student</option>
                                    <?php foreach ($activeStudents as $studentOption): ?>
                                        <option value="<?php echo (int)$studentOption['id']; ?>">
                                            <?php echo e((string)$studentOption['student_id']); ?> - <?php echo e(trim((string)$studentOption['first_name'] . ' ' . (string)$studentOption['last_name'])); ?>
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
                                        <td><?php echo e((string)$payment['first_name'] . ' ' . (string)$payment['last_name']); ?><br><small><?php echo e((string)$payment['student_id']); ?></small></td>
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
                                            <small><?php echo e((string)$bankRow['student_id']); ?></small>
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
                                        <td><?php echo e((string)$invoice['first_name'] . ' ' . (string)$invoice['last_name']); ?><br><small><?php echo e((string)$invoice['student_id']); ?></small></td>
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
            <div class="card-header">Student Balances (All Active Students)</div>
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
                                        <td><?php echo e((string)$balance['first_name'] . ' ' . (string)$balance['last_name']); ?><br><small><?php echo e((string)$balance['student_id']); ?></small></td>
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
    overflow-x: hidden;
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
    display: flex;
    align-items: center;
    gap: 10px;
}

.finance-dashboard .action-grid .action-card .action-icon {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    margin: 0;
    border-radius: 9px;
}

.finance-dashboard .action-grid .action-card h4 {
    margin: 0;
    font-size: 14px;
}

.finance-dashboard .action-grid .action-card p {
    margin: 2px 0 0;
    font-size: 11px;
    line-height: 1.3;
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

@media (max-width: 1200px) {
    .finance-dashboard .stats-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
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
        gap: 8px;
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

    if (studentSelect && invoiceSelect) {
        studentSelect.addEventListener('change', filterInvoicesByStudent);
        filterInvoicesByStudent();
    }

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
                ? 'No matching students'
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
});
</script>

<?php include '../../includes/footer.php'; ?>

