<?php
/**
 * Shared MFA verification page for module logins.
 */
require_once '../../config.php';

$allowedModules = ['admin', 'student', 'lecturer', 'finance'];
$module = strtolower(trim((string)($_GET['module'] ?? $_POST['module'] ?? '')));
if (!in_array($module, $allowedModules, true)) {
    $module = 'admin';
}

$session = new Session($module);
$auth = new Auth($module);
$error = '';
$success = '';
$pendingNotice = trim((string)$auth->consumePendingLoginNotice($module));
if ($pendingNotice !== '') {
    $success = $pendingNotice;
}

if ($auth->isLoggedIn() && $auth->getRole() === $module) {
    header('Location: ' . BASE_URL . '/views/' . $module . '/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'verify'));
        if ($action === 'resend') {
            $resend = $auth->resendPendingMfaChallenge();
            if (!empty($resend['success'])) {
                $success = $resend['message'] ?? 'Verification code resent.';
            } else {
                $error = $resend['message'] ?? 'Unable to resend verification code.';
            }
        } else {
            $code = trim((string)($_POST['code'] ?? ''));
            $result = $auth->verifyPendingMfa($code);
            if (!empty($result['success']) && (($result['role'] ?? '') === $module)) {
                if (!empty($result['require_password_change'])) {
                    header('Location: ' . BASE_URL . '/views/' . $module . '/change-password.php');
                    exit;
                }
                header('Location: ' . BASE_URL . '/views/' . $module . '/dashboard.php');
                exit;
            }
            if (!empty($result['consent_required'])) {
                header('Location: ' . BASE_URL . '/views/auth/privacy-consent.php?module=' . urlencode($module));
                exit;
            }
            $error = $result['message'] ?? 'Verification failed.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MFA Verification - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="mb-3">Security Verification</h4>
                    <p class="text-muted">Enter the verification code sent to your email.</p>
                    <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

                    <form method="post" class="mb-2">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="module" value="<?php echo e($module); ?>">
                        <input type="hidden" name="action" value="verify">
                        <div class="form-group">
                            <label for="code">Verification Code</label>
                            <input id="code" name="code" class="form-control" maxlength="8" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Verify and Continue</button>
                    </form>

                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="module" value="<?php echo e($module); ?>">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn btn-outline-secondary btn-block">Resend Code</button>
                    </form>
                    <div class="mt-3 text-center">
                        <a href="<?php echo e(BASE_URL . '/views/' . $module . '/login.php'); ?>" class="small">Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
