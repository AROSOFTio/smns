<?php
/**
 * Admin - Lecturers per Semester & Year
 * Table showing which courses each lecturer teaches per academic year and semester
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
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

// Build main query
$sql = "
    SELECT
        ay.year_name,
        s.semester_name,
        s.semester_number,
        c.level_year,
        l.id            AS lecturer_db_id,
        l.lecturer_id   AS lecturer_code,
        CONCAT(l.first_name, ' ', l.last_name) AS lecturer_name,
        c.course_code,
        c.course_name,
        c.credit_hours,
        ca.id           AS assignment_id,
        ca.status       AS assignment_status
    FROM course_assignments ca
    INNER JOIN lecturers      l  ON ca.lecturer_id  = l.id
    INNER JOIN courses        c  ON ca.course_id    = c.id
    INNER JOIN semesters      s  ON ca.semester_id  = s.id
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE 1=1
";
$params = [];

if ($filterYear) {
    $sql .= " AND ay.year_name = :year";
    $params['year'] = $filterYear;
}
if ($filterSemester) {
    $sql .= " AND s.semester_number = :semester";
    $params['semester'] = $filterSemester;
}
if ($filterLecturer) {
    $sql .= " AND l.id = :lecturer";
    $params['lecturer'] = $filterLecturer;
}
if ($filterLevel) {
    $sql .= " AND c.level_year = :level";
    $params['level'] = $filterLevel;
}

$sql .= " ORDER BY ay.year_name DESC, s.semester_number ASC, c.level_year ASC, l.last_name ASC, c.course_code ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group: [academic_year][semester_name][level_year] => [lecturer_code => [name, courses[]]]
$grouped = [];
foreach ($rows as $row) {
    $y   = $row['year_name'];
    $s   = $row['semester_name'];
    $lvl = intval($row['level_year']) ?: 1;
    $lc  = $row['lecturer_code'];
    if (!isset($grouped[$y][$s][$lvl][$lc])) {
        $grouped[$y][$s][$lvl][$lc] = [
            'name'    => $row['lecturer_name'],
            'courses' => [],
        ];
    }
    $grouped[$y][$s][$lvl][$lc]['courses'][] = $row;
}

// Filter dropdowns
$years = $conn->query("
    SELECT DISTINCT ay.year_name FROM academic_years ay
    INNER JOIN semesters s ON s.academic_year_id = ay.id
    INNER JOIN course_assignments ca ON ca.semester_id = s.id
    ORDER BY ay.year_name DESC
")->fetchAll(PDO::FETCH_COLUMN);

// Fetch courses NOT assigned to any lecturer, filtered the same way
$uaSql = "
    SELECT
        c.id, c.course_code, c.course_name, c.credit_hours,
        c.level_year, c.semester_offered
    FROM courses c
    WHERE c.id NOT IN (SELECT DISTINCT course_id FROM course_assignments)
";
$uaParams = [];
if ($filterLevel) {
    $uaSql .= " AND c.level_year = :level";
    $uaParams['level'] = $filterLevel;
}
if ($filterSemester) {
    $uaSql .= " AND (c.semester_offered = :sem OR c.semester_offered = 3)";
    $uaParams['sem'] = $filterSemester;
}
// If lecturer filter active, unassigned courses are irrelevant — skip
if (!$filterLecturer) {
    $uaSql .= " ORDER BY c.level_year ASC, c.semester_offered ASC, c.course_code ASC";
    $uaStmt = $conn->prepare($uaSql);
    $uaStmt->execute($uaParams);
    $uaRows = $uaStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $uaRows = [];
}

// Group unassigned: [semester_number][level_year] => [courses[]]
// If a specific semester is already filtered, only put them under that semester key
$uaGrouped = [];
foreach ($uaRows as $r) {
    $sem = intval($r['semester_offered']);
    $lvl = intval($r['level_year']) ?: 1;
    if ($filterSemester) {
        // User already scoped to one semester — group everything under it
        $uaGrouped[intval($filterSemester)][$lvl][] = $r;
    } elseif ($sem === 3) {
        $uaGrouped[1][$lvl][] = $r;
        $uaGrouped[2][$lvl][] = $r;
    } else {
        $uaGrouped[$sem][$lvl][] = $r;
    }
}
ksort($uaGrouped);
foreach ($uaGrouped as &$sg) ksort($sg);
unset($sg);

// Label for year context in unassigned header
$uaYearLabel = $filterYear ?: 'All Academic Years';

$lecturerList = $conn->query("
    SELECT DISTINCT l.id, l.lecturer_id, l.first_name, l.last_name
    FROM lecturers l
    INNER JOIN course_assignments ca ON ca.lecturer_id = l.id
    ORDER BY l.last_name, l.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);
$pageTitle = 'Lecturer Schedule - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
/* Prevent ANY horizontal scroll on the page */
.main-content { overflow-x: hidden; }
.content-area  { overflow-x: hidden; }

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

