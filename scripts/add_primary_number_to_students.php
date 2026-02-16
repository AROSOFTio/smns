<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE students ADD COLUMN primary_number VARCHAR(30) NULL AFTER smns_email");
    echo "Column primary_number added to students table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column primary_number already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
