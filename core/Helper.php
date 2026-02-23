<?php
/**
 * Helper Functions Class
 * Provides utility functions
 */
class Helper {
    private static $lastEmailError = '';
    private static $emailFailureTableEnsured = false;

    /**
     * Send an email using the configured transport.
     */
    public static function sendEmail($to, $subject, $message, $options = []) {
        self::$lastEmailError = '';
        $recipients = is_array($to) ? $to : explode(',', (string)$to);
        $recipients = array_values(array_filter(array_map('trim', $recipients), function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }));

        if (empty($recipients) || trim((string)$subject) === '') {
            self::$lastEmailError = 'No valid recipients or subject is empty.';
            return false;
        }

        $fromEmail = $options['from_email'] ?? (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : null);
        $fromName = $options['from_name'] ?? (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : APP_NAME);
        $html = $options['html'] ?? null;
        $notifyAdminOnFailure = !array_key_exists('notify_admin_on_failure', $options) || (bool)$options['notify_admin_on_failure'];
        $transportError = '';
        $transportUsed = '';
        $retryAttempts = (int)($options['retry_attempts'] ?? (defined('EMAIL_RETRY_ATTEMPTS') ? EMAIL_RETRY_ATTEMPTS : 2));
        if ($retryAttempts < 1) {
            $retryAttempts = 1;
        }
        $retryDelayMs = (int)($options['retry_delay_ms'] ?? (defined('EMAIL_RETRY_DELAY_MS') ? EMAIL_RETRY_DELAY_MS : 1200));
        if ($retryDelayMs < 0) {
            $retryDelayMs = 0;
        }
        $allowPhpFallback = array_key_exists('allow_php_fallback', $options)
            ? (bool)$options['allow_php_fallback']
            : (defined('EMAIL_FALLBACK_PHP_MAIL') && EMAIL_FALLBACK_PHP_MAIL);

        $transport = strtolower((string)($options['transport'] ?? (defined('EMAIL_TRANSPORT') ? EMAIL_TRANSPORT : 'php_mail')));
        if ($transport === 'nodemailer') {
            $nodeScript = $options['node_script'] ?? (defined('NODEMAILER_SCRIPT') ? NODEMAILER_SCRIPT : (BASE_PATH . '/scripts/mailer/send-email.js'));
            $nodeBin = $options['node_bin'] ?? (defined('NODE_BIN') ? NODE_BIN : 'node');
            $transportUsed = 'nodemailer';

            if (is_file($nodeScript)) {
                $payload = [
                    'to' => $recipients,
                    'subject' => (string)$subject,
                    'text' => (string)$message,
                    'html' => $html,
                    'from' => [
                        'email' => $fromEmail,
                        'name' => $fromName
                    ],
                    'smtp' => [
                        'host' => defined('SMTP_HOST') ? SMTP_HOST : '',
                        'port' => defined('SMTP_PORT') ? (int)SMTP_PORT : 587,
                        'username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
                        'password' => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
                        'secure' => defined('SMTP_SECURE') ? (bool)SMTP_SECURE : false
                    ]
                ];

                $encoded = base64_encode((string)json_encode($payload));
                $cmd = escapeshellarg((string)$nodeBin) . ' ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($encoded) . ' 2>&1';
                $lastOutput = [];
                $exitCode = 1;
                for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
                    $output = [];
                    $exitCode = 1;
                    @exec($cmd, $output, $exitCode);
                    $lastOutput = $output;

                    if ($exitCode === 0) {
                        return true;
                    }

                    if ($attempt < $retryAttempts && $retryDelayMs > 0) {
                        usleep($retryDelayMs * 1000);
                    }
                }

                $transportError = self::extractTransportErrorMessage($lastOutput, 'Nodemailer send failed');
                error_log('Nodemailer send failed after ' . $retryAttempts . ' attempt(s): ' . $transportError);
            } else {
                $transportError = 'Nodemailer script missing: ' . $nodeScript;
                error_log($transportError);
            }

