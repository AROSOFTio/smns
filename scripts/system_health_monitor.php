<?php
/**
 * System Health Monitor (CLI/cron)
 * Runs core + module checks and emails admins when warning/fail issues are detected.
 */
require_once __DIR__ . '/../config.php';

function monitorCheckMetadata($checkName) {
    $map = [
        'database' => ['label' => 'Database Connection', 'area' => 'Core/Database'],
        'classes' => ['label' => 'Core Class Loading', 'area' => 'Core'],
        'smtp' => ['label' => 'SMTP Connectivity', 'area' => 'Email/SMTP'],
        'uptime_slo' => ['label' => 'Availability SLO (>=99%)', 'area' => 'Reliability/Uptime'],
        'restore_drill' => ['label' => 'Restore Drill Recency', 'area' => 'Disaster Recovery'],
        'admin_pages' => ['label' => 'Admin Module Pages', 'area' => 'Module: Admin'],
        'student_pages' => ['label' => 'Student Module Pages', 'area' => 'Module: Student'],
        'lecturer_pages' => ['label' => 'Lecturer Module Pages', 'area' => 'Module: Lecturer'],
        'finance_pages' => ['label' => 'Finance Module Pages', 'area' => 'Module: Finance'],
        'admin_data' => ['label' => 'Admin Module Data', 'area' => 'Module: Admin'],
        'student_data' => ['label' => 'Student Module Data', 'area' => 'Module: Student'],
        'lecturer_data' => ['label' => 'Lecturer Module Data', 'area' => 'Module: Lecturer'],
        'finance_data' => ['label' => 'Finance Module Data', 'area' => 'Module: Finance'],
    ];
    if (isset($map[$checkName])) {
        return $map[$checkName];
    }
    return [
        'label' => ucwords(str_replace('_', ' ', (string)$checkName)),
        'area' => 'System',
    ];
}

function monitorCollectIssues($checks) {
    $issues = [];
    foreach ($checks as $name => $check) {
        $status = strtolower((string)($check['status'] ?? ''));
        if ($status !== 'warning' && $status !== 'fail') {
            continue;
        }
        $meta = monitorCheckMetadata((string)$name);
        $issues[] = [
            'check' => (string)$name,
            'label' => (string)$meta['label'],
            'area' => (string)$meta['area'],
            'status' => $status,
            'message' => (string)($check['message'] ?? ''),
        ];
    }
    return $issues;
}

