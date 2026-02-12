<?php
/**
 * Test Login Functionality
 * This page tests the login system components
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>Login Test</title>";
echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css'>";
echo "<style>body{padding:40px;} .success{color:#28a745;} .error{color:#dc3545;}</style>";
echo "</head><body><div class='container'><h2>Login System Test</h2>";

// Test 1: Config File
echo "<h4>Test 1: Configuration</h4>";
if (file_exists('config.php')) {
    require_once 'config.php';
    echo "<p class='success'>✓ Config loaded</p>";
} else {
    echo "<p class='error'>✗ Config not found</p>";
    die();
}

// Test 2: Database Connection
echo "<h4>Test 2: Database Connection</h4>";
try {
    $db = new Database();
    $conn = $db->getConnection();
    echo "<p class='success'>✓ Database connected</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ Database error: " . $e->getMessage() . "</p>";
    die();
}

// Test 3: Check Users Table
echo "<h4>Test 3: Users Table</h4>";
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch()['count'];
    echo "<p class='success'>✓ Users table exists with $count users</p>";
    
    if ($count > 0) {
        $stmt = $conn->query("SELECT username, email, role FROM users LIMIT 5");
        echo "<table class='table table-sm'><thead><tr><th>Username</th><th>Email</th><th>Role</th></tr></thead><tbody>";
        while ($row = $stmt->fetch()) {
            echo "<tr><td>{$row['username']}</td><td>{$row['email']}</td><td>{$row['role']}</td></tr>";
        }
        echo "</tbody></table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Table error: " . $e->getMessage() . "</p>";
    die();
}

// Test 4: Test Authentication
echo "<h4>Test 4: Authentication Test</h4>";
try {
    // Test with admin credentials
    $testUsername = 'admin';
    $testPassword = 'password';
    
    echo "<p>Testing login with: <strong>$testUsername</strong> / <strong>$testPassword</strong></p>";
    
    $auth = new Auth();
    $result = $auth->login($testUsername, $testPassword);
    
    if ($result['success']) {
        echo "<p class='success'>✓ Login successful!</p>";
        echo "<pre>" . print_r($result, true) . "</pre>";
        
        // Logout immediately
        $auth->logout();
        echo "<p>✓ Logged out for testing purposes</p>";
    } else {
        echo "<p class='error'>✗ Login failed: " . $result['message'] . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Auth error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Test 5: Session Test
echo "<h4>Test 5: Session Test</h4>";
try {
    $session = new Session();
    echo "<p class='success'>✓ Session object created</p>";
    
    $session->set('test_key', 'test_value');
    $value = $session->get('test_key');
    
    if ($value === 'test_value') {
        echo "<p class='success'>✓ Session read/write works</p>";
    } else {
        echo "<p class='error'>✗ Session read/write failed</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Session error: " . $e->getMessage() . "</p>";
}

// Test 6: Password Hash Verification
echo "<h4>Test 6: Password Hash Test</h4>";
$testHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$testPass = 'password';

if (password_verify($testPass, $testHash)) {
    echo "<p class='success'>✓ Password verification works</p>";
} else {
    echo "<p class='error'>✗ Password verification failed</p>";
}

echo "<hr><div class='alert alert-info'>";
echo "<h5>All Tests Complete</h5>";
echo "<p>If all tests passed, you can proceed to:</p>";
echo "<a href='views/auth/login.php' class='btn btn-primary'>Login Page</a> ";
echo "<a href='index.php' class='btn btn-secondary'>Home Page</a>";
echo "</div>";

echo "</div></body></html>";
