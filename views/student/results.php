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

$registeredProgramName = '-';
if (!empty($studentProfile['id'])) {
    $effectiveProgram = getStudentEffectiveProgram($conn, (int)$studentProfile['id'], [
        'program_id'   => (int)($studentProfile['program_id'] ?? 0),
        'program_code' => (string)($studentProfile['program_code'] ?? ''),
        'program_name' => (string)($studentProfile['program_name'] ?? ''),
    ]);
    if (!empty($effectiveProgram['program_name'])) {
        $registeredProgramName = (string)$effectiveProgram['program_name'];
    } elseif (!empty($studentProfile['program_name'])) {
        $registeredProgramName = (string)$studentProfile['program_name'];
    }
}

$studentViewsPath     = BASE_PATH . '/views/student/';
$linkDashboard        = 'dashboard.php';
$linkResults          = 'results.php';
$linkProvisionalResults = 'provisional-results.php';
$linkInvoices         = file_exists($studentViewsPath . 'invoices.php')     ? 'invoices.php'     : 'payments.php?section=bills';
$linkFees             = file_exists($studentViewsPath . 'fees.php')         ? 'fees.php'         : 'payments.php?section=fees';
$linkGeneratePrn      = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll           = 'course-registration.php';
$linkPayments         = file_exists($studentViewsPath . 'payments.php')     ? 'payments.php'     : 'notifications.php';
$linkProgramme        = 'my-courses.php';
$linkApplyServices    = file_exists($studentViewsPath . 'services.php')     ? 'services.php'     : 'dashboard.php';
$linkServiceHistory   = 'notifications.php';
$linkNewIdCards       = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox          = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount      = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

$results          = [];
$organizedResults = [];

$resolveTranscriptScale = static function ($mark): array {
    if ($mark === null || $mark === '' || !is_numeric($mark)) {
        return ['grade' => '', 'grade_point' => null];
    }
    $score = (float)$mark;
    if ($score >= 80.0) return ['grade' => 'A',  'grade_point' => 5.00];
    if ($score >= 75.0) return ['grade' => 'B+', 'grade_point' => 4.00];
    if ($score >= 70.0) return ['grade' => 'B',  'grade_point' => 3.50];
    if ($score >= 65.0) return ['grade' => 'C+', 'grade_point' => 3.00];
    if ($score >= 60.0) return ['grade' => 'C',  'grade_point' => 2.50];
    if ($score >= 50.0) return ['grade' => 'D',  'grade_point' => 2.00];
    if ($score >= 40.0) return ['grade' => 'E',  'grade_point' => 1.00];
    return ['grade' => 'F', 'grade_point' => 0.00];
};

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
                  AND rp.status = 'published'
          )
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
    $year      = (int)($row['year_of_study'] ?? 1);
    $sem       = (int)($row['course_semester'] ?? 1);
    $courseKey = $year . '-' . $sem . '-' . strtoupper(trim((string)($row['course_code'] ?? '')));

    $status = strtolower((string)($row['result_status'] ?? ''));
    $score  = 0;
    if ($status === 'published') $score += 100;
    elseif ($status === 'approved')  $score += 70;
    elseif ($status === 'submitted') $score += 40;
    elseif ($status === 'draft')     $score += 20;
    if ($row['total_marks']  !== null) $score += 10;
    if ($row['grade_points'] !== null) $score += 10;

    if (!isset($bestCourses[$courseKey]) || $score > $bestCourses[$courseKey]['_score']) {
        $row['_score'] = $score;
        $bestCourses[$courseKey] = $row;
    }
}

foreach ($bestCourses as $row) {
    $year = (int)($row['year_of_study'] ?? 1);
    $sem  = (int)($row['course_semester'] ?? 1);
    if ($sem < 1 || $sem > 2) $sem = 1;

    if (!isset($organizedResults[$year]))       $organizedResults[$year] = [];
    if (!isset($organizedResults[$year][$sem])) {
        $organizedResults[$year][$sem] = [
            'academic_year' => $row['academic_year'] ?? '-',
            'semester_name' => $row['semester_name'] ?? ('Semester ' . $sem),
            'courses'       => []
        ];
    }
    unset($row['_score']);
    $organizedResults[$year][$sem]['courses'][] = $row;
}

