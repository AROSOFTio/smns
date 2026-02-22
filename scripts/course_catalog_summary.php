<?php
require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    echo "Run from CLI.\n";
    exit(1);
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "Courses by year/semester\n";
    foreach ($conn->query("SELECT level_year, semester_offered, COUNT(*) AS cnt FROM courses GROUP BY level_year, semester_offered ORDER BY level_year, semester_offered") as $row) {
        echo "Y{$row['level_year']} S{$row['semester_offered']} => {$row['cnt']}\n";
    }

    echo "\nCatalog\n";
    foreach ($conn->query("SELECT course_code, course_name, level_year, semester_offered FROM courses ORDER BY level_year, semester_offered, course_code") as $row) {
        echo "{$row['course_code']}\tY{$row['level_year']}S{$row['semester_offered']}\t{$row['course_name']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

