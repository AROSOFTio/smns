<?php
/**
 * Admin - View Course Details
 * View course information and assignments
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get course ID from URL
$courseId = (int)($_GET['id'] ?? 0);

if (!$courseId) {
    header('Location: list.php?error=invalid_course');
    exit;
}

// Fetch course details
$stmt = $conn->prepare("
    SELECT c.*, p.program_name, p.program_code
    FROM courses c
    INNER JOIN programs p ON c.program_id = p.id
    WHERE c.id = :id
");
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: list.php?error=course_not_found');
    exit;
}

// Fetch course assignments
$stmt = $conn->prepare("
    SELECT ca.*, l.lecturer_id, l.first_name, l.last_name, l.email,
           s.semester_name, ay.year_name
    FROM course_assignments ca
    INNER JOIN lecturers l ON ca.lecturer_id = l.id
    INNER JOIN semesters s ON ca.semester_id = s.id
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE ca.course_id = :course_id
    ORDER BY ay.year_name DESC, s.semester_number DESC, ca.assigned_date DESC
");
$stmt->execute(['course_id' => $courseId]);
$assignments = $stmt->fetchAll();

$pageTitle = 'View Course - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Course Details</h4>
        </div>
        <div class="topbar-right">
            <a href="assign.php?id=<?php echo $courseId; ?>" class="btn btn-success">➕ Assign to Lecturer</a>
            <a href="edit.php?id=<?php echo $courseId; ?>" class="btn btn-warning">✏️ Edit Course</a>
            <a href="list.php" class="btn btn-secondary">← Back to Courses</a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <!-- Course Information -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-book"></i> Course Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th width="150">Course Code:</th>
                                <td><?php echo e($course['course_code']); ?></td>
                            </tr>
                            <tr>
                                <th>Course Name:</th>
                                <td><?php echo e($course['course_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Credit Hours:</th>
                                <td><?php echo $course['credit_hours']; ?></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="badge badge-<?php echo Helper::getStatusColor($course['status']); ?>">
                                        <?php echo e($course['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th width="150">Program:</th>
                                <td><?php echo e($course['program_code'] . ' - ' . $course['program_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Academic Year:</th>
                                <td>Year <?php echo e($course['level_year']); ?></td>
                            </tr>
                            <tr>
                                <th>Semester Offered:</th>
                                <td>
                                    <?php
                                    $semesters_offered = ['', 'Semester 1', 'Semester 2', 'Both Semesters'];
                                    echo $semesters_offered[$course['semester_offered']] ?? '';
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Created:</th>
                                <td><?php echo Helper::formatDateTime($course['created_at']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php if (!empty($course['prerequisites'])): ?>
                <div class="row">
                    <div class="col-12">
                        <h6>Prerequisites:</h6>
                        <p><?php echo e($course['prerequisites']); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($course['description'])): ?>
                <div class="row">
                    <div class="col-12">
                        <h6>Description:</h6>
                        <p><?php echo e($course['description']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Course Assignments -->
        <div class="card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-users"></i> Course Assignments (<?php echo count($assignments); ?>)</h5>
                <a href="assign.php?id=<?php echo $courseId; ?>" class="btn btn-light btn-sm">➕ Assign Lecturer</a>
            </div>
            <div class="card-body">
                <?php if (count($assignments) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Lecturer</th>
                                    <th>Semester</th>
                                    <th>Assigned Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($assignment['lecturer_id']); ?></strong><br>
                                            <?php echo e($assignment['first_name'] . ' ' . $assignment['last_name']); ?><br>
                                            <small class="text-muted"><?php echo e($assignment['email']); ?></small>
                                        </td>
                                        <td><?php echo e($assignment['year_name'] . ' - ' . $assignment['semester_name']); ?></td>
                                        <td><?php echo Helper::formatDate($assignment['assigned_date']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo Helper::getStatusColor($assignment['status']); ?>">
                                                <?php echo e($assignment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="#" class="btn btn-sm btn-warning"
                                               onclick="changeStatus(<?php echo $assignment['id']; ?>, '<?php echo $assignment['status']; ?>')">
                                                Change Status
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Assignments Yet</h5>
                        <p class="text-muted">This course hasn't been assigned to any lecturers yet.</p>
                        <a href="assign.php?id=<?php echo $courseId; ?>" class="btn btn-success">🎓 Assign Lecturer</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function changeStatus(assignmentId, currentStatus) {
    const newStatus = prompt('Enter new status (active/completed/cancelled):', currentStatus);
    if (newStatus && newStatus !== currentStatus) {
        // You can implement AJAX call here to update status
        alert('Status update functionality can be implemented with AJAX');
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>