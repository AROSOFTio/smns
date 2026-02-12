<?php
/**
 * Logout Page - Handles logout for current user
 */
require_once '../../config.php';

// Try to detect which role session is active for THIS user
$roles = ['admin', 'student', 'lecturer', 'finance'];
$activeRole = null;

// Check which role session THIS user has active
foreach ($roles as $role) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    session_name('SMNS_' . strtoupper($role) . '_SESSION');
    session_start();
    
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $activeRole = $role;
        
        // Found the active session for THIS user - logout and destroy it
        $auth = new Auth($activeRole);
        $auth->logout();
        break;
    } else {
        session_write_close();
    }
}

// Start public session for flash message
session_name('SMNS_PUBLIC_SESSION');
session_start();
$_SESSION['flash_success'] = 'You have been successfully logged out.';

// Redirect to unified login page
header('Location: login.php');
exit;
exit;
