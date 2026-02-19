<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // List all Year 4 Semester 1 courses
    $courses = $conn->query("SELECT id, course_code, course_name, program_id FROM courses WHERE level_year=4 AND semester_offered=1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($courses)) {
        echo '<b>No Year 4 Semester 1 courses found in courses table.</b>';
    } else {
        echo '<b>Year 4 Semester 1 courses in courses table:</b><br><table border=1 cellpadding=4><tr><th>ID</th><th>Code</th><th>Name</th><th>Program ID</th></tr>';
        foreach ($courses as $c) {
            echo '<tr><td>' . $c['id'] . '</td><td>' . htmlspecialchars($c['course_code']) . '</td><td>' . htmlspecialchars($c['course_name']) . '</td><td>' . $c['program_id'] . '</td></tr>';
        }
        echo '</table>';
    }

    // List all students and their program_id
    $students = $conn->query("SELECT id, student_id, first_name, last_name, program_id FROM students")->fetchAll(PDO::FETCH_ASSOC);
    echo '<br><b>Students and their program_id:</b><br><table border=1 cellpadding=4><tr><th>ID</th><th>Student ID</th><th>Name</th><th>Program ID</th></tr>';
    foreach ($students as $s) {
        echo '<tr><td>' . $s['id'] . '</td><td>' . htmlspecialchars($s['student_id']) . '</td><td>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . '</td><td>' . $s['program_id'] . '</td></tr>';
    }
    echo '</table>';

    // For each student, check if they have course_registrations for Year 4 Semester 1
    $sem = $conn->query("SELECT id FROM semesters WHERE semester_number=1 AND academic_year_id=4 LIMIT 1");
    $semRow = $sem->fetch();
    if ($semRow) {
        $semester_id = $semRow['id'];
        echo '<br><b>Missing Year 4 Semester 1 registrations per student:</b><br>';
        foreach ($students as $s) {
            $missing = $conn->prepare("SELECT c.course_code, c.course_name FROM courses c WHERE c.level_year=4 AND c.semester_offered=1 AND c.program_id=? AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id=? AND cr.course_id=c.id AND cr.semester_id=?)");
            $missing->execute([$s['program_id'], $s['id'], $semester_id]);
            $missingCourses = $missing->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($missingCourses)) {
                echo '<b>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . ' (' . htmlspecialchars($s['student_id']) . '):</b> Missing:<ul>';
                foreach ($missingCourses as $mc) {
                    echo '<li>' . htmlspecialchars($mc['course_code']) . ' - ' . htmlspecialchars($mc['course_name']) . '</li>';
                }
                echo '</ul>';
            }
        }
    }

} catch (Exception $e) {
    echo '<b>Error:</b> ' . $e->getMessage();
    if (method_exists($e, 'getTraceAsString')) {
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
}
