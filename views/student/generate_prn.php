<?php
require_once '../../config.php';

$session = new Session('student');
$auth = new Auth('student');

if (
    !isset($_SESSION['student_logged_in']) ||
    $_SESSION['student_logged_in'] !== true ||
    ($_SESSION['student_role'] ?? '') !== 'student'
) {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$studentDbId = (int)($studentProfile['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];

$studentSemesterContext = getStudentCurrentSemesterContext($conn, $studentDbId);
if (!empty($studentSemesterContext['id'])) {
    $currentSemester['semester_name'] = $studentSemesterContext['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($studentSemesterContext['id'] ?? 0);
    $currentSemester['academic_year'] = $studentSemesterContext['academic_year'] ?? '-';
}

$outstandingBalance = (float)($studentProfile['account_balance'] ?? 0);
if ($studentDbId > 0 && $currentSemester['id'] > 0) {
    try {
        $balStmt = $conn->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM student_balances
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $balStmt->execute([
            'student_id' => $studentDbId,
            'semester_id' => (int)$currentSemester['id']
        ]);
        $outstandingBalance = (float)$balStmt->fetchColumn();
    } catch (Exception $e) {
    }
}

$academicStatusMeta = getStudentAcademicStatusMeta(
    $conn,
    (int)$studentDbId,
    (int)($currentSemester['id'] ?? 0),
    (string)($studentProfile['academic_status'] ?? '')
);
$academicStatus = (string)($academicStatusMeta['label'] ?? 'Status Pending');
$academicStatusStyle = (string)($academicStatusMeta['style'] ?? getAcademicStatusChipStyle('neutral'));

$registeredProgramName = '-';
if ($studentDbId > 0) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => $studentDbId]);
        $programName = $progStmt->fetchColumn();
        if (!empty($programName)) {
            $registeredProgramName = $programName;
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
    }
}

$unpaidInvoices = [];
$unpaidInvoicesTotal = 0.0;
$paymentRefs = [];
$activePaymentRefs = [];
$expiredPaymentRefs = [];
$invoiceDataError = '';

if ($studentDbId > 0) {
    try {
        $invStmt = $conn->prepare("
            SELECT
                i.id,
                i.invoice_number,
                i.total_amount,
                i.amount_paid,
                i.balance,
                i.due_date,
                i.status,
                s.semester_name,
                ay.year_name AS academic_year
            FROM invoices i
            LEFT JOIN semesters s ON i.semester_id = s.id
            LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
            WHERE i.student_id = :student_id
              AND i.balance > 0
              AND i.status IN ('pending', 'partial', 'overdue')
            ORDER BY i.due_date ASC, i.id DESC
        ");
        $invStmt->execute(['student_id' => $studentDbId]);
        $unpaidInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($unpaidInvoices as $invoiceRow) {
            $unpaidInvoicesTotal += (float)($invoiceRow['balance'] ?? 0);
        }
    } catch (Exception $e) {
        $invoiceDataError = 'Unable to load invoice data right now.';
    }

    try {
        $payStmt = $conn->prepare("
            SELECT
                p.payment_id,
                p.reference_number,
                p.receipt_number,
                p.amount,
                p.payment_date,
                p.payment_method,
                i.invoice_number,
                i.due_date
            FROM payments p
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE p.student_id = :student_id
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 20
        ");
        $payStmt->execute(['student_id' => $studentDbId]);
        $paymentRefs = $payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $today = date('Y-m-d');
        foreach ($paymentRefs as $refRow) {
            $expiryDate = !empty($refRow['due_date']) ? $refRow['due_date'] : date('Y-m-d', strtotime(($refRow['payment_date'] ?? date('Y-m-d')) . ' +14 days'));
            $refRow['expiry_date'] = $expiryDate;
            if ($expiryDate >= $today) {
                $activePaymentRefs[] = $refRow;
            } else {
                $expiredPaymentRefs[] = $refRow;
            }
        }
    } catch (Exception $e) {
    }
}

$generatedPrn = '';
$generatedAmount = '';
$prnError = '';
$activePrnTab = $_POST['prn_tab'] ?? 'new_prn';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_deposit_prn') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $prnError = 'Invalid request token.';
    } else {
        $amountRaw = trim($_POST['deposit_amount'] ?? '');
        $generatedAmount = $amountRaw;
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            $prnError = 'Enter a valid deposit amount.';
        } else {
            $generatedPrn = 'PRN' . date('YmdHis') . rand(100, 999);
            $generatedAmount = number_format((float)$amountRaw, 0);
            try {
                $session->setFlash('success', 'PRN generated successfully: ' . $generatedPrn);
            } catch (Exception $e) {
            }
        }
    }
    $activePrnTab = 'new_prn';
}

