<?php
/**
 * Authentication Handler Class
 * Handles user authentication, login, logout, and session management
 * Supports module-isolated sessions (admin, student, lecturer, finance can be logged in simultaneously)
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

class Auth {
        /**
         * Check if a username, email, or lecturer_id exists
         * For lecturer module: also matches against lecturers.lecturer_id
         * Returns user info if found, otherwise false
         */
        public function usernameExists($username) {
            try {
                // For lecturer logins, resolve lecturer_id → user record
                if ($this->module === 'lecturer') {
                    $sql = "SELECT u.* FROM users u
                            INNER JOIN lecturers l ON l.user_id = u.id
                            WHERE l.lecturer_id = :lid
                            LIMIT 1";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(['lid' => $username]);
                    $user = $stmt->fetch();
                    if ($user) {
                        return $user;
                    }
                    // Fall back to regular username/email check
                }
                $sql = "SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['username' => $username, 'email' => $username]);
                $user = $stmt->fetch();
                return $user ?: false;
            } catch (Exception $e) {
                error_log("usernameExists error: " . $e->getMessage());
                return false;
            }
        }
    private $db;
    private $session;
    private $module; // The current module context (admin, student, lecturer, finance)
    
    public function __construct($role = null) {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->session = new Session($role);
        $this->module = $role ?: $this->detectModuleFromActiveSession();
    }

    /**
     * Detect active module from the current session cookie name.
     */
    private function detectModuleFromActiveSession() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $map = [
            'SMNS_ADMIN_SESSION' => 'admin',
            'SMNS_STUDENT_SESSION' => 'student',
            'SMNS_LECTURER_SESSION' => 'lecturer',
            'SMNS_FINANCE_SESSION' => 'finance',
        ];

        $activeName = session_name();
        return $map[$activeName] ?? null;
    }
    
    /**
     * Get module-prefixed session key
     * This allows multiple roles to be logged in simultaneously without conflicts
     */
    private function getModuleKey($key) {
        if ($this->module) {
            return $this->module . '_' . $key;
        }
        return $key;
    }
    
    /**
     * Set module-specific session value
     */
    public function setModuleSession($key, $value) {
        $_SESSION[$this->getModuleKey($key)] = $value;
    }
    
    /**
     * Get module-specific session value
     */
    public function getModuleSession($key, $default = null) {
        return $_SESSION[$this->getModuleKey($key)] ?? $default;
    }
    
    /**
     * Check if module-specific session key exists
     */
    public function hasModuleSession($key) {
        return isset($_SESSION[$this->getModuleKey($key)]);
    }
    
    /**
     * Authenticate user with username and password
     */
    public function login($username, $password) {
        try {
            // For lecturer module, resolve lecturer_id → user record first
            if ($this->module === 'lecturer') {
                $lsql = "SELECT u.* FROM users u
                         INNER JOIN lecturers l ON l.user_id = u.id
                         WHERE l.lecturer_id = :lid
                         LIMIT 1";
                $lstmt = $this->db->prepare($lsql);
                $lstmt->execute(['lid' => $username]);
                $user = $lstmt->fetch();
                if ($user) {
                    // Use the actual username for further checks
                    $username = $user['username'];
                }
            }

            // Check if account is locked
            $sql = "SELECT * FROM users WHERE username = :username OR email = :email";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['username' => $username, 'email' => $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid credentials'];
            }

            // Ensure 'require_password_change' column exists
            $col = $this->db->query("SHOW COLUMNS FROM users LIKE 'require_password_change'")->fetch();
            if (!$col) {
                $this->db->exec("ALTER TABLE users ADD COLUMN require_password_change TINYINT(1) DEFAULT 0 AFTER account_locked_until");
                // refresh user record
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['username' => $username, 'email' => $username]);
                $user = $stmt->fetch();
            }
            
            // Account lockout check (disabled)
            // Previously, if account_locked_until was in the future, login was blocked.
            // This behavior has been disabled so users are not locked out.
            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                // Clear any stale lock/failed attempts and allow normal credential check
                $this->resetFailedAttempts($user['id']);
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
            
            // Regenerate session ID for security (prevent session fixation)
            session_regenerate_id(true);
            
            // Set module-specific session data (does NOT clear other modules' sessions)
            // This allows admin, student, lecturer, finance to be logged in simultaneously
            $modulePrefix = $this->module ?? $user['role'];
            
            // Clear only THIS module's session data
            $this->clearModuleSession($modulePrefix);
            
            // Set module-specific session data
            $_SESSION[$modulePrefix . '_user_id'] = $user['id'];
            $_SESSION[$modulePrefix . '_username'] = $user['username'];
            $_SESSION[$modulePrefix . '_email'] = $user['email'];
            $_SESSION[$modulePrefix . '_role'] = $user['role'];
            $_SESSION[$modulePrefix . '_profile'] = $profile;
            $_SESSION[$modulePrefix . '_logged_in'] = true;
            $_SESSION[$modulePrefix . '_login_time'] = time();
            $_SESSION[$modulePrefix . '_session_token'] = bin2hex(random_bytes(32));

            // Log successful login so admin can see it in Recent Activity / Login Sessions
            $moduleName = $this->module ?: $user['role'];
            $deviceAlertMeta = $this->buildDeviceAlertMeta((int)$user['id'], $moduleName);
            $description = 'User logged in as ' . $user['role'] . ' (' . $user['username'] . ')';
            $this->logActivity($user['id'], 'login', $moduleName, $description);
            $this->sendNewDeviceLoginAlert($user, $profile, $moduleName, $deviceAlertMeta);

            // Return success with user's role
            return ['success' => true, 'role' => $user['role']];
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
     * Clear module-specific session data only
     * This preserves other modules' sessions
     */
    public function clearModuleSession($module = null) {
        $prefix = $module ?? $this->module;
        if (!$prefix) return;
        
        $keysToRemove = [];
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, $prefix . '_') === 0) {
                $keysToRemove[] = $key;
            }
        }
        foreach ($keysToRemove as $key) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Logout user from current module only
     * Does NOT affect other module sessions
     */
    public function logout() {
        // Get user ID from module-specific session
        $modulePrefix = $this->module ?: $this->detectModuleFromActiveSession();
        $userId = null;
        
        if ($modulePrefix && isset($_SESSION[$modulePrefix . '_user_id'])) {
            $userId = $_SESSION[$modulePrefix . '_user_id'];
        } else {
            $userId = $this->session->get('user_id');
        }
        
        if ($userId) {
            $this->logActivity($userId, 'logout', 'authentication', 'User logged out from ' . ($modulePrefix ?? 'system'));
        }
        
        // Clear only THIS module's session data
        if ($modulePrefix) {
            $this->clearModuleSession($modulePrefix);
        } else {
            $this->session->destroy();
        }
    }
    
    /**
     * Check if user is logged in to the current module
     */
    public function isLoggedIn() {
        if (!$this->module) {
            return false;
        }
        $loggedInKey = $this->module . '_logged_in';
        $roleKey = $this->module . '_role';

        return (isset($_SESSION[$loggedInKey]) && $_SESSION[$loggedInKey] === true &&
                isset($_SESSION[$roleKey]) && $_SESSION[$roleKey] === $this->module);
    }

    /**
     * Get the role of the current user for this module
     */
    public function getRole() {
        if (!$this->module) {
            return null;
        }
        $roleKey = $this->module . '_role';
        return $_SESSION[$roleKey] ?? null;
    }

    /**
     * Check if user has specific role in current module
     */
    public function hasRole($role) {
        return $this->getRole() === $role;
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
     * Get current user from module-specific session
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $prefix = $this->module . '_';
        
        return [
            'id' => $_SESSION[$prefix . 'user_id'] ?? null,
            'username' => $_SESSION[$prefix . 'username'] ?? null,
            'email' => $_SESSION[$prefix . 'email'] ?? null,
            'role' => $_SESSION[$prefix . 'role'] ?? null,
            'profile' => $_SESSION[$prefix . 'profile'] ?? null
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
     * Build device metadata used for new-device login alerts.
     * This is evaluated before writing the current login activity row.
     */
    private function buildDeviceAlertMeta($userId, $moduleName) {
        $userAgentRaw = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($userAgentRaw === '') {
            $userAgentRaw = 'unknown';
        }

        $ipAddress = $this->extractClientIp();
        $loginTime = date('M j, Y, g:i A');
        $defaultMeta = [
            'is_new_device' => false,
            'used_count' => 1,
            'login_time' => $loginTime,
            'ip_address' => $ipAddress,
            'device_label' => $this->describeUserAgent($userAgentRaw),
            'module_label' => $this->resolveModuleLabel($moduleName)
        ];

        try {
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM activity_logs
                WHERE user_id = :user_id
                  AND action = 'login'
                  AND user_agent = :user_agent
            ");
            $countStmt->execute([
                'user_id' => (int)$userId,
                'user_agent' => $userAgentRaw
            ]);
            $priorCount = (int)$countStmt->fetchColumn();

            $defaultMeta['used_count'] = $priorCount + 1;
            $defaultMeta['is_new_device'] = ($priorCount === 0);
        } catch (Exception $e) {
            error_log('Device alert metadata error: ' . $e->getMessage());
        }

        return $defaultMeta;
    }

    /**
     * Send a per-user security alert for first-time device sign-ins.
     */
    private function sendNewDeviceLoginAlert($user, $profile, $moduleName, $deviceAlertMeta) {
        if (empty($deviceAlertMeta['is_new_device']) || !class_exists('Helper')) {
            return;
        }

        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $recipientName = $this->resolveRecipientName($user, $profile);
        $accountRef = $this->resolveAccountReference($user, $profile, $moduleName);

        $payload = [
            'recipient_name' => $recipientName,
            'greeting_name' => $recipientName,
            'module_label' => (string)($deviceAlertMeta['module_label'] ?? $this->resolveModuleLabel($moduleName)),
            'device_label' => (string)($deviceAlertMeta['device_label'] ?? 'Unknown device'),
            'ip_address' => (string)($deviceAlertMeta['ip_address'] ?? $this->extractClientIp()),
            'used_count' => (int)($deviceAlertMeta['used_count'] ?? 1),
            'login_time' => (string)($deviceAlertMeta['login_time'] ?? date('M j, Y, g:i A')),
            'account_label' => (string)$accountRef['label'],
            'account_value' => (string)$accountRef['value'],
            'service_desk_label' => 'ICT Service Desk',
            'reset_url' => $this->resolveSecurityResetUrl($moduleName),
            'support_email' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : ''
        ];

        try {
            Helper::sendTemplatedEmail(
                'security_new_device_login',
                $email,
                $payload,
                [
                    'context_label' => 'Login Security Alert',
                    'source_page' => $_SERVER['REQUEST_URI'] ?? ''
                ]
            );
        } catch (Exception $e) {
            error_log('New-device login alert send error: ' . $e->getMessage());
        }
    }

    private function resolveRecipientName($user, $profile) {
        $firstName = trim((string)($profile['first_name'] ?? ''));
        $lastName = trim((string)($profile['last_name'] ?? ''));
        if ($firstName !== '' || $lastName !== '') {
            return trim($firstName . ' ' . $lastName);
        }

        $fullName = trim((string)($profile['fullname'] ?? ($user['fullname'] ?? '')));
        if ($fullName !== '') {
            return $fullName;
        }

        $username = trim((string)($user['username'] ?? ''));
        return $username !== '' ? $username : 'User';
    }

    private function resolveAccountReference($user, $profile, $moduleName) {
        $module = strtolower((string)$moduleName);
        if ($module === 'student' && !empty($profile['student_id'])) {
            return ['label' => 'Student ID', 'value' => (string)$profile['student_id']];
        }
        if ($module === 'lecturer' && !empty($profile['lecturer_id'])) {
            return ['label' => 'Lecturer ID', 'value' => (string)$profile['lecturer_id']];
        }
        if ($module === 'finance') {
            if (!empty($profile['staff_id'])) {
                return ['label' => 'Staff ID', 'value' => (string)$profile['staff_id']];
            }
            if (!empty($profile['finance_id'])) {
                return ['label' => 'Staff ID', 'value' => (string)$profile['finance_id']];
            }
        }
        if ($module === 'admin' && !empty($profile['admin_id'])) {
            return ['label' => 'Admin ID', 'value' => (string)$profile['admin_id']];
        }

        return [
            'label' => 'Username',
            'value' => (string)($user['username'] ?? '-')
        ];
    }

    private function resolveSecurityResetUrl($moduleName) {
        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
        $module = strtolower((string)$moduleName);
        $allowed = ['admin', 'student', 'lecturer', 'finance'];
        if (!in_array($module, $allowed, true)) {
            $module = 'auth';
        }

        if ($module === 'student') {
            $studentResetPath = defined('BASE_PATH') ? BASE_PATH . '/views/student/reset-password.php' : '';
            if ($studentResetPath !== '' && file_exists($studentResetPath)) {
                return $baseUrl . '/views/student/reset-password.php';
            }
        }

        if ($module !== 'auth') {
            $moduleLoginPath = defined('BASE_PATH') ? BASE_PATH . '/views/' . $module . '/login.php' : '';
            if ($moduleLoginPath !== '' && file_exists($moduleLoginPath)) {
                return $baseUrl . '/views/' . $module . '/login.php';
            }
        }

        return $baseUrl . '/views/auth/login.php';
    }

    private function resolveModuleLabel($moduleName) {
        $module = strtolower((string)$moduleName);
        switch ($module) {
            case 'admin':
                return 'Admin Portal';
            case 'student':
                return 'Student Portal';
            case 'lecturer':
                return 'Lecturer Portal';
            case 'finance':
                return 'Finance Portal';
            default:
                return 'Portal';
        }
    }

    private function extractClientIp() {
        $rawIp = (string)(class_exists('Security') ? Security::getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        if (strpos($rawIp, ',') !== false) {
            $parts = explode(',', $rawIp);
            $rawIp = (string)($parts[0] ?? '');
        }
        $rawIp = trim($rawIp);
        return $rawIp !== '' ? $rawIp : 'UNKNOWN';
    }

    private function describeUserAgent($userAgentRaw) {
        $ua = trim((string)$userAgentRaw);
        if ($ua === '' || strtolower($ua) === 'unknown') {
            return 'unknown device';
        }

        $deviceType = 'desktop';
        if (preg_match('/ipad|tablet/i', $ua)) {
            $deviceType = 'tablet';
        } elseif (preg_match('/mobile|android|iphone/i', $ua)) {
            $deviceType = 'mobile';
        }

        $os = 'Unknown OS';
        $osMap = [
            'Windows NT 10.0' => 'Windows 10',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.2' => 'Windows 8',
            'Windows NT 6.1' => 'Windows 7',
            'Windows NT 6.0' => 'Windows Vista',
            'Windows NT 5.1' => 'Windows XP',
            'Android' => 'Android',
            'iPhone OS' => 'iOS',
            'iPad; CPU OS' => 'iPadOS',
            'Mac OS X' => 'macOS',
            'Linux' => 'Linux'
        ];
        foreach ($osMap as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $os = $label;
                break;
            }
        }

        $browser = 'Unknown';
        $version = '';
        $browserPatterns = [
            'Edge' => '/Edg\/([0-9\.]+)/i',
            'Opera' => '/OPR\/([0-9\.]+)/i',
            'Chrome' => '/Chrome\/([0-9\.]+)/i',
            'Firefox' => '/Firefox\/([0-9\.]+)/i',
            'Safari' => '/Version\/([0-9\.]+).*Safari/i',
            'Internet Explorer' => '/(?:MSIE\s|rv:)([0-9\.]+)/i'
        ];
        foreach ($browserPatterns as $label => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                $browser = $label;
                $version = (string)($m[1] ?? '');
                break;
            }
        }

        $browserPart = strtolower($deviceType) . ' ' . $browser . ' browser' . ($version !== '' ? ' ' . $version : '');
        return trim($browserPart) . ' (' . $os . ')';
    }
     
    /**
     * Increment failed login attempts
     */
    private function incrementFailedAttempts($userId) {
        $sql = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
        
        // Previously: check if failed attempts reached threshold and lock account.
        // Lockout has been disabled to avoid blocking user access, so we no longer
        // set account_locked_until here. Failed attempts are still tracked but
        // will not prevent login.
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
            $desc = trim((string)$description);
            $where = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
            if ($where !== '' && stripos($desc, '[where:') === false) {
                $desc = trim($desc . ' [where:' . $this->sanitizeLogMeta($where) . ']');
            }

            $sql = "INSERT INTO activity_logs (user_id, action, module, description, ip_address, user_agent) 
                    VALUES (:user_id, :action, :module, :description, :ip_address, :user_agent)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $desc,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch(Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }

    private function sanitizeLogMeta($value) {
        $clean = str_replace(["\r", "\n", "\t"], ' ', (string)$value);
        $clean = str_replace(['[', ']'], '', $clean);
        $clean = preg_replace('/\s{2,}/', ' ', $clean);
        return trim((string)$clean);
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
