<?php
/**
 * Security Utilities Class
 * Provides security functions for input validation, XSS prevention, CSRF protection
 */
class Security {
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     */
    public static function verifyCSRFToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Sanitize input
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Escape output
     */
    public static function escape($output) {
        return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate URL
     */
    public static function validateUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Hash password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate random password
     */
    public static function generatePassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        
        return $password;
    }
    
    /**
     * Prevent XSS
     */
    public static function cleanXSS($data) {
        $data = str_replace(['<', '>'], ['&lt;', '&gt;'], $data);
        $data = preg_replace('/javascript:/i', '', $data);
        $data = preg_replace('/on\w+\s*=\s*/i', '', $data);
        return $data;
    }
    
    /**
     * Validate SQL injection attempts
     */
    public static function validateSQL($input) {
        $patterns = [
            '/(\bUNION\b|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bDROP\b|\b--\b|\b#\b)/i',
            '/[\'\";]/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get client IP address
     */
    public static function getClientIP() {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    }
    
    /**
     * Restrict access by role with role-specific session handling
     */
    public static function requireRole($allowedRoles) {
        require_once __DIR__ . '/Auth.php';
        require_once __DIR__ . '/Session.php';
        
        // Detect role from URL path or session
        $detectedRole = null;
        if (isset($_SESSION['role'])) {
            $detectedRole = $_SESSION['role'];
        } elseif (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
            $detectedRole = 'admin';
        } elseif (strpos($_SERVER['PHP_SELF'], '/student/') !== false) {
            $detectedRole = 'student';
        } elseif (strpos($_SERVER['PHP_SELF'], '/lecturer/') !== false) {
            $detectedRole = 'lecturer';
        } elseif (strpos($_SERVER['PHP_SELF'], '/finance/') !== false) {
            $detectedRole = 'finance';
        }
        
        // Use role-specific session
        $session = new Session($detectedRole);
        $auth = new Auth($detectedRole);
        
        // Check if user is logged in
        if (!$auth->isLoggedIn()) {
            // Store the intended destination
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . BASE_URL . '/views/auth/login.php');
            exit;
        }
        
        // Validate session token exists
        if (!$session->has('session_token')) {
            // Invalid session, force logout
            $auth->logout();
            header('Location: ' . BASE_URL . '/views/auth/login.php?error=invalid_session');
            exit;
        }
        
        $currentUser = $auth->getCurrentUser();
        $userRole = $currentUser['role'];
        
        // Verify the session role matches the user role
        if (isset($_SESSION['session_role']) && $_SESSION['session_role'] !== $userRole) {
            // Role mismatch - possible session tampering
            error_log("Session role mismatch detected for user {$currentUser['username']}");
            $auth->logout();
            header('Location: ' . BASE_URL . '/views/auth/login.php?error=invalid_session');
            exit;
        }
        
        // Check if user has required role
        if (!in_array($userRole, (array)$allowedRoles)) {
            // Log unauthorized access attempt
            error_log("Unauthorized access attempt by user {$currentUser['username']} (role: {$userRole}) to restricted area requiring: " . implode(', ', (array)$allowedRoles));
            
            // Redirect to appropriate dashboard based on user's actual role
            switch($userRole) {
                case 'admin':
                    header('Location: ' . BASE_URL . '/views/admin/dashboard.php?error=access_denied');
                    break;
                case 'student':
                    header('Location: ' . BASE_URL . '/views/student/dashboard.php?error=access_denied');
                    break;
                case 'lecturer':
                    header('Location: ' . BASE_URL . '/views/lecturer/dashboard.php?error=access_denied');
                    break;
                case 'finance':
                    header('Location: ' . BASE_URL . '/views/finance/dashboard.php?error=access_denied');
                    break;
                default:
                    header('Location: ' . BASE_URL . '/index.php?error=access_denied');
            }
            exit;
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Require authentication
     */
    public static function requireAuth() {
        require_once __DIR__ . '/Auth.php';
        require_once __DIR__ . '/Session.php';
        
        $session = new Session();
        $auth = new Auth();
        
        if (!$auth->isLoggedIn()) {
            // Store the intended destination
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . BASE_URL . '/views/auth/login.php');
            exit;
        }
        
        // Validate session token
        if (!$session->has('session_token')) {
            $auth->logout();
            header('Location: ' . BASE_URL . '/views/auth/login.php?error=invalid_session');
            exit;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Validate user session integrity
     */
    public static function validateSessionIntegrity() {
        if (session_status() === PHP_SESSION_NONE) {
            return false;
        }
        
        // Check if session has required security markers
        if (!isset($_SESSION['fingerprint']) || !isset($_SESSION['ip_address'])) {
            return false;
        }
        
        // Check if session has user data
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            return false;
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
}
