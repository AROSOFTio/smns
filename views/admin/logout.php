<?php
/**
 * Admin Logout - Logout from admin module only
 * Does not affect other module sessions (student, lecturer, finance)
 */
require_once '../../config.php';

// Ensure session is started before creating Session/Auth objects
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize auth with admin module context
$auth = new Auth('admin');

// Perform module-specific logout (logs activity internally & clears admin session keys)
$auth->logout();

// Set success message BEFORE closing session
$_SESSION['flash_success'] = 'You have been logged out successfully.';

// Force session write to ensure changes are committed
session_write_close();

// Redirect to admin login page (flash message already set)
header('Location: login.php');
exit;
