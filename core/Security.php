<?php
/**
 * Security Utilities Class
 * Provides security functions for input validation, XSS prevention, CSRF protection
 */
class Security {

    /**
     * Detect module role from current request path.
     */
    private static function detectRoleFromRequestPath() {
        $path = strtolower($_SERVER['PHP_SELF'] ?? $_SERVER['REQUEST_URI'] ?? '');
        if (strpos($path, '/views/admin/') !== false || strpos($path, '\\views\\admin\\') !== false) {
            return 'admin';
        }
        if (strpos($path, '/views/student/') !== false || strpos($path, '\\views\\student\\') !== false) {
            return 'student';
        }
        if (strpos($path, '/views/lecturer/') !== false || strpos($path, '\\views\\lecturer\\') !== false) {
            return 'lecturer';
        }
        if (strpos($path, '/views/finance/') !== false || strpos($path, '\\views\\finance\\') !== false) {
            return 'finance';
        }
        return null;
    }

    /**
     * Ensure a session is started using role-aware session isolation.
     */
    private static function ensureRoleAwareSession() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $role = self::detectRoleFromRequestPath();
        if (class_exists('Session')) {
            new Session($role);
            return;
        }

        // Fallback if Session class is unavailable for any reason.
        session_start();
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        self::ensureRoleAwareSession();
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     */
    public static function verifyCSRFToken($token) {
        self::ensureRoleAwareSession();
        
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
     * Validate password against configured policy.
     */
    public static function validatePasswordPolicy($password, &$errors = []) {
        $errors = [];
        $pwd = (string)$password;
        $minLen = defined('PASSWORD_MIN_LENGTH') ? (int)PASSWORD_MIN_LENGTH : 8;
        if (strlen($pwd) < $minLen) {
            $errors[] = 'Password must be at least ' . $minLen . ' characters.';
        }
        if ((defined('PASSWORD_REQUIRE_UPPERCASE') ? PASSWORD_REQUIRE_UPPERCASE : false) && !preg_match('/[A-Z]/', $pwd)) {
            $errors[] = 'Password must include at least one uppercase letter.';
        }
        if ((defined('PASSWORD_REQUIRE_LOWERCASE') ? PASSWORD_REQUIRE_LOWERCASE : false) && !preg_match('/[a-z]/', $pwd)) {
            $errors[] = 'Password must include at least one lowercase letter.';
        }
        if ((defined('PASSWORD_REQUIRE_NUMBER') ? PASSWORD_REQUIRE_NUMBER : false) && !preg_match('/[0-9]/', $pwd)) {
            $errors[] = 'Password must include at least one number.';
        }
        if ((defined('PASSWORD_REQUIRE_SPECIAL') ? PASSWORD_REQUIRE_SPECIAL : false) && !preg_match('/[^A-Za-z0-9]/', $pwd)) {
            $errors[] = 'Password must include at least one special character.';
        }
        return empty($errors);
    }

    /**
     * Check whether a password appears in recent password history.
     */
    public static function isPasswordReused($conn, $userId, $password, $limit = null) {
        if (!($conn instanceof PDO)) {
            return false;
        }
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $historyLimit = $limit !== null ? (int)$limit : (defined('PASSWORD_HISTORY_LIMIT') ? (int)PASSWORD_HISTORY_LIMIT : 5);
        if ($historyLimit < 1) {
            $historyLimit = 5;
        }
        try {
            $stmt = $conn->prepare("
                SELECT password_hash
                FROM password_history
                WHERE user_id = :user_id
                ORDER BY id DESC
                LIMIT {$historyLimit}
            ");
            $stmt->execute(['user_id' => $userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['password_hash']) && password_verify((string)$password, (string)$row['password_hash'])) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
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
        $detectedRole = self::detectRoleFromRequestPath();
        if (!$detectedRole && session_status() === PHP_SESSION_ACTIVE) {
            $sessionRoleMap = [
                'SMNS_ADMIN_SESSION' => 'admin',
                'SMNS_STUDENT_SESSION' => 'student',
                'SMNS_LECTURER_SESSION' => 'lecturer',
                'SMNS_FINANCE_SESSION' => 'finance',
            ];
            $detectedRole = $sessionRoleMap[session_name()] ?? null;
        }
        if (!$detectedRole && isset($_SESSION['role'])) {
            $detectedRole = $_SESSION['role'];
        }
        
        // Use role-specific session
        $session = new Session($detectedRole);
        $auth = new Auth($detectedRole);

        $loginTarget = BASE_URL . '/views/auth/login.php';
        
        // Check if user is logged in
        if (!$auth->isLoggedIn()) {
            // Store the intended destination
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
            $redirectUrl = $loginTarget;
            if ($detectedRole) {
                $redirectUrl .= '?role=' . urlencode((string)$detectedRole);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // Validate session token exists (check module-prefixed or legacy token)
        $hasToken = false;
        if ($detectedRole && $session->hasModule('session_token')) {
            $hasToken = true;
        } elseif ($session->has('session_token')) {
            $hasToken = true;
        }
        if (!$hasToken) {
            $auth->logout();
            $redirectUrl = $loginTarget . '?error=invalid_session';
            if ($detectedRole) {
                $redirectUrl .= '&role=' . urlencode((string)$detectedRole);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        $currentUser = $auth->getCurrentUser();
        $userRole = $currentUser['role'];
        
        // Verify the session role matches the user role
        if (isset($_SESSION['session_role']) && $_SESSION['session_role'] !== $userRole) {
            // Role mismatch - possible session tampering
            error_log("Session role mismatch detected for user {$currentUser['username']}");
            $auth->logout();
            $redirectUrl = $loginTarget . '?error=invalid_session';
            if ($detectedRole) {
                $redirectUrl .= '&role=' . urlencode((string)$detectedRole);
            }
            header('Location: ' . $redirectUrl);
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

        $detectedRole = self::detectRoleFromRequestPath();
        $session = new Session($detectedRole);
        $auth = new Auth($detectedRole);

        $loginTarget = BASE_URL . '/views/auth/login.php';
        
        if (!$auth->isLoggedIn()) {
            // Store the intended destination
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
            $redirectUrl = $loginTarget;
            if ($detectedRole) {
                $redirectUrl .= '?role=' . urlencode((string)$detectedRole);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // Validate session token (module-specific if role-aware)
        $hasToken = $detectedRole ? $session->hasModule('session_token') : $session->has('session_token');
        if (!$hasToken) {
            $auth->logout();
            $redirectUrl = $loginTarget . '?error=invalid_session';
            if ($detectedRole) {
                $redirectUrl .= '&role=' . urlencode((string)$detectedRole);
            }
            header('Location: ' . $redirectUrl);
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

        $role = self::detectRoleFromRequestPath();
        if ($role) {
            if (!isset($_SESSION[$role . '_user_id']) || !isset($_SESSION[$role . '_role'])) {
                return false;
            }
            if ($_SESSION[$role . '_role'] !== $role) {
                return false;
            }
        } else {
            // Legacy/global fallback
            if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
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
}
