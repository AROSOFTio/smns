<?php
/**
 * Student Change Password (for first-time or manual change)
 */
require_once '../../config.php';



$session = new Session('student');
$auth = new Auth('student');

// Ensure student is logged in
if (!$auth->isLoggedIn() || !$auth->hasRole('student')) {
    header('Location: login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = $currentUser['id'] ?? null;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $result = $auth->changeCurrentUserPassword($current_password, $new_password, $confirm_password);
        if (!empty($result['success'])) {
            $success = (string)($result['message'] ?? 'Password changed successfully.');
        } else {
            $error = (string)($result['message'] ?? 'Failed to change password.');
        }
    }
}

// If this was an AJAX request, return JSON instead of rendering the full page
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => !empty($success),
        'message' => $success,
        'error' => $error
    ]);
    exit;
}

$pageTitle = 'Change Password - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/student/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar"><div class="topbar-left"><h4>Change Password</h4></div></div>
    <div class="content-area">
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
        <div class="card"><div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control" required></div>
                <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                <div class="text-right"><button type="submit" class="btn btn-primary">Change Password</button></div>
            </form>
        </div></div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
