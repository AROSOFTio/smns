<?php
require_once 'config.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Find a student with published results and a transcript issuance record
    $stmt = $conn->prepare("
        SELECT
            s.student_id,
            s.first_name,
            s.last_name,
            ti.token,
            ti.verification_code
        FROM transcript_issuances ti
        JOIN students s ON ti.student_identifier = s.student_id
        JOIN results r ON s.id = r.student_id
        WHERE r.status = 'published'
        GROUP BY s.student_id, s.first_name, s.last_name, ti.token, ti.verification_code
        HAVING COUNT(r.id) > 0
        ORDER BY ti.issued_at DESC
        LIMIT 1
    ");
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        echo "Found student with published results and a transcript issuance record:\n";
        echo "Student ID: " . htmlspecialchars($student['student_id']) . "\n";
        echo "Name: " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "\n";
        echo "Verification Token: " . htmlspecialchars($student['token']) . "\n";
        echo "Verification Code: " . htmlspecialchars($student['verification_code']) . "\n";
    } else {
        // Fallback: Find any student with published results
        $stmt = $conn->prepare("
            SELECT s.student_id, s.first_name, s.last_name
            FROM students s
            JOIN results r ON s.id = r.student_id
            WHERE r.status = 'published'
            GROUP BY s.student_id, s.first_name, s.last_name
            HAVING COUNT(r.id) > 0
            LIMIT 1
        ");
        $stmt->execute();
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            echo "Found a student with published results (no issuance record found):\n";
            echo "Student ID: " . htmlspecialchars($student['student_id']) . "\n";
            echo "Name: " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "\n";
            echo "You can use the Student ID to verify. The verification code/hash will be shown on the page after you submit.";
        } else {
            echo "No student with published results found in the database.";
        }
    }

} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage();
}
