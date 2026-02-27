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

        $lastStmt = $conn->prepare("SELECT MAX(payment_date) FROM payments WHERE student_id = :student_id AND semester_id = :semester_id");
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

    if ($action === 'record_payment') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $paymentDateInput = trim((string)($_POST['payment_date'] ?? ''));
        $referenceNumber = trim((string)($_POST['reference_number'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $allowedMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card'];
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

            $paymentId = financeGenerateCode('PAY');
            $receiptNumber = financeGenerateCode('RCP');

            $insertPaymentStmt = $conn->prepare("
                INSERT INTO payments (payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number, received_by, semester_id, notes, receipt_number, created_at, updated_at)
                VALUES (:payment_id, :student_id, :invoice_id, :amount, :payment_date, :payment_method, :reference_number, :received_by, :semester_id, :notes, :receipt_number, NOW(), NOW())
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
            financeNotifyUser(
                $conn,
                (int)($student['user_id'] ?? 0),
                'Payment Received',
                'Your payment of ' . Helper::formatCurrency($amount, 'UGX', 0) . ' has been received. Receipt: ' . $receiptNumber,
                'success',
                BASE_URL . '/views/student/payments.php'
            );

            $conn->commit();
            $session->setFlash('success', 'Payment recorded successfully. Receipt: ' . $receiptNumber);
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

if ($currentSemesterId > 0) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE semester_id = :semester_id");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $totalCollections = (float)($stmt->fetch()['total'] ?? 0);

    $stmt = $conn->prepare("SELECT COALESCE(SUM(balance), 0) as total FROM student_balances WHERE semester_id = :semester_id AND balance > 0");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $outstandingBalance = (float)($stmt->fetch()['total'] ?? 0);

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM invoices WHERE semester_id = :semester_id AND status != 'paid'");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $totalInvoices = (int)($stmt->fetch()['total'] ?? 0);

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_balances WHERE semester_id = :semester_id AND balance > 0");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $studentsWithBalance = (int)($stmt->fetch()['total'] ?? 0);
}

$stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date = CURDATE()");
$paymentsToday = (float)($stmt->fetch()['total'] ?? 0);

$stmt = $conn->prepare("
    SELECT p.*, s.id AS student_db_id, s.student_id, s.first_name, s.last_name, s.academic_status, i.invoice_number
    FROM payments p
    INNER JOIN students s ON p.student_id = s.id
    LEFT JOIN invoices i ON i.id = p.invoice_id
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

$studentBalances = [];
if ($currentSemesterId > 0) {
    $stmt = $conn->prepare("
        SELECT sb.*, s.student_id, s.first_name, s.last_name
        FROM student_balances sb
        INNER JOIN students s ON s.id = sb.student_id
        WHERE sb.semester_id = :semester_id
        ORDER BY sb.balance DESC, s.last_name ASC
        LIMIT 10
    ");
    $stmt->execute(['semester_id' => $currentSemesterId]);
    $studentBalances = $stmt->fetchAll();
}

$collectionsByMonth = [];
$stmt = $conn->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS period, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE YEAR(payment_date) = YEAR(CURDATE())
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
                    <p>Review students with outstanding fees.</p>
                </a>
                <a href="<?php echo e($dashboardUrl); ?>?section=reports#reports-section" class="action-card">
                    <div class="action-icon"><i class="fas fa-chart-bar"></i></div>
                    <h4>Collections Report</h4>
                    <p>Monthly total collections summary.</p>
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
                                    <th>Date</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentPayments as $payment): ?>
                                    <tr>
                                        <td><?php echo e((string)$payment['payment_id']); ?></td>
                                        <td><?php echo e((string)$payment['first_name'] . ' ' . (string)$payment['last_name']); ?><br><small><?php echo e((string)$payment['student_id']); ?></small></td>
                                        <td><?php echo e((string)($payment['invoice_number'] ?? '-')); ?></td>
                                        <td><strong><?php echo e(Helper::formatCurrency((float)$payment['amount'], 'UGX', 0)); ?></strong></td>
                                        <td><?php echo e(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td>
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
            <div class="card-header">Student Balances</div>
            <div class="card-body">
                <?php if (!empty($studentBalances)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Total Fees</th>
                                    <th>Total Paid</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentBalances as $balance): ?>
                                    <tr>
                                        <td><?php echo e((string)$balance['first_name'] . ' ' . (string)$balance['last_name']); ?><br><small><?php echo e((string)$balance['student_id']); ?></small></td>
                                        <td><?php echo e(Helper::formatCurrency((float)$balance['total_fees'], 'UGX', 0)); ?></td>
                                        <td><?php echo e(Helper::formatCurrency((float)$balance['total_paid'], 'UGX', 0)); ?></td>
                                        <td><strong><?php echo e(Helper::formatCurrency((float)$balance['balance'], 'UGX', 0)); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No balance records for the current semester.</p>
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
    grid-template-columns: repeat(4, minmax(0, 1fr));
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
});
</script>

<?php include '../../includes/footer.php'; ?>

