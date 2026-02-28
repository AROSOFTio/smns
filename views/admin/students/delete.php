<?php
/**
 * Delete Student - Admin
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get student ID from URL
$studentId = $_GET['id'] ?? null;
if (!$studentId) {
    $session->setFlash('error', 'Invalid student ID');
    header('Location: list.php');
    exit;
}

// Get student details for confirmation
$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT s.*, p.program_name, u.username
        FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.id = :id
    ");
    $stmt->execute(['id' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $session->setFlash('error', 'Student not found');
        header('Location: list.php');
        exit;
    }
} catch (Exception $e) {
    $session->setFlash('error', 'Database error: ' . $e->getMessage());
    header('Location: list.php');
    exit;
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCsrfToken()) {
        $session->setFlash('error', 'Invalid request');
        header('Location: list.php');
        exit;
    }

    try {
        $conn->beginTransaction();

        // Delete student record (this will cascade delete due to foreign key constraints)
        $stmt = $conn->prepare("DELETE FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);

        // Delete associated user account
        $stmt = $conn->prepare("DELETE FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $student['user_id']]);

        // Delete profile photo if it exists
        if (!empty($student['photo']) && file_exists('../../../' . $student['photo'])) {
            unlink('../../../' . $student['photo']);
        }

        $conn->commit();

        // Log the deletion
        $logger = new Logger('admin_actions');
        $logger->info('Student deleted', [
            'admin_id' => $currentUser['id'],
            'admin_name' => $currentUser['profile']['first_name'] . ' ' . $currentUser['profile']['last_name'],
            'student_id' => $student['student_id'],
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'deleted_at' => date('Y-m-d H:i:s')
        ]);

        $session->setFlash('success', 'Student deleted successfully');
        header('Location: list.php');
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        $session->setFlash('error', 'Failed to delete student: ' . $e->getMessage());
        header('Location: list.php');
        exit;
    }
}

$pageTitle = 'Delete Student - ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var mode = localStorage.getItem('smns_theme_mode');
                if (mode === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
            } catch (e) {}
        })();
    </script>
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="../../../assets/css/theme-shared.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="../../../assets/css/responsive-nav.css">
</head>
<body>
<style>
/* Custom styles for delete confirmation */
.delete-card {
    border: 2px solid #dc3545;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.warning-icon {
    font-size: 4rem;
    color: #dc3545;
}

.student-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin: 20px 0;
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Delete Student</h4>
        </div>
        <div class="topbar-right">
            <a href="view.php?id=<?php echo $student['id']; ?>" class="btn btn-info mr-2">
                <i class="fas fa-eye"></i> View Details
            </a>
            <a href="list.php" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card delete-card">
                    <div class="card-body text-center">
                        <div class="warning-icon mb-4">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>

                        <h4 class="card-title text-danger mb-4">Confirm Student Deletion</h4>

                        <p class="card-text mb-4">
                            Are you sure you want to delete this student? This action <strong>cannot be undone</strong> and will permanently remove all associated data.
                        </p>

                        <div class="student-info">
                            <h5><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?></h5>
                            <p class="mb-1"><strong>Student ID:</strong> <?php echo e($student['student_id']); ?></p>
                            <p class="mb-1"><strong>Program:</strong> <?php echo e($student['program_name']); ?></p>
                            <p class="mb-1"><strong>Email:</strong> <?php echo e($student['email']); ?></p>
                            <p class="mb-0"><strong>Username:</strong> <?php echo e($student['username']); ?></p>
                        </div>

                        <div class="alert alert-warning">
                            <strong>Warning:</strong> Deleting this student will also remove their user account and all associated records including grades, attendance, and other academic data.
                        </div>

                        <form method="POST" class="mt-4">
                            <?php echo csrfField(); ?>
                            <div class="form-group">
                                <label for="confirmDelete" class="font-weight-bold">Type "DELETE" to confirm:</label>
                                <input type="text" id="confirmDelete" name="confirm_delete" class="form-control text-center" required
                                       pattern="DELETE" title="Please type DELETE to confirm" style="text-transform: uppercase;">
                            </div>

                            <div class="btn-group">
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="fas fa-trash"></i> Yes, Delete Student
                                </button>
                                <a href="view.php?id=<?php echo $student['id']; ?>" class="btn btn-secondary btn-lg ml-2">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-uppercase the confirmation input
document.getElementById('confirmDelete').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
</script>

<?php include '../../../includes/footer.php'; ?>
</body>
</html>
