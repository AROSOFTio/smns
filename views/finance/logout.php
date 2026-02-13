<?php
/**
 * Finance Logout - Logout from finance module only
 * Does not affect other module sessions (admin, student, lecturer)
 */
require_once '../../config.php';

// Ensure session is started before creating Auth objects
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize auth with finance module context
$auth = new Auth('finance');

// Perform module-specific logout (logs activity internally & clears finance session keys)
$auth->logout();

// Set success message
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Redirect to finance login page (flash message already set)
header('Location: login.php');
exit;
