<?php
/**
 * Lecturer Logout - Logout from lecturer module only
 * Does not affect other module sessions (admin, student, finance)
 */
require_once '../../config.php';

// Ensure session is started before creating Auth objects
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize auth with lecturer module context
$auth = new Auth('lecturer');

// Perform module-specific logout (logs activity internally & clears lecturer session keys)
$auth->logout();

// Set success message
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Redirect to lecturer login page (flash message already set)
header('Location: login.php');
exit;
