<?php
/**
 * Lecturer Logout - Logout from lecturer module only
 * Does not affect other module sessions (admin, student, finance)
 */
require_once '../../config.php';

// Ensure session is started before creating Auth objects


// Initialize auth with lecturer module context
$auth = new Auth('lecturer');

// Perform module-specific logout (logs activity internally & clears lecturer session keys)
$auth->logout();

// Set success message BEFORE closing session
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Force session write to ensure changes are committed
session_write_close();

// Redirect to unified login page (flash message already set)
header('Location: ' . BASE_URL . '/views/auth/login.php?action=logout&module=lecturer');
exit;
