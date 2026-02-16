<?php
/**
 * Logout Page - Secure logout for all users
 * Destroys the entire session (all modules)
 */
require_once '../../config.php';

// Ensure session is started


// Initialize auth
$auth = new Auth();

// Log logout activity and destroy session
$auth->logout();

// Destroy the entire session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Start fresh session for flash message
session_start();
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Redirect to login page
header('Location: login.php?action=logout');
exit;
