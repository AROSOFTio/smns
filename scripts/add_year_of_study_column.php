<?php
// Run this script ONCE to add the year_of_study column to the students table
require_once __DIR__ . '/../config.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->exec("ALTER TABLE students ADD COLUMN year_of_study INT(2) DEFAULT 1 AFTER program_id;");
    echo "Column year_of_study added successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
