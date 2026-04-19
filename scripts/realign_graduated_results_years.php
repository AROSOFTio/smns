<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ensureAcademicYear(PDO $conn, string $yearName, string $startDate, string $endDate): int
{
    $stmt = $conn->prepare('SELECT id FROM academic_years WHERE year_name = :year_name LIMIT 1');
    $stmt->execute(['year_name' => $yearName]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $ins = $conn->prepare("
        INSERT INTO academic_years (year_name, start_date, end_date, status, created_at)
        VALUES (:year_name, :start_date, :end_date, 'inactive', NOW())
    ");
    $ins->execute([
        'year_name' => $yearName,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    return (int)$conn->lastInsertId();
}

function ensureSemester(PDO $conn, int $academicYearId, int $semesterNumber, string $startDate, string $endDate): int
{
    $stmt = $conn->prepare('
        SELECT id
        FROM semesters
        WHERE academic_year_id = :academic_year_id
          AND semester_number = :semester_number
        ORDER BY id ASC
        LIMIT 1
    ');
    $stmt->execute([
        'academic_year_id' => $academicYearId,
        'semester_number' => $semesterNumber,
    ]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $ins = $conn->prepare("
        INSERT INTO semesters (academic_year_id, semester_number, semester_name, start_date, end_date, status, created_at)
        VALUES (:academic_year_id, :semester_number, :semester_name, :start_date, :end_date, 'inactive', NOW())
    ");
    $ins->execute([
        'academic_year_id' => $academicYearId,
        'semester_number' => $semesterNumber,
        'semester_name' => 'Semester ' . $semesterNumber,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    return (int)$conn->lastInsertId();
}

function loadSemesterMap(PDO $conn, array $yearNames): array
{
    $map = [];
    $stmt = $conn->prepare("
        SELECT sem.id, ay.year_name, sem.semester_number
        FROM semesters sem
        INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
        WHERE ay.year_name = :year_name
          AND sem.semester_number = :semester_number
        ORDER BY sem.id ASC
        LIMIT 1
    ");

    foreach ($yearNames as $yearName) {
        for ($semesterNumber = 1; $semesterNumber <= 2; $semesterNumber++) {
            $stmt->execute([
                'year_name' => $yearName,
                'semester_number' => $semesterNumber,
            ]);
            $semesterId = (int)($stmt->fetchColumn() ?: 0);
            if ($semesterId <= 0) {
                throw new RuntimeException("Missing semester mapping for {$yearName} semester {$semesterNumber}");
            }
            $map[$yearName][$semesterNumber] = $semesterId;
        }
    }

    return $map;
}

try {
    $conn->beginTransaction();

    $yearDefinitions = [
        '2022/2023' => ['start' => '2022-08-01', 'end' => '2023-06-30'],
        '2023/2024' => ['start' => '2023-08-01', 'end' => '2024-06-30'],
        '2024/2025' => ['start' => '2024-08-01', 'end' => '2025-06-30'],
        '2025/2026' => ['start' => '2025-08-01', 'end' => '2026-06-30'],
    ];

    $academicYearIds = [];
    foreach ($yearDefinitions as $yearName => $def) {
        $academicYearIds[$yearName] = ensureAcademicYear($conn, $yearName, $def['start'], $def['end']);
    }

    // Match the existing semester date pattern used elsewhere in the system.
    ensureSemester($conn, $academicYearIds['2022/2023'], 1, '2023-02-10', '2023-07-30');
    ensureSemester($conn, $academicYearIds['2022/2023'], 2, '2022-08-10', '2022-12-12');
    ensureSemester($conn, $academicYearIds['2023/2024'], 1, '2024-02-10', '2024-07-30');
    ensureSemester($conn, $academicYearIds['2023/2024'], 2, '2023-08-10', '2023-12-12');
    ensureSemester($conn, $academicYearIds['2024/2025'], 1, '2025-02-10', '2025-07-30');
    ensureSemester($conn, $academicYearIds['2024/2025'], 2, '2024-08-10', '2024-12-12');
    ensureSemester($conn, $academicYearIds['2025/2026'], 1, '2026-02-13', '2026-07-30');
    ensureSemester($conn, $academicYearIds['2025/2026'], 2, '2025-08-03', '2025-12-12');

    $targetSemesterMap = loadSemesterMap($conn, array_keys($yearDefinitions));

    $studentsStmt = $conn->query("
        SELECT
            s.id,
            s.student_id,
            COALESCE(NULLIF(p.duration_years, 0), NULLIF(s.level_year, 0), 1) AS duration_years
        FROM students s
        LEFT JOIN programs p ON p.id = s.program_id
        WHERE s.status = 'graduated'
        ORDER BY s.id ASC
    ");
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $termStmt = $conn->prepare("
        SELECT semester_id, academic_year, semester_number
        FROM (
            SELECT DISTINCT
                sem.id AS semester_id,
                ay.year_name AS academic_year,
                ay.start_date AS ay_start_date,
                sem.semester_number
            FROM results r
            INNER JOIN semesters sem ON sem.id = r.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            WHERE r.student_id = :student_id_results

            UNION

            SELECT DISTINCT
                sem.id AS semester_id,
                ay.year_name AS academic_year,
                ay.start_date AS ay_start_date,
                sem.semester_number
            FROM course_registrations cr
            INNER JOIN semesters sem ON sem.id = cr.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            WHERE cr.student_id = :student_id_course_regs

            UNION

            SELECT DISTINCT
                sem.id AS semester_id,
                ay.year_name AS academic_year,
                ay.start_date AS ay_start_date,
                sem.semester_number
            FROM semester_registrations sr
            INNER JOIN semesters sem ON sem.id = sr.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            WHERE sr.student_id = :student_id_semester_regs
        ) terms
        ORDER BY ay_start_date ASC, semester_number ASC, semester_id ASC
    ");

    $updateResults = $conn->prepare('UPDATE results SET semester_id = :new_semester_id WHERE student_id = :student_id AND semester_id = :old_semester_id');
    $updateCourseRegs = $conn->prepare('UPDATE course_registrations SET semester_id = :new_semester_id WHERE student_id = :student_id AND semester_id = :old_semester_id');
    $updateSemesterRegs = $conn->prepare('UPDATE semester_registrations SET semester_id = :new_semester_id WHERE student_id = :student_id AND semester_id = :old_semester_id');

    $summary = [
        'students_processed' => 0,
        'results_updates' => 0,
        'course_registration_updates' => 0,
        'semester_registration_updates' => 0,
    ];

    foreach ($students as $student) {
        $studentId = (int)$student['id'];
        $durationYears = max(1, (int)$student['duration_years']);
        $durationYears = min($durationYears, 4);

        $termStmt->execute([
            'student_id_results' => $studentId,
            'student_id_course_regs' => $studentId,
            'student_id_semester_regs' => $studentId,
        ]);
        $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($terms)) {
            continue;
        }

        $targetYears = array_slice(['2022/2023', '2023/2024', '2024/2025', '2025/2026'], 4 - $durationYears);
        $expectedTerms = count($targetYears) * 2;
        if (count($terms) > $expectedTerms) {
            throw new RuntimeException('Student ' . $student['student_id'] . ' has more term buckets than expected for duration ' . $durationYears);
        }

        $studentChanged = false;
        foreach ($terms as $index => $term) {
            $targetYear = $targetYears[(int)floor($index / 2)] ?? null;
            $targetSemesterNumber = ($index % 2) + 1;
            if ($targetYear === null) {
                throw new RuntimeException('No target year available for student ' . $student['student_id']);
            }

            $oldSemesterId = (int)$term['semester_id'];
            $newSemesterId = (int)($targetSemesterMap[$targetYear][$targetSemesterNumber] ?? 0);
            if ($newSemesterId <= 0 || $oldSemesterId === $newSemesterId) {
                continue;
            }

            $params = [
                'student_id' => $studentId,
                'old_semester_id' => $oldSemesterId,
                'new_semester_id' => $newSemesterId,
            ];

            $updateResults->execute($params);
            $summary['results_updates'] += $updateResults->rowCount();

            $updateCourseRegs->execute($params);
            $summary['course_registration_updates'] += $updateCourseRegs->rowCount();

            $updateSemesterRegs->execute($params);
            $summary['semester_registration_updates'] += $updateSemesterRegs->rowCount();

            $studentChanged = true;
        }

        if ($studentChanged) {
            $summary['students_processed']++;
        }
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
