<?php
/**
 * Send Invite to Lecturer - Admin
 */
require_once dirname(__DIR__, 3) . '/config.php';



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
    SELECT l.*, u.username, u.email, u.status as user_status
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inviteType = $_POST['invite_type'] ?? 'welcome';
    $customMessage = Security::sanitize($_POST['custom_message'] ?? '');

    // Prepare email content based on type
    switch ($inviteType) {
        case 'welcome':
            $subject = 'Welcome to ' . APP_NAME . ' - Your Account is Ready';
            $message = "
Dear {$lecturer['first_name']} {$lecturer['last_name']},

Welcome to " . APP_NAME . "! Your lecturer account has been created and is ready for use.

Your login credentials are:
Username: {$lecturer['username']}
Email: {$lecturer['email']}

To get started, please visit: " . BASE_URL . "/views/auth/login.php

If you have any questions, please contact the administration.

Best regards,
" . APP_NAME . " Administration
            ";
            break;

        case 'activation':
            $subject = APP_NAME . ' - Your Account is Now Active';
            $message = "
Dear {$lecturer['first_name']} {$lecturer['last_name']},

Your lecturer account has been activated and you can now access the system.

Login Details:
Username: {$lecturer['username']}
Email: {$lecturer['email']}

Please visit: " . BASE_URL . "/views/auth/login.php

Best regards,
" . APP_NAME . " Administration
            ";
            break;

        case 'custom':
            $subject = APP_NAME . ' - Important Message';
            $message = "
Dear {$lecturer['first_name']} {$lecturer['last_name']},

{$customMessage}

Login Details:
Username: {$lecturer['username']}
Email: {$lecturer['email']}

Please visit: " . BASE_URL . "/views/auth/login.php

Best regards,
" . APP_NAME . " Administration
            ";
            break;

        default:
            $subject = APP_NAME . ' - System Notification';
            $message = "
Dear {$lecturer['first_name']} {$lecturer['last_name']},

This is a notification from " . APP_NAME . ".

Your account details:
Username: {$lecturer['username']}
Email: {$lecturer['email']}

Please visit: " . BASE_URL . "/views/auth/login.php

Best regards,
" . APP_NAME . " Administration
            ";
    }

    // Send email
    $emailSent = Helper::sendEmail($lecturer['email'], $subject, $message);

    if ($emailSent) {
        $session->setFlash('success', 'Invitation email sent successfully to ' . $lecturer['email']);
        header('Location: view.php?id=' . $lecturerId);
        exit;
    } else {
        $session->setFlash('error', 'Failed to send email. Please check email configuration.');
    }
}

$pageTitle = 'Send Invite - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>Send Invitation Email</h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <a href="view.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-secondary mr-2">👁️ View Lecturer</a>
            <a href="list.php" class="btn btn-secondary mr-2">← Back to List</a>
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
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Lecturer Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Name:</strong> <?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo e($lecturer['email']); ?></p>
                        <p><strong>Username:</strong> <?php echo e($lecturer['username']); ?></p>
                        <p><strong>Lecturer ID:</strong> <?php echo e($lecturer['lecturer_id']); ?></p>
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
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Send Invitation</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label>Invitation Type</label>
                                <select name="invite_type" class="form-control" id="inviteType">
                                    <option value="welcome">Welcome Email</option>
                                    <option value="activation">Account Activation</option>
                                    <option value="custom">Custom Message</option>
                                </select>
                            </div>

                            <div class="form-group" id="customMessageGroup" style="display: none;">
                                <label>Custom Message</label>
                                <textarea name="custom_message" class="form-control" rows="4" placeholder="Enter your custom message here..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-block">
                                📧 Send Invitation Email
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5>Email Preview</h5>
            </div>
            <div class="card-body">
                <div id="emailPreview">
                    <p><strong>Subject:</strong> <span id="previewSubject">Welcome to <?php echo APP_NAME; ?> - Your Account is Ready</span></p>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-family: monospace; white-space: pre-line;">
Dear <?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>,

Welcome to <?php echo APP_NAME; ?>! Your lecturer account has been created and is ready for use.

Your login credentials are:
Username: <?php echo e($lecturer['username']); ?>
Email: <?php echo e($lecturer['email']); ?>

To get started, please visit: <?php echo BASE_URL; ?>/views/auth/login.php

If you have any questions, please contact the administration.

Best regards,
<?php echo APP_NAME; ?> Administration
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('inviteType').addEventListener('change', function() {
    const customMessageGroup = document.getElementById('customMessageGroup');
    const previewSubject = document.getElementById('previewSubject');

    if (this.value === 'custom') {
        customMessageGroup.style.display = 'block';
        previewSubject.textContent = '<?php echo APP_NAME; ?> - Important Message';
    } else if (this.value === 'activation') {
        customMessageGroup.style.display = 'none';
        previewSubject.textContent = '<?php echo APP_NAME; ?> - Your Account is Now Active';
    } else {
        customMessageGroup.style.display = 'none';
        previewSubject.textContent = 'Welcome to <?php echo APP_NAME; ?> - Your Account is Ready';
    }
});
</script>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>