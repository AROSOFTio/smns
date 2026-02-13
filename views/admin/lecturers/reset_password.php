<?php
/**
 * Reset Lecturer Password - Admin
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
    SELECT l.*, u.username, u.email
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
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        // Generate new password
        $newPassword = Security::generatePassword(10);
        $passwordHash = Security::hashPassword($newPassword);

        try {
            // Update password
            $stmt = $conn->prepare("UPDATE users SET password_hash = :password, require_password_change = 1, status = 'active' WHERE id = :id");
            $stmt->execute(['password' => $passwordHash, 'id' => $lecturer['user_id']]);

            // Store new password in session for display
            $_SESSION['generated_password'] = $newPassword;

            $session->setFlash('success', 'New password generated successfully. Click "Send Email" to notify the lecturer.');
            header('Location: reset_password.php?id=' . $lecturerId);
            exit;

        } catch (Exception $e) {
            $session->setFlash('error', 'Error generating password: ' . $e->getMessage());
        }

    } elseif ($action === 'send_email') {
        $newPassword = $_SESSION['generated_password'] ?? '';

        if (empty($newPassword)) {
            $session->setFlash('error', 'No password has been generated yet. Please generate a new password first.');
        } else {
            // Send email with new password
            $subject = APP_NAME . ' - Password Reset';
            $message = "
Dear {$lecturer['first_name']} {$lecturer['last_name']},

Your password has been reset by an administrator.

Your new login credentials are:
Username: {$lecturer['username']}
Password: {$newPassword}

Please log in and change your password immediately for security reasons.

Best regards,
" . APP_NAME . " Administration
            ";

            $emailSent = Helper::sendEmail($lecturer['email'], $subject, $message);

            if ($emailSent) {
                // Clear the generated password from session
                unset($_SESSION['generated_password']);
                $session->setFlash('success', 'Password reset email sent successfully to ' . $lecturer['email']);
                header('Location: view.php?id=' . $lecturerId);
                exit;
            } else {
                $session->setFlash('error', 'Failed to send email. Please try again.');
            }
        }
    }
}

$pageTitle = 'Reset Password - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>Reset Lecturer Password</h4>
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
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Password Reset Actions</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="generate">

                            <p>This will generate a new random password for the lecturer.</p>

                            <button type="submit" class="btn btn-warning btn-block">
                                🔑 Generate New Password
                            </button>
                        </form>

                        <?php if (isset($_SESSION['generated_password'])): ?>
                            <hr>
                            <div class="alert alert-info">
                                <strong>New Password Generated:</strong><br>
                                <div class="input-group mb-2">
                                    <input type="text" class="form-control" id="generatedPassword" value="<?php echo e($_SESSION['generated_password']); ?>" readonly style="font-family: monospace; font-size: 1.2em;">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" title="Hide/Show Password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <small class="text-muted">Click the eye icon to hide/show the password. Copy this password and send it to the lecturer securely.</small>
                            </div>

                            <script>
                            document.getElementById('togglePassword').addEventListener('click', function() {
                                const passwordField = document.getElementById('generatedPassword');
                                const icon = this.querySelector('i');

                                if (passwordField.type === 'password') {
                                    passwordField.type = 'text';
                                    icon.className = 'fas fa-eye';
                                    this.title = 'Hide Password';
                                } else {
                                    passwordField.type = 'password';
                                    icon.className = 'fas fa-eye-slash';
                                    this.title = 'Show Password';
                                }
                            });
                            </script>

                            <form method="POST">
                                <input type="hidden" name="action" value="send_email">

                                <button type="submit" class="btn btn-primary btn-block">
                                    📧 Send Password via Email
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5>Security Notice</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong>Important:</strong>
                    <ul class="mb-0">
                        <li>The lecturer will be required to change their password on first login after reset.</li>
                        <li>Make sure to communicate the new password securely to the lecturer.</li>
                        <li>This action cannot be undone.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>