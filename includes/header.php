<?php
/**
 * Common Header
 */
if (!defined('BASE_PATH')) {
    require_once '../../config.php';
}

Security::requireAuth();

// Reuse existing auth and session objects if already initialized by the page
// This preserves role-specific session isolation
if (!isset($auth) || !is_object($auth)) {
    // Try to detect role from current session or URL path
    $detectedRole = null;
    if (isset($_SESSION['role'])) {
        $detectedRole = $_SESSION['role'];
    } elseif (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
        $detectedRole = 'admin';
    } elseif (strpos($_SERVER['PHP_SELF'], '/student/') !== false) {
        $detectedRole = 'student';
    } elseif (strpos($_SERVER['PHP_SELF'], '/lecturer/') !== false) {
        $detectedRole = 'lecturer';
    } elseif (strpos($_SERVER['PHP_SELF'], '/finance/') !== false) {
        $detectedRole = 'finance';
    }
    
    $auth = new Auth($detectedRole);
}

if (!isset($session) || !is_object($session)) {
    // Use the same role as auth if detected
    $detectedRole = null;
    if (isset($auth) && isset($_SESSION['role'])) {
        $detectedRole = $_SESSION['role'];
    }
    $session = new Session($detectedRole);
}

$currentUser = $auth->getCurrentUser();

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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <?php if (isset($additionalCSS)): ?>
        <?php foreach($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/assets/css/' . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <div class="wrapper">
