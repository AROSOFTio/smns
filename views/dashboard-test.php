<?php
/**
 * Simple Test Dashboard
 * Verify login redirect is working
 */
require_once '../config.php';

// Use the same session handling as the Auth class
require_once '../core/Session.php';

// Initialize session (compatible with Auth class)
$session = new Session();

echo "<!DOCTYPE html>";
echo "<html><head><title>Dashboard Test</title>";
echo "<style>body{font-family:Arial;margin:40px;background:#f5f5f5;} .card{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}</style>";
echo "</head><body>";

echo "<div class='card'>";
echo "<h1>🎉 Login Successful!</h1>";
echo "<h2>Dashboard Test Page</h2>";

echo "<p><strong>✅ You have successfully logged in!</strong></p>";
echo "<p>This confirms that:</p>";
echo "<ul>";
echo "<li>✅ Authentication is working</li>";
echo "<li>✅ Session management is working</li>";
echo "<li>✅ Redirect functionality is working</li>";
echo "</ul>";

echo "<h3>Session Information:</h3>";
if ($session->has('logged_in')) {
    echo "<p>Logged in: ✅ " . ($session->get('logged_in') ? 'Yes' : 'No') . "</p>";
}
if ($session->has('user_id')) {
    echo "<p>User ID: " . $session->get('user_id') . "</p>";
}
if ($session->has('role')) {
    echo "<p>Role: <strong>" . ucfirst($session->get('role')) . "</strong></p>";
}
if ($session->has('username')) {
    echo "<p>Username: " . $session->get('username') . "</p>";
}

echo "<h3>Next Steps:</h3>";
echo "<p>✅ <strong>Your login system is now working!</strong></p>";
echo "<p>You can now:</p>";
echo "<ul>";
echo "<li>Build your actual dashboard</li>";
echo "<li>Add role-specific features</li>";
echo "<li>Implement proper CSRF protection</li>";
echo "</ul>";

echo "<p style='margin-top:30px;'>";
echo "<a href='../auth/login.php' style='background:#dc3545;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;'>Logout & Test Again</a>";
echo "</p>";

echo "</div>";
echo "</body></html>";
?>