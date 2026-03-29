<?php
/**
 * Admin - Lecturers per Semester & Year
 * Table showing which courses each lecturer teaches per academic year and semester
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();

$db   = new Database();
$conn = $db->getConnection();

// Handle DELETE assignment (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment_id'])) {
    $assignmentId = (int)$_POST['delete_assignment_id'];
    if ($assignmentId > 0) {
        $delStmt = $conn->prepare("DELETE FROM course_assignments WHERE id = :id");
        $delStmt->execute(['id' => $assignmentId]);
        // Redirect back preserving filters
        $qs = http_build_query(array_filter([
            'year'     => $_POST['filter_year'] ?? '',
            'semester' => $_POST['filter_semester'] ?? '',
            'lecturer' => $_POST['filter_lecturer'] ?? '',
            'level'    => $_POST['filter_level'] ?? '',
            'program'  => $_POST['filter_program'] ?? '',
            'status'   => $_POST['filter_status'] ?? '',
        ]));
        header('Location: schedule.php' . ($qs ? '?' . $qs : ''));
        exit;
    }
}

// Filters
$filterYear     = $_GET['year']     ?? '';
$filterSemester = $_GET['semester'] ?? '';
$filterLecturer = $_GET['lecturer'] ?? '';
$filterLevel    = $_GET['level']    ?? '';
$filterProgram  = $_GET['program']  ?? '';
$filterStatus   = $_GET['status']   ?? 'active';
$allowedStatuses = ['all', 'active', 'completed', 'cancelled'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'active';
}

// Smart defaults: only when nothing is specified at all
$noFiltersSet = ($filterSemester === '' && $filterYear === '' && $filterProgram === '' && $filterLevel === '' && $filterLecturer === '' && $filterStatus === 'active');
if ($noFiltersSet) {
    $activeSemRow = $conn->query("
        SELECT s.semester_number, ay.year_name
        FROM semesters s
        JOIN academic_years ay ON ay.id = s.academic_year_id
        WHERE s.status = 'active'
        ORDER BY s.start_date DESC, s.id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if ($activeSemRow) {
        if ($filterSemester === '') {
            $filterSemester = (string)$activeSemRow['semester_number'];
        }
        if ($filterYear === '') {
            $filterYear = $activeSemRow['year_name'];
        }
    }
}

// Program list
$programList = $conn->query("
    SELECT id, program_code, program_name
    FROM programs
    WHERE status = 'active'
    ORDER BY program_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Default program if none selected: pick the program with most active courses
if ($noFiltersSet && $filterProgram === '' && $programList) {
    $pidRow = $conn->query("
        SELECT program_id
        FROM courses
        WHERE status = 'active'
        GROUP BY program_id
        ORDER BY COUNT(*) DESC
        LIMIT 1
    ")->fetchColumn();
    if ($pidRow) {
        $filterProgram = (string)$pidRow;
    }
}

// Resolve semester IDs for the chosen year/semester number (for assignment lookup)
$targetSemesterIds = [];
if ($filterSemester !== '') {
    $semStmt = $conn->prepare("
        SELECT s.id
        FROM semesters s
        JOIN academic_years ay ON ay.id = s.academic_year_id
        WHERE s.semester_number = :sem
        " . ($filterYear ? " AND ay.year_name = :year" : "") . "
    ");
    $semParams = ['sem' => $filterSemester];
    if ($filterYear) {
        $semParams['year'] = $filterYear;
    }
    $semStmt->execute($semParams);
    $targetSemesterIds = $semStmt->fetchAll(PDO::FETCH_COLUMN);
} elseif ($filterYear !== '') {
    $semStmt = $conn->prepare("
        SELECT s.id
        FROM semesters s
        JOIN academic_years ay ON ay.id = s.academic_year_id
        WHERE ay.year_name = :year
    ");
    $semStmt->execute(['year' => $filterYear]);
    $targetSemesterIds = $semStmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $targetSemesterIds = $conn->query("SELECT id FROM semesters")->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch courses for the selected program / level / semester
$courseSql = "
    SELECT c.id, c.course_code, c.course_name, c.credit_hours, c.level_year, c.semester_offered,
           p.program_code, p.program_name
    FROM courses c
    JOIN programs p ON p.id = c.program_id
    WHERE c.status = 'active'
";
$courseParams = [];
if ($filterProgram) {
    $courseSql .= " AND c.program_id = :program";
    $courseParams['program'] = $filterProgram;
}
if ($filterLevel) {
    $courseSql .= " AND c.level_year = :level";
    $courseParams['level'] = $filterLevel;
}
if ($filterSemester) {
    $courseSql .= " AND (c.semester_offered = :sem OR c.semester_offered = 3)";
    $courseParams['sem'] = $filterSemester;
}
$courseSql .= " ORDER BY c.level_year ASC, c.semester_offered ASC, c.course_code ASC";

$courseStmt = $conn->prepare($courseSql);
$courseStmt->execute($courseParams);
$courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Map courses by id
$courseMap = [];
foreach ($courses as $c) {
    $courseMap[$c['id']] = $c + ['lecturers' => []];
}

// Fetch assignments for these courses in the selected semester IDs (if available)
if (!empty($courseMap) && !empty($targetSemesterIds)) {
    $inCourse = implode(',', array_fill(0, count($courseMap), '?'));
    $inSem    = implode(',', array_fill(0, count($targetSemesterIds), '?'));
    $assignSql = "
        SELECT ca.course_id, ca.status, ca.assigned_date,
               l.lecturer_id, l.first_name, l.last_name, l.id AS lecturer_db_id
        FROM course_assignments ca
        JOIN lecturers l ON l.id = ca.lecturer_id
        WHERE ca.course_id IN ($inCourse)
          AND ca.semester_id IN ($inSem)
          AND l.status = 'active'
    ";
    $assignParams = array_merge(array_keys($courseMap), $targetSemesterIds);
    if ($filterStatus !== 'all') {
        $assignSql .= " AND ca.status = ?";
        $assignParams[] = $filterStatus;
    }
    $assignStmt = $conn->prepare($assignSql);
    $assignStmt->execute($assignParams);
    $assignRows = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignRows as $ar) {
        $cid = (int)$ar['course_id'];
        if (!isset($courseMap[$cid])) continue;
        $courseMap[$cid]['lecturers'][] = $ar;
    }
}

// If a lecturer is selected, only keep that lecturer's assignments/courses
if ($filterLecturer !== '') {
    $selectedLecturer = (int)$filterLecturer;
    foreach ($courseMap as $cid => &$course) {
        $course['lecturers'] = array_values(array_filter(
            $course['lecturers'],
            static fn($lt) => (int)$lt['lecturer_db_id'] === $selectedLecturer
        ));
        if (empty($course['lecturers'])) {
            unset($courseMap[$cid]);
        }
    }
    unset($course);
}

// Build unassigned courses groups for the "Courses Without Lecturer" section
$uaGrouped = [];
$uaYearLabel = $filterYear ?: 'All Academic Years';
if ($filterLecturer === '') {
    foreach ($courseMap as $course) {
        if (!empty($course['lecturers'])) {
            continue;
        }
        $level = (int)$course['level_year'] ?: 1;
        if ($filterSemester !== '') {
            $semKeys = [(int)$filterSemester];
        } else {
            $offered = (int)$course['semester_offered'];
            $semKeys = ($offered === 3) ? [1, 2] : [$offered];
        }
        foreach ($semKeys as $semKey) {
            $uaGrouped[$semKey][$level][] = $course;
        }
    }
}
ksort($uaGrouped);
foreach ($uaGrouped as &$levels) {
    ksort($levels);
}
unset($levels);

// Build grouped structure: [level_year] => courses[]
$groupedCourses = [];
foreach ($courseMap as $c) {
    $lvl = (int)$c['level_year'];
    if (!isset($groupedCourses[$lvl])) $groupedCourses[$lvl] = [];
    $groupedCourses[$lvl][] = $c;
}
ksort($groupedCourses);

// Years dropdown based on semesters table (not assignments)
$years = $conn->query("SELECT year_name FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// Lecturers dropdown (active)
$lecturerList = $conn->query("
    SELECT id, lecturer_id, first_name, last_name
    FROM lecturers
    WHERE status = 'active'
    ORDER BY last_name, first_name
")->fetchAll(PDO::FETCH_ASSOC);

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);
$pageTitle = 'Lecturer Schedule - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
/* Keep parent containers from clipping filter controls */
.main-content { overflow-x: visible; }
.content-area  { overflow-x: visible; }
.card.filter-card,
.card.filter-card .card-body,
.filter-form { overflow: visible !important; }

