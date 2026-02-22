<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // Define the required Year 4 Semester 1 courses for BTH program
    $requiredCourses = [
        ['course_code' => 'THE4101', 'course_name' => 'Advanced Systematic Theology'],
        ['course_code' => 'MIN4102', 'course_name' => 'Church Planting and Growth Strategy'],
        ['course_code' => 'PST4103', 'course_name' => 'Advanced Pastoral Counseling'],
        ['course_code' => 'MIS4104', 'course_name' => 'Global Mission Strategy and Leadership'],
        ['course_code' => 'RES4105', 'course_name' => 'Theological Research Methods'],
        ['course_code' => 'BIB4106', 'course_name' => 'Biblical Hermeneutics and Application'],
    ];
    $program_id = (int)($conn->query("SELECT id FROM programs WHERE UPPER(program_code) = 'BTH' LIMIT 1")->fetchColumn() ?: 1);
    $level_year = 4;
    $semester_offered = 1;

    // Check and insert missing courses
    foreach ($requiredCourses as $rc) {
        $stmt = $conn->prepare("SELECT id FROM courses WHERE course_code=? AND program_id=?");
        $stmt->execute([$rc['course_code'], $program_id]);
        $exists = $stmt->fetch();
        if (!$exists) {
            $insert = $conn->prepare("INSERT INTO courses (course_code, course_name, program_id, level_year, semester_offered) VALUES (?, ?, ?, ?, ?)");
            $insert->execute([$rc['course_code'], $rc['course_name'], $program_id, $level_year, $semester_offered]);
            echo 'Inserted course: ' . htmlspecialchars($rc['course_code']) . ' - ' . htmlspecialchars($rc['course_name']) . '<br>';
        } else {
            echo 'Course already exists: ' . htmlspecialchars($rc['course_code']) . ' - ' . htmlspecialchars($rc['course_name']) . '<br>';
        }
    }

    echo '<br>All required Year 4 Semester 1 courses for program ' . (int)$program_id . ' are now present.';

} catch (Exception $e) {
    echo '<b>Error:</b> ' . $e->getMessage();
    if (method_exists($e, 'getTraceAsString')) {
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
}
