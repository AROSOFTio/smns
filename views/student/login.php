<?php
/**
 * Student Login Page
 * Independent login for students only
 */
require_once '../../config.php';

$unifiedLoginQuery = $_GET;
$unifiedLoginQuery['role'] = 'student';
header('Location: ' . BASE_URL . '/views/auth/login.php?' . http_build_query($unifiedLoginQuery));
exit;

// Don't start session here - let Session class handle it with proper role-specific name
// Session will be started when Auth is created

// Create session with student role context to use SMNS_STUDENT_SESSION cookie
$tempSession = new Session('student');

// Check if student is already logged in (using module-specific session keys)
if (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true && $_SESSION['student_role'] === 'student') {
    header('Location: dashboard.php');
    exit;
}


$error = '';
$success = '';
$step = 1;
$username_valid = false;
$entered_username = '';
$profile_name = '';

// Check for flash messages
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Check for error messages from URL
if (isset($_GET['error'])) {
    switch($_GET['error']) {
        case 'session_expired':
            $error = 'Your session expired due to inactivity. Please login again.';
            break;
        case 'invalid_session':
            $error = 'Your session has expired. Please login again.';
            break;
        case 'unauthorized':
            $error = 'Access denied. Student access only.';
            break;
    }
}

// Check for action messages (e.g., after logout)
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $success = 'You have been logged out successfully.';
}


