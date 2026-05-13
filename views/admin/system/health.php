<?php
/**
 * System Health Check
 * Verifies all system components are working properly
 */
require_once '../../../config.php';

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access using Auth helper (module-specific session keys)
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

// Resolve current admin once for reuse in checks/actions
$currentUser = $auth->getCurrentUser();

// Maintenance actions handler (backup DB, clear cache)
$actionResult = null;
$showLogs = isset($_GET['show_logs']) && $_GET['show_logs'] == 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCSRFToken($token)) {
        $actionResult = ['status' => 'fail', 'message' => 'Invalid CSRF token'];
    } else {
        $action = $_POST['action'];
        switch ($action) {
            case 'backup_db':
                // manual backup (same behavior as scheduled script)
                $backupDir = BackupSecurity::ensureBackupDirectory();
                $timestamp = date('Ymd_His');
                $fileName = 'smns_backup_' . $timestamp . '.sql';
                $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

                $dbHost = DB_HOST; $dbUser = DB_USER; $dbPass = DB_PASS; $dbName = DB_NAME;
                $escapedPath = escapeshellarg($filePath);
                $cmd = "mysqldump --host=" . escapeshellarg($dbHost) . " --user=" . escapeshellarg($dbUser) . " --password=" . escapeshellarg($dbPass) . " " . escapeshellarg($dbName) . " > $escapedPath";
                @exec($cmd, $out, $ret);

                if ($ret === 0 && file_exists($filePath)) {
                    $filePath = BackupSecurity::encryptIfEnabled($filePath);
                    $fileName = basename($filePath);
                    $actionResult = ['status' => 'success', 'message' => 'Database backup created: ' . $fileName, 'path' => $filePath];
                    $backupCreated = true;
                } else {
                    // PHP fallback
                    try {
                        $pdo = (new Database())->getConnection();
                        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                        $dump = "-- SMNS PHP SQL dump\n-- Generated: " . date('c') . "\n\n";
                        foreach ($tables as $table) {
                            $row = $pdo->query("SHOW CREATE TABLE `" . $table . "`")->fetch(PDO::FETCH_ASSOC);
                            $createStmt = $row['Create Table'] ?? $row[1] ?? null;
                            if ($createStmt) {
                                $dump .= $createStmt . ";\n\n";
                            } else {
                                $dump .= "-- WARNING: could not retrieve CREATE TABLE for `{$table}`\n\n";
                            }
                            $rows = $pdo->query("SELECT * FROM `" . $table . "`")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($rows as $r) {
                                $cols = array_map(function($c){ return "`" . str_replace('`','``',$c) . "`"; }, array_keys($r));
                                $vals = array_map(function($v) use ($pdo){
                                    if (is_null($v)) return 'NULL';
                                    return $pdo->quote((string)$v);
                                }, array_values($r));
                                $dump .= "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
                            }
                            $dump .= "\n";
                        }
                        file_put_contents($filePath, $dump);
                        if (file_exists($filePath)) {
                            $filePath = BackupSecurity::encryptIfEnabled($filePath);
                            $fileName = basename($filePath);
                            $actionResult = ['status' => 'success', 'message' => 'Database backup created (PHP fallback): ' . $fileName, 'path' => $filePath];
                            $backupCreated = true;
                        } else {
                            $actionResult = ['status' => 'fail', 'message' => 'Backup failed (mysqldump unavailable and PHP dump write failed)'];
                            $backupCreated = false;
                        }
                    } catch (Throwable $e) {
                        $actionResult = ['status' => 'fail', 'message' => 'Backup error: ' . $e->getMessage()];
                        $backupCreated = false;
                    }
                }

                // Notifications, logging and email for manual backup (security measure)
                try {
                    $logger = new Logger();
                    $currentUser = $auth->getCurrentUser();
                    $actorId = $currentUser['id'] ?? 0;

                    if (!empty($backupCreated)) {
                        // Log activity
                        $logger->log($actorId, 'backup_manual', 'system', 'Manual backup created: ' . basename($filePath));

                        // Email recipients
                        $recips = trim(getSetting('scheduled_backup_recipients', SMTP_FROM_EMAIL));
                        $emails = array_filter(array_map('trim', explode(',', $recips)));
                        $subject = APP_NAME . ' - Manual Backup Created';
                        $body = 'A manual database backup was created by ' . ($currentUser['username'] ?? 'system') . ".\n\nBackup: " . basename($filePath) . "\n\nRegards,\n" . APP_NAME;
                        if (!empty($emails)) { Helper::sendEmail($emails, $subject, $body); }

                        // Create notifications for admins
                        $db = new Database(); $conn = $db->getConnection();
                        $admins = $conn->prepare("SELECT id FROM users WHERE role = 'admin'");
                        $admins->execute();
                        $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                        $link = BASE_URL . '/views/admin/backup/compare.php?file=' . urlencode(basename($filePath));
                        $title = 'Manual backup created';
                        $msg = 'Manual backup created: ' . basename($filePath);
                        while ($a = $admins->fetch(PDO::FETCH_ASSOC)) {
                            try { $noteStmt->execute(['uid' => $a['id'], 'title' => $title, 'msg' => $msg, 'type' => 'success', 'link' => $link]); } catch (Throwable $e) { }
                        }
                    } else {
                        // Log failure and notify
                        $logger->log($actorId, 'backup_manual_failed', 'system', 'Manual backup failed');
                        $recips = trim(getSetting('scheduled_backup_recipients', SMTP_FROM_EMAIL));
                        $emails = array_filter(array_map('trim', explode(',', $recips)));
                        $subject = APP_NAME . ' - Manual Backup FAILED';
                        $body = 'A manual database backup attempt failed. Please check system logs.\n\nRegards,\n' . APP_NAME;
                        if (!empty($emails)) { Helper::sendEmail($emails, $subject, $body); }
                        // notify admins (include action to re-run backup)
                        $db = new Database(); $conn = $db->getConnection();
                        $admins = $conn->prepare("SELECT id FROM users WHERE role = 'admin'");
                        $admins->execute();
                        $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                        $link = 'action:run_backup';
                        $title = 'Manual backup failed';
                        $msg = 'Manual backup failed on ' . date('Y-m-d H:i:s') . '. Click to retry.';
                        while ($a = $admins->fetch(PDO::FETCH_ASSOC)) {
                            try { $noteStmt->execute(['uid' => $a['id'], 'title' => $title, 'msg' => $msg, 'type' => 'error', 'link' => $link]); } catch (Throwable $e) { }
                        }
                    }
                } catch (Throwable $e) {
                    // non-fatal
                }

                break;

            case 'run_scheduled_backup':
                $cronScript = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup_cron.php';
                $ran = false;
                if (is_file($cronScript)) {
                    $phpExec = escapeshellarg(resolvePhpExecBinary(true));
                    $out = [];
                    $retCode = 1;
                    @exec($phpExec . ' ' . escapeshellarg($cronScript) . ' 2>&1', $out, $retCode);
                    $tail = trim(implode(' | ', array_slice((array)$out, -3)));
                    if ($retCode === 0) {
                        $actionResult = ['status' => 'success', 'message' => 'Scheduled backup script executed.' . ($tail !== '' ? ' ' . $tail : '')];
                        $ran = true;
                    }
                }
                if (!$ran) {
                    $actionResult = ['status' => 'warning', 'message' => 'Scheduled backup script could not be executed from this environment. Use Backup Database to run an immediate backup.'];
                }
                break;

            case 'run_uptime_probe':
                $probeScript = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'uptime_monitor.php';
                if (!is_file($probeScript)) {
                    $actionResult = ['status' => 'fail', 'message' => 'Uptime monitor script not found.'];
                    break;
                }
                $phpExec = escapeshellarg(resolvePhpExecBinary(true));
                $out = [];
                $retCode = 1;
                @exec($phpExec . ' ' . escapeshellarg($probeScript) . ' 2>&1', $out, $retCode);
                $tail = trim(implode(' | ', array_slice((array)$out, -3)));
                if ($retCode === 0) {
                    $actionResult = ['status' => 'success', 'message' => 'Uptime probe completed successfully.' . ($tail !== '' ? ' ' . $tail : '')];
                } else {
                    $actionResult = ['status' => 'warning', 'message' => 'Uptime probe reported downtime/failure.' . ($tail !== '' ? ' ' . $tail : '')];
                }
                break;

            case 'run_restore_drill':
                $restoreScript = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'restore_test_drill.php';
                if (!is_file($restoreScript)) {
                    $actionResult = ['status' => 'fail', 'message' => 'Restore drill script not found.'];
                    break;
                }
                $phpExec = escapeshellarg(resolvePhpExecBinary(true));
                $out = [];
                $retCode = 1;
                @exec($phpExec . ' ' . escapeshellarg($restoreScript) . ' 2>&1', $out, $retCode);
                $tail = trim(implode(' | ', array_slice((array)$out, -3)));
                if ($retCode === 0) {
                    $actionResult = ['status' => 'success', 'message' => 'Restore drill passed.' . ($tail !== '' ? ' ' . $tail : '')];
                } else {
                    $actionResult = ['status' => 'fail', 'message' => 'Restore drill failed.' . ($tail !== '' ? ' ' . $tail : '')];
                }
                break;

            case 'run_smtp_probe':
                try {
                    $smtpProbe = runSmtpDiagnostics();
                    $actionResult = [
                        'status' => ($smtpProbe['status'] ?? 'warning') === 'pass'
                            ? 'success'
                            : (($smtpProbe['status'] ?? 'warning') === 'warning' ? 'warning' : 'fail'),
                        'message' => 'SMTP health check completed. ' . trim((string)($smtpProbe['message'] ?? ''))
                    ];
                } catch (Throwable $e) {
                    $actionResult = ['status' => 'fail', 'message' => 'SMTP health check failed: ' . $e->getMessage()];
                }
                break;

            case 'purge_backups':
                $backupDir = BackupSecurity::getBackupDirectory();
                $deleted = 0;
                $retentionDays = (int)getSetting('backup_retention_days', defined('BACKUP_RETENTION_DAYS') ? BACKUP_RETENTION_DAYS : 30);
                $maxFiles = defined('BACKUP_RETENTION_MAX_FILES') ? BACKUP_RETENTION_MAX_FILES : 50;

                if (is_dir($backupDir)) {
                    $files = BackupSecurity::listBackupFiles();
                    // delete by age
                    foreach ($files as $f) {
                        if (filemtime($f) < strtotime("-{$retentionDays} days")) { @unlink($f) && $deleted++; }
                    }
                    // enforce max files
                    usort($files, function($a, $b){ return filemtime($a) - filemtime($b); }); // oldest first
                    while (count($files) > $maxFiles) {
                        $f = array_shift($files);
                        if (is_file($f)) { @unlink($f) && $deleted++; }
                    }
                }
                $actionResult = ['status' => 'success', 'message' => "Purged old backups ({$deleted} files removed)"];
                // Log + notify admins
                try {
                    $logger = new Logger();
                    $currentUser = $auth->getCurrentUser();
                    $logger->log($currentUser['id'] ?? 0, 'purge_backups', 'system', 'Purged backups: ' . $deleted . ' files');

                    $db = new Database(); $c2 = $db->getConnection();
                    $admins = $c2->prepare("SELECT id FROM users WHERE role = 'admin'"); $admins->execute();
                    $noteStmt = $c2->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                    $title = 'Backups purged';
                    $msg = 'Purged ' . $deleted . ' old backup files';
                    while ($a = $admins->fetch(PDO::FETCH_ASSOC)) { try { $noteStmt->execute(['uid' => $a['id'], 'title'=>$title, 'msg'=>$msg, 'type'=>'info', 'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Throwable $e) {} }
                } catch (Throwable $e) {}
                break;

            case 'purge_logs':
                try {
                    $days = (int)getSetting('log_retention_days', defined('LOG_RETENTION_DAYS') ? LOG_RETENTION_DAYS : 90);
                    $pdo = (new Database())->getConnection();
                    $currentUser = $auth->getCurrentUser();
                    $currentUserId = (int)($currentUser['id'] ?? 0);
                    if (!FeeStructureGovernance::isSuperAdmin($pdo, $currentUserId)) {
                        $actionResult = ['status' => 'fail', 'message' => 'Only super-admin can purge activity logs.'];
                        break;
                    }
                    $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
                    $stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
                    $stmt->execute();
                    $deleted = $stmt->rowCount();
                    $actionResult = ['status' => 'success', 'message' => "Purged {$deleted} activity log entries older than {$days} days"];

                    // Log + notify admins
                    try {
                        $logger = new Logger();
                        $currentUser = $auth->getCurrentUser();
                        $logger->log($currentUser['id'] ?? 0, 'purge_logs', 'system', 'Purged activity logs: ' . $deleted . ' entries');

                        $db2 = new Database(); $c2 = $db2->getConnection();
                        $admins = $c2->prepare("SELECT id FROM users WHERE role = 'admin'"); $admins->execute();
                        $noteStmt = $c2->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                        $title = 'Activity logs purged';
                        $msg = 'Purged ' . $deleted . ' activity log entries';
                        while ($a = $admins->fetch(PDO::FETCH_ASSOC)) { try { $noteStmt->execute(['uid' => $a['id'], 'title'=>$title, 'msg'=>$msg, 'type'=>'info', 'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Throwable $e) {} }
                    } catch (Throwable $e) {}

                } catch (Throwable $e) {
                    $actionResult = ['status' => 'fail', 'message' => 'Log purge failed: ' . $e->getMessage()];
                }
                break;

            case 'clear_cache':
                $cacheDir = BASE_PATH . DIRECTORY_SEPARATOR . 'cache';
                $removed = 0;
                if (is_dir($cacheDir)) {
                    $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*');
                    foreach ($files as $f) {
                        if (is_file($f) && basename($f) !== '.gitkeep') {
                            @unlink($f) && $removed++;
                        }
                        if (is_dir($f)) {
                            $inner = glob($f . DIRECTORY_SEPARATOR . '*');
                            if (empty($inner)) { @rmdir($f); }
                        }
                    }
                }
                $actionResult = ['status' => 'success', 'message' => "Cache cleared ({$removed} files removed)"];
                // Log + notify admins
                try {
                    $logger = new Logger();
                    $currentUser = $auth->getCurrentUser();
                    $logger->log($currentUser['id'] ?? 0, 'clear_cache', 'system', 'Cleared cache: ' . $removed . ' files');

                    $db2 = new Database(); $c2 = $db2->getConnection();
                    $admins = $c2->prepare("SELECT id FROM users WHERE role = 'admin'"); $admins->execute();
                    $noteStmt = $c2->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                    $title = 'Cache cleared';
                    $msg = 'Cleared ' . $removed . ' cache files';
                    while ($a = $admins->fetch(PDO::FETCH_ASSOC)) { try { $noteStmt->execute(['uid' => $a['id'], 'title'=>$title, 'msg'=>$msg, 'type'=>'info', 'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Throwable $e) {} }
                } catch (Throwable $e) {}

                break;

            default:
                $actionResult = ['status' => 'fail', 'message' => 'Unknown action'];
        }
    }
}

/**
 * Human-friendly labels and areas for health checks.
 */
function getHealthCheckMetadata($checkName) {
    $map = [
        'database' => ['label' => 'Database Connection', 'area' => 'Core/Database'],
        'auth' => ['label' => 'Authentication', 'area' => 'Core/Auth'],
        'session' => ['label' => 'Session Management', 'area' => 'Core/Session'],
        'security' => ['label' => 'Security', 'area' => 'Core/Security'],
        'helper' => ['label' => 'Helper Functions', 'area' => 'Core/Helper'],
        'logger' => ['label' => 'Logger', 'area' => 'Core/Logger'],
        'classes' => ['label' => 'Core Class Loading', 'area' => 'Core'],
        'permissions' => ['label' => 'Directory Permissions', 'area' => 'Filesystem'],
        'constants' => ['label' => 'System Constants', 'area' => 'Configuration'],
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

function getHealthStatusSeverity($status) {
    $status = strtolower((string)$status);
    if ($status === 'fail') {
        return 3;
    }
    if ($status === 'warning') {
        return 2;
    }
    return 1;
}

function resolveHealthAggregateStatus($statuses) {
    $highest = 1;
    foreach ((array)$statuses as $status) {
        $highest = max($highest, getHealthStatusSeverity($status));
    }
    if ($highest >= 3) {
        return 'fail';
    }
    if ($highest >= 2) {
        return 'warning';
    }
    return 'pass';
}

function runSmtpDiagnostics() {
    $emailConfig = class_exists('Helper')
        ? Helper::getEmailConfiguration()
        : [
            'smtp_host' => trim((string)(defined('SMTP_HOST') ? SMTP_HOST : '')),
            'smtp_port' => (int)(defined('SMTP_PORT') ? SMTP_PORT : 0),
            'transport' => trim((string)(defined('EMAIL_TRANSPORT') ? EMAIL_TRANSPORT : 'php_mail')),
            'smtp_secure' => defined('SMTP_SECURE') ? (bool)SMTP_SECURE : false,
        ];

    $smtpHost = trim((string)($emailConfig['smtp_host'] ?? ''));
    $smtpPort = (int)($emailConfig['smtp_port'] ?? 0);
    $transport = trim((string)($emailConfig['transport'] ?? 'php_mail'));
    $secure = !empty($emailConfig['smtp_secure']) ? 'SSL/TLS' : 'STARTTLS / opportunistic TLS';

    $details = [];
    $summaryParts = [];

    $configStatus = 'pass';
    if ($smtpHost === '' || $smtpPort < 1 || $smtpPort > 65535) {
        $configStatus = 'warning';
        $summaryParts[] = 'SMTP settings are incomplete.';
    } else {
        $summaryParts[] = "Configured host {$smtpHost} on port {$smtpPort}.";
    }
    $details[] = [
        'label' => 'Configuration',
        'status' => $configStatus,
        'message' => 'Transport: ' . ($transport !== '' ? $transport : 'not set')
            . ' | Host: ' . ($smtpHost !== '' ? $smtpHost : 'not set')
            . ' | Port: ' . ($smtpPort > 0 ? $smtpPort : 'not set')
            . ' | Security: ' . $secure
    ];

    $dnsStatus = 'warning';
    $dnsAddresses = [];
    $dnsNotes = [];
    if ($smtpHost === '') {
        $dnsStatus = 'warning';
        $dnsNotes[] = 'SMTP host is not configured.';
    } else {
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($smtpHost, DNS_A + DNS_AAAA);
            if (is_array($records) && !empty($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $dnsAddresses[] = $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $dnsAddresses[] = $record['ipv6'];
                    }
                }
            }
        }

        if (empty($dnsAddresses)) {
            $fallbackAddress = @gethostbyname($smtpHost);
            if ($fallbackAddress !== '' && $fallbackAddress !== $smtpHost) {
                $dnsAddresses[] = $fallbackAddress;
                $dnsNotes[] = 'Resolved using gethostbyname() fallback.';
            }
        }

        $dnsAddresses = array_values(array_unique(array_filter(array_map('trim', $dnsAddresses))));
        if (!empty($dnsAddresses)) {
            $dnsStatus = 'pass';
            $dnsNotes[] = 'Resolved address(es): ' . implode(', ', $dnsAddresses);
        } else {
            $dnsStatus = 'fail';
            $dnsNotes[] = 'DNS could not resolve the configured SMTP host.';
        }
    }
    $details[] = [
        'label' => 'DNS Resolution',
        'status' => $dnsStatus,
        'message' => implode(' ', $dnsNotes)
    ];

    $reachabilityStatus = 'warning';
    $reachabilityMessage = 'SMTP reachability not tested.';
    if ($smtpHost === '' || $smtpPort < 1 || $smtpPort > 65535) {
        $reachabilityStatus = 'warning';
        $reachabilityMessage = 'SMTP host/port must be configured before port reachability can be tested.';
    } else {
        $connectHost = !empty($dnsAddresses) ? (string)$dnsAddresses[0] : $smtpHost;
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($connectHost, $smtpPort, $errno, $errstr, 4);
        $elapsedMs = (int)round((microtime(true) - $start) * 1000);
        if ($socket) {
            fclose($socket);
            $reachabilityStatus = 'pass';
            $reachabilityMessage = 'TCP connection succeeded to ' . $connectHost . ':' . $smtpPort . ' in ' . $elapsedMs . ' ms.';
            if ($connectHost !== $smtpHost) {
                $reachabilityMessage .= ' Original host: ' . $smtpHost . '.';
            }
        } else {
            $reachabilityStatus = !empty($dnsAddresses) ? 'fail' : 'warning';
            $reachabilityMessage = 'TCP connection failed to ' . $connectHost . ':' . $smtpPort;
            if ($errno || $errstr !== '') {
                $reachabilityMessage .= ' (' . $errno . ') ' . $errstr;
            }
            $reachabilityMessage .= '.';
            if (!empty($dnsAddresses)) {
                $reachabilityMessage .= ' DNS resolved, so this usually points to firewall, antivirus, ISP, or SMTP egress blocking.';
            }
        }
    }
    $details[] = [
        'label' => 'Port Reachability',
        'status' => $reachabilityStatus,
        'message' => $reachabilityMessage
    ];

    $overallStatus = resolveHealthAggregateStatus([$configStatus, $dnsStatus, $reachabilityStatus]);
    if ($overallStatus === 'pass') {
        $summaryParts[] = 'DNS resolution and SMTP port reachability are both working.';
    } elseif ($dnsStatus === 'fail') {
        $summaryParts[] = 'DNS resolution failed for the SMTP host.';
    } elseif ($reachabilityStatus === 'fail') {
        $summaryParts[] = 'SMTP host resolves, but the configured port is not reachable.';
    } else {
        $summaryParts[] = 'SMTP diagnostics need attention.';
    }

    return [
        'status' => $overallStatus,
        'message' => implode(' ', $summaryParts),
        'details' => $details,
        'meta' => [
            'checked_at' => date('Y-m-d H:i:s'),
            'host' => $smtpHost,
            'port' => $smtpPort,
            'transport' => $transport,
            'secure' => $secure,
        ],
    ];
}

/**
 * Extract warning/fail issues with location context.
 */
function collectHealthIssuesWithLocation($checks) {
    $issues = [];
    foreach ($checks as $checkName => $check) {
        $status = strtolower((string)($check['status'] ?? ''));
        if ($status !== 'warning' && $status !== 'fail') {
            continue;
        }
        $meta = getHealthCheckMetadata((string)$checkName);
        $issues[] = [
            'check' => (string)$checkName,
            'label' => $meta['label'],
            'area' => $meta['area'],
            'status' => $status,
            'message' => (string)($check['message'] ?? ''),
        ];
    }
    return $issues;
}

function ensureSystemHealthAlertTable($conn) {
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

function sendSystemHealthAlertIfNeeded($checks, $currentUser) {
    try {
        $issues = collectHealthIssuesWithLocation($checks);
        $failCount = 0;
        $warningCount = 0;
        foreach ($issues as $issue) {
            if ($issue['status'] === 'fail') $failCount++;
            if ($issue['status'] === 'warning') $warningCount++;
        }

        $db = new Database();
        $conn = $db->getConnection();
        ensureSystemHealthAlertTable($conn);

        // If issues are resolved, mark previous unresolved alerts as resolved.
        if (empty($issues)) {
            $conn->exec("UPDATE system_health_alerts SET resolved_at = NOW() WHERE resolved_at IS NULL");
            return;
        }

        $signatureData = [];
        foreach ($issues as $issue) {
            $signatureData[] = [$issue['check'], $issue['status'], $issue['message']];
        }
        $signatureJson = json_encode($signatureData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($signatureJson)) {
            $signatureJson = (string)json_encode(['fallback' => time()], JSON_UNESCAPED_UNICODE);
        }
        $issueHash = hash('sha256', $signatureJson);

        // Anti-spam cooldown for unchanged issue set.
        $cooldownMinutes = 30;
        $recentStmt = $conn->prepare("SELECT sent_at FROM system_health_alerts WHERE issue_hash = :hash ORDER BY id DESC LIMIT 1");
        $recentStmt->execute(['hash' => $issueHash]);
        $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);
        if ($recent && !empty($recent['sent_at'])) {
            $lastTs = strtotime($recent['sent_at']);
            if ($lastTs !== false && (time() - $lastTs) < ($cooldownMinutes * 60)) {
                return;
            }
        }

        $adminStmt = $conn->query("
            SELECT email
            FROM users
            WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email <> ''
        ");
        $emails = [];
        while ($row = $adminStmt->fetch(PDO::FETCH_ASSOC)) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        $emails = array_values(array_unique($emails));

        $subject = APP_NAME . " - System Health Alert ({$failCount} failed, {$warningCount} warning)";
        $lines = [];
        $lines[] = "System health check detected warning/fail states.";
        $lines[] = "Time: " . date('Y-m-d H:i:s');
        $lines[] = "Fail count: {$failCount}";
        $lines[] = "Warning count: {$warningCount}";
        $lines[] = "Reported by: " . (($currentUser['username'] ?? 'system'));
        $lines[] = "Location: " . BASE_URL . "/views/admin/system/health.php";
        $lines[] = "";
        $lines[] = "Issues:";
        foreach ($issues as $i => $issue) {
            $idx = $i + 1;
            $lines[] = "{$idx}. [{$issue['status']}] {$issue['label']} ({$issue['area']}) - {$issue['message']}";
        }
        $lines[] = "";
        $lines[] = "Action required: review System Health page and run maintenance actions where needed.";
        $body = implode("\n", $lines);

        $emailSent = false;
        if (!empty($emails)) {
            $emailSent = (bool)Helper::sendEmail(
                $emails,
                $subject,
                $body,
                null,
                [
                    'context_label' => 'System health alert',
                    'source_page' => 'views/admin/system/health.php',
                    'notify_admin_on_failure' => false
                ]
            );
        }

        $insert = $conn->prepare("INSERT INTO system_health_alerts
            (issue_hash, fail_count, warning_count, issues_json, email_sent, recipient_count, sent_at, reported_by_user_id)
            VALUES (:issue_hash, :fail_count, :warning_count, :issues_json, :email_sent, :recipient_count, NOW(), :reported_by_user_id)");
        $insert->execute([
            'issue_hash' => $issueHash,
            'fail_count' => $failCount,
            'warning_count' => $warningCount,
            'issues_json' => json_encode($issues),
            'email_sent' => $emailSent ? 1 : 0,
            'recipient_count' => count($emails),
            'reported_by_user_id' => (int)($currentUser['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        error_log('System health alert dispatch error: ' . $e->getMessage());
    }
}

$checks = [];

// 1. Database Connection Test
try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->query("SELECT 1");
    $checks['database'] = ['status' => 'pass', 'message' => 'Database connection successful'];
} catch (Throwable $e) {
    $checks['database'] = ['status' => 'fail', 'message' => 'Database connection failed: ' . $e->getMessage()];
}

// 2. User Authentication Test
try {
    $testAuth = new Auth();
    $checks['auth'] = ['status' => 'pass', 'message' => 'Authentication system loaded'];
} catch (Throwable $e) {
    $checks['auth'] = ['status' => 'fail', 'message' => 'Auth system error: ' . $e->getMessage()];
}

// 3. Session Management Test
try {
    $testSession = new Session();
    $checks['session'] = ['status' => 'pass', 'message' => 'Session management working'];
} catch (Throwable $e) {
    $checks['session'] = ['status' => 'fail', 'message' => 'Session error: ' . $e->getMessage()];
}

// 4. Security System Test (verify CSRF helpers)
try {
    if (method_exists('Security', 'generateCSRFToken') && method_exists('Security', 'verifyCSRFToken')) {
        $token = Security::generateCSRFToken();
        $valid = Security::verifyCSRFToken($token);
        if ($valid) {
            $checks['security'] = ['status' => 'pass', 'message' => 'Security system operational (CSRF token generation/verification OK)'];
        } else {
            $checks['security'] = ['status' => 'warning', 'message' => 'CSRF token generation succeeded but verification failed'];
        }
    } else {
        $checks['security'] = ['status' => 'fail', 'message' => 'Security class missing CSRF methods'];
    }
} catch (Throwable $e) {
    $checks['security'] = ['status' => 'fail', 'message' => 'Security system error: ' . $e->getMessage()];
}

// 5. Helper Functions Test
try {
    $currentSem = Helper::getCurrentSemester();
    if ($currentSem) {
        $checks['helper'] = ['status' => 'pass', 'message' => 'Helper functions working (current semester: ' . ($currentSem['semester_name'] ?? 'N/A') . ')'];
    } else {
        $checks['helper'] = ['status' => 'warning', 'message' => 'Helper loaded but current semester could not be determined'];
    }
} catch (Throwable $e) {
    $checks['helper'] = ['status' => 'fail', 'message' => 'Helper error: ' . $e->getMessage()];
} 

// 6. Logger Test (with actual write test)
try {
    $logger = new Logger();
    // Attempt to get recent activities to verify the table exists and is readable
    $activities = $logger->getRecentActivities(1);
    
        // Determine a user id to exercise write (prefer module-specific admin session key)
        $testUserId = $currentUser['id']
            ?? $_SESSION['admin_user_id']
            ?? $_SESSION['admin_id']
            ?? $_SESSION['user_id']
            ?? null;
        if ($testUserId) {
            $logResult = $logger->log($testUserId, 'health_check', 'system', 'System health check performed');
            if ($logResult) {
                $checks['logger'] = ['status' => 'pass', 'message' => 'Logging system operational - read/write OK'];
            } else {
                $checks['logger'] = ['status' => 'warning', 'message' => 'Logger loaded but write failed (check activity_logs table/permissions)'];
            }
        } else {
            $checks['logger'] = ['status' => 'warning', 'message' => 'Logger loaded but no user ID available for write test'];
        }
    } catch (Throwable $e) {
        $checks['logger'] = ['status' => 'fail', 'message' => 'Logger error: ' . $e->getMessage()];
    }
$coreClasses = ['Database', 'Auth', 'Session', 'Security', 'Helper', 'Logger', 'Validator'];
$missingClasses = [];
foreach ($coreClasses as $class) {
    if (!class_exists($class)) {
        $missingClasses[] = $class;
    }
}

if (empty($missingClasses)) {
    $checks['classes'] = ['status' => 'pass', 'message' => 'All core classes loaded'];
} else {
    $checks['classes'] = ['status' => 'fail', 'message' => 'Missing classes: ' . implode(', ', $missingClasses)];
}

// 8. File Permissions Test (use absolute paths and attempt auto-fix when possible)
$criticalDirs = [
    'logs' => BASE_PATH . '/logs',
    'uploads' => BASE_PATH . '/uploads', 
    'cache' => BASE_PATH . '/cache',
    'downloads' => BASE_PATH . '/downloads'
];

$permissionIssues = [];
foreach ($criticalDirs as $name => $dirPath) {
    // Ensure directory exists
    if (!is_dir($dirPath)) {
        $created = @mkdir($dirPath, 0777, true);
        if ($created) {
            $permissionIssues[] = "$name directory was missing and was created";
        } else {
            $permissionIssues[] = "$name directory missing (failed to create: $dirPath)";
            continue;
        }
    }

    // Ensure writable
    if (!is_writable($dirPath)) {
        @chmod($dirPath, 0777);
        if (!is_writable($dirPath)) {
            $permissionIssues[] = "$name directory not writable: $dirPath";
        }
    }
}

if (empty($permissionIssues)) {
    $checks['permissions'] = ['status' => 'pass', 'message' => 'Directory permissions OK'];
} else {
    // If all are only 'created' messages, treat as pass with notes
    $createdOnly = true;
    foreach ($permissionIssues as $pi) {
        if (strpos($pi, 'created') === false) { $createdOnly = false; break; }
    }
    if ($createdOnly) {
        $checks['permissions'] = ['status' => 'pass', 'message' => 'Missing directories were auto-created: ' . implode('; ', $permissionIssues)];
    } else {
        $checks['permissions'] = ['status' => 'warning', 'message' => 'Issues: ' . implode('; ', $permissionIssues)];
    }
} 

// 9. System Constants Test
$requiredConstants = ['BASE_PATH', 'BASE_URL', 'APP_NAME', 'DB_HOST', 'DB_NAME'];
$missingConstants = [];
foreach ($requiredConstants as $const) {
    if (!defined($const)) {
        $missingConstants[] = $const;
    }
}

if (empty($missingConstants)) {
    $checks['constants'] = ['status' => 'pass', 'message' => 'All required constants defined'];
} else {
    $checks['constants'] = ['status' => 'fail', 'message' => 'Missing constants: ' . implode(', ', $missingConstants)];
}

// 10. SMTP diagnostics
try {
    $checks['smtp'] = runSmtpDiagnostics();
} catch (Throwable $e) {
    $checks['smtp'] = ['status' => 'fail', 'message' => 'SMTP check error: ' . $e->getMessage()];
}

$smtpDiagnostics = $checks['smtp'] ?? ['status' => 'warning', 'message' => 'SMTP diagnostics unavailable.', 'details' => []];

// 11. Module page coverage checks (all major modules)
$modulePageChecks = [
    'admin_pages' => ['label' => 'Admin', 'paths' => ['views/admin/login.php', 'views/admin/dashboard.php', 'views/admin/logout.php']],
    'student_pages' => ['label' => 'Student', 'paths' => ['views/student/login.php', 'views/student/dashboard.php', 'views/student/logout.php']],
    'lecturer_pages' => ['label' => 'Lecturer', 'paths' => ['views/lecturer/login.php', 'views/lecturer/dashboard.php', 'views/lecturer/logout.php']],
    'finance_pages' => ['label' => 'Finance', 'paths' => ['views/finance/login.php', 'views/finance/dashboard.php', 'views/finance/logout.php']],
];
foreach ($modulePageChecks as $checkKey => $cfg) {
    $missing = [];
    foreach ($cfg['paths'] as $relPath) {
        $fullPath = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        if (!is_file($fullPath)) {
            $missing[] = $relPath;
        }
    }
    if (empty($missing)) {
        $checks[$checkKey] = ['status' => 'pass', 'message' => $cfg['label'] . ' module routes/pages present'];
    } else {
        $checks[$checkKey] = ['status' => 'fail', 'message' => $cfg['label'] . ' module missing files: ' . implode(', ', $missing)];
    }
}

// 12. Module data checks (tables used by each module)
try {
    $dbForModules = new Database();
    $connForModules = $dbForModules->getConnection();

    $moduleDataChecks = [
        'admin_data' => ['label' => 'Admin', 'tables' => ['users', 'admins', 'notifications']],
        'student_data' => ['label' => 'Student', 'tables' => ['students', 'course_registrations', 'results']],
        'lecturer_data' => ['label' => 'Lecturer', 'tables' => ['lecturers', 'course_assignments', 'results']],
        'finance_data' => ['label' => 'Finance', 'tables' => ['finance_staff', 'invoices', 'payments']],
    ];

    foreach ($moduleDataChecks as $checkKey => $cfg) {
        $missingTables = [];
        foreach ($cfg['tables'] as $table) {
            $st = $connForModules->prepare("
                SELECT COUNT(*) AS c
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = :t
            ");
            $st->execute(['t' => $table]);
            $exists = (int)$st->fetchColumn();
            if ($exists < 1) {
                $missingTables[] = $table;
            }
        }

        if (empty($missingTables)) {
            $checks[$checkKey] = ['status' => 'pass', 'message' => $cfg['label'] . ' module data tables available'];
        } else {
            $checks[$checkKey] = ['status' => 'fail', 'message' => $cfg['label'] . ' module missing tables: ' . implode(', ', $missingTables)];
        }
    }
} catch (Throwable $e) {
    $checks['admin_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
    $checks['student_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
    $checks['lecturer_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
    $checks['finance_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
}

// 13. Availability SLO check (based on uptime monitor evidence).
try {
    $dbReliability = new Database();
    $connReliability = $dbReliability->getConnection();
    $connReliability->exec("CREATE TABLE IF NOT EXISTS system_uptime_checks (
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

    $uptimeAggStmt = $connReliability->prepare("
        SELECT
            COUNT(*) AS total_checks,
            COALESCE(SUM(is_up), 0) AS up_checks,
            MAX(checked_at) AS last_checked_at
        FROM system_uptime_checks
        WHERE checked_at >= :window_start
    ");
    $uptimeAggStmt->execute(['window_start' => $windowStart]);
    $uptimeAgg = $uptimeAggStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalChecks = (int)($uptimeAgg['total_checks'] ?? 0);
    $upChecks = (int)($uptimeAgg['up_checks'] ?? 0);
    $availability = $totalChecks > 0 ? round(($upChecks / $totalChecks) * 100, 3) : null;
    $lastCheckedAt = (string)($uptimeAgg['last_checked_at'] ?? '');

    if ($totalChecks < 20) {
        $checks['uptime_slo'] = [
            'status' => 'warning',
            'message' => 'Not enough uptime samples for SLO evaluation. Samples: ' . $totalChecks . ' (minimum 20).'
        ];
    } else {
        $freshnessWarning = '';
        if ($lastCheckedAt !== '') {
            $ageMinutes = (int)floor((time() - strtotime($lastCheckedAt)) / 60);
            if ($ageMinutes > 60) {
                $freshnessWarning = ' Last probe is stale (' . $ageMinutes . ' minutes old).';
            }
        }
        if ($availability !== null && $availability >= $targetPercent) {
            $checks['uptime_slo'] = [
                'status' => $freshnessWarning === '' ? 'pass' : 'warning',
                'message' => 'Availability ' . number_format((float)$availability, 3) . '% over last ' . $windowDays . ' days (target ' . number_format((float)$targetPercent, 2) . '%).' . $freshnessWarning
            ];
        } else {
            $checks['uptime_slo'] = [
                'status' => 'fail',
                'message' => 'Availability ' . number_format((float)($availability ?? 0), 3) . '% over last ' . $windowDays . ' days is below target ' . number_format((float)$targetPercent, 2) . '%.'
            ];
        }
    }
} catch (Throwable $e) {
    $checks['uptime_slo'] = ['status' => 'fail', 'message' => 'Uptime SLO check failed: ' . $e->getMessage()];
}

// 14. Restore drill evidence recency.
try {
    $dbRestore = isset($dbReliability) && $dbReliability instanceof Database ? $dbReliability : new Database();
    $connRestore = isset($connReliability) && $connReliability instanceof PDO ? $connReliability : $dbRestore->getConnection();
    $connRestore->exec("CREATE TABLE IF NOT EXISTS system_restore_drills (
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

    $latestDrillStmt = $connRestore->query("SELECT * FROM system_restore_drills ORDER BY executed_at DESC, id DESC LIMIT 1");
    $latestDrill = $latestDrillStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $maxAgeDays = (int)getSetting('restore_drill_max_age_days', defined('RESTORE_DRILL_MAX_AGE_DAYS') ? RESTORE_DRILL_MAX_AGE_DAYS : 90);
    if ($maxAgeDays <= 0) {
        $maxAgeDays = 90;
    }

    if (!$latestDrill) {
        $checks['restore_drill'] = [
            'status' => 'warning',
            'message' => 'No restore drill evidence found. Run restore drill and record evidence.'
        ];
    } else {
        $executedAt = (string)($latestDrill['executed_at'] ?? '');
        $status = strtolower((string)($latestDrill['status'] ?? 'fail'));
        $backupFile = (string)($latestDrill['backup_file'] ?? 'n/a');
        $ageDays = $executedAt !== '' ? (int)floor((time() - strtotime($executedAt)) / 86400) : 9999;
        $isStale = $ageDays > $maxAgeDays;

        if ($status === 'pass' && !$isStale) {
            $checks['restore_drill'] = [
                'status' => 'pass',
                'message' => 'Latest restore drill passed on ' . $executedAt . ' using ' . $backupFile . ' (' . $ageDays . ' days ago).'
            ];
        } elseif ($status === 'pass' && $isStale) {
            $checks['restore_drill'] = [
                'status' => 'warning',
                'message' => 'Latest restore drill passed on ' . $executedAt . ' but is older than ' . $maxAgeDays . ' days.'
            ];
        } elseif ($status === 'warning') {
            $checks['restore_drill'] = [
                'status' => 'warning',
                'message' => 'Latest restore drill has warning status (' . $executedAt . ', backup: ' . $backupFile . ').'
            ];
        } else {
            $checks['restore_drill'] = [
                'status' => 'fail',
                'message' => 'Latest restore drill failed on ' . $executedAt . ' (backup: ' . $backupFile . ').'
            ];
        }
    }
} catch (Throwable $e) {
    $checks['restore_drill'] = ['status' => 'fail', 'message' => 'Restore drill check failed: ' . $e->getMessage()];
}

// Avoid synchronous email dispatch on page render; it can cause 500s under SMTP timeout/failure.
try {
    $sendAlertsOnLoad = (int)getSetting('system_health_email_alerts_on_page_load', 0) === 1;
    if ($sendAlertsOnLoad) {
        sendSystemHealthAlertIfNeeded($checks, $currentUser ?? []);
    }
} catch (Throwable $e) {
    error_log('System health alert gate error: ' . $e->getMessage());
}

$pageTitle = 'System Health Check - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content system-health-page">
    <div class="topbar">
        <div class="topbar-left">
            <h4>
                <a href="../dashboard.php" class="btn btn-link">&larr; Back to Dashboard</a>
                System Health Check
            </h4>
        </div>
        <div class="topbar-right">
            <small class="text-muted">Last checked: <?php echo date('M j, Y g:i A'); ?></small>
        </div>
    </div>
    
    <div class="content-area">
        <div class="container-fluid">
            
            <!-- System Overview -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-heartbeat"></i> System Status Overview
                            </h5>
                        </div>
                        <div class="card-body system-overview-body">
                            <?php
                            $passCount = count(array_filter($checks, function($check) { return $check['status'] === 'pass'; }));
                            $totalChecks = max(1, count($checks));
                            $healthPercentage = round(($passCount / $totalChecks) * 100);
                            ?>
                            
                            <div class="row system-overview-layout">
                                <div class="col-md-3 text-center system-health-score-col">
                                    <div class="health-score">
                                        <div class="score-circle <?php echo $healthPercentage >= 90 ? 'excellent' : ($healthPercentage >= 70 ? 'good' : 'poor'); ?>">
                                            <?php echo $healthPercentage; ?>%
                                        </div>
                                        <p>System Health</p>
                                    </div>
                                </div>
                                <div class="col-md-9 system-health-info-col">
                                    <div class="health-summary">
                                        <div class="summary-item">
                                            <span class="badge badge-success"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'pass'; })); ?></span>
                                            <span>Passing Checks</span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="badge badge-warning"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'warning'; })); ?></span>
                                            <span>Warnings</span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="badge badge-danger"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'fail'; })); ?></span>
                                            <span>Failed Checks</span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($healthPercentage >= 90): ?>
                                        <div class="alert alert-success mt-3">
                                            <i class="fas fa-check-circle"></i>
                                            <strong>Excellent!</strong> Your system is running optimally.
                                        </div>
                                    <?php elseif ($healthPercentage >= 70): ?>
                                        <div class="alert alert-warning mt-3">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>Good</strong> System is functional but some improvements recommended.
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-danger mt-3">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <strong>Attention Required!</strong> Critical issues detected that need immediate attention.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Detailed Health Checks -->
            <div class="row mt-4" id="smtp-diagnostics">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap:12px;">
                                <h5 class="mb-0">
                                    <i class="fas fa-envelope-open-text"></i> SMTP Diagnostics
                                </h5>
                                <form method="post" action="<?php echo e(BASE_URL . '/views/admin/system/health.php#smtp-diagnostics'); ?>" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="run_smtp_probe">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-stethoscope"></i> Run SMTP Health Check
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="smtp-diagnostic-summary smtp-<?php echo e($smtpDiagnostics['status'] ?? 'warning'); ?>">
                                <div>
                                    <strong>Status:</strong>
                                    <span class="badge badge-<?php echo ($smtpDiagnostics['status'] ?? 'warning') === 'pass' ? 'success' : (($smtpDiagnostics['status'] ?? 'warning') === 'warning' ? 'warning' : 'danger'); ?>">
                                        <?php echo strtoupper((string)($smtpDiagnostics['status'] ?? 'warning')); ?>
                                    </span>
                                </div>
                                <?php if (!empty($smtpDiagnostics['meta']['checked_at'])): ?>
                                    <div class="mt-2 text-muted">
                                        Last checked: <?php echo e((string)$smtpDiagnostics['meta']['checked_at']); ?>
                                    </div>
                                <?php endif; ?>
                                <p class="mb-0 mt-2"><?php echo e($smtpDiagnostics['message'] ?? ''); ?></p>
                            </div>

                            <div class="row mt-3">
                                <?php foreach (($smtpDiagnostics['details'] ?? []) as $detail): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="smtp-detail-card smtp-<?php echo e($detail['status'] ?? 'warning'); ?>">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0"><?php echo e($detail['label'] ?? 'Check'); ?></h6>
                                                <span class="badge badge-<?php echo ($detail['status'] ?? 'warning') === 'pass' ? 'success' : (($detail['status'] ?? 'warning') === 'warning' ? 'warning' : 'danger'); ?>">
                                                    <?php echo strtoupper((string)($detail['status'] ?? 'warning')); ?>
                                                </span>
                                            </div>
                                            <p class="mb-0 text-secondary"><?php echo e($detail['message'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list-check"></i> Detailed System Checks
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="health-checks">
                                <?php foreach ($checks as $checkName => $check): ?>
                                    <div class="health-check-item">
                                        <div class="check-status">
                                            <?php if ($check['status'] === 'pass'): ?>
                                                <i class="fas fa-check-circle text-success"></i>
                                            <?php elseif ($check['status'] === 'warning'): ?>
                                                <i class="fas fa-exclamation-triangle text-warning"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times-circle text-danger"></i>
                                            <?php endif; ?>
                                        </div>
                                         <div class="check-details">
                                             <h6><?php echo ucwords(str_replace('_', ' ', $checkName)); ?></h6>
                                             <p class="text-secondary"><?php echo e($check['message']); ?></p>
                                             <?php if (!empty($check['details']) && is_array($check['details'])): ?>
                                                 <div class="health-check-subdetails">
                                                     <?php foreach ($check['details'] as $detail): ?>
                                                         <div class="health-check-subdetail">
                                                             <span class="badge badge-<?php echo ($detail['status'] ?? 'warning') === 'pass' ? 'success' : (($detail['status'] ?? 'warning') === 'warning' ? 'warning' : 'danger'); ?>">
                                                                 <?php echo strtoupper((string)($detail['status'] ?? 'warning')); ?>
                                                             </span>
                                                             <span class="subdetail-label"><?php echo e($detail['label'] ?? 'Detail'); ?>:</span>
                                                             <span><?php echo e($detail['message'] ?? ''); ?></span>
                                                         </div>
                                                     <?php endforeach; ?>
                                                 </div>
                                             <?php endif; ?>
                                         </div>
                                         <div class="check-badge">
                                             <span class="badge badge-<?php echo $check['status'] === 'pass' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'danger'); ?>">
                                                <?php echo strtoupper($check['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-tools"></i> System Maintenance
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($actionResult)): ?>
                                <div class="alert alert-<?php echo $actionResult['status'] === 'success' ? 'success' : ($actionResult['status'] === 'warning' ? 'warning' : 'danger'); ?>">
                                    <?php echo e($actionResult['message']); ?>
                                    <?php if (!empty($actionResult['path'])): ?>
                                        <?php $openBackupUrl = BASE_URL . '/views/admin/backup/download.php?file=' . urlencode(basename((string)$actionResult['path'])); ?>
                                        <div class="mt-2"><a href="<?php echo e($openBackupUrl); ?>" target="_blank">Download backup file</a></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="btn-toolbar" role="toolbar">
                                <div class="btn-group mr-3" role="group">
                                    <button onclick="window.location.reload()" class="btn btn-primary">
                                        <i class="fas fa-redo"></i> Refresh Check
                                    </button>
                                    <form method="post" action="<?php echo e(BASE_URL . '/views/admin/system/health.php#smtp-diagnostics'); ?>" style="display:inline-block;margin-left:8px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="run_smtp_probe">
                                        <button type="submit" class="btn btn-outline-primary">
                                            <i class="fas fa-envelope-open-text"></i> SMTP Health Check
                                        </button>
                                    </form>
                                    <a href="?show_logs=<?php echo $showLogs ? '0' : '1'; ?>" class="btn btn-secondary">
                                        <i class="fas fa-file-alt"></i> <?php echo $showLogs ? 'Hide Logs' : 'View Logs'; ?>
                                    </a>

                                    <form method="post" onsubmit="return confirm('Purge activity logs older than <?php echo (int)getSetting("log_retention_days", defined("LOG_RETENTION_DAYS") ? LOG_RETENTION_DAYS : 90); ?> days? This cannot be undone.');" style="display:inline-block;margin-left:8px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="purge_logs">
                                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Purge Logs</button>
                                    </form>
                                </div>

                                <div class="btn-group mr-3" role="group">
                                    <form method="post" onsubmit="return confirm('Create a database backup now?');" style="display:inline-block;margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="backup_db">
                                        <button type="submit" class="btn btn-info"><i class="fas fa-download"></i> Backup Database</button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('Clear application cache? This will delete generated cache files.');" style="display:inline-block;margin:0 0 0 10px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="clear_cache">
                                        <button type="submit" class="btn btn-warning"><i class="fas fa-broom"></i> Clean Cache</button>
                                    </form>
                                </div>

                                <div class="btn-group mr-3" role="group">
                                    <form method="post" onsubmit="return confirm('Run a live uptime probe now?');" style="display:inline-block;margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="run_uptime_probe">
                                        <button type="submit" class="btn btn-outline-primary"><i class="fas fa-signal"></i> Run Uptime Probe</button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('Run restore drill verification on latest backup now?');" style="display:inline-block;margin:0 0 0 10px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="run_restore_drill">
                                        <button type="submit" class="btn btn-outline-success"><i class="fas fa-life-ring"></i> Run Restore Drill</button>
                                    </form>
                                </div>

                                <div class="btn-group" role="group">
                                    <?php
                                    // List recent backups
                                    $backupDir = BackupSecurity::getBackupDirectory();
                                    $backups = [];
                                    if (is_dir($backupDir)) {
                                        foreach (BackupSecurity::listBackupFiles() as $f) {
                                            $backups[filemtime($f)] = $f;
                                        }
                                        krsort($backups);
                                        $backups = array_values($backups);
                                    }
                                    ?>
                                    <?php if (!empty($backups)): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="backupMenu" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                <i class="fas fa-archive"></i> Recent Backups
                                            </button>
                                            <div class="dropdown-menu" aria-labelledby="backupMenu">
                                                <?php foreach (array_slice($backups, 0, 8) as $b): ?>
                                                    <?php $downloadUrl = BASE_URL . '/views/admin/backup/download.php?file=' . urlencode(basename($b)); ?>
                                                    <?php $isEncryptedBackup = (substr((string)$b, -8) === '.sql.enc'); ?>
                                                    <?php $compareUrl = $isEncryptedBackup ? '' : (BASE_URL . '/views/admin/backup/compare.php?file=' . urlencode(basename($b))); ?>
                                                    <div class="dropdown-item d-flex justify-content-between align-items-center">
                                                        <div><a href="<?php echo e($downloadUrl); ?>"><?php echo e(basename($b)); ?></a><br><small class="text-muted"><?php echo date('Y-m-d H:i', filemtime($b)); ?></small></div>
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if (!$isEncryptedBackup): ?>
                                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo e($compareUrl); ?>">Compare</a>
                                                            <?php endif; ?>
                                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($downloadUrl); ?>">Download</a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="dropdown-divider"></div>
                                                <form method="post" style="padding:8px 12px;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="purge_backups">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-block" onclick="return confirm('Purge backups older than <?php echo defined('BACKUP_RETENTION_DAYS') ? BACKUP_RETENTION_DAYS : 30; ?> days?')">Purge Old Backups</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted ml-2">No backups yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($showLogs): ?>
                                <hr>
                                <h6>Recent Activity Logs</h6>
                                <?php
                                    $logger = new Logger();
                                    $logs = $logger->getRecentActivities(200);
                                ?>
                                <div style="max-height:360px;overflow:auto;margin-top:10px;">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th width="160">Time</th>
                                                <th>User</th>
                                                <th>Action</th>
                                                <th>Module</th>
                                                <th>Description</th>
                                                <th>IP</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($logs as $row): ?>
                                            <tr>
                                                <td><?php echo e(date('Y-m-d H:i', strtotime($row['created_at']))); ?></td>
                                                <td><?php echo e($row['display_name'] ?? ($row['username'] ?? 'system')); ?></td>
                                                <td><?php echo e($row['action']); ?></td>
                                                <td><?php echo e($row['module']); ?></td>
                                                <td><?php echo e($row['description']); ?></td>
                                                <td><?php echo e($row['ip_address']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.health-score .score-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: bold;
    margin: 0 auto;
}
.score-circle.excellent { background: #d4edda; color: #155724; }
.score-circle.good { background: #fff3cd; color: #856404; }
.score-circle.poor { background: #f8d7da; color: #721c24; }

.health-summary {
    display: flex;
    gap: 20px;
    align-items: center;
}
.summary-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.health-check-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid #f1f3f4;
}
.health-check-item:last-child {
    border-bottom: none;
}
.check-status {
    width: 40px;
    text-align: center;
    font-size: 1.2rem;
}
.check-details {
    flex: 1;
    margin-left: 15px;
}
.check-details h6 {
    margin: 0;
    font-weight: 600;
}
.check-details p {
    margin: 0;
    font-size: 0.9rem;
}
.health-check-subdetails {
    margin-top: 10px;
    display: grid;
    gap: 6px;
}
.health-check-subdetail {
    font-size: 0.85rem;
    color: #4b5563;
}
.health-check-subdetail .badge {
    margin-right: 6px;
}
.health-check-subdetail .subdetail-label {
    font-weight: 600;
    margin-right: 4px;
}
.check-badge {
    margin-left: 15px;
}
.smtp-diagnostic-summary {
    padding: 14px 16px;
    border-radius: 12px;
    border: 1px solid #dbe4ea;
    background: #f8fafc;
}
.smtp-detail-card {
    height: 100%;
    padding: 14px 16px;
    border-radius: 12px;
    border: 1px solid #dbe4ea;
    background: #ffffff;
}
.smtp-pass {
    border-color: #c3e6cb;
    background: #f0fff4;
}
.smtp-warning {
    border-color: #ffe08a;
    background: #fff9e6;
}
.smtp-fail {
    border-color: #f5c6cb;
    background: #fff5f5;
}

@media (max-width: 767.98px) {
    .system-health-page .topbar {
        grid-template-columns: minmax(0, 1fr);
        row-gap: 4px;
    }

    .system-health-page .topbar-left h4 {
        max-width: 100% !important;
        white-space: normal !important;
        font-size: 0.86rem !important;
    }

    .system-health-page .topbar-left .btn-link {
        padding: 0 4px 0 0;
        font-size: 0.78rem;
    }

    .system-health-page .topbar-right {
        justify-self: start !important;
    }

    .system-health-page .topbar-right small {
        font-size: 0.72rem;
    }

    .system-health-page .container-fluid {
        padding-left: 0;
        padding-right: 0;
    }

    .system-health-page .card {
        padding: 0 !important;
        overflow: hidden;
    }

    .system-health-page .card-header {
        padding: 10px 12px;
    }

    .system-health-page .card-header h5 {
        font-size: 0.92rem;
        line-height: 1.25;
    }

    .system-health-page .card-body {
        padding: 12px;
    }

    .system-overview-body {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .system-overview-layout {
        min-width: 520px;
        flex-wrap: nowrap;
        align-items: center;
        margin-left: 0;
        margin-right: 0;
    }

    .system-health-score-col {
        flex: 0 0 120px;
        max-width: 120px;
        padding-left: 8px;
        padding-right: 12px;
    }

    .system-health-info-col {
        flex: 1 0 360px;
        max-width: none;
        padding-left: 8px;
        padding-right: 8px;
    }

    .health-score .score-circle {
        width: 64px;
        height: 64px;
        font-size: 1rem;
    }

    .health-score p {
        margin: 8px 0 0;
        font-size: 0.76rem;
        line-height: 1.2;
    }

    .health-summary {
        gap: 10px;
        flex-wrap: nowrap;
    }

    .summary-item {
        min-width: 94px;
        gap: 6px;
        font-size: 0.78rem;
        white-space: nowrap;
    }

    .system-health-info-col .alert {
        margin-top: 10px !important;
        padding: 8px 10px;
        font-size: 0.78rem;
        line-height: 1.35;
    }

    .health-checks {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .health-check-item {
        min-width: 620px;
        align-items: flex-start;
        padding: 11px 0;
    }

    .check-status {
        width: 30px;
        font-size: 1rem;
    }

    .check-details {
        margin-left: 8px;
    }

    .check-details h6 {
        font-size: 0.84rem;
    }

    .check-details p,
    .health-check-subdetail {
        font-size: 0.76rem;
        line-height: 1.35;
    }

    .check-badge {
        margin-left: 10px;
    }

    .smtp-diagnostic-summary,
    .smtp-detail-card {
        padding: 10px 12px;
        border-radius: 10px;
        font-size: 0.8rem;
    }
}

@media (max-width: 430px) {
    .system-overview-layout {
        min-width: 480px;
    }

    .system-health-info-col {
        flex-basis: 330px;
    }

    .health-check-item {
        min-width: 560px;
    }
}

html[data-theme='dark'] .alert.alert-success.mt-3 {
    background: #14532d;
    border-color: #166534;
    color: #dcfce7;
}

html[data-theme='dark'] .alert.alert-success.mt-3 i,
html[data-theme='dark'] .alert.alert-success.mt-3 strong {
    color: #bbf7d0;
}

html[data-theme='dark'] .health-score .score-circle.excellent {
    background: #166534;
    color: #dcfce7;
    border: 1px solid #22c55e;
}

html[data-theme='dark'] .health-score .score-circle.good {
    background: #854d0e;
    color: #fef3c7;
    border: 1px solid #f59e0b;
}

html[data-theme='dark'] .health-score .score-circle.poor {
    background: #7f1d1d;
    color: #fee2e2;
    border: 1px solid #ef4444;
}

html[data-theme='dark'] .health-score p {
    color: #e2e8f0;
}
html[data-theme='dark'] .health-check-subdetail {
    color: #cbd5e1;
}
html[data-theme='dark'] .smtp-diagnostic-summary,
html[data-theme='dark'] .smtp-detail-card {
    color: #e2e8f0;
    border-color: #334155;
    background: #0f172a;
}
html[data-theme='dark'] .smtp-pass {
    border-color: #166534;
    background: #052e16;
}
html[data-theme='dark'] .smtp-warning {
    border-color: #a16207;
    background: #422006;
}
html[data-theme='dark'] .smtp-fail {
    border-color: #991b1b;
    background: #450a0a;
}
</style>

<?php include '../../../includes/footer.php'; ?>


