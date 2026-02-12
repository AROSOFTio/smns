<?php
/**
 * Admin Login Page
 * Independent login for administrators only
 */
require_once '../../config.php';

// Check if admin is already logged in
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_name('SMNS_ADMIN_SESSION');
@session_start();

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && $_SESSION['role'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Close and start public session for login
session_write_close();
session_name('SMNS_PUBLIC_SESSION');
@session_start();

$error = '';
$success = '';

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
        case 'invalid_session':
            $error = 'Your session has expired. Please login again.';
            break;
        case 'unauthorized':
            $error = 'Access denied. Admin access only.';
            break;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = Security::sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Validate inputs
        $validator = new Validator($_POST);
        $validator->required('username', 'Username is required')
                  ->required('password', 'Password is required');
        
        if ($validator->passed()) {
            // Create Auth object
            $auth = new Auth();
            $result = $auth->login($username, $password);
            
            if ($result['success']) {
                // Verify user is an admin
                if ($result['role'] === 'admin') {
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Access denied. This login is for administrators only.';
                    // Logout the user since they're not admin
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
    <title>Admin Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css">
    <style>
        .login-card { border-top: 4px solid #dc3545; }
        .btn-primary { background-color: #dc3545; border-color: #dc3545; }
        .btn-primary:hover { background-color: #c82333; border-color: #bd2130; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="../../assets/images/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">
                <h2><?php echo APP_SHORT_NAME; ?> - Administration</h2>
                <p>Admin Access Only</p>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo e($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="login-form">
                <?php echo csrfField(); ?>
                
                <div class="form-group">
                    <label for="username">Admin Username</label>
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="username" 
                           value="<?php echo e($_POST['username'] ?? ''); ?>" 
                           placeholder="Enter admin username"
                           required 
                           autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Admin Password</label>
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="Enter admin password"
                           required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Admin Sign In
                </button>
                
                <div class="login-footer mt-3">
                    <a href="../auth/login.php">General Login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
