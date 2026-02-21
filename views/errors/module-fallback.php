<?php
/**
 * Module-aware error fallback redirect.
 * Keeps users inside their active module when invalid/missing pages are requested.
 */

if (!function_exists('smnsErrorModuleMap')) {
    function smnsErrorModuleMap() {
        return [
            'admin' => [
                'dashboard' => BASE_URL . '/views/admin/dashboard.php',
                'login' => BASE_URL . '/views/admin/login.php',
            ],
            'student' => [
                'dashboard' => BASE_URL . '/views/student/dashboard.php',
                'login' => BASE_URL . '/views/student/login.php',
            ],
            'lecturer' => [
                'dashboard' => BASE_URL . '/views/lecturer/dashboard.php',
                'login' => BASE_URL . '/views/lecturer/login.php',
            ],
            'finance' => [
                'dashboard' => BASE_URL . '/views/finance/dashboard.php',
                'login' => BASE_URL . '/views/finance/login.php',
            ],
        ];
    }
}

if (!function_exists('smnsDetectModuleFromPath')) {
    function smnsDetectModuleFromPath($rawPath) {
        $path = strtolower(str_replace('\\', '/', (string)$rawPath));
        $path = ltrim($path, '/');
        if ($path === '') {
            return null;
        }

        if (strpos($path, 'smns/views/admin/') !== false || strpos($path, 'views/admin/') === 0 || strpos($path, 'admin/') === 0) return 'admin';
        if (strpos($path, 'smns/views/student/') !== false || strpos($path, 'views/student/') === 0 || strpos($path, 'student/') === 0) return 'student';
        if (strpos($path, 'smns/views/lecturer/') !== false || strpos($path, 'views/lecturer/') === 0 || strpos($path, 'lecturer/') === 0) return 'lecturer';
        if (strpos($path, 'smns/views/finance/') !== false || strpos($path, 'views/finance/') === 0 || strpos($path, 'finance/') === 0) return 'finance';

        return null;
    }
}

if (!function_exists('smnsIsRoleSessionActiveFromErrorPage')) {
    function smnsIsRoleSessionActiveFromErrorPage($role) {
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
}

if (!function_exists('smnsRedirectFromErrorContext')) {
    function smnsRedirectFromErrorContext() {
        $modules = smnsErrorModuleMap();
        $roles = array_keys($modules);

        $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $refererPath = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '';

        $targetRole = smnsDetectModuleFromPath($requestedPath);
        if ($targetRole === null) {
            $targetRole = smnsDetectModuleFromPath($refererPath);
        }

        $activeRoles = [];
        foreach ($roles as $role) {
            if (smnsIsRoleSessionActiveFromErrorPage($role)) {
                $activeRoles[] = $role;
            }
        }

        if ($targetRole !== null) {
            if (in_array($targetRole, $activeRoles, true)) {
                header('Location: ' . $modules[$targetRole]['dashboard']);
                exit;
            }
            header('Location: ' . $modules[$targetRole]['login']);
            exit;
        }

        if (!empty($activeRoles)) {
            $primaryRole = $activeRoles[0];
            header('Location: ' . $modules[$primaryRole]['dashboard']);
            exit;
        }

        header('Location: ' . BASE_URL . '/views/auth/login.php');
        exit;
    }
}
