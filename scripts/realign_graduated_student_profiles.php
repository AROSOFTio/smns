<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $conn->beginTransaction();

    $studentsStmt = $conn->query("
        SELECT
            s.id,
            s.user_id,
            s.student_id,
            s.admission_number,
            s.email AS student_email,
            s.smns_email,
            s.entry_year,
            s.entry_semester_id,
            s.graduation_semester_id,
            u.email AS user_email
        FROM students s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.status = 'graduated'
        ORDER BY s.id ASC
    ");
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $termStmt = $conn->prepare("
        SELECT semester_id
        FROM (
            SELECT DISTINCT
                sem.id AS semester_id,
                ay.start_date AS ay_start_date,
                sem.semester_number
            FROM results r
            INNER JOIN semesters sem ON sem.id = r.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            WHERE r.student_id = :student_id_results

            UNION

            SELECT DISTINCT
                sem.id AS semester_id,
                ay.start_date AS ay_start_date,
                sem.semester_number
            FROM course_registrations cr
            INNER JOIN semesters sem ON sem.id = cr.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            WHERE cr.student_id = :student_id_course_regs

            UNION

            SELECT DISTINCT
                sem.id AS semester_id,
                ay.start_date AS ay_start_date,
                sem.semester_number
            FROM semester_registrations sr
            INNER JOIN semesters sem ON sem.id = sr.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            WHERE sr.student_id = :student_id_semester_regs
        ) terms
        ORDER BY ay_start_date ASC, semester_number ASC, semester_id ASC
    ");

    $studentsByAdmission = $conn->prepare('SELECT id FROM students WHERE admission_number = :admission_number LIMIT 1');
    $studentsBySmnsEmail = $conn->prepare('SELECT id FROM students WHERE smns_email = :smns_email LIMIT 1');
    $usersByEmail = $conn->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');

    $updateStudent = $conn->prepare("
        UPDATE students
        SET admission_number = :admission_number,
            email = :student_email,
            smns_email = :smns_email,
            entry_semester_id = :entry_semester_id,
            graduation_semester_id = :graduation_semester_id
        WHERE id = :id
    ");
    $updateUser = $conn->prepare('UPDATE users SET email = :email WHERE id = :id');

    $summary = [
        'student_rows_updated' => 0,
        'user_rows_updated' => 0,
    ];

    foreach ($students as $student) {
        $studentId = (int)$student['id'];
        $entryYear = (int)($student['entry_year'] ?? 0);
        $studentNo = (string)$student['student_id'];
        if ($entryYear <= 0 || !preg_match('/^(\d{4})-STU-(\d{3})$/', $studentNo, $matches)) {
            throw new RuntimeException('Student profile cannot be realigned for record #' . $studentId);
        }

        $suffixInt = (int)$matches[2];
        $suffix4 = str_pad((string)$suffixInt, 4, '0', STR_PAD_LEFT);
        $newAdmissionNumber = 'ADM' . $entryYear . $suffix4;

        $newAliasBase = 'fvt' . $entryYear . str_pad((string)$suffixInt, 3, '0', STR_PAD_LEFT);
        $currentStudentEmail = trim((string)($student['student_email'] ?? ''));
        $currentSmnsEmail = trim((string)($student['smns_email'] ?? ''));
        $currentUserEmail = trim((string)($student['user_email'] ?? ''));

        $newStudentEmail = $currentStudentEmail;
        $newSmnsEmail = $currentSmnsEmail;
        $newUserEmail = $currentUserEmail;

        if (preg_match('/^fvt\d{7}@student\.smns\.local$/i', $currentStudentEmail)) {
            $newStudentEmail = $newAliasBase . '@student.smns.local';
        }
        if (preg_match('/^fvt\d{7}@smns\.local$/i', $currentSmnsEmail)) {
            $newSmnsEmail = $newAliasBase . '@smns.local';
        }
        if (preg_match('/^fvt\d{7}@student\.smns\.local$/i', $currentUserEmail)) {
            $newUserEmail = $newAliasBase . '@student.smns.local';
        }

        $termStmt->execute([
            'student_id_results' => $studentId,
            'student_id_course_regs' => $studentId,
            'student_id_semester_regs' => $studentId,
        ]);
        $terms = array_map('intval', $termStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (empty($terms)) {
            throw new RuntimeException('No term history found for graduated student ' . $studentNo);
        }
        $newEntrySemesterId = $terms[0];
        $newGraduationSemesterId = $terms[count($terms) - 1];

        $studentsByAdmission->execute(['admission_number' => $newAdmissionNumber]);
        $admissionOwner = (int)($studentsByAdmission->fetchColumn() ?: 0);
        if ($admissionOwner > 0 && $admissionOwner !== $studentId) {
            throw new RuntimeException('Admission number collision for ' . $newAdmissionNumber);
        }

        if ($newSmnsEmail !== '') {
            $studentsBySmnsEmail->execute(['smns_email' => $newSmnsEmail]);
            $smnsOwner = (int)($studentsBySmnsEmail->fetchColumn() ?: 0);
            if ($smnsOwner > 0 && $smnsOwner !== $studentId) {
                throw new RuntimeException('SMNS email collision for ' . $newSmnsEmail);
            }
        }

        if ($newUserEmail !== '') {
            $usersByEmail->execute(['email' => $newUserEmail]);
            $userEmailOwner = (int)($usersByEmail->fetchColumn() ?: 0);
            if ($userEmailOwner > 0 && $userEmailOwner !== (int)$student['user_id']) {
                throw new RuntimeException('User email collision for ' . $newUserEmail);
            }
        }

        $updateStudent->execute([
            'admission_number' => $newAdmissionNumber,
            'student_email' => $newStudentEmail !== '' ? $newStudentEmail : null,
            'smns_email' => $newSmnsEmail !== '' ? $newSmnsEmail : null,
            'entry_semester_id' => $newEntrySemesterId,
            'graduation_semester_id' => $newGraduationSemesterId,
            'id' => $studentId,
        ]);
        $summary['student_rows_updated'] += $updateStudent->rowCount();

        if ($newUserEmail !== $currentUserEmail) {
            $updateUser->execute([
                'email' => $newUserEmail,
                'id' => (int)$student['user_id'],
            ]);
            $summary['user_rows_updated'] += $updateUser->rowCount();
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
