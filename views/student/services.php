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
$studentId = (int)($studentProfile['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();

$tab = $_GET['tab'] ?? 'apply';
$validTabs = ['apply', 'history', 'new_id'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'apply';
}

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];
$activeSemester = Helper::getCurrentSemester();
if (!empty($activeSemester)) {
    $currentSemester['semester_name'] = $activeSemester['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($activeSemester['id'] ?? 0);
    if (!empty($activeSemester['academic_year_id'])) {
        $ayStmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = :id LIMIT 1");
        $ayStmt->execute(['id' => (int)$activeSemester['academic_year_id']]);
        $yearName = $ayStmt->fetchColumn();
        if ($yearName) {
            $currentSemester['academic_year'] = $yearName;
        }
    }
}

$outstandingBalance = (float)($studentProfile['account_balance'] ?? 0);
if ($studentId > 0 && $currentSemester['id'] > 0) {
    try {
        $balStmt = $conn->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM student_balances
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $balStmt->execute([
            'student_id' => $studentId,
            'semester_id' => (int)$currentSemester['id']
        ]);
        $outstandingBalance = (float)$balStmt->fetchColumn();
    } catch (Exception $e) {
    }
}

$academicStatus = 'Normal Progress';
if ($studentId > 0) {
    try {
        $standingStmt = $conn->prepare("
            SELECT sg.academic_standing
            FROM student_gpas sg
            WHERE sg.student_id = :student_id
            ORDER BY
                CASE WHEN :semester_id > 0 AND sg.semester_id = :semester_id THEN 0 ELSE 1 END,
                sg.semester_id DESC,
                sg.id DESC
            LIMIT 1
        ");
        $standingStmt->execute([
            'student_id' => $studentId,
            'semester_id' => (int)$currentSemester['id']
        ]);
        $standing = trim((string)$standingStmt->fetchColumn());
        if ($standing !== '') {
            $standingLower = strtolower($standing);
            if ($standingLower === 'good standing') {
                $academicStatus = 'Normal Progress';
            } elseif ($standingLower === 'suspension') {
                $academicStatus = 'Suspended';
            } else {
                $academicStatus = $standing;
            }
        }
    } catch (Exception $e) {
    }
}

$registeredProgramName = '-';
if ($studentId > 0) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => $studentId]);
        $programName = $progStmt->fetchColumn();
        if (!empty($programName)) {
            $registeredProgramName = $programName;
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
    }
}

