<?php
// Script to add pending registration for Birungi Ryan for all courses in the latest semester
$host = 'localhost';
$dbname = 'smns';
$user = 'root';
$pass = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $student = $db->query("SELECT id FROM students WHERE first_name = 'Birungi' AND last_name = 'Ryan'")->fetch();
    if ($student) {
        $semester = $db->query("SELECT semester_name FROM semesters ORDER BY id DESC LIMIT 1")->fetch();
        if ($semester) {
            $courses = $db->query("SELECT id FROM courses WHERE semester_offered = '{$semester['semester_name']}'")->fetchAll();
            if ($courses) {
                $count = 0;
                foreach ($courses as $course) {
                    $db->exec("INSERT INTO course_registrations (student_id, course_id, status, created_at) VALUES ({$student['id']}, {$course['id']}, 'pending', NOW())");
                    $count++;
                }
                echo "Added $count pending registrations for Birungi Ryan.\n";
            } else {
                echo "No courses found for the latest semester.\n";
            }
        } else {
            echo "No semester found.\n";
        }
    } else {
        echo "Student not found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
