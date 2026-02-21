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
$resultView = $_GET['view'] ?? 'results'; // results | provisional
if (!in_array($resultView, ['results', 'provisional'], true)) {
    $resultView = 'results';
}

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

$rows = [];
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
        ORDER BY COALESCE(c.level_year, 1) ASC, course_semester ASC, c.course_code ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['student_id' => $studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$best = [];
foreach ($rows as $row) {
    $status = strtolower((string)($row['result_status'] ?? ''));
    $isPublished = ($status === 'published');
    if ($resultView === 'results' && !$isPublished) {
        continue;
    }
    if ($resultView === 'provisional' && $isPublished) {
        continue;
    }

    $year = (int)($row['year_of_study'] ?? 1);
    $sem = (int)($row['course_semester'] ?? 1);
    $code = strtoupper(trim((string)($row['course_code'] ?? '')));
    $key = $year . '-' . $sem . '-' . $code;

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

    if (!isset($best[$key]) || $score > $best[$key]['_score']) {
        $row['_score'] = $score;
        $best[$key] = $row;
    }
}

foreach ($best as $row) {
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
            'courses' => []
        ];
    }
    unset($row['_score']);
    $organizedResults[$year][$sem]['courses'][] = $row;
}
ksort($organizedResults);
foreach ($organizedResults as &$s) {
    ksort($s);
}
unset($s);

