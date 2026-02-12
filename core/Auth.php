<?php
/**
 * Authentication Handler Class
 * Handles user authentication, login, logout, and session management
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

class Auth {
    private $db;
    private $session;
    
    public function __construct($role = null) {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->session = new Session($role);
    }
    
    /**
     * Authenticate user with username and password
     */
    public function login($username, $password) {
        try {
            // Check if account is locked
            $sql = "SELECT * FROM users WHERE username = :username OR email = :email";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['username' => $username, 'email' => $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Check if account is locked
            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                return ['success' => false, 'message' => 'Account is locked. Please try again later.'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                // Increment failed login attempts
                $this->incrementFailedAttempts($user['id']);
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Check if account is active
            if ($user['status'] !== 'active') {
                return ['success' => false, 'message' => 'Account is not active'];
            }
            
            // Reset failed attempts
            $this->resetFailedAttempts($user['id']);
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Get user profile based on role
            $profile = $this->getUserProfile($user['id'], $user['role']);
            
            // Clear current user's session data only (not other users!)
            // Regenerate session ID for security (prevent session fixation)
            session_regenerate_id(true);
            $_SESSION = []; // Clear only THIS user's session data
            
            // Start new role-specific session for THIS user
            $this->session = new Session($user['role']);
            
            // Set session with security markers for THIS user only
            $this->session->set('user_id', $user['id']);
            $this->session->set('username', $user['username']);
            $this->session->set('email', $user['email']);
            $this->session->set('role', $user['role']);
            $this->session->set('session_role', $user['role']);
            $this->session->set('profile', $profile);
            $this->session->set('logged_in', true);
            $this->session->set('login_time', time());
            $this->session->set('session_token', bin2hex(random_bytes(32)));
            
            // Log activity
            $this->logActivity($user['id'], 'login', 'authentication', 'User logged in successfully');
            
            return ['success' => true, 'role' => $user['role'], 'user' => $profile];
        } catch(Exception $e) {
            error_log("Login error: " . $e->getMessage());
            // Show detailed error in development mode
            if (defined('APP_DEBUG') && APP_DEBUG) {
                return ['success' => false, 'message' => 'Login error: ' . $e->getMessage()];
            }
            return ['success' => false, 'message' => 'An error occurred during login'];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        $userId = $this->session->get('user_id');
        if ($userId) {
            $this->logActivity($userId, 'logout', 'authentication', 'User logged out');
        }
        $this->session->destroy();
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return $this->session->get('logged_in') === true;
    }
    
    /**
     * Check if user has specific role
     */
    public function hasRole($role) {
        return $this->session->get('role') === $role;
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->hasRole('admin');
    }
    
    /**
     * Check if user is student
     */
    public function isStudent() {
        return $this->hasRole('student');
    }
    
    /**
     * Check if user is lecturer
     */
    public function isLecturer() {
        return $this->hasRole('lecturer');
    }
    
    /**
     * Check if user is finance staff
     */
    public function isFinance() {
        return $this->hasRole('finance');
    }
    
    /**
     * Get current user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $this->session->get('user_id'),
            'username' => $this->session->get('username'),
            'email' => $this->session->get('email'),
            'role' => $this->session->get('role'),
            'profile' => $this->session->get('profile')
        ];
    }
    
    /**
     * Get user profile based on role
     */
    private function getUserProfile($userId, $role) {
        $profileTable = '';
        switch($role) {
            case 'student':
                $profileTable = 'students';
                break;
            case 'lecturer':
                $profileTable = 'lecturers';
                break;
            case 'admin':
                $profileTable = 'admins';
                break;
            case 'finance':
                $profileTable = 'finance_staff';
                break;
        }
        
        if ($profileTable) {
            $sql = "SELECT * FROM $profileTable WHERE user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $userId]);
            return $stmt->fetch();
        }
        
        return null;
    }
    
    /**
     * Increment failed login attempts
     */
    private function incrementFailedAttempts($userId) {
        $sql = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
        
        // Check if should lock account
        $sql = "SELECT failed_login_attempts FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        
        $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
        if ($user['failed_login_attempts'] >= $maxAttempts) {
            $lockDuration = defined('ACCOUNT_LOCKOUT_DURATION') ? ACCOUNT_LOCKOUT_DURATION : 30;
            $lockUntil = date('Y-m-d H:i:s', strtotime("+$lockDuration minutes"));
            $sql = "UPDATE users SET account_locked_until = :lock_until WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['lock_until' => $lockUntil, 'id' => $userId]);
        }
    }
    
    /**
     * Reset failed login attempts
     */
    private function resetFailedAttempts($userId) {
        $sql = "UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
    }
    
    /**
     * Update last login time
     */
    private function updateLastLogin($userId) {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
    }
    
    /**
     * Log user activity
     */
    private function logActivity($userId, $action, $module, $description) {
        try {
            $sql = "INSERT INTO activity_logs (user_id, action, module, description, ip_address, user_agent) 
                    VALUES (:user_id, :action, :module, :description, :ip_address, :user_agent)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch(Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate password reset token
     */
    public function generatePasswordResetToken($email) {
        try {
            $sql = "SELECT id FROM users WHERE email = :email AND status = 'active'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Email not found'];
            }
            
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $sql = "UPDATE users SET password_reset_token = :token, password_reset_expires = :expiry WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'token' => $token,
                'expiry' => $expiry,
                'id' => $user['id']
            ]);
            
            return ['success' => true, 'token' => $token];
        } catch(Exception $e) {
            error_log("Password reset token error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred'];
        }
    }
    
    /**
     * Reset password using token
     */
    public function resetPassword($token, $newPassword) {
        try {
            $sql = "SELECT id FROM users WHERE password_reset_token = :token 
                    AND password_reset_expires > NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['token' => $token]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid or expired token'];
            }
            
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $sql = "UPDATE users SET password_hash = :password_hash, 
                    password_reset_token = NULL, password_reset_expires = NULL 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'password_hash' => $passwordHash,
                'id' => $user['id']
            ]);
            
            // Add to password history
            $sql = "INSERT INTO password_history (user_id, password_hash) VALUES (:user_id, :password_hash)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'user_id' => $user['id'],
                'password_hash' => $passwordHash
            ]);
            
            return ['success' => true, 'message' => 'Password reset successfully'];
        } catch(Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred'];
        }
    }
}
