<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $conn->beginTransaction();

    $completionYear = (int)($conn->query("
        SELECT MAX(CAST(RIGHT(ay.year_name, 4) AS UNSIGNED))
        FROM students s
        INNER JOIN results r ON r.student_id = s.id
        INNER JOIN semesters sem ON sem.id = r.semester_id
        INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
        WHERE s.status = 'graduated'
    ")->fetchColumn() ?: 0);
    if ($completionYear <= 0) {
        throw new RuntimeException('Unable to resolve the latest completion year for graduated students.');
    }

    $studentsStmt = $conn->query("
        SELECT
            s.id,
            s.student_id,
            s.entry_year,
            s.intake,
            COALESCE(NULLIF(p.duration_years, 0), NULLIF(s.level_year, 0), 1) AS duration_years
        FROM students s
        LEFT JOIN programs p ON p.id = s.program_id
        WHERE s.status = 'graduated'
        ORDER BY s.id ASC
    ");
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $checkStmt = $conn->prepare('SELECT id FROM students WHERE student_id = :student_id LIMIT 1');
    $updateStmt = $conn->prepare("
        UPDATE students
        SET student_id = :new_student_id,
            entry_year = :entry_year,
            intake = :intake
        WHERE id = :id
    ");

    $summary = [
        'students_updated' => 0,
    ];

    foreach ($students as $student) {
        $durationYears = max(1, min(4, (int)($student['duration_years'] ?? 1)));
        $suffix = trim((string)substr((string)$student['student_id'], -3));
        if ($suffix === '' || !ctype_digit($suffix)) {
            throw new RuntimeException('Invalid student_id suffix for student #' . (int)$student['id']);
        }

        $entryYear = ($completionYear - $durationYears) + 1;
        $newStudentId = $entryYear . '-STU-' . $suffix;
        $newIntake = 'August ' . $entryYear;

        $checkStmt->execute(['student_id' => $newStudentId]);
        $existingId = (int)($checkStmt->fetchColumn() ?: 0);
        if ($existingId > 0 && $existingId !== (int)$student['id']) {
            throw new RuntimeException('Target student_id collision for ' . $newStudentId);
        }

        if (
            (string)$student['student_id'] === $newStudentId
            && (int)($student['entry_year'] ?? 0) === $entryYear
            && trim((string)($student['intake'] ?? '')) === $newIntake
        ) {
            continue;
        }

        $updateStmt->execute([
            'new_student_id' => $newStudentId,
            'entry_year' => $entryYear,
            'intake' => $newIntake,
            'id' => (int)$student['id'],
        ]);
        $summary['students_updated'] += $updateStmt->rowCount();
    }

    $conn->commit();

    foreach ($summary as $key => $value) {
        echo $key . ': ' . $value . PHP_EOL;
    }
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
