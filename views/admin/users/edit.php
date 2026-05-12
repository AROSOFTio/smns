<?php
/**
 * Manage one system user across all modules.
 */
require_once __DIR__ . '/../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash_error'] = 'Session expired. Please login again.';
    }
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=session_expired&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();
$userId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    setFlash('error', 'Invalid user ID.');
    header('Location: add.php');
    exit;
}

function smnsUserEditColumnExists(PDO $conn, $table, $column) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
        $stmt->execute(['column_name' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function smnsUserEditFetchUser(PDO $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT
            u.*,
            a.id AS admin_profile_id,
            a.first_name AS admin_first_name,
            a.last_name AS admin_last_name,
            a.phone AS admin_phone,
            a.email AS admin_profile_email,
            l.id AS lecturer_profile_id,
            l.first_name AS lecturer_first_name,
            l.last_name AS lecturer_last_name,
            l.phone AS lecturer_phone,
            l.email AS lecturer_profile_email,
            l.lecturer_id,
            l.department AS lecturer_department,
            l.status AS lecturer_status,
            s.id AS student_profile_id,
            s.first_name AS student_first_name,
            s.middle_name AS student_middle_name,
            s.last_name AS student_last_name,
            s.phone AS student_phone,
            s.email AS student_profile_email,
            s.student_id,
            s.status AS student_status,
            f.id AS finance_profile_id,
            f.first_name AS finance_first_name,
            f.last_name AS finance_last_name,
            f.phone AS finance_phone,
            f.email AS finance_profile_email
        FROM users u
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN lecturers l ON l.user_id = u.id
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN finance_staff f ON f.user_id = u.id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function smnsUserEditProfileValues(array $user) {
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        return [
            'first_name' => (string)($user['admin_first_name'] ?? ''),
            'middle_name' => '',
            'last_name' => (string)($user['admin_last_name'] ?? ''),
            'phone' => (string)($user['admin_phone'] ?? ''),
            'profile_email' => (string)($user['admin_profile_email'] ?? ''),
            'identifier' => 'Admin #' . (int)($user['admin_profile_id'] ?? 0),
            'extra_label' => '',
            'extra_value' => ''
        ];
    }
    if ($role === 'lecturer') {
        return [
            'first_name' => (string)($user['lecturer_first_name'] ?? ''),
            'middle_name' => '',
            'last_name' => (string)($user['lecturer_last_name'] ?? ''),
            'phone' => (string)($user['lecturer_phone'] ?? ''),
            'profile_email' => (string)($user['lecturer_profile_email'] ?? ''),
            'identifier' => (string)($user['lecturer_id'] ?? ''),
            'extra_label' => 'Department',
            'extra_value' => (string)($user['lecturer_department'] ?? '')
        ];
    }
    if ($role === 'student') {
        return [
            'first_name' => (string)($user['student_first_name'] ?? ''),
            'middle_name' => (string)($user['student_middle_name'] ?? ''),
            'last_name' => (string)($user['student_last_name'] ?? ''),
            'phone' => (string)($user['student_phone'] ?? ''),
            'profile_email' => (string)($user['student_profile_email'] ?? ''),
            'identifier' => (string)($user['student_id'] ?? ''),
            'extra_label' => '',
            'extra_value' => ''
        ];
    }
    if ($role === 'finance') {
        return [
            'first_name' => (string)($user['finance_first_name'] ?? ''),
            'middle_name' => '',
            'last_name' => (string)($user['finance_last_name'] ?? ''),
            'phone' => (string)($user['finance_phone'] ?? ''),
            'profile_email' => (string)($user['finance_profile_email'] ?? ''),
            'identifier' => 'Finance #' . (int)($user['finance_profile_id'] ?? 0),
            'extra_label' => '',
            'extra_value' => ''
        ];
    }
    return [
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'phone' => '',
        'profile_email' => '',
        'identifier' => '-',
        'extra_label' => '',
        'extra_value' => ''
    ];
}

function smnsUserEditEnsureProfile(PDO $conn, array $user, array $values) {
    $role = (string)$user['role'];
    $params = [
        'user_id' => (int)$user['id'],
        'first_name' => $values['first_name'],
        'last_name' => $values['last_name'],
        'phone' => $values['phone'],
        'email' => $values['email']
    ];

    if ($role === 'admin') {
        $exists = !empty($user['admin_profile_id']);
        if ($exists) {
            $stmt = $conn->prepare("UPDATE admins SET first_name = :first_name, last_name = :last_name, phone = :phone, email = :email WHERE user_id = :user_id");
            $stmt->execute($params);
            return;
        }
        $stmt = $conn->prepare("INSERT INTO admins (user_id, first_name, last_name, phone, email) VALUES (:user_id, :first_name, :last_name, :phone, :email)");
        $stmt->execute($params);
        return;
    }

    if ($role === 'finance') {
        $exists = !empty($user['finance_profile_id']);
        if ($exists) {
            $stmt = $conn->prepare("UPDATE finance_staff SET first_name = :first_name, last_name = :last_name, phone = :phone, email = :email WHERE user_id = :user_id");
            $stmt->execute($params);
            return;
        }
        $stmt = $conn->prepare("INSERT INTO finance_staff (user_id, first_name, last_name, phone, email) VALUES (:user_id, :first_name, :last_name, :phone, :email)");
        $stmt->execute($params);
        return;
    }

    if ($role === 'lecturer') {
        $exists = !empty($user['lecturer_profile_id']);
        $params['department'] = $values['extra_value'] !== '' ? $values['extra_value'] : 'General';
        $params['status'] = $values['profile_status'];
        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE lecturers
                SET first_name = :first_name, last_name = :last_name, phone = :phone, email = :email,
                    department = :department, status = :status, updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute($params);
            return;
        }
        $params['lecturer_id'] = 'LEC' . str_pad((string)$user['id'], 3, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("
            INSERT INTO lecturers (user_id, lecturer_id, first_name, last_name, phone, email, department, status)
            VALUES (:user_id, :lecturer_id, :first_name, :last_name, :phone, :email, :department, :status)
        ");
        $stmt->execute($params);
        return;
    }

    if ($role === 'student') {
        $exists = !empty($user['student_profile_id']);
        $params['middle_name'] = $values['middle_name'];
        $params['status'] = $values['profile_status'];
        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE students
                SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name,
                    phone = :phone, email = :email, status = :status, updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute($params);
            return;
        }

        $studentId = function_exists('generateStudentRegistrationNumber')
            ? generateStudentRegistrationNumber($conn)
            : ('STD' . str_pad((string)$user['id'], 4, '0', STR_PAD_LEFT));
        $params['student_id'] = $studentId;
        $stmt = $conn->prepare("
            INSERT INTO students (user_id, student_id, first_name, middle_name, last_name, phone, email, program_id, level_year, entry_year, status)
            VALUES (:user_id, :student_id, :first_name, :middle_name, :last_name, :phone, :email, 1, 1, :entry_year, :status)
        ");
        $params['entry_year'] = date('Y');
        $stmt->execute($params);
    }
}

$user = smnsUserEditFetchUser($conn, $userId);
if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: add.php');
    exit;
}

$profile = smnsUserEditProfileValues($user);
$errors = [];
$success = '';
$role = (string)$user['role'];
$statusOptions = ['active', 'inactive', 'suspended'];
$profileStatusOptionsByRole = [
    'student' => ['active', 'graduated', 'withdrawn', 'suspended'],
    'lecturer' => ['active', 'inactive', 'retired']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Refresh and try again.';
    } else {
        $username = trim(Security::sanitize($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $status = trim(Security::sanitize($_POST['status'] ?? 'active'));
        $firstName = trim(Security::sanitize($_POST['first_name'] ?? ''));
        $middleName = trim(Security::sanitize($_POST['middle_name'] ?? ''));
        $lastName = trim(Security::sanitize($_POST['last_name'] ?? ''));
        $phone = trim(Security::sanitize($_POST['phone'] ?? ''));
        $extraValue = trim(Security::sanitize($_POST['extra_value'] ?? ''));
        $profileStatus = trim(Security::sanitize($_POST['profile_status'] ?? 'active'));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $forcePasswordChange = isset($_POST['force_password_change']) ? 1 : 0;

        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if (!Security::validateEmail($email)) {
            $errors[] = 'A valid email is required.';
        }
        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First name and last name are required.';
        }
        if (!in_array($status, $statusOptions, true)) {
            $errors[] = 'Invalid account status.';
        }
        $profileStatusOptions = $profileStatusOptionsByRole[$role] ?? ['active'];
        if (!in_array($profileStatus, $profileStatusOptions, true)) {
            $profileStatus = 'active';
        }

        if ($newPassword !== '' || $confirmPassword !== '') {
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New password and confirmation do not match.';
            } else {
                $policyErrors = [];
                if (!Security::validatePasswordPolicy($newPassword, $policyErrors)) {
                    $errors = array_merge($errors, $policyErrors);
                }
            }
        }

        if (!$errors) {
            $dup = $conn->prepare("SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :id LIMIT 1");
            $dup->execute([
                'username' => $username,
                'email' => $email,
                'id' => $userId
            ]);
            if ($dup->fetch()) {
                $errors[] = 'Username or email is already used by another account.';
            }
        }

        if (!$errors) {
            try {
                $conn->beginTransaction();
                $hasRequirePasswordChange = smnsUserEditColumnExists($conn, 'users', 'require_password_change');
                $userSql = "
                    UPDATE users
                    SET username = :username,
                        email = :email,
                        status = :status,
                        failed_login_attempts = 0,
                        account_locked_until = NULL
                ";
                $userParams = [
                    'username' => $username,
                    'email' => $email,
                    'status' => $status,
                    'id' => $userId
                ];

                if ($newPassword !== '') {
                    $userSql .= ", password_hash = :password_hash";
                    $userParams['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    if ($hasRequirePasswordChange) {
                        $userSql .= ", require_password_change = :require_password_change";
                        $userParams['require_password_change'] = $forcePasswordChange;
                    }
                } elseif ($hasRequirePasswordChange && $forcePasswordChange) {
                    $userSql .= ", require_password_change = 1";
                }

                $userSql .= " WHERE id = :id";
                $stmt = $conn->prepare($userSql);
                $stmt->execute($userParams);

                smnsUserEditEnsureProfile($conn, $user, [
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'email' => $email,
                    'extra_value' => $extraValue,
                    'profile_status' => $profileStatus
                ]);

                try {
                    $logger = new Logger();
                    $logger->log($currentUser['id'], 'update', 'users', 'Updated user account: ' . $username);
                } catch (Exception $e) {
                }

                $conn->commit();
                setFlash('success', 'User updated successfully.');
                header('Location: edit.php?id=' . $userId);
                exit;
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $errors[] = 'Update failed: ' . $e->getMessage();
            }
        }

        $user['username'] = $username;
        $user['email'] = $email;
        $user['status'] = $status;
        $profile['first_name'] = $firstName;
        $profile['middle_name'] = $middleName;
        $profile['last_name'] = $lastName;
        $profile['phone'] = $phone;
        $profile['profile_email'] = $email;
        $profile['extra_value'] = $extraValue;
        if ($user['role'] === 'lecturer') {
            $user['lecturer_status'] = $profileStatus;
        } elseif ($user['role'] === 'student') {
            $user['student_status'] = $profileStatus;
        }
    }
}

$flashSuccess = getFlash('success');
$flashError = getFlash('error');
$profileStatusValue = 'active';
if ($role === 'lecturer') {
    $profileStatusValue = (string)($user['lecturer_status'] ?? 'active');
} elseif ($role === 'student') {
    $profileStatusValue = (string)($user['student_status'] ?? 'active');
}
$profileStatusOptions = $profileStatusOptionsByRole[$role] ?? ['active'];

$pageTitle = 'Edit User - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include __DIR__ . '/../../../includes/header.php';
?>

<?php include __DIR__ . '/../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>
                <a href="add.php" class="btn btn-link"><i class="fas fa-arrow-left"></i> Back to Manage Users</a>
                Edit User
            </h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <?php include __DIR__ . '/../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="container-fluid">
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo e($flashSuccess); ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo e($flashError); ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?></div>
            <?php endforeach; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-user-edit"></i> Account Details</h5>
                            <span class="badge badge-dark"><?php echo e(ucfirst($role)); ?></span>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <?php echo csrfField(); ?>

                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" value="<?php echo e($user['username']); ?>" required>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="email">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo e($user['email']); ?>" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label for="first_name">First name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e($profile['first_name']); ?>" required>
                                    </div>
                                    <?php if ($role === 'student'): ?>
                                        <div class="form-group col-md-4">
                                            <label for="middle_name">Middle name</label>
                                            <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo e($profile['middle_name']); ?>">
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-group <?php echo $role === 'student' ? 'col-md-4' : 'col-md-8'; ?>">
                                        <label for="last_name">Last name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo e($profile['last_name']); ?>" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="phone">Phone</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($profile['phone']); ?>">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="status">Account status</label>
                                        <select class="form-control" id="status" name="status">
                                            <?php foreach ($statusOptions as $statusOption): ?>
                                                <option value="<?php echo e($statusOption); ?>" <?php echo (string)$user['status'] === $statusOption ? 'selected' : ''; ?>>
                                                    <?php echo e(ucfirst($statusOption)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <?php if ($role === 'student' || $role === 'lecturer'): ?>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="profile_status">Profile status</label>
                                            <select class="form-control" id="profile_status" name="profile_status">
                                                <?php foreach ($profileStatusOptions as $statusOption): ?>
                                                    <option value="<?php echo e($statusOption); ?>" <?php echo $profileStatusValue === $statusOption ? 'selected' : ''; ?>>
                                                        <?php echo e(ucfirst($statusOption)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php if ($role === 'lecturer'): ?>
                                            <div class="form-group col-md-6">
                                                <label for="extra_value">Department</label>
                                                <input type="text" class="form-control" id="extra_value" name="extra_value" value="<?php echo e($profile['extra_value']); ?>">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <hr>
                                <h6><i class="fas fa-key"></i> Password</h6>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="new_password">New password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password">
                                        <small class="form-text text-muted">Leave blank to keep the existing password.</small>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="confirm_password">Confirm new password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password">
                                    </div>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="force_password_change" name="force_password_change" value="1">
                                    <label class="form-check-label" for="force_password_change">Require password change at next login</label>
                                </div>

                                <div class="d-flex flex-wrap justify-content-between align-items-center">
                                    <a href="add.php" class="btn btn-secondary mb-2"><i class="fas fa-times"></i> Cancel</a>
                                    <button type="submit" class="btn btn-primary mb-2"><i class="fas fa-save"></i> Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mt-3 mt-lg-0">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-id-card"></i> User Summary</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>User ID:</strong> <?php echo (int)$user['id']; ?></p>
                            <p><strong>Role:</strong> <?php echo e(ucfirst($role)); ?></p>
                            <p><strong>Identifier:</strong> <?php echo e($profile['identifier'] ?: '-'); ?></p>
                            <p><strong>Created:</strong> <?php echo e((string)($user['created_at'] ?? '-')); ?></p>
                            <p><strong>Last login:</strong> <?php echo e((string)($user['last_login'] ?? '-')); ?></p>
                            <p><strong>Failed attempts:</strong> <?php echo (int)($user['failed_login_attempts'] ?? 0); ?></p>
                            <?php if (!empty($user['account_locked_until'])): ?>
                                <div class="alert alert-warning mb-0">Locked until <?php echo e($user['account_locked_until']); ?>. Saving clears the lockout.</div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">Saving clears failed login attempts and account lockout.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.main-content,
.content-area,
.container-fluid {
    max-width: 100%;
    overflow-x: hidden;
}
</style>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
