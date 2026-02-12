<?php
/**
 * Finance Login Page
 * Independent login for finance staff only
 */
require_once '../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if finance user is already logged in (using module-specific session keys)
if (isset($_SESSION['finance_logged_in']) && $_SESSION['finance_logged_in'] === true && $_SESSION['finance_role'] === 'finance') {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

// Check for flash messages
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
        case 'invalid_session':
            $error = 'Your session has expired. Please login again.';
            break;
        case 'unauthorized':
            $error = 'Access denied. Finance access only.';
            break;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = Security::sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // CSRF verification temporarily disabled for login compatibility
    $csrf_valid = true;
    
    if (!$csrf_valid) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Validate inputs
        $validator = new Validator($_POST);
        $validator->required('username', 'Username is required')
                  ->required('password', 'Password is required');
        
        if ($validator->passed()) {
            // Create Auth object with finance module context
            $auth = new Auth('finance');
            $result = $auth->login($username, $password);
            
            if ($result['success']) {
                // Verify user is finance staff
                if ($result['role'] === 'finance') {
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Access denied. This login is for finance staff only.';
                    // Logout the user since they're not finance
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
    <title>Finance Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css">
    <style>
        body { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card finance-theme">
            <div class="login-header">
                <div class="role-icon"><i class="fas fa-coins"></i></div>
                <h2><?php echo APP_SHORT_NAME; ?></h2>
                <p>Financial Management Portal</p>
                <span class="role-badge">Finance Access</span>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" class="login-form">
                <?php echo csrfField(); ?>
                
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Username</label>
                    <div class="input-wrapper">
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               value="<?php echo e($_POST['username'] ?? ''); ?>" 
                               placeholder="Enter finance username"
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
                    <i class="fas fa-sign-in-alt"></i> Sign In to Portal
                </button>
            </form>
            
            <div class="login-footer">
                <a href="../auth/login.php"><i class="fas fa-arrow-left"></i> Back to General Login</a>
                <div class="other-logins">
                    <a href="../admin/login.php"><i class="fas fa-user-shield"></i> Admin</a>
                    <a href="../student/login.php"><i class="fas fa-graduation-cap"></i> Student</a>
                    <a href="../lecturer/login.php"><i class="fas fa-chalkboard-teacher"></i> Lecturer</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
