<?php
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

if ($financeStaffId <= 0 && $currentUserId > 0) {
    $staffStmt = $conn->prepare("SELECT id FROM finance_staff WHERE user_id = :uid LIMIT 1");
    $staffStmt->execute(['uid' => $currentUserId]);
    $financeStaffId = (int)$staffStmt->fetchColumn();
}

$conn->exec("CREATE TABLE IF NOT EXISTS finance_student_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester_id INT NULL,
    adjustment_type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    signed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL,
    reference_number VARCHAR(120) NULL,
    notes TEXT NULL,
    created_by_finance_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fsa_student (student_id),
    INDEX idx_fsa_semester (semester_id),
    INDEX idx_fsa_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->exec("CREATE TABLE IF NOT EXISTS finance_student_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester_id INT NULL,
    note_text TEXT NOT NULL,
    created_by_finance_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fsn_student (student_id),
    INDEX idx_fsn_semester (semester_id),
    INDEX idx_fsn_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$currentSemester = Helper::getCurrentSemester();
$currentSemesterId = (int)($currentSemester['id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
$selectedSemesterId = (int)($_GET['semester_id'] ?? $currentSemesterId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . BASE_URL . '/views/finance/student-profile.php');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $postStudentId = (int)($_POST['student_id'] ?? 0);
    $postSemesterId = (int)($_POST['semester_id'] ?? 0);
    $postQ = trim((string)($_POST['redirect_q'] ?? ''));
    $redirect = BASE_URL . '/views/finance/student-profile.php?' . http_build_query([
        'student_id' => $postStudentId,
        'semester_id' => $postSemesterId,
        'q' => $postQ
    ]);

    try {
        if ($financeStaffId <= 0) {
            throw new Exception('Finance staff profile missing.');
        }
        if ($postStudentId <= 0) {
            throw new Exception('Select a student first.');
        }

        if ($action === 'add_adjustment') {
            $type = strtolower(trim((string)($_POST['adjustment_type'] ?? '')));
            $amount = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $reference = trim((string)($_POST['reference_number'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if (!in_array($type, ['credit', 'debit'], true)) {
                throw new Exception('Invalid adjustment type.');
            }
            if ($amount <= 0 || $reason === '') {
                throw new Exception('Adjustment amount and reason are required.');
            }
            $signed = $type === 'credit' ? (-1 * $amount) : $amount;
            $ins = $conn->prepare("INSERT INTO finance_student_adjustments
                (student_id, semester_id, adjustment_type, amount, signed_amount, reason, reference_number, notes, created_by_finance_id, created_at)
                VALUES (:student_id, :semester_id, :type, :amount, :signed, :reason, :reference, :notes, :created_by, NOW())");
            $ins->execute([
                'student_id' => $postStudentId,
                'semester_id' => $postSemesterId > 0 ? $postSemesterId : null,
                'type' => $type,
                'amount' => $amount,
                'signed' => $signed,
                'reason' => $reason,
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $financeStaffId
            ]);
            $session->setFlash('success', 'Finance adjustment saved.');
        } elseif ($action === 'add_note') {
            $note = trim((string)($_POST['note_text'] ?? ''));
            if ($note === '') {
                throw new Exception('Note cannot be empty.');
            }
            $ins = $conn->prepare("INSERT INTO finance_student_notes
                (student_id, semester_id, note_text, created_by_finance_id, created_at)
                VALUES (:student_id, :semester_id, :note_text, :created_by, NOW())");
            $ins->execute([
                'student_id' => $postStudentId,
                'semester_id' => $postSemesterId > 0 ? $postSemesterId : null,
                'note_text' => $note,
                'created_by' => $financeStaffId
            ]);
            $session->setFlash('success', 'Finance note saved.');
        }
    } catch (Exception $e) {
        $session->setFlash('error', $e->getMessage());
    }

    header('Location: ' . $redirect);
    exit;
}

$semesters = $conn->query("SELECT s.id, s.semester_name, ay.year_name
    FROM semesters s INNER JOIN academic_years ay ON ay.id = s.academic_year_id
    ORDER BY ay.start_date DESC, s.semester_number DESC, s.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$students = [];
$studentSql = "SELECT s.id, s.student_id, s.first_name, s.last_name, s.status, s.academic_status, p.program_name
    FROM students s
    LEFT JOIN programs p ON p.id = s.program_id
    INNER JOIN users u ON u.id = s.user_id
    WHERE u.status = 'active'";
$params = [];
if ($q !== '') {
    $studentSql .= " AND (s.student_id LIKE :q OR s.first_name LIKE :q OR s.last_name LIKE :q OR CONCAT(s.first_name,' ',s.last_name) LIKE :q)";
    $params['q'] = '%' . $q . '%';
}
$studentSql .= " ORDER BY s.first_name, s.last_name LIMIT 200";
$st = $conn->prepare($studentSql);
$st->execute($params);
$students = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedStudent = null;
foreach ($students as $s) {
    if ((int)$s['id'] === $selectedStudentId) {
        $selectedStudent = $s;
        break;
    }
}
if (!$selectedStudent && $selectedStudentId > 0) {
    $singleStmt = $conn->prepare("SELECT s.id, s.student_id, s.first_name, s.last_name, s.status, s.academic_status, p.program_name
        FROM students s
        LEFT JOIN programs p ON p.id = s.program_id
        WHERE s.id = :id
        LIMIT 1");
    $singleStmt->execute(['id' => $selectedStudentId]);
    $selectedStudent = $singleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$snapshot = [];
$invoiceRows = [];
$paymentRows = [];
$mobileMoneyRows = [];
$adjustmentRows = [];
$noteRows = [];
$invoiceTotal = 0.0;
$paymentTotal = 0.0;
$netAdjustments = 0.0;
$effectiveBalance = 0.0;

if ($selectedStudent) {
    $sid = (int)$selectedStudent['id'];
    $scopeSemester = $selectedSemesterId > 0 ? $selectedSemesterId : $currentSemesterId;
    $snapshot = getStudentFinancialSnapshot($conn, $sid, $scopeSemester);
    $baseBalance = (float)($snapshot['balance_due'] ?? 0);

    $invSql = "SELECT invoice_number, total_amount, amount_paid, balance, status, due_date, created_at
        FROM invoices WHERE student_id = :sid";
    $invParams = ['sid' => $sid];
    if ($selectedSemesterId > 0) {
        $invSql .= " AND semester_id = :sem";
        $invParams['sem'] = $selectedSemesterId;
    }
    $invSql .= " ORDER BY created_at DESC LIMIT 150";
    $invStmt = $conn->prepare($invSql);
    $invStmt->execute($invParams);
    $invoiceRows = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($invoiceRows as $r) {
        $invoiceTotal += (float)($r['total_amount'] ?? 0);
    }

    $verifiedPredicate = getVerifiedPaymentsPredicate($conn, 'p');
    $paySql = "SELECT p.payment_id, p.amount, p.payment_method, p.payment_date, p.reference_number, p.receipt_number, i.invoice_number
        FROM payments p LEFT JOIN invoices i ON i.id = p.invoice_id
        WHERE p.student_id = :sid AND {$verifiedPredicate}";
    $payParams = ['sid' => $sid];
    if ($selectedSemesterId > 0) {
        $paySql .= " AND p.semester_id = :sem";
        $payParams['sem'] = $selectedSemesterId;
    }
    $paySql .= " ORDER BY p.payment_date DESC, p.created_at DESC LIMIT 150";
    $payStmt = $conn->prepare($paySql);
    $payStmt->execute($payParams);
    $paymentRows = $payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($paymentRows as $r) {
        $paymentTotal += (float)($r['amount'] ?? 0);
    }

    $mmtSql = "SELECT transaction_ref, reference_number, provider, msisdn, amount_expected, amount_received, provider_status, status, posted_payment_id, created_at, paid_at, posted_at, failure_reason
        FROM mobile_money_transactions
        WHERE student_id = :sid";
    $mmtParams = ['sid' => $sid];
    if ($selectedSemesterId > 0) {
        $mmtSql .= " AND semester_id = :sem";
        $mmtParams['sem'] = $selectedSemesterId;
    }
    $mmtSql .= " ORDER BY created_at DESC LIMIT 150";
    $mmtStmt = $conn->prepare($mmtSql);
    $mmtStmt->execute($mmtParams);
    $mobileMoneyRows = $mmtStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $adjSql = "SELECT * FROM finance_student_adjustments WHERE student_id = :sid";
    $adjParams = ['sid' => $sid];
    if ($selectedSemesterId > 0) {
        $adjSql .= " AND (semester_id = :sem OR semester_id IS NULL)";
        $adjParams['sem'] = $selectedSemesterId;
    }
    $adjSql .= " ORDER BY created_at DESC LIMIT 150";
    $adjStmt = $conn->prepare($adjSql);
    $adjStmt->execute($adjParams);
    $adjustmentRows = $adjStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($adjustmentRows as $r) {
        $netAdjustments += (float)($r['signed_amount'] ?? 0);
    }

    $noteSql = "SELECT * FROM finance_student_notes WHERE student_id = :sid";
    $noteParams = ['sid' => $sid];
    if ($selectedSemesterId > 0) {
        $noteSql .= " AND (semester_id = :sem OR semester_id IS NULL)";
        $noteParams['sem'] = $selectedSemesterId;
    }
    $noteSql .= " ORDER BY created_at DESC LIMIT 150";
    $noteStmt = $conn->prepare($noteSql);
    $noteStmt->execute($noteParams);
    $noteRows = $noteStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $effectiveBalance = $baseBalance + $netAdjustments;
}

$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');
$pageTitle = 'Student Financial Profile - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/finance/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar"><div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button><h4>Student Financial Profile</h4></div><?php include '../../includes/notification_bell.php'; ?></div>
    <div class="content-area">
        <?php if (!empty($flashSuccess)): ?><div class="alert alert-success"><?php echo e($flashSuccess); ?></div><?php endif; ?>
        <?php if (!empty($flashError)): ?><div class="alert alert-danger"><?php echo e($flashError); ?></div><?php endif; ?>
        <div class="card mb-3"><div class="card-header">Student Lookup</div><div class="card-body">
            <form method="GET" class="row">
                <div class="col-md-5 form-group"><label>Search</label><input class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Student No, first or last name"></div>
                <div class="col-md-4 form-group"><label>Semester Scope</label><select class="form-control" name="semester_id"><option value="0" <?php echo $selectedSemesterId <= 0 ? 'selected' : ''; ?>>All Semesters</option><?php foreach ($semesters as $sem): ?><option value="<?php echo (int)$sem['id']; ?>" <?php echo (int)$sem['id'] === (int)$selectedSemesterId ? 'selected' : ''; ?>><?php echo e((string)$sem['year_name'] . ' - ' . (string)$sem['semester_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3 form-group d-flex align-items-end"><button type="submit" class="btn btn-primary btn-block">Search</button></div>
            </form>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Student No</th><th>Name</th><th>Programme</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($students as $s): ?><tr><td><?php echo e((string)$s['student_id']); ?></td><td><?php echo e(trim((string)$s['first_name'] . ' ' . (string)$s['last_name'])); ?></td><td><?php echo e((string)($s['program_name'] ?? '-')); ?></td><td><?php echo e(strtoupper((string)($s['status'] ?? '-'))); ?></td><td class="text-right"><a class="btn btn-sm btn-outline-primary" href="<?php echo e(BASE_URL . '/views/finance/student-profile.php?' . http_build_query(['student_id' => (int)$s['id'], 'semester_id' => (int)$selectedSemesterId, 'q' => $q])); ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div>
        </div></div>
        <?php if ($selectedStudent): ?>
        <div class="card mb-3"><div class="card-body"><div class="row"><div class="col-md-3"><strong>No:</strong> <?php echo e((string)$selectedStudent['student_id']); ?></div><div class="col-md-3"><strong>Name:</strong> <?php echo e(trim((string)$selectedStudent['first_name'] . ' ' . (string)$selectedStudent['last_name'])); ?></div><div class="col-md-3"><strong>Programme:</strong> <?php echo e((string)($selectedStudent['program_name'] ?? '-')); ?></div><div class="col-md-3"><strong>Academic:</strong> <?php echo e((string)($selectedStudent['academic_status'] ?? '-')); ?></div></div><hr><div class="row"><div class="col-md-3"><strong>Invoice Total:</strong> <?php echo e(Helper::formatCurrency($invoiceTotal, 'UGX', 0)); ?></div><div class="col-md-3"><strong>Payments:</strong> <?php echo e(Helper::formatCurrency($paymentTotal, 'UGX', 0)); ?></div><div class="col-md-3"><strong>Net Adj:</strong> <?php echo e(Helper::formatCurrency($netAdjustments, 'UGX', 0)); ?></div><div class="col-md-3"><strong>Effective Balance:</strong> <?php echo e(Helper::formatCurrency($effectiveBalance, 'UGX', 0)); ?></div></div></div></div>
        <div class="row">
            <div class="col-lg-6"><div class="card mb-3"><div class="card-header">Add Adjustment (Safe Finance Edit)</div><div class="card-body"><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_adjustment"><input type="hidden" name="student_id" value="<?php echo (int)$selectedStudent['id']; ?>"><input type="hidden" name="semester_id" value="<?php echo (int)$selectedSemesterId; ?>"><input type="hidden" name="redirect_q" value="<?php echo e($q); ?>"><div class="form-row"><div class="form-group col-md-4"><label>Type</label><select name="adjustment_type" class="form-control"><option value="debit">Debit (+due)</option><option value="credit">Credit (-due)</option></select></div><div class="form-group col-md-4"><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required></div><div class="form-group col-md-4"><label>Reference</label><input type="text" name="reference_number" class="form-control"></div></div><div class="form-group"><label>Reason</label><input type="text" name="reason" class="form-control" required></div><div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div><button class="btn btn-primary btn-sm">Save Adjustment</button></form></div></div></div>
            <div class="col-lg-6"><div class="card mb-3"><div class="card-header">Add Internal Finance Note</div><div class="card-body"><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_note"><input type="hidden" name="student_id" value="<?php echo (int)$selectedStudent['id']; ?>"><input type="hidden" name="semester_id" value="<?php echo (int)$selectedSemesterId; ?>"><input type="hidden" name="redirect_q" value="<?php echo e($q); ?>"><div class="form-group"><label>Note</label><textarea name="note_text" class="form-control" rows="7" required></textarea></div><button class="btn btn-primary btn-sm">Save Note</button></form></div></div></div>
        </div>
        <div class="card mb-3"><div class="card-header">Invoices</div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Invoice #</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due</th></tr></thead><tbody><?php foreach ($invoiceRows as $r): ?><tr><td><?php echo e((string)$r['invoice_number']); ?></td><td><?php echo e(Helper::formatCurrency((float)$r['total_amount'], 'UGX', 0)); ?></td><td><?php echo e(Helper::formatCurrency((float)$r['amount_paid'], 'UGX', 0)); ?></td><td><?php echo e(Helper::formatCurrency((float)$r['balance'], 'UGX', 0)); ?></td><td><?php echo e(strtoupper((string)$r['status'])); ?></td><td><?php echo e(!empty($r['due_date']) ? date('d M Y', strtotime((string)$r['due_date'])) : '-'); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <div class="card mb-3"><div class="card-header">Payments</div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Payment ID</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Date</th><th>Ref</th></tr></thead><tbody><?php foreach ($paymentRows as $r): ?><tr><td><?php echo e((string)$r['payment_id']); ?></td><td><?php echo e((string)($r['invoice_number'] ?? '-')); ?></td><td><?php echo e(Helper::formatCurrency((float)$r['amount'], 'UGX', 0)); ?></td><td><?php echo e(ucwords(str_replace('_', ' ', (string)$r['payment_method']))); ?></td><td><?php echo e(!empty($r['payment_date']) ? date('d M Y', strtotime((string)$r['payment_date'])) : '-'); ?></td><td><?php echo e((string)($r['reference_number'] ?: ($r['receipt_number'] ?: '-'))); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <div class="card mb-3"><div class="card-header">Mobile Money Transactions (Raw Gateway Log)</div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Created</th><th>Transaction Ref</th><th>PRN/Ref</th><th>MSISDN</th><th>Expected</th><th>Received</th><th>Status</th><th>Provider Status</th><th>Posted Payment</th></tr></thead><tbody><?php foreach ($mobileMoneyRows as $m): ?><tr><td><?php echo e(!empty($m['created_at']) ? date('d M Y, h:i A', strtotime((string)$m['created_at'])) : '-'); ?></td><td><?php echo e((string)$m['transaction_ref']); ?></td><td><?php echo e((string)$m['reference_number']); ?></td><td><?php echo e((string)($m['msisdn'] ?: '-')); ?></td><td><?php echo e(Helper::formatCurrency((float)$m['amount_expected'], 'UGX', 0)); ?></td><td><?php echo e($m['amount_received'] !== null ? Helper::formatCurrency((float)$m['amount_received'], 'UGX', 0) : '-'); ?></td><td><?php echo e(strtoupper((string)$m['status'])); ?></td><td><?php echo e((string)($m['provider_status'] ?: '-')); ?></td><td><?php echo e(!empty($m['posted_payment_id']) ? ('#' . (int)$m['posted_payment_id']) : '-'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (empty($mobileMoneyRows)): ?><p class="mb-0 text-muted mt-2">No mobile money transaction records in this scope.</p><?php endif; ?></div></div>
        <div class="card mb-3"><div class="card-header">Adjustments History</div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>When</th><th>Type</th><th>Amount</th><th>Impact</th><th>Reason</th><th>Ref</th></tr></thead><tbody><?php foreach ($adjustmentRows as $r): ?><tr><td><?php echo e(!empty($r['created_at']) ? date('d M Y, h:i A', strtotime((string)$r['created_at'])) : '-'); ?></td><td><?php echo e(strtoupper((string)$r['adjustment_type'])); ?></td><td><?php echo e(Helper::formatCurrency((float)$r['amount'], 'UGX', 0)); ?></td><td><?php echo e(Helper::formatCurrency((float)$r['signed_amount'], 'UGX', 0)); ?></td><td><?php echo e((string)$r['reason']); ?></td><td><?php echo e((string)($r['reference_number'] ?? '-')); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <div class="card"><div class="card-header">Internal Notes History</div><div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>When</th><th>Note</th></tr></thead><tbody><?php foreach ($noteRows as $r): ?><tr><td><?php echo e(!empty($r['created_at']) ? date('d M Y, h:i A', strtotime((string)$r['created_at'])) : '-'); ?></td><td><?php echo nl2br(e((string)$r['note_text'])); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <?php endif; ?>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
