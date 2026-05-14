<?php
/**
 * Shared MFA verification page for module logins.
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
$success = '';
$returnTo = sharedAuthResolveReturnTo($module, $_GET['return_to'] ?? $_POST['return_to'] ?? '');
$postAuthTarget = $returnTo !== '' ? (BASE_URL . $returnTo) : (BASE_URL . '/views/' . $module . '/dashboard.php');
$pendingNotice = trim((string)$auth->consumePendingLoginNotice($module));
if ($pendingNotice !== '') {
    $success = $pendingNotice;
}
$shouldBootstrapMfa = $auth->shouldBootstrapPendingMfa($module);

if ($auth->isLoggedIn() && $auth->getRole() === $module) {
    header('Location: ' . $postAuthTarget);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'verify'));
    if ($action === 'bootstrap') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
            exit;
        }
        try {
            $bootstrap = $auth->issuePendingMfaChallenge();
        } catch (Throwable $e) {
            error_log('MFA bootstrap request failed: ' . $e->getMessage());
            $bootstrap = ['success' => false, 'message' => 'Unable to send verification code: ' . $e->getMessage()];
        }
        echo json_encode([
            'success' => !empty($bootstrap['success']),
            'message' => (string)($bootstrap['message'] ?? (!empty($bootstrap['success']) ? 'Verification code sent.' : 'Unable to send verification code.')),
            'delivery' => (string)($bootstrap['delivery'] ?? '')
        ]);
        exit;
    }

    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
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
                    $changePwdUrl = BASE_URL . '/views/' . $module . '/change-password.php';
                    if ($returnTo !== '') {
                        $changePwdUrl .= '?return_to=' . urlencode($returnTo);
                    }
                    header('Location: ' . $changePwdUrl);
                    exit;
                }
                header('Location: ' . $postAuthTarget);
                exit;
            }
            if (!empty($result['consent_required'])) {
                $consentUrl = BASE_URL . '/views/auth/privacy-consent.php?module=' . urlencode($module);
                if ($returnTo !== '') {
                    $consentUrl .= '&return_to=' . urlencode($returnTo);
                }
                header('Location: ' . $consentUrl);
                exit;
            }
            $error = $result['message'] ?? 'Verification failed.';
        }
    }
}
$shouldBootstrapMfa = $auth->shouldBootstrapPendingMfa($module);

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
        window.SMNS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.SMNS_APP_VERSION = <?php echo json_encode((string)APP_VERSION); ?>;
        window.SMNS_LOGO_URL = <?php echo json_encode(BASE_URL . '/assets/img/sem.PNG?v=' . urlencode((string)APP_VERSION)); ?>;
    </script>
    <title>MFA Verification - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <style>
        body {
            background: url('../../uploads/seminary.jpeg') no-repeat center center fixed;
            background-size: cover;
        }
        .login-container {
            max-width: 320px !important;
            width: min(320px, calc(100% - 24px)) !important;
            margin: 14px auto !important;
        }
        .login-card {
            padding: 18px 14px !important;
            border-radius: 12px;
        }
        .login-header .logo {
            max-width: 64px !important;
            margin-bottom: 6px !important;
        }
        .login-header h2 {
            font-size: 1.2rem;
            margin-bottom: 8px;
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
        .mfa-status {
            display: none;
        }
        .mfa-status.is-visible {
            display: block;
        }
        html[data-theme='dark'] .mfa-helper {
            color: #9ca3af;
        }
        @media (max-width: 576px) {
            .login-container {
                max-width: 320px !important;
                width: calc(100% - 16px) !important;
                margin: 10px auto !important;
            }
            .login-card {
                padding: 16px 12px !important;
            }
        }
        @media (max-width: 380px) {
            .mfa-helper {
                font-size: 12px;
            }
            .mfa-code-input {
                letter-spacing: 0.18em;
                font-size: 0.98rem;
                padding-left: 12px !important;
                padding-right: 12px !important;
            }
            .mfa-actions .btn {
                font-size: 0.95rem;
                height: 48px;
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
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
            <img src="<?php echo BASE_URL; ?>/assets/img/sem.PNG?v=<?php echo urlencode((string)APP_VERSION); ?>" alt="Logo" class="logo mb-2">
            <h2>Security Verification</h2>
            <span class="role-badge"><?php echo e($moduleLabel); ?> OTP</span>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
        <div id="mfaStatus" class="alert mfa-status"></div>

        <p class="mfa-helper" id="mfaHelperText">
            <?php echo 'Enter the verification code sent to your email. If it expires, click <strong>Resend Code</strong>.'; ?>
        </p>

        <form method="post" class="login-form mfa-actions" id="mfaVerifyForm" data-auto-issue="<?php echo $shouldBootstrapMfa ? '1' : '0'; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="module" value="<?php echo e($module); ?>">
            <input type="hidden" name="return_to" value="<?php echo e($returnTo); ?>">
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
        </form>

        <form method="post" class="mfa-actions" id="mfaResendForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="module" value="<?php echo e($module); ?>">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-outline-secondary btn-block" id="mfaResendBtn">Resend Code</button>
        </form>

        <div class="text-center mt-2">
            <a href="<?php echo e(BASE_URL . '/views/auth/login.php?role=' . urlencode($module)); ?>" class="btn btn-link btn-sm">
                <i class="fas fa-arrow-left"></i> Back to login
            </a>
        </div>
    </div>
</div>
<script src="../../assets/js/login-theme.js?v=<?php echo urlencode((string)APP_VERSION); ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var verifyForm = document.getElementById('mfaVerifyForm');
    var codeInput = document.getElementById('code');
    var resendButton = document.getElementById('mfaResendBtn');
    var helperText = document.getElementById('mfaHelperText');
    var statusBox = document.getElementById('mfaStatus');
    if (!verifyForm || !codeInput) return;

    function setStatus(type, message) {
        if (!statusBox) return;
        statusBox.className = 'alert mfa-status is-visible alert-' + type;
        statusBox.textContent = message || '';
    }

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

    if (verifyForm.dataset.autoIssue === '1') {
        if (resendButton) {
            resendButton.disabled = true;
        }
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({
                csrf_token: verifyForm.querySelector('input[name="csrf_token"]').value,
                module: verifyForm.querySelector('input[name="module"]').value,
                return_to: verifyForm.querySelector('input[name="return_to"]').value,
                action: 'bootstrap'
            })
        })
        .then(function (response) {
            return response.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return {
                        success: false,
                        message: 'Unable to send verification code. Server returned: ' + String(text || ('HTTP ' + response.status)).slice(0, 180)
                    };
                }
            });
        })
        .then(function (data) {
            if (data && data.success) {
                if (data.delivery === 'local_code' || data.delivery === 'local_fallback') {
                    setStatus('success', data.message || 'Verification code ready.');
                    if (helperText) {
                        helperText.innerHTML = 'Use the verification code shown above. If it expires, click <strong>Resend Code</strong>.';
                    }
                } else {
                    if (helperText) {
                        helperText.innerHTML = 'Enter the verification code sent to your email. If it expires, click <strong>Resend Code</strong>.';
                    }
                }
            } else {
                setStatus('danger', (data && data.message) ? data.message : 'Unable to send verification code.');
                if (helperText) {
                    helperText.innerHTML = 'Use <strong>Resend Code</strong> to request a new verification email.';
                }
            }
        })
        .catch(function (error) {
            setStatus('danger', 'Unable to send verification code right now: ' + ((error && error.message) ? error.message : 'request failed') + '. Please use Resend Code.');
            if (helperText) {
                helperText.innerHTML = 'Use <strong>Resend Code</strong> to request a new verification email.';
            }
        })
        .finally(function () {
            if (resendButton) {
                resendButton.disabled = false;
            }
            codeInput.focus();
        });
    }
});
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js?v=<?php echo urlencode((string)APP_VERSION); ?>" defer></script>
</body>
</html>