function monitorEnsureAlertTable($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS system_health_alerts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        issue_hash VARCHAR(64) NOT NULL,
        fail_count INT NOT NULL DEFAULT 0,
        warning_count INT NOT NULL DEFAULT 0,
        issues_json MEDIUMTEXT NULL,
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        recipient_count INT NOT NULL DEFAULT 0,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        reported_by_user_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_issue_hash (issue_hash),
        INDEX idx_sent_at (sent_at),
        INDEX idx_resolved (resolved_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function monitorRunChecks() {
    $checks = [];

    // Core database
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $conn->query("SELECT 1");
        $checks['database'] = ['status' => 'pass', 'message' => 'Database connection successful'];
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'fail', 'message' => 'Database connection failed: ' . $e->getMessage()];
        return $checks;
    }

    // Core classes
    $required = ['Database', 'Auth', 'Session', 'Security', 'Helper', 'Logger', 'Validator'];
    $missing = [];
    foreach ($required as $class) {
        if (!class_exists($class)) $missing[] = $class;
    }
    $checks['classes'] = empty($missing)
        ? ['status' => 'pass', 'message' => 'All core classes loaded']
        : ['status' => 'fail', 'message' => 'Missing classes: ' . implode(', ', $missing)];

    // Module pages
    $modulePageChecks = [
        'admin_pages' => ['label' => 'Admin', 'paths' => ['views/admin/login.php', 'views/admin/dashboard.php', 'views/admin/logout.php']],
        'student_pages' => ['label' => 'Student', 'paths' => ['views/student/login.php', 'views/student/dashboard.php', 'views/student/logout.php']],
        'lecturer_pages' => ['label' => 'Lecturer', 'paths' => ['views/lecturer/login.php', 'views/lecturer/dashboard.php', 'views/lecturer/logout.php']],
        'finance_pages' => ['label' => 'Finance', 'paths' => ['views/finance/login.php', 'views/finance/dashboard.php', 'views/finance/logout.php']],
    ];
    foreach ($modulePageChecks as $checkKey => $cfg) {
        $missingFiles = [];
        foreach ($cfg['paths'] as $rel) {
            $full = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($full)) $missingFiles[] = $rel;
        }
        $checks[$checkKey] = empty($missingFiles)
            ? ['status' => 'pass', 'message' => $cfg['label'] . ' module routes/pages present']
            : ['status' => 'fail', 'message' => $cfg['label'] . ' module missing files: ' . implode(', ', $missingFiles)];
    }

    // Module tables
    $moduleDataChecks = [
        'admin_data' => ['label' => 'Admin', 'tables' => ['users', 'admins', 'notifications']],
        'student_data' => ['label' => 'Student', 'tables' => ['students', 'course_registrations', 'results']],
        'lecturer_data' => ['label' => 'Lecturer', 'tables' => ['lecturers', 'course_assignments', 'results']],
        'finance_data' => ['label' => 'Finance', 'tables' => ['finance_staff', 'invoices', 'payments']],
    ];
    foreach ($moduleDataChecks as $checkKey => $cfg) {
        $missingTables = [];
        foreach ($cfg['tables'] as $table) {
            $st = $conn->prepare("
                SELECT COUNT(*) AS c
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = :t
            ");
            $st->execute(['t' => $table]);
            $exists = (int)$st->fetchColumn();
            if ($exists < 1) $missingTables[] = $table;
        }
        $checks[$checkKey] = empty($missingTables)
            ? ['status' => 'pass', 'message' => $cfg['label'] . ' module data tables available']
            : ['status' => 'fail', 'message' => $cfg['label'] . ' module missing tables: ' . implode(', ', $missingTables)];
    }

    // SMTP reachability
    try {
        $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
        $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : null;
        if ($smtpHost && $smtpPort) {
            $fp = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                $checks['smtp'] = ['status' => 'pass', 'message' => "SMTP reachable: $smtpHost:$smtpPort"];
            } else {
                $checks['smtp'] = ['status' => 'warning', 'message' => "SMTP not reachable: $smtpHost:$smtpPort ($errno) $errstr"];
            }
        } else {
            $checks['smtp'] = ['status' => 'warning', 'message' => 'SMTP settings not configured in config.php'];
        }
    } catch (Exception $e) {
        $checks['smtp'] = ['status' => 'fail', 'message' => 'SMTP check error: ' . $e->getMessage()];
    }

    // Uptime SLO evidence check
    try {
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

        $targetPercent = (float)getSetting('availability_target_percent', defined('UPTIME_SLO_TARGET_PERCENT') ? UPTIME_SLO_TARGET_PERCENT : 99.0);
        if ($targetPercent <= 0 || $targetPercent > 100) {
            $targetPercent = 99.0;
        }
        $windowDays = (int)getSetting('availability_window_days', 30);
        if ($windowDays <= 0) {
            $windowDays = 30;
        }
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total_checks, COALESCE(SUM(is_up), 0) AS up_checks, MAX(checked_at) AS last_checked_at
            FROM system_uptime_checks
            WHERE checked_at >= :window_start
        ");
        $stmt->execute(['window_start' => $windowStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($row['total_checks'] ?? 0);
        $up = (int)($row['up_checks'] ?? 0);
        $availability = $total > 0 ? round(($up / $total) * 100, 3) : null;
        $lastCheck = (string)($row['last_checked_at'] ?? '');

        if ($total < 20) {
            $checks['uptime_slo'] = ['status' => 'warning', 'message' => 'Uptime evidence insufficient: ' . $total . ' probes in window.'];
        } elseif ($availability !== null && $availability >= $targetPercent) {
            $ageWarn = '';
            if ($lastCheck !== '' && (time() - strtotime($lastCheck)) > 3600) {
                $ageWarn = ' Last probe older than 60 minutes.';
            }
            $checks['uptime_slo'] = [
                'status' => $ageWarn === '' ? 'pass' : 'warning',
                'message' => 'Availability ' . number_format((float)$availability, 3) . '% (target ' . number_format((float)$targetPercent, 2) . '%).' . $ageWarn
            ];
        } else {
            $checks['uptime_slo'] = ['status' => 'fail', 'message' => 'Availability ' . number_format((float)($availability ?? 0), 3) . '% below target ' . number_format((float)$targetPercent, 2) . '%.'];
        }
    } catch (Exception $e) {
        $checks['uptime_slo'] = ['status' => 'fail', 'message' => 'Uptime SLO check failed: ' . $e->getMessage()];
    }

    // Restore drill recency check
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS system_restore_drills (
            id INT PRIMARY KEY AUTO_INCREMENT,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            backup_file VARCHAR(255) NULL,
            backup_size_bytes BIGINT NULL,
            backup_modified_at DATETIME NULL,
            status ENUM('pass','warning','fail') NOT NULL DEFAULT 'fail',
            duration_seconds DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            details_json MEDIUMTEXT NULL,
            executed_by VARCHAR(100) NULL,
            INDEX idx_executed_at (executed_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $maxAgeDays = (int)getSetting('restore_drill_max_age_days', defined('RESTORE_DRILL_MAX_AGE_DAYS') ? RESTORE_DRILL_MAX_AGE_DAYS : 90);
        if ($maxAgeDays <= 0) {
            $maxAgeDays = 90;
        }
        $latest = $conn->query("SELECT status, executed_at, backup_file FROM system_restore_drills ORDER BY executed_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$latest) {
            $checks['restore_drill'] = ['status' => 'warning', 'message' => 'No restore drill evidence found.'];
        } else {
            $st = strtolower((string)($latest['status'] ?? 'fail'));
            $when = (string)($latest['executed_at'] ?? '');
            $file = (string)($latest['backup_file'] ?? 'n/a');
            $ageDays = $when !== '' ? (int)floor((time() - strtotime($when)) / 86400) : 9999;
            if ($st === 'pass' && $ageDays <= $maxAgeDays) {
                $checks['restore_drill'] = ['status' => 'pass', 'message' => 'Latest restore drill passed on ' . $when . ' (' . $ageDays . ' days ago, backup: ' . $file . ').'];
            } elseif ($st === 'pass') {
                $checks['restore_drill'] = ['status' => 'warning', 'message' => 'Latest restore drill is older than ' . $maxAgeDays . ' days (' . $when . ').'];
            } elseif ($st === 'warning') {
                $checks['restore_drill'] = ['status' => 'warning', 'message' => 'Latest restore drill has warning status (' . $when . ', backup: ' . $file . ').'];
            } else {
                $checks['restore_drill'] = ['status' => 'fail', 'message' => 'Latest restore drill failed on ' . $when . ' (backup: ' . $file . ').'];
            }
        }
    } catch (Exception $e) {
        $checks['restore_drill'] = ['status' => 'fail', 'message' => 'Restore drill check failed: ' . $e->getMessage()];
    }

    return $checks;
}

function monitorDispatchAlerts($checks) {
    $db = new Database();
    $conn = $db->getConnection();
    monitorEnsureAlertTable($conn);

    $issues = monitorCollectIssues($checks);
    if (empty($issues)) {
        $conn->exec("UPDATE system_health_alerts SET resolved_at = NOW() WHERE resolved_at IS NULL");
        return ['sent' => false, 'reason' => 'no_issues'];
    }

    $signatureData = [];
    $failCount = 0;
    $warningCount = 0;
    foreach ($issues as $issue) {
        $signatureData[] = [$issue['check'], $issue['status'], $issue['message']];
        if ($issue['status'] === 'fail') $failCount++;
        if ($issue['status'] === 'warning') $warningCount++;
    }
    $issueHash = hash('sha256', json_encode($signatureData));

    $cooldownMinutes = 30;
    $recentStmt = $conn->prepare("SELECT sent_at FROM system_health_alerts WHERE issue_hash = :hash ORDER BY id DESC LIMIT 1");
    $recentStmt->execute(['hash' => $issueHash]);
    $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);
    if ($recent && !empty($recent['sent_at'])) {
        $lastTs = strtotime($recent['sent_at']);
        if ($lastTs !== false && (time() - $lastTs) < ($cooldownMinutes * 60)) {
            return ['sent' => false, 'reason' => 'cooldown'];
        }
    }

    $emails = [];
    $st = $conn->query("SELECT email FROM users WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email <> ''");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    $emails = array_values(array_unique($emails));

    $subject = APP_NAME . " - System Health Alert ({$failCount} failed, {$warningCount} warning)";
    $lines = [];
    $lines[] = "Automated system health monitor detected warning/fail states.";
    $lines[] = "Time: " . date('Y-m-d H:i:s');
    $lines[] = "Fail count: {$failCount}";
    $lines[] = "Warning count: {$warningCount}";
    $lines[] = "Location: " . BASE_URL . "/views/admin/system/health.php";
    $lines[] = "";
    $lines[] = "Issues:";
    foreach ($issues as $i => $issue) {
        $n = $i + 1;
        $lines[] = "{$n}. [{$issue['status']}] {$issue['label']} ({$issue['area']}) - {$issue['message']}";
    }
    $lines[] = "";
    $lines[] = "Action required: open the System Health page and resolve affected components.";
    $body = implode("\n", $lines);

    $sent = false;
    if (!empty($emails)) {
        $sent = (bool)Helper::sendEmail(
            $emails,
            $subject,
            $body,
            null,
            [
                'context_label' => 'System health alert',
                'source_page' => 'scripts/system_health_monitor.php',
                'notify_admin_on_failure' => false,
            ]
        );
    }

    $insert = $conn->prepare("INSERT INTO system_health_alerts
        (issue_hash, fail_count, warning_count, issues_json, email_sent, recipient_count, sent_at, reported_by_user_id)
        VALUES (:issue_hash, :fail_count, :warning_count, :issues_json, :email_sent, :recipient_count, NOW(), 0)");
    $insert->execute([
        'issue_hash' => $issueHash,
        'fail_count' => $failCount,
        'warning_count' => $warningCount,
        'issues_json' => json_encode($issues),
        'email_sent' => $sent ? 1 : 0,
        'recipient_count' => count($emails),
    ]);

    return ['sent' => $sent, 'reason' => $sent ? 'sent' : 'no_recipients'];
}

try {
    $checks = monitorRunChecks();
    $result = monitorDispatchAlerts($checks);
    if (PHP_SAPI === 'cli') {
        echo "Health monitor completed: " . json_encode($result) . PHP_EOL;
    }
} catch (Exception $e) {
    error_log('System health monitor fatal error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'System health monitor error: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