ksort($organizedResults);
foreach ($organizedResults as &$semesters) { ksort($semesters); }
unset($semesters);

$pageTitle     = 'View Results - ' . APP_NAME;
$additionalCSS = array_merge($additionalCSS ?? [], ['student-portal.css']);
$additionalJS  = array_merge($additionalJS  ?? [], ['student-portal.js']);
include '../../includes/header.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   BASE LAYOUT
═══════════════════════════════════════════════════════════════ */
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
    left: 0; top: 0;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(15,23,42,0.04);
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
.student-sidebar li:hover  { background: #f1f5f9; color: #0f172a; }

.sidebar-user-card {
    margin: 0.4rem 0.45rem 0.15rem;
    background: linear-gradient(180deg, #31465d 0%, #243547 100%);
    border-radius: 10px;
    color: #fff;
    text-align: center;
    padding: 0.4rem 0.45rem 0.5rem;
    display: flex; flex-direction: column; align-items: center; gap: 0.28rem;
}
.sidebar-user-card img { width: 126px; height: 126px; border-radius: 16px; object-fit: cover; border: 2px solid rgba(255,255,255,0.82); box-shadow: 0 8px 18px rgba(0,0,0,0.2); }
.sidebar-user-name    { font-size: 0.8rem; line-height: 1.12; margin: 0; }
.sidebar-user-no      { font-size: 1rem; font-weight: 700; line-height: 1.08; margin: 0; }
.sidebar-portal-title { font-size: 0.6rem; letter-spacing: 0.07em; text-transform: uppercase; color: #d7e3f3; margin-bottom: 0.12rem; font-weight: 700; }

.student-sidebar.sidebar-collapsed { transform: translateX(-100%); }

.main-content {
    margin-left: var(--student-sidebar-width, 230px);
    min-height: 100vh;
    background: #f8fafc;
    transition: margin-left 0.25s ease;
    overflow-x: hidden;
}
.main-content.full-width { margin-left: 0; }

.student-topbar {
    display: flex; align-items: center; justify-content: space-between;
    background: #fff; border-bottom: 1px solid #e5e7eb;
    padding: 0.5rem 1.2rem;
    position: sticky; top: 0; z-index: 10;
    --key-btn-bg: #fff; --key-btn-border: #e5e7eb; --key-btn-color: #0f172a;
}
.student-profile-pic { width: 64px; height: 64px; border-radius: 14px; object-fit: cover; border: 2px solid #e5e7eb; }

/* ═══════════════════════════════════════════════════════════════
   RESULTS CARD
═══════════════════════════════════════════════════════════════ */
.results-wrap   { padding: 1rem 1.2rem; }
.results-card   { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 4px 16px rgba(15,23,42,0.04); }
.results-header { padding: 1rem 1.2rem; border-bottom: 1px solid #eef2f7; display: flex; align-items: center; justify-content: space-between; }
.results-header h4 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
.student-meta  { font-size: 0.9rem; color: #334155; }
.year-block    { margin: 1rem 0; }
.year-title    { font-size: 1.02rem; font-weight: 700; color: #1e293b; margin: 0 0 0.6rem 0; }
.semester-title {
    background: #f8fafc; border: 1px solid #e2e8f0; border-bottom: none;
    padding: 0.65rem 0.9rem; font-size: 0.94rem; font-weight: 600; color: #334155;
}

/* ── Table base ────────────────────────────────────────────── */
.marks-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #e2e8f0;
}
.marks-table th, .marks-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #edf2f7;
    font-size: 0.83rem;
    vertical-align: middle;
}
.marks-table th {
    background: #eaf2ff; color: #0f172a; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.02em; font-size: 0.78rem;
}
.marks-table tbody tr:nth-child(even) { background: #f9fbff; }
.marks-table tbody tr:hover           { background: #eef6ff; }

/* ── Results table fixed column widths ─────────────────────── */
.student-results-table { table-layout: fixed; }
.student-results-table .col-code  { width: 100px; text-align: left; }
.student-results-table .col-name  { width: 220px; text-align: left; white-space: normal; overflow-wrap: anywhere; }
.student-results-table .col-cu    { width: 48px;  text-align: center; }
.student-results-table .col-cw    { width: 55px;  text-align: center; }
.student-results-table .col-exam  { width: 58px;  text-align: center; }
.student-results-table .col-total { width: 58px;  text-align: center; }
.student-results-table .col-grade { width: 58px;  text-align: center; }
.student-results-table .col-gp    { width: 65px;  text-align: center; }

/* All non-name columns: no wrap */
.student-results-table th:not(.col-name),
.student-results-table td:not(.col-name) { white-space: nowrap; }

.text-center   { text-align: center; }
.summary-row td { background: #f8fafc; font-weight: 700; }
.cgpa-row    td { background: #ecfdf3; color: #166534; font-weight: 700; }

.grading-key {
    margin-top: 1rem; padding: 0.85rem 1rem;
    border: 1px solid #e2e8f0; border-radius: 10px;
    background: #f8fafc; font-size: 0.82rem; color: #334155;
}
.grading-key strong { color: #0f172a; }

.badge-published { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; white-space: nowrap; }
.badge-pending   { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; white-space: nowrap; }

/* ═══════════════════════════════════════════════════════════════
   SCROLL WRAPPER — single authoritative definition
═══════════════════════════════════════════════════════════════ */
.table-scroll-wrap {
    display: block;
    width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    touch-action: pan-x pan-y;
    scrollbar-width: thin;
    scrollbar-color: #94a3b8 transparent;
    border: 1px solid #e2e8f0;
    border-top: none;
}
.table-scroll-wrap::-webkit-scrollbar        { height: 5px; }
.table-scroll-wrap::-webkit-scrollbar-thumb  { background: #94a3b8; border-radius: 999px; }
.table-scroll-wrap::-webkit-scrollbar-track  { background: transparent; }

/* Table inside wrapper: never collapse below min-width */
.table-scroll-wrap .marks-table {
    min-width: 640px;
    width: 100%;
    border: none;
}

/* ── Mobile scroll hint ─────────────────────────────────────── */
.mobile-scroll-hint {
    display: none;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #1d4ed8;
    background: #eff6ff;
    padding: 5px 10px;
    border: 1px solid #bfdbfe;
    border-top: none;
    border-bottom: none;
    transition: opacity 0.3s ease;
}
.mobile-scroll-hint.hint-hidden {
    opacity: 0;
    pointer-events: none;
}
.mobile-scroll-hint svg {
    flex-shrink: 0;
    animation: hint-nudge 1.8s ease-in-out infinite;
}
@keyframes hint-nudge {
    0%, 100% { transform: translateX(0); }
    40%       { transform: translateX(4px); }
    60%       { transform: translateX(-2px); }
}

/* ═══════════════════════════════════════════════════════════════
   DARK MODE
═══════════════════════════════════════════════════════════════ */
html[data-theme='dark'] .main-content   { background: var(--app-bg, #020617) !important; }
html[data-theme='dark'] .results-card   { background: var(--app-surface, #0f172a) !important; border-color: var(--app-border, #334155) !important; box-shadow: 0 10px 28px rgba(2,6,23,0.45); }
html[data-theme='dark'] .results-header { background: var(--app-surface-1, #111827) !important; border-bottom-color: var(--app-border, #334155) !important; }
html[data-theme='dark'] .results-header h4,
html[data-theme='dark'] .student-meta,
html[data-theme='dark'] .year-title     { color: #e2e8f0; }
html[data-theme='dark'] .semester-title { background: #172033 !important; color: #dbeafe !important; border-color: #334155 !important; }
html[data-theme='dark'] .marks-table    { border-color: #334155 !important; }
html[data-theme='dark'] .marks-table th { background: #1e293b !important; color: #f8fafc !important; border-color: #334155 !important; }
html[data-theme='dark'] .marks-table td { border-color: #334155 !important; color: #e2e8f0 !important; }
html[data-theme='dark'] .marks-table tbody tr              { background: #0f172a !important; }
html[data-theme='dark'] .marks-table tbody tr:nth-child(even) { background: #0f172a !important; }
html[data-theme='dark'] .marks-table tbody tr:hover        { background: #132235 !important; }
html[data-theme='dark'] .summary-row td { background: #182235 !important; color: #f8fafc !important; }
html[data-theme='dark'] .cgpa-row td    { background: #0f2b1f !important; color: #bbf7d0 !important; }
html[data-theme='dark'] .grading-key    { background: #172033 !important; border-color: #334155 !important; color: #e2e8f0 !important; }
html[data-theme='dark'] .grading-key strong { color: #f8fafc; }
html[data-theme='dark'] .table-scroll-wrap  { border-color: #334155 !important; background: #0f172a !important; }
html[data-theme='dark'] .mobile-scroll-hint { background: #172033; color: #93c5fd; border-color: #334155; }
html[data-theme='dark'] .student-topbar { --key-btn-bg: rgba(15,23,42,0.9); --key-btn-border: rgba(148,163,184,0.5); --key-btn-color: #f8fafc; }
html[data-theme='dark'] #keyDropMenu    { background: #0f172a; color: #e2e8f0; border-color: #334155; box-shadow: 0 2px 12px rgba(2,6,23,0.65); }
html[data-theme='dark'] #keyDropMenu label { color: #e2e8f0; }
html[data-theme='dark'] #keyDropMenu input.form-control { background: #0b1220; color: #e2e8f0; border-color: #334155; }
html[data-theme='dark'] #keyDropMenu input.form-control::placeholder { color: #94a3b8; }
html[data-theme='dark'] .student-topbar span { color: var(--app-text, #e2e8f0) !important; }
html[data-theme='dark'] #menuBtn { color: var(--app-text, #e2e8f0) !important; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE — SIDEBAR COLLAPSES ≤992px
═══════════════════════════════════════════════════════════════ */
@media (max-width: 992px) {
    .student-sidebar { transform: translateX(-100%); }
    .main-content    { margin-left: 0 !important; width: 100% !important; overflow-x: hidden; }
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ≤767px — typography/padding only, no layout overrides
═══════════════════════════════════════════════════════════════ */
@media (max-width: 767.98px) {
    .student-topbar {
        flex-wrap: nowrap !important;
        gap: 6px !important;
        padding: 6px 8px 6px 52px !important;
        min-height: 50px !important;
    }
    .student-topbar > div:first-child {
        flex: 1 1 auto !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        gap: 5px !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 0 !important;
    }
    .student-topbar > div:first-child::-webkit-scrollbar { display: none; }
    .student-topbar > div:last-child {
        flex: 0 0 auto !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        gap: 4px !important;
        margin-left: auto !important;
    }
    .student-topbar button { font-size: 0.72rem !important; padding: 4px 8px !important; white-space: nowrap !important; }
    #menuBtn, #profileDropBtn, #keyDropBtn { width: 30px !important; height: 30px !important; min-width: 30px !important; padding: 0 !important; }
    .student-profile-pic { width: 34px !important; height: 34px !important; border-radius: 9px !important; }

    .results-wrap           { padding: 0.65rem !important; }
    .results-header         { flex-wrap: wrap !important; gap: 0.4rem !important; padding: 0.75rem !important; }
    .results-header h4      { font-size: 0.9rem !important; }
    .student-meta,
    .year-title,
    .semester-title,
    .grading-key            { font-size: 0.79rem !important; }
    .marks-table th,
    .marks-table td         { padding: 8px 9px !important; font-size: 0.79rem !important; }

    .student-results-table .col-code  { width: 88px; }
    .student-results-table .col-name  { width: 190px; }
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ≤576px
═══════════════════════════════════════════════════════════════ */
@media (max-width: 576px) {
    .results-wrap  { padding: 0.45rem !important; }
    .results-card  { border-radius: 0 !important; box-shadow: none !important; }
    .results-card .p-3 { padding: 0.6rem !important; }
    .results-header { display: block !important; padding: 0.55rem 0.7rem !important; }
    .results-header h4 { font-size: 0.86rem !important; line-height: 1.2 !important; text-transform: uppercase; }
    .student-meta  { display: none !important; }
    .year-title    { margin: 0 0 0.45rem !important; font-size: 0.82rem !important; text-transform: uppercase; }
    .semester-title {
        border-radius: 0 !important;
        padding: 0.55rem 0.65rem !important;
        font-size: 0.78rem !important;
        text-transform: uppercase;
    }
    .marks-table th,
    .marks-table td { padding: 7px 8px !important; font-size: 0.76rem !important; }

    .student-results-table .col-code  { width: 78px; }
    .student-results-table .col-name  { width: 160px; }
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ≤430px
═══════════════════════════════════════════════════════════════ */
@media (max-width: 430px) {
    .student-topbar { padding-left: 48px !important; }
    .student-topbar > div:last-child > span { display: none !important; }
    .results-wrap { padding: 0.4rem !important; }
    .marks-table th,
    .marks-table td { padding: 6px 7px !important; font-size: 0.72rem !important; }
    .student-results-table .col-code { width: 70px; }
    .student-results-table .col-name { width: 145px; }
}
</style>

<?php /* ── SIDEBAR ───────────────────────────────────────────────── */ ?>
<div class="student-sidebar" id="studentSidebar">
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
        <div class="sidebar-user-no"><?php echo e($studentDisplayId); ?></div>
    </div>
    <ul>
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT &amp; REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY COURSES &amp; RESULTS</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<?php /* ── MAIN CONTENT ──────────────────────────────────────────── */ ?>
<div class="main-content" id="mainContent">

    <?php /* ── Top bar ─────────────────────────────────────────────── */ ?>
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:0.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#2563eb; color:#fff; border:none; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#2563eb; color:#fff; border:none; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic" style="width:36px;height:36px;">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic" style="width:36px;height:36px;">
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
                    <a href="dashboard.php"          style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Profile</a>
                    <a href="services.php?tab=apply"  style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Services</a>
                    <a href="logout.php"              style="display:block; padding:8px 14px; color:#dc2626; text-decoration:none; font-weight:600; font-size:0.92rem;">Logout</a>
                </div>
            </div>
            <div class="profile-dropdown" style="position:relative;">
                <button id="keyDropBtn" style="background:var(--key-btn-bg,#fff); border:1px solid var(--key-btn-border,#e5e7eb); border-radius:50%; width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:var(--key-btn-color,#0f172a);">
                    <i class="fas fa-key"></i>
                </button>
                <div id="keyDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.12); min-width:260px; padding:12px; z-index:100;">
                    <div style="font-weight:700; font-size:0.92rem; margin-bottom:8px; color:#0f172a;">
                        <i class="fas fa-key" style="margin-right:6px;"></i> Change Password
                    </div>
                    <form id="keyChangePasswordForm" method="POST" action="change-password.php">
                        <?php echo csrfField(); ?>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div id="keyChangePasswordMsg" style="display:none; font-size:0.82rem; margin-bottom:8px;"></div>
                        <button type="submit" class="btn btn-sm btn-primary btn-block">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php /* ── Programme / status strip ────────────────────────────── */ ?>
    <div style="padding:0.7rem 1.2rem 0.2rem 1.2rem; font-size:0.98rem; font-weight:600; display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap;">
        <span>PROGRAMME: <?php echo e($registeredProgramName); ?></span>
        <span class="status-badge status-active" style="font-size:0.85rem; padding:3px 10px;">
            <?php echo !empty($studentProfile['status']) ? strtoupper(e($studentProfile['status'])) : 'ACTIVE'; ?>
        </span>
        <span style="margin-left:auto; font-size:1.05rem; color:#222;">
            ACADEMIC STATUS:
            <span style="<?php echo e($academicStatusStyle); ?> border-radius:6px; padding:4px 12px; font-weight:600;">
                <?php echo !empty($academicStatus) ? e($academicStatus) : '-'; ?>
            </span>
        </span>
    </div>

    <?php /* ── Chip strip ──────────────────────────────────────────── */ ?>
    <div style="padding:0.45rem 1.2rem 0.2rem 1.2rem; display:flex; align-items:center; gap:0.35rem; overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch; scrollbar-width:none;">
        <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; white-space:nowrap; flex:0 0 auto;">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span>
        <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; white-space:nowrap; flex:0 0 auto;">CURRENT SEM. <span style="color:#2563eb;"><?php echo e($currentSemester['semester_name']); ?></span></span>
        <?php
        $lifecycleStatus  = getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0));
        $isEnrolled       = ($lifecycleStatus['enrollment_status']  ?? 'not_enrolled')  === 'enrolled';
        $isRegistered     = ($lifecycleStatus['registration_status'] ?? 'not_registered') === 'registered';
        $chipBase         = 'border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; white-space:nowrap; flex:0 0 auto;';
        ?>
        <span style="<?php echo $isEnrolled  ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; echo $chipBase; ?>">
            <?php echo $isEnrolled  ? 'ENROLLED'   : 'NOT ENROLLED'; ?>
        </span>
        <span style="<?php echo $isRegistered ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; echo $chipBase; ?>">
            <?php echo $isRegistered ? 'REGISTERED' : 'NOT REGISTERED'; ?>
        </span>
        <span style="background:#f1f5f9; color:#991b1b; <?php echo $chipBase; ?>">APPROVED FEES AMOUNT: <?php echo number_format($approvedFeesAmount); ?>/=</span>
        <span style="background:#2563eb; color:#fff; <?php echo $chipBase; ?>">BALANCE ON ACCOUNT: <?php echo number_format((float)$balanceOnAccount); ?>/=</span>
    </div>

    <?php /* ── Results card ────────────────────────────────────────── */ ?>
    <div class="results-wrap">
        <div class="results-card">
            <div class="results-header">
                <h4>View Published Results</h4>
                <div class="student-meta">STUDENT NO: <?php echo e($studentDisplayId); ?></div>
            </div>

            <div class="p-3" style="padding:1rem 1.2rem;">
                <?php if (empty($organizedResults)): ?>
                    <div class="alert alert-info mb-0" style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;color:#64748b;text-align:center;">
                        No published results found yet.
                    </div>
                <?php else: ?>

                <?php
                $cumulativeCredits = 0;
                $cumulativePoints  = 0.0;
                ?>

                <?php foreach ($organizedResults as $year => $semesters): ?>
                    <div class="year-block">
                        <div class="year-title">Year <?php echo (int)$year; ?></div>

                        <?php foreach ($semesters as $semNum => $data): ?>
                            <div style="margin-bottom:1.4rem;">
                                <div class="semester-title">
                                    <?php echo e($data['academic_year']); ?> &mdash; Semester <?php echo (int)$semNum; ?>
                                </div>

                                <?php /* Scroll hint — shown by JS only when table actually overflows */ ?>
                                <div class="mobile-scroll-hint">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path d="M5 12h14M15 7l5 5-5 5"/>
                                    </svg>
                                    Scroll right to see all columns
                                </div>

                                <div class="table-scroll-wrap">
                                    <table class="marks-table student-results-table">
                                        <colgroup>
                                            <col class="col-code">
                                            <col class="col-name">
                                            <col class="col-cu">
                                            <col class="col-cw">
                                            <col class="col-exam">
                                            <col class="col-total">
                                            <col class="col-grade">
                                            <col class="col-gp">
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th class="col-code">Course Code</th>
                                                <th class="col-name">Course Name</th>
                                                <th class="col-cu">CU</th>
                                                <th class="col-cw">CW</th>
                                                <th class="col-exam">Exam</th>
                                                <th class="col-total">Total</th>
                                                <th class="col-grade">Grade</th>
                                                <th class="col-gp">GP</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $semesterCredits  = 0;
                                            $semesterPoints   = 0.0;
                                            $allPublished     = true;
                                            $totalCourses     = count($data['courses']);
                                            $publishedCourses = 0;
                                            ?>

                                            <?php foreach ($data['courses'] as $course): ?>
                                                <?php
                                                $isPublished   = (($course['result_status'] ?? '') === 'published');
                                                $cu            = (int)($course['credit_hours'] ?? 0);
                                                $resolvedScale = $resolveTranscriptScale($course['total_marks'] ?? null);

                                                $storedGrade      = trim((string)($course['grade'] ?? ''));
                                                $storedGradePoint = ($course['grade_points'] !== null && $course['grade_points'] !== '')
                                                    ? (float)$course['grade_points'] : null;

                                                $displayGrade      = $isPublished
                                                    ? ($storedGrade !== '' ? $storedGrade : (string)$resolvedScale['grade'])
                                                    : '';
                                                $displayGradePoint = $isPublished
                                                    ? ($storedGradePoint !== null ? $storedGradePoint : $resolvedScale['grade_point'])
                                                    : null;

                                                if ($isPublished && $displayGradePoint !== null) {
                                                    $semesterCredits += $cu;
                                                    $semesterPoints  += ((float)$displayGradePoint) * $cu;
                                                    $publishedCourses++;
                                                } else {
                                                    $allPublished = false;
                                                }
                                                ?>
                                                <tr>
                                                    <td class="col-code"><strong><?php echo e($course['course_code'] ?? '-'); ?></strong></td>
                                                    <td class="col-name"><?php echo e($course['course_name'] ?? '-'); ?></td>
                                                    <td class="col-cu text-center"><?php echo e($course['credit_hours'] ?? '-'); ?></td>
                                                    <td class="col-cw text-center"><?php echo $isPublished ? number_format((float)$course['assignment_marks'], 0) : 'PA'; ?></td>
                                                    <td class="col-exam text-center"><?php echo $isPublished ? number_format((float)$course['final_exam_marks'], 0) : 'PA'; ?></td>
                                                    <td class="col-total text-center"><?php echo $isPublished ? number_format((float)$course['total_marks'], 0) : 'PA'; ?></td>
                                                    <td class="col-grade text-center"><?php echo ($isPublished && $displayGrade !== '') ? e($displayGrade) : 'PA'; ?></td>
                                                    <td class="col-gp text-center"><?php echo ($isPublished && $displayGradePoint !== null) ? number_format((float)$displayGradePoint, 2) : 'PA'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <?php
                                            $sgpa     = ($semesterCredits > 0) ? ($semesterPoints / $semesterCredits) : null;
                                            $sgpaText = ($sgpa !== null && $allPublished) ? number_format($sgpa, 2) : 'PA';

                                            if ($semesterCredits > 0 && $allPublished) {
                                                $cumulativeCredits += $semesterCredits;
                                                $cumulativePoints  += $semesterPoints;
                                            }

                                            $cgpa     = ($cumulativeCredits > 0) ? ($cumulativePoints / $cumulativeCredits) : null;
                                            $cgpaText = ($cgpa !== null) ? number_format($cgpa, 2) : 'PA';
                                            ?>

                                            <tr class="summary-row">
                                                <td colspan="2" style="text-align:left;">Published Courses</td>
                                                <td class="text-center"><?php echo $publishedCourses; ?>/<?php echo $totalCourses; ?></td>
                                                <td colspan="3" class="text-center">Semester GPA</td>
                                                <td colspan="2" class="text-center"><?php echo $sgpaText; ?></td>
                                            </tr>
                                            <tr class="cgpa-row">
                                                <td colspan="6" style="text-align:left;">Cumulative GPA (CGPA)</td>
                                                <td colspan="2" class="text-center"><?php echo $cgpaText; ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div><!-- /.table-scroll-wrap -->
                            </div>
                        <?php endforeach; ?>
                    </div><!-- /.year-block -->
                <?php endforeach; ?>

                <div class="grading-key">
                    <strong>Grading Key:</strong>
                    A (80–100, 5.00) &nbsp;|&nbsp;
                    B+ (75–79, 4.00) &nbsp;|&nbsp;
                    B (70–74, 3.50) &nbsp;|&nbsp;
                    C+ (65–69, 3.00) &nbsp;|&nbsp;
                    C (60–64, 2.50) &nbsp;|&nbsp;
                    D (50–59, 2.00) &nbsp;|&nbsp;
                    E (40–49, 1.00) &nbsp;|&nbsp;
                    F (0–39, 0.00)
                </div>

                <?php endif; ?>
            </div><!-- /.p-3 -->
        </div><!-- /.results-card -->
    </div><!-- /.results-wrap -->
</div><!-- /.main-content -->

<script>
/* ── Sidebar toggle ────────────────────────────────────────── */
document.getElementById('menuBtn').addEventListener('click', function () {
    var sidebar = document.getElementById('studentSidebar');
    var main    = document.getElementById('mainContent');
    sidebar.classList.toggle('sidebar-collapsed');
    if (main) main.classList.toggle('full-width');
});

/* ── Profile dropdown ──────────────────────────────────────── */
document.getElementById('profileDropBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    var menu = document.getElementById('profileDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});

/* ── Key / change-password dropdown ───────────────────────── */
document.getElementById('keyDropBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    var menu = document.getElementById('keyDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});

var keyForm = document.getElementById('keyChangePasswordForm');
if (keyForm) {
    keyForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg       = document.getElementById('keyChangePasswordMsg');
        var submitBtn = keyForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        if (msg) { msg.style.display = 'none'; msg.textContent = ''; }

        fetch('change-password.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(keyForm)
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (msg) {
                msg.style.display = 'block';
                if (data && data.success) {
                    msg.style.color = '#166534';
                    msg.textContent = data.message || 'Password updated.';
                    keyForm.reset();
                } else {
                    msg.style.color = '#b91c1c';
                    msg.textContent = (data && (data.error || data.message))
                        ? (data.error || data.message) : 'Unable to update password.';
                }
            }
        })
        .catch(function () {
            if (msg) {
                msg.style.display = 'block';
                msg.style.color = '#b91c1c';
                msg.textContent = 'Unable to update password.';
            }
        })
        .finally(function () { if (submitBtn) submitBtn.disabled = false; });
    });
}

document.addEventListener('click', function () {
    var menu    = document.getElementById('profileDropMenu');
    var keyMenu = document.getElementById('keyDropMenu');
    if (menu)    menu.style.display    = 'none';
    if (keyMenu) keyMenu.style.display = 'none';
});

/* ── Mobile scroll hints ───────────────────────────────────────
   Shows the hint strip only when the table actually overflows,
   then fades it out after the user has scrolled past 20 px.
──────────────────────────────────────────────────────────────── */
(function () {
    function initScrollHints() {
        document.querySelectorAll('.table-scroll-wrap').forEach(function (wrap) {
            var hint = wrap.previousElementSibling;
            if (!hint || !hint.classList.contains('mobile-scroll-hint')) return;

            function evaluate() {
                if (wrap.scrollWidth > wrap.clientWidth + 4) {
                    hint.style.display = 'flex';
                    if (wrap.scrollLeft > 20) {
                        hint.classList.add('hint-hidden');
                    } else {
                        hint.classList.remove('hint-hidden');
                    }
                } else {
                    hint.style.display = 'none';
                    hint.classList.remove('hint-hidden');
                }
            }

            wrap.addEventListener('scroll', function () {
                if (wrap.scrollLeft > 20) {
                    hint.classList.add('hint-hidden');
                } else {
                    hint.classList.remove('hint-hidden');
                }
            }, { passive: true });

            window.addEventListener('resize', evaluate, { passive: true });
            evaluate();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScrollHints);
    } else {
        requestAnimationFrame(function () {
            requestAnimationFrame(initScrollHints);
        });
    }
})();
</script>

<?php include '../../includes/footer.php'; ?>