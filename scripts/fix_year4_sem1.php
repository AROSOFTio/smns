<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // 1. Check if Year 4 Semester 1 exists in semesters table
    $sem = $conn->query("SELECT id FROM semesters WHERE semester_number=1 AND academic_year_id=4 LIMIT 1");
    $semRow = $sem->fetch();
    if (!$semRow) {
        // If not found, create it (set start_date as today, adjust as needed)
        $conn->exec("INSERT INTO semesters (semester_name, semester_number, academic_year_id, start_date) VALUES ('Semester 1', 1, 4, NOW())");
        $semester_id = $conn->lastInsertId();
        echo "Created Year 4 Semester 1 in semesters table.<br>\n";
    } else {
        $semester_id = $semRow['id'];
        echo "Year 4 Semester 1 already exists in semesters table.<br>\n";
    }

    // 2. Check if there are courses for Year 4 Semester 1
    $courses = $conn->query("SELECT id FROM courses WHERE level_year=4 AND semester_offered=1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($courses)) {
        echo '<b>Error:</b> No courses found for Year 4 Semester 1. Please add courses to the courses table for level_year=4 and semester_offered=1.';
        exit;
    }


    // 3. Add missing course registrations for all students in program 7 for the 7 required courses
    $requiredCourses = [
        'TBB4119', 'TDT4129', 'TJC4137', 'TDT4137', 'TLI4167', 'TPT4187', 'TST4157'
    ];
    $program_id = 7;
    $added = 0;
    // Get all students in program 7
    $students = $conn->query("SELECT id FROM students WHERE program_id=7")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($students as $s) {
        foreach ($requiredCourses as $cc) {
            $course = $conn->prepare("SELECT id FROM courses WHERE course_code=? AND program_id=? AND level_year=4 AND semester_offered=1 LIMIT 1");
            $course->execute([$cc, $program_id]);
            $c = $course->fetch();
            if ($c) {
                // Check if registration exists
                $exists = $conn->prepare("SELECT 1 FROM course_registrations WHERE student_id=? AND course_id=? AND semester_id=?");
                $exists->execute([$s['id'], $c['id'], $semester_id]);
                if (!$exists->fetch()) {
                    $ins = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date) VALUES (?, ?, ?, NOW(), 'approved', 1, NOW())");
                    $ins->execute([$s['id'], $c['id'], $semester_id]);
                    $added++;
                }
            }
        }
    }
    echo "Added $added course registrations for Year 4 Semester 1 for program 7.<br>\n";

} catch (Exception $e) {
    echo '<b>Error:</b> ' . $e->getMessage();
    if (method_exists($e, 'getTraceAsString')) {
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
}
