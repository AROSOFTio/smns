<?php
/**
 * Admin User Management - Add User Page
 */
require_once __DIR__ . '/../../../config.php';

// Simple session handling

// Keep-alive endpoint for long form sessions (AJAX ping).
if (isset($_GET['keepalive']) && $_GET['keepalive'] === '1') {
    $session = new Session('admin');
    $auth = new Auth('admin');
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'session_expired']);
    } else {
        // Session constructor already refreshes last_activity.
        echo json_encode(['success' => true, 'message' => 'alive', 'ts' => time()]);
    }
    exit;
}


// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash_error'] = 'Session expired before saving. No user account was created.';
    }
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=session_expired&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $username = Security::sanitize($_POST['username'] ?? '');
        $email = Security::sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role = Security::sanitize($_POST['role'] ?? '');
        $firstName = Security::sanitize($_POST['first_name'] ?? '');
        $lastName = Security::sanitize($_POST['last_name'] ?? '');
        $phone = Security::sanitize($_POST['phone'] ?? '');
        
        // Validation
        $validator = new Validator($_POST);
        $minPasswordLen = defined('PASSWORD_MIN_LENGTH') ? (int)PASSWORD_MIN_LENGTH : 8;
        $validator->required('username', 'Username is required')
                  ->required('email', 'Email is required')
                  ->email('email', 'Valid email is required')
                  ->required('password', 'Password is required')
                  ->minLength('password', $minPasswordLen, 'Password must be at least ' . $minPasswordLen . ' characters')
                  ->required('confirm_password', 'Password confirmation is required')
                  ->required('role', 'Role is required')
                  ->required('first_name', 'First name is required')
                  ->required('last_name', 'Last name is required');
        
        if ($password !== $confirmPassword) {
            $validator->addError('confirm_password', 'Passwords do not match');
        }
        $policyErrors = [];
        if (!Security::validatePasswordPolicy($password, $policyErrors)) {
            foreach ($policyErrors as $policyError) {
                $validator->addError('password', $policyError);
            }
        }
        
        if (!in_array($role, ['admin', 'lecturer', 'student', 'finance'])) {
            $validator->addError('role', 'Invalid role selected');
        }
        
        if ($validator->passed()) {
            $conn = null;
            try {
                $db = new Database();
                $conn = $db->getConnection();
                $conn->beginTransaction();

                // Check if username or email already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);

                if ($stmt->fetch()) {
                    $conn->rollBack();
                    $error = 'Username or email already exists';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $requireChange = in_array($role, ['lecturer', 'finance'], true) ? 1 : 0;

                    // Use require_password_change only when column exists.
                    $hasRequirePasswordChange = false;
                    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'require_password_change'")->fetch();
                    if ($col) {
                        $hasRequirePasswordChange = true;
                    }

                    if ($hasRequirePasswordChange) {
                        $stmt = $conn->prepare("
                            INSERT INTO users (username, email, password_hash, role, status, require_password_change, created_at) 
                            VALUES (?, ?, ?, ?, 'active', ?, NOW())
                        ");
                        $stmt->execute([$username, $email, $passwordHash, $role, $requireChange]);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO users (username, email, password_hash, role, status, created_at) 
                            VALUES (?, ?, ?, ?, 'active', NOW())
                        ");
                        $stmt->execute([$username, $email, $passwordHash, $role]);
                    }

                    $userId = (int)$conn->lastInsertId();
                    $accountIdentifier = '-';
                    $accountIdLabel = 'Account ID';

                    // Create role-specific profile
                    switch ($role) {
                        case 'admin':
                            $stmt = $conn->prepare("
                                INSERT INTO admins (user_id, first_name, last_name, phone, email) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$userId, $firstName, $lastName, $phone, $email]);
                            break;
                        case 'lecturer':
                            $lecturerId = 'LEC' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT);
                            $accountIdentifier = $lecturerId;
                            $accountIdLabel = 'Lecturer ID';
                            $stmt = $conn->prepare("
                                INSERT INTO lecturers (user_id, lecturer_id, first_name, last_name, phone, email, department, status) 
                                VALUES (?, ?, ?, ?, ?, ?, 'General', 'active')
                            ");
                            $stmt->execute([$userId, $lecturerId, $firstName, $lastName, $phone, $email]);
                            break;
                        case 'student':
                            $studentId = 'STD' . date('Y') . str_pad((string)$userId, 3, '0', STR_PAD_LEFT);
                            $accountIdentifier = $studentId;
                            $accountIdLabel = 'Student ID';
                            $stmt = $conn->prepare("
                                INSERT INTO students (user_id, student_id, first_name, last_name, phone, email, program_id, level_year, entry_year, status) 
                                VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, 'active')
                            ");
                            $stmt->execute([$userId, $studentId, $firstName, $lastName, $phone, $email, date('Y')]);
                            break;
                        case 'finance':
                            $accountIdentifier = 'FIN-' . str_pad((string)$userId, 4, '0', STR_PAD_LEFT);
                            $accountIdLabel = 'Finance Staff ID';
                            $stmt = $conn->prepare("
                                INSERT INTO finance_staff (user_id, first_name, last_name, phone, email) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$userId, $firstName, $lastName, $phone, $email]);
                            break;
                    }

                    $conn->commit();

                    // Log activity
                    $logger = new Logger();
                    $logger->log($currentUser['id'], 'create', 'users', "Created new $role user: $username");

                    $mailSent = false;
                    $mailError = '';

                    // Send finance credentials by email after successful account creation.
                    if ($role === 'finance') {
                        $mailSent = Helper::sendTemplatedEmail(
                            'credentials_issued',
                            $email,
                            [
                                'recipient_name' => trim($firstName . ' ' . $lastName),
                                'role_label' => 'Finance',
                                'account_id_label' => $accountIdLabel,
                                'account_id_value' => $accountIdentifier,
                                'username' => $username,
                                'temporary_password' => $password,
                                'login_url' => BASE_URL . '/views/finance/login.php'
                            ],
                            [
                                'context_label' => 'Finance Account Credentials',
                                'source_page' => '/views/admin/users/add.php'
                            ]
                        );

                        if (!$mailSent) {
                            $mailError = trim((string)Helper::getLastEmailError());
                        }
                    }

                    // Show credentials to admin for lecturer/finance
                    if (in_array($role, ['lecturer', 'finance'], true)) {
                        $_SESSION['new_user_credentials'] = [
                            'user_name' => trim($firstName . ' ' . $lastName),
                            'username' => $username,
                            'password' => $password,
                            'email' => $email,
                            'role' => $role,
                            'account_id_label' => $accountIdLabel,
                            'account_id_value' => $accountIdentifier,
                            'mail_sent' => $mailSent,
                            'mail_error' => $mailError
                        ];
                        $_SESSION['new_user_type'] = $role;

                        $flashMessage = ucfirst($role) . ' account created successfully.';
                        if ($role === 'finance') {
                            if ($mailSent) {
                                $flashMessage .= ' Login credentials were sent to ' . $email . '.';
                            } else {
                                $flashMessage .= ' Account created, but credential email failed. Share the credentials manually from the next page.';
                            }
                        }
                        $session->setFlash('success', $flashMessage);
                        header('Location: ../credentials.php');
                        exit;
                    }

                    $success = "User created successfully! Username: $username, Role: $role";

                    // Clear form
                    $_POST = [];
                }
            } catch (Throwable $e) {
                if ($conn instanceof PDO && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log("User creation error: " . $e->getMessage());
                $error = 'An error occurred while creating the user. No account was created.';
            }
        } else {
            $error = $validator->firstError();
        }
    }

