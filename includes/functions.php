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
 */
function getFlash($key) {
    $session = new Session();
    return $session->getFlash($key);
}

/**
 * Set flash message
 */
function setFlash($key, $message) {
    $session = new Session();
    $session->setFlash($key, $message);
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
 */
function isLoggedIn() {
    $auth = new Auth();
    return $auth->isLoggedIn();
}

/**
 * Get current user
 */
function currentUser() {
    $auth = new Auth();
    return $auth->getCurrentUser();
}

/**
 * Check user role
 */
function hasRole($role) {
    $auth = new Auth();
    return $auth->hasRole($role);
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
