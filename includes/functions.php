<?php
/**
 * Global Utility Functions
 * Common functions used throughout the application
 */

/**
 * Get base URL
 */
function baseUrl($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Get asset URL
 */
function asset($path = '') {
    return BASE_URL . '/assets/' . ltrim($path, '/');
}

/**
 * Include view
 */
function view($path, $data = []) {
    extract($data);
    $viewPath = BASE_PATH . '/views/' . $path . '.php';
    if (file_exists($viewPath)) {
        include $viewPath;
    } else {
        throw new Exception("View not found: $path");
    }
}

/**
 * Redirect helper
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Get flash message
 * Uses existing session if available, otherwise creates public session
 */
function getFlash($key) {
    global $session;
    if (isset($session) && is_object($session)) {
        return $session->getFlash($key);
    }
    $tempSession = new Session();
    return $tempSession->getFlash($key);
}

/**
 * Set flash message
 * Uses existing session if available, otherwise creates public session
 */
function setFlash($key, $message) {
    global $session;
    if (isset($session) && is_object($session)) {
        $session->setFlash($key, $message);
    } else {
        $tempSession = new Session();
        $tempSession->setFlash($key, $message);
    }
}

/**
 * Escape HTML
 */
function e($string) {
    if ($string === null) {
        return '';
    }
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Debug dump
 */
function dd($var) {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    die;
}

/**
 * Check if user is logged in
 * Uses existing auth if available
 */
function isLoggedIn() {
    global $auth;
    if (isset($auth) && is_object($auth)) {
        return $auth->isLoggedIn();
    }
    // Try to detect from any active session
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Get current user
 * Uses existing auth if available
 */
function currentUser() {
    global $auth;
    if (isset($auth) && is_object($auth)) {
        return $auth->getCurrentUser();
    }
    return null;
}

/**
 * Check user role
 * Uses existing auth if available
 */
function hasRole($role) {
    global $auth;
    if (isset($auth) && is_object($auth)) {
        return $auth->hasRole($role);
    }
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * OLD input value (for form repopulation)
 */
function old($key, $default = '') {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/**
 * CSRF token field
 */
function csrfField() {
    $token = Security::generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Get setting value
 */
function getSetting($key, $default = null) {
    static $settings = null;
    
    if ($settings === null) {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings[$key] ?? $default;
}
