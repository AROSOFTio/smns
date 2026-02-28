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
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
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
                    } catch (Exception $e) {
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
                            try { $noteStmt->execute(['uid' => $a['id'], 'title' => $title, 'msg' => $msg, 'type' => 'success', 'link' => $link]); } catch (Exception $e) { }
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
                            try { $noteStmt->execute(['uid' => $a['id'], 'title' => $title, 'msg' => $msg, 'type' => 'error', 'link' => $link]); } catch (Exception $e) { }
                        }
                    }
                } catch (Exception $e) {
                    // non-fatal
                }

                break;
                break;

            case 'run_scheduled_backup':
                // If cron-worker script exists, prefer executing it; otherwise run inline
                $cronScript = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup_cron.php';
                $ran = false;
                if (is_file($cronScript) && is_executable($cronScript)) {
                    $phpExec = escapeshellarg(resolvePhpExecBinary(true));
                    @exec($phpExec . ' ' . escapeshellarg($cronScript), $out, $retCode);
                    if ($retCode === 0) { $actionResult = ['status' => 'success', 'message' => 'Scheduled backup script executed']; $ran = true; }
                }
                if (!$ran) {
                    // fallback to manual backup behavior
                    $_POST['action'] = 'backup_db';
                    // reuse existing code path by reloading the page (simple approach)
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
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
                    while ($a = $admins->fetch(PDO::FETCH_ASSOC)) { try { $noteStmt->execute(['uid' => $a['id'], 'title'=>$title, 'msg'=>$msg, 'type'=>'info', 'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Exception $e) {} }
                } catch (Exception $e) {}
                break;

            case 'purge_logs':
                try {
                    $days = (int)getSetting('log_retention_days', defined('LOG_RETENTION_DAYS') ? LOG_RETENTION_DAYS : 90);
                    $pdo = (new Database())->getConnection();
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
                        while ($a = $admins->fetch(PDO::FETCH_ASSOC)) { try { $noteStmt->execute(['uid' => $a['id'], 'title'=>$title, 'msg'=>$msg, 'type'=>'info', 'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Exception $e) {} }
                    } catch (Exception $e) {}

                } catch (Exception $e) {
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
                    while ($a = $admins->fetch(PDO::FETCH_ASSOC)) { try { $noteStmt->execute(['uid' => $a['id'], 'title'=>$title, 'msg'=>$msg, 'type'=>'info', 'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Exception $e) {} }
                } catch (Exception $e) {}

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
        $issueHash = hash('sha256', json_encode($signatureData));

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
    } catch (Exception $e) {
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
} catch (Exception $e) {
    $checks['database'] = ['status' => 'fail', 'message' => 'Database connection failed: ' . $e->getMessage()];
}

// 2. User Authentication Test
try {
    $testAuth = new Auth();
    $checks['auth'] = ['status' => 'pass', 'message' => 'Authentication system loaded'];
} catch (Exception $e) {
    $checks['auth'] = ['status' => 'fail', 'message' => 'Auth system error: ' . $e->getMessage()];
}

// 3. Session Management Test
try {
    $testSession = new Session();
    $checks['session'] = ['status' => 'pass', 'message' => 'Session management working'];
} catch (Exception $e) {
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
} catch (Exception $e) {
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
} catch (Exception $e) {
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
    } catch (Exception $e) {
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

// 10. SMTP connectivity (basic)
try {
    $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
    $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : null;
    if ($smtpHost && $smtpPort) {
        $fp = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 2);
        if ($fp) { fclose($fp); $checks['smtp'] = ['status' => 'pass', 'message' => "SMTP reachable: $smtpHost:$smtpPort"]; }
        else { $checks['smtp'] = ['status' => 'warning', 'message' => "SMTP not reachable: $smtpHost:$smtpPort ($errno) $errstr"]; }
    } else {
        $checks['smtp'] = ['status' => 'warning', 'message' => 'SMTP settings not configured in config.php'];
    }
} catch (Exception $e) {
    $checks['smtp'] = ['status' => 'fail', 'message' => 'SMTP check error: ' . $e->getMessage()];
} 

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
} catch (Exception $e) {
    $checks['admin_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
    $checks['student_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
    $checks['lecturer_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
    $checks['finance_data'] = ['status' => 'fail', 'message' => 'Module data check failed: ' . $e->getMessage()];
}

// Send throttled email alert to admins when warnings/failures exist.
sendSystemHealthAlertIfNeeded($checks, $currentUser ?? []);

$pageTitle = 'System Health Check - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>
                <a href="../dashboard.php" class="btn btn-link">← Back to Dashboard</a>
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
                        <div class="card-body">
                            <?php
                            $passCount = count(array_filter($checks, function($check) { return $check['status'] === 'pass'; }));
                            $totalChecks = max(1, count($checks));
                            $healthPercentage = round(($passCount / $totalChecks) * 100);
                            ?>
                            
                            <div class="row">
                                <div class="col-md-3 text-center">
                                    <div class="health-score">
                                        <div class="score-circle <?php echo $healthPercentage >= 90 ? 'excellent' : ($healthPercentage >= 70 ? 'good' : 'poor'); ?>">
                                            <?php echo $healthPercentage; ?>%
                                        </div>
                                        <p>System Health</p>
                                    </div>
                                </div>
                                <div class="col-md-9">
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
.check-badge {
    margin-left: 15px;
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
</style>

<?php include '../../../includes/footer.php'; ?>
