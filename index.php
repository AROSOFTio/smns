<?php
/**
 * Main Entry Point / Landing Page
 * Redirects to login or appropriate dashboard using module-isolated sessions
 */
require_once 'config.php';

function smnsGetModuleMap() {
    return [
        'admin' => [
            'dashboard' => 'views/admin/dashboard.php',
            'login' => 'views/admin/login.php',
        ],
        'student' => [
            'dashboard' => 'views/student/dashboard.php',
            'login' => 'views/student/login.php',
        ],
        'lecturer' => [
            'dashboard' => 'views/lecturer/dashboard.php',
            'login' => 'views/lecturer/login.php',
        ],
        'finance' => [
            'dashboard' => 'views/finance/dashboard.php',
            'login' => 'views/finance/login.php',
        ],
    ];
}

function smnsDetectRequestedModule($rawPath) {
    $path = strtolower(str_replace('\\', '/', (string)$rawPath));
    $path = ltrim($path, '/');
    if ($path === '') {
        return null;
    }

    if (strpos($path, 'views/admin/') === 0 || strpos($path, 'admin/') === 0) return 'admin';
    if (strpos($path, 'views/student/') === 0 || strpos($path, 'student/') === 0) return 'student';
    if (strpos($path, 'views/lecturer/') === 0 || strpos($path, 'lecturer/') === 0) return 'lecturer';
    if (strpos($path, 'views/finance/') === 0 || strpos($path, 'finance/') === 0) return 'finance';

    return null;
}

function smnsIsRoleSessionActive($role) {
    $cookieName = 'SMNS_' . strtoupper($role) . '_SESSION';
    if (empty($_COOKIE[$cookieName])) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($cookieName);
    @session_start();

    $active = !empty($_SESSION[$role . '_logged_in']) && (($_SESSION[$role . '_role'] ?? null) === $role);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $active;
}

$modules = smnsGetModuleMap();
$roles = array_keys($modules);
$activeRoles = [];
foreach ($roles as $role) {
    if (smnsIsRoleSessionActive($role)) {
        $activeRoles[] = $role;
    }
}

$requestedUrl = $_GET['url'] ?? '';
$requestedModule = smnsDetectRequestedModule($requestedUrl);

// If an invalid/missing page was requested inside a module, keep the user in that module flow.
if ($requestedModule !== null) {
    if (in_array($requestedModule, $activeRoles, true)) {
        header('Location: ' . $modules[$requestedModule]['dashboard']);
        exit;
    }

    header('Location: ' . $modules[$requestedModule]['login']);
    exit;
}

// Default landing behavior: send active users to their dashboard, otherwise to unified login.
if (!empty($activeRoles)) {
    if (defined('UNIFIED_RBAC_LOGIN') && UNIFIED_RBAC_LOGIN && !empty($_COOKIE['SMNS_SSO_SESSION'])) {
        header('Location: views/auth/module-hub.php');
        exit;
    }
    $primaryRole = $activeRoles[0];
    header('Location: ' . $modules[$primaryRole]['dashboard']);
    exit;
}

header('Location: views/auth/login.php');
exit;
