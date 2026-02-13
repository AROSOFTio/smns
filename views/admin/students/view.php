<?php
/**
 * View Student Details - Admin
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    header('Location: list.php?error=invalid_id');
    exit;
}

// Get student details
$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT s.*, p.program_code, p.program_name, u.username, u.status as user_status, u.created_at as user_created_at, u.last_login, u.failed_login_attempts as login_attempts
        FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.id = :id
    ");
    $stmt->execute(['id' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        header('Location: list.php?error=student_not_found');
        exit;
    }
} catch (Exception $e) {
    header('Location: list.php?error=database_error');
    exit;
}

$pageTitle = 'View Student - ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="../../../assets/css/responsive-nav.css">
</head>
<body>
<style>
/* Custom styles for student view */
.student-photo {
    max-width: 150px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.info-card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.info-label {
    font-weight: 600;
    color: #495057;
    min-width: 140px;
}

.status-badge {
    font-size: 0.875rem;
    padding: 0.375rem 0.75rem;
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Student Details</h4>
        </div>
        <div class="topbar-right">
            <a href="list.php" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="edit.php?id=<?php echo $student['id']; ?>" class="btn btn-warning mr-2">
                <i class="fas fa-edit"></i> Edit Student
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $session->getFlash('success'); ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $session->getFlash('error'); ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Student Photo and Basic Info -->
            <div class="col-md-4">
                <div class="card info-card">
                    <div class="card-body text-center">
                        <?php if (!empty($student['photo'])): ?>
                            <img src="<?php echo BASE_URL . '/' . $student['photo']; ?>" alt="Student Photo" class="student-photo mb-3">
                        <?php else: ?>
                            <div class="student-photo-placeholder rounded mb-3 d-inline-flex align-items-center justify-content-center bg-primary text-white" style="width: 150px; height: 150px; font-size: 3rem; font-weight: bold;">
                                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>

                        <h5 class="mb-1"><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?></h5>
                        <p class="text-muted mb-2"><?php echo e($student['student_id']); ?></p>
                        <span class="badge status-badge badge-<?php echo Helper::getStatusColor($student['status']); ?>">
                            <?php echo e(ucfirst($student['status'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Personal Information -->
            <div class="col-md-8">
                <div class="card info-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user"></i> Personal Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Full Name:</span>
                                    <?php echo e((!empty($student['title']) ? $student['title'] . ' ' : '') . $student['first_name'] . ' ' . (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Gender:</span>
                                    <?php echo e(ucfirst($student['gender'])); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Date of Birth:</span>
                                    <?php echo e(!empty($student['date_of_birth']) ? Helper::formatDate($student['date_of_birth'], 'M d, Y') : 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">National ID:</span>
                                    <?php echo e($student['national_id'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Phone:</span>
                                    <?php echo e($student['phone']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Email:</span>
                                    <?php echo e($student['email']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Address:</span>
                                    <?php echo e($student['address'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Emergency Contact:</span>
                                    <?php echo e($student['emergency_contact'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="card info-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Academic Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Student ID:</span>
                                    <strong><?php echo e($student['student_id']); ?></strong>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Admission Number:</span>
                                    <?php echo e($student['admission_number'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Program:</span>
                                    <strong><?php echo e($student['program_code']); ?> - <?php echo e($student['program_name']); ?></strong>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Level:</span>
                                    Year <?php echo e($student['level_year']); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Enrollment Date:</span>
                                    <?php echo e(!empty($student['enrollment_date']) ? Helper::formatDate($student['enrollment_date'], 'M d, Y') : 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Specialization:</span>
                                    <?php echo e($student['specialization'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Qualifications:</span>
                                    <?php echo e($student['qualifications'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Account Status:</span>
                                    <span class="badge badge-<?php echo $student['user_status'] === 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo e(ucfirst($student['user_status'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information -->
                <div class="card info-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Account Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Username:</span>
                                    <?php echo e($student['username']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Account Created:</span>
                                    <?php echo e(Helper::formatDateTime($student['user_created_at'], 'M d, Y g:i A')); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Last Login:</span>
                                    <?php echo e($student['last_login'] ? Helper::formatDateTime($student['last_login'], 'M d, Y g:i A') : 'Never'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Login Attempts:</span>
                                    <?php echo e($student['login_attempts'] ?? 0); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="edit.php?id=<?php echo $student['id']; ?>" class="btn btn-warning mr-2">
                    <i class="fas fa-edit"></i> Edit Student
                </a>
                <a href="reset_password.php?id=<?php echo $student['id']; ?>" class="btn btn-info mr-2">
                    <i class="fas fa-key"></i> Reset Password
                </a>
                <a href="send_invite.php?id=<?php echo $student['id']; ?>" class="btn btn-success mr-2">
                    <i class="fas fa-envelope"></i> Send Invite
                </a>
                <button type="button" class="btn btn-danger" onclick="deleteStudent(<?php echo $student['id']; ?>)">
                    <i class="fas fa-trash"></i> Delete Student
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function deleteStudent(studentId) {
    if (confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + studentId;
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>
</body>
</html>