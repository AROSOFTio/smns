<?php
/**
 * Sample Configuration File
 * Copy this to config.php and update with your settings
 */

// Error reporting (keep disabled in production)
error_reporting(0);
ini_set('display_errors', 0);
define('APP_DEBUG', false);

if (!function_exists('smnsEnv')) {
    function smnsEnv($key, $default = '') {
        $value = getenv((string)$key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return $default;
    }
}

// Database Configuration
define('DB_HOST', smnsEnv('DB_HOST', smnsEnv('SMNS_DB_HOST', 'localhost:3306')));
define('DB_NAME', smnsEnv('DB_NAME', smnsEnv('SMNS_DB_NAME', 'smns')));
define('DB_USER', smnsEnv('DB_USER', smnsEnv('SMNS_DB_USER', 'root')));
define('DB_PASS', smnsEnv('DB_PASS', smnsEnv('SMNS_DB_PASS', '')));
define('DB_SSL_ENABLED', false);
define('DB_SSL_CA', '/path/to/ca.pem');
define('DB_SSL_CERT', '/path/to/client-cert.pem');
define('DB_SSL_KEY', '/path/to/client-key.pem');

// Application Configuration
define('APP_NAME', 'Seminary Results Management System');
define('APP_SHORT_NAME', 'SMNS');
define('APP_VERSION', '1.1.3');

$baseUrlFromEnv = getenv('SMNS_BASE_URL');
if (!is_string($baseUrlFromEnv) || trim($baseUrlFromEnv) === '') {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0';
    $scheme = $isHttps ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $appRoot = '';
    $marker = '/views/';
    $viewsPos = strpos($scriptName, $marker);
    if ($viewsPos !== false) {
        $appRoot = substr($scriptName, 0, $viewsPos);
    } elseif ($scriptName !== '') {
        $appRoot = rtrim(str_replace('/index.php', '', $scriptName), '/');
    }
    $baseUrlFromEnv = $scheme . '://' . $host . ($appRoot !== '' ? $appRoot : '');
}
define('BASE_URL', rtrim((string)$baseUrlFromEnv, '/'));
define('BASE_PATH', __DIR__);

// Timezone
date_default_timezone_set('UTC');

/**
 * Detect whether the current HTTP request is already protected by TLS.
 * Supports direct HTTPS and reverse-proxy forwarded headers.
 */
if (!function_exists('smnsIsHttpsRequest')) {
    function smnsIsHttpsRequest() {
        if (php_sapi_name() === 'cli') {
            return true;
        }
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        $scheme = strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto === 'https') {
            return true;
        }
        $forwardedSsl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        return $forwardedSsl === 'on' || $forwardedSsl === '1';
    }
}

// Transport Security / HTTPS
define('FORCE_HTTPS', true);
define('SESSION_COOKIE_SECURE', true);
define('SESSION_COOKIE_SAMESITE', 'Strict');
define('HSTS_ENABLED', true);
define('HSTS_MAX_AGE', 31536000);

if (php_sapi_name() !== 'cli' && FORCE_HTTPS && !smnsIsHttpsRequest() && !headers_sent()) {
    $redirectHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirectUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $redirectHost . $redirectUri, true, 301);
    exit;
}
if (php_sapi_name() !== 'cli' && HSTS_ENABLED && smnsIsHttpsRequest() && !headers_sent()) {
    header('Strict-Transport-Security: max-age=' . (int)HSTS_MAX_AGE . '; includeSubDomains');
}

// Session Configuration
define('SESSION_TIMEOUT', 3600);

// Security Configuration
define('MAX_LOGIN_ATTEMPTS', 5);
define('ACCOUNT_LOCKOUT_DURATION', 30);
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);
define('PASSWORD_HISTORY_LIMIT', 5);

// MFA (email OTP scaffold)
define('MFA_ENABLED', true);
define('MFA_ENFORCED_ROLES', 'admin,finance');
define('MFA_CODE_LENGTH', 6);
define('MFA_CHALLENGE_TTL_SECONDS', 300);
define('MFA_MAX_ATTEMPTS', 5);
define('ADMIN_MFA_EMAIL', getenv('ADMIN_MFA_EMAIL') ?: 'your-admin-inbox@example.com');
define('MFA_LOCAL_DELIVERY_MODE', getenv('MFA_LOCAL_DELIVERY_MODE') ?: 'email');
define('MFA_LOCAL_FALLBACK_EXPOSE_CODE', true);

// Privacy consent controls
define('PRIVACY_CONSENT_REQUIRED', true);
define('PRIVACY_CONSENT_KEY', 'privacy_notice');
define('PRIVACY_NOTICE_VERSION', '2026-02-28');

// File Upload Configuration
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('MAX_FILE_SIZE', 5242880);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

// Pagination
define('RECORDS_PER_PAGE', 20);

