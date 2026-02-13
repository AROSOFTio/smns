<?php
/**
 * Student Logout - Logout from student module only
 * Does not affect other module sessions (admin, lecturer, finance)
 */
require_once '../../config.php';

// Ensure session is started before creating Auth objects
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize auth with student module context
$auth = new Auth('student');

// Perform module-specific logout (logs activity internally & clears student session keys)
$auth->logout();

// Set success message
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Redirect to student login page (flash message already set)
header('Location: login.php');
exit;