$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = 'generate_prn.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

$pageTitle = 'Generate PRN - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
body { background: #f2f4f7; }
.student-sidebar {
    width: 230px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    min-height: 100vh;
    height: 100vh;
    overflow-y: auto;
    overflow-x: hidden;
    border-right: 1px solid #e5e7eb;
    position: fixed;
    left: 0;
    top: 0;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(15, 23, 42, 0.04);
    transition: transform 0.25s ease;
}
.student-sidebar ul { list-style: none; padding: 10px 8px; margin: 0; }
.student-sidebar > ul { padding-bottom: 20px; }
.student-sidebar li {
    padding: 9px 12px;
    margin-bottom: 4px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 0.82rem;
    letter-spacing: 0.02em;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s ease;
}
.student-sidebar li a { color: inherit; text-decoration: none; display: block; }
.student-sidebar li.active { background: #eaf2ff; border-color: #bfdbfe; color: #1d4ed8; font-weight: 700; }
.student-sidebar li:hover { background: #f1f5f9; color: #0f172a; }
.student-sidebar.sidebar-collapsed { transform: translateX(-100%); }
.sidebar-user-card {
    margin: 0.45rem 0.45rem 0.2rem;
    background: #2b3c4f;
    border-radius: 8px;
    color: #fff;
    text-align: center;
    padding: 0.6rem 0.55rem 0.6rem;
}
.sidebar-user-card img {
    width: 62px;
    height: 72px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.35);
    margin-bottom: 0.3rem;
}
.sidebar-user-name { font-size: 0.82rem; line-height: 1.2; }
.sidebar-user-no { font-size: 0.9rem; font-weight: 700; }


.sidebar-portal-title { font-size: 0.66rem; letter-spacing: 0.08em; text-transform: uppercase; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 700; }
.main-content {
    margin-left: 230px;
    width: calc(100vw - 230px);
    max-width: calc(100vw - 230px);
    min-height: 100vh;
    transition: margin-left 0.25s ease, width 0.25s ease;
}
.main-content.full-width { margin-left: 0; width: 100vw; max-width: 100vw; }

.student-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    padding: 0.5rem 1.2rem;
    position: sticky;
    top: 0;
    z-index: 10;
}
.student-profile-pic { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb; }

.chip-row { padding: 0.45rem 1.2rem 0.2rem; display: flex; align-items: center; gap: 0.35rem; white-space: nowrap; }
.chip {
    border-radius: 6px;
    padding: 4px 8px;
    font-weight: 600;
    font-size: 0.78rem;
    line-height: 1;
    white-space: nowrap;
}
.chip.gray { background: #f1f5f9; color: #222; }
.chip.blue { background: #1f7aa8; color: #fff; }
.chip.red { background: #fee2e2; color: #991b1b; }

.prn-wrap { padding: 0.9rem 1.2rem 1.3rem; }
.prn-card {
    background: #f6f7f9;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 0.9rem;
}
.prn-tabs { display: flex; gap: 0.35rem; margin-bottom: 0.8rem; }
.prn-tab {
    border: 1px solid #d1d5db;
    background: #fff;
    color: #334155;
    border-radius: 8px 8px 0 0;
    padding: 9px 16px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
}
.prn-tab.active {
    color: #1f7aa8;
    border-bottom-color: #fff;
}
.notice {
    background: #fef3c7;
    color: #7c5f14;
    border: 1px solid #f3d88c;
    border-radius: 4px;
    padding: 12px 14px;
    margin-bottom: 0.7rem;
}
.acc-item { border: 1px solid #d1d5db; border-top: none; background: #fff; }
.acc-item:first-of-type { border-top: 1px solid #d1d5db; border-radius: 6px 6px 0 0; }
.acc-item:last-of-type { border-radius: 0 0 6px 6px; }
.acc-head {
    width: 100%;
    text-align: left;
    border: none;
    background: #fff;
    padding: 12px 14px;
    font-size: 1rem;
    font-weight: 700;
    color: #374151;
    cursor: pointer;
}
.acc-head.active { color: #b42318; }
.acc-body { display: none; padding: 14px; border-top: 1px solid #e5e7eb; }
.acc-body.active { display: block; }
.prn-input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    width: 180px;
    font-size: 1rem;
}
.prn-generate-btn {
    border: 1px solid #1f7aa8;
    background: #1f7aa8;
    color: #fff;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
}
.prn-generated {
    margin-top: 12px;
    background: #ecfdf3;
    border: 1px solid #86efac;
    color: #166534;
    border-radius: 8px;
    padding: 10px;
    font-weight: 700;
}
.prn-list-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}
.prn-list-table th,
.prn-list-table td {
    border: 1px solid #e5e7eb;
    padding: 7px 8px;
    font-size: 0.82rem;
}
.prn-list-table th {
    background: #f8fafc;
    font-weight: 700;
}
.status-pill {
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 0.75rem;
    font-weight: 700;
}
.status-pill.pending { background: #fee2e2; color: #991b1b; }
.status-pill.partial { background: #ffedd5; color: #9a3412; }
.status-pill.overdue { background: #fef2f2; color: #b91c1c; }
.refs-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 8px;
    background: #f8fafc;
}
.refs-groups {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    padding: 3px;
}
.refs-group-btn {
    border: 1px solid transparent;
    background: transparent;
    color: #475569;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 0.86rem;
    cursor: pointer;
}
.refs-group-btn.active {
    background: #fff;
    color: #0f172a;
    border-color: #e2e8f0;
    box-shadow: 0 1px 2px rgba(2, 6, 23, 0.08);
}
.refs-reload-btn {
    border: 1px dashed #f87171;
    background: #fff;
    color: #ef4444;
    border-radius: 8px;
    padding: 7px 12px;
    font-weight: 700;
    font-size: 0.82rem;
    cursor: pointer;
}
.ref-line {
    border: 1px solid #d8dee6;
    background: #fff;
    border-radius: 3px;
    padding: 12px 14px;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 7px;
}
.ref-line .red { color: #b42318; }
.methods-wrap {
    padding: 6px 0 2px;
}
.methods-tabs {
    display: flex;
    gap: 6px;
    margin: 0 auto 10px;
    width: fit-content;
    background: #f1f5f9;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 3px;
}
.method-btn {
    border: 1px solid transparent;
    background: transparent;
    color: #64748b;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
}
.method-btn.active {
    background: #fff;
    color: #1f7aa8;
    border-color: #e2e8f0;
    box-shadow: 0 1px 2px rgba(2, 6, 23, 0.08);
}
.method-panel {
    display: none;
    max-width: 900px;
    margin: 0 auto;
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 12px;
    padding: 14px 18px;
}
.method-panel.active { display: block; }
.method-list {
    margin: 0;
    padding-left: 20px;
    font-size: 0.95rem;
    color: #1f2937;
    line-height: 1.25;
}
.mobile-money-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
.mobile-money-col {
    border-right: 1px solid #e5e7eb;
    padding-right: 12px;
}
.mobile-money-col:last-child {
    border-right: none;
    padding-right: 0;
}
.mobile-money-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f7aa8;
    text-decoration: underline;
    margin-bottom: 3px;
}
.dial-code {
    color: #b42318;
    font-weight: 700;
}

/* Dark mode overrides for generate PRN panels */
html[data-theme='dark'] .prn-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .prn-tab {
    background: var(--app-surface-2) !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .prn-tab.active {
    background: var(--app-surface-1) !important;
    color: #f8fafc !important;
    border-bottom-color: var(--app-surface-1) !important;
}
html[data-theme='dark'] .acc-item {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .acc-head {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
}
html[data-theme='dark'] .acc-head.active {
    color: #fca5a5 !important;
}
html[data-theme='dark'] .acc-body {
    background: var(--app-surface-1) !important;
    color: #e5e7eb !important;
    border-top-color: var(--app-border) !important;
}
html[data-theme='dark'] .acc-body > div[style*='color:#334155'],
html[data-theme='dark'] .acc-body > div[style*='color: #334155'],
html[data-theme='dark'] .acc-body > div[style*='color:#64748b'],
html[data-theme='dark'] .acc-body > div[style*='color: #64748b'] {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .prn-input {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .prn-generated {
    background: rgba(16, 185, 129, 0.12) !important;
    border-color: rgba(52, 211, 153, 0.5) !important;
    color: #34d399 !important;
}
html[data-theme='dark'] .notice {
    background: #3a2f14 !important;
    color: #fef3c7 !important;
    border-color: #7c5f14 !important;
}
html[data-theme='dark'] .notice[style*='background:#eef2ff'],
html[data-theme='dark'] .notice[style*='background: #eef2ff'],
html[data-theme='dark'] .notice[style*='background:#f8fafc'],
html[data-theme='dark'] .notice[style*='background: #f8fafc'],
html[data-theme='dark'] .notice[style*='background:#ecfdf3'],
html[data-theme='dark'] .notice[style*='background: #ecfdf3'] {
    background: var(--app-surface-2) !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-toolbar {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-groups {
    background: #0f172a !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-group-btn {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .refs-group-btn.active {
    background: var(--app-surface-1) !important;
    color: #f8fafc !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-reload-btn {
    background: var(--app-surface-2) !important;
    color: #fca5a5 !important;
    border-color: #ef4444 !important;
}
html[data-theme='dark'] .refs-reload-btn:hover {
    background: #3a1820 !important;
    color: #fecaca !important;
}
html[data-theme='dark'] .ref-line {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .ref-line .red {
    color: #fca5a5 !important;
}
html[data-theme='dark'] .methods-tabs {
    background: #0f172a !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .method-btn {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .method-btn.active {
    background: var(--app-surface-1) !important;
    color: #93c5fd !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .method-panel {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .method-list {
    color: #e5e7eb !important;
}
html[data-theme='dark'] .mobile-money-col {
    border-right-color: var(--app-border) !important;
}
html[data-theme='dark'] .mobile-money-title {
    color: #93c5fd !important;
}
html[data-theme='dark'] .dial-code {
    color: #fca5a5 !important;
}

@media (max-width: 1200px) {
    .chip-row {
        white-space: normal;
        flex-wrap: wrap;
    }
    .methods-tabs {
        width: 100%;
        flex-wrap: wrap;
    }
    .mobile-money-grid {
        grid-template-columns: 1fr;
    }
    .mobile-money-col {
        border-right: none;
        padding-right: 0;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 10px;
    }
    .mobile-money-col:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
}
</style>

<div class="student-sidebar">
    <div class="sidebar-user-card">
        <div class="sidebar-portal-title">SMNS-STUDENT PORTAL</div>
        <?php if (!empty($studentProfile['photo'])): ?>
            <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile">
        <?php else: ?>
            <img src="/assets/img/student_sample.jpg" alt="Profile">
        <?php endif; ?>
        <div class="sidebar-user-name">
            <?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?>
        </div>
        <div class="sidebar-user-no">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
    </div>
    <ul>
        <li class="active"><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY PROGRAMME</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:0.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#1f7aa8; color:#fff; border:1px solid #1f7aa8; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:700;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic">
            <?php endif; ?>
            <span style="font-size:0.98rem; color:#222; font-weight:600; white-space:nowrap;">
                <?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?>
            </span>
            <a href="<?php echo e($linkMailbox); ?>" title="My Mailbox" style="position:relative; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px solid #dbe3ef; border-radius:50%; color:#1f7aa8; text-decoration:none; background:#fff;">
                <i class="far fa-envelope"></i>
                <?php if ($mailUnreadCount > 0): ?>
                    <span style="position:absolute; top:-6px; right:-6px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:#ef4444; color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center;"><?php echo $mailUnreadCount > 99 ? '99+' : $mailUnreadCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="profile-dropdown" style="position:relative;">
                <button id="profileDropBtn" style="background:none; border:none; font-size:0.98rem; cursor:pointer; padding:0 6px;">
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div id="profileDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.08); min-width:140px; z-index:100;">
                    <a href="dashboard.php" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Profile</a>
                    <a href="services.php?tab=apply" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Services</a>
                    <a href="logout.php" style="display:block; padding:8px 14px; color:#dc2626; text-decoration:none; font-weight:600; font-size:0.92rem;">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="chip-row">
        <span style="font-size:1rem; color:#1f7aa8;">PROGRAMME:</span>
        <span style="font-size:1rem;"><?php echo e($registeredProgramName); ?></span>
        <span class="chip" style="background:#16a34a; color:#fff;">ACTIVE</span>
        <span style="margin-left:auto; font-size:1rem; color:#1f7aa8;">ACADEMIC STATUS:</span>
        <span class="chip red" style="<?php echo e($academicStatusStyle); ?>"><?php echo e($academicStatus); ?></span>
    </div>

    <div class="chip-row">
        <span class="chip gray">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span>
        <span class="chip gray">CURRENT SEM. <span style="color:#2563eb;"><?php echo e($currentSemester['semester_name']); ?></span></span>
        <span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?></span>
        <span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
        <span class="chip gray">TOTAL FEES BAL DUE: <?php echo number_format($outstandingBalance); ?>/=</span>
        <span class="chip blue">BALANCE ON ACCOUNT: <?php echo number_format((float)($studentProfile['account_balance'] ?? 0)); ?>/=</span>
    </div>

    <div class="prn-wrap">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if (!empty($prnError)): ?>
            <div class="alert alert-danger"><?php echo e($prnError); ?></div>
        <?php endif; ?>

        <div class="prn-card">
            <div class="prn-tabs">
                <button type="button" class="prn-tab <?php echo $activePrnTab === 'new_prn' ? 'active' : ''; ?>" data-prn-tab="new_prn">GENERATE NEW PRN</button>
                <button type="button" class="prn-tab <?php echo $activePrnTab === 'payment_refs' ? 'active' : ''; ?>" data-prn-tab="payment_refs">MY PAYMENT REFs</button>
                <button type="button" class="prn-tab <?php echo $activePrnTab === 'payment_methods' ? 'active' : ''; ?>" data-prn-tab="payment_methods">PAYMENT METHODS</button>
            </div>

            <div id="prnTab_new_prn" class="prn-tab-panel" style="<?php echo $activePrnTab === 'new_prn' ? '' : 'display:none;'; ?>">
                <?php if (!empty($invoiceDataError)): ?>
                    <div class="alert alert-warning mb-2"><?php echo e($invoiceDataError); ?></div>
                <?php elseif (!empty($unpaidInvoices)): ?>
                    <div class="notice" style="background:#ecfdf3; border-color:#86efac; color:#166534;">
                        You have <?php echo count($unpaidInvoices); ?> unpaid invoice(s). Total outstanding: <?php echo number_format($unpaidInvoicesTotal); ?>/=
                    </div>
                <?php endif; ?>

                <div class="acc-item">
                    <button type="button" class="acc-head" data-acc="all_pending">GENERATE PRN TO PAY FOR ALL PENDING INVOICES</button>
                    <div class="acc-body" id="acc_all_pending">
                        <?php if (!empty($unpaidInvoices)): ?>
                            <div style="font-size:0.86rem; margin-bottom:8px; color:#334155;">
                                All pending invoices selected. Total amount: <strong><?php echo number_format($unpaidInvoicesTotal); ?>/=</strong>
                            </div>
                            <table class="prn-list-table">
                                <thead>
                                    <tr>
                                        <th>Invoice No.</th>
                                        <th>Semester</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaidInvoices as $invoice): ?>
                                        <?php $invStatus = strtolower((string)($invoice['status'] ?? 'pending')); ?>
                                        <tr>
                                            <td><?php echo e($invoice['invoice_number'] ?? '-'); ?></td>
                                            <td><?php echo e(trim((string)($invoice['academic_year'] ?? '-') . ' ' . (string)($invoice['semester_name'] ?? ''))); ?></td>
                                            <td><?php echo !empty($invoice['due_date']) ? e(date('d M Y', strtotime($invoice['due_date']))) : '-'; ?></td>
                                            <td><span class="status-pill <?php echo e($invStatus); ?>"><?php echo e(strtoupper($invStatus)); ?></span></td>
                                            <td style="text-align:right;"><?php echo number_format((float)($invoice['balance'] ?? 0)); ?>/=</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            No pending invoices found.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="acc-item">
                    <button type="button" class="acc-head" data-acc="partial_pending">GENERATE PRN TO MAKE PARTIAL PAYMENT ON PENDING INVOICES</button>
                    <div class="acc-body" id="acc_partial_pending">
                        <?php if (!empty($unpaidInvoices)): ?>
                            <table class="prn-list-table">
                                <thead>
                                    <tr>
                                        <th>Invoice No.</th>
                                        <th style="text-align:right;">Balance</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaidInvoices as $invoice): ?>
                                        <tr>
                                            <td><?php echo e($invoice['invoice_number'] ?? '-'); ?></td>
                                            <td style="text-align:right;"><?php echo number_format((float)($invoice['balance'] ?? 0)); ?>/=</td>
                                            <td><?php echo e($invoice['invoice_number'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="margin-top:8px; font-size:0.8rem; color:#64748b;">
                                Use "Deposit to my account" below if you want to pay any custom amount.
                            </div>
                        <?php else: ?>
                            No pending invoices available for partial payment.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="acc-item">
                    <button type="button" class="acc-head active" data-acc="deposit_account">GENERATE PRN TO DEPOSIT TO MY ACCOUNT</button>
                    <div class="acc-body active" id="acc_deposit_account">
                        <form method="POST" style="display:flex; align-items:end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="generate_deposit_prn">
                            <input type="hidden" name="prn_tab" value="new_prn">
                            <div>
                                <label style="font-size:1rem; margin-bottom:6px; display:block;"><span style="color:#dc2626;">*</span> AMOUNT TO DEPOSIT:</label>
                                <input type="number" name="deposit_amount" min="1" step="0.01" class="prn-input" value="<?php echo e($generatedAmount); ?>" required>
                            </div>
                            <button type="submit" class="prn-generate-btn">GENERATE PRN</button>
                        </form>
                        <?php if (!empty($generatedPrn)): ?>
                            <div class="prn-generated">Generated PRN: <?php echo e($generatedPrn); ?> | Amount: <?php echo e($generatedAmount); ?>/=</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="prnTab_payment_refs" class="prn-tab-panel" style="<?php echo $activePrnTab === 'payment_refs' ? '' : 'display:none;'; ?>">
                <div class="refs-toolbar">
                    <div class="refs-groups">
                        <button type="button" class="refs-group-btn active" data-ref-group="active_refs">Active References (<?php echo count($activePaymentRefs); ?>)</button>
                        <button type="button" class="refs-group-btn" data-ref-group="expired_refs">Expired References (<?php echo count($expiredPaymentRefs); ?>)</button>
                    </div>
                    <button type="button" id="reloadPaymentRefsBtn" class="refs-reload-btn">RELOAD</button>
                </div>

                <div id="refGroup_active_refs">
                    <?php if (!empty($activePaymentRefs)): ?>
                        <?php foreach ($activePaymentRefs as $ref): ?>
                            <?php $refNumber = $ref['reference_number'] ?: ($ref['receipt_number'] ?: ($ref['payment_id'] ?? '-')); ?>
                            <div class="ref-line">
                                REFERENCE: <span class="red"><?php echo e($refNumber); ?></span>,
                                AMOUNT TO PAY: <span class="red"><?php echo number_format((float)($ref['amount'] ?? 0)); ?></span> UGX,
                                EXPIRY DATE: <span class="red"><?php echo e(date('Y.m.d', strtotime($ref['expiry_date']))); ?></span>,
                                GENERATED BY: <span class="red">SELF</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notice" style="background:#eef2ff; color:#334155; border-color:#cbd5e1;">No active references.</div>
                    <?php endif; ?>
                </div>

                <div id="refGroup_expired_refs" style="display:none;">
                    <?php if (!empty($expiredPaymentRefs)): ?>
                        <?php foreach ($expiredPaymentRefs as $ref): ?>
                            <?php $refNumber = $ref['reference_number'] ?: ($ref['receipt_number'] ?: ($ref['payment_id'] ?? '-')); ?>
                            <div class="ref-line" style="opacity:0.85;">
                                REFERENCE: <span class="red"><?php echo e($refNumber); ?></span>,
                                AMOUNT TO PAY: <span class="red"><?php echo number_format((float)($ref['amount'] ?? 0)); ?></span> UGX,
                                EXPIRY DATE: <span class="red"><?php echo e(date('Y.m.d', strtotime($ref['expiry_date']))); ?></span>,
                                GENERATED BY: <span class="red">SELF</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notice" style="background:#f8fafc; color:#64748b; border-color:#e2e8f0;">No expired references.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div id="prnTab_payment_methods" class="prn-tab-panel" style="<?php echo $activePrnTab === 'payment_methods' ? '' : 'display:none;'; ?>">
                <div class="methods-wrap">
                    <div class="methods-tabs">
                        <button type="button" class="method-btn active" data-method-tab="bank">HOW TO PAY WITH MOBILE MONEY</button>
                        <button type="button" class="method-btn" data-method-tab="mobile">HOW TO PAY WITH MOBILE MONEY</button>
                        <button type="button" class="method-btn" data-method-tab="visa">HOW TO PAY WITH VISA</button>
                    </div>

                    <div id="methodPanel_bank" class="method-panel active">
                        <ol class="method-list">
                            <li>Visit Your Nearest Bank</li>
                            <li>Provide Your Details (Email, Phone Number etc) and Payment Reference Number (PRN)</li>
                            <li>Confirm Payment Status</li>
                        </ol>
                    </div>

                    <div id="methodPanel_mobile" class="method-panel">
                        <div class="mobile-money-grid">
                            <div class="mobile-money-col">
                                <div class="mobile-money-title">PAY WITH MTN MOBILE MONEY</div>
                                <ol class="method-list">
                                    <li>Dial <span class="dial-code">*165*18#</span></li>
                                    <li>Follow Prompts and enter Payment Reference Number (PRN)</li>
                                    <li>Confirm Payment recipient</li>
                                </ol>
                            </div>
                            <div class="mobile-money-col">
                                <div class="mobile-money-title">PAY WITH AIRTEL MONEY</div>
                                <ol class="method-list">
                                    <li>Dial <span class="dial-code">*165*4*7*1#</span></li>
                                    <li>Follow Prompts and enter Payment Reference Number (PRN)</li>
                                    <li>Confirm Payment recipient</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <div id="methodPanel_visa" class="method-panel">
                        <ol class="method-list">
                            <li>Use a secure card payment channel approved by the institution</li>
                            <li>Enter your card details and the Payment Reference Number (PRN)</li>
                            <li>Authorize the transaction (OTP/3D Secure)</li>
                            <li>Confirm payment status after successful processing</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('menuBtn').addEventListener('click', function() {
    var sidebar = document.querySelector('.student-sidebar');
    var main = document.querySelector('.main-content');
    sidebar.classList.toggle('sidebar-collapsed');
    if (main) main.classList.toggle('full-width');
});

document.getElementById('profileDropBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    var menu = document.getElementById('profileDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function() {
    var menu = document.getElementById('profileDropMenu');
    if (menu) menu.style.display = 'none';
});

document.querySelectorAll('.prn-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var target = tab.getAttribute('data-prn-tab');
        document.querySelectorAll('.prn-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.prn-tab-panel').forEach(function(p) { p.style.display = 'none'; });
        tab.classList.add('active');
        var panel = document.getElementById('prnTab_' + target);
        if (panel) panel.style.display = '';
    });
});

document.querySelectorAll('.acc-head').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = btn.getAttribute('data-acc');
        var body = document.getElementById('acc_' + key);
        if (!body) return;
        var isActive = body.classList.contains('active');
        document.querySelectorAll('.acc-head').forEach(function(h) { h.classList.remove('active'); });
        document.querySelectorAll('.acc-body').forEach(function(b) { b.classList.remove('active'); });
        if (!isActive) {
            btn.classList.add('active');
            body.classList.add('active');
        }
    });
});

var reloadPaymentRefsBtn = document.getElementById('reloadPaymentRefsBtn');
if (reloadPaymentRefsBtn) {
    reloadPaymentRefsBtn.addEventListener('click', function() {
        window.location.reload();
    });
}

document.querySelectorAll('.refs-group-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = btn.getAttribute('data-ref-group');
        document.querySelectorAll('.refs-group-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('[id^=\"refGroup_\"]').forEach(function(g) { g.style.display = 'none'; });
        btn.classList.add('active');
        var group = document.getElementById('refGroup_' + key);
        if (group) {
            group.style.display = '';
        }
    });
});

document.querySelectorAll('.method-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = btn.getAttribute('data-method-tab');
        document.querySelectorAll('.method-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.method-panel').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        var panel = document.getElementById('methodPanel_' + key);
        if (panel) {
            panel.classList.add('active');
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>