$db = new Database();
$conn = $db->getConnection();

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

// System users directory (all modules)
$systemUsers = [];
$systemUsersError = '';
$roleSummary = [
    'admin' => 0,
    'lecturer' => 0,
    'student' => 0,
    'finance' => 0
];
$rolePortalMap = [
    'admin' => [
        'label' => 'Admin Portal',
        'url' => BASE_URL . '/views/admin/dashboard.php',
        'area' => 'Administration'
    ],
    'lecturer' => [
        'label' => 'Lecturer Portal',
        'url' => BASE_URL . '/views/lecturer/dashboard.php',
        'area' => 'Teaching & Results Entry'
    ],
    'student' => [
        'label' => 'Student Portal',
        'url' => BASE_URL . '/views/student/dashboard.php',
        'area' => 'Registration & Results'
    ],
    'finance' => [
        'label' => 'Finance Portal',
        'url' => BASE_URL . '/views/finance/dashboard.php',
        'area' => 'Billing & Payments'
    ]
];

try {
    $usersStmt = $conn->query("
        SELECT
            u.id,
            u.username,
            u.email,
            u.role,
            u.status,
            u.created_at,
            a.first_name AS admin_first_name,
            a.last_name AS admin_last_name,
            l.first_name AS lecturer_first_name,
            l.last_name AS lecturer_last_name,
            l.lecturer_id,
            s.first_name AS student_first_name,
            s.middle_name AS student_middle_name,
            s.last_name AS student_last_name,
            s.student_id,
            f.first_name AS finance_first_name,
            f.last_name AS finance_last_name
        FROM users u
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN lecturers l ON l.user_id = u.id
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN finance_staff f ON f.user_id = u.id
        ORDER BY FIELD(u.role, 'admin', 'finance', 'lecturer', 'student'), u.created_at DESC, u.id DESC
    ");
    $systemUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($systemUsers as $directoryUser) {
        if (isset($roleSummary[$directoryUser['role']])) {
            $roleSummary[$directoryUser['role']]++;
        }
    }
} catch (Exception $e) {
    error_log('System users directory error: ' . $e->getMessage());
    $systemUsersError = 'Unable to load system users directory right now.';
}

$pageTitle = 'Add User - ' . APP_NAME;
$additionalCSS = ['admin.css'];
$disableAutoLogout = true;
include __DIR__ . '/../../../includes/header.php';
?>

<?php include __DIR__ . '/../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>
                <a href="../dashboard.php" class="btn btn-link">← Back to Dashboard</a>
                Add User
            </h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <?php include __DIR__ . '/../../../includes/notification_bell.php'; ?>
        </div>
    </div>
    
    <div class="content-area">
        <div class="container-fluid">
            
            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo e($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card system-users-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-user-plus"></i> Create New User Account
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                
                                <!-- Role Selection -->
                                <div class="form-group">
                                    <label for="role">
                                        <i class="fas fa-user-tag"></i> User Role *
                                    </label>
                                    <select class="form-control" id="role" name="role" required>
                                        <option value="">Select Role</option>
                                        <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                        <option value="lecturer" <?php echo ($_POST['role'] ?? '') === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                                        <option value="student" <?php echo ($_POST['role'] ?? '') === 'student' ? 'selected' : ''; ?>>Student</option>
                                        <option value="finance" <?php echo ($_POST['role'] ?? '') === 'finance' ? 'selected' : ''; ?>>Finance Staff</option>
                                    </select>
                                </div>
                                
                                <!-- Personal Information -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="first_name">
                                                <i class="fas fa-user"></i> First Name *
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="first_name" 
                                                   name="first_name" 
                                                   value="<?php echo e($_POST['first_name'] ?? ''); ?>" 
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="last_name">
                                                <i class="fas fa-user"></i> Last Name *
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="last_name" 
                                                   name="last_name" 
                                                   value="<?php echo e($_POST['last_name'] ?? ''); ?>" 
                                                   required>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Contact Information -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="email">
                                                <i class="fas fa-envelope"></i> Email Address *
                                            </label>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="email" 
                                                   name="email" 
                                                   value="<?php echo e($_POST['email'] ?? ''); ?>" 
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="phone">
                                                <i class="fas fa-phone"></i> Phone Number
                                            </label>
                                            <input type="tel" 
                                                   class="form-control" 
                                                   id="phone" 
                                                   name="phone" 
                                                   value="<?php echo e($_POST['phone'] ?? ''); ?>" 
                                                   placeholder="+256...">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Account Credentials -->
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="username">
                                                <i class="fas fa-user-circle"></i> Username *
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="username" 
                                                   name="username" 
                                                   value="<?php echo e($_POST['username'] ?? ''); ?>" 
                                                   required>
                                            <small class="text-muted">Must be unique</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="password">
                                                <i class="fas fa-lock"></i> Password *
                                            </label>
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="password" 
                                                   name="password" 
                                                   required>
                                            <small class="text-muted">Follow current password policy requirements</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="confirm_password">
                                                <i class="fas fa-lock"></i> Confirm Password *
                                            </label>
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="confirm_password" 
                                                   name="confirm_password" 
                                                   required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group text-center">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-plus-circle"></i> Create User
                                    </button>
                                    <a href="../dashboard.php" class="btn btn-secondary btn-lg ml-3">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-users"></i> System Users Directory
                            </h5>
                            <span class="badge badge-dark"><?php echo count($systemUsers); ?> Total</span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">
                                This list identifies all system users and where they operate: Admin, Student, Lecturer, and Finance modules.
                            </p>

                            <?php if ($systemUsersError): ?>
                                <div class="alert alert-warning mb-0">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo e($systemUsersError); ?>
                                </div>
                            <?php else: ?>
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <div class="form-group mb-0">
                                            <input type="text" id="systemUserSearch" class="form-control" placeholder="Search by name, username, email, role, or operating area...">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex flex-wrap justify-content-md-end mt-2 mt-md-0">
                                            <span class="badge badge-primary mr-2 mb-2">Admins: <?php echo (int)$roleSummary['admin']; ?></span>
                                            <span class="badge badge-info mr-2 mb-2">Lecturers: <?php echo (int)$roleSummary['lecturer']; ?></span>
                                            <span class="badge badge-success mr-2 mb-2">Students: <?php echo (int)$roleSummary['student']; ?></span>
                                            <span class="badge badge-warning mb-2">Finance: <?php echo (int)$roleSummary['finance']; ?></span>
                                        </div>
                                    </div>
                                </div>

                                <?php if (empty($systemUsers)): ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle"></i> No users found in the system yet.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive system-users-table-wrap">
                                        <table class="table table-bordered table-hover" id="systemUsersTable">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Full Name</th>
                                                    <th>Username</th>
                                                    <th>Email</th>
                                                    <th>Role</th>
                                                    <th>Identifier</th>
                                                    <th>Operating Area</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($systemUsers as $index => $directoryUser): ?>
                                                    <?php
                                                        $directoryRole = (string)($directoryUser['role'] ?? '');
                                                        $fullName = '';
                                                        $identifier = '-';

                                                        if ($directoryRole === 'admin') {
                                                            $fullName = trim(((string)($directoryUser['admin_first_name'] ?? '')) . ' ' . ((string)($directoryUser['admin_last_name'] ?? '')));
                                                        } elseif ($directoryRole === 'lecturer') {
                                                            $fullName = trim(((string)($directoryUser['lecturer_first_name'] ?? '')) . ' ' . ((string)($directoryUser['lecturer_last_name'] ?? '')));
                                                            $identifier = (string)($directoryUser['lecturer_id'] ?? '') !== '' ? (string)$directoryUser['lecturer_id'] : '-';
                                                        } elseif ($directoryRole === 'student') {
                                                            $fullName = trim(((string)($directoryUser['student_first_name'] ?? '')) . ' ' . ((string)($directoryUser['student_middle_name'] ?? '')) . ' ' . ((string)($directoryUser['student_last_name'] ?? '')));
                                                            $identifier = (string)($directoryUser['student_id'] ?? '') !== '' ? (string)$directoryUser['student_id'] : '-';
                                                        } elseif ($directoryRole === 'finance') {
                                                            $fullName = trim(((string)($directoryUser['finance_first_name'] ?? '')) . ' ' . ((string)($directoryUser['finance_last_name'] ?? '')));
                                                        }

                                                        if ($fullName === '') {
                                                            $fullName = (string)($directoryUser['username'] ?? 'Unknown User');
                                                        }

                                                        $portalMeta = $rolePortalMap[$directoryRole] ?? [
                                                            'label' => 'Unknown Module',
                                                            'url' => '#',
                                                            'area' => 'Unknown Area'
                                                        ];
                                                        $portalPath = (string)(parse_url($portalMeta['url'], PHP_URL_PATH) ?? '#');

                                                        $roleBadgeClass = 'secondary';
                                                        if ($directoryRole === 'admin') $roleBadgeClass = 'danger';
                                                        if ($directoryRole === 'lecturer') $roleBadgeClass = 'info';
                                                        if ($directoryRole === 'student') $roleBadgeClass = 'success';
                                                        if ($directoryRole === 'finance') $roleBadgeClass = 'warning';

                                                        $statusRaw = (string)($directoryUser['status'] ?? 'unknown');
                                                        $statusBadgeClass = 'secondary';
                                                        if ($statusRaw === 'active') $statusBadgeClass = 'success';
                                                        if ($statusRaw === 'inactive') $statusBadgeClass = 'secondary';
                                                        if ($statusRaw === 'suspended') $statusBadgeClass = 'danger';
                                                    ?>
                                                    <tr>
                                                        <td><?php echo (int)$index + 1; ?></td>
                                                        <td><?php echo e($fullName); ?></td>
                                                        <td><?php echo e((string)($directoryUser['username'] ?? '')); ?></td>
                                                        <td><?php echo e((string)($directoryUser['email'] ?? '')); ?></td>
                                                        <td>
                                                            <span class="badge badge-<?php echo e($roleBadgeClass); ?>">
                                                                <?php echo e(ucfirst($directoryRole)); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo e($identifier); ?></td>
                                                        <td class="operating-area-cell">
                                                            <div><strong><?php echo e((string)$portalMeta['label']); ?></strong></div>
                                                            <small class="text-muted"><?php echo e((string)$portalMeta['area']); ?> | <?php echo e($portalPath); ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-<?php echo e($statusBadgeClass); ?>">
                                                                <?php echo e(ucfirst($statusRaw)); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
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

.system-users-card {
    overflow: hidden;
}

.system-users-card .card-body {
    overflow-x: hidden;
}

.system-users-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.system-users-table-wrap table {
    min-width: 980px;
    table-layout: fixed;
    margin-bottom: 0;
}

#systemUsersTable th,
#systemUsersTable td {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
    vertical-align: middle;
}

#systemUsersTable td:nth-child(1) { width: 56px; }
#systemUsersTable td:nth-child(5) { width: 95px; }
#systemUsersTable td:nth-child(6) { width: 130px; }
#systemUsersTable td:nth-child(8) { width: 100px; }

.operating-area-cell small {
    display: block;
    overflow-wrap: anywhere;
    word-break: break-word;
}

@media (max-width: 767.98px) {
    .system-users-table-wrap table {
        min-width: 860px;
    }
}
</style>

<script>
// Auto-generate username from names
document.addEventListener('DOMContentLoaded', function() {
    const firstName = document.getElementById('first_name');
    const lastName = document.getElementById('last_name');
    const username = document.getElementById('username');
    const role = document.getElementById('role');
    const systemUserSearch = document.getElementById('systemUserSearch');
    const systemUsersTable = document.getElementById('systemUsersTable');
    
    function generateUsername() {
        if (firstName.value && lastName.value && role.value && !username.value) {
            let prefix = '';
            switch(role.value) {
                case 'lecturer': prefix = 'prof.'; break;
                case 'student': prefix = 'std'; break;
                case 'finance': prefix = 'fin'; break;
                case 'admin': prefix = 'admin'; break;
            }
            
            const generated = prefix + firstName.value.toLowerCase() + '.' + lastName.value.toLowerCase();
            username.value = generated.replace(/[^a-z0-9.]/g, '');
        }
    }
    
    firstName.addEventListener('input', generateUsername);
    lastName.addEventListener('input', generateUsername);
    role.addEventListener('change', generateUsername);

    if (systemUserSearch && systemUsersTable) {
        const rows = systemUsersTable.querySelectorAll('tbody tr');
        systemUserSearch.addEventListener('input', function() {
            const keyword = systemUserSearch.value.toLowerCase().trim();
            rows.forEach(function(row) {
                const text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(keyword) !== -1 ? '' : 'none';
            });
        });
    }

    // Keep admin session active while user is filling the create form.
    const keepAliveUrl = 'add.php?keepalive=1';
    const keepAliveEveryMs = 4 * 60 * 1000;
    let keepAliveTimer = null;

    function pingKeepAlive() {
        fetch(keepAliveUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache'
            }
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('keepalive_failed');
            }
            return response.json();
        }).then(function(data) {
            if (!data || data.success !== true) {
                throw new Error('keepalive_invalid');
            }
        }).catch(function() {
            if (keepAliveTimer) {
                clearInterval(keepAliveTimer);
            }
        });
    }

    keepAliveTimer = setInterval(pingKeepAlive, keepAliveEveryMs);
    window.addEventListener('beforeunload', function() {
        if (keepAliveTimer) {
            clearInterval(keepAliveTimer);
        }
    });
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
