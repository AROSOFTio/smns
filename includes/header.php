<?php
/**
 * Common Header
 */
if (!defined('BASE_PATH')) {
    require_once '../../config.php';
}

Security::requireAuth();
$auth = new Auth();
$currentUser = $auth->getCurrentUser();
$session = new Session();

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
