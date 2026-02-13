<?php
/**
 * View Lecturer Details - Admin
 */
require_once dirname(__DIR__, 3) . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$lecturerId = (int)($_GET['id'] ?? 0);

if (!$lecturerId) {
    $session->setFlash('error', 'Invalid lecturer ID');
    header('Location: list.php');
    exit;
}

// Get lecturer details
$stmt = $conn->prepare("
    SELECT l.*, u.username, u.email as user_email, u.status as user_status,
           u.last_login, u.created_at as user_created_at
    FROM lecturers l
    INNER JOIN users u ON l.user_id = u.id
    WHERE l.id = :id
");
$stmt->execute(['id' => $lecturerId]);
$lecturer = $stmt->fetch();

if (!$lecturer) {
    $session->setFlash('error', 'Lecturer not found');
    header('Location: list.php');
    exit;
}

$pageTitle = 'View Lecturer - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>Lecturer Details</h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <a href="list.php" class="btn btn-secondary mr-2">← Back to List</a>
            <a href="edit.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-warning mr-2">✏️ Edit</a>
            <?php include dirname(__DIR__, 3) . '/includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success">
                <?php echo e($session->getFlash('success')); ?>
            </div>
        <?php endif; ?>

        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo e($session->getFlash('error')); ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Lecturer Information -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Lecturer Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Lecturer ID:</strong> <?php echo e($lecturer['lecturer_id']); ?></p>
                                <p><strong>Full Name:</strong> <?php echo e($lecturer['title'] . ' ' . $lecturer['first_name'] . ' ' . $lecturer['middle_name'] . ' ' . $lecturer['last_name']); ?></p>
                                <p><strong>Email:</strong> <?php echo e($lecturer['email']); ?></p>
                                <p><strong>Phone:</strong> <?php echo e($lecturer['phone'] ?? 'N/A'); ?></p>
                                <p><strong>Gender:</strong> <?php echo e($lecturer['gender'] ?? 'N/A'); ?></p>
                                <p><strong>Date of Birth:</strong> <?php echo e($lecturer['date_of_birth'] ? date('M d, Y', strtotime($lecturer['date_of_birth'])) : 'N/A'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Department:</strong> <?php echo e($lecturer['department'] ?? 'N/A'); ?></p>
                                <p><strong>Designation:</strong> <?php echo e($lecturer['designation'] ?? 'N/A'); ?></p>
                                <p><strong>Specialization:</strong> <?php echo e($lecturer['specialization'] ?? 'N/A'); ?></p>
                                <p><strong>Qualifications:</strong> <?php echo e($lecturer['qualifications'] ?? 'N/A'); ?></p>
                                <p><strong>Employment Type:</strong> <?php echo e($lecturer['employment_type'] ?? 'N/A'); ?></p>
                                <p><strong>Employment Date:</strong> <?php echo e($lecturer['employment_date'] ? date('M d, Y', strtotime($lecturer['employment_date'])) : 'N/A'); ?></p>
                            </div>
                        </div>

                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Office Location:</strong> <?php echo e($lecturer['office_location'] ?? 'N/A'); ?></p>
                                <p><strong>National ID:</strong> <?php echo e($lecturer['national_id'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Status:</strong>
                                    <span class="badge badge-<?php
                                        switch($lecturer['status']) {
                                            case 'active': echo 'success'; break;
                                            case 'pending': echo 'warning'; break;
                                            case 'rejected': echo 'danger'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo e(ucfirst($lecturer['status'])); ?>
                                    </span>
                                </p>
                                <p><strong>Created:</strong> <?php echo e(date('M d, Y H:i', strtotime($lecturer['created_at']))); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Information -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Account Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Username:</strong> <?php echo e($lecturer['username']); ?></p>
                        <p><strong>User Status:</strong>
                            <span class="badge badge-<?php echo $lecturer['user_status'] == 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo e(ucfirst($lecturer['user_status'])); ?>
                            </span>
                        </p>
                        <p><strong>Last Login:</strong> <?php echo e($lecturer['last_login'] ? date('M d, Y H:i', strtotime($lecturer['last_login'])) : 'Never'); ?></p>
                        <p><strong>Account Created:</strong> <?php echo e(date('M d, Y H:i', strtotime($lecturer['user_created_at']))); ?></p>

                        <hr>
                        <div class="text-center">
                            <a href="reset_password.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-secondary btn-sm">🔑 Reset Password</a>
                            <a href="send_invite.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-primary btn-sm">📧 Send Invite</a>
                        </div>
                    </div>
                </div>

                <!-- Photo -->
                <?php if (!empty($lecturer['photo'])): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h5>Photo</h5>
                    </div>
                    <div class="card-body text-center">
                        <img src="<?php echo BASE_URL . '/' . $lecturer['photo']; ?>" alt="Lecturer Photo" class="img-fluid rounded" style="max-width: 200px;">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>