<?php
/**
 * Lecturer - My Courses (shows courses assigned to this lecturer)
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

// Academic years + default selection (mirror student my-courses behaviour)
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int) $_GET['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int) $_GET['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);

// Map academic year + semester number to semester record
$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow = $mapStmt->fetch();
$semesterId  = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

// Fetch lecturer's assigned courses for this semester
$myCourses = [];
if ($semesterId) {
    $sql = "SELECT c.*, ca.id AS assignment_id, s.semester_name
            FROM course_assignments ca
            INNER JOIN courses c ON ca.course_id = c.id
            INNER JOIN semesters s ON ca.semester_id = s.id
            WHERE ca.lecturer_id = :lecturer_id
              AND ca.semester_id = :semester_id
              AND ca.status = 'active'
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'lecturer_id' => $lecturerProfile['id'],
        'semester_id' => $semesterId,
    ]);
    $myCourses = $stmt->fetchAll();
}

$pageTitle = 'My Courses - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>My Courses</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar">
                            <?php if (!empty($lecturerProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $lecturerProfile['photo']; ?>" alt="Profile Photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($lecturerProfile['first_name'], 0, 1) . substr($lecturerProfile['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong><?php echo e($lecturerProfile['first_name']); ?> <?php echo e($lecturerProfile['last_name']); ?></strong>
                            <br><small><?php echo e($lecturerProfile['lecturer_id']); ?></small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item">
                            <i>👤</i> My Profile
                        </a>
                        <a href="my-courses.php" class="dropdown-item">
                            <i>📚</i> My Courses
                        </a>
                        <a href="reports.php" class="dropdown-item">
                            <i>📁</i> Reports
                        </a>
                        <a href="change-password.php" class="dropdown-item">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">
        <div class="welcome-section mb-3">
            <h5>My Assigned Courses</h5>
            <p class="text-muted mb-0" style="font-size:13px;">
                <strong>ID:</strong> <?php echo e($lecturerProfile['lecturer_id']); ?> &nbsp;|&nbsp;
                <strong>Dept:</strong> <?php echo e($lecturerProfile['department'] ?? 'N/A'); ?> &nbsp;|&nbsp;
                <?php echo e($lecturerProfile['specialization'] ?? ''); ?>
            </p>
        </div>

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
                </form>

                <h6 class="mb-3">Courses for <?php echo e($semesterName); ?></h6>

                <?php if (!$semesterId): ?>
                    <p class="text-muted">No semester configured for the selected academic year / semester number.</p>
                <?php else: ?>
                    <?php if (empty($myCourses)): ?>
                        <p class="text-muted mb-0">No courses have been assigned to you for this semester.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" style="font-size:13px;">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Credits</th>
                                        <th>Level</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($myCourses as $course): ?>
                                        <tr>
                                            <td><?php echo e($course['course_code']); ?></td>
                                            <td><?php echo e($course['course_name']); ?></td>
                                            <td><?php echo $course['credit_hours']; ?></td>
                                            <td>Year <?php echo $course['level_year']; ?></td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-success py-0 px-2">Enter Results</a>
                                                <a href="<?php echo BASE_URL; ?>/views/lecturer/view-results.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info py-0 px-2">View Results</a>
                                            </td>
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
                <p class="text-muted">Courses listed here are those assigned to you by the administration (via course assignments) for the selected academic year and semester, similar to how courses are managed on the admin side.</p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
