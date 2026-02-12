<?php
/**
 * Main Entry Point / Landing Page
 * Redirects to appropriate dashboard based on user role
 */
require_once 'config.php';

// Start session
$session = new Session();
$auth = new Auth();

// Check if user is logged in
if ($auth->isLoggedIn()) {
    $user = $auth->getCurrentUser();
    
    // Redirect based on role
    switch($user['role']) {
        case 'admin':
            header('Location: views/admin/dashboard.php');
            break;
        case 'student':
            header('Location: views/student/dashboard.php');
            break;
        case 'lecturer':
            header('Location: views/lecturer/dashboard.php');
            break;
        case 'finance':
            header('Location: views/finance/dashboard.php');
            break;
        default:
            header('Location: views/auth/login.php');
    }
    exit;
}

// If not logged in, redirect to login page
header('Location: views/auth/login.php');
exit;
