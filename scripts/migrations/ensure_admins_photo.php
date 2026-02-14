<?php
/**
 * Migration: ensure admins.photo column exists
 * - Defines function ensure_admins_photo_exists(PDO $conn, Logger $logger = null): bool
 * - Can be executed from CLI to run migration immediately
 */
require_once __DIR__ . '/../../config.php';

use \PDO;

if (!function_exists('ensure_admins_photo_exists')) {
    function ensure_admins_photo_exists(PDO $conn, $logger = null) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM admins LIKE 'photo'")->fetch();
            if ($col) {
                if ($logger) {
                    try { $logger->log(0, 'migration_check', 'migrations', 'admins.photo already present'); } catch (Exception $e) {}
                }
                return true;
            }

            $conn->exec("ALTER TABLE admins ADD COLUMN photo VARCHAR(255) NULL AFTER email");

            // verify
            $col2 = $conn->query("SHOW COLUMNS FROM admins LIKE 'photo'")->fetch();
            if ($col2) {
                if ($logger) {
                    try { $logger->log(0, 'migration_run', 'migrations', 'Added admins.photo column'); } catch (Exception $e) {}
                }
                return true;
            }

            return false;
        } catch (Exception $e) {
            if ($logger) {
                try { $logger->log(0, 'migration_error', 'migrations', 'ensure_admins_photo failed: ' . $e->getMessage()); } catch (Exception $ignored) {}
            }
            throw $e; // rethrow for CLI/test to observe
        }
    }
}

// CLI runner
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['argv'][0])) {
    $db = new Database();
    $conn = $db->getConnection();
    $logger = new Logger();

    try {
        $ok = ensure_admins_photo_exists($conn, $logger);
        if ($ok) {
            echo "Migration complete: admins.photo is present\n";
            exit(0);
        } else {
            echo "Migration failed: admins.photo not present after run\n";
            exit(2);
        }
    } catch (Exception $e) {
        fwrite(STDERR, "Migration error: " . $e->getMessage() . "\n");
        exit(3);
    }
}
