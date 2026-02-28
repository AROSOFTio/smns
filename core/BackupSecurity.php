<?php
/**
 * Backup storage and encryption utilities.
 */
class BackupSecurity {
    private static function getSettingValue($key, $default = null) {
        if (function_exists('getSetting')) {
            try {
                $val = getSetting((string)$key, null);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            } catch (Exception $e) {
                // fall through to default
            }
        }
        return $default;
    }

    public static function getBackupDirectory() {
        if (defined('BACKUP_STORAGE_PATH') && trim((string)BACKUP_STORAGE_PATH) !== '') {
            return (string)BACKUP_STORAGE_PATH;
        }
        return dirname(BASE_PATH, 2) . DIRECTORY_SEPARATOR . 'smns_secure_backups';
    }

    public static function ensureBackupDirectory() {
        $backupDir = self::getBackupDirectory();
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0700, true);
        }
        // Defense-in-depth for accidental in-webroot placement.
        $htaccess = $backupDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            $rules = "Order allow,deny\nDeny from all\n";
            @file_put_contents($htaccess, $rules);
        }
        return $backupDir;
    }

    public static function listBackupFiles() {
        $dir = self::getBackupDirectory();
        if (!is_dir($dir)) {
            return [];
        }
        $patterns = ['*.sql', '*.sql.enc'];
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: [] as $f) {
                if (is_file($f)) {
                    $files[] = $f;
                }
            }
        }
        return $files;
    }

    public static function isEncryptionEnabled() {
        $default = (defined('BACKUP_ENCRYPTION_ENABLED') && BACKUP_ENCRYPTION_ENABLED) ? '1' : '0';
        $raw = (string)self::getSettingValue('backup_encryption_enabled', $default);
        $normalized = strtolower(trim($raw));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public static function resolveEncryptionKey() {
        $envKey = trim((string)getenv('SMNS_BACKUP_ENCRYPTION_KEY'));
        if ($envKey !== '') {
            return ['key' => $envKey, 'source' => 'environment', 'configured' => true];
        }

        $dbKey = trim((string)self::getSettingValue('backup_encryption_key', ''));
        if ($dbKey !== '') {
            return ['key' => $dbKey, 'source' => 'database', 'configured' => true];
        }

        $fallback = (string)(defined('BACKUP_ENCRYPTION_KEY') ? BACKUP_ENCRYPTION_KEY : '');
        if ($fallback !== '') {
            return ['key' => $fallback, 'source' => 'fallback', 'configured' => false];
        }

        return ['key' => '', 'source' => 'missing', 'configured' => false];
    }

    public static function getEncryptionKeyStatus() {
        $resolved = self::resolveEncryptionKey();
        return [
            'enabled' => self::isEncryptionEnabled(),
            'source' => (string)$resolved['source'],
            'configured' => (bool)$resolved['configured']
        ];
    }

    public static function encryptIfEnabled($plainSqlPath) {
        $plainSqlPath = (string)$plainSqlPath;
        if (!self::isEncryptionEnabled()) {
            return $plainSqlPath;
        }
        $resolvedKey = self::resolveEncryptionKey();
        $keyMaterial = (string)($resolvedKey['key'] ?? '');
        if ($keyMaterial === '' || !extension_loaded('openssl')) {
            return $plainSqlPath;
        }
        if (!is_file($plainSqlPath)) {
            return $plainSqlPath;
        }

        $cipher = 'AES-256-CBC';
        $ivLen = openssl_cipher_iv_length($cipher);
        if ($ivLen <= 0) {
            return $plainSqlPath;
        }
        $iv = random_bytes($ivLen);
        $key = hash('sha256', $keyMaterial, true);
        $plain = file_get_contents($plainSqlPath);
        if ($plain === false) {
            return $plainSqlPath;
        }
        $enc = openssl_encrypt($plain, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) {
            return $plainSqlPath;
        }

        $target = $plainSqlPath . '.enc';
        $payload = "SMNSENC1" . $iv . $enc;
        if (@file_put_contents($target, $payload) === false) {
            return $plainSqlPath;
        }

        @unlink($plainSqlPath);
        return $target;
    }
}
