<?php
require_once __DIR__ . '/../config.php';

function fetchScalar(PDO $conn, string $sql, array $params = [])
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function buildBackfillMarks(int $studentIndex, int $courseIndex, int $yearOfStudy, int $semesterNumber): array
{
    $base = 63 + (($studentIndex + ($courseIndex * 3) + ($yearOfStudy * 4) + $semesterNumber) % 23);
    $assignment = 10 + (($studentIndex + $courseIndex) % 6);
    $test = 8 + (($studentIndex + $yearOfStudy + $semesterNumber) % 5);
    $midterm = 12 + (($courseIndex + $yearOfStudy) % 6);
    $participation = 3 + (($studentIndex + $semesterNumber) % 3);
    $finalExam = $base - ($assignment + $test + $midterm + $participation);

    if ($finalExam < 25) {
        $finalExam = 25;
        $base = $assignment + $test + $midterm + $participation + $finalExam;
    }

    if ($finalExam > 45) {
        $extra = $finalExam - 45;
        $assignment += min(3, $extra);
        $remaining = $extra - min(3, $extra);
        $test += min(2, max(0, $remaining));
        $remaining -= min(2, max(0, $remaining));
        $midterm += max(0, $remaining);
        $finalExam = 45;
        $base = $assignment + $test + $midterm + $participation + $finalExam;
    }

    return [
        'assignment_marks' => $assignment,
        'test_marks' => $test,
        'midterm_marks' => $midterm,
        'final_exam_marks' => $finalExam,
        'participation_marks' => $participation,
    ];
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    $programId = (int)fetchScalar(
        $conn,
        "SELECT id FROM programs WHERE UPPER(program_code) = 'FVT' LIMIT 1"
    );
    if ($programId <= 0) {
        throw new RuntimeException('FVT programme was not found.');
    }

    $adminId = (int)fetchScalar($conn, "SELECT id FROM admins ORDER BY id ASC LIMIT 1");
    $adminUserId = (int)fetchScalar(
        $conn,
        "SELECT user_id FROM admins WHERE id = :id LIMIT 1",
        ['id' => $adminId]
    );
    if ($adminId <= 0 || $adminUserId <= 0) {
        throw new RuntimeException('An admin account is required to approve transcript backfill records.');
    }

    $semesterRows = $conn->query("
        SELECT s.id, s.semester_number, ay.id AS academic_year_id, ay.year_name, ay.start_date
        FROM semesters s
        INNER JOIN academic_years ay ON ay.id = s.academic_year_id
        WHERE ay.year_name IN ('2025/2026', '2026/2027')
        ORDER BY ay.start_date ASC, s.semester_number ASC, s.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $semesterPlan = [];
    foreach ($semesterRows as $row) {
        $yearName = (string)$row['year_name'];
        $semesterNumber = (int)$row['semester_number'];
        if (!isset($semesterPlan[$yearName])) {
            $semesterPlan[$yearName] = [];
        }
        $semesterPlan[$yearName][$semesterNumber] = (int)$row['id'];
    }

    $targetSlots = [
        ['year_of_study' => 1, 'semester_number' => 1, 'year_name' => '2025/2026'],
        ['year_of_study' => 1, 'semester_number' => 2, 'year_name' => '2025/2026'],
        ['year_of_study' => 2, 'semester_number' => 1, 'year_name' => '2026/2027'],
    ];

    foreach ($targetSlots as &$slot) {
        $semesterId = (int)($semesterPlan[$slot['year_name']][$slot['semester_number']] ?? 0);
        if ($semesterId <= 0) {
            throw new RuntimeException(
                'Missing semester setup for ' . $slot['year_name'] . ' semester ' . $slot['semester_number'] . '.'
            );
        }
        $slot['semester_id'] = $semesterId;
    }
    unset($slot);

    $courseRows = $conn->prepare("
        SELECT id, course_code, level_year, semester_offered
        FROM courses
        WHERE program_id = :program_id
          AND (
              (level_year = 1 AND semester_offered IN (1, 2))
              OR (level_year = 2 AND semester_offered = 1)
          )
        ORDER BY level_year ASC, semester_offered ASC, course_code ASC
    ");
    $courseRows->execute(['program_id' => $programId]);
    $courses = $courseRows->fetchAll(PDO::FETCH_ASSOC);
    if (!$courses) {
        throw new RuntimeException('No FVT course catalog rows were found for the target semesters.');
    }

    $coursesBySlot = [];
    foreach ($courses as $course) {
        $slotKey = (int)$course['level_year'] . '-' . (int)$course['semester_offered'];
        if (!isset($coursesBySlot[$slotKey])) {
            $coursesBySlot[$slotKey] = [];
        }
        $coursesBySlot[$slotKey][] = $course;
    }

    $lecturerIds = $conn->query("SELECT id FROM lecturers WHERE status = 'active' ORDER BY id ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$lecturerIds) {
        throw new RuntimeException('No active lecturers are available for result attribution.');
    }

    $assignmentStmt = $conn->prepare("
        SELECT course_id, semester_id, lecturer_id
        FROM course_assignments
        WHERE semester_id IN (:sem1, :sem2, :sem3)
    ");
    $assignmentStmt->execute([
        'sem1' => $targetSlots[0]['semester_id'],
        'sem2' => $targetSlots[1]['semester_id'],
        'sem3' => $targetSlots[2]['semester_id'],
    ]);
    $assignmentMap = [];
    foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $assignmentMap[(int)$assignment['semester_id'] . ':' . (int)$assignment['course_id']] = (int)$assignment['lecturer_id'];
    }

    $studentsStmt = $conn->prepare("
        SELECT id, user_id, student_id, first_name, middle_name, last_name
        FROM students
        WHERE student_id LIKE '2026-STU-%'
          AND program_id = :program_id
        ORDER BY student_id ASC
    ");
    $studentsStmt->execute(['program_id' => $programId]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$students) {
        throw new RuntimeException('No 2026-STU FVT students were found for backfill.');
    }

    $semesterRegistrationStmt = $conn->prepare("
        INSERT INTO semester_registrations (
            student_id, semester_id, year_of_study, status, request_date, approved_by, approval_date
        ) VALUES (
            :student_id, :semester_id, :year_of_study, 'approved', :request_date, :approved_by, :approval_date
        )
        ON DUPLICATE KEY UPDATE
            year_of_study = VALUES(year_of_study),
            status = VALUES(status),
            approved_by = VALUES(approved_by),
            approval_date = VALUES(approval_date)
    ");

    $courseRegistrationStmt = $conn->prepare("
        INSERT INTO course_registrations (
            student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, remarks
        ) VALUES (
            :student_id, :course_id, :semester_id, :registration_date, 'approved', :approved_by, :approved_date, :remarks
        )
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            approved_by = VALUES(approved_by),
            approved_date = VALUES(approved_date),
            remarks = VALUES(remarks)
    ");

    $resultStmt = $conn->prepare("
        INSERT INTO results (
            student_id, course_id, semester_id, assignment_marks, test_marks, midterm_marks, final_exam_marks,
            participation_marks, status, entered_by, approved_by, submitted_date, approved_date, published_date, remarks
        ) VALUES (
            :student_id, :course_id, :semester_id, :assignment_marks, :test_marks, :midterm_marks, :final_exam_marks,
            :participation_marks, 'published', :entered_by, :approved_by, :submitted_date, :approved_date, :published_date, :remarks
        )
        ON DUPLICATE KEY UPDATE
            assignment_marks = VALUES(assignment_marks),
            test_marks = VALUES(test_marks),
            midterm_marks = VALUES(midterm_marks),
            final_exam_marks = VALUES(final_exam_marks),
            participation_marks = VALUES(participation_marks),
            status = VALUES(status),
            entered_by = VALUES(entered_by),
            approved_by = VALUES(approved_by),
            submitted_date = VALUES(submitted_date),
            approved_date = VALUES(approved_date),
            published_date = VALUES(published_date),
            remarks = VALUES(remarks)
    ");

    $studentUpdateStmt = $conn->prepare("
        UPDATE students
        SET study_year = 2,
            year_of_study = 2,
            level_year = 2,
            current_semester = :current_semester,
            entry_year = COALESCE(entry_year, 2025),
            entry_semester_id = COALESCE(entry_semester_id, :entry_semester_id),
            academic_status = CASE
                WHEN academic_status IS NULL OR academic_status = '' OR LOWER(academic_status) = 'pending' THEN 'Active'
                ELSE academic_status
            END,
            status = CASE
                WHEN status IS NULL OR status = '' THEN 'active'
                ELSE status
            END
        WHERE id = :id
    ");

    $deleteLegacyResultsStmt = $conn->prepare("
        DELETE r
        FROM results r
        INNER JOIN courses c ON c.id = r.course_id
        WHERE r.student_id = :student_id
          AND r.semester_id IN (:sem1, :sem2, :sem3)
          AND c.course_code NOT LIKE 'FVT%'
    ");

    $deleteLegacyRegistrationsStmt = $conn->prepare("
        DELETE cr
        FROM course_registrations cr
        INNER JOIN courses c ON c.id = cr.course_id
        WHERE cr.student_id = :student_id
          AND cr.semester_id IN (:sem1, :sem2, :sem3)
          AND c.course_code NOT LIKE 'FVT%'
    ");

    $stats = [
        'students_processed' => 0,
        'semester_registrations' => 0,
        'course_registrations' => 0,
        'results' => 0,
        'legacy_rows_removed' => 0,
    ];

    foreach ($students as $studentIndex => $student) {
        $studentId = (int)$student['id'];

        $deleteParams = [
            'student_id' => $studentId,
            'sem1' => $targetSlots[0]['semester_id'],
            'sem2' => $targetSlots[1]['semester_id'],
            'sem3' => $targetSlots[2]['semester_id'],
        ];
        $deleteLegacyResultsStmt->execute($deleteParams);
        $stats['legacy_rows_removed'] += (int)$deleteLegacyResultsStmt->rowCount();
        $deleteLegacyRegistrationsStmt->execute($deleteParams);
        $stats['legacy_rows_removed'] += (int)$deleteLegacyRegistrationsStmt->rowCount();

        foreach ($targetSlots as $slot) {
            $yearOfStudy = (int)$slot['year_of_study'];
            $semesterNumber = (int)$slot['semester_number'];
            $semesterId = (int)$slot['semester_id'];
            $slotKey = $yearOfStudy . '-' . $semesterNumber;
            $slotCourses = $coursesBySlot[$slotKey] ?? [];
            if (!$slotCourses) {
                throw new RuntimeException('No courses were found for slot ' . $slotKey . '.');
            }

            $requestDate = sprintf('%04d-%02d-01 08:00:00', 2025 + ($yearOfStudy - 1), $semesterNumber === 1 ? 2 : 8);
            $approvalDate = sprintf('%04d-%02d-03 10:00:00', 2025 + ($yearOfStudy - 1), $semesterNumber === 1 ? 2 : 8);
            $publishDate = sprintf('%04d-%02d-20 09:30:00', 2025 + ($yearOfStudy - 1), $semesterNumber === 1 ? 7 : 12);

            $semesterRegistrationStmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId,
                'year_of_study' => $yearOfStudy,
                'request_date' => $requestDate,
                'approved_by' => $adminUserId,
                'approval_date' => $approvalDate,
            ]);
            $stats['semester_registrations']++;

            foreach ($slotCourses as $courseIndex => $course) {
                $courseId = (int)$course['id'];
                $courseRegistrationStmt->execute([
                    'student_id' => $studentId,
                    'course_id' => $courseId,
                    'semester_id' => $semesterId,
                    'registration_date' => substr($requestDate, 0, 10),
                    'approved_by' => $adminId,
                    'approved_date' => $approvalDate,
                    'remarks' => 'Backfilled approved registration for 2026 FVT transcript cohort.',
                ]);
                $stats['course_registrations']++;

                $marks = buildBackfillMarks($studentIndex + 1, $courseIndex + 1, $yearOfStudy, $semesterNumber);
                $enteredBy = (int)($assignmentMap[$semesterId . ':' . $courseId] ?? $lecturerIds[0]);

                $resultStmt->execute([
                    'student_id' => $studentId,
                    'course_id' => $courseId,
                    'semester_id' => $semesterId,
                    'assignment_marks' => $marks['assignment_marks'],
                    'test_marks' => $marks['test_marks'],
                    'midterm_marks' => $marks['midterm_marks'],
                    'final_exam_marks' => $marks['final_exam_marks'],
                    'participation_marks' => $marks['participation_marks'],
                    'entered_by' => $enteredBy,
                    'approved_by' => $adminId,
                    'submitted_date' => date('Y-m-d H:i:s', strtotime($publishDate . ' -7 days')),
                    'approved_date' => date('Y-m-d H:i:s', strtotime($publishDate . ' -3 days')),
                    'published_date' => $publishDate,
                    'remarks' => 'Backfilled published result for 2026 FVT transcript cohort.',
                ]);
                $stats['results']++;
            }
        }

        $studentUpdateStmt->execute([
            'current_semester' => $targetSlots[2]['semester_id'],
            'entry_semester_id' => $targetSlots[0]['semester_id'],
            'id' => $studentId,
        ]);

        $stats['students_processed']++;
    }

    $conn->commit();

    echo "2026 FVT transcript marks backfilled successfully.\n";
    echo 'Students processed: ' . $stats['students_processed'] . "\n";
    echo 'Semester registrations upserted: ' . $stats['semester_registrations'] . "\n";
    echo 'Course registrations upserted: ' . $stats['course_registrations'] . "\n";
    echo 'Results upserted: ' . $stats['results'] . "\n";
    echo 'Legacy non-FVT rows removed: ' . $stats['legacy_rows_removed'] . "\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);
    echo 'Backfill failed: ' . $e->getMessage() . "\n";
    exit(1);
}
