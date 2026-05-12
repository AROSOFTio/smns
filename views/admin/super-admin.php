<?php
/**
 * Local first-run super admin bootstrap.
 *
 * This page is intentionally available only from localhost/private machine setup.
 * It creates or repairs an admin account, stores it as settings.super_admin_user_id,
 * grants current privacy consent, and starts an admin session.
 */
require_once __DIR__ . '/../../config.php';

$session = new Session('admin');

function smnsBootstrapIsLocalRequest() {
    if (php_sapi_name() === 'cli') {
        return true;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostOnly = $host;
    if (strpos($hostOnly, ':') !== false) {
        $hostOnly = substr($hostOnly, 0, (int)strpos($hostOnly, ':'));
    }

    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    $remoteAddr = strtolower((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $serverAddr = strtolower((string)($_SERVER['SERVER_ADDR'] ?? ''));
    $locals = ['localhost', '127.0.0.1', '::1'];

    return in_array($hostOnly, $locals, true)
        || in_array($serverName, $locals, true)
        || in_array($remoteAddr, $locals, true)
        || in_array($serverAddr, $locals, true);
}

function smnsBootstrapColumnExists(PDO $conn, $table, $column) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
        $stmt->execute(['column_name' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function smnsBootstrapEnsureSettingsTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT NULL,
            category VARCHAR(50) NULL,
            description TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function smnsBootstrapSaveSetting(PDO $conn, $key, $value, $category, $description) {
    smnsBootstrapEnsureSettingsTable($conn);
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value, category, description)
        VALUES (:setting_key, :setting_value, :category, :description)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            category = VALUES(category),
            description = VALUES(description)
    ");
    $stmt->execute([
        'setting_key' => $key,
        'setting_value' => $value,
        'category' => $category,
        'description' => $description
    ]);
}

function smnsBootstrapFindConfiguredSuperAdmin(PDO $conn) {
    try {
        smnsBootstrapEnsureSettingsTable($conn);
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.email, u.status,
                   a.first_name, a.last_name, a.phone
            FROM settings s
            INNER JOIN users u ON u.id = CAST(s.setting_value AS UNSIGNED)
            LEFT JOIN admins a ON a.user_id = u.id
            WHERE s.setting_key = 'super_admin_user_id'
              AND u.role = 'admin'
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function smnsBootstrapAdminProfile(PDO $conn, $userId, $firstName, $lastName, $phone, $email) {
    $stmt = $conn->prepare("SELECT id FROM admins WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    if ($stmt->fetchColumn()) {
        $update = $conn->prepare("
            UPDATE admins
            SET first_name = :first_name,
                last_name = :last_name,
                phone = :phone,
                email = :email
            WHERE user_id = :user_id
        ");
        $update->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'email' => $email,
            'user_id' => $userId
        ]);
        return;
    }

    $insert = $conn->prepare("
        INSERT INTO admins (user_id, first_name, last_name, phone, email)
        VALUES (:user_id, :first_name, :last_name, :phone, :email)
    ");
    $insert->execute([
        'user_id' => $userId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone,
        'email' => $email
    ]);
}

function smnsBootstrapGrantPrivacyConsent(PDO $conn, $userId) {
    try {
        $consent = new PrivacyConsentService($conn);
        $consent->grantCurrent(
            (int)$userId,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        );
    } catch (Exception $e) {
    }
}

function smnsBootstrapStartAdminSession(PDO $conn, array $user) {
    $stmt = $conn->prepare("SELECT * FROM admins WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => (int)$user['id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    session_regenerate_id(true);
    foreach (array_keys($_SESSION) as $key) {
        if (strpos((string)$key, 'admin_') === 0) {
            unset($_SESSION[$key]);
        }
    }

    $_SESSION['admin_user_id'] = (int)$user['id'];
    $_SESSION['admin_username'] = (string)$user['username'];
    $_SESSION['admin_email'] = (string)$user['email'];
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_primary_role'] = 'admin';
    $_SESSION['admin_profile'] = $profile;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_login_time'] = time();
    $_SESSION['admin_session_token'] = bin2hex(random_bytes(32));
    $_SESSION['sso_logged_in'] = true;
    $_SESSION['sso_user_id'] = (int)$user['id'];
    $_SESSION['sso_username'] = (string)$user['username'];
    $_SESSION['sso_email'] = (string)$user['email'];
    $_SESSION['sso_primary_role'] = 'admin';
    $_SESSION['sso_access_modules'] = ['admin'];
    $_SESSION['sso_last_login_at'] = time();

    try {
        $update = $conn->prepare("UPDATE users SET last_login = NOW(), failed_login_attempts = 0, account_locked_until = NULL WHERE id = :id");
        $update->execute(['id' => (int)$user['id']]);
    } catch (Exception $e) {
    }
}

$isLocalRequest = smnsBootstrapIsLocalRequest();
$configuredSuperAdmin = null;
$error = '';
$success = '';
$form = [
    'username' => 'superadmin',
    'email' => 'superadmin@localhost.test',
    'first_name' => 'Super',
    'last_name' => 'Admin',
    'phone' => '0000000000'
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    $configuredSuperAdmin = smnsBootstrapFindConfiguredSuperAdmin($conn);
    if ($configuredSuperAdmin) {
        $form['username'] = (string)$configuredSuperAdmin['username'];
        $form['email'] = (string)$configuredSuperAdmin['email'];
        $form['first_name'] = (string)($configuredSuperAdmin['first_name'] ?: 'Super');
        $form['last_name'] = (string)($configuredSuperAdmin['last_name'] ?: 'Admin');
        $form['phone'] = (string)($configuredSuperAdmin['phone'] ?: '0000000000');
    }
} catch (Exception $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLocalRequest) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Refresh and try again.';
    } else {
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $form['email'] = trim((string)($_POST['email'] ?? ''));
        $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
        $form['last_name'] = trim((string)($_POST['last_name'] ?? ''));
        $form['phone'] = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($form['username'] === '') {
            $errors[] = 'Username is required.';
        }
        if (!Security::validateEmail($form['email'])) {
            $errors[] = 'Valid email is required.';
        }
        if ($form['first_name'] === '' || $form['last_name'] === '') {
            $errors[] = 'First name and last name are required.';
        }
        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        } else {
            $policyErrors = [];
            if (!Security::validatePasswordPolicy($password, $policyErrors)) {
                $errors = array_merge($errors, $policyErrors);
            }
        }

        if ($errors) {
            $error = implode(' ', $errors);
        } else {
            try {
                $conn->beginTransaction();

                $existing = $conn->prepare("SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1");
                $existing->execute([
                    'username' => $form['username'],
                    'email' => $form['email']
                ]);
                $user = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $hasRequirePasswordChange = smnsBootstrapColumnExists($conn, 'users', 'require_password_change');

                if ($user && (string)$user['role'] !== 'admin') {
                    throw new Exception('That username or email belongs to a non-admin account. Choose a different one.');
                }

                if ($user) {
                    $sql = "
                        UPDATE users
                        SET username = :username,
                            email = :email,
                            password_hash = :password_hash,
                            role = 'admin',
                            status = 'active',
                            failed_login_attempts = 0,
                            account_locked_until = NULL
                    ";
                    if ($hasRequirePasswordChange) {
                        $sql .= ", require_password_change = 0";
                    }
                    $sql .= " WHERE id = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        'username' => $form['username'],
                        'email' => $form['email'],
                        'password_hash' => $passwordHash,
                        'id' => (int)$user['id']
                    ]);
                    $userId = (int)$user['id'];
                } else {
                    if ($hasRequirePasswordChange) {
                        $stmt = $conn->prepare("
                            INSERT INTO users (username, email, password_hash, role, status, require_password_change, created_at)
                            VALUES (:username, :email, :password_hash, 'admin', 'active', 0, NOW())
                        ");
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO users (username, email, password_hash, role, status, created_at)
                            VALUES (:username, :email, :password_hash, 'admin', 'active', NOW())
                        ");
                    }
                    $stmt->execute([
                        'username' => $form['username'],
                        'email' => $form['email'],
                        'password_hash' => $passwordHash
                    ]);
                    $userId = (int)$conn->lastInsertId();
                }

                smnsBootstrapAdminProfile($conn, $userId, $form['first_name'], $form['last_name'], $form['phone'], $form['email']);
                smnsBootstrapSaveSetting(
                    $conn,
                    'super_admin_user_id',
                    (string)$userId,
                    'security',
                    'User ID allowed to perform super-admin-only safeguards.'
                );
                smnsBootstrapGrantPrivacyConsent($conn, $userId);

                $conn->commit();

                $fresh = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
                $fresh->execute(['id' => $userId]);
                $freshUser = $fresh->fetch(PDO::FETCH_ASSOC);
                if ($freshUser) {
                    smnsBootstrapStartAdminSession($conn, $freshUser);
                }

                setFlash('success', 'Super admin account is ready. You are signed in.');
                header('Location: ' . BASE_URL . '/views/admin/users/add.php');
                exit;
            } catch (Exception $e) {
                if ($conn && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Bootstrap - <?php echo e(APP_NAME); ?></title>
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            background: #eef2f7;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #172033;
        }
        .bootstrap-shell {
            width: 100%;
            max-width: 760px;
            background: #fff;
            border: 1px solid #d7dee8;
            border-radius: 8px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }
        .bootstrap-head {
            padding: 24px 28px;
            background: #111827;
            color: #fff;
        }
        .bootstrap-head h1 {
            font-size: 24px;
            margin: 0 0 6px;
        }
        .bootstrap-head p {
            margin: 0;
            color: #cbd5e1;
        }
        .bootstrap-body {
            padding: 28px;
        }
        .status-line {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px 14px;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin-bottom: 18px;
        }
        .status-line i {
            margin-top: 3px;
        }
        .form-control {
            min-height: 44px;
        }
        .btn-primary {
            background: #0f766e;
            border-color: #0f766e;
        }
        .btn-primary:hover {
            background: #115e59;
            border-color: #115e59;
        }
        .small-note {
            color: #64748b;
            font-size: 13px;
        }
        .theme-toggle {
            position: fixed;
            right: 16px;
            bottom: 16px;
            width: 40px;
            height: 40px;
            border: 1px solid #d7dee8;
            border-radius: 10px;
            background: #ffffff;
            color: #172033;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .theme-toggle:focus {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        html[data-theme='dark'] body {
            background: #0b1220;
            color: #e5e7eb;
        }
        html[data-theme='dark'] .bootstrap-shell {
            background: #111827;
            border-color: #334155;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.34);
        }
        html[data-theme='dark'] .bootstrap-head {
            background: #020617;
        }
        html[data-theme='dark'] .bootstrap-head p,
        html[data-theme='dark'] .small-note {
            color: #9ca3af;
        }
        html[data-theme='dark'] .status-line,
        html[data-theme='dark'] .form-control {
            background: #1f2937;
            border-color: #334155;
            color: #e5e7eb;
        }
        html[data-theme='dark'] label,
        html[data-theme='dark'] strong {
            color: #f8fafc;
        }
        html[data-theme='dark'] .theme-toggle {
            background: #111827;
            border-color: #334155;
            color: #e5e7eb;
        }
    </style>
</head>
<body>
    <button id="superAdminThemeToggle" class="theme-toggle" type="button" title="Switch to dark mode" aria-label="Switch to dark mode">
        <i class="fas fa-moon" aria-hidden="true"></i>
    </button>
    <main class="bootstrap-shell">
        <section class="bootstrap-head">
            <h1><i class="fas fa-user-shield"></i> Super Admin Bootstrap</h1>
            <p>Create or repair the first super admin account for this machine.</p>
        </section>
        <section class="bootstrap-body">
            <?php if (!$isLocalRequest): ?>
                <div class="alert alert-danger mb-0">
                    This bootstrap page is only available from the local machine.
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <div class="status-line">
                    <i class="fas fa-info-circle text-info"></i>
                    <div>
                        <?php if ($configuredSuperAdmin): ?>
                            Current super admin is <strong><?php echo e($configuredSuperAdmin['username']); ?></strong>
                            (user ID <?php echo (int)$configuredSuperAdmin['id']; ?>). Submitting this form will reset that account and sign you in.
                        <?php else: ?>
                            No configured super admin was found. Submit the form to create one and sign in.
                        <?php endif; ?>
                        <div class="small-note mt-1">Use this after moving the system to another machine or restoring the database when admin login is blocked.</div>
                        <div class="mt-3">
                            <a class="btn btn-sm btn-outline-primary mr-2" href="<?php echo e(BASE_URL . '/views/admin/dashboard.php'); ?>">
                                <i class="fas fa-tachometer-alt"></i> Admin Dashboard
                            </a>
                            <a class="btn btn-sm btn-outline-dark" href="<?php echo e(BASE_URL . '/views/admin/users/add.php'); ?>">
                                <i class="fas fa-users-cog"></i> Manage Users
                            </a>
                        </div>
                    </div>
                </div>

                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="username">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo e($form['username']); ?>" required autocomplete="username">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo e($form['email']); ?>" required autocomplete="email">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="first_name">First name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e($form['first_name']); ?>" required autocomplete="given-name">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="last_name">Last name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo e($form['last_name']); ?>" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($form['phone']); ?>" autocomplete="tel">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="password">New password</label>
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="confirm_password">Confirm password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-key"></i> Save Super Admin and Sign In
                    </button>
                </form>

                <div class="text-center mt-3">
                    <a href="<?php echo e(BASE_URL . '/views/auth/login.php?role=admin'); ?>">Go to normal login</a>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
        (function () {
            var STORAGE_KEY = 'smns_theme_mode';
            var root = document.documentElement;
            var btn = document.getElementById('superAdminThemeToggle');

            function applyTheme(mode) {
                if (mode === 'dark') {
                    root.setAttribute('data-theme', 'dark');
                    if (btn) {
                        btn.innerHTML = '<i class="fas fa-sun" aria-hidden="true"></i>';
                        btn.setAttribute('title', 'Switch to light mode');
                        btn.setAttribute('aria-label', 'Switch to light mode');
                    }
                    return;
                }

                root.removeAttribute('data-theme');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-moon" aria-hidden="true"></i>';
                    btn.setAttribute('title', 'Switch to dark mode');
                    btn.setAttribute('aria-label', 'Switch to dark mode');
                }
            }

            var initial = 'light';
            try {
                var saved = localStorage.getItem(STORAGE_KEY);
                if (saved === 'dark' || saved === 'light') {
                    initial = saved;
                }
            } catch (e) {}
            applyTheme(initial);

            if (btn) {
                btn.addEventListener('click', function () {
                    var current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    var next = current === 'dark' ? 'light' : 'dark';
                    try {
                        localStorage.setItem(STORAGE_KEY, next);
                    } catch (e) {}
                    applyTheme(next);
                });
            }
        })();
    </script>
</body>
</html>
