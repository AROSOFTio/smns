<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE students ADD COLUMN session VARCHAR(30) NULL AFTER study_year");
    echo "Column session added to students table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column session already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
