<?php
/**
 * Session Management Class
 * Handles session operations
 */
class Session {
    
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', 0); // Set to 1 in production with HTTPS
            ini_set('session.gc_maxlifetime', defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600);
            
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } else if (time() - $_SESSION['created'] > 1800) {
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
    }
    
    /**
     * Set session variable
     */
    public function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get session variable
     */
    public function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Check if session variable exists
     */
    public function has($key) {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove session variable
     */
    public function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Destroy session
     */
    public function destroy() {
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
    
    /**
     * Flash message - set
     */
    public function setFlash($key, $message) {
        $_SESSION['flash'][$key] = $message;
    }
    
    /**
     * Flash message - get and remove
     */
    public function getFlash($key) {
        if (isset($_SESSION['flash'][$key])) {
            $message = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $message;
        }
        return null;
    }
    
    /**
     * Check if flash message exists
     */
    public function hasFlash($key) {
        return isset($_SESSION['flash'][$key]);
    }
}