            if (!$allowPhpFallback) {
                self::$lastEmailError = $transportError !== '' ? $transportError : 'Nodemailer transport failed.';
                if ($notifyAdminOnFailure) {
                    self::recordEmailDeliveryFailure($recipients, (string)$subject, (string)$message, $html, $transportUsed, self::$lastEmailError, $options);
                }
                return false;
            }
        }

        if ($transportUsed === '') {
            $transportUsed = 'php_mail';
        }

        $headers = 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $mailSent = false;
        if (!empty($html)) {
            $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
            $mailSent = @mail(implode(',', $recipients), (string)$subject, (string)$html, $headers);
        } else {
            $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
            $mailSent = @mail(implode(',', $recipients), (string)$subject, (string)$message, $headers);
        }

        if ($mailSent) {
            return true;
        }

        $phpMailError = 'PHP mail() returned false.';
        self::$lastEmailError = $transportError !== '' ? ($transportError . ' | ' . $phpMailError) : $phpMailError;

        if ($notifyAdminOnFailure) {
            self::recordEmailDeliveryFailure($recipients, (string)$subject, (string)$message, $html, $transportUsed, self::$lastEmailError, $options);
        }

        return false;
    }

    /**
     * Send an email using a standardized template type.
     */
    public static function sendTemplatedEmail($templateType, $to, $data = [], $options = []) {
        if (!class_exists('EmailTemplate')) {
            return false;
        }
        $rendered = EmailTemplate::render((string)$templateType, is_array($data) ? $data : []);
        $subject = (string)($options['subject'] ?? ($rendered['subject'] ?? ''));
        $text = (string)($options['message'] ?? ($rendered['text'] ?? ''));
        $mergedOptions = $options;
        if (!isset($mergedOptions['html']) && isset($rendered['html'])) {
            $mergedOptions['html'] = $rendered['html'];
        }
        $mergedOptions['template_type'] = (string)$templateType;
        if (empty($mergedOptions['context_label'])) {
            $mergedOptions['context_label'] = self::resolveTemplateContextLabel((string)$templateType);
        }
        return self::sendEmail($to, $subject, $text, $mergedOptions);
    }

    /**
     * Last email error captured by sendEmail().
     */
    public static function getLastEmailError() {
        return self::$lastEmailError;
    }

    /**
     * Retry sending an email from a recorded failed-delivery row.
     */
    public static function resendFailedEmail($failureId, $actorUserId = null) {
        $failureId = (int)$failureId;
        if ($failureId <= 0) {
            return ['success' => false, 'message' => 'Invalid failed email id.'];
        }

        try {
            $db = new Database();
            $conn = $db->getConnection();
            self::ensureEmailFailureTable($conn);

            $stmt = $conn->prepare("SELECT * FROM email_delivery_failures WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $failureId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'message' => 'Failed email record not found.'];
            }

            $recipients = array_values(array_filter(array_map('trim', explode(',', (string)($row['recipients'] ?? '')))));
            if (empty($recipients)) {
                $update = $conn->prepare("UPDATE email_delivery_failures
                    SET status = 'resend_failed', attempts = attempts + 1, last_attempt_at = NOW(), last_error_message = :err
                    WHERE id = :id");
                $update->execute(['err' => 'No recipients available for resend.', 'id' => $failureId]);
                return ['success' => false, 'message' => 'No recipients available for resend.'];
            }

            $sendOptions = [
                'html' => $row['message_html'] ?? '',
                'transport' => $row['transport'] ?: (defined('EMAIL_TRANSPORT') ? EMAIL_TRANSPORT : 'php_mail'),
                'notify_admin_on_failure' => false,
                'context_label' => $row['context_label'] ?: 'Manual Resend'
            ];
            if (!empty($row['source_page'])) {
                $sendOptions['source_page'] = $row['source_page'];
            }

            $sent = self::sendEmail(
                $recipients,
                (string)($row['subject'] ?? ''),
                (string)($row['message_text'] ?? ''),
                $sendOptions
            );

            if ($sent) {
                $update = $conn->prepare("UPDATE email_delivery_failures
                    SET status = 'resent', attempts = attempts + 1, last_attempt_at = NOW(), resent_at = NOW(), last_error_message = NULL
                    WHERE id = :id");
                $update->execute(['id' => $failureId]);
                return ['success' => true, 'message' => 'Email resent successfully.'];
            }

            $error = self::getLastEmailError() ?: 'Resend failed.';
            $update = $conn->prepare("UPDATE email_delivery_failures
                SET status = 'resend_failed', attempts = attempts + 1, last_attempt_at = NOW(), last_error_message = :err
                WHERE id = :id");
            $update->execute(['err' => $error, 'id' => $failureId]);

            return ['success' => false, 'message' => $error];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Resend failed: ' . $e->getMessage()];
        }
    }

    private static function resolveTemplateContextLabel($templateType) {
        $map = [
            'credentials_issued' => 'Credentials Delivery',
            'password_reset' => 'Password Reset',
            'approval_status' => 'Approval Update',
            'request_response' => 'Request Response',
            'finance_alert' => 'Finance Alert',
            'invite_notice' => 'Invitation Notice',
            'scheduled_report' => 'Scheduled Report',
            'security_new_device_login' => 'Login Security Alert'
        ];
        return $map[$templateType] ?? ucwords(str_replace('_', ' ', (string)$templateType));
    }

    private static function extractTransportErrorMessage($outputLines, $fallback = 'Email transport failed.') {
        $lines = is_array($outputLines) ? $outputLines : [];
        $combined = trim(implode("\n", $lines));
        if ($combined === '') {
            return $fallback;
        }

        foreach ($lines as $line) {
            $decoded = json_decode((string)$line, true);
            if (is_array($decoded)) {
                $parts = [];
                if (!empty($decoded['message'])) {
                    $parts[] = $decoded['message'];
                }
                if (!empty($decoded['details'])) {
                    $parts[] = $decoded['details'];
                }
                if (!empty($parts)) {
                    return implode(': ', $parts);
                }
            }
        }

        return self::truncateText($combined, 600);
    }

    private static function recordEmailDeliveryFailure($recipients, $subject, $textMessage, $htmlMessage, $transport, $errorMessage, $options = []) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            self::ensureEmailFailureTable($conn);

            $recipientList = implode(',', array_map('trim', (array)$recipients));
            $contextLabel = trim((string)($options['context_label'] ?? 'System Communication'));
            $templateType = trim((string)($options['template_type'] ?? ''));
            $sourcePage = trim((string)($options['source_page'] ?? ($_SERVER['REQUEST_URI'] ?? '')));
            $failureLink = trim((string)($options['failure_link'] ?? ''));

            $insert = $conn->prepare("INSERT INTO email_delivery_failures
                (recipients, subject, message_text, message_html, transport, context_label, template_type, source_page, error_message, status, attempts, last_attempt_at, created_by_user_id)
                VALUES (:recipients, :subject, :message_text, :message_html, :transport, :context_label, :template_type, :source_page, :error_message, 'failed', 1, NOW(), :created_by_user_id)");
            $insert->execute([
                'recipients' => self::truncateText($recipientList, 2000),
                'subject' => self::truncateText((string)$subject, 255),
                'message_text' => (string)$textMessage,
                'message_html' => (string)$htmlMessage,
                'transport' => self::truncateText((string)$transport, 50),
                'context_label' => self::truncateText($contextLabel, 120),
                'template_type' => self::truncateText($templateType, 80),
                'source_page' => self::truncateText($sourcePage, 255),
                'error_message' => self::truncateText((string)$errorMessage, 2000),
                'created_by_user_id' => self::resolveCurrentUserId()
            ]);
            $failureId = (int)$conn->lastInsertId();

            $title = 'Email delivery failed';
            if ($contextLabel !== '') {
                $title .= ': ' . $contextLabel;
            }

            $summaryRecipients = self::summarizeRecipients($recipientList);
            $message = 'To: ' . $summaryRecipients . '. Error: ' . self::truncateText((string)$errorMessage, 500);
            if ($sourcePage !== '') {
                $message .= ' | Source: ' . $sourcePage;
            }
            if ($failureLink !== '') {
                $message .= ' | Location: ' . $failureLink;
            }

            self::notifyAdmins($conn, $title, $message, 'error', 'action:resend_email:' . $failureId);
        } catch (Exception $e) {
            error_log('Failed to record email delivery failure: ' . $e->getMessage());
        }
    }

    private static function summarizeRecipients($recipientList) {
        $items = array_values(array_filter(array_map('trim', explode(',', (string)$recipientList))));
        if (empty($items)) {
            return 'unknown recipient';
        }
        if (count($items) <= 3) {
            return implode(', ', $items);
        }
        $first = array_slice($items, 0, 3);
        return implode(', ', $first) . ' +' . (count($items) - 3) . ' more';
    }

    private static function resolveCurrentUserId() {
        $keys = [
            'admin_user_id',
            'student_user_id',
            'lecturer_user_id',
            'finance_user_id',
            'user_id'
        ];
        foreach ($keys as $key) {
            if (!empty($_SESSION[$key])) {
                return (int)$_SESSION[$key];
            }
        }
        return null;
    }

    private static function notifyAdmins($conn, $title, $message, $type = 'info', $link = null) {
        try {
            $stmt = $conn->query("SELECT id FROM users WHERE role = 'admin'");
            $adminIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            if (empty($adminIds)) {
                return;
            }

            $insert = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at)
                VALUES (:uid, :title, :message, :type, :link, NOW())");

            foreach ($adminIds as $adminId) {
                try {
                    $insert->execute([
                        'uid' => (int)$adminId,
                        'title' => self::truncateText((string)$title, 255),
                        'message' => self::truncateText((string)$message, 2000),
                        'type' => (string)$type,
                        'link' => $link
                    ]);
                } catch (Exception $inner) {
                    // Continue notifying other admins even if one insert fails.
                }
            }
        } catch (Exception $e) {
            error_log('Failed to notify admins about email failure: ' . $e->getMessage());
        }
    }

    private static function ensureEmailFailureTable($conn) {
        if (self::$emailFailureTableEnsured) {
            return;
        }

        $conn->exec("CREATE TABLE IF NOT EXISTS email_delivery_failures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipients TEXT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message_text MEDIUMTEXT NULL,
            message_html MEDIUMTEXT NULL,
            transport VARCHAR(50) NULL,
            context_label VARCHAR(120) NULL,
            template_type VARCHAR(80) NULL,
            source_page VARCHAR(255) NULL,
            error_message TEXT NULL,
            last_error_message TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'failed',
            attempts INT NOT NULL DEFAULT 1,
            last_attempt_at DATETIME NULL,
            resent_at DATETIME NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        self::$emailFailureTableEnsured = true;
    }

    private static function truncateText($value, $limit) {
        $text = (string)$value;
        if ($limit <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }
        return substr($text, 0, $limit);
    }
    
    /**
     * Format date
     */
    public static function formatDate($date, $format = 'M d, Y') {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
    
    /**
     * Format datetime
     */
    public static function formatDateTime($datetime, $format = 'M d, Y g:i A') {
        if (empty($datetime)) return '';
        return date($format, strtotime($datetime));
    }
    
    /**
     * Time ago
     */
    public static function timeAgo($datetime) {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) {
            return 'just now';
        } else if ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } else if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } else if ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else if ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        } else if ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($diff / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }
    
    /**
     * Format currency
     */
    public static function formatCurrency($amount, $currency = 'UGX', $decimals = null) {
        $value = (float)$amount;
        $curr = strtoupper(trim((string)$currency));

        if ($curr === 'USD') {
            $places = $decimals === null ? 2 : (int)$decimals;
            return '$' . number_format($value, $places);
        }

        if ($curr === 'UGX') {
            $places = $decimals === null ? 0 : (int)$decimals;
            return 'UGX ' . number_format($value, $places);
        }

        $places = $decimals === null ? 2 : (int)$decimals;
        return $curr . ' ' . number_format($value, $places);
    }

    /**
     * Resolve USD -> UGX exchange rate from settings.
     */
    public static function getUsdUgxRate($fallback = 3700.0) {
        $rate = (float)$fallback;
        if (function_exists('getSetting')) {
            $configured = (float)getSetting('usd_to_ugx_rate', $fallback);
            if ($configured > 0) {
                $rate = $configured;
            }
        }
        if ($rate <= 0) {
            $rate = 3700.0;
        }
        return $rate;
    }

    /**
     * Format an amount as both USD and UGX.
     * Assumes UGX base amount unless base currency is explicitly USD.
     */
    public static function formatCurrencyDual($amount, $baseCurrency = 'UGX', $usdUgxRate = null) {
        $rate = $usdUgxRate === null ? self::getUsdUgxRate() : (float)$usdUgxRate;
        if ($rate <= 0) {
            $rate = 3700.0;
        }

        $base = strtoupper(trim((string)$baseCurrency));
        $numericAmount = (float)$amount;

        if ($base === 'USD') {
            $usd = $numericAmount;
            $ugx = $numericAmount * $rate;
        } else {
            $ugx = $numericAmount;
            $usd = $numericAmount / $rate;
        }

        return self::formatCurrency($usd, 'USD', 2) . ' / ' . self::formatCurrency($ugx, 'UGX', 0);
    }
    
    /**
     * Format number
     */
    public static function formatNumber($number, $decimals = 0) {
        return number_format($number, $decimals);
    }
    
    /**
     * Generate student ID
     */
    public static function generateStudentID($prefix = 'STD', $lastId = 0) {
        $nextId = $lastId + 1;
        return $prefix . str_pad($nextId, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate lecturer ID
     */
    public static function generateLecturerID($prefix = 'LEC', $lastId = 0) {
        $nextId = $lastId + 1;
        return $prefix . str_pad($nextId, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate invoice number
     */
    public static function generateInvoiceNumber($prefix = 'INV', $year = null) {
        $year = $year ?? date('Y');
        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . '-' . $year . '-' . $random;
    }
    
    /**
     * Generate receipt number
     */
    public static function generateReceiptNumber($prefix = 'REC') {
        $timestamp = time();
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . '-' . $timestamp . '-' . $random;
    }
    
    /**
     * Get academic status color
     */
    public static function getStatusColor($status) {
        $colors = [
            'active' => 'success',
            'inactive' => 'secondary',
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'suspended' => 'danger',
            'completed' => 'info',
            'published' => 'success',
            'graduated' => 'primary'
        ];
        
        return $colors[strtolower($status)] ?? 'secondary';
    }
    
    /**
     * Get GPA color
     */
    public static function getGPAColor($gpa) {
        if ($gpa >= 4.5) return 'success';  // A+/A (4.5-5.0)
        if ($gpa >= 4.0) return 'info';     // B+ (4.0-4.5)
        if ($gpa >= 3.0) return 'primary';  // B/C+/C (3.0-4.0)
        if ($gpa >= 2.0) return 'success';  // D+/D (2.0-3.0) - passing
        if ($gpa >= 1.0) return 'warning';  // E/E- (1.0-2.0) - marginal
        return 'danger';                    // F (0.0)
    }
    
    /**
     * Get grade letter color (for badges)
     */
    public static function getGradeColor($gradeLetter) {
        $gradeLetter = strtoupper(trim($gradeLetter));
        
        // Passing grades show green/blue, failing shows red
        switch($gradeLetter) {
            case 'A+':
            case 'A':
                return 'success'; // green (5.0 GP)
            case 'B+':
            case 'B':
                return 'info'; // blue (4.0-4.5 GP)
            case 'C+':
            case 'C':
                return 'primary'; // darker blue (3.0-3.5 GP)
            case 'D+':
            case 'D':
                return 'success'; // green (2.0-2.5 GP - passing)
            case 'E':
            case 'E-':
                return 'warning'; // yellow/orange (1.0-1.5 GP - marginal pass)
            case 'F':
                return 'danger'; // red (0.0 GP - fail)
            default:
                return 'secondary'; // gray
        }
    }
    
    /**
     * Redirect
     */
    public static function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Redirect with message
     */
    public static function redirectWithMessage($url, $type, $message) {
        $session = new Session();
        $session->setFlash($type, $message);
        self::redirect($url);
    }
    
    /**
     * Get current academic year
     */
    public static function getCurrentAcademicYear() {
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "SELECT * FROM academic_years WHERE status = 'active' ORDER BY start_date DESC LIMIT 1";
        $stmt = $conn->query($sql);
        return $stmt->fetch();
    }
    
    /**
     * Get current semester
     */
    public static function getCurrentSemester() {
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "SELECT * FROM semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1";
        $stmt = $conn->query($sql);
        return $stmt->fetch();
    }
    
    /**
     * Paginate results
     */
    public static function paginate($totalItems, $itemsPerPage, $currentPage = 1) {
        $totalPages = ceil($totalItems / $itemsPerPage);
        $currentPage = max(1, min($currentPage, $totalPages));
        $offset = ($currentPage - 1) * $itemsPerPage;
        
        return [
            'total_items' => $totalItems,
            'items_per_page' => $itemsPerPage,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'offset' => $offset,
            'has_prev' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages
        ];
    }
    
    /**
     * Truncate text
     */
    public static function truncate($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . $suffix;
    }
    
    /**
     * Get user full name
     */
    public static function getFullName($firstName, $middleName, $lastName) {
        $name = $firstName;
        if (!empty($middleName)) {
            $name .= ' ' . $middleName;
        }
        $name .= ' ' . $lastName;
        return $name;
    }
}
