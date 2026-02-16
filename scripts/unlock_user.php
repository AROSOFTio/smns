<?php
// Admin tool to unlock a user account by username or email
require_once '../config.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.";
    exit(1);
}

if ($argc < 2) {
    echo "Usage: php unlock_user.php <username-or-email>\n";
    exit(1);
}

$identifier = $argv[1];

try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("UPDATE users SET status = 'active', account_locked_until = NULL, failed_login_attempts = 0 WHERE username = :id OR email = :id");
    $stmt->execute(['id' => $identifier]);
    if ($stmt->rowCount() > 0) {
        echo "User '$identifier' unlocked successfully.\n";
    } else {
        echo "No user found with username or email '$identifier'.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
