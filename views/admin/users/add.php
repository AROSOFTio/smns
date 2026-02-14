<?php
/**
 * Admin User Management - Add User Page
 */
require_once '../../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $username = Security::sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role = Security::sanitize($_POST['role'] ?? '');
        $firstName = Security::sanitize($_POST['first_name'] ?? '');
        $lastName = Security::sanitize($_POST['last_name'] ?? '');
        $phone = Security::sanitize($_POST['phone'] ?? '');
        
        // Validation
        $validator = new Validator($_POST);
        $validator->required('username', 'Username is required')
                  ->required('email', 'Email is required')
                  ->email('email', 'Valid email is required')
                  ->required('password', 'Password is required')
                  ->minLength('password', 8, 'Password must be at least 8 characters')
                  ->required('confirm_password', 'Password confirmation is required')
                  ->required('role', 'Role is required')
                  ->required('first_name', 'First name is required')
                  ->required('last_name', 'Last name is required');
        
        if ($password !== $confirmPassword) {
            $validator->addError('confirm_password', 'Passwords do not match');
        }
        
        if (!in_array($role, ['admin', 'lecturer', 'student', 'finance'])) {
            $validator->addError('role', 'Invalid role selected');
        }
        
        if ($validator->passed()) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                // Check if username or email already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                
                if ($stmt->fetch()) {
                    $error = 'Username or email already exists';
                } else {
                    // Create user
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $requireChange = in_array($role, ['lecturer', 'finance']) ? 1 : 0;
                    // Ensure require_password_change column exists
                    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'require_password_change'")->fetch();
                    if (!$col) {
                        $conn->exec("ALTER TABLE users ADD COLUMN require_password_change TINYINT(1) DEFAULT 0 AFTER account_locked_until");
                    }
                    $stmt = $conn->prepare("
                        INSERT INTO users (username, email, password_hash, role, status, require_password_change, created_at) 
                        VALUES (?, ?, ?, ?, 'active', ?, NOW())
                    ");
                    if ($stmt->execute([$username, $email, $passwordHash, $role, $requireChange])) {
                        $userId = $conn->lastInsertId();
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
                                $lecturerId = 'LEC' . str_pad($userId, 3, '0', STR_PAD_LEFT);
                                $stmt = $conn->prepare("
                                    INSERT INTO lecturers (user_id, lecturer_id, first_name, last_name, phone, email, department, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, 'General', 'active')
                                ");
                                $stmt->execute([$userId, $lecturerId, $firstName, $lastName, $phone, $email]);
                                break;
                            case 'student':
                                $studentId = 'STD' . date('Y') . str_pad($userId, 3, '0', STR_PAD_LEFT);
                                $stmt = $conn->prepare("
                                    INSERT INTO students (user_id, student_id, first_name, last_name, phone, email, program_id, level_year, entry_year, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, 'active')
                                ");
                                $stmt->execute([$userId, $studentId, $firstName, $lastName, $phone, $email, date('Y')]);
                                break;
                            case 'finance':
                                $stmt = $conn->prepare("
                                    INSERT INTO finance_staff (user_id, first_name, last_name, phone, email) 
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([$userId, $firstName, $lastName, $phone, $email]);
                                break;
                        }
                        // Show credentials to admin for lecturer/finance
                        if (in_array($role, ['lecturer', 'finance'])) {
                            // Store credentials in session for display
                            $_SESSION['new_user_credentials'] = [
                                'user_name' => $firstName . ' ' . $lastName,
                                'username' => $username,
                                'password' => $password,
                                'email' => $email,
                                'role' => $role,
                                'mail_sent' => false // Admin users don't get emails
                            ];
                            $_SESSION['new_user_type'] = $role;

                            $session->setFlash('success', ucfirst($role) . ' account created successfully! Redirecting to credentials page...');
                            header('Location: ../credentials.php');
                            exit;
                        } else {
                            $success = "User created successfully! Username: $username, Role: $role";
                        }
                        
                        // Log activity
                        $logger = new Logger();
                        $logger->log($currentUser['id'], 'create', 'users', "Created new $role user: $username");
                        
                        $success = "User created successfully! Username: $username, Role: $role";
                        
                        // Clear form
                        $_POST = [];
                        
                    } else {
                        $error = 'Failed to create user. Please try again.';
                    }
                }
                
            } catch (Exception $e) {
                error_log("User creation error: " . $e->getMessage());
                $error = 'An error occurred while creating the user.';
            }
        } else {
            $error = $validator->firstError();
        }
    }

$db = new Database();
$conn = $db->getConnection();

// Notifications (admin sees all system notifications)
$stmt = $conn->prepare("SELECT * FROM notifications WHERE read_status = 'unread' ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'Add User - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>
                <a href="../dashboard.php" class="btn btn-link">← Back to Dashboard</a>
                Add User
            </h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <?php include '../../../includes/notification_bell.php'; ?>
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
                    <div class="card">
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
                                            <small class="text-muted">Minimum 8 characters</small>
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
        </div>
    </div>
</div>

<script>
// Auto-generate username from names
document.addEventListener('DOMContentLoaded', function() {
    const firstName = document.getElementById('first_name');
    const lastName = document.getElementById('last_name');
    const username = document.getElementById('username');
    const role = document.getElementById('role');
    
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
});
</script>

<?php include '../../../includes/footer.php'; ?>