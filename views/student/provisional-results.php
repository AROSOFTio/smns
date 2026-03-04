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
    (int)($studentProfile['id'] ?? 0),
    (int)($currentSemester['id'] ?? 0),
    (string)($studentProfile['academic_status'] ?? '')
);
$academicStatus = (string)($academicStatusMeta['label'] ?? 'Status Pending');
$academicStatusStyle = (string)($academicStatusMeta['style'] ?? getAcademicStatusChipStyle('neutral'));

// Always resolve programme from admin-assigned student record.
$registeredProgramName = '-';
if (!empty($studentProfile['id'])) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => (int)$studentProfile['id']]);
        $programName = $progStmt->fetchColumn();
        if (!empty($programName)) {
            $registeredProgramName = $programName;
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
        $registeredProgramName = !empty($studentProfile['program_name']) ? $studentProfile['program_name'] : '-';
    }
}

// Navigation links with safe fallbacks for pages that may not exist yet.
$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkProvisionalResults = 'provisional-results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

$results = [];
$organizedResults = [];

if ($studentId > 0) {
    $sql = "
        SELECT
            cr.semester_id,
            cr.course_id,
            c.course_code,
            c.course_name,
            c.credit_hours,
            COALESCE(c.level_year, 1) AS year_of_study,
            CASE
                WHEN c.semester_offered = 3 THEN s.semester_number
                ELSE c.semester_offered
            END AS course_semester,
            s.semester_name,
            s.semester_number,
            ay.year_name AS academic_year,
            r.assignment_marks,
            r.final_exam_marks,
            r.total_marks,
            r.grade,
            r.grade_points,
            r.status AS result_status
        FROM course_registrations cr
        INNER JOIN courses c ON cr.course_id = c.id
        INNER JOIN semesters s ON cr.semester_id = s.id
        INNER JOIN academic_years ay ON s.academic_year_id = ay.id
        LEFT JOIN results r
            ON r.student_id = cr.student_id
            AND r.course_id = cr.course_id
            AND r.semester_id = cr.semester_id
        WHERE cr.student_id = :student_id
          AND EXISTS (
                SELECT 1
                FROM results rp
                WHERE rp.student_id = cr.student_id
                  AND rp.semester_id = cr.semester_id
                  AND rp.status IN ('approved', 'submitted', 'draft')
          )
          AND (r.status IS NULL OR r.status IN ('approved', 'submitted', 'draft'))
          AND (c.semester_offered = s.semester_number OR c.semester_offered = 3)
          AND (
                NOT EXISTS (
                    SELECT 1
                    FROM semester_registrations srx
                    WHERE srx.student_id = cr.student_id
                      AND srx.semester_id = cr.semester_id
                      AND srx.status = 'approved'
                )
                OR c.level_year = (
                    SELECT sry.year_of_study
                    FROM semester_registrations sry
                    WHERE sry.student_id = cr.student_id
                      AND sry.semester_id = cr.semester_id
                      AND sry.status = 'approved'
                    ORDER BY sry.id DESC
                    LIMIT 1
                )
          )
        ORDER BY COALESCE(c.level_year, 1) ASC, course_semester ASC, c.course_code ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute(['student_id' => $studentId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$bestCourses = [];
foreach ($results as $row) {
    $year = (int)($row['year_of_study'] ?? 1);
    $sem = (int)($row['course_semester'] ?? 1);
    $courseCode = strtoupper(trim((string)($row['course_code'] ?? '')));
    $courseKey = $year . '-' . $sem . '-' . $courseCode;

    // Prefer rows that are furthest along the provisional workflow.
    $status = strtolower((string)($row['result_status'] ?? ''));
    $score = 0;
    if ($status === 'published') {
        $score += 100;
    } elseif ($status === 'approved') {
        $score += 70;
    } elseif ($status === 'submitted') {
        $score += 40;
    } elseif ($status === 'draft') {
        $score += 20;
    }
    if ($row['total_marks'] !== null) {
        $score += 10;
    }
    if ($row['grade_points'] !== null) {
        $score += 10;
    }

    if (!isset($bestCourses[$courseKey]) || $score > $bestCourses[$courseKey]['_score']) {
        $row['_score'] = $score;
        $bestCourses[$courseKey] = $row;
    }
}

foreach ($bestCourses as $row) {
    $year = (int)($row['year_of_study'] ?? 1);
    $sem = (int)($row['course_semester'] ?? 1);

    if ($sem < 1 || $sem > 2) {
        $sem = 1;
    }

    if (!isset($organizedResults[$year])) {
        $organizedResults[$year] = [];
    }
    if (!isset($organizedResults[$year][$sem])) {
        $organizedResults[$year][$sem] = [
            'academic_year' => $row['academic_year'] ?? '-',
            'semester_name' => $row['semester_name'] ?? ('Semester ' . $sem),
            'courses' => []
        ];
    }

    unset($row['_score']);
    $organizedResults[$year][$sem]['courses'][] = $row;
}

ksort($organizedResults);
foreach ($organizedResults as &$semesters) {
    ksort($semesters);
}
unset($semesters);

$pageTitle = 'My Provisional Results - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
body { background: #f8fafc; }
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
.student-sidebar ul {
    list-style: none;
    padding: 10px 8px;
    margin: 0;
}
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
.student-sidebar li a {
    color: inherit;
    text-decoration: none;
    display: block;
}
.student-sidebar li.active {
    background: #eaf2ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
    font-weight: 700;
}
.student-sidebar li:hover {
    background: #f1f5f9;
    color: #0f172a;
}
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
    background: #f8fafc;
    transition: margin-left 0.25s ease, width 0.25s ease;
}
.student-sidebar.sidebar-collapsed {
    transform: translateX(-100%);
}
.main-content.full-width {
    margin-left: 0;
    width: 100vw;
    max-width: 100vw;
}
.student-topbar {
    display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0.5rem 1.2rem; position: sticky; top: 0; z-index: 10;
}
.student-profile-pic { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb; }
.results-wrap { padding: 1rem 1.2rem; }
.results-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04); }
.results-header { padding: 1rem 1.2rem; border-bottom: 1px solid #eef2f7; display: flex; align-items: center; justify-content: space-between; }
.results-header h4 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
.student-meta { font-size: 0.9rem; color: #334155; }
.year-block { margin: 1rem 0; }
.year-title { font-size: 1.02rem; font-weight: 700; color: #1e293b; margin: 0 0 0.6rem 0; }
.semester-title { background: #f8fafc; border: 1px solid #e2e8f0; border-bottom: none; padding: 0.65rem 0.9rem; font-size: 0.94rem; font-weight: 600; color: #334155; }
.results-table { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; }
.results-table th, .results-table td { padding: 8px 10px; border-bottom: 1px solid #edf2f7; font-size: 0.83rem; }
.results-table th { background: #f8fafc; color: #1f2937; font-weight: 700; }
.text-center { text-align: center; }
.summary-row td { background: #f8fafc; font-weight: 700; }
.cgpa-row td { background: #ecfdf3; color: #166534; font-weight: 700; }
.badge-published { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; }
.badge-pending { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; }
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
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
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
        <li class="active"><a href="<?php echo e($linkProvisionalResults); ?>">MY PROVISIONAL RESULTS</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:0.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#2563eb; color:#fff; border:none; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW BIO DATA</button>
                    <button onclick="location.href='<?php echo e($linkProvisionalResults); ?>'" style="background:#2563eb; color:#fff; border:none; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW PROVISIONAL RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">Generate PRN</button>
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

    <div style="padding:0.7rem 1.2rem 0.2rem 1.2rem; font-size:0.98rem; font-weight:600; display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap;">
        <span>PROGRAMME: <?php echo e($registeredProgramName); ?></span>
        <span class="status-badge status-active" style="font-size:0.85rem; padding:3px 10px;"><?php echo !empty($studentProfile['status']) ? strtoupper(e($studentProfile['status'])) : 'ACTIVE'; ?></span>
        <span style="margin-left:auto; font-size:1.05rem; color:#222;">ACADEMIC STATUS: <span style="<?php echo e($academicStatusStyle); ?> border-radius:6px; padding:4px 12px; font-weight:600;">
            <?php echo !empty($academicStatus) ? e($academicStatus) : '-'; ?>
        </span></span>
    </div>

    <div style="padding:0.45rem 1.2rem 0.2rem 1.2rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:nowrap; white-space:nowrap;">
        <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span>
        <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">CURRENT SEM. <span style="color:#2563eb;"><?php echo e($currentSemester['semester_name']); ?></span></span>
        <span style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'background:#dcfce7; color:#166534; border:1px solid #86efac; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;' : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;'; ?>">
            <?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?>
        </span>
        <span style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'background:#dcfce7; color:#166534; border:1px solid #86efac; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;' : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;'; ?>">
            <?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?>
        </span>
        <span style="background:#f1f5f9; color:#991b1b; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">APPROVED FEES AMOUNT: <?php echo number_format($approvedFeesAmount); ?>/=</span>
        <span style="background:#2563eb; color:#fff; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">BALANCE ON ACCOUNT: <?php echo number_format((float)$balanceOnAccount); ?>/=</span>
    </div>

    <div class="results-wrap">
        <div class="results-card">
            <div class="results-header">
                <h4>My Provisional Results</h4>
                <div class="student-meta">
                    STUDENT NO: <?php echo e($studentProfile['student_id'] ?? '-'); ?>
                </div>
            </div>

            <div class="p-3">
                <?php if (empty($organizedResults)): ?>
                    <div class="alert alert-info mb-0">
                        No provisional results found yet.
                    </div>
                <?php else: ?>
                    <?php
                    $cumulativeCredits = 0;
                    $cumulativePoints = 0.0;
                    ?>

                    <?php foreach ($organizedResults as $year => $semesters): ?>
                        <div class="year-block">
                            <div class="year-title">Year <?php echo (int)$year; ?></div>

                            <?php foreach ($semesters as $semNum => $data): ?>
                                <div class="semester-title">
                                    <?php echo e($data['academic_year']); ?> - Semester <?php echo (int)$semNum; ?>
                                </div>
                                <div class="table-responsive">
                                    <table class="results-table">
                                        <thead>
                                            <tr>
                                                <th>Course Code</th>
                                                <th>Course Name</th>
                                                <th class="text-center">CU</th>
                                                <th class="text-center">CW</th>
                                                <th class="text-center">Exam</th>
                                                <th class="text-center">Total</th>
                                                <th class="text-center">Grade</th>
                                                <th class="text-center">GP</th>
                                                <th class="text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $semesterCredits = 0;
                                            $semesterPoints = 0.0;
                                            $allPublished = true;
                                            $totalCourses = count($data['courses']);
                                            $publishedCourses = 0;
                                            ?>

                                            <?php foreach ($data['courses'] as $course): ?>
                                                <?php
                                                $isPublished = (($course['result_status'] ?? '') === 'published');
                                                $cu = (int)($course['credit_hours'] ?? 0);

                                                if ($isPublished && is_numeric($course['grade_points'])) {
                                                    $semesterCredits += $cu;
                                                    $semesterPoints += ((float)$course['grade_points']) * $cu;
                                                    $publishedCourses++;
                                                } else {
                                                    $allPublished = false;
                                                }
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo e($course['course_code'] ?? '-'); ?></strong></td>
                                                    <td><?php echo e($course['course_name'] ?? '-'); ?></td>
                                                    <td class="text-center"><?php echo e($course['credit_hours'] ?? '-'); ?></td>
                                                    <td class="text-center"><?php echo $isPublished ? number_format((float)$course['assignment_marks'], 0) : 'PA'; ?></td>
                                                    <td class="text-center"><?php echo $isPublished ? number_format((float)$course['final_exam_marks'], 0) : 'PA'; ?></td>
                                                    <td class="text-center"><?php echo $isPublished ? number_format((float)$course['total_marks'], 0) : 'PA'; ?></td>
                                                    <td class="text-center"><?php echo $isPublished ? e($course['grade'] ?? '-') : 'PA'; ?></td>
                                                    <td class="text-center"><?php echo $isPublished ? number_format((float)$course['grade_points'], 2) : 'PA'; ?></td>
                                                    <td class="text-center">
                                                        <?php if ($isPublished): ?>
                                                            <span class="badge-published">Published</span>
                                                        <?php else: ?>
                                                            <span class="badge-pending">PA</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <?php
                                            $sgpa = ($semesterCredits > 0) ? ($semesterPoints / $semesterCredits) : null;
                                            $sgpaText = ($sgpa !== null && $allPublished) ? number_format($sgpa, 2) : 'PA';

                                            if ($semesterCredits > 0 && $allPublished) {
                                                $cumulativeCredits += $semesterCredits;
                                                $cumulativePoints += $semesterPoints;
                                            }

                                            $cgpa = ($cumulativeCredits > 0) ? ($cumulativePoints / $cumulativeCredits) : null;
                                            $cgpaText = ($cgpa !== null) ? number_format($cgpa, 2) : 'PA';
                                            ?>

                                            <tr class="summary-row">
                                                <td colspan="2" class="text-center">Published Courses</td>
                                                <td class="text-center"><?php echo $publishedCourses; ?>/<?php echo $totalCourses; ?></td>
                                                <td colspan="3" class="text-center">Semester GPA</td>
                                                <td colspan="3" class="text-center"><?php echo $sgpaText; ?></td>
                                            </tr>
                                            <tr class="cgpa-row">
                                                <td colspan="6" class="text-center">Cumulative GPA (CGPA)</td>
                                                <td colspan="3" class="text-center"><?php echo $cgpaText; ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
</script>

<?php include '../../includes/footer.php'; ?>


