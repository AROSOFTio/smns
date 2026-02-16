<?php
// Migration: Add year_of_study to semester_registrations
require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->exec("ALTER TABLE semester_registrations ADD COLUMN year_of_study INT(2) NULL AFTER semester_id");
    echo "Column year_of_study added to semester_registrations successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column year_of_study already exists in semester_registrations.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