// Two-step login process
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = intval($_POST['step'] ?? 1);
    $entered_username = Security::sanitize($_POST['username'] ?? '');
    
    if ($step === 1) {
        // Step 1: Check if username exists
        if (empty($entered_username)) {
            $error = 'Username is required.';
        } else {
            $auth = new Auth('student');
            $user = $auth->usernameExists($entered_username);
            if ($user && $user['role'] === 'student') {
                $username_valid = true;
                $step = 2;
                // Get student profile for full name
                $db = new Database();
                $conn = $db->getConnection();
                $stmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE user_id = :user_id");
                $stmt->execute(['user_id' => $user['id']]);
                $student = $stmt->fetch();
                if ($student) {
                    $profile_name = trim($student['first_name'] . ' ' . $student['last_name']);
                } else {
                    $profile_name = $user['username'];
                }
            } else {
                $error = 'Invalid credentials';
            }
        }
    } elseif ($step === 2) {
        // Step 2: Validate password
        $password = $_POST['password'] ?? '';
        
        if (empty($entered_username)) {
            $error = 'Session expired. Please start again.';
            $step = 1;
        } elseif (empty($password)) {
            $error = 'Password is required.';
            $username_valid = true;
            $step = 2;
            // Re-fetch user for profile name
            $auth = new Auth('student');
            $user = $auth->usernameExists($entered_username);
            if (is_array($user)) {
                // Get student profile for full name
                $db = new Database();
                $conn = $db->getConnection();
                $stmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE user_id = :user_id");
                $stmt->execute(['user_id' => $user['id']]);
                $student = $stmt->fetch();
                if ($student) {
                    $profile_name = trim($student['first_name'] . ' ' . $student['last_name']);
                } else {
                    $profile_name = $user['username'];
                }
            }
        } else {
            // Attempt login
            $auth = new Auth('student');
            $result = $auth->login($entered_username, $password);
            
            if (!empty($result['mfa_required'])) {
                header('Location: ' . BASE_URL . '/views/auth/mfa-verify.php?module=student');
                exit;
            } elseif (!empty($result['consent_required'])) {
                header('Location: ' . BASE_URL . '/views/auth/privacy-consent.php?module=student');
                exit;
            } elseif ($result['success'] && $result['role'] === 'student') {
                // Login successful - check if password change required
                if (!empty($result['require_password_change'])) {
                    // User must change password first
                    // Keep user logged in and redirect to change-password page
                    header('Location: change-password.php');
                    exit;
                }
                // Login successful - redirect to dashboard
                header('Location: dashboard.php');
                exit;
            } else {
                // Login failed - show error and stay on step 2
                $error = $result['message'] ?? 'Invalid password. Please try again.';
                $username_valid = true;
                $step = 2;
                // Re-fetch user for profile name
                $user = $auth->usernameExists($entered_username);
                if (is_array($user)) {
                    // Get student profile for full name
                    $db = new Database();
                    $conn = $db->getConnection();
                    $stmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE user_id = :user_id");
                    $stmt->execute(['user_id' => $user['id']]);
                    $student = $stmt->fetch();
                    if ($student) {
                        $profile_name = trim($student['first_name'] . ' ' . $student['last_name']);
                    } else {
                        $profile_name = $user['username'];
                    }
                } else {
                    $profile_name = $entered_username;
                }
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
    <title>Student Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <style>
        body { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
    </style>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css">
</head>

<body style="background: url('../../uploads/seminary.jpeg') no-repeat center center fixed; background-size: cover;">
    <div class="login-container">
        <div class="login-card student-theme">
            <div class="login-header">
                <img src="../../assets/img/sem.PNG" alt="Logo" class="logo mb-2" style="max-width:80px;">
                <h2><?php echo APP_SHORT_NAME; ?></h2>
               
                <span class="role-badge">Student Portal</span>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>
            

            <form method="POST" action="" class="login-form" autocomplete="on">
                <?php echo csrfField(); ?>
                <input type="hidden" name="step" value="<?php echo $step; ?>">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($entered_username); ?>" autocomplete="section-student username">
                
                <?php if ($step === 1): ?>
                    <div class="form-group">
                        <label for="username"> Username</label>
                        <div class="input-wrapper">
                            <input type="text"
                                   class="form-control"
                                   id="username"
                                   name="username"
                                   value="<?php echo htmlspecialchars($entered_username); ?>"
                                   placeholder="Enter student ID or username"
                                   autocomplete="section-student username"
                                   required autofocus>
                            <span class="input-icon"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-arrow-right"></i> Next
                    </button>
                <?php elseif ($step === 2): ?>
                    <div class="form-group text-center mb-3">
                        
                        <span class="badge badge-info" style="font-size:14px;padding:8px 20px;">
                            Welcome, <?php echo htmlspecialchars($profile_name ?: $entered_username); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="password"> Password</label>
                        <div class="input-wrapper" style="position:relative;">
                            <input type="password"
                                   class="form-control"
                                   id="password"
                                   name="password"
                                   placeholder="Enter your password"
                                   autocomplete="section-student current-password"
                                   required autofocus>
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" style="position:absolute; right:10px; top:50%; transform:translateY(-50%);" onclick="togglePassword('password', this)">Show</button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                    <div class="text-center mt-2">
                        <a href="<?php echo e(BASE_URL . '/views/auth/forgot-password.php?role=student'); ?>" class="btn btn-link btn-sm">
                            Forgot password?
                        </a>
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-link btn-sm">
                            <i class="fas fa-arrow-left"></i> Not you? Use different account
                        </a>
                    </div>
                <?php endif; ?>
            </form>
            <script>
                // Show/hide password toggle
                function togglePassword(id, btn) {
                    var input = document.getElementById(id);
                    if (input.type === 'password') {
                        input.type = 'text';
                        btn.textContent = 'Hide';
                    } else {
                        input.type = 'password';
                        btn.textContent = 'Show';
                    }
                }
                // Auto-hide success message after 30 seconds
                setTimeout(function() {
                    const successAlert = document.querySelector('.alert-success');
                    if (successAlert) {
                        successAlert.style.transition = 'opacity 0.5s ease-out';
                        successAlert.style.opacity = '0';
                        setTimeout(function() {
                            successAlert.style.display = 'none';
                        }, 500);
                    }
                }, 30000);
            </script>
            <script src="../../assets/js/login-theme.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
        </div>
    </div>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js"></script>
</body>
</html>
