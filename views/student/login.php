<?php
/**
 * Student Login Page
 * Independent login for students only
 */
require_once '../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Check for flash messages
    $profile_name = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
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
                $profile_name = $user['fullname'] ?? $user['username'];
            } else {
                $error = 'Invalid credentials';
            }
        }
    } elseif ($step === 2) {
        // Step 2: Validate password
        $password = $_POST['password'] ?? '';
        $auth = new Auth('student');
        $user = $auth->usernameExists($entered_username);
        if (is_array($user)) {
            $profile_name = $user['fullname'] ?? $user['username'];
        } else {
            $profile_name = $entered_username;
        }
        if (!$user || $user['role'] !== 'student') {
            $error = 'Invalid credentials';
            $step = 1;
        } elseif (empty($password)) {
            $error = 'Password is required.';
            $username_valid = true;
            $step = 2;
        } else {
            $result = $auth->login($entered_username, $password);
            if ($result['success'] && $result['role'] === 'student') {
                header('Location: dashboard.php');
                exit;
            } else {
                $error = $result['message'] ?? 'Login failed.';
                $username_valid = true;
                $step = 2;
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
    <title>Student Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css">
    <style>
        body { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
    </style>
</head>

<body style="background: url('../../assets/img/seminary.jpeg') no-repeat center center fixed; background-size: cover;">
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
            

            <form method="POST" action="" class="login-form" autocomplete="off">
                <?php echo csrfField(); ?>
                <input type="hidden" name="step" value="<?php echo $step; ?>">
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
                                   required autofocus>
                            
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-arrow-right"></i> Next
                    </button>
                <?php elseif ($step === 2): ?>
                    <div class="form-group text-center mb-2">
                        <span class="badge badge-info" style="font-size:13px;padding:6px 16px;">Welcome, <?php echo htmlspecialchars($entered_username); ?></span>
                    </div>
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password</label>
                        <div class="input-wrapper">
                            <input type="password"
                                   class="form-control"
                                   id="password"
                                   name="password"
                                   placeholder="Enter your password"
                                   required autofocus>
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                <?php endif; ?>
            </form>
            
            <div class="login-footer">
                <!-- Footer content (if any) -->
            </div>
                <div class="form-group">
                <a href="../auth/login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
                
            </div>
        </div>
    </div>
</body>
</html>
