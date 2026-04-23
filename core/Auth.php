<?php
/**
 * Authentication Handler Class
 * Handles user authentication, login, logout, and session management
 * Supports module-isolated sessions (admin, student, lecturer, finance can be logged in simultaneously)
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/MfaService.php';
require_once __DIR__ . '/PrivacyConsentService.php';

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
                        $moduleName = $this->module ?: (string)($user['role'] ?? '');
                        if (!$this->userHasModuleAccess($user, $moduleName)) {
                            return false;
                        }
                        if ($moduleName !== '') {
                            $user['role'] = $moduleName;
                        }
                        return $user;
                    }
                    // Fall back to regular username/email check
                }
                if ($this->module === 'student') {
                    $ssql = "SELECT u.* FROM users u
                            INNER JOIN students s ON s.user_id = u.id
                            WHERE s.student_id = :sid
                            LIMIT 1";
                    $sstmt = $this->db->prepare($ssql);
                    $sstmt->execute(['sid' => $username]);
                    $studentUser = $sstmt->fetch();
                    if ($studentUser) {
                        $moduleName = $this->module ?: (string)($studentUser['role'] ?? '');
                        if (!$this->userHasModuleAccess($studentUser, $moduleName)) {
                            return false;
                        }
                        if ($moduleName !== '') {
                            $studentUser['role'] = $moduleName;
                        }
                        return $studentUser;
                    }
                }
                $sql = "SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['username' => $username, 'email' => $username]);
                $user = $stmt->fetch();
                if (!$user) {
                    return false;
                }
                $moduleName = $this->module ?: (string)($user['role'] ?? '');
                if (!$this->userHasModuleAccess($user, $moduleName)) {
                    return false;
                }
                if ($moduleName !== '') {
                    $user['role'] = $moduleName;
                }
                return $user;
            } catch (Exception $e) {
                error_log("usernameExists error: " . $e->getMessage());
                return false;
            }
        }
    private $db;
    private $session;
    private $module; // The current module context (admin, student, lecturer, finance)

    private function isLocalRequest() {
        if (php_sapi_name() === 'cli') {
            return true;
        }
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
        $remoteAddr = strtolower((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $serverAddr = strtolower((string)($_SERVER['SERVER_ADDR'] ?? ''));

        $hostOnly = $host;
        if (strpos($hostOnly, ':') !== false) {
            $hostOnly = substr($hostOnly, 0, (int)strpos($hostOnly, ':'));
        }

        $locals = ['localhost', '127.0.0.1', '::1'];
        return in_array($hostOnly, $locals, true)
            || in_array($serverName, $locals, true)
            || in_array($remoteAddr, $locals, true)
            || in_array($serverAddr, $locals, true);
    }

    private function isUserStatusActive($status) {
        $status = strtolower(trim((string)$status));
        if ($status === '' || $status === 'active') {
            return true;
        }
        return !in_array($status, ['inactive', 'disabled', 'suspended', 'locked'], true);
    }
    
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

        if (session_name() === 'SMNS_SSO_SESSION' && !empty($this->module)) {
            return $this->module;
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
            if ($this->module === 'student') {
                $ssql = "SELECT u.* FROM users u
                         INNER JOIN students s ON s.user_id = u.id
                         WHERE s.student_id = :sid
                         LIMIT 1";
                $sstmt = $this->db->prepare($ssql);
                $sstmt->execute(['sid' => $username]);
                $studentUser = $sstmt->fetch();
                if ($studentUser) {
                    $username = $studentUser['username'];
                }
            }

            // Check user by username/email
            $sql = "SELECT * FROM users WHERE username = :username OR email = :email";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['username' => $username, 'email' => $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid credentials'];
            }

            // Ensure required auth support structures exist.
            $this->ensureAuthSupportStructures();

            // Account lockout check
            if (!empty($user['account_locked_until']) && strtotime((string)$user['account_locked_until']) > time()) {
                if ($this->isLocalRequest()) {
                    // Local/dev safety: auto-clear lockouts to avoid dead-end during setup.
                    $this->resetFailedAttempts((int)$user['id']);
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(['username' => $username, 'email' => $username]);
                    $user = $stmt->fetch();
                } else {
                    $until = date('Y-m-d H:i:s', strtotime((string)$user['account_locked_until']));
                    return ['success' => false, 'message' => 'Account locked due to failed login attempts. Try again after ' . $until . '.'];
                }
            }
            if (!empty($user['account_locked_until']) && strtotime((string)$user['account_locked_until']) <= time()) {
                $this->resetFailedAttempts((int)$user['id']);
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['username' => $username, 'email' => $username]);
                $user = $stmt->fetch();
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                // Increment failed login attempts
                $this->incrementFailedAttempts($user['id']);
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Check if account is active
            if (!$this->isUserStatusActive($user['status'] ?? 'active')) {
                return ['success' => false, 'message' => 'Account is not active'];
            }
            
            $moduleName = $this->module ?: $user['role'];
            if (!$this->userHasModuleAccess($user, $moduleName)) {
                return ['success' => false, 'message' => 'Access denied for this module.'];
            }

            // Optional MFA gate before session is finalized.
            if (MfaService::isMfaRequiredForRole((string)$user['role'])) {
                $mfa = new MfaService($this->db);
                $challenge = $mfa->issueChallenge((int)$user['id'], $moduleName, (string)($user['email'] ?? ''));
                if (!$challenge['success']) {
                    return ['success' => false, 'message' => $challenge['message'] ?? 'Unable to issue MFA challenge.'];
                }
                $this->setPendingLoginContext($moduleName, (int)$user['id'], 'mfa', (string)($challenge['message'] ?? ''));
                return [
                    'success' => false,
                    'role' => $moduleName,
                    'mfa_required' => true,
                    'message' => $challenge['message'] ?? 'Verification code sent.'
                ];
            }

            // Privacy consent gate before session is finalized.
            $privacyDefault = (defined('PRIVACY_CONSENT_REQUIRED') && PRIVACY_CONSENT_REQUIRED) ? '1' : '0';
            $privacyRaw = (string)(function_exists('getSetting') ? getSetting('privacy_consent_required', $privacyDefault) : $privacyDefault);
            $privacyRequired = in_array(strtolower(trim($privacyRaw)), ['1', 'true', 'yes', 'on'], true);
            if ($privacyRequired) {
                $consent = new PrivacyConsentService($this->db);
                if (!$consent->hasAcceptedCurrent((int)$user['id'])) {
                    $this->setPendingLoginContext($moduleName, (int)$user['id'], 'consent');
                    return [
                        'success' => false,
                        'role' => $moduleName,
                        'consent_required' => true,
                        'message' => 'Privacy consent is required before proceeding.'
                    ];
                }
            }

            return $this->finalizeAuthenticatedLogin($user);
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
            if (!$this->hasAnyActiveModuleSession()) {
                unset($_SESSION['sso_logged_in']);
                unset($_SESSION['sso_user_id']);
                unset($_SESSION['sso_username']);
                unset($_SESSION['sso_email']);
                unset($_SESSION['sso_primary_role']);
                unset($_SESSION['sso_access_modules']);
                unset($_SESSION['sso_last_login_at']);
            }
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

        if (isset($_SESSION[$loggedInKey]) && $_SESSION[$loggedInKey] === true &&
            isset($_SESSION[$roleKey]) && $_SESSION[$roleKey] === $this->module) {
            return true;
        }

        return $this->hydrateModuleSessionFromSso($this->module);
    }

    /**
     * Get the role of the current user for this module
     */
    public function getRole() {
        if (!$this->module) {
            return $_SESSION['sso_primary_role'] ?? null;
        }
        if (!$this->isLoggedIn()) {
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

        $userId = $_SESSION[$prefix . 'user_id'] ?? null;
        $role = $_SESSION[$prefix . 'role'] ?? null;
        $profile = $_SESSION[$prefix . 'profile'] ?? null;

        // Refresh profile from DB to avoid stale session data (e.g., updated photo/name)
        if ($userId && $role) {
            try {
                $freshProfile = $this->getUserProfile($userId, $role);
                if ($freshProfile) {
                    $profile = $freshProfile;
                    $_SESSION[$prefix . 'profile'] = $freshProfile;
                }
            } catch (Exception $e) {
                // Fall back to existing session profile if DB refresh fails
            }
        }

        return [
            'id' => $userId,
            'username' => $_SESSION[$prefix . 'username'] ?? null,
            'email' => $_SESSION[$prefix . 'email'] ?? null,
            'role' => $role,
            'primary_role' => $_SESSION[$prefix . 'primary_role'] ?? ($_SESSION['sso_primary_role'] ?? null),
            'profile' => $profile
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

    /**
     * Ensure required auth-related schema elements exist.
     */
    private function ensureAuthSupportStructures() {
        try {
            $col = $this->db->query("SHOW COLUMNS FROM users LIKE 'require_password_change'")->fetch();
            if (!$col) {
                $this->db->exec("ALTER TABLE users ADD COLUMN require_password_change TINYINT(1) DEFAULT 0 AFTER account_locked_until");
            }
        } catch (Exception $e) {
            // Non-fatal bootstrap safeguard
        }

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS password_history (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    user_id INT(11) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user_created (user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            // Non-fatal bootstrap safeguard
        }

        try {
            $mfa = new MfaService($this->db);
            $mfa->ensureTable();
        } catch (Exception $e) {
            // Non-fatal
        }

        try {
            $consent = new PrivacyConsentService($this->db);
            $consent->ensureTable();
        } catch (Exception $e) {
            // Non-fatal
        }

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS user_module_access (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    user_id INT(11) NOT NULL,
                    module ENUM('admin','student','lecturer','finance') NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_user_module (user_id, module),
                    KEY idx_module_active (module, is_active),
                    KEY idx_user_active (user_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            // Non-fatal
        }
    }

    private function normalizeModuleName($module) {
        $module = strtolower(trim((string)$module));
        if (!in_array($module, ['admin', 'student', 'lecturer', 'finance'], true)) {
            return '';
        }
        return $module;
    }

    private function resolveAccessibleModules(array $user) {
        $primary = $this->normalizeModuleName((string)($user['role'] ?? ''));
        $modules = [];
        if ($primary !== '') {
            $modules[$primary] = true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return array_keys($modules);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT module
                FROM user_module_access
                WHERE user_id = :user_id
                  AND is_active = 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $module = $this->normalizeModuleName((string)($row['module'] ?? ''));
                if ($module !== '') {
                    $modules[$module] = true;
                }
            }
        } catch (Exception $e) {
        }

        return array_keys($modules);
    }

    private function userHasModuleAccess(array $user, $module) {
        $module = $this->normalizeModuleName($module);
        if ($module === '') {
            return false;
        }
        $modules = $this->resolveAccessibleModules($user);
        if (!in_array($module, $modules, true)) {
            return false;
        }
        if ($module === $this->normalizeModuleName((string)($user['role'] ?? ''))) {
            return true;
        }
        return $this->moduleProfileExists((int)($user['id'] ?? 0), $module);
    }

    private function moduleProfileExists($userId, $module) {
        $userId = (int)$userId;
        $module = $this->normalizeModuleName($module);
        if ($userId <= 0 || $module === '') {
            return false;
        }
        $tableMap = [
            'admin' => 'admins',
            'student' => 'students',
            'lecturer' => 'lecturers',
            'finance' => 'finance_staff'
        ];
        $table = $tableMap[$module] ?? '';
        if ($table === '') {
            return false;
        }
        try {
            $stmt = $this->db->prepare("SELECT id FROM {$table} WHERE user_id = :user_id LIMIT 1");
            $stmt->execute(['user_id' => $userId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    private function hydrateModuleSessionFromSso($moduleName) {
        $moduleName = $this->normalizeModuleName($moduleName);
        if ($moduleName === '') {
            return false;
        }

        if (empty($_SESSION['sso_logged_in']) || empty($_SESSION['sso_user_id'])) {
            return false;
        }

        $ssoUserId = (int)($_SESSION['sso_user_id'] ?? 0);
        if ($ssoUserId <= 0) {
            return false;
        }

        $user = $this->getUserById($ssoUserId);
        if (!$user || !$this->isUserStatusActive($user['status'] ?? 'active')) {
            return false;
        }
        if (!$this->userHasModuleAccess($user, $moduleName)) {
            return false;
        }

        $profile = $this->getUserProfile((int)$user['id'], $moduleName);
        $_SESSION[$moduleName . '_user_id'] = (int)$user['id'];
        $_SESSION[$moduleName . '_username'] = (string)($user['username'] ?? '');
        $_SESSION[$moduleName . '_email'] = (string)($user['email'] ?? '');
        $_SESSION[$moduleName . '_role'] = $moduleName;
        $_SESSION[$moduleName . '_primary_role'] = (string)($user['role'] ?? '');
        $_SESSION[$moduleName . '_profile'] = $profile;
        $_SESSION[$moduleName . '_logged_in'] = true;
        $_SESSION[$moduleName . '_login_time'] = time();
        $_SESSION[$moduleName . '_session_token'] = bin2hex(random_bytes(32));
        return true;
    }

    private function hasAnyActiveModuleSession() {
        foreach (['admin', 'student', 'lecturer', 'finance'] as $module) {
            if (!empty($_SESSION[$module . '_logged_in']) && !empty($_SESSION[$module . '_user_id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Finalize authenticated session after all pre-login gates pass.
     */
    private function finalizeAuthenticatedLogin(array $user) {
        $moduleName = $this->module ?: $user['role'];
        $profile = $this->getUserProfile($user['id'], $moduleName);

        // Successful auth finalization: clear lock state and update login audit.
        $this->resetFailedAttempts($user['id']);
        $this->updateLastLogin($user['id']);

        // Regenerate session ID for security (prevent session fixation)
        session_regenerate_id(true);

        // Clear only THIS module's session data
        $this->clearModuleSession($moduleName);

        // Set module-specific session data
        $_SESSION[$moduleName . '_user_id'] = $user['id'];
        $_SESSION[$moduleName . '_username'] = $user['username'];
        $_SESSION[$moduleName . '_email'] = $user['email'];
        $_SESSION[$moduleName . '_role'] = $moduleName;
        $_SESSION[$moduleName . '_primary_role'] = (string)$user['role'];
        $_SESSION[$moduleName . '_profile'] = $profile;
        $_SESSION[$moduleName . '_logged_in'] = true;
        $_SESSION[$moduleName . '_login_time'] = time();
        $_SESSION[$moduleName . '_session_token'] = bin2hex(random_bytes(32));
        $this->clearPendingLoginContext($moduleName);
        $accessibleModules = $this->resolveAccessibleModules($user);
        $_SESSION['sso_logged_in'] = true;
        $_SESSION['sso_user_id'] = (int)$user['id'];
        $_SESSION['sso_username'] = (string)$user['username'];
        $_SESSION['sso_email'] = (string)$user['email'];
        $_SESSION['sso_primary_role'] = (string)$user['role'];
        $_SESSION['sso_access_modules'] = $accessibleModules;
        $_SESSION['sso_last_login_at'] = time();

        // Log successful login so admin can see it in Recent Activity / Login Sessions
        $deviceAlertMeta = $this->buildDeviceAlertMeta((int)$user['id'], $moduleName);
        $description = 'User logged in as ' . $user['role'] . ' (' . $user['username'] . ')';
        $this->logActivity($user['id'], 'login', $moduleName, $description);
        $this->sendNewDeviceLoginAlert($user, $profile, $moduleName, $deviceAlertMeta);

        return [
            'success' => true,
            'role' => $moduleName,
            'require_password_change' => !empty($user['require_password_change'])
        ];
    }

    private function getPendingLoginCookieName($module) {
        return 'SMNS_PENDING_' . strtoupper(trim((string)$module));
    }

    private function getPendingLoginCookiePath() {
        $path = '/';
        if (defined('BASE_URL')) {
            $parsedPath = (string)parse_url((string)BASE_URL, PHP_URL_PATH);
            if ($parsedPath !== '') {
                $path = $parsedPath;
            }
        }
        if ($path === '') {
            $path = '/';
        }
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }
        return $path;
    }

    private function getPendingLoginCookieSecret() {
        if (defined('BACKUP_ENCRYPTION_KEY') && trim((string)BACKUP_ENCRYPTION_KEY) !== '') {
            return (string)BACKUP_ENCRYPTION_KEY;
        }
        return hash('sha256', (defined('DB_NAME') ? DB_NAME : 'smns') . '|' . (defined('DB_USER') ? DB_USER : 'root') . '|' . __FILE__);
    }

    private function signPendingLoginCookie($module, $userId, $reason, $expiresAt) {
        $data = strtolower(trim((string)$module)) . '|' . (int)$userId . '|' . trim((string)$reason) . '|' . (int)$expiresAt;
        return hash_hmac('sha256', $data, $this->getPendingLoginCookieSecret());
    }

    private function setPendingLoginCookie($module, $userId, $reason, $expiresAt) {
        if (headers_sent()) {
            return;
        }
        $moduleName = strtolower(trim((string)$module));
        if ($moduleName === '' || (int)$userId <= 0 || (int)$expiresAt <= 0) {
            return;
        }

        $payload = [
            'u' => (int)$userId,
            'r' => trim((string)$reason),
            'e' => (int)$expiresAt
        ];
        $payload['s'] = $this->signPendingLoginCookie($moduleName, (int)$payload['u'], (string)$payload['r'], (int)$payload['e']);
        $encoded = base64_encode((string)json_encode($payload));
        $cookieName = $this->getPendingLoginCookieName($moduleName);
        $cookiePath = $this->getPendingLoginCookiePath();
        $cookieSecure = (bool)(defined('SESSION_COOKIE_SECURE') ? SESSION_COOKIE_SECURE : smnsIsHttpsRequest());
        $cookieSameSite = (string)(defined('SESSION_COOKIE_SAMESITE') ? SESSION_COOKIE_SAMESITE : 'Strict');

        if (PHP_VERSION_ID >= 70300) {
            setcookie($cookieName, $encoded, [
                'expires' => (int)$expiresAt,
                'path' => $cookiePath,
                'secure' => $cookieSecure,
                'httponly' => true,
                'samesite' => $cookieSameSite
            ]);
        } else {
            setcookie(
                $cookieName,
                $encoded,
                (int)$expiresAt,
                $cookiePath . '; samesite=' . $cookieSameSite,
                '',
                $cookieSecure,
                true
            );
        }
    }

    private function clearPendingLoginCookie($module) {
        if (headers_sent()) {
            return;
        }
        $moduleName = strtolower(trim((string)$module));
        if ($moduleName === '') {
            return;
        }
        $cookieName = $this->getPendingLoginCookieName($moduleName);
        $cookiePath = $this->getPendingLoginCookiePath();
        $cookieSecure = (bool)(defined('SESSION_COOKIE_SECURE') ? SESSION_COOKIE_SECURE : smnsIsHttpsRequest());
        $cookieSameSite = (string)(defined('SESSION_COOKIE_SAMESITE') ? SESSION_COOKIE_SAMESITE : 'Strict');

        if (PHP_VERSION_ID >= 70300) {
            setcookie($cookieName, '', [
                'expires' => time() - 3600,
                'path' => $cookiePath,
                'secure' => $cookieSecure,
                'httponly' => true,
                'samesite' => $cookieSameSite
            ]);
        } else {
            setcookie(
                $cookieName,
                '',
                time() - 3600,
                $cookiePath . '; samesite=' . $cookieSameSite,
                '',
                $cookieSecure,
                true
            );
        }
    }

    private function getPendingLoginContextFromCookie($module) {
        $moduleName = strtolower(trim((string)$module));
        if ($moduleName === '') {
            return null;
        }
        $cookieName = $this->getPendingLoginCookieName($moduleName);
        $raw = (string)($_COOKIE[$cookieName] ?? '');
        if ($raw === '') {
            return null;
        }

        $decodedJson = base64_decode($raw, true);
        if ($decodedJson === false) {
            return null;
        }
        $data = json_decode($decodedJson, true);
        if (!is_array($data)) {
            return null;
        }

        $uid = (int)($data['u'] ?? 0);
        $reason = trim((string)($data['r'] ?? ''));
        $expiresAt = (int)($data['e'] ?? 0);
        $sig = (string)($data['s'] ?? '');
        if ($uid <= 0 || $reason === '' || $expiresAt <= 0 || $sig === '') {
            return null;
        }

        $expected = $this->signPendingLoginCookie($moduleName, $uid, $reason, $expiresAt);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return [
            'module' => $moduleName,
            'user_id' => $uid,
            'reason' => $reason,
            'expires_at' => $expiresAt
        ];
    }

    private function setPendingLoginContext($module, $userId, $reason, $notice = '') {
        $module = strtolower(trim((string)$module));
        if ($module === '') {
            return;
        }
        $ttlDefault = defined('MFA_CHALLENGE_TTL_SECONDS') ? (int)MFA_CHALLENGE_TTL_SECONDS : 300;
        $ttl = (int)(function_exists('getSetting') ? getSetting('mfa_challenge_ttl_seconds', $ttlDefault) : $ttlDefault);
        if ($ttl < 60) {
            $ttl = 300;
        }
        $expiresAt = time() + $ttl;
        $_SESSION[$module . '_pending_user_id'] = (int)$userId;
        $_SESSION[$module . '_pending_reason'] = trim((string)$reason);
        $_SESSION[$module . '_pending_expires_at'] = $expiresAt;
        $noticeValue = trim((string)$notice);
        if ($noticeValue !== '') {
            $_SESSION[$module . '_pending_notice'] = $noticeValue;
        } else {
            unset($_SESSION[$module . '_pending_notice']);
        }
        $this->setPendingLoginCookie($module, (int)$userId, trim((string)$reason), (int)$expiresAt);
    }

    private function getPendingLoginContext($module = null, $allowExpired = false) {
        $ctx = $this->getPendingLoginContextRaw($module);
        if (!$ctx) {
            return null;
        }

        $isExpired = time() > (int)$ctx['expires_at'];
        if ($isExpired && !$allowExpired) {
            return null;
        }

        // Cleanup very old pending contexts to avoid stale session buildup.
        if (time() > ((int)$ctx['expires_at'] + 86400)) {
            $this->clearPendingLoginContext((string)$ctx['module']);
            return null;
        }

        $ctx['expired'] = $isExpired;
        return $ctx;
    }

    private function getPendingLoginContextRaw($module = null) {
        $moduleName = $module ? strtolower(trim((string)$module)) : strtolower(trim((string)$this->module));
        if ($moduleName === '') {
            return null;
        }
        $uid = (int)($_SESSION[$moduleName . '_pending_user_id'] ?? 0);
        $reason = (string)($_SESSION[$moduleName . '_pending_reason'] ?? '');
        $expiresAt = (int)($_SESSION[$moduleName . '_pending_expires_at'] ?? 0);
        if ($uid <= 0 || $expiresAt <= 0 || trim($reason) === '') {
            $cookieCtx = $this->getPendingLoginContextFromCookie($moduleName);
            if ($cookieCtx) {
                $uid = (int)$cookieCtx['user_id'];
                $reason = (string)$cookieCtx['reason'];
                $expiresAt = (int)$cookieCtx['expires_at'];
                $_SESSION[$moduleName . '_pending_user_id'] = $uid;
                $_SESSION[$moduleName . '_pending_reason'] = $reason;
                $_SESSION[$moduleName . '_pending_expires_at'] = $expiresAt;
            }
        }
        if ($uid <= 0 || $expiresAt <= 0) {
            $this->clearPendingLoginContext($moduleName);
            return null;
        }
        return [
            'module' => $moduleName,
            'user_id' => $uid,
            'reason' => $reason,
            'expires_at' => $expiresAt
        ];
    }

    private function clearPendingLoginContext($module = null) {
        $moduleName = $module ? strtolower(trim((string)$module)) : strtolower(trim((string)$this->module));
        if ($moduleName === '') {
            return;
        }
        unset($_SESSION[$moduleName . '_pending_user_id']);
        unset($_SESSION[$moduleName . '_pending_reason']);
        unset($_SESSION[$moduleName . '_pending_expires_at']);
        unset($_SESSION[$moduleName . '_pending_notice']);
        $this->clearPendingLoginCookie($moduleName);
    }

    public function consumePendingLoginNotice($module = null) {
        $moduleName = $module ? strtolower(trim((string)$module)) : strtolower(trim((string)$this->module));
        if ($moduleName === '') {
            return '';
        }
        $key = $moduleName . '_pending_notice';
        $msg = trim((string)($_SESSION[$key] ?? ''));
        unset($_SESSION[$key]);
        return $msg;
    }

    private function getUserById($userId) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int)$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Verify MFA code for a pending login and complete sign-in.
     */
    public function verifyPendingMfa($code) {
        try {
            $ctx = $this->getPendingLoginContext(null, true);
            if (!$ctx || $ctx['reason'] !== 'mfa') {
                return ['success' => false, 'message' => 'No pending MFA verification found.'];
            }
            if (!empty($ctx['expired'])) {
                return ['success' => false, 'message' => 'Verification code expired. Click Resend Code to get a new code.'];
            }

            $user = $this->getUserById((int)$ctx['user_id']);
            if (!$user || !$this->isUserStatusActive($user['status'] ?? 'active')) {
                $this->clearPendingLoginContext($ctx['module']);
                return ['success' => false, 'message' => 'User account is not active.'];
            }

            $mfa = new MfaService($this->db);
            $verified = $mfa->verifyChallenge((int)$ctx['user_id'], (string)$ctx['module'], (string)$code);
            if (!$verified['success']) {
                return $verified;
            }

            $privacyDefault = (defined('PRIVACY_CONSENT_REQUIRED') && PRIVACY_CONSENT_REQUIRED) ? '1' : '0';
            $privacyRaw = (string)(function_exists('getSetting') ? getSetting('privacy_consent_required', $privacyDefault) : $privacyDefault);
            $privacyRequired = in_array(strtolower(trim($privacyRaw)), ['1', 'true', 'yes', 'on'], true);
            if ($privacyRequired) {
                $consent = new PrivacyConsentService($this->db);
                if (!$consent->hasAcceptedCurrent((int)$ctx['user_id'])) {
                    $this->setPendingLoginContext($ctx['module'], (int)$ctx['user_id'], 'consent');
                    return [
                        'success' => false,
                        'role' => (string)$ctx['module'],
                        'consent_required' => true,
                        'message' => 'Privacy consent is required before proceeding.'
                    ];
                }
            }

            return $this->finalizeAuthenticatedLogin($user);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'MFA verification failed.'];
        }
    }

    /**
     * Re-issue MFA challenge for the current pending login context.
     */
    public function resendPendingMfaChallenge() {
        try {
            $ctx = $this->getPendingLoginContext(null, true);
            if (!$ctx || $ctx['reason'] !== 'mfa') {
                return ['success' => false, 'message' => 'No pending MFA challenge found.'];
            }
            $user = $this->getUserById((int)$ctx['user_id']);
            if (!$user || !$this->isUserStatusActive($user['status'] ?? 'active')) {
                return ['success' => false, 'message' => 'User account is not active.'];
            }
            $mfa = new MfaService($this->db);
            $resent = $mfa->issueChallenge((int)$ctx['user_id'], (string)$ctx['module'], (string)($user['email'] ?? ''));
            if (!empty($resent['success'])) {
                $this->setPendingLoginContext((string)$ctx['module'], (int)$ctx['user_id'], 'mfa', (string)($resent['message'] ?? 'Verification code resent.'));
            }
            return $resent;
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Unable to resend verification code.'];
        }
    }

    /**
     * Record consent for a pending login and complete sign-in.
     */
    public function acceptPendingPrivacyConsent() {
        try {
            $ctx = $this->getPendingLoginContext();
            if (!$ctx || $ctx['reason'] !== 'consent') {
                return ['success' => false, 'message' => 'No pending privacy consent found.'];
            }

            $user = $this->getUserById((int)$ctx['user_id']);
            if (!$user || !$this->isUserStatusActive($user['status'] ?? 'active')) {
                $this->clearPendingLoginContext($ctx['module']);
                return ['success' => false, 'message' => 'User account is not active.'];
            }

            $consent = new PrivacyConsentService($this->db);
            $granted = $consent->grantCurrent(
                (int)$ctx['user_id'],
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            if (!$granted) {
                return ['success' => false, 'message' => 'Unable to record privacy consent.'];
            }

            return $this->finalizeAuthenticatedLogin($user);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Unable to complete privacy consent.'];
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
            return ['label' => 'Student ID', 'value' => resolveDisplayedStudentRegistrationNumber($this->db, (array)$profile)];
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
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }

        $sql = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);

        $maxAttemptsDefault = defined('MAX_LOGIN_ATTEMPTS') ? max(1, (int)MAX_LOGIN_ATTEMPTS) : 5;
        $lockMinutesDefault = defined('ACCOUNT_LOCKOUT_DURATION') ? max(1, (int)ACCOUNT_LOCKOUT_DURATION) : 30;
        $maxAttempts = (int)(function_exists('getSetting') ? getSetting('max_login_attempts', $maxAttemptsDefault) : $maxAttemptsDefault);
        $lockMinutes = (int)(function_exists('getSetting') ? getSetting('account_lockout_duration', $lockMinutesDefault) : $lockMinutesDefault);
        if ($maxAttempts < 1) {
            $maxAttempts = $maxAttemptsDefault;
        }
        if ($lockMinutes < 1) {
            $lockMinutes = $lockMinutesDefault;
        }
        $check = $this->db->prepare("SELECT failed_login_attempts FROM users WHERE id = :id LIMIT 1");
        $check->execute(['id' => $userId]);
        $attempts = (int)$check->fetchColumn();
        if ($attempts >= $maxAttempts && !$this->isLocalRequest()) {
            $lockedUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
            $lock = $this->db->prepare("UPDATE users SET account_locked_until = :locked_until WHERE id = :id");
            $lock->execute(['locked_until' => $lockedUntil, 'id' => $userId]);
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
     * Prevent password reuse across recent password history entries.
     */
    private function isPasswordReused($userId, $candidatePassword) {
        $limit = defined('PASSWORD_HISTORY_LIMIT') ? max(1, (int)PASSWORD_HISTORY_LIMIT) : 5;
        try {
            $stmt = $this->db->prepare("
                SELECT password_hash
                FROM password_history
                WHERE user_id = :user_id
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute(['user_id' => (int)$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['password_hash']) && password_verify((string)$candidatePassword, (string)$row['password_hash'])) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
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
     * Change the password for the currently authenticated module user.
     */
    public function changeCurrentUserPassword($currentPassword, $newPassword, $confirmPassword) {
        $currentPassword = (string)$currentPassword;
        $newPassword = (string)$newPassword;
        $confirmPassword = (string)$confirmPassword;

        if (!$this->isLoggedIn()) {
            return ['success' => false, 'message' => 'You must be logged in to change your password.'];
        }

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return ['success' => false, 'message' => 'All fields are required.'];
        }

        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }

        $policyErrors = [];
        if (class_exists('Security') && !Security::validatePasswordPolicy($newPassword, $policyErrors)) {
            return ['success' => false, 'message' => implode(' ', $policyErrors)];
        }

        $currentUser = $this->getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'Unable to determine the current user.'];
        }

        try {
            $this->ensureAuthSupportStructures();

            $stmt = $this->db->prepare('SELECT id, username, password_hash, require_password_change FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['password_hash'])) {
                return ['success' => false, 'message' => 'Unable to verify the current password for this account.'];
            }

            if (!password_verify($currentPassword, (string)$user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect.'];
            }

            if (class_exists('Security') && Security::isPasswordReused($this->db, $userId, $newPassword)) {
                return ['success' => false, 'message' => 'You cannot reuse a recent password.'];
            }

            $newHash = class_exists('Security')
                ? Security::hashPassword($newPassword)
                : password_hash($newPassword, PASSWORD_DEFAULT);

            $update = $this->db->prepare('
                UPDATE users
                SET password_hash = :hash,
                    require_password_change = 0,
                    updated_at = NOW()
                WHERE id = :id
            ');
            $update->execute([
                'hash' => $newHash,
                'id' => $userId,
            ]);

            try {
                $history = $this->db->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (:user_id, :password_hash)');
                $history->execute([
                    'user_id' => $userId,
                    'password_hash' => $newHash,
                ]);
            } catch (Exception $e) {
                error_log('Password history insert failed for user ' . $userId . ': ' . $e->getMessage());
            }

            return ['success' => true, 'message' => 'Password changed successfully.'];
        } catch (Exception $e) {
            error_log('Password change failed for user ' . $userId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to change password.'];
        }
    }
    
    /**
     * Reset password using token
     */
    public function resetPassword($token, $newPassword) {
        try {
            $policyErrors = [];
            if (!Security::validatePasswordPolicy($newPassword, $policyErrors)) {
                return ['success' => false, 'message' => implode(' ', $policyErrors)];
            }

            $sql = "SELECT id FROM users WHERE password_reset_token = :token 
                    AND password_reset_expires > NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['token' => $token]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid or expired token'];
            }

            if ($this->isPasswordReused((int)$user['id'], (string)$newPassword)) {
                return ['success' => false, 'message' => 'Password was used recently. Choose a new one.'];
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
