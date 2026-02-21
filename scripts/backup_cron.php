<?php
/**
 * CLI script: Automated DB backup + retention for SMNS
 * Run from cron: 0 2 * * * /usr/bin/php /path/to/smns/scripts/backup_cron.php
 */
require_once __DIR__ . '/../config.php';

// If scheduled backups are disabled in settings, do nothing
if (php_sapi_name() === 'cli') {
    $enabled = getSetting('scheduled_backup_enabled', '1');
    if ($enabled !== '1') {
        echo "Scheduled backups are disabled (settings). Exiting.\n";
        exit(0);
    }
}

// minimal environment for CLI
try {
    $backupDir = BASE_PATH . DIRECTORY_SEPARATOR . 'database backup';
    if (!is_dir($backupDir)) { @mkdir($backupDir, 0777, true); }

    $timestamp = date('Ymd_His');
    $fileName = 'smns_backup_' . $timestamp . '.sql';
    $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

    $dbHost = DB_HOST; $dbUser = DB_USER; $dbPass = DB_PASS; $dbName = DB_NAME;
    $escapedPath = escapeshellarg($filePath);
    $cmd = "mysqldump --host=" . escapeshellarg($dbHost) . " --user=" . escapeshellarg($dbUser) . " --password=" . escapeshellarg($dbPass) . " " . escapeshellarg($dbName) . " > $escapedPath";

    @exec($cmd, $out, $ret);
    $created = false;

    if ($ret === 0 && file_exists($filePath)) {
        $created = true;
    } else {
        // PHP fallback dump
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
        if (file_exists($filePath)) { $created = true; }
    }

    // retention (prefer DB settings when available)
    $retentionDays = (int)getSetting('backup_retention_days', defined('BACKUP_RETENTION_DAYS') ? BACKUP_RETENTION_DAYS : 30);
    $maxFiles = (int)getSetting('backup_retention_max_files', defined('BACKUP_RETENTION_MAX_FILES') ? BACKUP_RETENTION_MAX_FILES : 50);
    $deleted = 0;
    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql');
    if (!empty($files)) {
        foreach ($files as $f) {
            if (filemtime($f) < strtotime("-{$retentionDays} days")) { @unlink($f) && $deleted++; }
        }
        usort($files, function($a,$b){ return filemtime($a) - filemtime($b); });
        while (count($files) > $maxFiles) { $f = array_shift($files); if (is_file($f)) { @unlink($f) && $deleted++; } }
    }

    // log the action (system user id = 0)
    try {
        $logger = new Logger();
        $logger->log(0, 'scheduled_backup', 'system', 'Automated scheduled backup created: ' . ($created ? basename($filePath) : 'failed'));
    } catch (Exception $e) {
        // swallow logging errors in cron
    }

    // Notify recipients (email) and create admin notifications
    try {
        $recips = trim(getSetting('scheduled_backup_recipients', SMTP_FROM_EMAIL));
        $emails = array_filter(array_map('trim', explode(',', $recips)));
        $subject = APP_NAME . ' - Scheduled Backup ' . ($created ? 'Succeeded' : 'FAILED');
        $body = ($created ? "Backup created: " . BASE_URL . '/database%20backup/' . basename($filePath) : "Backup failed on " . date('Y-m-d H:i:s')) . "\n\nRegards,\n" . APP_NAME;
        if (!empty($emails)) {
            Helper::sendEmail($emails, $subject, $body);
        }

        // create notifications for all admin users
        $db = new Database(); $conn = $db->getConnection();
        $admins = $conn->prepare("SELECT id, email FROM users WHERE role = 'admin'");
        $admins->execute();
        $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
        $link = $created ? (BASE_URL . '/views/admin/system/health.php') : 'action:run_backup';
        $title = $created ? 'Scheduled backup created' : 'Scheduled backup failed';
        $msg = $created ? 'Automated scheduled backup created: ' . basename($filePath) : 'Automated scheduled backup failed on ' . date('Y-m-d H:i:s') . '. Click to retry.';
        while ($a = $admins->fetch(PDO::FETCH_ASSOC)) {
            try { $noteStmt->execute(['uid' => $a['id'], 'title' => $title, 'msg' => $msg, 'type' => $created ? 'success' : 'error', 'link' => $link]); } catch (Exception $e) { /* ignore */ }
        }
    } catch (Exception $e) {
        // ignore notification/email errors in cron
    }

    if (php_sapi_name() === 'cli') {
        echo ($created ? "Backup created: $filePath\n" : "Backup failed\n");
        echo "Retention cleaned: {$deleted} files removed\n";
        exit($created ? 0 : 1);
    }
} catch (Exception $ex) {
    if (php_sapi_name() === 'cli') { echo 'Error: ' . $ex->getMessage() . "\n"; exit(2); }
}
