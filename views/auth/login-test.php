<?php
/**
 * Login Form Test - Check what's being received
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Login Form Test</h2>";
echo "<p>This page shows what happens when the login form is submitted.</p>";

// Show server variables
echo "<h3>Server Info:</h3>";
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "<br>";
echo "Script Name: " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "PHP Version: " . phpversion() . "<br>";

// Check POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>POST Data Received:</h3>";
    echo "<pre>";
    var_dump($_POST);
    echo "</pre>";
    
    echo "<h3>Step-by-step Login Test:</h3>";
    
    // Step 1: Check config inclusion
    echo "1. Loading config...<br>";
    try {
        require_once '../../config.php';
        echo "✓ Config loaded successfully<br><br>";
    } catch (Exception $e) {
        echo "❌ Config failed: " . $e->getMessage() . "<br><br>";
        exit;
    }
    
    // Step 2: Check classes
    echo "2. Checking required classes...<br>";
    if (class_exists('Security')) {
        echo "✓ Security class exists<br>";
    } else {
        echo "❌ Security class missing<br>";
    }
    
    if (class_exists('Validator')) {
        echo "✓ Validator class exists<br>";
    } else {
        echo "❌ Validator class missing<br>";
    }
    
    if (class_exists('Auth')) {
        echo "✓ Auth class exists<br>";
    } else {
        echo "❌ Auth class missing<br>";
    }
    echo "<br>";
    
    // Step 3: Check if functions exist
    echo "3. Checking required functions...<br>";
    if (function_exists('e')) {
        echo "✓ e() function exists<br>";
    } else {
        echo "❌ e() function missing<br>";
    }
    
    if (function_exists('csrfField')) {
        echo "✓ csrfField() function exists<br>";
    } else {
        echo "❌ csrfField() function missing<br>";
    }
    echo "<br>";
    
    // Step 4: Test form data
    echo "4. Processing form data...<br>";
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    echo "Username: " . htmlspecialchars($username) . "<br>";
    echo "Password: " . (strlen($password) > 0 ? str_repeat('*', strlen($password)) : 'EMPTY') . "<br>";
    echo "CSRF Token: " . ($csrf_token ? 'Present' : 'MISSING') . "<br><br>";
    
    // Step 5: Test Security class
    echo "5. Testing Security class...<br>";
    try {
        $sanitized_username = Security::sanitize($username);
        echo "✓ Username sanitized: " . htmlspecialchars($sanitized_username) . "<br>";
        
        if ($csrf_token && method_exists('Security', 'verifyCSRFToken')) {
            $csrf_valid = Security::verifyCSRFToken($csrf_token);
            echo ($csrf_valid ? "✓" : "❌") . " CSRF verification: " . ($csrf_valid ? "Valid" : "Invalid") . "<br>";
        } else {
            echo "❌ CSRF verification method not available<br>";
        }
    } catch (Exception $e) {
        echo "❌ Security error: " . $e->getMessage() . "<br>";
    }
    echo "<br>";
    
    // Step 6: Test Validator
    echo "6. Testing Validator...<br>";
    try {
        $validator = new Validator($_POST);
        $validator->required('username', 'Username is required')
                  ->required('password', 'Password is required');
        
        if ($validator->passed()) {
            echo "✓ Validation passed<br>";
        } else {
            echo "❌ Validation failed: " . $validator->firstError() . "<br>";
        }
    } catch (Exception $e) {
        echo "❌ Validator error: " . $e->getMessage() . "<br>";
    }
    echo "<br>";
    
    // Step 7: Test Auth
    echo "7. Testing Authentication...<br>";
    if ($username && $password) {
        try {
            $auth = new Auth();
            $result = $auth->login($username, $password);
            
            echo "Auth result:<br>";
            echo "<pre>";
            var_dump($result);
            echo "</pre>";
            
            if ($result['success']) {
                echo "✅ LOGIN SUCCESS! Role: " . $result['role'] . "<br>";
            } else {
                echo "❌ LOGIN FAILED: " . $result['message'] . "<br>";
            }
        } catch (Exception $e) {
            echo "❌ Auth error: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "❌ Missing username or password<br>";
    }
    
} else {
    echo "<h3>Test Login Form</h3>";
    ?>
    
    <form method="POST" action="" style="max-width: 400px;">
        
        <p>
            <label>Username:</label><br>
            <input type="text" name="username" value="admin" required style="width: 100%; padding: 8px;">
        </p>
        
        <p>
            <label>Password:</label><br>
            <input type="password" name="password" value="password" required style="width: 100%; padding: 8px;">
        </p>
        
        <p>
            <button type="submit" style="padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer;">
                Test Login
            </button>
        </p>
    </form>
    
    <h4>Test Credentials:</h4>
    <ul>
        <li>Admin: admin / password</li>
        <li>Student: std001 / password</li>
        <li>Lecturer: prof.johnson / password</li>
        <li>Finance: finance1 / password</li>
    </ul>
    
    <?php
}
?>