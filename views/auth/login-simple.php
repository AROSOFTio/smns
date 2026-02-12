<?php
/**
 * Simple Login Test - Minimal version to test core functionality
 */
require_once '../../config.php';

echo "<h2>Simple Login Test</h2>";
echo "<style>body{font-family:Arial;margin:40px;} .success{color:green;} .error{color:red;}</style>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>POST Request Received</h3>";
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    echo "<p>Username: <strong>" . htmlspecialchars($username) . "</strong></p>";
    echo "<p>Password: <strong>" . (strlen($password) > 0 ? str_repeat('*', strlen($password)) : 'EMPTY') . "</strong></p>";
    
    if (empty($username) || empty($password)) {
        echo "<p class='error'>❌ Username or password is empty</p>";
    } else {
        try {
            // Test authentication directly
            $auth = new Auth();
            $result = $auth->login($username, $password);
            
            if ($result['success']) {
                echo "<p class='success'>✅ Authentication SUCCESS!</p>";
                echo "<p>User Role: <strong>{$result['role']}</strong></p>";
                echo "<p>User ID: {$result['user']['id']}</p>";
                echo "<p>User Name: {$result['user']['first_name']} {$result['user']['last_name']}</p>";
                
                // Test redirect - use same test dashboard for all roles
                $redirectUrl = '../dashboard-test.php';
                
                echo "<p class='success'>✅ Should redirect to: <strong>$redirectUrl</strong></p>";
                echo "<p><a href='$redirectUrl' target='_blank'>Test Redirect Link</a></p>";
                
                // Show the actual redirect
                echo "<p style='background:#f0f0f0;padding:10px;margin:20px 0;'>";
                echo "<strong>Performing redirect in 3 seconds...</strong><br>";
                echo "If this works, the login system is functioning correctly.";
                echo "</p>";
                echo "<script>setTimeout(function(){ window.location.href = '$redirectUrl'; }, 3000);</script>";
                
            } else {
                echo "<p class='error'>❌ Authentication FAILED: " . $result['message'] . "</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
        }
    }
} else {
    echo "<h3>Login Form</h3>";
    echo "<form method='POST' style='max-width:300px;'>";
    echo "<p><label>Username:</label><br><input type='text' name='username' value='admin' style='width:100%;padding:8px;'></p>";
    echo "<p><label>Password:</label><br><input type='password' name='password' value='password' style='width:100%;padding:8px;'></p>";
    echo "<p><button type='submit' style='padding:10px 20px;background:#007bff;color:white;border:none;cursor:pointer;'>Login</button></p>";
    echo "</form>";
    
    echo "<h4>Available Test Accounts:</h4>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> admin / password</li>";
    echo "<li><strong>Student:</strong> std001 / password</li>";
    echo "<li><strong>Lecturer:</strong> prof.johnson / password</li>";
    echo "<li><strong>Finance:</strong> finance1 / password</li>";
    echo "</ul>";
}
?>