/* Filter form — wraps on small screens */
.filter-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.filter-form select, .filter-form button, .filter-form a { flex-shrink: 0; }

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
        <div class="card mb-3">
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
                    <select name="lecturer" class="form-control form-control-sm">
                        <option value="">All Lecturers</option>
                        <?php foreach ($lecturerList as $lec): ?>
                            <option value="<?php echo $lec['id']; ?>" <?php echo $filterLecturer == $lec['id'] ? 'selected' : ''; ?>>
                                <?php echo e($lec['first_name'] . ' ' . $lec['last_name'] . ' (' . $lec['lecturer_id'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                    <a href="schedule.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                </form>
            </div>
        </div>

        <?php if (empty($grouped)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-chalkboard-teacher fa-3x mb-3"></i>
                    <h5>No lecturer assignments found</h5>
                    <p>No course assignments match the selected filters.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $year => $semesters): ?>
                <div class="sched-card">
                    <div class="sched-year-header">
                        <i class="fas fa-graduation-cap mr-2"></i> Academic Year: <?php echo e($year); ?>
                    </div>

                    <?php foreach ($semesters as $semName => $yearGroups): ?>
                        <?php
                            // Count totals for this semester across all year groups
                            $semTotalLecturers = 0; $semTotalCourses = 0;
                            foreach ($yearGroups as $lecturers) {
                                $semTotalLecturers += count($lecturers);
                                foreach ($lecturers as $ldata) $semTotalCourses += count($ldata['courses']);
                            }
                        ?>
                        <div class="sched-sem-header">
                            <i class="fas fa-calendar-alt mr-1"></i> <?php echo e($semName); ?>
                            &nbsp;|&nbsp; <span style="font-weight:400;"><?php echo $semTotalLecturers; ?> lecturer(s) &mdash; <?php echo $semTotalCourses; ?> course assignment(s)</span>
                        </div>

                        <?php foreach ($yearGroups as $lvl => $lecturers): ?>
                            <?php
                                $totalCourses   = 0;
                                $totalLecturers = count($lecturers);
                                foreach ($lecturers as $ldata) $totalCourses += count($ldata['courses']);
                            ?>
                            <!-- Year of Study sub-header -->
                            <div style="background:#f0fdf4; border-left:4px solid #16a34a; padding:6px 20px; font-weight:600; color:#15803d; font-size:0.88rem; border-bottom:1px solid #dcfce7;">
                                <i class="fas fa-users mr-1"></i> Year <?php echo $lvl; ?> &nbsp;&mdash;&nbsp;
                                <span style="font-weight:400;"><?php echo $totalLecturers; ?> lecturer(s), <?php echo $totalCourses; ?> course(s)</span>
                            </div>
                        <div class="table-responsive" style="overflow-x:hidden;">
                                <table class="table sched-table mb-0">
                                    <colgroup>
                                        <col class="col-num">
                                        <col class="col-lec">
                                        <col class="col-code">
                                        <col class="col-name">
                                        <col class="col-cu">
                                        <col class="col-status">
                                        <col class="col-action">
                                    </colgroup>
                                        <tr>
                                            <th>#</th>
                                            <th>Lecturer</th>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Credit Units</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $rowNum = 1; ?>
                                        <?php foreach ($lecturers as $lecCode => $ldata): ?>
                                            <?php $courseCount = count($ldata['courses']); ?>
                                            <?php foreach ($ldata['courses'] as $i => $c): ?>
                                                <tr>
                                                    <?php if ($i === 0): ?>
                                                        <td rowspan="<?php echo $courseCount; ?>" style="border-right:2px solid #e2e8f0; background:#f8fafc; text-align:center; vertical-align:middle; color:#94a3b8; font-weight:600;">
                                                            <?php echo $rowNum++; ?>
                                                        </td>
                                                        <td rowspan="<?php echo $courseCount; ?>" style="border-right:2px solid #e2e8f0; background:#f8fafc; vertical-align:middle;">
                                                            <div class="lecturer-cell"><?php echo e($ldata['name']); ?></div>
                                                            <span class="lecturer-id-badge"><?php echo e($lecCode); ?></span>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td><span class="course-badge"><?php echo e($c['course_code']); ?></span></td>
                                                    <td><?php echo e($c['course_name']); ?></td>
                                                    <td><span class="cu-badge"><?php echo e($c['credit_hours']); ?> CU</span></td>
                                                    <td>
                                                        <span class="status-<?php echo $c['assignment_status']; ?>">
                                                            <?php echo ucfirst($c['assignment_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <form method="POST" class="d-inline delete-assignment-form">
                                                            <input type="hidden" name="delete_assignment_id" value="<?php echo $c['assignment_id']; ?>">
                                                            <input type="hidden" name="filter_year" value="<?php echo e($filterYear); ?>">
                                                            <input type="hidden" name="filter_semester" value="<?php echo e($filterSemester); ?>">
                                                            <input type="hidden" name="filter_lecturer" value="<?php echo e($filterLecturer); ?>">
                                                            <input type="hidden" name="filter_level" value="<?php echo e($filterLevel); ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm" title="Remove this course assignment">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>

                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($uaGrouped) && !$filterLecturer): ?>
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
                                            <td style="color:#94a3b8; font-weight:600; text-align:center;"><?php echo $idx + 1; ?></td>
                                            <td><span class="ua-course-badge"><?php echo e($uc['course_code']); ?></span></td>
                                            <td><?php echo e($uc['course_name']); ?></td>
                                            <td><span class="cu-badge"><?php echo e($uc['credit_hours']); ?> CU</span></td>
                                            <td style="color:#94a3b8; font-size:0.82rem;">
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
document.querySelectorAll('select[name="year"], select[name="semester"], select[name="level"], select[name="lecturer"]')
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