.sched-card { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden; }
.sched-year-header { background: linear-gradient(135deg,#1e40af,#3b82f6); color:#fff; padding:12px 20px; font-size: 1.1rem; font-weight: 700; }
.sched-sem-header { background: #dbeafe; color: #1e3a8a; padding: 8px 20px; font-weight: 600; font-size: 0.95rem; border-bottom: 1px solid #bfdbfe; }

/* Fixed-layout table — NO horizontal scroll */
.sched-table { width: 100%; table-layout: fixed; border-collapse: collapse; }
.sched-table col.col-num    { width: 4%; }
.sched-table col.col-lec    { width: 16%; }
.sched-table col.col-code   { width: 10%; }
.sched-table col.col-name   { width: 32%; }
.sched-table col.col-cu     { width: 8%; }
.sched-table col.col-status { width: 9%; }
.sched-table col.col-action { width: 10%; }

.sched-table thead th { background: #f1f5f9; font-size: 0.78rem; text-transform: uppercase; letter-spacing:0.04em; color:#475569; padding:8px 10px; vertical-align:middle; border-bottom: 2px solid #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sched-table tbody td { padding: 8px 10px; vertical-align: middle; font-size: 0.85rem; border-top: 1px solid #f1f5f9; word-wrap: break-word; overflow-wrap: break-word; }
.sched-table tbody tr:hover { background: #f8fafc; }

.lecturer-cell { font-weight: 600; color: #1e40af; font-size: 0.85rem; word-wrap: break-word; }
.lecturer-id-badge { display:inline-block; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius: 4px; font-size:0.68rem; padding: 1px 5px; font-weight:700; }
.course-badge { display:inline-block; background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; border-radius:4px; font-size:0.7rem; padding: 1px 5px; font-weight:600; word-break: break-all; }
.cu-badge { background:#fef9c3; color:#854d0e; border:1px solid #fde68a; border-radius:4px; font-size:0.7rem; padding:1px 5px; font-weight:600; white-space: nowrap; }
.status-active    { color:#16a34a; font-weight:600; font-size:0.82rem; }
.status-completed { color:#2563eb; font-weight:600; font-size:0.82rem; }
.status-cancelled { color:#dc2626; font-weight:600; font-size:0.82rem; }
.sched-program-header { background:#eff6ff; border-left:4px solid #3b82f6; padding:6px 20px; font-weight:600; color:#1d4ed8; font-size:0.86rem; border-bottom:1px solid #bfdbfe; }
.sched-level-header { background:#f0fdf4; border-left:4px solid #16a34a; padding:6px 20px; font-weight:600; color:#15803d; font-size:0.88rem; border-bottom:1px solid #dcfce7; }
.sched-index-cell { border-right:2px solid #e2e8f0; background:#f8fafc; text-align:center; vertical-align:middle; color:#94a3b8; font-weight:600; }
.sched-lecturer-cell-wrap { border-right:2px solid #e2e8f0; background:#f8fafc; vertical-align:middle; }

/* Unassigned courses section */
.ua-card { border: 2px solid #fca5a5; border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 1px 4px rgba(239,68,68,0.1); overflow: hidden; }
.ua-header { background: linear-gradient(135deg,#b91c1c,#ef4444); color:#fff; padding:12px 20px; font-size:1.1rem; font-weight:700; }
.ua-sem-header { background:#fee2e2; color:#7f1d1d; padding:8px 20px; font-weight:600; font-size:0.95rem; border-bottom:1px solid #fca5a5; }
.ua-year-bar { background:#fff7f7; border-left:4px solid #ef4444; padding:6px 20px; font-weight:600; color:#b91c1c; font-size:0.88rem; border-bottom:1px solid #fee2e2; }
.ua-table { width:100%; table-layout:fixed; border-collapse:collapse; }
.ua-table col.col-num  { width:5%; }
.ua-table col.col-code { width:14%; }
.ua-table col.col-name { width:55%; }
.ua-table col.col-cu   { width:13%; }
.ua-table col.col-sem  { width:13%; }
.ua-table thead th { background:#fef2f2; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:#7f1d1d; padding:8px 10px; border-bottom:2px solid #fca5a5; white-space:nowrap; }
.ua-table tbody td { padding:8px 10px; vertical-align:middle; font-size:0.85rem; border-top:1px solid #fff1f2; word-wrap:break-word; }
.ua-table tbody tr:hover { background:#fff5f5; }
.ua-course-badge { display:inline-block; background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; border-radius:4px; font-size:0.7rem; padding:1px 5px; font-weight:600; }
.ua-row-index { color:#94a3b8; font-weight:600; text-align:center; }
.ua-sem-col { color:#94a3b8; font-size:0.82rem; }

/* Filter form — wraps on small screens */
.filter-card { position: static; }
.filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    overflow-x: visible;
    overflow-y: visible;
    white-space: normal;
}
.filter-form .form-control,
.filter-form button,
.filter-form a {
    flex: 1 1 165px;
    min-width: 150px;
    max-width: 230px;
}
.filter-form button,
.filter-form a {
    flex: 0 0 auto;
    min-width: auto;
    max-width: none;
    white-space: nowrap;
}
@media (max-width: 992px) {
    .filter-form {
        align-items: flex-start;
        overflow: visible;
    }
    .filter-form .form-control,
    .filter-form button,
    .filter-form a {
        width: auto;
        min-width: 0;
        max-width: none;
    }
}

/* Page-specific dark mode overrides */
html[data-theme='dark'] .filter-form .form-control {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
    color: var(--app-text) !important;
}
html[data-theme='dark'] .filter-form .form-control option {
    background: var(--app-surface-1);
    color: var(--app-text);
}
html[data-theme='dark'] .sched-card,
html[data-theme='dark'] .ua-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
    box-shadow: none;
}
html[data-theme='dark'] .sched-sem-header {
    background: #0f2b57 !important;
    color: #dbeafe !important;
    border-bottom-color: #1e3a8a !important;
}
html[data-theme='dark'] .sched-program-header {
    background: #10243f !important;
    color: #bfdbfe !important;
    border-left-color: #3b82f6 !important;
    border-bottom-color: #1e3a8a !important;
}
html[data-theme='dark'] .sched-level-header {
    background: #062e1f !important;
    color: #86efac !important;
    border-left-color: #22c55e !important;
    border-bottom-color: #14532d !important;
}
html[data-theme='dark'] .sched-table thead th {
    background: var(--app-surface-2) !important;
    color: #f8fafc !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .sched-table tbody td {
    border-top-color: var(--app-border) !important;
    color: var(--app-text) !important;
}
html[data-theme='dark'] .sched-table tbody tr:hover { background: #172033 !important; }
html[data-theme='dark'] .sched-index-cell,
html[data-theme='dark'] .sched-lecturer-cell-wrap {
    background: #141d2d !important;
    border-right-color: var(--app-border) !important;
}
html[data-theme='dark'] .sched-index-cell,
html[data-theme='dark'] .ua-row-index,
html[data-theme='dark'] .ua-sem-col { color: #94a3b8 !important; }
html[data-theme='dark'] .lecturer-cell { color: #93c5fd !important; }
html[data-theme='dark'] .lecturer-id-badge {
    background: #1e3a8a !important;
    color: #dbeafe !important;
    border-color: #3b82f6 !important;
}
html[data-theme='dark'] .course-badge {
    background: #14532d !important;
    color: #bbf7d0 !important;
    border-color: #16a34a !important;
}
html[data-theme='dark'] .cu-badge {
    background: #422006 !important;
    color: #fef3c7 !important;
    border-color: #a16207 !important;
}
html[data-theme='dark'] .status-active { color: #4ade80 !important; }
html[data-theme='dark'] .status-completed { color: #60a5fa !important; }
html[data-theme='dark'] .status-cancelled { color: #f87171 !important; }
html[data-theme='dark'] .ua-sem-header {
    background: #3b0a0a !important;
    color: #fecaca !important;
    border-bottom-color: #7f1d1d !important;
}
html[data-theme='dark'] .ua-year-bar {
    background: #2a1113 !important;
    color: #fecaca !important;
    border-left-color: #ef4444 !important;
    border-bottom-color: #7f1d1d !important;
}
html[data-theme='dark'] .ua-table thead th {
    background: #3b0a0a !important;
    color: #fecaca !important;
    border-bottom-color: #7f1d1d !important;
}
html[data-theme='dark'] .ua-table tbody td {
    border-top-color: #4a1b1f !important;
    color: var(--app-text) !important;
}
html[data-theme='dark'] .ua-table tbody tr:hover { background: #2a1113 !important; }
html[data-theme='dark'] .ua-course-badge {
    background: #450a0a !important;
    color: #fecaca !important;
    border-color: #ef4444 !important;
}

@media (max-width: 768px) {
    .sched-table col.col-lec  { width: 22%; }
    .sched-table col.col-name { width: 30%; }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-chalkboard-teacher"></i> Lecturer Schedule</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../../includes/notification_bell.php'; ?>
            <div class="user-dropdown">
                <button class="user-dropdown-toggle" id="userDropdown">
                    <div class="user-avatar-sm">
                        <?php echo strtoupper(substr($currentUser['profile']['first_name'] ?? 'A', 0, 1) . substr($currentUser['profile']['last_name'] ?? 'D', 0, 1)); ?>
                    </div>
                    <i class="dropdown-arrow">▼</i>
                </button>
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <div class="user-profile-meta">
                        <div class="user-fullname"><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></div>
                    </div>
                    <a href="../dashboard.php" class="dropdown-item"><i>🏠</i> Dashboard</a>
                    <a href="../profile.php"   class="dropdown-item"><i>👤</i> Profile</a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">

        <!-- Filters -->
        <div class="card mb-3 filter-card">
            <div class="card-body py-2">
                <form method="GET" class="filter-form">
                    <select name="year" class="form-control form-control-sm">
                        <option value="">All Academic Years</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo e($y); ?>" <?php echo $filterYear === $y ? 'selected' : ''; ?>><?php echo e($y); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="semester" class="form-control form-control-sm">
                        <option value="">All Semesters</option>
                        <option value="1" <?php echo $filterSemester === '1' ? 'selected' : ''; ?>>Semester 1</option>
                        <option value="2" <?php echo $filterSemester === '2' ? 'selected' : ''; ?>>Semester 2</option>
                    </select>
                    <select name="level" class="form-control form-control-sm">
                        <option value="">All Years of Study</option>
                        <option value="1" <?php echo $filterLevel === '1' ? 'selected' : ''; ?>>Year 1</option>
                        <option value="2" <?php echo $filterLevel === '2' ? 'selected' : ''; ?>>Year 2</option>
                        <option value="3" <?php echo $filterLevel === '3' ? 'selected' : ''; ?>>Year 3</option>
                        <option value="4" <?php echo $filterLevel === '4' ? 'selected' : ''; ?>>Year 4</option>
                    </select>
                    <select name="program" class="form-control form-control-sm">
                        <option value="">All Programs</option>
                        <?php foreach ($programList as $program): ?>
                            <option value="<?php echo (int)$program['id']; ?>" <?php echo (string)$filterProgram === (string)$program['id'] ? 'selected' : ''; ?>>
                                <?php echo e($program['program_code'] . ' - ' . $program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="lecturer" class="form-control form-control-sm">
                        <option value="">All Lecturers</option>
                        <?php foreach ($lecturerList as $lec): ?>
                            <option value="<?php echo $lec['id']; ?>" <?php echo $filterLecturer == $lec['id'] ? 'selected' : ''; ?>>
                                <?php echo e($lec['first_name'] . ' ' . $lec['last_name'] . ' (' . $lec['lecturer_id'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-control form-control-sm">
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active Assignments</option>
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                    <a href="schedule.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                </form>
            </div>
        </div>

        <?php
            $totalUniqueCourses = count($courseMap);
            $assignmentCount = 0;
            $lecturerSet = [];
            foreach ($courseMap as $c) {
                foreach ($c['lecturers'] as $lt) {
                    $assignmentCount++;
                    $lecturerSet[$lt['lecturer_db_id']] = true;
                }
            }
            $totalLecturers = count($lecturerSet);
            $displayYear = $filterYear ?: 'All Years';
            $displaySem  = $filterSemester ? 'Semester ' . $filterSemester : 'All Semesters';
        ?>

        <?php if (empty($courseMap)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-chalkboard-teacher fa-3x mb-3"></i>
                    <h5>No courses found</h5>
                    <p>No courses match the selected filters.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="sched-card">
                <div class="sched-year-header">
                    <i class="fas fa-graduation-cap mr-2"></i> Academic Year: <?php echo e($displayYear); ?> &mdash; <?php echo e($displaySem); ?>
                </div>
                <div class="sched-program-header">
                    <i class="fas fa-layer-group mr-1"></i>
                    Program: <?php
                        $pRow = array_values(array_filter($programList, fn($p) => (string)$p['id'] === (string)$filterProgram));
                        echo e($pRow ? ($pRow[0]['program_code'] . ' - ' . $pRow[0]['program_name']) : 'All Programs');
                    ?>
                    &nbsp;|&nbsp; <span style="font-weight:400;"><?php echo $totalLecturers; ?> lecturer(s), <?php echo $assignmentCount; ?> assignment(s), <?php echo $totalUniqueCourses; ?> unique course(s)</span>
                </div>

                <?php foreach ($groupedCourses as $lvl => $courseList): ?>
                    <?php
                        $lvlAssign = 0;
                        $lvlLectSet = [];
                        foreach ($courseList as $c) {
                            foreach ($c['lecturers'] as $lt) {
                                $lvlAssign++;
                                $lvlLectSet[$lt['lecturer_db_id']] = true;
                            }
                        }
                    ?>
                    <div class="sched-level-header">
                        <i class="fas fa-users mr-1"></i> Year <?php echo $lvl; ?>
                        &nbsp;|&nbsp; <span style="font-weight:400;"><?php echo count($lvlLectSet); ?> lecturer(s), <?php echo $lvlAssign; ?> assignment(s), <?php echo count($courseList); ?> course(s)</span>
                    </div>
                    <div class="table-responsive" style="overflow-x:hidden;">
                        <table class="table sched-table mb-0">
                            <colgroup>
                                <col class="col-num">
                                <col class="col-code">
                                <col class="col-name">
                                <col class="col-cu">
                                <col class="col-lec">
                                <col class="col-status">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Credit Units</th>
                                    <th>Lecturer(s)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNum = 1; ?>
                                <?php foreach ($courseList as $c): ?>
                                    <?php
                                        $lectNames = [];
                                        $statusTags = [];
                                        foreach ($c['lecturers'] as $lt) {
                                            $lectNames[] = trim($lt['first_name'] . ' ' . $lt['last_name']) . ' (' . $lt['lecturer_id'] . ')';
                                            $statusTags[] = ucfirst($lt['status'] ?? 'active');
                                        }
                                        $statusText = $statusTags ? implode(', ', array_unique($statusTags)) : 'Unassigned';
                                        $statusClass = $statusTags ? strtolower($statusTags[0]) : 'cancelled';
                                    ?>
                                    <tr>
                                        <td class="sched-index-cell"><?php echo $rowNum++; ?></td>
                                        <td><span class="course-badge"><?php echo e($c['course_code']); ?></span></td>
                                        <td><?php echo e($c['course_name']); ?></td>
                                        <td><span class="cu-badge"><?php echo e($c['credit_hours']); ?> CU</span></td>
                                        <td><?php echo $lectNames ? e(implode(' | ', $lectNames)) : '<span class="text-muted">Unassigned</span>'; ?></td>
                                        <td>
                                            <span class="status-<?php echo $statusClass; ?>">
                                                <?php echo e($statusText); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($uaGrouped) && !$filterLecturer && $filterStatus === 'active'): ?>
            <?php
                $uaTotalAll = 0;
                foreach ($uaGrouped as $semLevels) foreach ($semLevels as $clist) $uaTotalAll += count($clist);
            ?>
            <div class="ua-card">
                <div class="ua-header">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Courses Without Lecturer &mdash; <?php echo e($uaYearLabel); ?>
                    <span style="font-size:0.85rem; font-weight:400; margin-left:10px;">
                        <?php echo $uaTotalAll; ?> course(s) need a lecturer
                    </span>
                </div>

                <?php foreach ($uaGrouped as $semNum => $levelGroups): ?>
                    <?php
                        $semLabel = 'Semester ' . $semNum;
                        $semCount = 0;
                        foreach ($levelGroups as $clist) $semCount += count($clist);
                        ksort($levelGroups);
                    ?>
                    <div class="ua-sem-header">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        <?php echo e($uaYearLabel); ?> &mdash; <?php echo $semLabel; ?>
                        &nbsp;|&nbsp; <span style="font-weight:400;"><?php echo $semCount; ?> unassigned course(s)</span>
                    </div>

                    <?php foreach ($levelGroups as $lvl => $courses): ?>
                        <div class="ua-year-bar">
                            <i class="fas fa-users mr-1"></i> Year <?php echo $lvl; ?>
                            &nbsp;&mdash;&nbsp; <span style="font-weight:400;"><?php echo count($courses); ?> course(s)</span>
                        </div>
                        <div style="overflow-x:hidden;">
                            <table class="ua-table">
                                <colgroup>
                                    <col class="col-num">
                                    <col class="col-code">
                                    <col class="col-name">
                                    <col class="col-cu">
                                    <col class="col-sem">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Credit Units</th>
                                        <th>Semester</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courses as $idx => $uc): ?>
                                        <tr>
                                            <td class="ua-row-index"><?php echo $idx + 1; ?></td>
                                            <td><span class="ua-course-badge"><?php echo e($uc['course_code']); ?></span></td>
                                            <td><?php echo e($uc['course_name']); ?></td>
                                            <td><span class="cu-badge"><?php echo e($uc['credit_hours']); ?> CU</span></td>
                                            <td class="ua-sem-col">
                                                <?php echo $uc['semester_offered'] == 3 ? 'Both' : 'Sem ' . $uc['semester_offered']; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div><!-- /content-area -->
</div><!-- /main-content -->

<script>
// Auto-submit on dropdown change
document.querySelectorAll('select[name="year"], select[name="semester"], select[name="level"], select[name="program"], select[name="lecturer"], select[name="status"]')
    .forEach(el => el.addEventListener('change', () => el.closest('form').submit()));

// Confirm before deleting a course assignment
document.querySelectorAll('.delete-assignment-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const row = this.closest('tr');
        const courseName = row.querySelector('td:nth-child(2), td:nth-child(4)');
        if (!confirm('Are you sure you want to remove this course assignment? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>
