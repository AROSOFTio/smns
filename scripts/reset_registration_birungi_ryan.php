<?php
// Script to reset registration for Birungi Ryan
$host = 'localhost';
$dbname = 'smns';
$user = 'root';
$pass = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $student = $db->query("SELECT id FROM students WHERE first_name = 'Birungi' AND last_name = 'Ryan'")->fetch();
    if ($student) {
        $studentId = $student['id'];
        $deleted = $db->exec("DELETE FROM course_registrations WHERE student_id = $studentId AND status IN ('pending', 'approved')");
        echo "Registration reset for Birungi Ryan. Rows affected: $deleted\n";
    } else {
        echo "Student not found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
