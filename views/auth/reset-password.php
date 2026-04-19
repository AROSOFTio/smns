<?php
require_once '../../config.php';

$role = strtolower(trim((string)($_GET['role'] ?? '')));
$allowedRoles = ['admin', 'student', 'lecturer', 'finance'];
if (!in_array($role, $allowedRoles, true)) {
    $role = '';
}
$cardThemeClass = $role !== '' ? $role . '-theme' : 'unified-theme';
$backLoginUrl = $role !== ''
    ? (BASE_URL . '/views/' . $role . '/login.php')
    : (BASE_URL . '/views/auth/login.php');

$token = trim((string)($_GET['token'] ?? ''));
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfValid = Security::verifyCSRFToken($_POST['csrf_token'] ?? '');
    $token = trim((string)($_POST['token'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!$csrfValid) {
        $error = 'Invalid request. Please try again.';
    } elseif ($token === '') {
        $error = 'Reset token is missing or invalid.';
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $auth = new Auth($role !== '' ? $role : null);
            $result = $auth->resetPassword($token, $newPassword);
            if (!empty($result['success'])) {
                $success = 'Your password has been updated. You can now log in.';
            } else {
                $error = (string)($result['message'] ?? 'Unable to reset password.');
            }
        } catch (Exception $e) {
            error_log('Reset password error: ' . $e->getMessage());
            $error = 'Unable to reset password right now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
    <title>Reset Password - <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
</head>
<body style="background: url('../../uploads/seminary.jpeg') no-repeat center center fixed; background-size: cover;">
<div class="login-container">
    <div class="login-card <?php echo e($cardThemeClass); ?>">
        <div class="login-header">
            <h2><?php echo e(APP_SHORT_NAME); ?></h2>
            <span class="role-badge">Reset Password</span>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success === '' && $token !== ''): ?>
            <form method="POST" class="login-form" autocomplete="on">
                <?php echo csrfField(); ?>
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <div class="form-group">
                    <label class="mb-1">New Password</label>
                    <div class="input-wrapper" style="position:relative;">
                        <input
                            type="password"
                            name="new_password"
                            id="new_password"
                            class="form-control"
                            placeholder="Enter new password"
                            required
                            autofocus
                        >
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="position:absolute; right:10px; top:50%; transform:translateY(-50%);" onclick="togglePassword('new_password', this)">Show</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="mb-1">Confirm Password</label>
                    <div class="input-wrapper" style="position:relative;">
                        <input
                            type="password"
                            name="confirm_password"
                            id="confirm_password"
                            class="form-control"
                            placeholder="Confirm new password"
                            required
                        >
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="position:absolute; right:10px; top:50%; transform:translateY(-50%);" onclick="togglePassword('confirm_password', this)">Show</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
            </form>
        <?php elseif ($token === ''): ?>
            <div class="alert alert-warning">Reset token is missing or invalid.</div>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?php echo e($backLoginUrl); ?>" class="btn btn-link btn-sm">Back to Login</a>
        </div>
    </div>
</div>
<script src="../../assets/js/login-theme.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
<script>
    function togglePassword(id, btn) {
        var input = document.getElementById(id);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = 'Hide';
        } else {
            input.type = 'password';
            btn.textContent = 'Show';
        }
    }
</script>
</body>
</html>
