<?php
/**
 * Session Management Class
 * Handles session operations with security measures
 * Supports module-isolated sessions for multi-role access
 * 
 * Each role (admin, student, lecturer, finance) uses a separate session cookie.
 * This allows multiple roles to be logged in simultaneously in different browser tabs.
 */
class Session {
    private $role = null;
    
    public function __construct($role = null) {
        $this->role = $role;
        
        $hasActiveSession = (session_status() === PHP_SESSION_ACTIVE);

        // Determine the correct session name for this context.
        // If no role is supplied and a session is already active, keep that active session name
        // to avoid switching away from module sessions mid-request.
        if ($this->role) {
            $desiredName = 'SMNS_' . strtoupper($this->role) . '_SESSION';
        } elseif ($hasActiveSession) {
            $desiredName = session_name();
        } else {
            $desiredName = 'SMNS_PUBLIC_SESSION';
        }
        
        // If headers have already been sent, we cannot change ini settings or start a new session.
        // In that case, we simply rely on whatever session is already active (if any).
        if (headers_sent()) {
            return;
        }
        
        if ($hasActiveSession) {
            // A session is already active - check if it's the correct one
            if ($desiredName && session_name() !== $desiredName) {
                // Wrong session is active (e.g. a page called session_start() before us)
                // Close it and start the correct one
                session_write_close();
                
                // Configure and start the correct session
                ini_set('session.cookie_httponly', 1);
                ini_set('session.use_only_cookies', 1);
                ini_set('session.cookie_secure', 0);
                ini_set('session.cookie_samesite', 'Strict');
                ini_set('session.gc_maxlifetime', defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600);
                ini_set('session.use_strict_mode', 1);
                
                session_name($desiredName);
                session_start();
            }
            // If same name, session is already correct - do nothing
        } else {
            // No active session - configure and start fresh
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', 0);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600);
            ini_set('session.use_strict_mode', 1);
            
            if (!empty($desiredName)) {
                session_name($desiredName);
            }
            session_start();
        }
        
        // Check session timeout for logged-in users
        if ($this->role) {
            $loggedInKey = $this->role . '_logged_in';
            if (isset($_SESSION[$loggedInKey]) && $_SESSION[$loggedInKey] === true) {
                $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
                if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
                    // Session expired - clear this module's data
                    $this->clearModule();
                }
            }
        }
        
        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Regenerate session ID (call after login for security)
     */
    public function regenerateId() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
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
     * Destroy session completely
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
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
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
