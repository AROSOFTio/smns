<?php
/**
 * Restore test drill (CLI/cron).
 * Verifies latest backup can be read/decrypted and appears structurally restorable.
 */
require_once __DIR__ . '/../config.php';

function ensureRestoreDrillTable(PDO $conn): void
{
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
}

function decryptBackupPayloadIfNeeded(string $path, array &$details): ?string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== 'enc') {
        return @file_get_contents($path) ?: null;
    }

    if (!extension_loaded('openssl')) {
        $details['error'] = 'OpenSSL extension unavailable for encrypted backup verification.';
        return null;
    }

    $payload = @file_get_contents($path);
    if (!is_string($payload) || $payload === '') {
        $details['error'] = 'Unable to read encrypted backup file.';
        return null;
    }

    if (substr($payload, 0, 8) !== 'SMNSENC1') {
        $details['error'] = 'Encrypted backup header not recognized.';
        return null;
    }

    $ivLen = openssl_cipher_iv_length('AES-256-CBC');
    if (!is_int($ivLen) || $ivLen <= 0) {
        $details['error'] = 'Unable to determine encryption IV length.';
        return null;
    }

    $resolved = BackupSecurity::resolveEncryptionKey();
    $keyMaterial = trim((string)($resolved['key'] ?? ''));
    if ($keyMaterial === '') {
        $details['error'] = 'Backup encryption key is not configured.';
        return null;
    }

    $iv = substr($payload, 8, $ivLen);
    $cipherText = substr($payload, 8 + $ivLen);
    if ($iv === '' || $cipherText === '') {
        $details['error'] = 'Encrypted backup payload is incomplete.';
        return null;
    }

    $key = hash('sha256', $keyMaterial, true);
    $plain = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false || $plain === '') {
        $details['error'] = 'Encrypted backup could not be decrypted with current key.';
        return null;
    }

    $details['decryption_key_source'] = (string)($resolved['source'] ?? 'unknown');
    return $plain;
}

function notifyRestoreDrillFailure(PDO $conn, string $message, array $details): bool
{
    $emails = [];
    $stmt = $conn->query("SELECT email FROM users WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    $emails = array_values(array_unique($emails));
    if (empty($emails)) {
        return false;
    }

    $subject = APP_NAME . ' - Restore Drill Failed';
    $bodyLines = [
        'Automated restore drill failed.',
        'Time: ' . date('Y-m-d H:i:s'),
        'Message: ' . $message,
        'System Health: ' . BASE_URL . '/views/admin/system/health.php',
    ];
    if (!empty($details['backup_file'])) {
        $bodyLines[] = 'Backup: ' . (string)$details['backup_file'];
    }
    if (!empty($details['error'])) {
        $bodyLines[] = 'Error: ' . (string)$details['error'];
    }

    return (bool)Helper::sendEmail(
        $emails,
        $subject,
        implode("\n", $bodyLines),
        null,
        [
            'context_label' => 'Restore drill failure',
            'source_page' => 'scripts/restore_test_drill.php',
            'notify_admin_on_failure' => false,
        ]
    );
}

try {
    $started = microtime(true);
    $db = new Database();
    $conn = $db->getConnection();
    ensureRestoreDrillTable($conn);

    $details = [];
    $status = 'fail';
    $backupFile = null;
    $backupPath = null;
    $backupSize = null;
    $backupMtimeSql = null;
    $message = '';

    $files = BackupSecurity::listBackupFiles();
    if (empty($files)) {
        $message = 'No backup files available for restore drill.';
        $details['error'] = $message;
    } else {
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $backupPath = (string)$files[0];
        $backupFile = basename($backupPath);
        $backupSize = @filesize($backupPath);
        $backupMtime = @filemtime($backupPath);
        $backupMtimeSql = $backupMtime ? date('Y-m-d H:i:s', $backupMtime) : null;

        $details['backup_file'] = $backupFile;
        $details['backup_size_bytes'] = $backupSize;
        $details['backup_modified_at'] = $backupMtimeSql;

        $plain = decryptBackupPayloadIfNeeded($backupPath, $details);
        if (!is_string($plain) || $plain === '') {
            $message = 'Backup could not be read/decrypted for restore verification.';
        } else {
            $sample = substr($plain, 0, 1024 * 1024);
            $hasCreate = (stripos($sample, 'CREATE TABLE') !== false);
            $hasInsert = (stripos($sample, 'INSERT INTO') !== false);
            preg_match_all('/CREATE\s+TABLE/i', $plain, $tblMatches);
            $tableCount = count($tblMatches[0] ?? []);
            $details['contains_create_table'] = $hasCreate;
            $details['contains_insert_into'] = $hasInsert;
            $details['estimated_table_count'] = $tableCount;

            if ($hasCreate && $tableCount >= 3) {
                $status = $hasInsert ? 'pass' : 'warning';
                $message = $hasInsert
                    ? 'Restore drill passed: backup structure/data statements validated.'
                    : 'Restore drill warning: structure validated but INSERT statements not detected in sampled content.';
            } else {
                $message = 'Backup content does not contain expected SQL structure (CREATE TABLE).';
                $details['error'] = $message;
            }
        }
    }

    $duration = round(microtime(true) - $started, 3);
    $insert = $conn->prepare("
        INSERT INTO system_restore_drills
            (executed_at, backup_file, backup_size_bytes, backup_modified_at, status, duration_seconds, details_json, executed_by)
        VALUES
            (NOW(), :backup_file, :backup_size_bytes, :backup_modified_at, :status, :duration_seconds, :details_json, :executed_by)
    ");
    $insert->execute([
        'backup_file' => $backupFile,
        'backup_size_bytes' => $backupSize,
        'backup_modified_at' => $backupMtimeSql,
        'status' => $status,
        'duration_seconds' => $duration,
        'details_json' => json_encode($details),
        'executed_by' => PHP_SAPI === 'cli' ? 'cli' : 'web',
    ]);

    try {
        $logger = new Logger();
        $logger->log(null, 'restore_drill_' . $status, 'system', $message . ($backupFile ? ' Backup: ' . $backupFile : ''));
    } catch (Exception $e) {
        // non-fatal
    }

    if ($status === 'fail') {
        $emailSent = notifyRestoreDrillFailure($conn, $message, $details);
        if (!$emailSent) {
            // still continue; evidence already logged
        }
    }

    if (PHP_SAPI === 'cli') {
        echo strtoupper($status) . ': ' . $message . PHP_EOL;
        if ($backupFile) {
            echo 'Backup: ' . $backupFile . PHP_EOL;
        }
        echo 'Duration: ' . number_format((float)$duration, 3) . " sec" . PHP_EOL;
    }

    exit($status === 'pass' ? 0 : ($status === 'warning' ? 0 : 1));
} catch (Exception $e) {
    error_log('Restore drill fatal error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Restore drill failed: ' . $e->getMessage() . PHP_EOL);
    }
    exit(2);
}
