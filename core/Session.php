<?php
/**
 * Session Management Class
 * Handles session operations with security measures
 * Supports module-isolated sessions for multi-role access
 */
class Session {
    private $role = null;
    
    public function __construct($role = null) {
        $this->role = $role;  // Store role first
        
        if (session_status() === PHP_SESSION_NONE) {
            // Configure secure session
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', 0); // Set to 1 in production with HTTPS
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600);
            ini_set('session.use_strict_mode', 1);
            
            // Use unified session name for all users
            session_name('SMNS_SESSION');
            
            // Start session
            session_start();
            
            // Initialize session security fingerprint
            if (!isset($_SESSION['initialized'])) {
                $this->initializeSession();
            }
            
            // Only validate if this is a role-specific session (not public)
            // Public sessions are for login page and don't need strict validation
            if ($this->role) {
                // Validate session fingerprint and role match
                if (!$this->validateSession()) {
                    $this->destroy();
                    session_start();
                    $this->initializeSession();
                }
                
                // Regenerate session ID periodically
                if (isset($_SESSION['created'])) {
                    if (time() - $_SESSION['created'] > 1800) { // 30 minutes
                        $this->regenerateId();
                    }
                }
            }
            
            // Update last activity
            $_SESSION['last_activity'] = time();
        } else {
            // Session already started, set role if provided
            if ($role && !$this->role) {
                $this->role = $role;
            }
        }
    }
    
    /**
     * Initialize session with security fingerprint
     */
    private function initializeSession() {
        $_SESSION['initialized'] = true;
        $_SESSION['created'] = time();
        $_SESSION['fingerprint'] = $this->generateFingerprint();
        $_SESSION['ip_address'] = $this->getClientIP();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($this->role) {
            $_SESSION['session_role'] = $this->role;
        }
    }
    
    /**
     * Generate session fingerprint including role for isolation
     */
    private function generateFingerprint() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ipAddress = $this->getClientIP();
        $role = $this->role ?? 'public';
        return hash('sha256', $userAgent . $ipAddress . $role . session_id());
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? '';
        }
    }
    
    /**
     * Validate session fingerprint and role match
     */
    private function validateSession() {
        if (!isset($_SESSION['fingerprint']) || !isset($_SESSION['ip_address'])) {
            return false;
        }
        
        // Check IP address hasn't changed
        if ($_SESSION['ip_address'] !== $this->getClientIP()) {
            return false;
        }
        
        // Check user agent hasn't changed
        if (isset($_SESSION['user_agent'])) {
            $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ($_SESSION['user_agent'] !== $currentUserAgent) {
                return false;
            }
        }
        
        // Check role matches if role is set (for role-specific sessions)
        if ($this->role && isset($_SESSION['session_role'])) {
            if ($_SESSION['session_role'] !== $this->role) {
                return false;
            }
        }
        
        // Verify user role matches session role for logged in users
        if (isset($_SESSION['role']) && isset($_SESSION['session_role'])) {
            if ($_SESSION['role'] !== $_SESSION['session_role']) {
                return false;
            }
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity'])) {
            $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
            if (time() - $_SESSION['last_activity'] > $timeout) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Regenerate session ID
     */
    public function regenerateId() {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
        $_SESSION['fingerprint'] = $this->generateFingerprint();
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
     * Get current module/role context
     */
    public function getRole() {
        return $this->role;
    }
    
    /**
     * Set module-specific session variable
     */
    public function setModule($key, $value) {
        $prefix = $this->role ? $this->role . '_' : '';
        $_SESSION[$prefix . $key] = $value;
    }
    
    /**
     * Get module-specific session variable
     */
    public function getModule($key, $default = null) {
        $prefix = $this->role ? $this->role . '_' : '';
        return $_SESSION[$prefix . $key] ?? $default;
    }
    
    /**
     * Check if module-specific session variable exists
     */
    public function hasModule($key) {
        $prefix = $this->role ? $this->role . '_' : '';
        return isset($_SESSION[$prefix . $key]);
    }
    
    /**
     * Clear only module-specific session data
     * Preserves other modules' sessions
     */
    public function clearModule() {
        if (!$this->role) return;
        
        $prefix = $this->role . '_';
        $keysToRemove = [];
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                $keysToRemove[] = $key;
            }
        }
        foreach ($keysToRemove as $key) {
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
