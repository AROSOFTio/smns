<?php
// scripts/migrations/add_academic_year_to_courses.php
require_once __DIR__ . '/../../config.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "Running migration to add 'academic_year_id' to 'courses' table...\n";

    // Check if the column already exists
    $checkStmt = $conn->query("SHOW COLUMNS FROM `courses` LIKE 'academic_year_id'");
    if ($checkStmt->fetch()) {
        echo "Column 'academic_year_id' already exists. Skipping.\n";
    } else {
        // Add the column
        $conn->exec("ALTER TABLE `courses` ADD `academic_year_id` INT(11) NULL DEFAULT NULL AFTER `program_id`;");
        echo "Column 'academic_year_id' added successfully.\n";
    }

    // Check if foreign key exists
    $fkCheckStmt = $conn->prepare("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'courses' 
        AND COLUMN_NAME = 'academic_year_id' 
        AND REFERENCED_TABLE_NAME = 'academic_years';
    ");
    $fkCheckStmt->execute();

    if ($fkCheckStmt->fetch()) {
        echo "Foreign key for 'academic_year_id' already exists. Skipping.\n";
    } else {
        // Add the foreign key constraint
        $conn->exec("
            ALTER TABLE `courses` 
            ADD CONSTRAINT `fk_courses_academic_year`
            FOREIGN KEY (`academic_year_id`) 
            REFERENCES `academic_years`(`id`) 
            ON DELETE SET NULL 
            ON UPDATE CASCADE;
        ");
        echo "Foreign key 'fk_courses_academic_year' added successfully.\n";
    }

    echo "Migration completed.\n";

} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
}
