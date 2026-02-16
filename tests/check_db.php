<?php
require_once __DIR__ . '/../config.php';

echo "Checking DB connection...\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    if ($conn instanceof PDO) {
        echo "Connected to database: " . DB_NAME . " as " . DB_USER . "\n";
    } else {
        echo "Unexpected connection object type.\n";
    }
} catch (Exception $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Quick check: list a few tables
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'students'");
    $found = $stmt->fetch();
    echo "students table: " . ($found ? 'exists' : 'missing') . "\n";
} catch (Exception $e) {
    echo "Error checking tables: " . $e->getMessage() . "\n";
}

echo "Done.\n";
