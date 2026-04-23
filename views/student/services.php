<?php
require_once '../../config.php';

$session = new Session('student');
$auth = new Auth('student');

if (
    !isset($_SESSION['student_logged_in']) ||
    $_SESSION['student_logged_in'] !== true ||
    ($_SESSION['student_role'] ?? '') !== 'student'
) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=student');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$studentId = (int)($studentProfile['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
$studentDisplayId = resolveDisplayedStudentRegistrationNumber($conn, $studentProfile);

$tab = $_GET['tab'] ?? 'apply';
$validTabs = ['apply', 'history', 'new_id'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'apply';
}

$requestCatalog = [
    'change_programme' => ['label' => 'CHANGE OF PROGRAMME', 'icon' => 'fas fa-user-graduate'],
    'administrative_registration' => ['label' => 'ADMINISTRATIVE REGISTRATION', 'icon' => 'fas fa-user-tie'],
    'accommodation' => ['label' => 'APPLY FOR ACCOMMODATION', 'icon' => 'fas fa-home'],
    'transcript_request' => ['label' => 'REQUEST TRANSCRIPT', 'icon' => 'fas fa-file-signature'],
    'student_record_access' => ['label' => 'REQUEST RECORD ACCESS', 'icon' => 'fas fa-folder-open'],
    'student_record_correction' => ['label' => 'REQUEST RECORD CORRECTION', 'icon' => 'fas fa-pen-to-square'],
    'data_deletion_anonymization' => ['label' => 'DATA DELETION / ANONYMIZATION', 'icon' => 'fas fa-user-shield'],
];
$selectedRequestType = trim((string)($_GET['request'] ?? ''));
if ($selectedRequestType !== '' && !array_key_exists($selectedRequestType, $requestCatalog)) {
    $selectedRequestType = '';
}
$formatRequestType = static function (?string $requestType) use ($requestCatalog): string {
    $requestType = (string)$requestType;
    if ($requestType !== '' && isset($requestCatalog[$requestType]['label'])) {
        return (string)$requestCatalog[$requestType]['label'];
    }
    return $requestType === '' ? '-' : ucwords(str_replace('_', ' ', $requestType));
};
$buildTranscriptChecklist = static function (array $eligibility): string {
    $parts = [
        'Completed studies: ' . (!empty($eligibility['completed_studies']) ? 'YES' : 'NO'),
        'No outstanding retakes: ' . (!empty($eligibility['has_no_retakes']) ? 'YES' : 'NO'),
        'Bills cleared: ' . (!empty($eligibility['bills_cleared']) ? 'YES' : 'NO'),
        'Discipline in good standing: ' . (!empty($eligibility['discipline_ok']) ? 'YES' : 'NO'),
    ];
    return implode(' | ', $parts);
};

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];
$studentSemesterContext = getStudentCurrentSemesterContext($conn, $studentId);
if (!empty($studentSemesterContext['id'])) {
    $currentSemester['semester_name'] = $studentSemesterContext['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($studentSemesterContext['id'] ?? 0);
    $currentSemester['academic_year'] = $studentSemesterContext['academic_year'] ?? '-';
}

$approvedFeesAmount = 0.0;
$outstandingBalance = 0.0;
$balanceOnAccount = 0.0;
if ($studentId > 0 && $currentSemester['id'] > 0) {
    $financialSnapshot = getStudentFinancialSnapshot(
        $conn,
        $studentId,
        (int)$currentSemester['id'],
        (int)($studentProfile['program_id'] ?? 0),
        (int)($studentSemesterContext['academic_year_id'] ?? 0),
        (int)($studentProfile['level_year'] ?? ($studentProfile['year_of_study'] ?? 1))
    );
    $approvedFeesAmount = (float)($financialSnapshot['approved_total_fees'] ?? 0);
    $outstandingBalance = (float)($financialSnapshot['balance_due'] ?? 0);
    $balanceOnAccount = (float)($financialSnapshot['balance_on_account'] ?? $outstandingBalance);
}

$academicStatusMeta = getStudentAcademicStatusMeta(
    $conn,
    (int)$studentId,
    (int)($currentSemester['id'] ?? 0),
    (string)($studentProfile['academic_status'] ?? '')
);
$academicStatus = (string)($academicStatusMeta['label'] ?? 'Status Pending');
$academicStatusStyle = (string)($academicStatusMeta['style'] ?? getAcademicStatusChipStyle('neutral'));

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
$highlightRequestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($studentId > 0) {
    try {
        $historyStmt = $conn->prepare("
            SELECT id, request_type, reason, status, admin_response, created_at, updated_at
            FROM student_requests
            WHERE student_id = :student_id
            ORDER BY created_at DESC
        ");
        $historyStmt->execute(['student_id' => $studentId]);
        $serviceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
    }
}
$transcriptEligibilityCurrent = null;
if ($studentId > 0) {
    try {
        $transcriptEligibilityCurrent = getStudentTranscriptEligibility($conn, $studentId);
    } catch (Exception $e) {
        $transcriptEligibilityCurrent = null;
    }
}
foreach ($serviceHistory as &$historyRow) {
    $historyRow['transcript_system_check'] = '';
    $historyRow['transcript_unfulfilled'] = [];
    $historyRow['transcript_eligible'] = null;
    if ((string)($historyRow['request_type'] ?? '') === 'transcript_request') {
        $eligibility = is_array($transcriptEligibilityCurrent) ? $transcriptEligibilityCurrent : [];
        $historyRow['transcript_eligible'] = !empty($eligibility['eligible']);
        $historyRow['transcript_system_check'] = $buildTranscriptChecklist((array)$eligibility);
        $historyRow['transcript_unfulfilled'] = !empty($eligibility['blocking_reasons'])
            ? array_values((array)$eligibility['blocking_reasons'])
            : [];
        if (empty($historyRow['transcript_eligible']) && empty($historyRow['transcript_unfulfilled'])) {
            $historyRow['transcript_unfulfilled'] = ['Unable to evaluate transcript requirements at the moment.'];
        }
    }
}
unset($historyRow);

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
    margin: 0.4rem 0.45rem 0.15rem;
    background: linear-gradient(180deg, #31465d 0%, #243547 100%);
    border-radius: 10px;
    color: #fff;
    text-align: center;
    padding: 0.4rem 0.45rem 0.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.28rem;
}
.sidebar-user-card img { width: 126px; height: 126px; border-radius: 16px; object-fit: cover; border: 2px solid rgba(255,255,255,0.82); box-shadow: 0 8px 18px rgba(0,0,0,0.2); }
.sidebar-user-name { font-size: 0.8rem; line-height: 1.12; margin: 0; }
.sidebar-user-no { font-size: 1rem; font-weight: 700; line-height: 1.08; margin: 0; }

.sidebar-portal-title { font-size: 0.6rem; letter-spacing: 0.07em; text-transform: uppercase; color: #d7e3f3; margin-bottom: 0.12rem; font-weight: 700; }
.services-submenu { list-style:none; padding:0 0 0 10px; margin:0 0 6px 0; }
.services-submenu li { font-size:.79rem; margin-bottom:3px; }
.services-submenu li.active { background:#dceaf3; color:#0e7490; border-color:#bfddeb; }

.main-content { margin-left:230px; width:calc(100vw - 230px); max-width:calc(100vw - 230px); min-height:100vh; transition:margin-left .25s ease, width .25s ease; }
.main-content.full-width { margin-left:0; width:100vw; max-width:100vw; }
.student-topbar { display:flex; align-items:center; justify-content:space-between; background:#fff; border-bottom:1px solid #e5e7eb; padding:.5rem 1.2rem; position:sticky; top:0; z-index:10; --key-btn-bg:#fff; --key-btn-border:#e5e7eb; --key-btn-color:#0f172a; }
html[data-theme='dark'] .student-topbar { --key-btn-bg:rgba(15,23,42,0.9); --key-btn-border:rgba(148,163,184,0.5); --key-btn-color:#f8fafc; }
html[data-theme='dark'] #keyDropMenu { background:#0f172a; color:#e2e8f0; border-color:#334155; box-shadow:0 2px 12px rgba(2,6,23,0.65); }
html[data-theme='dark'] #keyDropMenu label { color:#e2e8f0; }
html[data-theme='dark'] #keyDropMenu input.form-control { background:#0b1220; color:#e2e8f0; border-color:#334155; }
html[data-theme='dark'] #keyDropMenu input.form-control::placeholder { color:#94a3b8; }
.student-profile-pic { width: 64px; height: 64px; border-radius: 14px; object-fit: cover; border: 2px solid #e5e7eb; }
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
.unfulfilled-box {
    margin-top: 8px;
    border: 1px solid #fca5a5;
    background: #fff1f2;
    border-radius: 8px;
    padding: 8px 10px;
    color: #9f1239;
}
.unfulfilled-box .title {
    font-weight: 700;
    margin-bottom: 4px;
}
.unfulfilled-box ul {
    margin: 0;
    padding-left: 18px;
}

/* Dark mode overrides for services tiles */
html[data-theme='dark'] .service-tile {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
    color: #e5e7eb !important;
}
html[data-theme='dark'] .service-tile:hover {
    background: #273449 !important;
    border-color: #3b82f6 !important;
}
html[data-theme='dark'] .service-tile i {
    color: #93c5fd !important;
}
html[data-theme='dark'] .unfulfilled-box {
    background: rgba(127, 29, 29, 0.22);
    border-color: #7f1d1d;
    color: #fecaca;
}
html[data-theme='dark'] .status-pill.pending {
    background: #3f2a1f;
    color: #fdba74;
    border: 1px solid #7c2d12;
}
html[data-theme='dark'] .status-pill.approved {
    background: #183427;
    color: #86efac;
    border: 1px solid #166534;
}
html[data-theme='dark'] .status-pill.rejected {
    background: #3f1f25;
    color: #fca5a5;
    border: 1px solid #991b1b;
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
        <div class="sidebar-user-name"><?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?></div>
        <div class="sidebar-user-no"><?php echo e($studentDisplayId); ?></div>
    </div>
    <ul>
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY COURSES & RESULTS</a></li>
        <li class="active"><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li class="<?php echo $tab === 'apply' ? 'active' : ''; ?>"><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li class="<?php echo $tab === 'history' ? 'active' : ''; ?>"><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li class="<?php echo $tab === 'new_id' ? 'active' : ''; ?>"><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
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
            <div class="profile-dropdown" style="position:relative;">
                <button id="keyDropBtn" style="background:var(--key-btn-bg,#fff);border:1px solid var(--key-btn-border,#e5e7eb);border-radius:50%;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:var(--key-btn-color,#0f172a);">
                    <i class="fas fa-key"></i>
                </button>
                <div id="keyDropMenu" style="display:none;position:absolute;top:120%;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.12);min-width:260px;padding:12px;z-index:100;">
                    <div style="font-weight:700;font-size:0.92rem;margin-bottom:8px;color:#0f172a;">
                        <i class="fas fa-key" style="margin-right:6px;"></i> Change Password
                    </div>
                    <form id="keyChangePasswordForm" method="POST" action="change-password.php">
                        <?php echo csrfField(); ?>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem;margin-bottom:4px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem;margin-bottom:4px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:0.82rem;margin-bottom:4px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div id="keyChangePasswordMsg" style="display:none;font-size:0.82rem;margin-bottom:8px;"></div>
                        <button type="submit" class="btn btn-sm btn-primary btn-block">Update Password</button>
                    </form>
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
        <span class="chip gray">APPROVED FEES AMOUNT: <?php echo number_format($approvedFeesAmount); ?>/=</span>
        <span class="chip blue">BALANCE ON ACCOUNT: <?php echo number_format((float)$balanceOnAccount); ?>/=</span>
    </div>

    <div class="wrap">
        <div class="cardx">
            <?php if ($tab === 'apply'): ?>
                <div class="service-grid">
                    <?php foreach ($requestCatalog as $requestKey => $requestMeta): ?>
                        <a class="service-tile" href="services.php?tab=apply&request=<?php echo urlencode((string)$requestKey); ?>">
                            <i class="<?php echo e((string)$requestMeta['icon']); ?>"></i><?php echo e((string)$requestMeta['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-info mt-3 mb-0">
                    Compliance requests are handled here: record access, correction, and deletion/anonymization.
                </div>
                <?php if ($selectedRequestType !== ''): ?>
                    <div style="margin-top:14px;" class="request-box">
                        <form method="POST" action="submit-request.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="request_type" value="<?php echo e($selectedRequestType); ?>">
                            <label>Reason for <?php echo e($formatRequestType($selectedRequestType)); ?></label>
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
                                <tr<?php echo (int)($h['id'] ?? 0) === $highlightRequestId ? ' style="background:#fff7d6;"' : ''; ?>>
                                    <td><?php echo !empty($h['created_at']) ? e(date('d M Y H:i', strtotime($h['created_at']))) : '-'; ?></td>
                                    <td><?php echo e($formatRequestType((string)($h['request_type'] ?? ''))); ?></td>
                                    <td><?php echo e($h['reason'] ?? '-'); ?></td>
                                    <td><span class="status-pill <?php echo e($st); ?>"><?php echo e(strtoupper($st)); ?></span></td>
                                    <td>
                                        <div><strong>Request ID:</strong> <?php echo (int)($h['id'] ?? 0); ?></div>
                                        <div><?php echo e(trim((string)($h['admin_response'] ?? '')) !== '' ? (string)$h['admin_response'] : ($st === 'pending' ? 'Awaiting admin response.' : 'No written admin response was recorded.')); ?></div>
                                        <div class="mt-1"><small class="text-muted">Last updated: <?php echo !empty($h['updated_at']) ? e(date('d M Y H:i', strtotime($h['updated_at']))) : '-'; ?></small></div>
                                        <?php if ((string)($h['request_type'] ?? '') === 'transcript_request'): ?>
                                            <div class="mt-1"><small class="text-muted"><?php echo e((string)($h['transcript_system_check'] ?? '')); ?></small></div>
                                            <?php if (!empty($h['transcript_unfulfilled'])): ?>
                                                <div class="unfulfilled-box">
                                                    <div class="title">Unfulfilled Requirements</div>
                                                    <ul>
                                                        <?php foreach ((array)$h['transcript_unfulfilled'] as $unmetItem): ?>
                                                            <li><?php echo e((string)$unmetItem); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
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
var keyBtn = document.getElementById('keyDropBtn');
if (keyBtn) {
    keyBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        var menu = document.getElementById('keyDropMenu');
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    });
}
var keyForm = document.getElementById('keyChangePasswordForm');
if (keyForm) {
    keyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = document.getElementById('keyChangePasswordMsg');
        var submitBtn = keyForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        if (msg) {
            msg.style.display = 'none';
            msg.textContent = '';
        }
        fetch('change-password.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(keyForm)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (msg) {
                msg.style.display = 'block';
                if (data && data.success) {
                    msg.style.color = '#166534';
                    msg.textContent = data.message || 'Password updated.';
                    keyForm.reset();
                } else {
                    msg.style.color = '#b91c1c';
                    msg.textContent = (data && (data.error || data.message)) ? (data.error || data.message) : 'Unable to update password.';
                }
            }
        })
        .catch(function() {
            if (msg) {
                msg.style.display = 'block';
                msg.style.color = '#b91c1c';
                msg.textContent = 'Unable to update password.';
            }
        })
        .finally(function() {
            if (submitBtn) submitBtn.disabled = false;
        });
    });
}
document.addEventListener('click', function() {
    var keyMenu = document.getElementById('keyDropMenu');
    if (keyMenu) keyMenu.style.display = 'none';
});
</script>

<?php include '../../includes/footer.php'; ?>



