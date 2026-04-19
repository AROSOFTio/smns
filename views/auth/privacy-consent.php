<?php
/**
 * Shared privacy consent acceptance page for module logins.
 */
require_once '../../config.php';

if (!function_exists('sharedAuthResolveReturnTo')) {
    function sharedAuthResolveReturnTo($module, $rawValue) {
        $raw = trim((string)$rawValue);
        if ($raw === '') {
            return '';
        }
        $parsed = @parse_url($raw);
        if ($parsed === false) {
            return '';
        }
        if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
            return '';
        }
        $path = (string)($parsed['path'] ?? '');
        if ($path === '' || strpos($path, '/views/' . $module . '/') !== 0) {
            return '';
        }
        $normalized = $path;
        if (!empty($parsed['query'])) {
            $normalized .= '?' . $parsed['query'];
        }
        if (!empty($parsed['fragment'])) {
            $normalized .= '#' . $parsed['fragment'];
        }
        return $normalized;
    }
}

$allowedModules = ['admin', 'student', 'lecturer', 'finance'];
$module = strtolower(trim((string)($_GET['module'] ?? $_POST['module'] ?? '')));
if (!in_array($module, $allowedModules, true)) {
    $module = 'admin';
}

$session = new Session($module);
$auth = new Auth($module);
$error = '';
$returnTo = sharedAuthResolveReturnTo($module, $_GET['return_to'] ?? $_POST['return_to'] ?? '');
$postConsentTarget = $returnTo !== '' ? (BASE_URL . $returnTo) : (BASE_URL . '/views/' . $module . '/dashboard.php');

if ($auth->isLoggedIn() && $auth->getRole() === $module) {
    header('Location: ' . $postConsentTarget);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } elseif (empty($_POST['consent_ack'])) {
        $error = 'You must accept the privacy notice to continue.';
    } else {
        $result = $auth->acceptPendingPrivacyConsent();
        if (!empty($result['success']) && (($result['role'] ?? '') === $module)) {
            if (!empty($result['require_password_change'])) {
                $changePwdUrl = BASE_URL . '/views/' . $module . '/change-password.php';
                if ($returnTo !== '') {
                    $changePwdUrl .= '?return_to=' . urlencode($returnTo);
                }
                header('Location: ' . $changePwdUrl);
                exit;
            }
            header('Location: ' . $postConsentTarget);
            exit;
        }
        $error = $result['message'] ?? 'Unable to complete privacy consent.';
    }
}
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
    <title>Privacy Consent - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/css/theme-shared.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="mb-3">Privacy Notice and Data Use Consent</h4>
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                    <p class="text-muted mb-3">
                        Version: <strong><?php echo e(defined('PRIVACY_NOTICE_VERSION') ? PRIVACY_NOTICE_VERSION : '1'); ?></strong>
                    </p>
                    <p>
                        By continuing, you agree that this system may process your personal data for academic administration,
                        finance operations, communication, and statutory reporting in line with institutional policy.
                    </p>
                    <p>
                        Your actions are auditable, and consent records are retained for compliance verification.
                    </p>

                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="module" value="<?php echo e($module); ?>">
                        <input type="hidden" name="return_to" value="<?php echo e($returnTo); ?>">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="consent_ack" name="consent_ack" required>
                            <label class="form-check-label" for="consent_ack">
                                I have read and accept the privacy notice and data-use terms.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Accept and Continue</button>
                        <a href="<?php echo e(BASE_URL . '/views/auth/login.php?role=' . urlencode($module)); ?>" class="btn btn-link">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
</body>
</html>
