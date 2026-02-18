<?php
/**
 * Student - My Courses (shows courses assigned by admin for selected semester)
 */
require_once '../../config.php';



$session = new Session('student');
$auth = new Auth('student');

// Verify student access
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

$db = new Database();
$conn = $db->getConnection();

// Academic years + default selection
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int)$_GET['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);

$mapStmt = $conn->prepare("SELECT id FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1");
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$r = $mapStmt->fetch();
$semesterId = $r['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);

// Fetch assigned courses for this semester (admin-registered courses)
$assigned = [];
if ($semesterId) {
    $sql = "SELECT ca.course_id, c.course_code, c.course_name, c.credit_hours, c.level_year
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.id
            WHERE ca.semester_id = :semester_id
              AND (c.program_id = :program_id OR c.program_id IS NULL)
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['semester_id' => $semesterId, 'program_id' => $studentProfile['program_id'] ?? 0]);
    $assigned = $stmt->fetchAll();
}

// Fetch student's registrations for this semester to show status
$registered = [];
if ($semesterId) {
    $rstmt = $conn->prepare("SELECT course_id, status FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    while ($rr = $rstmt->fetch()) {
        $registered[$rr['course_id']] = $rr['status'];
    }
}

// Fetch all students registered for this semester (enrollment roster)
$registeredStudents = [];
$selectedRegisteredStudentId = isset($_GET['registered_student_id']) ? (int)$_GET['registered_student_id'] : 0;
if ($semesterId) {
    $rsSql = "SELECT s.id, s.first_name, s.last_name, s.student_id AS reg_no, s.level_year, p.program_name,
                     MAX(CASE WHEN cr.status = 'approved' THEN 1 ELSE 0 END) AS has_approved,
                     MAX(CASE WHEN cr.status = 'pending' THEN 1 ELSE 0 END) AS has_pending,
                     MAX(cr.registration_date) AS registration_date
              FROM course_registrations cr
              JOIN students s ON cr.student_id = s.id
              LEFT JOIN programs p ON s.program_id = p.id
              WHERE cr.semester_id = :semester_id
              GROUP BY s.id
              ORDER BY s.last_name, s.first_name";
    $rsStmt = $conn->prepare($rsSql);
    $rsStmt->execute(['semester_id' => $semesterId]);
    $registeredStudents = $rsStmt->fetchAll();
}

// Fetch unread notifications for header bell
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'My Courses - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>My Courses</h4>
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

                    <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php?academic_year_id=<?php echo $selectedAcademicYearId; ?>&semester_number=<?php echo $selectedSemesterNumber; ?>" class="btn btn-primary ml-2">Register for semester</a>

                    <!-- Registered students dropdown (roster) -->
                    <?php if (!empty($registeredStudents) && $semesterId): ?>
                        <label class="ml-3 mr-2">Registered students:</label>
                        <select name="registered_student_id" class="form-control mr-2" onchange="this.form.submit();">
                            <option value="">All students (<?php echo count($registeredStudents); ?>)</option>
                            <?php foreach ($registeredStudents as $rs): ?>
                                <option value="<?php echo $rs['id']; ?>" <?php echo $selectedRegisteredStudentId == $rs['id'] ? 'selected' : ''; ?>><?php echo e($rs['first_name'] . ' ' . $rs['last_name'] . ' (' . $rs['reg_no'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </form>

                <?php if (!$semesterId): ?>
                    <p class="text-muted">No semester configured for the selected academic year / semester number.</p>
                <?php else: ?>
                    <?php if (empty($assigned)): ?>
                        <!-- intentionally blank when no courses are assigned; students should use the registration button only -->
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Credits</th>
                                        <th>Level</th>
                                        <th>Registration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assigned as $c): ?>
                                        <tr>
                                            <td><?php echo e($c['course_code']); ?></td>
                                            <td><?php echo e($c['course_name']); ?></td>
                                            <td><?php echo e($c['credit_hours']); ?></td>
                                            <td>Year <?php echo e($c['level_year']); ?></td>
                                            <td>
                                                <?php if (isset($registered[$c['course_id']])): ?>
                                                    <span class="badge badge-info"><?php echo e(ucfirst($registered[$c['course_id']])); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not registered</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Enrollment roster for this semester -->
                        <div class="card mt-4">
                            <div class="card-body">
                                <h5>Enrolled students for this semester</h5>
                                <?php if (empty($registeredStudents)): ?>
                                    <p class="text-muted">No students have registered for this semester yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Reg #</th>
                                                    <th>Program</th>
                                                    <th>Year</th>
                                                    <th>Status</th>
                                                    <th>Registered on</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($registeredStudents as $rs):
                                                    if ($selectedRegisteredStudentId && $selectedRegisteredStudentId != $rs['id']) continue;
                                                    $status = ($rs['has_approved'] ? ($rs['has_pending'] ? 'Partial' : 'Approved') : ($rs['has_pending'] ? 'Pending' : '-'));
                                                ?>
                                                    <tr>
                                                        <td><?php echo e($rs['first_name'] . ' ' . $rs['last_name']); ?></td>
                                                        <td><?php echo e($rs['reg_no']); ?></td>
                                                        <td><?php echo e($rs['program_name'] ?? '-'); ?></td>
                                                        <td><?php echo 'Year ' . e($rs['level_year'] ?? '-'); ?></td>
                                                        <td><?php echo e($status); ?></td>
                                                        <td><?php echo e(Helper::formatDateTime($rs['registration_date'] ?? '')); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

                <div class="card">
            <div class="card-body">
                <h5>Notes</h5>
                <p class="text-muted">Courses shown here are those assigned by administration for the selected semester.</p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php';
