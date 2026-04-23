<?php
/**
 * Lecturer Teaching Reports
 */
require_once '../../config.php';

$session = new Session('lecturer');
$auth = new Auth('lecturer');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'lecturer') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=lecturer');
    exit;
}

$currentUser = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'] ?? [];
if (empty($lecturerProfile['id'])) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=lecturer');
    exit;
}
$lecturerResultOwnerIds = array_values(array_unique(array_filter([
    (int)($lecturerProfile['id'] ?? 0),
    (int)($lecturerProfile['user_id'] ?? 0),
    (int)($currentUser['id'] ?? 0),
])));
if (empty($lecturerResultOwnerIds)) {
    $lecturerResultOwnerIds = [(int)($lecturerProfile['id'] ?? 0)];
}
$resultOwnerPlaceholders = implode(',', array_fill(0, count($lecturerResultOwnerIds), '?'));

$db = new Database();
$conn = $db->getConnection();

$window = getAcademicCalendarDisplayWindowBounds();
$academicYearsStmt = $conn->prepare("SELECT id, year_name, start_date FROM academic_years WHERE start_date >= :start_date AND start_date <= :end_date ORDER BY start_date DESC");
$academicYearsStmt->execute($window);
$academicYears = $academicYearsStmt->fetchAll();
$defaultAcademicYearId = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : (int)$defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int)$_GET['semester_number'] : (int)(Helper::getCurrentSemester()['semester_number'] ?? 1);

