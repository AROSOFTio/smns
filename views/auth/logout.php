<?php
/**
 * Logout Page
 * Supports module-scoped logout by default and full logout when explicitly requested.
 */
require_once '../../config.php';

function detectLogoutModuleFromRequest() {
    $validRoles = ['admin', 'student', 'lecturer', 'finance'];
    $fromQuery = strtolower(trim((string)($_GET['module'] ?? '')));
    if (in_array($fromQuery, $validRoles, true)) {
        return $fromQuery;
    }

    $refererPath = strtolower(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?? '');
    if (strpos($refererPath, '/views/admin/') !== false) return 'admin';
    if (strpos($refererPath, '/views/student/') !== false) return 'student';
    if (strpos($refererPath, '/views/lecturer/') !== false) return 'lecturer';
    if (strpos($refererPath, '/views/finance/') !== false) return 'finance';

    return null;
}

function destroyRoleSession($role) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name('SMNS_' . strtoupper($role) . '_SESSION');
    @session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

function destroySsoSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('SMNS_SSO_SESSION');
    @session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    @session_destroy();
}

$module = detectLogoutModuleFromRequest();
$logoutAll = isset($_GET['all']) && $_GET['all'] === '1';

if ($module) {
    $auth = new Auth($module);
    $auth->logout();
    $_SESSION['flash_success'] = 'You have been logged out successfully.';
    session_write_close();
    header('Location: ' . BASE_URL . '/views/auth/login.php?action=logout&module=' . urlencode($module));
    exit;
}

if ($logoutAll) {
    destroySsoSession();
    foreach (['admin', 'student', 'lecturer', 'finance'] as $role) {
        destroyRoleSession($role);
    }

    // Public session flash for unified login page
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('SMNS_PUBLIC_SESSION');
    @session_start();
    $_SESSION['flash_success'] = 'You have been logged out successfully.';
    session_write_close();

    header('Location: ' . BASE_URL . '/views/auth/login.php?action=logout');
    exit;
}

// Default fallback: avoid destructive global logout unless explicitly requested.
header('Location: ' . BASE_URL . '/views/auth/login.php');
exit;
