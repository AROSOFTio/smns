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
     * Restrict access by role
     */
    public static function requireRole($allowedRoles) {
        require_once __DIR__ . '/Auth.php';
        $auth = new Auth();
        
        if (!$auth->isLoggedIn()) {
            header('Location: ' . BASE_URL . '/views/auth/login.php');
            exit;
        }
        
        $currentUser = $auth->getCurrentUser();
        $userRole = $currentUser['role'];
        
        if (!in_array($userRole, (array)$allowedRoles)) {
            header('Location: ' . BASE_URL . '/index.php?error=access_denied');
            exit;
        }
    }
    
    /**
     * Require authentication
     */
    public static function requireAuth() {
        require_once __DIR__ . '/Auth.php';
        $auth = new Auth();
        
        if (!$auth->isLoggedIn()) {
            header('Location: ' . BASE_URL . '/views/auth/login.php');
            exit;
        }
    }
}
