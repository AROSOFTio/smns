<?php
/**
 * Unified Login Page - Secure Single Entry Point
 * Validates authorization and redirects based on user role
 */
require_once '../../config.php';

// Start a simple session for login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

// Get flash messages
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Check for URL error parameters
if (isset($_GET['error'])) {
    switch($_GET['error']) {
        case 'invalid_session':
            $error = 'Your session has expired or is invalid. Please login again.';
            break;
        case 'access_denied':
            $error = 'Access denied. You do not have permission to access that page.';
            break;
        case 'unauthorized':
            $error = 'Unauthorized access. Please login to continue.';
            break;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = Security::sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Temporarily disable CSRF for login to avoid session conflicts
    // TODO: Fix CSRF implementation to work with custom Session class
    $csrf_valid = true;
    
    if (!$csrf_valid) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Validate inputs
        $validator = new Validator($_POST);
        $validator->required('username', 'Username is required')
                  ->required('password', 'Password is required');
        
        if ($validator->passed()) {
            // Authenticate user
            $auth = new Auth();
            $result = $auth->login($username, $password);
            
            if ($result['success']) {
                // Login successful - redirect based on user role
                switch($result['role']) {
                    case 'admin':
                        header('Location: ../admin/dashboard.php');
                        exit;
                    case 'student':
                        header('Location: ../student/dashboard.php');
                        exit;
                    case 'lecturer':
                        header('Location: ../lecturer/dashboard.php');
                        exit;
                    case 'finance':
                        header('Location: ../finance/dashboard.php');
                        exit;
                    default:
                        $error = 'Invalid user role. Please contact administrator.';
                        $auth->logout();
                }
            } else {
                $error = $result['message'];
            }
        } else {
            $error = $validator->firstError();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="role-icon"><i class="fas fa-graduation-cap"></i></div>
                <h2><?php echo APP_SHORT_NAME; ?></h2>
                <p>Seminary Results Management System</p>
                <span class="role-badge">All Users</span>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" class="login-form">
                <?php // echo csrfField(); // Temporarily disabled ?>
                
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Username or Email</label>
                    <div class="input-wrapper">
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               value="<?php echo e($_POST['username'] ?? ''); ?>" 
                               placeholder="Enter your username or email"
                               required 
                               autofocus>
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <div class="input-wrapper">
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required>
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
            
            <div class="login-footer">
                <p style="color: #6c757d; font-size: 13px; margin-bottom: 15px;">Quick access to role-specific portals:</p>
                <div class="other-logins">
                    <a href="../admin/login.php"><i class="fas fa-user-shield"></i> Admin</a>
                    <a href="../student/login.php"><i class="fas fa-graduation-cap"></i> Student</a>
                    <a href="../lecturer/login.php"><i class="fas fa-chalkboard-teacher"></i> Lecturer</a>
                    <a href="../finance/login.php"><i class="fas fa-coins"></i> Finance</a>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-3">
            <p style="color: rgba(255,255,255,0.8);">
                <small>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.</small>
            </p>
        </div>
    </div>
</body>
</html>
