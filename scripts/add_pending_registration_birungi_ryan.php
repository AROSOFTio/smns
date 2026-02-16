<?php
// Script to add a pending registration for Birungi Ryan
$host = 'localhost';
$dbname = 'smns';
$user = 'root';
$pass = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $student = $db->query("SELECT id FROM students WHERE first_name = 'Birungi' AND last_name = 'Ryan'")->fetch();
    if ($student) {
        $semester = $db->query("SELECT id FROM semesters ORDER BY id DESC LIMIT 1")->fetch();
        if ($semester) {
            $db->exec("INSERT INTO course_registrations (student_id, semester_id, status, created_at) VALUES ({$student['id']}, {$semester['id']}, 'pending', NOW())");
            echo "Birungi Ryan registration request added for approval.\n";
        } else {
            echo "No semester found.\n";
        }
    } else {
        echo "Student not found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