$semesterStmt = $conn->prepare("SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1");
$semesterStmt->execute([
    'ay' => $selectedAcademicYearId,
    'sn' => $selectedSemesterNumber
]);
$semesterRow = $semesterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$semesterId = (int)($semesterRow['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? ('Semester ' . $selectedSemesterNumber);

$selectedAcademicYearLabel = '';
foreach ($academicYears as $yearRow) {
    if ((int)$yearRow['id'] === $selectedAcademicYearId) {
        $selectedAcademicYearLabel = (string)$yearRow['year_name'];
        break;
    }
}
if ($selectedAcademicYearLabel === '') {
    $selectedAcademicYearLabel = 'N/A';
}

$assignedCourses = [];
if ($semesterId > 0) {
    $coursesStmt = $conn->prepare("
        SELECT c.id, c.course_code, c.course_name, c.level_year, c.credit_hours
        FROM course_assignments ca
        INNER JOIN courses c ON ca.course_id = c.id
        WHERE ca.lecturer_id = :lecturer_id
          AND ca.semester_id = :semester_id
          AND ca.status = 'active'
        ORDER BY c.course_code
    ");
    $coursesStmt->execute([
        'lecturer_id' => (int)$lecturerProfile['id'],
        'semester_id' => $semesterId
    ]);
    $assignedCourses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$courseIds = array_map('intval', array_column($assignedCourses, 'id'));
$studentsByCourse = [];
$resultByCourse = [];

if ($semesterId > 0 && !empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

    $studentsSql = "
        SELECT cr.course_id, COUNT(DISTINCT cr.student_id) AS total_students
        FROM course_registrations cr
        WHERE cr.semester_id = ?
          AND cr.status = 'approved'
          AND cr.course_id IN ($placeholders)
        GROUP BY cr.course_id
    ";
    $studentsStmt = $conn->prepare($studentsSql);
    $studentsStmt->execute(array_merge([$semesterId], $courseIds));
    foreach ($studentsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentsByCourse[(int)$row['course_id']] = (int)$row['total_students'];
    }

    $resultsSql = "
        SELECT r.course_id, r.status, COUNT(*) AS total_rows
        FROM results r
        WHERE r.semester_id = ?
          AND r.entered_by IN ($resultOwnerPlaceholders)
          AND r.course_id IN ($placeholders)
        GROUP BY r.course_id, r.status
    ";
    $resultsStmt = $conn->prepare($resultsSql);
    $resultsStmt->execute(array_merge([$semesterId], $lecturerResultOwnerIds, $courseIds));
    foreach ($resultsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $courseId = (int)$row['course_id'];
        $status = (string)$row['status'];
        if (!isset($resultByCourse[$courseId])) {
            $resultByCourse[$courseId] = [];
        }
        $resultByCourse[$courseId][$status] = (int)$row['total_rows'];
    }
}

$summary = [
    'courses' => count($assignedCourses),
    'students' => 0,
    'draft' => 0,
    'submitted' => 0,
    'approved' => 0,
    'published' => 0,
    'other' => 0
];

foreach ($assignedCourses as $course) {
    $courseId = (int)$course['id'];
    $summary['students'] += (int)($studentsByCourse[$courseId] ?? 0);
    $statusRows = $resultByCourse[$courseId] ?? [];
    foreach ($statusRows as $status => $count) {
        if (isset($summary[$status])) {
            $summary[$status] += (int)$count;
        } else {
            $summary['other'] += (int)$count;
        }
    }
}

$pageTitle = 'Teaching Reports - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Teaching Reports</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container-fluid p-3">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter mr-2"></i>Report Filters</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row align-items-end">
                    <div class="col-md-4 mb-2">
                        <label class="mb-1">Academic Year</label>
                        <select name="academic_year_id" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo (int)$ay['id']; ?>" <?php echo $selectedAcademicYearId === (int)$ay['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($ay['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="mb-1">Semester</label>
                        <select name="semester_number" class="form-control" onchange="this.form.submit()">
                            <?php for ($sn = 1; $sn <= 4; $sn++): ?>
                                <option value="<?php echo $sn; ?>" <?php echo $selectedSemesterNumber === $sn ? 'selected' : ''; ?>>
                                    Semester <?php echo $sn; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-5 mb-2 text-md-right">
                        <a href="reports.php" class="btn btn-outline-secondary">
                            <i class="fas fa-sync-alt mr-1"></i>Reset
                        </a>
                        <a href="enter-results.php?academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>" class="btn btn-primary">
                            <i class="fas fa-edit mr-1"></i>Enter Results
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($semesterId <= 0): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                No semester record exists for <?php echo e($selectedAcademicYearLabel); ?>, Semester <?php echo (int)$selectedSemesterNumber; ?>.
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-md-3 mb-2">
                <div class="card h-100">
                    <div class="card-body p-3">
                        <small class="text-muted d-block">Assigned Courses</small>
                        <h4 class="mb-0"><?php echo number_format($summary['courses']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="card h-100">
                    <div class="card-body p-3">
                        <small class="text-muted d-block">Registered Students</small>
                        <h4 class="mb-0"><?php echo number_format($summary['students']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="card h-100">
                    <div class="card-body p-3">
                        <small class="text-muted d-block">Draft Result Rows</small>
                        <h4 class="mb-0"><?php echo number_format($summary['draft']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="card h-100">
                    <div class="card-body p-3">
                        <small class="text-muted d-block">Submitted Result Rows</small>
                        <h4 class="mb-0"><?php echo number_format($summary['submitted']); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar mr-2"></i>
                    Course-Level Reporting (<?php echo e($selectedAcademicYearLabel); ?> - <?php echo e($semesterName); ?>)
                </h5>
                <div>
                    <a class="btn btn-sm btn-outline-info" href="class-list.php?academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>">
                        <i class="fas fa-users mr-1"></i>Class List
                    </a>
                    <a class="btn btn-sm btn-outline-secondary" href="draft-results.php?academic_year=<?php echo (int)$selectedAcademicYearId; ?>&semester=<?php echo (int)$semesterId; ?>">
                        <i class="fas fa-save mr-1"></i>Draft Results
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($assignedCourses)): ?>
                    <div class="p-3 text-muted">No active course assignments found for the selected semester.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th class="text-center">Year</th>
                                    <th class="text-center">Students</th>
                                    <th class="text-center">Draft</th>
                                    <th class="text-center">Submitted</th>
                                    <th class="text-center">Approved</th>
                                    <th class="text-center">Published</th>
                                    <th class="text-center">Completion</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignedCourses as $course): ?>
                                    <?php
                                        $cid = (int)$course['id'];
                                        $students = (int)($studentsByCourse[$cid] ?? 0);
                                        $statusRows = $resultByCourse[$cid] ?? [];
                                        $draft = (int)($statusRows['draft'] ?? 0);
                                        $submitted = (int)($statusRows['submitted'] ?? 0);
                                        $approved = (int)($statusRows['approved'] ?? 0);
                                        $published = (int)($statusRows['published'] ?? 0);
                                        $totalResults = 0;
                                        foreach ($statusRows as $count) {
                                            $totalResults += (int)$count;
                                        }
                                        $completion = ($students > 0) ? min(100, round(($totalResults / $students) * 100, 1)) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($course['course_code']); ?></strong><br>
                                            <small class="text-muted"><?php echo e($course['course_name']); ?></small>
                                        </td>
                                        <td class="text-center">Year <?php echo (int)($course['level_year'] ?? 0); ?></td>
                                        <td class="text-center"><?php echo $students; ?></td>
                                        <td class="text-center"><span class="badge badge-warning"><?php echo $draft; ?></span></td>
                                        <td class="text-center"><span class="badge badge-info"><?php echo $submitted; ?></span></td>
                                        <td class="text-center"><span class="badge badge-primary"><?php echo $approved; ?></span></td>
                                        <td class="text-center"><span class="badge badge-success"><?php echo $published; ?></span></td>
                                        <td class="text-center">
                                            <span class="badge badge-light"><?php echo number_format($completion, 1); ?>%</span>
                                        </td>
                                        <td class="text-center">
                                            <a href="enter-results.php?academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>&course_id=<?php echo $cid; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
