<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // Define the required Year 4 Semester 1 courses for program_id=7
    $requiredCourses = [
        ['course_code' => 'TBB4119', 'course_name' => 'NT: Book of Revelation'],
        ['course_code' => 'TDT4129', 'course_name' => 'Dogmatic Theology: Mariology'],
        ['course_code' => 'TJC4137', 'course_name' => 'Canon Law: Sanctifying Office of the Church'],
        ['course_code' => 'TDT4137', 'course_name' => 'Dogmatic Theology: Sacraments II'],
        ['course_code' => 'TLI4167', 'course_name' => 'Liturgy: Sacraments of healing: Reconciliation and Anointing of the Sick'],
        ['course_code' => 'TPT4187', 'course_name' => 'PastoralTheology: Human Promotion and Self - reliance'],
        ['course_code' => 'TST4157', 'course_name' => 'Spiritual of the Laity, Consecrated Life and some Challenges in life'],
    ];
    $program_id = 7;
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

    echo '<br>All required Year 4 Semester 1 courses for program 7 are now present.';

} catch (Exception $e) {
    echo '<b>Error:</b> ' . $e->getMessage();
    if (method_exists($e, 'getTraceAsString')) {
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
}
