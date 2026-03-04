<?php
/**
 * MFA service: email OTP challenge issuing and verification.
 */
class MfaService {
    private $db;

    private static function isLocalRequest() {
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

    private static function getSettingValue($key, $default = null) {
        if (function_exists('getSetting')) {
            try {
                $val = getSetting((string)$key, null);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            } catch (Exception $e) {
                // fall through
            }
        }
        return $default;
    }

    private static function getBoolSetting($key, $default = false) {
        $fallback = $default ? '1' : '0';
        $raw = strtolower(trim((string)self::getSettingValue($key, $fallback)));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function getIntSetting($key, $default = 0, $min = 0) {
        $raw = self::getSettingValue($key, $default);
        $v = (int)$raw;
        return $v < $min ? $min : $v;
    }

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function ensureTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS auth_mfa_challenges (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                module VARCHAR(20) NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 5,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_module (user_id, module),
                INDEX idx_expires (expires_at),
                INDEX idx_used (used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public static function isMfaRequiredForRole($role) {
        $defaultEnabled = (defined('MFA_ENABLED') && MFA_ENABLED);
        if (!self::getBoolSetting('mfa_enabled', $defaultEnabled)) {
            return false;
        }
        $role = strtolower(trim((string)$role));
        $defaultRoles = defined('MFA_ENFORCED_ROLES') ? (string)MFA_ENFORCED_ROLES : '';
        $raw = (string)self::getSettingValue('mfa_enforced_roles', $defaultRoles);
        if ($raw === '') {
            return false;
        }
        $roles = array_filter(array_map('trim', explode(',', strtolower($raw))));
        return in_array($role, $roles, true);
    }

    public function issueChallenge($userId, $module, $email) {
        $userId = (int)$userId;
        $module = strtolower(trim((string)$module));
        $email = trim((string)$email);
        $adminOtpEmail = trim((string)self::getSettingValue('admin_mfa_email', (defined('ADMIN_MFA_EMAIL') ? ADMIN_MFA_EMAIL : '')));
        if ($module === 'admin' && filter_var($adminOtpEmail, FILTER_VALIDATE_EMAIL)) {
            $email = $adminOtpEmail;
        }
        if ($userId <= 0 || $module === '') {
            return ['success' => false, 'message' => 'Unable to prepare MFA challenge.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'MFA requires a valid email address on file.'];
        }

        $this->ensureTable();

        $defaultCodeLen = defined('MFA_CODE_LENGTH') ? (int)MFA_CODE_LENGTH : 6;
        $defaultMaxAttempts = defined('MFA_MAX_ATTEMPTS') ? (int)MFA_MAX_ATTEMPTS : 5;
        $defaultTtl = defined('MFA_CHALLENGE_TTL_SECONDS') ? (int)MFA_CHALLENGE_TTL_SECONDS : 300;
        $codeLength = self::getIntSetting('mfa_code_length', $defaultCodeLen, 4);
        $maxAttempts = self::getIntSetting('mfa_max_attempts', $defaultMaxAttempts, 1);
        $ttl = self::getIntSetting('mfa_challenge_ttl_seconds', $defaultTtl, 60);

        $min = (int)pow(10, $codeLength - 1);
        $max = (int)pow(10, $codeLength) - 1;
        $code = (string)random_int($min, $max);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $stmt = $this->db->prepare("
            INSERT INTO auth_mfa_challenges (user_id, module, code_hash, expires_at, max_attempts)
            VALUES (:user_id, :module, :code_hash, :expires_at, :max_attempts)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'module' => $module,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'max_attempts' => $maxAttempts
        ]);

        $subject = APP_NAME . ' - Security verification code';
        $message = "Your login verification code is: {$code}\n\n";
        $message .= "This code expires in " . (int)ceil($ttl / 60) . " minute(s).\n";
        $message .= "If you did not try to sign in, contact support immediately.";

        // Localhost delivery mode switch:
        // - fast: return OTP immediately in response (no SMTP wait)
        // - email: send through configured email transport for real inbox testing
        $localDeliveryMode = strtolower(trim((string)self::getSettingValue(
            'mfa_local_delivery_mode',
            (defined('MFA_LOCAL_DELIVERY_MODE') ? MFA_LOCAL_DELIVERY_MODE : 'fast')
        )));
        if (!in_array($localDeliveryMode, ['fast', 'email'], true)) {
            $localDeliveryMode = 'fast';
        }
        if (self::isLocalRequest() && $localDeliveryMode === 'fast') {
            error_log('MFA local fast mode challenge for user ' . $userId . ' (' . $module . '): ' . $code);
            return [
                'success' => true,
                'message' => 'Local verification code: ' . $code
                    . ' (expires in ' . (int)ceil($ttl / 60) . ' minute(s)).'
            ];
        }

        $sent = Helper::sendEmail([$email], $subject, $message, [
            'context_label' => 'MFA OTP',
            // Keep MFA responsive on slow localhost SMTP/network links.
            'retry_attempts' => 1,
            'retry_delay_ms' => 0,
            'allow_php_fallback' => false,
            'smtp_connection_timeout_ms' => 6000,
            'smtp_greeting_timeout_ms' => 5000,
            'smtp_socket_timeout_ms' => 7000
        ]);
        if (!$sent) {
            $lastError = method_exists('Helper', 'getLastEmailError') ? trim((string)Helper::getLastEmailError()) : '';
            error_log('MFA email delivery failed for user ' . $userId . ' (' . $module . '): ' . ($lastError !== '' ? $lastError : 'unknown error'));

            if ((defined('APP_DEBUG') && APP_DEBUG) || self::isLocalRequest()) {
                $reason = $lastError !== '' ? $lastError : 'unknown transport error';
                return [
                    'success' => true,
                    'message' => 'Email delivery failed (' . $reason . '). Use verification code: ' . $code
                        . ' (expires in ' . (int)ceil($ttl / 60) . ' minute(s)).'
                ];
            }
            return ['success' => false, 'message' => 'Unable to send MFA code. Please try again shortly.'];
        }

        return ['success' => true, 'message' => 'Verification code sent to your email address.'];
    }

    public function verifyChallenge($userId, $module, $code) {
        $userId = (int)$userId;
        $module = strtolower(trim((string)$module));
        $code = trim((string)$code);
        if ($userId <= 0 || $module === '' || $code === '') {
            return ['success' => false, 'message' => 'Verification code is required.'];
        }
        $this->ensureTable();

        $stmt = $this->db->prepare("
            SELECT *
            FROM auth_mfa_challenges
            WHERE user_id = :user_id
              AND module = :module
              AND used_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId, 'module' => $module]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$challenge) {
            return ['success' => false, 'message' => 'No active MFA challenge found. Click Resend Code or login again.'];
        }

        if (strtotime((string)$challenge['expires_at']) < time()) {
            return ['success' => false, 'message' => 'Verification code expired. Click Resend Code to get a new code.'];
        }

        $attempts = (int)($challenge['attempts'] ?? 0);
        $maxAttempts = (int)($challenge['max_attempts'] ?? 5);
        if ($attempts >= $maxAttempts) {
            return ['success' => false, 'message' => 'Maximum verification attempts reached. Click Resend Code to request a new code.'];
        }

        if (!password_verify($code, (string)$challenge['code_hash'])) {
            $up = $this->db->prepare("UPDATE auth_mfa_challenges SET attempts = attempts + 1 WHERE id = :id");
            $up->execute(['id' => (int)$challenge['id']]);
            return ['success' => false, 'message' => 'Invalid verification code.'];
        }

        $done = $this->db->prepare("UPDATE auth_mfa_challenges SET used_at = NOW() WHERE id = :id");
        $done->execute(['id' => (int)$challenge['id']]);
        return ['success' => true];
    }
}
