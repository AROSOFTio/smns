<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // Get Year 4 Semester 1 semester_id
    $sem = $conn->query("SELECT id FROM semesters WHERE semester_number=1 AND academic_year_id=4 LIMIT 1");
    $semRow = $sem->fetch();
    if (!$semRow) {
        die('Year 4 Semester 1 not found in semesters table.');
    }
    $semester_id = $semRow['id'];

    // List all students in program 7
    $students = $conn->query("SELECT id, student_id, first_name, last_name FROM students WHERE program_id=7")->fetchAll(PDO::FETCH_ASSOC);
    echo '<b>Students in program 7:</b><br><ul>';
    foreach ($students as $s) {
        echo '<li>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . ' (' . htmlspecialchars($s['student_id']) . ')</li>';
    }
    echo '</ul>';

    // List all course_registrations for these students for Year 4 Semester 1
    $q = $conn->prepare("SELECT cr.student_id, cr.course_id, c.course_code, c.course_name FROM course_registrations cr JOIN courses c ON cr.course_id=c.id WHERE cr.semester_id=? AND c.level_year=4 AND c.semester_offered=1 AND c.program_id=7");
    $q->execute([$semester_id]);
    $registrations = $q->fetchAll(PDO::FETCH_ASSOC);
    echo '<b>Existing Year 4 Semester 1 registrations for program 7:</b><br>';
    if (empty($registrations)) {
        echo 'None found.';
    } else {
        echo '<table border=1 cellpadding=4><tr><th>Student ID</th><th>Course Code</th><th>Course Name</th></tr>';
        foreach ($registrations as $r) {
            echo '<tr><td>' . $r['student_id'] . '</td><td>' . htmlspecialchars($r['course_code']) . '</td><td>' . htmlspecialchars($r['course_name']) . '</td></tr>';
        }
        echo '</table>';
    }

    // For each student, show missing Year 4 Semester 1 courses
    echo '<br><b>Missing Year 4 Semester 1 courses per student:</b><br>';
    foreach ($students as $s) {
        $missing = $conn->prepare("SELECT c.course_code, c.course_name FROM courses c WHERE c.level_year=4 AND c.semester_offered=1 AND c.program_id=7 AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id=? AND cr.course_id=c.id AND cr.semester_id=?)");
        $missing->execute([$s['id'], $semester_id]);
        $missingCourses = $missing->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($missingCourses)) {
            echo '<b>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . ' (' . htmlspecialchars($s['student_id']) . '):</b> Missing:<ul>';
            foreach ($missingCourses as $mc) {
                echo '<li>' . htmlspecialchars($mc['course_code']) . ' - ' . htmlspecialchars($mc['course_name']) . '</li>';
            }
            echo '</ul>';
        }
    }

} catch (Exception $e) {
    echo '<b>Error:</b> ' . $e->getMessage();
    if (method_exists($e, 'getTraceAsString')) {
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
}
