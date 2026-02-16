<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE students ADD COLUMN current_semester INT(11) NULL AFTER session");
    echo "Column current_semester added to students table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column current_semester already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
