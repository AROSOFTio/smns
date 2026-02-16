<?php
// Auto-recreate missing semester registration for any student if not found
require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();

function ensureSemesterRegistration($studentId, $semesterId, $yearOfStudy = 1) {
    global $conn;
    $check = $conn->prepare("SELECT id FROM semester_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $check->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
    if (!$check->fetch()) {
        $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :status, :request_date, :created_at, :updated_at)");
            $stmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId,
                'status' => 'pending',
                'request_date' => $now,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        echo "Created missing registration for student_id $studentId, semester_id $semesterId\n";
    } else {
        echo "Registration already exists for student_id $studentId, semester_id $semesterId\n";
    }
}

// Example: Auto-fix for Kemi (or any student)
$studentName = 'Kemi';
$studentStmt = $conn->prepare("SELECT id FROM students WHERE first_name = :name OR last_name = :name LIMIT 1");
$studentStmt->execute(['name' => $studentName]);
$student = $studentStmt->fetch();
if ($student) {
    // Use latest semester
    $sem = $conn->query("SELECT id FROM semesters ORDER BY start_date DESC LIMIT 1")->fetch();
    if ($sem) {
        ensureSemesterRegistration($student['id'], $sem['id']);
    } else {
        echo "No semester found.\n";
    }
} else {
    echo "Student named $studentName not found.\n";
}