$serviceHistory = [];
if ($studentId > 0) {
    try {
        $historyStmt = $conn->prepare("
            SELECT request_type, reason, status, admin_response, created_at, updated_at
            FROM student_requests
            WHERE student_id = :student_id
            ORDER BY created_at DESC
        ");
        $historyStmt->execute(['student_id' => $studentId]);
        $serviceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
    }
}

$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';

$pageTitle = 'Services - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
body { background: #f2f4f7; }
.student-sidebar { width:230px; background:linear-gradient(180deg,#fff 0%,#f8fafc 100%); min-height:100vh; height:100vh; overflow-y:auto; overflow-x:hidden; border-right:1px solid #e5e7eb; position:fixed; left:0; top:0; z-index:100; box-shadow:2px 0 12px rgba(15,23,42,.04); transition:transform .25s ease; }
.student-sidebar ul { list-style:none; padding:10px 8px; margin:0; }
.student-sidebar > ul { padding-bottom:20px; }
.student-sidebar li { padding:9px 12px; margin-bottom:4px; border:1px solid transparent; border-radius:8px; font-size:.82rem; letter-spacing:.02em; color:#334155; cursor:pointer; transition:all .2s ease; }
.student-sidebar li a { color:inherit; text-decoration:none; display:block; }
.student-sidebar li.active { background:#eaf2ff; border-color:#bfdbfe; color:#1d4ed8; font-weight:700; }
.student-sidebar li:hover { background:#f1f5f9; color:#0f172a; }
.student-sidebar.sidebar-collapsed { transform:translateX(-100%); }
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
.services-submenu { list-style:none; padding:0 0 0 10px; margin:0 0 6px 0; }
.services-submenu li { font-size:.79rem; margin-bottom:3px; }
.services-submenu li.active { background:#dceaf3; color:#0e7490; border-color:#bfddeb; }

.main-content { margin-left:230px; width:calc(100vw - 230px); max-width:calc(100vw - 230px); min-height:100vh; transition:margin-left .25s ease, width .25s ease; }
.main-content.full-width { margin-left:0; width:100vw; max-width:100vw; }
.student-topbar { display:flex; align-items:center; justify-content:space-between; background:#fff; border-bottom:1px solid #e5e7eb; padding:.5rem 1.2rem; position:sticky; top:0; z-index:10; }
.student-profile-pic { width:48px; height:48px; border-radius:50%; object-fit:cover; border:2px solid #e5e7eb; }
.top-logout-link { color:#dc2626; font-size:.82rem; font-weight:700; text-decoration:none; border:1px solid #fecaca; background:#fff1f2; border-radius:6px; padding:5px 9px; line-height:1; }
.top-logout-link:hover { color:#b91c1c; background:#ffe4e6; text-decoration:none; }
.chip-row { padding:.45rem 1.2rem .2rem; display:flex; align-items:center; gap:.35rem; white-space:nowrap; }
.chip { border-radius:6px; padding:4px 8px; font-weight:600; font-size:.78rem; line-height:1; white-space:nowrap; }
.chip.gray { background:#f1f5f9; color:#222; }
.chip.blue { background:#1f7aa8; color:#fff; }
.chip.red { background:#fee2e2; color:#991b1b; }

.wrap { padding:.9rem 1.2rem 1.3rem; }
.cardx { background:#f6f7f9; border:1px solid #e5e7eb; border-radius:10px; padding:.9rem; }
.service-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.service-tile {
    border:1px solid #d1d5db;
    border-radius:12px;
    background:#fff;
    text-align:center;
    padding:24px 12px;
    text-decoration:none;
    color:#1f2937;
    font-weight:700;
    font-size:.95rem;
}
.service-tile i { display:block; font-size:3rem; margin-bottom:12px; color:#111827; }
.tbl { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; }
.tbl th,.tbl td { border:1px solid #e5e7eb; padding:8px; font-size:.82rem; }
.tbl th { background:#f8fafc; font-weight:700; }
.status-pill { border-radius:999px; padding:2px 8px; font-size:.74rem; font-weight:700; }
.status-pill.pending { background:#ffedd5; color:#9a3412; }
.status-pill.approved { background:#dcfce7; color:#166534; }
.status-pill.rejected { background:#fee2e2; color:#991b1b; }
.request-box { border:1px solid #e5e7eb; border-radius:10px; background:#fff; padding:12px; max-width:640px; }
.request-box label { font-size:.84rem; font-weight:700; margin-bottom:6px; display:block; }
.request-box textarea { width:100%; min-height:110px; border:1px solid #cbd5e1; border-radius:8px; padding:8px 10px; font-size:.84rem; }
.request-box button { margin-top:10px; border:1px solid #1f7aa8; background:#1f7aa8; color:#fff; border-radius:8px; padding:8px 12px; font-weight:700; font-size:.84rem; }
</style>

<div class="student-sidebar">
    <div class="sidebar-user-card">
        <div class="sidebar-portal-title">SMNS-STUDENT PORTAL</div>
        <?php if (!empty($studentProfile['photo'])): ?>
            <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile">
        <?php else: ?>
            <img src="/assets/img/student_sample.jpg" alt="Profile">
        <?php endif; ?>
        <div class="sidebar-user-name"><?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?></div>
        <div class="sidebar-user-no">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
    </div>
    <ul>
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY PROGRAMME</a></li>
        <li class="active"><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li class="<?php echo $tab === 'apply' ? 'active' : ''; ?>"><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li class="<?php echo $tab === 'history' ? 'active' : ''; ?>"><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li class="<?php echo $tab === 'new_id' ? 'active' : ''; ?>"><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic">
            <?php endif; ?>
            <span style="font-size:.98rem; color:#222; font-weight:600; white-space:nowrap;"><?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?></span>
            <a href="logout.php" class="top-logout-link">Logout</a>
        </div>
    </div>

    <div class="chip-row">
        <span style="font-size:1rem; color:#1f7aa8;">PROGRAMME:</span>
        <span style="font-size:1rem;"><?php echo e($registeredProgramName); ?></span>
        <span class="chip" style="background:#16a34a; color:#fff;">ACTIVE</span>
        <span style="margin-left:auto; font-size:1rem; color:#1f7aa8;">ACADEMIC STATUS:</span>
        <span class="chip red" style="color:#c2410c; background:#ffedd5; border:1px solid #fdba74;"><?php echo e($academicStatus); ?></span>
    </div>
    <div class="chip-row">
        <span class="chip gray">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span>
        <span class="chip gray">CURRENT SEM. <span style="color:#2563eb;"><?php echo e($currentSemester['semester_name']); ?></span></span>
        <span class="chip red"><?php echo (isset($studentProfile['enrollment_status']) && strtolower($studentProfile['enrollment_status']) === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?></span>
        <span class="chip red"><?php echo (isset($studentProfile['registration_status']) && strtolower($studentProfile['registration_status']) === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
        <span class="chip gray">TOTAL FEES BAL DUE: <?php echo number_format($outstandingBalance); ?>/=</span>
        <span class="chip blue">BALANCE ON ACCOUNT: <?php echo number_format((float)($studentProfile['account_balance'] ?? 0)); ?>/=</span>
    </div>

    <div class="wrap">
        <div class="cardx">
            <?php if ($tab === 'apply'): ?>
                <div class="service-grid">
                    <a class="service-tile" href="services.php?tab=apply&request=change_programme"><i class="fas fa-user-graduate"></i>CHANGE OF PROGRAMME</a>
                    <a class="service-tile" href="services.php?tab=apply&request=administrative_registration"><i class="fas fa-user-tie"></i>ADMINISTRATIVE REGISTRATION</a>
                    <a class="service-tile" href="services.php?tab=apply&request=accommodation"><i class="fas fa-home"></i>APPLY FOR ACCOMMODATION</a>
                </div>
                <?php if (!empty($_GET['request'])): ?>
                    <div style="margin-top:14px;" class="request-box">
                        <form method="POST" action="submit-request.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="request_type" value="<?php echo e((string)$_GET['request']); ?>">
                            <label>Reason</label>
                            <textarea name="reason" required></textarea>
                            <button type="submit">Submit Request</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php elseif ($tab === 'history'): ?>
                <?php if (empty($serviceHistory)): ?>
                    <div class="alert alert-info mb-0">No service request history found.</div>
                <?php else: ?>
                    <table class="tbl">
                        <thead><tr><th>Date</th><th>Request Type</th><th>Reason</th><th>Status</th><th>Admin Response</th></tr></thead>
                        <tbody>
                            <?php foreach ($serviceHistory as $h): ?>
                                <?php $st = strtolower((string)($h['status'] ?? 'pending')); ?>
                                <tr>
                                    <td><?php echo !empty($h['created_at']) ? e(date('d M Y H:i', strtotime($h['created_at']))) : '-'; ?></td>
                                    <td><?php echo e(ucwords(str_replace('_', ' ', (string)($h['request_type'] ?? '-')))); ?></td>
                                    <td><?php echo e($h['reason'] ?? '-'); ?></td>
                                    <td><span class="status-pill <?php echo e($st); ?>"><?php echo e(strtoupper($st)); ?></span></td>
                                    <td><?php echo e($h['admin_response'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php else: ?>
                <div class="request-box">
                    <form method="POST" action="submit-request.php">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="request_type" value="new_id_card">
                        <label>New ID Card Request Reason</label>
                        <textarea name="reason" placeholder="Explain why you need a new ID card." required></textarea>
                        <button type="submit">Submit New ID Card Request</button>
                    </form>
                </div>
            <?php endif; ?>
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
</script>

<?php include '../../includes/footer.php'; ?>

