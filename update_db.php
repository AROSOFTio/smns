<?php
require 'config.php';

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add missing columns to students table
    $sql = "
    ALTER TABLE students
    ADD COLUMN title VARCHAR(10) NULL AFTER last_name,
    ADD COLUMN national_id VARCHAR(20) NULL AFTER date_of_birth,
    ADD COLUMN specialization VARCHAR(200) NULL AFTER level_year,
    ADD COLUMN qualifications TEXT NULL AFTER specialization
    ";

    $pdo->exec($sql);
    echo "Students table updated successfully.\n";

    // Add missing columns to lecturers table
    $sql2 = "
    ALTER TABLE lecturers
    ADD COLUMN title VARCHAR(10) NULL AFTER last_name,
    ADD COLUMN date_of_birth DATE NULL AFTER last_name
    ";

    $pdo->exec($sql2);
    echo "Lecturers table updated successfully.\n";

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>