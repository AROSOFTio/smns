<?php
/**
 * Lecturer - Class List
 * Shows students registered in the lecturer's assigned courses for a selected semester
 */
require_once '../../config.php';

$session = new Session('lecturer');
$auth    = new Auth('lecturer');

// Verify lecturer access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'lecturer') {
    header('Location: ' . BASE_URL . '/views/lecturer/login.php?error=unauthorized');
    exit;
}

$currentUser     = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];

$db   = new Database();
$conn = $db->getConnection();

// Academic years & semester selection (similar to my-courses)
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int) $_GET['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int) $_GET['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);

// Map AY + semester number to semester row
$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

// Fetch lecturer's assigned courses for this semester (for filter dropdown)
$assignedCourses = [];
if ($semesterId) {
    $sql = "SELECT c.id, c.course_code, c.course_name
            FROM course_assignments ca
            INNER JOIN courses c ON ca.course_id = c.id
            WHERE ca.lecturer_id = :lecturer_id
              AND ca.semester_id  = :semester_id
              AND ca.status       = 'active'
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'lecturer_id' => $lecturerProfile['id'],
        'semester_id' => $semesterId,
    ]);
    $assignedCourses = $stmt->fetchAll();
}

$selectedCourseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

// Fetch class list (students) for selected course & semester
$classList = [];
if ($semesterId && $selectedCourseId) {
    $sql = "SELECT s.id, s.first_name, s.last_name, s.student_id AS reg_no,
                   s.level_year, p.program_name, cr.status, cr.registration_date
            FROM course_registrations cr
            INNER JOIN students s ON cr.student_id = s.id
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE cr.semester_id = :semester_id
              AND cr.course_id   = :course_id
              AND cr.status IN ('pending', 'approved')
            ORDER BY s.last_name, s.first_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => $semesterId,
        'course_id'   => $selectedCourseId,
    ]);
    $classList = $stmt->fetchAll();
}

$pageTitle = 'Class List - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Class List</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-3">
                    <label class="mr-2">Academic Year:</label>
                    <select name="academic_year_id" class="form-control mr-2" onchange="this.form.submit();">
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Semester:</label>
                    <select name="semester_number" class="form-control mr-2" onchange="this.form.submit();">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>

                    <label class="ml-3 mr-2">Course:</label>
                    <select name="course_id" class="form-control mr-2" onchange="this.form.submit();">
                        <option value="">-- Select Course --</option>
                        <?php foreach ($assignedCourses as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedCourseId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo e($c['course_code'] . ' - ' . $c['course_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($assignedCourses)): ?>
                    <p class="text-muted mb-0">No courses have been assigned to you for this semester.</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to view its class list.</p>
                <?php else: ?>
                    <h5 class="mb-3">Class List - <?php echo e($semesterName); ?></h5>

                    <?php if (empty($classList)): ?>
                        <p class="text-muted mb-0">No students registered for this course yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Reg #</th>
                                        <th>Program</th>
                                        <th>Year</th>
                                        <th>Status</th>
                                        <th>Registered On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($classList as $s): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                            <td><?php echo e($s['reg_no']); ?></td>
                                            <td><?php echo e($s['program_name'] ?? '-'); ?></td>
                                            <td><?php echo 'Year ' . e($s['level_year'] ?? '-'); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $s['status'] === 'approved' ? 'success' : 'warning'; ?>">
                                                    <?php echo e(ucfirst($s['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo e(Helper::formatDateTime($s['registration_date'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5>Notes</h5>
                <p class="text-muted">This class list is based on student course registrations for your assigned courses in the selected semester, to support result entry and reporting.</p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
