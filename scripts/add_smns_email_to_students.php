<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE students ADD COLUMN smns_email VARCHAR(150) NULL AFTER email");
    echo "Column smns_email added to students table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column smns_email already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
