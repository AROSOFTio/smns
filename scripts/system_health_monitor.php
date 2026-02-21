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
