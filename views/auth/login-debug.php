<?php
/**
 * Debug Login Page - Shows detailed error information
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<!DOCTYPE html><html><head><title>Login Debug</title>";
echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css'>";
echo "<style>body{padding:20px;} .debug{background:#f8f9fa;padding:15px;margin:10px 0;border-left:4px solid #dc3545;} .success-box{background:#d4edda;padding:15px;margin:10px 0;border-left:4px solid #28a745;}</style>";
echo "</head><body><div class='container'>";

echo "<h2>Login Debug Mode</h2>";
echo "<div class='alert alert-warning'>This page shows detailed debug information. Remove after testing.</div>";

// Step 1: Check config
echo "<h4>Step 1: Loading Config</h4>";
try {
    require_once '../../config.php';
    echo "<div class='success-box'>✓ Config loaded successfully</div>";
    echo "<pre>DB_HOST: " . DB_HOST . "\nDB_NAME: " . DB_NAME . "\nDB_USER: " . DB_USER . "</pre>";
} catch (Exception $e) {
    echo "<div class='debug'>✗ Config error: " . $e->getMessage() . "</div>";
    die();
}

// Step 2: Check session
echo "<h4>Step 2: Session Status</h4>";
session_name('SMNS_PUBLIC_SESSION');
@session_start();
echo "<div class='success-box'>✓ Session started: " . session_id() . "</div>";

// Step 3: Check database connection
echo "<h4>Step 3: Database Connection</h4>";
try {
    $db = new Database();
    $conn = $db->getConnection();
    echo "<div class='success-box'>✓ Database connected</div>";
    
    // Check users table
    $userCount = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "<div class='success-box'>✓ Users table exists with $userCount users</div>";
    
} catch (Exception $e) {
    echo "<div class='debug'>✗ Database error: " . $e->getMessage() . "</div>";
    echo "<div class='alert alert-danger'>";
    echo "<h5>Database Setup Required!</h5>";
    echo "<p>Please import the database files:</p>";
    echo "<ol><li>Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a></li>";
    echo "<li>Create database 'smns' if it doesn't exist</li>";
    echo "<li>Import: Seed/schema.sql</li>";
    echo "<li>Import: Seed/seed.sql</li></ol>";
    echo "</div>";
    die();
}

$error = '';
$success = '';

// Step 4: Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h4>Step 4: Processing Login</h4>";
    
    echo "<div class='debug'><strong>POST Data:</strong><pre>" . print_r($_POST, true) . "</pre></div>";
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    echo "<div class='debug'>Username: $username</div>";
    echo "<div class='debug'>Password: " . (empty($password) ? 'EMPTY' : '(provided)') . "</div>";
    
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required';
        echo "<div class='debug'>✗ Validation failed: $error</div>";
    } else {
        try {
            echo "<div class='debug'>Creating Auth object...</div>";
            $auth = new Auth();
            echo "<div class='success-box'>✓ Auth object created</div>";
            
            echo "<div class='debug'>Calling login method...</div>";
            $result = $auth->login($username, $password);
            
            echo "<div class='debug'><strong>Login Result:</strong><pre>" . print_r($result, true) . "</pre></div>";
            
            if ($result['success']) {
                echo "<div class='success-box'><h5>✓ Login Successful!</h5>";
                echo "<p>Role: " . $result['role'] . "</p>";
                echo "<p>Redirecting in 2 seconds...</p></div>";
                
                $redirectUrl = '';
                switch($result['role']) {
                    case 'admin':
                        $redirectUrl = '../admin/dashboard.php';
                        break;
                    case 'student':
                        $redirectUrl = '../student/dashboard.php';
                        break;
                    case 'lecturer':
                        $redirectUrl = '../lecturer/dashboard.php';
                        break;
                    case 'finance':
                        $redirectUrl = '../finance/dashboard.php';
                        break;
                }
                
                echo "<script>setTimeout(function(){ window.location.href='$redirectUrl'; }, 2000);</script>";
            } else {
                $error = $result['message'];
                echo "<div class='debug'>✗ Login failed: $error</div>";
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
            echo "<div class='debug'>✗ Exception: " . $e->getMessage() . "</div>";
            echo "<div class='debug'><strong>Stack Trace:</strong><pre>" . $e->getTraceAsString() . "</pre></div>";
        }
    }
}

?>

<hr>
<h4>Test Login Form</h4>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" class="card p-4">
    <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" class="form-control" value="admin" required>
        <small class="form-text text-muted">Try: admin, std001, prof.johnson, finance1</small>
    </div>
    
    <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" class="form-control" value="password" required>
        <small class="form-text text-muted">Default password: password</small>
    </div>
    
    <button type="submit" class="btn btn-primary">Test Login</button>
</form>

<hr>
<div class="alert alert-info">
    <h5>Test Credentials</h5>
    <ul>
        <li><strong>Admin:</strong> admin / password</li>
        <li><strong>Student:</strong> std001 / password</li>
        <li><strong>Lecturer:</strong> prof.johnson / password</li>
        <li><strong>Finance:</strong> finance1 / password</li>
    </ul>
</div>

<hr>
<p><a href="login.php" class="btn btn-secondary">Back to Normal Login</a></p>

</div></body></html>
