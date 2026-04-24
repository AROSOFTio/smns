<?php
require_once '../../config.php';

$role = strtolower(trim((string)($_GET['role'] ?? '')));
$allowedRoles = ['admin', 'student', 'lecturer', 'finance'];
if (!in_array($role, $allowedRoles, true)) {
    $role = '';
}

$roleLabels = [
    'admin' => 'Admin',
    'student' => 'Student',
    'lecturer' => 'Lecturer',
    'finance' => 'Finance'
];
$roleLabel = $roleLabels[$role] ?? 'Account';
$cardThemeClass = $role !== '' ? $role . '-theme' : 'unified-theme';

$backLoginUrl = $role !== ''
    ? (BASE_URL . '/views/' . $role . '/login.php')
    : (BASE_URL . '/views/auth/login.php');

$error = '';
$success = '';
$resetPreviewUrl = '';

function smnsIsLocalDebugContext() {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    $remoteAddr = strtolower((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $serverAddr = strtolower((string)($_SERVER['SERVER_ADDR'] ?? ''));

    $hostOnly = $host;
    if (strpos($hostOnly, ':') !== false) {
        $hostOnly = substr($hostOnly, 0, (int)strpos($hostOnly, ':'));
    }

    $locals = ['localhost', '127.0.0.1', '::1'];
    return (defined('APP_DEBUG') && APP_DEBUG)
        || in_array($hostOnly, $locals, true)
        || in_array($serverName, $locals, true)
        || in_array($remoteAddr, $locals, true)
        || in_array($serverAddr, $locals, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfValid = Security::verifyCSRFToken($_POST['csrf_token'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));

    if (!$csrfValid) {
        $error = 'Invalid request. Please try again.';
    } elseif ($email === '' || !Security::validateEmail($email)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            $auth = new Auth($role !== '' ? $role : null);
            $tokenResult = $auth->generatePasswordResetToken($email);
            if (!empty($tokenResult['success']) && !empty($tokenResult['token'])) {
                $token = (string)$tokenResult['token'];
                $resetUrl = BASE_URL . '/views/auth/reset-password.php?token=' . urlencode($token);
                if ($role !== '') {
                    $resetUrl .= '&role=' . urlencode($role);
                }
                if (smnsIsLocalDebugContext()) {
                    $resetPreviewUrl = $resetUrl;
                }

                $appName = defined('APP_NAME') ? APP_NAME : 'System';
                $subject = $appName . ' - Password Reset Request';
                $message = "Hello,\n\n"
                    . "A request was received to reset your {$roleLabel} password.\n"
                    . "Reset link (valid for 1 hour): {$resetUrl}\n\n"
                    . "If you did not request this, you can ignore this email.\n\n"
                    . "Regards,\n{$appName} Administration";

                $html = '<!DOCTYPE html><html><body style="font-family:Segoe UI,Arial,sans-serif;color:#111827;background:#f3f4f6;padding:24px;">'
                    . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;padding:22px;">'
                    . '<h2 style="margin:0 0 12px;font-size:20px;color:#0f172a;">Password Reset Request</h2>'
                    . '<p style="margin:0 0 10px;">A request was received to reset your ' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . ' password.</p>'
                    . '<p style="margin:0 0 16px;">This link is valid for 1 hour.</p>'
                    . '<p style="margin:0 0 20px;"><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#2563eb;text-decoration:underline;">Reset your password</a></p>'
                    . '<p style="margin:0;">If you did not request this, you can ignore this email.</p>'
                    . '</div></body></html>';

                $sent = Helper::sendEmail($email, $subject, $message, [
                    'html' => $html,
                    'context_label' => 'Password Reset Request'
                ]);
                if (!$sent) {
                    $deliveryError = trim((string)Helper::getLastEmailError());
                    error_log('Forgot password email failed: ' . $deliveryError);
                    if (smnsIsLocalDebugContext()) {
                        $error = 'Reset link could not be emailed. Mailer error: ' . ($deliveryError !== '' ? $deliveryError : 'Unknown mail transport failure.');
                    } else {
                        $success = 'If that email is registered, we have sent a password reset link.';
                    }
                } else {
                    $success = 'If that email is registered, we have sent a password reset link.';
                }
            } else {
                $lookupMessage = trim((string)($tokenResult['message'] ?? ''));
                error_log('Forgot password lookup skipped: ' . ($lookupMessage !== '' ? $lookupMessage : 'Unknown lookup result.'));
                if (smnsIsLocalDebugContext()) {
                    $roleText = $role !== '' ? ($roleLabel . ' ') : '';
                    $error = 'No active ' . $roleText . 'account matches that email address.';
                } else {
                    $success = 'If that email is registered, we have sent a password reset link.';
                }
            }
        } catch (Exception $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            $error = 'Unable to process your request right now.';
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
    <title>Forgot Password - <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
</head>
<body style="background: url('../../uploads/seminary.jpeg') no-repeat center center fixed; background-size: cover;">
<div class="login-container">
    <div class="login-card <?php echo e($cardThemeClass); ?>">
        <div class="login-header">
            <h2><?php echo e(APP_SHORT_NAME); ?></h2>
            <span class="role-badge">Forgot Password</span>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($resetPreviewUrl !== ''): ?>
            <div class="alert alert-warning">
                Local reset shortcut is ready.
                <a href="<?php echo e($resetPreviewUrl); ?>" class="btn btn-link btn-sm" style="padding-left:6px;">Open Reset Page</a>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form" autocomplete="on">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label class="mb-1">Email Address</label>
                <div class="input-wrapper">
                    <input
                        type="email"
                        name="email"
                        class="form-control"
                        placeholder="Enter your email"
                        required
                        autofocus
                    >
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>

        <div class="text-center mt-3">
            <a href="<?php echo e($backLoginUrl); ?>" class="btn btn-link btn-sm">Back to Login</a>
        </div>
    </div>
</div>
<script src="../../assets/js/login-theme.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
</body>
</html>
