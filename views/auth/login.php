<?php
require_once '../../config.php';

$session = new Session();
$error = '';
$success = '';
$logoutModule = strtolower(trim((string)($_GET['module'] ?? '')));
$step = 1;
$enteredUsername = '';
$matchedModule = '';
$profileName = '';

$moduleLabels = [
    'admin' => 'Admin',
    'student' => 'Student',
    'lecturer' => 'Lecturer',
    'finance' => 'Finance'
];

if (isset($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$incomingError = strtolower(trim((string)($_GET['error'] ?? '')));
if ($incomingError === 'session_expired') {
    $error = 'Your session expired due to inactivity. Please login again.';
}

if (!empty($_GET['action']) && $_GET['action'] === 'logout') {
    if (isset($moduleLabels[$logoutModule])) {
        $_SESSION['flash_success'] = 'You logged out from ' . $moduleLabels[$logoutModule] . '.';
    } else {
        $_SESSION['flash_success'] = 'You have been logged out successfully.';
    }
    header('Location: ' . BASE_URL . '/views/auth/login.php');
    exit;
}

function smnsDetectLoggedModules() {
    $modules = ['admin', 'student', 'lecturer', 'finance'];
    $active = [];
    foreach ($modules as $module) {
        $auth = new Auth($module);
        if ($auth->isLoggedIn()) {
            $active[] = $module;
        }
    }
    return $active;
}

function smnsDashboardUrl($module) {
    $map = [
        'admin' => BASE_URL . '/views/admin/dashboard.php',
        'student' => BASE_URL . '/views/student/dashboard.php',
        'lecturer' => BASE_URL . '/views/lecturer/dashboard.php',
        'finance' => BASE_URL . '/views/finance/dashboard.php'
    ];
    return $map[$module] ?? (BASE_URL . '/views/auth/module-hub.php');
}

function smnsResolvePostLoginTarget($preferredModule = '') {
    $preferredModule = strtolower(trim((string)$preferredModule));
    $loggedModules = smnsDetectLoggedModules();
    if ($preferredModule !== '' && in_array($preferredModule, $loggedModules, true)) {
        return smnsDashboardUrl($preferredModule);
    }
    if (count($loggedModules) === 1) {
        return smnsDashboardUrl($loggedModules[0]);
    }
    return BASE_URL . '/views/auth/module-hub.php';
}

function smnsProbeIdentity($username) {
    $username = trim((string)$username);
    if ($username === '') {
        return null;
    }
    $modules = ['admin', 'student', 'lecturer', 'finance'];
    foreach ($modules as $module) {
        $probeAuth = new Auth($module);
        $probeUser = $probeAuth->usernameExists($username);
        if ($probeUser && is_array($probeUser)) {
            return ['module' => $module, 'user' => $probeUser];
        }
    }
    return null;
}

function smnsResolveProfileName($module, array $user, $fallback = '') {
    $fallbackName = trim((string)$fallback);
    if ($fallbackName === '') {
        $fallbackName = trim((string)($user['username'] ?? ''));
    }
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return $fallbackName;
    }
    $tableMap = [
        'admin' => ['table' => 'admins', 'id_col' => 'user_id'],
        'student' => ['table' => 'students', 'id_col' => 'user_id'],
        'lecturer' => ['table' => 'lecturers', 'id_col' => 'user_id'],
        'finance' => ['table' => 'finance_staff', 'id_col' => 'user_id']
    ];
    $cfg = $tableMap[$module] ?? null;
    if (!$cfg) {
        return $fallbackName;
    }
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("
            SELECT first_name, last_name, fullname, name
            FROM {$cfg['table']}
            WHERE {$cfg['id_col']} = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string)($profile['first_name'] ?? '') . ' ' . (string)($profile['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string)($profile['fullname'] ?? ($profile['name'] ?? '')));
        if ($name !== '') {
            return $name;
        }
    } catch (Exception $e) {
    }
    return $fallbackName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $step = (int)($_POST['step'] ?? 1);
        $enteredUsername = trim((string)($_POST['username'] ?? ''));
        $matchedModule = strtolower(trim((string)($_POST['matched_module'] ?? '')));

        if ($step <= 1) {
            if ($enteredUsername === '') {
                $error = 'Username, email, student ID, or lecturer ID is required.';
                $step = 1;
            } else {
                $probe = smnsProbeIdentity($enteredUsername);
                if (!$probe) {
                    $error = 'Invalid credentials.';
                    $step = 1;
                } else {
                    $matchedModule = (string)$probe['module'];
                    $profileName = smnsResolveProfileName($matchedModule, (array)$probe['user'], $enteredUsername);
                    $step = 2;
                }
            }
        } else {
            $password = (string)($_POST['password'] ?? '');
            if ($enteredUsername === '') {
                $error = 'Session expired. Enter your username again.';
                $step = 1;
                $matchedModule = '';
            } elseif ($password === '') {
                $error = 'Password is required.';
                $probe = smnsProbeIdentity($enteredUsername);
                if ($probe) {
                    $matchedModule = (string)$probe['module'];
                    $profileName = smnsResolveProfileName($matchedModule, (array)$probe['user'], $enteredUsername);
                    $step = 2;
                } else {
                    $step = 1;
                    $matchedModule = '';
                }
            } else {
                try {
                    if (!in_array($matchedModule, ['admin', 'student', 'lecturer', 'finance'], true)) {
                        $probe = smnsProbeIdentity($enteredUsername);
                        $matchedModule = $probe ? (string)$probe['module'] : '';
                    }
                    if ($matchedModule === '') {
                        $error = 'Invalid credentials.';
                        $step = 1;
                    } else {
                        $auth = new Auth($matchedModule);
                        $result = $auth->login($enteredUsername, $password);

                        if (!empty($result['mfa_required'])) {
                            header('Location: ' . BASE_URL . '/views/auth/mfa-verify.php?module=' . urlencode($matchedModule));
                            exit;
                        }
                        if (!empty($result['consent_required'])) {
                            header('Location: ' . BASE_URL . '/views/auth/privacy-consent.php?module=' . urlencode($matchedModule));
                            exit;
                        }
                        if (!empty($result['success'])) {
                            header('Location: ' . smnsResolvePostLoginTarget($matchedModule));
                            exit;
                        }
                        $error = (string)($result['message'] ?? 'Login failed.');
                        $probe = smnsProbeIdentity($enteredUsername);
                        if ($probe) {
                            $profileName = smnsResolveProfileName((string)$probe['module'], (array)$probe['user'], $enteredUsername);
                            $step = 2;
                        } else {
                            $step = 1;
                            $matchedModule = '';
                        }
                    }
                } catch (Exception $e) {
                    error_log('Unified login failure: ' . $e->getMessage());
                    $error = 'Unable to login right now.';
                    $step = 1;
                    $matchedModule = '';
                }
            }
        }
    }
}

$loggedModules = smnsDetectLoggedModules();
if (!empty($loggedModules)) {
    header('Location: ' . smnsResolvePostLoginTarget());
    exit;
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
    <title>Unified Login - <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <style>
        body {
            background: url('../../assets/img/seminary.jpeg') no-repeat center center fixed;
            background-size: cover;
        }
        .login-container {
            max-width: 320px !important;
            width: min(320px, calc(100% - 24px)) !important;
            margin: 14px auto !important;
        }
        .login-card.unified-theme {
            padding: 18px 14px !important;
            border-radius: 12px;
        }
        .login-header .logo {
            max-width: 64px !important;
            margin-bottom: 6px !important;
        }
        .login-header h2 {
            font-size: 1.35rem;
            margin-bottom: 8px;
        }
        .login-card.unified-theme::before {
            background: linear-gradient(90deg, #0ea5e9, #2563eb);
        }
        .login-card.unified-theme .btn-primary {
            background: linear-gradient(135deg, #1d4ed8, #2563eb) !important;
            border-color: #1d4ed8 !important;
        }
        .login-card.unified-theme .btn-primary:hover,
        .login-card.unified-theme .btn-primary:focus {
            background: linear-gradient(135deg, #1e40af, #1d4ed8) !important;
            border-color: #1e40af !important;
        }
        .module-chip-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            margin-top: 8px;
        }
        .module-chip {
            border-radius: 8px;
            border: 1px solid #dbeafe;
            background: #f8fafc;
            font-size: 10px;
            font-weight: 600;
            color: #334155;
            padding: 6px 7px;
            text-align: left;
        }
        .module-chip .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            vertical-align: middle;
        }
        .module-chip .label {
            vertical-align: middle;
        }
        .u-info {
            font-size: 12px;
            color: #64748b;
            margin-top: 10px;
            margin-bottom: 0;
        }
        html[data-theme='dark'] .module-chip {
            background: #0f172a;
            border-color: #334155;
            color: #cbd5e1;
        }
        html[data-theme='dark'] .u-info {
            color: #94a3b8;
        }
        html[data-theme='dark'] .login-card.unified-theme::before {
            background: linear-gradient(90deg, #0284c7, #1d4ed8);
        }
        @media (max-width: 576px) {
            .login-container {
                max-width: 320px !important;
                width: calc(100% - 16px) !important;
                margin: 10px auto !important;
            }
            .login-card.unified-theme {
                padding: 16px 12px !important;
            }
        }
    </style>
</head>
<body style="background: url('../../assets/img/seminary.jpeg') no-repeat center center fixed; background-size: cover;">
<div class="login-container">
    <div class="login-card unified-theme">
        <div class="login-header">
            <img src="../../assets/img/sem.PNG" alt="Logo" class="logo mb-2">
            <h2><?php echo e(APP_SHORT_NAME); ?></h2>
            <span class="role-badge">Login</span>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success auto-dismiss-alert"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger auto-dismiss-alert"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form" autocomplete="on">
            <?php echo csrfField(); ?>
            <input type="hidden" name="step" value="<?php echo (int)$step; ?>">
            <input type="hidden" name="matched_module" value="<?php echo e($matchedModule); ?>">
            <?php if ($step === 1): ?>
                <div class="form-group">
                    <label class="mb-1">Username</label>
                    <div class="input-wrapper">
                        <input
                            type="text"
                            name="username"
                            class="form-control"
                            value="<?php echo e($enteredUsername); ?>"
                            placeholder="Enter username"
                            required
                            autofocus
                        >
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    Next
                </button>
            <?php else: ?>
                <input type="hidden" name="username" value="<?php echo e($enteredUsername); ?>">
                <div class="form-group text-center mb-3">
                    <span class="badge badge-info" style="font-size:14px;padding:8px 20px;">
                        Welcome, <?php echo e($profileName !== '' ? $profileName : $enteredUsername); ?>
                    </span>
                </div>
                <div class="form-group">
                    <label class="mb-1">Password</label>
                    <div class="input-wrapper" style="position:relative;">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            required
                            autofocus
                        >
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="position:absolute; right:10px; top:50%; transform:translateY(-50%);" onclick="togglePassword('password', this)">Show</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
                <div class="text-center mt-3">
                    <a href="<?php echo e(BASE_URL . '/views/auth/login.php'); ?>" class="btn btn-link btn-sm">
                        <i class="fas fa-arrow-left"></i> Not you? Use different account
                    </a>
                </div>
            <?php endif; ?>
        </form>

        <div class="module-chip-grid">
            <div class="module-chip"><span class="dot" style="background:#dc3545;"></span><span class="label">Admin</span></div>
            <div class="module-chip"><span class="dot" style="background:#007bff;"></span><span class="label">Student</span></div>
            <div class="module-chip"><span class="dot" style="background:#28a745;"></span><span class="label">Lecturer</span></div>
            <div class="module-chip"><span class="dot" style="background:#ffc107;"></span><span class="label">Finance</span></div>
        </div>
    </div>
</div>
<script src="../../assets/js/login-theme.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var alerts = document.querySelectorAll('.auto-dismiss-alert');
    if (!alerts.length) return;
    setTimeout(function () {
        alerts.forEach(function (el) {
            el.style.transition = 'opacity 0.25s ease';
            el.style.opacity = '0';
            setTimeout(function () {
                if (el && el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 260);
        });
    }, 3500);
});
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
