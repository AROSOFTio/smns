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

$defaultMfaCodeLength = defined('MFA_CODE_LENGTH') ? (int)MFA_CODE_LENGTH : 6;
$mfaCodeLength = (int)(function_exists('getSetting') ? getSetting('mfa_code_length', $defaultMfaCodeLength) : $defaultMfaCodeLength);
if ($mfaCodeLength < 4) {
    $mfaCodeLength = 4;
}
if ($mfaCodeLength > 8) {
    $mfaCodeLength = 8;
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
    <title>MFA Verification - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <style>
        body {
            background: url('../../assets/img/seminary.jpeg') no-repeat center center fixed;
            background-size: cover;
        }
        .login-container {
            max-width: 420px;
        }
        .mfa-helper {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 12px;
        }
        .mfa-code-input {
            text-align: center;
            letter-spacing: 0.28em;
            font-weight: 600;
            font-size: 1.05rem;
            padding-left: 14px !important;
            padding-right: 14px !important;
        }
        .mfa-code-input::placeholder {
            letter-spacing: normal;
            font-weight: 400;
            font-size: 0.95rem;
        }
        .mfa-actions .btn {
            margin-bottom: 8px;
        }
        html[data-theme='dark'] .mfa-helper {
            color: #9ca3af;
        }
    </style>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css">
</head>
<body>
<?php
$themeMap = [
    'admin' => 'admin-theme',
    'student' => 'student-theme',
    'lecturer' => 'lecturer-theme',
    'finance' => 'finance-theme'
];
$cardThemeClass = $themeMap[$module] ?? 'admin-theme';
$moduleLabel = ucfirst($module);
?>
<div class="login-container">
    <div class="login-card <?php echo e($cardThemeClass); ?>">
        <div class="login-header">
            <img src="../../assets/img/sem.PNG" alt="Logo" class="logo mb-2" style="max-width:80px;">
            <h2>Security Verification</h2>
            <span class="role-badge"><?php echo e($moduleLabel); ?> OTP</span>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

        <p class="mfa-helper">Enter the verification code sent to your email. If it expires, click <strong>Resend Code</strong>.</p>

        <form method="post" class="login-form mfa-actions" id="mfaVerifyForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="module" value="<?php echo e($module); ?>">
            <input type="hidden" name="action" value="verify">
            <div class="form-group">
                <label for="code">Verification Code</label>
                <input
                    id="code"
                    name="code"
                    class="form-control mfa-code-input"
                    maxlength="<?php echo (int)$mfaCodeLength; ?>"
                    minlength="<?php echo (int)$mfaCodeLength; ?>"
                    data-code-length="<?php echo (int)$mfaCodeLength; ?>"
                    placeholder="Enter <?php echo (int)$mfaCodeLength; ?>-digit code"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9]{<?php echo (int)$mfaCodeLength; ?>}"
                    required
                    autofocus
                >
            </div>
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-check-circle"></i> Verify and Continue
            </button>
        </form>

        <form method="post" class="mfa-actions">
            <?php echo csrfField(); ?>
            <input type="hidden" name="module" value="<?php echo e($module); ?>">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-outline-secondary btn-block">Resend Code</button>
        </form>

        <div class="text-center mt-2">
            <a href="<?php echo e(BASE_URL . '/views/' . $module . '/login.php'); ?>" class="btn btn-link btn-sm">
                <i class="fas fa-arrow-left"></i> Back to login
            </a>
        </div>
    </div>
</div>
<script src="../../assets/js/login-theme.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var verifyForm = document.getElementById('mfaVerifyForm');
    var codeInput = document.getElementById('code');
    if (!verifyForm || !codeInput) return;

    var expectedLength = parseInt(codeInput.getAttribute('data-code-length') || '6', 10);
    if (!Number.isFinite(expectedLength) || expectedLength < 4) {
        expectedLength = 6;
    }

    var submitted = false;
    verifyForm.addEventListener('submit', function () {
        submitted = true;
    });

    function tryAutoSubmit() {
        var digitsOnly = String(codeInput.value || '').replace(/\D/g, '');
        if (digitsOnly !== codeInput.value) {
            codeInput.value = digitsOnly;
        }
        if (!submitted && digitsOnly.length === expectedLength) {
            submitted = true;
            if (typeof verifyForm.requestSubmit === 'function') {
                verifyForm.requestSubmit();
            } else {
                verifyForm.submit();
            }
        }
    }

    codeInput.addEventListener('input', tryAutoSubmit);
    codeInput.addEventListener('paste', function () {
        setTimeout(tryAutoSubmit, 0);
    });
});
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js"></script>
</body>
</html>
