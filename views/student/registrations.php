<?php
/**
 * Student - View Registrations (by semester)
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

$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

if ($semesterId) {
    // Detailed view for selected semester
    $stmt = $conn->prepare("SELECT cr.*, c.course_code, c.course_name, c.credit_hours, cr.status
                           FROM course_registrations cr
                           INNER JOIN courses c ON cr.course_id = c.id
                           WHERE cr.student_id = :student_id AND cr.semester_id = :semester_id
                           ORDER BY c.course_code");
    $stmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    $registrations = $stmt->fetchAll();

    // Fetch semester info
    $sstmt = $conn->prepare("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id");
    $sstmt->execute(['id' => $semesterId]);
    $semester = $sstmt->fetch();

    // Fetch unread notifications for header bell
    $unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

    $pageTitle = 'My Registrations - ' . APP_NAME;
    include '../../includes/header.php';
    ?>

    <?php include '../../includes/student/sidebar.php'; ?>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
                <h4>My Registrations</h4>
            </div>
            <div class="topbar-right">
                <?php include '../../includes/notification_bell.php'; ?>
            </div>
        </div>

        <div class="content-area container p-4">
            <div class="card mb-3">
                <div class="card-body">
                    <h5>Registrations for <?php echo e($semester['year_name'] . ' - ' . $semester['semester_name']); ?></h5>
                    <?php if (empty($registrations)): ?>
                        <p class="text-muted">You have not registered any courses for this semester.</p>
                        <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php?semester_id=<?php echo $semesterId; ?>" class="btn btn-primary">Register Courses</a>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Credits</th>
                                        <th>Status</th>
                                        <th>Registered On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['course_code']); ?></td>
                                            <td><?php echo e($r['course_name']); ?></td>
                                            <td><?php echo e($r['credit_hours']); ?></td>
                                            <td><span class="badge badge-<?php echo $r['status'] === 'approved' ? 'success' : ($r['status'] === 'pending' ? 'info' : 'secondary'); ?>"><?php echo e(ucfirst($r['status'])); ?></span></td>
                                            <td><?php echo e(Helper::formatDate($r['registration_date'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php?semester_id=<?php echo $semesterId; ?>" class="btn btn-secondary">Back to Registration</a>
        </div>
    </div>

    <?php include '../../includes/footer.php';
    exit;
}

// Summary view grouped by semester
$sql = "SELECT cr.semester_id, sem.semester_name, ay.year_name, COUNT(*) as course_count,
           SUM(CASE WHEN cr.status = 'approved' THEN 1 ELSE 0 END) as approved_count
        FROM course_registrations cr
        JOIN semesters sem ON cr.semester_id = sem.id
        JOIN academic_years ay ON sem.academic_year_id = ay.id
        WHERE cr.student_id = :student_id
        GROUP BY cr.semester_id
        ORDER BY ay.year_name DESC, sem.semester_number DESC";
$stmt = $conn->prepare($sql);
$stmt->execute(['student_id' => $studentProfile['id']]);
$summary = $stmt->fetchAll();

// Fetch unread notifications for header bell
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'My Registrations - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>My Registrations</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card">
            <div class="card-body">
                <?php if (empty($summary)): ?>
                    <p class="text-muted">You have no registrations yet.</p>
                    <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="btn btn-primary">Register Courses</a>
                <?php else: ?>
                    <div class="mb-3">
                        <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="btn btn-success">Register Again</a>
                        <small class="text-muted">You can register for new courses or update your registration for another semester.</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Semester</th>
                                    <th>Courses Registered</th>
                                    <th>Courses Approved</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary as $row): ?>
                                    <tr>
                                        <td><?php echo e($row['year_name'] . ' - ' . $row['semester_name']); ?></td>
                                        <td><?php echo e($row['course_count']); ?></td>
                                        <td><?php echo e($row['approved_count']); ?></td>
                                        <td>
                                            <a href="registrations.php?semester_id=<?php echo $row['semester_id']; ?>" class="btn btn-sm btn-info">View</a>
                                            <a href="course-registration.php?semester_id=<?php echo $row['semester_id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
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

<?php include '../../includes/footer.php';
