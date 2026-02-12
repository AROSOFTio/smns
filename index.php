<?php
/**
 * Main Entry Point / Landing Page
 * Redirects to login or appropriate dashboard
 */
require_once 'config.php';

// Check all role-specific sessions to find active login
$roles = ['admin', 'student', 'lecturer', 'finance'];
$activeRole = null;

foreach ($roles as $role) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    session_name('SMNS_' . strtoupper($role) . '_SESSION');
    @session_start();
    
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $activeRole = $role;
        session_write_close();
        
        // Redirect to appropriate dashboard based on role
        switch($activeRole) {
            case 'admin':
                header('Location: views/admin/dashboard.php');
                exit;
            case 'student':
                header('Location: views/student/dashboard.php');
                exit;
            case 'lecturer':
                header('Location: views/lecturer/dashboard.php');
                exit;
            case 'finance':
                header('Location: views/finance/dashboard.php');
                exit;
        }
    }
}

// No active session - close any open session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Redirect to unified login page
header('Location: views/auth/login.php');
exit;
