<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE students ADD COLUMN other_name VARCHAR(100) NULL AFTER first_name");
    echo "Column other_name added to students table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column other_name already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
