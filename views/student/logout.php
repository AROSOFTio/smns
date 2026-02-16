<?php
/**
 * Student Logout - Logout from student module only
 * Does not affect other module sessions (admin, lecturer, finance)
 */
require_once '../../config.php';

// Ensure session is started before creating Auth objects


// Initialize auth with student module context
$auth = new Auth('student');

// Perform module-specific logout (logs activity internally & clears student session keys)
$auth->logout();

// Set success message BEFORE closing session
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Force session write to ensure changes are committed
session_write_close();

// Redirect to student login page (flash message already set)
header('Location: login.php');
exit;
