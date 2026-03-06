<?php
/**
 * Uptime monitor (CLI/cron).
 * Records probe outcomes and alerts admins on repeated downtime or SLO breach.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/AfricasTalkingSmsService.php';

function ensureUptimeTables(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS system_uptime_checks (
        id INT PRIMARY KEY AUTO_INCREMENT,
        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        target_url VARCHAR(500) NOT NULL,
        http_status INT NULL,
        response_ms INT NULL,
        is_up TINYINT(1) NOT NULL DEFAULT 0,
        status_label VARCHAR(30) NOT NULL DEFAULT 'down',
        error_message VARCHAR(500) NULL,
        payload_json MEDIUMTEXT NULL,
        INDEX idx_checked_at (checked_at),
        INDEX idx_is_up (is_up)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->exec("CREATE TABLE IF NOT EXISTS system_uptime_alerts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        alert_type VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        availability_percent DECIMAL(6,3) NULL,
        consecutive_failures INT NULL,
        recipient_count INT NOT NULL DEFAULT 0,
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        sms_recipient_count INT NOT NULL DEFAULT 0,
        sms_sent TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_alert_type (alert_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    try {
        $conn->exec("ALTER TABLE system_uptime_alerts ADD COLUMN sms_recipient_count INT NOT NULL DEFAULT 0 AFTER email_sent");
    } catch (Exception $e) {
        // Column already exists or table engine does not support this alter path.
    }
    try {
        $conn->exec("ALTER TABLE system_uptime_alerts ADD COLUMN sms_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_recipient_count");
    } catch (Exception $e) {
        // Column already exists or table engine does not support this alter path.
    }
}

function probeUptimeEndpoint(string $url, int $timeoutSeconds = 8): array
{
    $start = microtime(true);
    $httpCode = 0;
    $error = '';
    $body = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(2, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(2, min($timeoutSeconds, 5)));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        $body = (string)curl_exec($ch);
        if ($body === '' || $body === false) {
            $error = (string)curl_error($ch);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(2, $timeoutSeconds),
                'ignore_errors' => true,
            ]
        ]);
        $bodyRaw = @file_get_contents($url, false, $ctx);
        if ($bodyRaw === false) {
            $error = 'Unable to connect to endpoint';
            $body = '';
        } else {
            $body = (string)$bodyRaw;
        }
        if (!empty($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/\s(\d{3})\s/', (string)$line, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }
    }

    $elapsedMs = (int)round((microtime(true) - $start) * 1000);
    $payload = json_decode($body, true);
    $statusLabel = is_array($payload) ? strtolower((string)($payload['status'] ?? '')) : '';
    $isUp = ($httpCode >= 200 && $httpCode < 400 && $statusLabel !== 'down');

    return [
        'is_up' => $isUp,
        'http_status' => $httpCode > 0 ? $httpCode : null,
        'response_ms' => $elapsedMs,
        'status_label' => $statusLabel !== '' ? $statusLabel : ($isUp ? 'ok' : 'down'),
        'error' => $error !== '' ? $error : null,
        'payload' => is_array($payload) ? $payload : null,
    ];
}

function normalizeAlertPhone(string $phone): ?string
{
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }

    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (!is_string($phone) || $phone === '') {
        return null;
    }

    if (strpos($phone, '+') === 0) {
        $digits = '+' . preg_replace('/\D/', '', substr($phone, 1));
        return strlen($digits) >= 11 ? $digits : null;
    }

    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') {
        return null;
    }

    if (strpos($digits, '256') === 0) {
        return '+' . $digits;
    }
    if (strpos($digits, '0') === 0 && strlen($digits) >= 10) {
        return '+256' . ltrim($digits, '0');
    }
    if (strlen($digits) >= 9) {
        return '+' . $digits;
    }

    return null;
}

function fetchAlertRecipients(PDO $conn): array
{
    $emails = [];
    $phones = [];
    $stmt = $conn->query("
        SELECT u.email, a.phone
        FROM users u
        LEFT JOIN admins a ON a.user_id = u.id
        WHERE u.role = 'admin'
          AND u.status = 'active'
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
        $phone = normalizeAlertPhone((string)($row['phone'] ?? ''));
        if ($phone !== null) {
            $phones[] = $phone;
        }
    }
    return [
        'emails' => array_values(array_unique($emails)),
        'phones' => array_values(array_unique($phones)),
    ];
}

function logUptimeAlert(PDO $conn, string $type, string $message, ?float $availability, ?int $consecutiveFailures, bool $emailSent, int $recipientCount, bool $smsSent = false, int $smsRecipientCount = 0): void
{
    $stmt = $conn->prepare("
        INSERT INTO system_uptime_alerts
            (alert_type, message, availability_percent, consecutive_failures, recipient_count, email_sent, sms_recipient_count, sms_sent, created_at)
        VALUES
            (:alert_type, :message, :availability_percent, :consecutive_failures, :recipient_count, :email_sent, :sms_recipient_count, :sms_sent, NOW())
    ");
    $stmt->execute([
        'alert_type' => $type,
        'message' => $message,
        'availability_percent' => $availability,
        'consecutive_failures' => $consecutiveFailures,
        'recipient_count' => $recipientCount,
        'email_sent' => $emailSent ? 1 : 0,
        'sms_recipient_count' => max(0, $smsRecipientCount),
        'sms_sent' => $smsSent ? 1 : 0,
    ]);
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureUptimeTables($conn);

    $defaultTarget = defined('UPTIME_SLO_TARGET_PERCENT') ? (float)UPTIME_SLO_TARGET_PERCENT : 99.0;
    $targetPercent = (float)getSetting('availability_target_percent', $defaultTarget);
    if ($targetPercent <= 0 || $targetPercent > 100) {
        $targetPercent = 99.0;
    }
    $windowDays = (int)getSetting('availability_window_days', 30);
    if ($windowDays <= 0) {
        $windowDays = 30;
    }
    $timeoutSeconds = (int)getSetting('uptime_probe_timeout_seconds', 8);
    if ($timeoutSeconds <= 0) {
        $timeoutSeconds = 8;
    }

    $targetUrl = trim((string)getSetting('uptime_monitor_url', BASE_URL . '/api/health/uptime.php'));
    if ($targetUrl === '') {
        $targetUrl = BASE_URL . '/api/health/uptime.php';
    }

    $result = probeUptimeEndpoint($targetUrl, $timeoutSeconds);
    $insert = $conn->prepare("
        INSERT INTO system_uptime_checks
            (checked_at, target_url, http_status, response_ms, is_up, status_label, error_message, payload_json)
        VALUES
            (NOW(), :target_url, :http_status, :response_ms, :is_up, :status_label, :error_message, :payload_json)
    ");
    $insert->execute([
        'target_url' => $targetUrl,
        'http_status' => $result['http_status'],
        'response_ms' => (int)($result['response_ms'] ?? 0),
        'is_up' => !empty($result['is_up']) ? 1 : 0,
        'status_label' => (string)($result['status_label'] ?? 'down'),
        'error_message' => (string)($result['error'] ?? ''),
        'payload_json' => !empty($result['payload']) ? json_encode($result['payload']) : null,
    ]);

    $windowStart = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));
    $agg = $conn->prepare("
        SELECT COUNT(*) AS total_checks, COALESCE(SUM(is_up), 0) AS up_checks
        FROM system_uptime_checks
        WHERE checked_at >= :window_start
    ");
    $agg->execute(['window_start' => $windowStart]);
    $summary = $agg->fetch(PDO::FETCH_ASSOC) ?: ['total_checks' => 0, 'up_checks' => 0];
    $totalChecks = (int)($summary['total_checks'] ?? 0);
    $upChecks = (int)($summary['up_checks'] ?? 0);
    $availability = $totalChecks > 0 ? round(($upChecks / $totalChecks) * 100, 3) : 100.0;

    $recentStmt = $conn->query("SELECT is_up FROM system_uptime_checks ORDER BY checked_at DESC, id DESC LIMIT 10");
    $consecutiveFailures = 0;
    while ($row = $recentStmt->fetch(PDO::FETCH_ASSOC)) {
        if ((int)($row['is_up'] ?? 0) === 1) {
            break;
        }
        $consecutiveFailures++;
    }

    $triggerType = null;
    $triggerMessage = '';
    if ($consecutiveFailures >= 3) {
        $triggerType = 'consecutive_downtime';
        $triggerMessage = "Service appears down for {$consecutiveFailures} consecutive probes.";
    } elseif ($availability < $targetPercent && $totalChecks >= 20) {
        $triggerType = 'slo_breach';
        $triggerMessage = "Availability dropped to {$availability}% over last {$windowDays} days (target {$targetPercent}%).";
    }

    $alertSent = false;
    $recipientCount = 0;
    $smsSent = false;
    $smsRecipientCount = 0;
    if ($triggerType !== null) {
        $cooldownMinutes = (int)getSetting('uptime_alert_cooldown_minutes', 60);
        if ($cooldownMinutes <= 0) {
            $cooldownMinutes = 60;
        }

        $cooldownStmt = $conn->prepare("
            SELECT created_at
            FROM system_uptime_alerts
            WHERE alert_type = :alert_type
            ORDER BY id DESC
            LIMIT 1
        ");
        $cooldownStmt->execute(['alert_type' => $triggerType]);
        $recentAlertAt = $cooldownStmt->fetchColumn();
        $cooldownActive = false;
        if (!empty($recentAlertAt)) {
            $ts = strtotime((string)$recentAlertAt);
            if ($ts !== false && (time() - $ts) < ($cooldownMinutes * 60)) {
                $cooldownActive = true;
            }
        }

        if (!$cooldownActive) {
            $recipients = fetchAlertRecipients($conn);
            $emails = $recipients['emails'] ?? [];
            $phones = $recipients['phones'] ?? [];
            $recipientCount = count($emails);
            $smsRecipientCount = count($phones);
            if (!empty($emails)) {
                $subject = APP_NAME . ' - Uptime Alert (' . strtoupper($triggerType) . ')';
                $body = implode("\n", [
                    'Automated uptime monitor detected a reliability issue.',
                    'Time: ' . date('Y-m-d H:i:s'),
                    'Type: ' . $triggerType,
                    'Details: ' . $triggerMessage,
                    'Probe URL: ' . $targetUrl,
                    'Last HTTP Status: ' . (string)($result['http_status'] ?? 'n/a'),
                    'Last Response Time: ' . (string)($result['response_ms'] ?? 'n/a') . ' ms',
                    'Availability (window): ' . $availability . '%',
                    'Target: ' . $targetPercent . '%',
                    '',
                    'Review page: ' . BASE_URL . '/views/admin/system/health.php',
                ]);
                $alertSent = (bool)Helper::sendEmail(
                    $emails,
                    $subject,
                    $body,
                    null,
                    [
                        'context_label' => 'Uptime monitor alert',
                        'source_page' => 'scripts/uptime_monitor.php',
                        'notify_admin_on_failure' => false,
                    ]
                );
            }

            if (!empty($phones)) {
                $smsMessage = sprintf(
                    '%s uptime alert: %s. HTTP %s, %sms, availability %s%%. %s',
                    APP_SHORT_NAME,
                    $triggerMessage,
                    (string)($result['http_status'] ?? 'n/a'),
                    (string)($result['response_ms'] ?? 'n/a'),
                    (string)$availability,
                    BASE_URL . '/views/admin/system/health.php'
                );
                $sms = new AfricasTalkingSmsService();
                $smsResult = $sms->sendBulkMessage($smsMessage, $phones, [
                    'senderId' => defined('AFRICASTALKING_DEFAULT_SENDER_ID') ? AFRICASTALKING_DEFAULT_SENDER_ID : '',
                    'maskedNumber' => defined('AFRICASTALKING_DEFAULT_MASKED_NUMBER') ? AFRICASTALKING_DEFAULT_MASKED_NUMBER : '',
                    'telco' => defined('AFRICASTALKING_DEFAULT_TELCO') ? AFRICASTALKING_DEFAULT_TELCO : '',
                ]);
                $smsSent = !empty($smsResult['success']);
                if (!$smsSent && !empty($smsResult['message'])) {
                    error_log('Uptime SMS alert failed: ' . (string)$smsResult['message']);
                }
            }

            logUptimeAlert(
                $conn,
                $triggerType,
                $triggerMessage,
                $availability,
                $consecutiveFailures,
                $alertSent,
                $recipientCount,
                $smsSent,
                $smsRecipientCount
            );
        }
    }

    try {
        $logger = new Logger();
        $logger->log(null, 'uptime_probe', 'system', 'Uptime probe ' . (!empty($result['is_up']) ? 'up' : 'down') . ' (' . (string)($result['http_status'] ?? 'n/a') . ', ' . (string)($result['response_ms'] ?? 'n/a') . 'ms)');
    } catch (Exception $e) {
        // non-fatal
    }

    if (PHP_SAPI === 'cli') {
        echo 'Uptime probe: ' . (!empty($result['is_up']) ? 'UP' : 'DOWN') . PHP_EOL;
        echo 'HTTP status: ' . (string)($result['http_status'] ?? 'n/a') . PHP_EOL;
        echo 'Response: ' . (string)($result['response_ms'] ?? 'n/a') . " ms" . PHP_EOL;
        echo 'Availability (' . $windowDays . 'd): ' . $availability . '% (target ' . $targetPercent . "%)" . PHP_EOL;
        if ($triggerType !== null) {
            echo 'Alert condition: ' . $triggerType . PHP_EOL;
        }
    }

    exit(!empty($result['is_up']) ? 0 : 1);
} catch (Exception $e) {
    error_log('Uptime monitor error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Uptime monitor failed: ' . $e->getMessage() . PHP_EOL);
    }
    exit(2);
}
