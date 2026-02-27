<?php
/**
 * Sample Configuration File
 * Copy this to config.php and update with your settings
 */

// Error reporting (Set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'smns');
define('DB_USER', 'root');
define('DB_PASS', '');

// Application Configuration
define('APP_NAME', 'Seminary Results Management System');
define('APP_SHORT_NAME', 'SMNS');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/smns');
define('BASE_PATH', __DIR__);

// Timezone
date_default_timezone_set('UTC');

// Session Configuration
define('SESSION_TIMEOUT', 3600);

// Security Configuration
define('MAX_LOGIN_ATTEMPTS', 5);
define('ACCOUNT_LOCKOUT_DURATION', 30);

// File Upload Configuration
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('MAX_FILE_SIZE', 5242880);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

// Pagination
define('RECORDS_PER_PAGE', 20);

// Payment Gateway / Mobile Money
define('PAYMENT_GATEWAY_MODE', 'live'); // mock | sandbox | live
define('MOBILE_MONEY_DEFAULT_PROVIDER', 'mtn');
define('PAYMENT_GATEWAY_WEBHOOK_SECRET', 'replace-with-strong-shared-secret');
define('PAYMENT_GATEWAY_WEBHOOK_URL', BASE_URL . '/api/payments/webhook.php');
define('PAYMENT_GATEWAY_HTTP_TIMEOUT', 30);
define('MOBILE_MONEY_MTN_INITIATE_URL', 'https://your-mtn-gateway.example.com/collections/request-to-pay');
define('MOBILE_MONEY_MTN_BEARER_TOKEN', '');
define('MOBILE_MONEY_MTN_API_KEY', '');
define('MOBILE_MONEY_MTN_API_SECRET', '');
define('MOBILE_MONEY_AIRTEL_INITIATE_URL', 'https://your-airtel-gateway.example.com/merchant/v1/payments');
define('MOBILE_MONEY_AIRTEL_BEARER_TOKEN', '');
define('MOBILE_MONEY_AIRTEL_CLIENT_ID', '');
define('MOBILE_MONEY_AIRTEL_CLIENT_SECRET', '');
define('MOBILE_MONEY_AIRTEL_COUNTRY_CODE', 'UG');

// Academic Configuration
define('STUDENT_ID_PREFIX', 'STD');
define('LECTURER_ID_PREFIX', 'LEC');
define('MIN_CREDIT_HOURS', 12);
define('MAX_CREDIT_HOURS', 21);
define('PASS_MARK', 50);

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-gmail-app-password');
define('SMTP_SECURE', false);
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', APP_NAME);
define('EMAIL_TRANSPORT', 'nodemailer');
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
