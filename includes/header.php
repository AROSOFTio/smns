<?php
/**
 * Common Header
 */
if (!defined('BASE_PATH')) {
    require_once '../../config.php';
}

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Module-aware login check
// Each dashboard already does its own module-specific auth check before including this header.
// This check supports both module-specific sessions (admin_logged_in, student_logged_in, etc.)
// and the legacy 'logged_in' key for backward compatibility.
$isAuthenticated = false;

// Check module-specific sessions first
$modules = ['admin', 'student', 'lecturer', 'finance'];
foreach ($modules as $mod) {
    if (isset($_SESSION[$mod . '_logged_in']) && $_SESSION[$mod . '_logged_in'] === true) {
        $isAuthenticated = true;
        break;
    }
}

// Fallback to legacy session check
if (!$isAuthenticated && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $isAuthenticated = true;
}

if (!$isAuthenticated) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized');
    exit;
}

// Reuse existing auth and session objects if already initialized by the page
if (!isset($auth) || !is_object($auth)) {
    $auth = new Auth();
}

if (!isset($session) || !is_object($session)) {
    $session = new Session();
}

// Only override $currentUser if not already set by the dashboard
if (!isset($currentUser) || !is_array($currentUser)) {
    $currentUser = $auth->getCurrentUser();
}

// Get user initials
$initials = '';
if ($currentUser && isset($currentUser['profile'])) {
    $firstName = $currentUser['profile']['first_name'] ?? '';
    $lastName = $currentUser['profile']['last_name'] ?? '';
    $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/responsive-nav.css">
    <?php if (isset($additionalCSS)): ?>
        <?php foreach($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/assets/css/' . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <div class="wrapper">
