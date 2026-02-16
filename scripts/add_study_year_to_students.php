<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE students ADD COLUMN study_year INT(2) NULL AFTER program_id");
    echo "Column study_year added to students table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column study_year already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
