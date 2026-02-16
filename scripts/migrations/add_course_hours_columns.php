<?php
// scripts/migrations/add_course_hours_columns.php
require_once __DIR__ . '/../../config.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "Running migration to add course hour columns to 'courses' table...\n";

    $columns = [
        'lecture_hours' => 'INT(11) NOT NULL DEFAULT 0',
        'tutorial_hours' => 'INT(11) NOT NULL DEFAULT 0',
        'practical_hours' => 'INT(11) NOT NULL DEFAULT 0'
    ];

    foreach ($columns as $column => $definition) {
        // Check if the column already exists
        $checkStmt = $conn->query("SHOW COLUMNS FROM `courses` LIKE '{$column}'");
        if ($checkStmt->fetch()) {
            echo "Column '{$column}' already exists. Skipping.\n";
        } else {
            // Add the column after 'credit_hours' for organization
            $conn->exec("ALTER TABLE `courses` ADD `{$column}` {$definition} AFTER `credit_hours`;");
            echo "Column '{$column}' added successfully.\n";
        }
    }

    echo "Migration completed successfully.\n";

} catch (Exception $e) {
    $errorMessage = "An error occurred: " . $e->getMessage();
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain', true, 500);
    }
    echo $errorMessage . "\n";
}
