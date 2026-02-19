
<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Script to check and add Year 4 Semester 1 course registrations for all students
require_once '../config.php';

try {
  $db   = new Database();
  $conn = $db->getConnection();

  // Find the semester_id for Year 4 Semester 1 (should be semester_number=1, academic_year_id=4)
  $sem = $conn->query("SELECT id FROM semesters WHERE semester_number=1 AND academic_year_id=4 LIMIT 1");
  $semRow = $sem->fetch();
  if (!$semRow) {
    throw new Exception("Year 4 Semester 1 not found in semesters table.\n");
  }
  $semester_id = $semRow['id'];

  // For each student, add all Year 4 Semester 1 courses for their program if not already registered
  $sql = "
  INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date)
  SELECT s.id, c.id, :semester_id, NOW(), 'approved', 1, NOW()
  FROM students s
  JOIN courses c ON c.program_id = s.program_id
  WHERE c.level_year = 4 AND c.semester_offered = 1
  AND NOT EXISTS (
    SELECT 1 FROM course_registrations cr
    WHERE cr.student_id = s.id AND cr.course_id = c.id AND cr.semester_id = :semester_id
  )
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute(['semester_id' => $semester_id]);

  $count = $stmt->rowCount();
  echo "Added $count course registrations for Year 4 Semester 1.<br>\n";
} catch (Exception $e) {
  echo '<b>Error:</b> ' . $e->getMessage();
  if (method_exists($e, 'getTraceAsString')) {
    echo '<pre>' . $e->getTraceAsString() . '</pre>';
  }
}
