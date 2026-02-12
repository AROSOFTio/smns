<?php
/**
 * Login Page
 */
require_once '../../config.php';

$session = new Session();
$auth = new Auth();

// If already logged in, redirect to dashboard
if ($auth->isLoggedIn()) {
    $user = $auth->getCurrentUser();
    switch($user['role']) {
        case 'admin':
            header('Location: ../admin/dashboard.php');
            break;
        case 'student':
            header('Location: ../student/dashboard.php');
            break;
        case 'lecturer':
            header('Location: ../lecturer/dashboard.php');
            break;
        case 'finance':
            header('Location: ../finance/dashboard.php');
            break;
    }
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = Security::sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate inputs
    $validator = new Validator($_POST);
    $validator->required('username', 'Username is required')
              ->required('password', 'Password is required');
    
    if ($validator->passed()) {
        $result = $auth->login($username, $password);
        
        if ($result['success']) {
            // Redirect based on role
            switch($result['role']) {
                case 'admin':
                    header('Location: ../admin/dashboard.php');
                    break;
                case 'student':
                    header('Location: ../student/dashboard.php');
                    break;
                case 'lecturer':
                    header('Location: ../lecturer/dashboard.php');
                    break;
                case 'finance':
                    header('Location: ../finance/dashboard.php');
                    break;
            }
            exit;
        } else {
            $error = $result['message'];
        }
    } else {
        $error = $validator->firstError();
    }
}

// Get flash messages
$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="../../assets/images/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">
                <h2><?php echo APP_NAME; ?></h2>
                <p>Sign in to your account</p>
            </div>
            
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success">
                    <?php echo e($flashSuccess); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($flashError): ?>
                <div class="alert alert-danger">
                    <?php echo e($flashError); ?>
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
                    <label for="username">Username or Email</label>
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="username" 
                           value="<?php echo e($_POST['username'] ?? ''); ?>" 
                           required 
                           autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           required>
                </div>
                
                <div class="form-group form-check">
                    <input type="checkbox" class="form-check-input" id="remember">
                    <label class="form-check-label" for="remember">
                        Remember me
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    Sign In
                </button>
                
                <div class="login-footer">
                    <a href="forgot-password.php">Forgot Password?</a>
                </div>
            </form>
            
            <div class="login-info">
                <p><small>Default login credentials for testing:</small></p>
                <p><small><strong>Admin:</strong> admin / password</small></p>
                <p><small><strong>Student:</strong> std001 / password</small></p>
                <p><small><strong>Lecturer:</strong> prof.johnson / password</small></p>
                <p><small><strong>Finance:</strong> finance1 / password</small></p>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
