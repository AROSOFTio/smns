<?php
/**
 * Simple unit test for ensure_admins_photo_exists migration
 * Run with: php tests/test_ensure_admins_photo.php
 * Exits 0 on success, non-zero on failure.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../scripts/migrations/ensure_admins_photo.php';

$passed = false;
try {
    $db = new Database();
    $conn = $db->getConnection();
    $logger = new Logger();

    // Run migration function
    $res = ensure_admins_photo_exists($conn, $logger);

    // Verify column exists
    $col = $conn->query("SHOW COLUMNS FROM admins LIKE 'photo'")->fetch();
    if ($res && $col) {
        echo "PASS: admins.photo exists\n";
        $passed = true;
    } else {
        echo "FAIL: admins.photo missing after migration\n";
    }
} catch (Exception $e) {
    echo "ERROR: Exception during test - " . $e->getMessage() . "\n";
}

exit($passed ? 0 : 1);
