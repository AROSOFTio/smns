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

$requestPath = $_SERVER['REQUEST_URI'] ?? '';
$isStudentPortalPage = (strpos($requestPath, '/views/student/') !== false);
$isAdminPage = (strpos($requestPath, '/views/admin/') !== false || strpos($requestPath, '/admin/') !== false);
$bodyClasses = [];
if ($isAdminPage) {
    $bodyClasses[] = 'smns-admin-mobile-shell';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var mode = localStorage.getItem('smns_theme_mode');
                if (mode === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
            } catch (e) {}
        })();
        window.SMNS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.SMNS_APP_VERSION = <?php echo json_encode((string)APP_VERSION); ?>;
        window.SMNS_LOGO_URL = <?php echo json_encode(BASE_URL . '/assets/img/sem.PNG?v=' . urlencode((string)APP_VERSION)); ?>;
    </script>
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>
    <link rel="preload" as="image" href="<?php echo BASE_URL; ?>/assets/img/sem.PNG?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/theme-shared.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/responsive-nav.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <?php if (isset($additionalCSS)): ?>
        <?php foreach($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/assets/css/' . $css . '?v=' . urlencode((string)APP_VERSION); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($isStudentPortalPage): ?>
    <style>
        /* Student portal typography normalization: keep text medium and consistent */
        .student-sidebar li { font-size: 0.82rem !important; }
        .student-sidebar .services-submenu li,
        .student-sidebar .programme-submenu li,
        .student-sidebar .enroll-submenu li { font-size: 0.79rem !important; }
        .main-content { font-size: 0.9rem; }
        .main-content h1 { font-size: 1.28rem !important; }
        .main-content h2 { font-size: 1.18rem !important; }
        .main-content h3 { font-size: 1.08rem !important; }
        .main-content h4 { font-size: 1rem !important; }
        .main-content h5 { font-size: 0.94rem !important; }
        .mail-title { font-size: 1.25rem !important; }
        .mail-folder { font-size: 0.92rem !important; }
        .mail-empty { font-size: 0.92rem !important; }
        .cal-title { font-size: 1.3rem !important; }
        .cal-block-head { font-size: 1.12rem !important; }
        .cal-table th,
        .cal-table td { font-size: 0.9rem !important; }
        .history-title { font-size: 1rem !important; }
    </style>
    <?php endif; ?>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . e(implode(' ', $bodyClasses)) . '"' : ''; ?>>
    <div class="wrapper">
        <img
            id="smnsSharedLogoAsset"
            src="<?php echo BASE_URL; ?>/assets/img/sem.PNG?v=<?php echo urlencode((string)APP_VERSION); ?>"
            alt=""
            aria-hidden="true"
            style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px;top:-9999px;"
        >
