<?php
/**
 * Common Header
 *
 * NOTE: Access control and session initialization are handled by each
 * module/page before including this header. This file should NOT start
 * sessions or redirect users; it only renders the common layout using
 * the already-available $currentUser (if provided).
 */
if (!defined('BASE_PATH')) {
    require_once '../../config.php';
}

// Only derive $currentUser if the page has already created an Auth instance
if (!isset($currentUser) || !is_array($currentUser)) {
    if (isset($auth) && is_object($auth)) {
        $currentUser = $auth->getCurrentUser();
    } else {
        $currentUser = null;
    }
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
