<?php
/**
 * View registered courses for a student in a semester (Admin)
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

if (!$studentId || !$semesterId) {
    header('Location: pending.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get student details
$stmt = $conn->prepare("SELECT s.*, u.email FROM students s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = :id");
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();

// Get semester name
$stmt = $conn->prepare("SELECT * FROM semesters WHERE id = :id");
$stmt->execute(['id' => $semesterId]);
$semester = $stmt->fetch();

// Get registrations
$stmt = $conn->prepare("SELECT cr.*, c.course_code, c.course_name, c.credit_hours
                        FROM course_registrations cr
                        JOIN courses c ON cr.course_id = c.id
                        WHERE cr.student_id = :student_id AND cr.semester_id = :semester_id");
$stmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
$regs = $stmt->fetchAll();

$pageTitle = 'Registered Courses - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>Registered Courses</h4>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card">
            <div class="card-body">
                <h5><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?> — <?php echo e($semester['semester_name'] ?? 'Semester'); ?></h5>
                <p class="text-muted">Student ID: <?php echo e($student['student_id']); ?></p>

                <?php if (empty($regs)): ?>
                    <p class="text-muted">No course registrations found for this student and semester.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Credit Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($regs as $r): ?>
                                    <tr>
                                        <td><?php echo e($r['course_code']); ?></td>
                                        <td><?php echo e($r['course_name']); ?></td>
                                        <td><?php echo e($r['credit_hours']); ?></td>
                                        <td><?php echo e($r['status']); ?></td>
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

<?php include '../../../includes/footer.php'; ?>