// Payment Gateway / Mobile Money
define('PAYMENT_GATEWAY_MODE', smnsEnv('PAYMENT_GATEWAY_MODE', 'mock')); // mock | sandbox | live
define('MOBILE_MONEY_DEFAULT_PROVIDER', 'mtn');
define('PAYMENT_GATEWAY_WEBHOOK_SECRET', smnsEnv('PAYMENT_GATEWAY_WEBHOOK_SECRET', ''));
define('PAYMENT_GATEWAY_WEBHOOK_URL', smnsEnv('PAYMENT_GATEWAY_WEBHOOK_URL', BASE_URL . '/api/payments/webhook.php'));
define('PAYMENT_GATEWAY_HTTP_TIMEOUT', 30);
define('MOBILE_MONEY_MTN_INITIATE_URL', smnsEnv('MOBILE_MONEY_MTN_INITIATE_URL', ''));
define('MOBILE_MONEY_MTN_BEARER_TOKEN', smnsEnv('MOBILE_MONEY_MTN_BEARER_TOKEN', ''));
define('MOBILE_MONEY_MTN_API_KEY', smnsEnv('MOBILE_MONEY_MTN_API_KEY', ''));
define('MOBILE_MONEY_MTN_API_SECRET', smnsEnv('MOBILE_MONEY_MTN_API_SECRET', ''));
define('MOBILE_MONEY_AIRTEL_INITIATE_URL', smnsEnv('MOBILE_MONEY_AIRTEL_INITIATE_URL', ''));
define('MOBILE_MONEY_AIRTEL_BEARER_TOKEN', smnsEnv('MOBILE_MONEY_AIRTEL_BEARER_TOKEN', ''));
define('MOBILE_MONEY_AIRTEL_CLIENT_ID', smnsEnv('MOBILE_MONEY_AIRTEL_CLIENT_ID', ''));
define('MOBILE_MONEY_AIRTEL_CLIENT_SECRET', smnsEnv('MOBILE_MONEY_AIRTEL_CLIENT_SECRET', ''));
define('MOBILE_MONEY_AIRTEL_COUNTRY_CODE', 'UG');

// Interoperability / External integrations
define('INTEGRATION_API_TOKEN', smnsEnv('SMNS_INTEGRATION_API_TOKEN', ''));
define('LMS_INTEGRATION_PROVIDER', 'moodle');
define('LMS_INTEGRATION_BASE_URL', smnsEnv('SMNS_LMS_BASE_URL', ''));
define('UPTIME_SLO_TARGET_PERCENT', 99.0);
define('RESTORE_DRILL_MAX_AGE_DAYS', 90);
define('UPTIME_MONITOR_TIMEOUT_SECONDS', 8);

// Backup controls
define('BACKUP_STORAGE_PATH', dirname(BASE_PATH, 2) . DIRECTORY_SEPARATOR . 'smns_secure_backups');
define('BACKUP_ENCRYPTION_ENABLED', true);
define('BACKUP_ENCRYPTION_KEY', smnsEnv('SMNS_BACKUP_ENCRYPTION_KEY', ''));

// Academic Configuration
define('STUDENT_ID_PREFIX', 'STD');
define('LECTURER_ID_PREFIX', 'LEC');
define('MIN_CREDIT_HOURS', 12);
define('MAX_CREDIT_HOURS', 21);
define('PASS_MARK', 50);

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', smnsEnv('SMTP_USERNAME', smnsEnv('SMTP_USER', '')));
define('SMTP_PASSWORD', smnsEnv('SMTP_PASSWORD', smnsEnv('SMTP_PASS', '')));
define('SMTP_SECURE', false);
define('SMTP_FROM_EMAIL', smnsEnv('SMTP_FROM_EMAIL', SMTP_USERNAME));
define('SMTP_FROM_NAME', APP_NAME);
define('EMAIL_TRANSPORT', 'smtp');
define('EMAIL_FALLBACK_PHP_MAIL', true);
define('NODE_BIN', 'node');
define('NODEMAILER_SCRIPT', BASE_PATH . '/scripts/mailer/send-email.js');

// Institution Information
define('INSTITUTION_NAME', 'Seminary Institution');
define('INSTITUTION_EMAIL', 'info@seminary.edu');
define('INSTITUTION_PHONE', '+1234567890');
define('INSTITUTION_ADDRESS', '123 Seminary Street, City, Country');
define('BANK_ACCOUNT_NAME', 'Seminary Management System');
define('BANK_ACCOUNT_NUMBER', '0000000000');
define('BANK_BRANCH', 'Main Branch');
define('BANK_SWIFT', 'SWIFTXXX');

// Autoload core classes
spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/core/' . $class . '.php',
        BASE_PATH . '/models/' . $class . '.php',
        BASE_PATH . '/controllers/' . $class . '.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});
