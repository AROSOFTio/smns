<?php
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();
$columns = [
    'secondary_number' => 'VARCHAR(30) NULL AFTER primary_number',
    'nationality' => 'VARCHAR(100) NULL AFTER secondary_number',
    'school_college' => 'VARCHAR(100) NULL AFTER nationality',
    'department' => 'VARCHAR(100) NULL AFTER school_college',
    'intake' => 'VARCHAR(50) NULL AFTER department',
    'academic_status' => "ENUM('Active','Suspended','Withdrawn','Graduated') NULL AFTER intake",
    'discipline_status' => "ENUM('Good Standing','Probation','Suspended','Expelled') NULL AFTER academic_status",
    'financial_information' => 'TEXT NULL AFTER discipline_status',
];
foreach ($columns as $col => $def) {
    try {
        $conn->exec("ALTER TABLE students ADD COLUMN $col $def");
        echo "Column $col added to students table.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Column $col already exists.\n";
        } else {
            echo "Error adding $col: " . $e->getMessage() . "\n";
        }
    }
}