// In provisional view, always show all study-year/semester slots up to Year 4.
if ($resultView === 'provisional') {
    for ($y = 1; $y <= 4; $y++) {
        if (!isset($organizedResults[$y])) {
            $organizedResults[$y] = [];
        }
        for ($sem = 1; $sem <= 2; $sem++) {
            if (!isset($organizedResults[$y][$sem])) {
                $organizedResults[$y][$sem] = [
                    'academic_year' => '-',
                    'courses' => []
                ];
            }
        }
        ksort($organizedResults[$y]);
    }
    ksort($organizedResults);
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
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';

$pageTitle = 'My Programme - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
body { background: #f8fafc; }
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
.programme-submenu { list-style:none; padding:0 0 0 10px; margin:0 0 6px 0; }
.programme-submenu li { font-size:.79rem; margin-bottom:3px; }

.main-content { margin-left:230px; width:calc(100vw - 230px); max-width:calc(100vw - 230px); min-height:100vh; background:#f8fafc; transition:margin-left .25s ease, width .25s ease; }
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

.wrap { padding:1rem 1.2rem; }
.cardx { background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 4px 16px rgba(15,23,42,.04); }
.cardx-head { padding:1rem 1.2rem; border-bottom:1px solid #eef2f7; display:flex; align-items:center; justify-content:space-between; }
.cardx-title { margin:0; font-size:1.05rem; font-weight:700; color:#0f172a; }
.view-switch a { text-decoration:none; padding:6px 10px; border:1px solid #dbe3ef; border-radius:7px; font-size:.8rem; font-weight:700; color:#334155; margin-left:5px; }
.view-switch a.active { background:#1f7aa8; border-color:#1f7aa8; color:#fff; }
.tbl { width:100%; border-collapse:collapse; border:1px solid #e2e8f0; }
.tbl th,.tbl td { padding:8px 10px; border-bottom:1px solid #edf2f7; font-size:.83rem; }
.tbl th { background:#f8fafc; color:#1f2937; font-weight:700; }
.badge-published { background:#dcfce7; border:1px solid #86efac; color:#166534; padding:2px 8px; border-radius:999px; font-size:.75rem; }
.badge-provisional { background:#ffedd5; border:1px solid #fdba74; color:#9a3412; padding:2px 8px; border-radius:999px; font-size:.75rem; }
.semester-title { background:#f8fafc; border:1px solid #e2e8f0; border-bottom:none; padding:.65rem .9rem; font-size:.94rem; font-weight:600; color:#334155; margin-top:1rem; }

/* Dark mode overrides for results/provisional card */
html[data-theme='dark'] .cardx {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
    box-shadow: 0 4px 16px rgba(2, 6, 23, 0.45) !important;
}
html[data-theme='dark'] .cardx-head {
    background: var(--app-surface-2) !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .cardx-title {
    color: #e5e7eb !important;
}
html[data-theme='dark'] .view-switch a {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .view-switch a:hover {
    background: #273449 !important;
    color: #e2e8f0 !important;
}
html[data-theme='dark'] .view-switch a.active {
    background: #1f7aa8 !important;
    border-color: #1f7aa8 !important;
    color: #ffffff !important;
}
html[data-theme='dark'] .cardx > div[style*='padding:1rem 1.2rem;'] {
    background: var(--app-surface-1) !important;
    color: #e5e7eb !important;
}
html[data-theme='dark'] .semester-title {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
    color: #e5e7eb !important;
}
html[data-theme='dark'] .tbl {
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .tbl th {
    background: #1f2937 !important;
    color: #f8fafc !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .tbl td {
    color: #e5e7eb !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .tbl tbody tr:nth-child(odd) {
    background: rgba(148, 163, 184, 0.06) !important;
}
html[data-theme='dark'] .tbl tbody tr:hover {
    background: rgba(59, 130, 246, 0.10) !important;
}
html[data-theme='dark'] .badge-published {
    background: #14532d !important;
    border-color: #22c55e !important;
    color: #bbf7d0 !important;
}
html[data-theme='dark'] .badge-provisional {
    background: #7c2d12 !important;
    border-color: #fdba74 !important;
    color: #fed7aa !important;
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
        <div class="sidebar-user-no">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
    </div>
    <ul>
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li class="active"><a href="<?php echo e($linkProgramme); ?>">MY PROGRAMME</a></li>
        <ul class="programme-submenu">
            <li class="<?php echo $resultView === 'results' ? 'active' : ''; ?>"><a href="my-courses.php?view=results">MY RESULTS</a></li>
            <li class="<?php echo $resultView === 'provisional' ? 'active' : ''; ?>"><a href="my-courses.php?view=provisional">MY PROVISIONAL RESULTS</a></li>
        </ul>
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

    <div class="wrap">
        <div class="cardx">
            <div class="cardx-head">
                <h4 class="cardx-title"><?php echo $resultView === 'results' ? 'MY RESULTS (PUBLISHED)' : 'MY PROVISIONAL RESULTS'; ?></h4>
                <div class="view-switch">
                    <a class="<?php echo $resultView === 'results' ? 'active' : ''; ?>" href="my-courses.php?view=results">MY RESULTS</a>
                    <a class="<?php echo $resultView === 'provisional' ? 'active' : ''; ?>" href="my-courses.php?view=provisional">MY PROVISIONAL RESULTS</a>
                </div>
            </div>
            <div style="padding:1rem 1.2rem;">
                <?php if (empty($organizedResults)): ?>
                    <div class="alert alert-info mb-0">No <?php echo $resultView === 'results' ? 'published' : 'provisional'; ?> results found.</div>
                <?php else: ?>
                    <?php foreach ($organizedResults as $year => $semesters): ?>
                        <?php foreach ($semesters as $semNum => $data): ?>
                            <div class="semester-title">YEAR <?php echo (int)$year; ?> - <?php echo e($data['academic_year']); ?> - SEMESTER <?php echo (int)$semNum; ?></div>
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th>CODE</th><th>TITLE</th><th>MARK</th><th>CUs</th><th>GRADE</th><th>GD POINT</th><th>REMARK</th><th>STATUS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['courses'] as $c): ?>
                                        <?php $status = strtolower((string)($c['result_status'] ?? '')); ?>
                                        <tr>
                                            <td><?php echo e($c['course_code'] ?? '-'); ?></td>
                                            <td><?php echo e($c['course_name'] ?? '-'); ?></td>
                                            <td><?php echo $c['total_marks'] !== null ? number_format((float)$c['total_marks'], 0) : '-'; ?></td>
                                            <td><?php echo e($c['credit_hours'] ?? '-'); ?></td>
                                            <td><?php echo !empty($c['grade']) ? e($c['grade']) : '-'; ?></td>
                                            <td><?php echo $c['grade_points'] !== null ? number_format((float)$c['grade_points'], 2) : '-'; ?></td>
                                            <td><?php echo $status === 'published' ? 'FINAL' : 'PROVISIONAL'; ?></td>
                                            <td><?php echo $status === 'published' ? '<span class="badge-published">Published</span>' : '<span class="badge-provisional">' . e(ucfirst($status !== '' ? $status : 'provisional')) . '</span>'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
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
</script>

<?php include '../../includes/footer.php'; ?>


