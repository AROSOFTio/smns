<?php
// scripts/truncate_course_data.php
require_once __DIR__ . '/../config.php';

// Allow being called from browser or CLI for quick checks
try {
    $db = new Database();
    $conn = $db->getConnection();

    // Disable foreign key checks to avoid errors
    $conn->exec('SET FOREIGN_KEY_CHECKS=0;');

    // Truncate tables
    $conn->exec('TRUNCATE TABLE course_registrations;');
    $conn->exec('TRUNCATE TABLE courses;');

    // Re-enable foreign key checks
    $conn->exec('SET FOREIGN_KEY_CHECKS=1;');

    $message = "Successfully truncated 'course_registrations' and 'courses' tables.";
    if (php_sapi_name() === 'cli') {
        echo $message . "\n";
    } else {
        echo "<h1>Success</h1><p>{$message}</p>";
    }
    exit;
} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain', true, 500);
    }
    echo $error_message;
    exit;
}
