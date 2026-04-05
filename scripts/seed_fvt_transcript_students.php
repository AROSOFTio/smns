<?php
require_once __DIR__ . '/../config.php';

const FVT_TARGET_STUDENTS = [
    'group_one' => [
        'BASHUMBA CHRISTOPHER',
        'ONYOIN RAPHAEL',
        'AMUMPE MACKLEAN',
        'BYARUGABA GAVIN',
        'BALABA GEOFREY',
        'SSEMPIJJA ALLAN MUGENYI',
        'NABAASA ANTONY',
        'NASASIRA JAMES',
        'MBABAZIZE JERRY DENZEL',
        'AKANDWANAHO PATIENCE',
    ],
    'group_two' => [
        'MUKIZA THOMAS',
        'MAYANJA ALEX',
        'ASHABAHEBWA FRANCIS',
        'MUGISHAMUNTU VICTOR',
        'NAWAGUMA VIVIAN',
        'MUSIMENTA ALINDA GRACE',
        'EZARU FAITH FRIDAY',
        'NALUBWAMA NAHIA',
        'MUKASA JOSEPH',
        'TOMUSANGE MARVIN',
        'MOSOLO DESSA PRINCE',
        'BUNYIRI REGAN',
        'ARINAWE YOWASI',
        'MUBIRU RONALD',
    ],
    'group_three' => [
        'AKANYIJUKA ASIRAFU',
        'ASIIMWE MICHEAL',
        'KAMUSIIME VALLEN',
        'OBBO EMMANUEL',
        'NANKINDU SHERINA SENYONJO',
        'KASHEIJA JUSTUS',
        'SSENTONGO ZIYADI',
        'NDAGIRE PATRICIA BRIDGET',
        'NEBOKHE MARTHA',
        'AINEMBABAZI MERCY',
        'KASOZI RAYAN',
        'TWINOMUJUNI KATO',
        'BALUKU RONALD',
        'MUGISHA BLAIR',
        'MUGABI CALEB',
        'AMPEIRE NICKSON',
        'AMUTUSIIMIRE BRIAN',
        'BIRIBAWA SHADIA',
        'WAMONO ISAIAH',
        'WAMANI RONNIE',
        'KISA PETER SENJAKO',
        'EMUNA DERRICK',
        'AMANYIRE JULIUS',
        'BULO GEOFREY',
        'ABWOYE ISMAEL',
        'KAWEESA HATIM',
        'INEZA ALICE',
        'KABAZARWE SABERAH',
        'LUSSE MARVIN WILLIAM',
        'OKURUT SAMUEL STEPHEN',
        'KAMUNTU DAVID',
        'BARIGYE DAVIS',
    ],
];

function normalizeFullName(string $name): string
{
    $name = preg_replace('/\s+/', ' ', strtoupper(trim($name))) ?: '';
    return trim($name);
}

function splitStudentName(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    if (count($parts) === 0) {
        return ['first_name' => 'FVT', 'middle_name' => null, 'last_name' => 'STUDENT'];
    }
    if (count($parts) === 1) {
        return ['first_name' => strtoupper($parts[0]), 'middle_name' => null, 'last_name' => 'STUDENT'];
    }

    $first = strtoupper(array_shift($parts));
    $last = strtoupper(array_pop($parts));
    $middle = count($parts) > 0 ? strtoupper(implode(' ', $parts)) : null;

    return [
        'first_name' => $first,
        'middle_name' => $middle,
        'last_name' => $last,
    ];
}

function inferGender(string $fullName): string
{
    $femaleTokens = [
        'PATIENCE', 'VIVIAN', 'ALINDA', 'GRACE', 'FAITH', 'NAHIA', 'SHERINA',
        'PATRICIA', 'BRIDGET', 'MARTHA', 'MERCY', 'SHADIA', 'ALICE', 'SABERAH'
    ];
    $upperName = normalizeFullName($fullName);
    foreach ($femaleTokens as $token) {
        if (strpos($upperName, $token) !== false) {
            return 'Female';
        }
    }
    return 'Male';
}

function buildMarks(int $studentIndex, int $courseIndex, int $yearOfStudy, int $semesterNumber): array
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
        'total' => $base,
    ];
}

function transformedFvtCode(string $sourceCode): string
{
    if (preg_match('/(\d+)$/', $sourceCode, $matches)) {
        return 'FVT' . $matches[1];
    }
    return 'FVT' . preg_replace('/[^A-Z0-9]/', '', strtoupper($sourceCode));
}

function fetchSingleValue(PDO $conn, string $sql, array $params = [])
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    $programId = (int)fetchSingleValue($conn, "SELECT id FROM programs WHERE UPPER(program_code) = 'FVT' LIMIT 1");
    if ($programId <= 0) {
        $stmt = $conn->prepare("
            INSERT INTO programs (program_code, program_name, department, duration_years, total_credits_required, status)
            VALUES ('FVT', 'FIAT VOLUNTAS TUA', 'Theology', 3, 120, 'active')
        ");
        $stmt->execute();
        $programId = (int)$conn->lastInsertId();
    }

    $adminId = (int)fetchSingleValue($conn, "SELECT id FROM admins ORDER BY id ASC LIMIT 1");
    $adminUserId = (int)fetchSingleValue($conn, "SELECT user_id FROM admins WHERE id = :id LIMIT 1", ['id' => $adminId]);
    if ($adminId <= 0 || $adminUserId <= 0) {
        throw new RuntimeException('Admin account is required before seeding FVT transcript students.');
    }

    $semesterRows = $conn->query("
        SELECT s.id, s.semester_number, ay.id AS academic_year_id, ay.year_name, ay.start_date
        FROM semesters s
        INNER JOIN academic_years ay ON ay.id = s.academic_year_id
        ORDER BY ay.start_date ASC, s.semester_number ASC, s.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $yearBuckets = [];
    foreach ($semesterRows as $row) {
        $academicYearId = (int)$row['academic_year_id'];
        if (!isset($yearBuckets[$academicYearId])) {
            $yearBuckets[$academicYearId] = [
                'academic_year_id' => $academicYearId,
                'year_name' => (string)$row['year_name'],
                'start_date' => (string)$row['start_date'],
                'semesters' => []
            ];
        }
        $yearBuckets[$academicYearId]['semesters'][(int)$row['semester_number']] = (int)$row['id'];
    }
    $yearBuckets = array_values($yearBuckets);
    if (count($yearBuckets) < 3) {
        throw new RuntimeException('At least three academic years with semesters are required.');
    }

    $semesterPlan = [];
    for ($level = 1; $level <= 3; $level++) {
        $bucket = $yearBuckets[$level - 1] ?? null;
        if (!$bucket || empty($bucket['semesters'][1]) || empty($bucket['semesters'][2])) {
            throw new RuntimeException('Incomplete semester setup for year ' . $level . '.');
        }
        $semesterPlan[$level] = [
            1 => (int)$bucket['semesters'][1],
            2 => (int)$bucket['semesters'][2],
            'academic_year_id' => (int)$bucket['academic_year_id'],
            'year_name' => (string)$bucket['year_name'],
        ];
    }

    $existingFvtCourseCount = (int)fetchSingleValue(
        $conn,
        "SELECT COUNT(*) FROM courses WHERE program_id = :program_id AND level_year BETWEEN 1 AND 3",
        ['program_id' => $programId]
    );

    if ($existingFvtCourseCount === 0) {
        $sourceCourses = $conn->query("
            SELECT *
            FROM courses
            WHERE program_id = (
                SELECT id FROM programs WHERE UPPER(program_code) = 'BTH' LIMIT 1
            )
              AND level_year BETWEEN 1 AND 3
            ORDER BY level_year ASC, semester_offered ASC, course_code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!$sourceCourses) {
            throw new RuntimeException('No source BTH course catalog found for FVT cloning.');
        }

        $insertCourse = $conn->prepare("
            INSERT INTO courses (
                course_code, course_name, credit_hours, practical_hours, tutorial_hours, lecture_hours,
                program_id, level_year, semester_offered, prerequisites, description, status
            ) VALUES (
                :course_code, :course_name, :credit_hours, :practical_hours, :tutorial_hours, :lecture_hours,
                :program_id, :level_year, :semester_offered, :prerequisites, :description, :status
            )
        ");

        foreach ($sourceCourses as $course) {
            $newCode = transformedFvtCode((string)$course['course_code']);
            $existingCourseId = (int)fetchSingleValue(
                $conn,
                "SELECT id FROM courses WHERE course_code = :course_code LIMIT 1",
                ['course_code' => $newCode]
            );

            if ($existingCourseId > 0) {
                $update = $conn->prepare("
                    UPDATE courses
                    SET course_name = :course_name,
                        credit_hours = :credit_hours,
                        practical_hours = :practical_hours,
                        tutorial_hours = :tutorial_hours,
                        lecture_hours = :lecture_hours,
                        program_id = :program_id,
                        level_year = :level_year,
                        semester_offered = :semester_offered,
                        prerequisites = :prerequisites,
                        description = :description,
                        status = :status
                    WHERE id = :id
                ");
                $update->execute([
                    'course_name' => $course['course_name'],
                    'credit_hours' => $course['credit_hours'],
                    'practical_hours' => $course['practical_hours'],
                    'tutorial_hours' => $course['tutorial_hours'],
                    'lecture_hours' => $course['lecture_hours'],
                    'program_id' => $programId,
                    'level_year' => $course['level_year'],
                    'semester_offered' => $course['semester_offered'],
                    'prerequisites' => $course['prerequisites'],
                    'description' => $course['description'],
                    'status' => $course['status'],
                    'id' => $existingCourseId,
                ]);
            } else {
                $insertCourse->execute([
                    'course_code' => $newCode,
                    'course_name' => $course['course_name'],
                    'credit_hours' => $course['credit_hours'],
                    'practical_hours' => $course['practical_hours'],
                    'tutorial_hours' => $course['tutorial_hours'],
                    'lecture_hours' => $course['lecture_hours'],
                    'program_id' => $programId,
                    'level_year' => $course['level_year'],
                    'semester_offered' => $course['semester_offered'],
                    'prerequisites' => $course['prerequisites'],
                    'description' => $course['description'],
                    'status' => $course['status'],
                ]);
            }
        }
    }

    $fvtCourses = $conn->prepare("
        SELECT *
        FROM courses
        WHERE program_id = :program_id
          AND level_year BETWEEN 1 AND 3
        ORDER BY level_year ASC, semester_offered ASC, course_code ASC
    ");
    $fvtCourses->execute(['program_id' => $programId]);
    $courseRows = $fvtCourses->fetchAll(PDO::FETCH_ASSOC);
    if (!$courseRows) {
        throw new RuntimeException('FVT course catalog is still empty after setup.');
    }

    $coursesBySlot = [];
    foreach ($courseRows as $course) {
        $slot = (int)$course['level_year'] . '-' . (int)$course['semester_offered'];
        if (!isset($coursesBySlot[$slot])) {
            $coursesBySlot[$slot] = [];
        }
        $coursesBySlot[$slot][] = $course;
    }

    $lecturerIds = $conn->query("SELECT id FROM lecturers WHERE status = 'active' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (!$lecturerIds) {
        throw new RuntimeException('No active lecturers available for course/result assignment.');
    }

    $insertAssignment = $conn->prepare("
        INSERT INTO course_assignments (lecturer_id, course_id, semester_id, assigned_date, status)
        VALUES (:lecturer_id, :course_id, :semester_id, :assigned_date, 'active')
        ON DUPLICATE KEY UPDATE
            lecturer_id = VALUES(lecturer_id),
            assigned_date = VALUES(assigned_date),
            status = VALUES(status)
    ");

    $assignmentLecturerMap = [];
    $lecturerCount = count($lecturerIds);
    foreach ($courseRows as $index => $course) {
        $level = (int)$course['level_year'];
        $semesterNumber = (int)$course['semester_offered'];
        if (empty($semesterPlan[$level][$semesterNumber])) {
            continue;
        }
        $semesterId = (int)$semesterPlan[$level][$semesterNumber];
        $lecturerId = (int)$lecturerIds[$index % $lecturerCount];
        $semesterStart = (string)fetchSingleValue($conn, "SELECT start_date FROM semesters WHERE id = :id", ['id' => $semesterId]);

        $insertAssignment->execute([
            'lecturer_id' => $lecturerId,
            'course_id' => (int)$course['id'],
            'semester_id' => $semesterId,
            'assigned_date' => $semesterStart ?: date('Y-m-d'),
        ]);
        $assignmentLecturerMap[$semesterId . ':' . (int)$course['id']] = $lecturerId;
    }

    $existingStudentsStmt = $conn->prepare("
        SELECT id, user_id, student_id, first_name, middle_name, last_name
        FROM students
        WHERE program_id = :program_id
    ");
    $existingStudentsStmt->execute(['program_id' => $programId]);
    $existingStudents = [];
    foreach ($existingStudentsStmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
        $name = normalizeFullName(trim(
            (string)$student['first_name'] . ' ' .
            (string)($student['middle_name'] ?? '') . ' ' .
            (string)$student['last_name']
        ));
        $existingStudents[$name] = $student;
    }

    $allNames = array_merge(
        FVT_TARGET_STUDENTS['group_one'],
        FVT_TARGET_STUDENTS['group_two'],
        FVT_TARGET_STUDENTS['group_three']
    );

    $groupMembership = [];
    foreach (FVT_TARGET_STUDENTS as $group => $names) {
        foreach ($names as $name) {
            $groupMembership[normalizeFullName($name)] = $group;
        }
    }

    $insertUser = $conn->prepare("
        INSERT INTO users (username, email, password_hash, role, status, require_password_change)
        VALUES (:username, :email, :password_hash, 'student', 'active', 1)
    ");

    $insertStudent = $conn->prepare("
        INSERT INTO students (
            user_id, student_id, admission_number, first_name, middle_name, last_name, title,
            date_of_birth, gender, phone, email, smns_email, primary_number, nationality, school_college,
            department, intake, academic_status, discipline_status, address, city, country, guardian_name,
            guardian_relation, guardian_phone, guardian_email, emergency_contact_name, emergency_contact_phone,
            emergency_contact_relationship, program_id, study_year, session, current_semester, year_of_study,
            level_year, entry_year, entry_semester_id, entry_mode, enrollment_type, status, religion, district,
            profile_locked
        ) VALUES (
            :user_id, :student_id, :admission_number, :first_name, :middle_name, :last_name, :title,
            :date_of_birth, :gender, :phone, :email, :smns_email, :primary_number, :nationality, :school_college,
            :department, :intake, :academic_status, :discipline_status, :address, :city, :country, :guardian_name,
            :guardian_relation, :guardian_phone, :guardian_email, :emergency_contact_name, :emergency_contact_phone,
            :emergency_contact_relationship, :program_id, :study_year, :session, :current_semester, :year_of_study,
            :level_year, :entry_year, :entry_semester_id, :entry_mode, :enrollment_type, :status, :religion, :district,
            0
        )
    ");

    $updateStudent = $conn->prepare("
        UPDATE students
        SET user_id = :user_id,
            student_id = :student_id,
            admission_number = :admission_number,
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            title = :title,
            date_of_birth = :date_of_birth,
            gender = :gender,
            phone = :phone,
            email = :email,
            smns_email = :smns_email,
            primary_number = :primary_number,
            nationality = :nationality,
            school_college = :school_college,
            department = :department,
            intake = :intake,
            academic_status = :academic_status,
            discipline_status = :discipline_status,
            address = :address,
            city = :city,
            country = :country,
            guardian_name = :guardian_name,
            guardian_relation = :guardian_relation,
            guardian_phone = :guardian_phone,
            guardian_email = :guardian_email,
            emergency_contact_name = :emergency_contact_name,
            emergency_contact_phone = :emergency_contact_phone,
            emergency_contact_relationship = :emergency_contact_relationship,
            program_id = :program_id,
            study_year = :study_year,
            session = :session,
            current_semester = :current_semester,
            year_of_study = :year_of_study,
            level_year = :level_year,
            entry_year = :entry_year,
            entry_semester_id = :entry_semester_id,
            entry_mode = :entry_mode,
            enrollment_type = :enrollment_type,
            status = :status,
            religion = :religion,
            district = :district
        WHERE id = :id
    ");

    $insertSemesterRegistration = $conn->prepare("
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

    $insertCourseRegistration = $conn->prepare("
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

    $insertResult = $conn->prepare("
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

    $grantTranscript = $conn->prepare("
        INSERT INTO transcript_download_rights (
            student_id, status, verified_by_user_id, verified_at, notes
        ) VALUES (
            :student_id, 'granted', :verified_by_user_id, :verified_at, :notes
        )
        ON DUPLICATE KEY UPDATE
            status = 'granted',
            verified_by_user_id = VALUES(verified_by_user_id),
            verified_at = VALUES(verified_at),
            revoked_by_user_id = NULL,
            revoked_at = NULL,
            notes = VALUES(notes)
    ");

    $stats = [
        'students_processed' => 0,
        'semester_registrations' => 0,
        'course_registrations' => 0,
        'results' => 0,
        'transcript_rights' => 0,
    ];

    foreach ($allNames as $index => $fullName) {
        $normalizedName = normalizeFullName($fullName);
        $group = $groupMembership[$normalizedName] ?? 'group_one';
        $nameParts = splitStudentName($fullName);
        $sequence = str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
        $studentCode = 'FVT2026' . $sequence;
        $username = 'fvt' . strtolower($sequence);
        $email = strtolower($studentCode) . '@student.smns.local';
        $smnsEmail = strtolower($studentCode) . '@smns.local';
        $gender = inferGender($fullName);
        $dateOfBirth = sprintf('200%d-%02d-%02d', ($index % 4), (($index % 11) + 1), (($index % 27) + 1));
        $phoneSuffix = str_pad((string)(700000 + $index + 1), 6, '0', STR_PAD_LEFT);
        $phone = '+25677' . $phoneSuffix;
        $guardianPhone = '+25678' . $phoneSuffix;

        $completedThrough = [[1, 1], [1, 2], [2, 1]];
        $isGraduated = false;

        if ($group === 'group_two') {
            $completedThrough[] = [2, 2];
            $groupTwoIndex = array_search($fullName, FVT_TARGET_STUDENTS['group_two'], true);
            if ($groupTwoIndex !== false) {
                if ($groupTwoIndex < 7) {
                    $completedThrough[] = [3, 1];
                } else {
                    $completedThrough[] = [3, 1];
                    $completedThrough[] = [3, 2];
                }
            }
        } elseif ($group === 'group_three') {
            $completedThrough = [[1, 1], [1, 2], [2, 1], [2, 2], [3, 1], [3, 2]];
            $isGraduated = true;
        }

        $highestSlot = end($completedThrough);
        $highestYear = (int)$highestSlot[0];
        $highestSemesterNumber = (int)$highestSlot[1];
        $currentSemesterId = (int)$semesterPlan[$highestYear][$highestSemesterNumber];

        $userId = (int)fetchSingleValue($conn, "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1", [
            'username' => $username,
            'email' => $email,
        ]);

        if ($userId <= 0 && isset($existingStudents[$normalizedName]['user_id'])) {
            $userId = (int)$existingStudents[$normalizedName]['user_id'];
        }

        if ($userId <= 0) {
            $insertUser->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash('ChangeMe@123', PASSWORD_DEFAULT),
            ]);
            $userId = (int)$conn->lastInsertId();
        } else {
            $updateUser = $conn->prepare("
                UPDATE users
                SET username = :username,
                    email = :email,
                    role = 'student',
                    status = 'active',
                    require_password_change = 1
                WHERE id = :id
            ");
            $updateUser->execute([
                'username' => $username,
                'email' => $email,
                'id' => $userId,
            ]);
        }

        $studentPayload = [
            'user_id' => $userId,
            'student_id' => $studentCode,
            'admission_number' => $studentCode,
            'first_name' => $nameParts['first_name'],
            'middle_name' => $nameParts['middle_name'],
            'last_name' => $nameParts['last_name'],
            'title' => null,
            'date_of_birth' => $dateOfBirth,
            'gender' => $gender,
            'phone' => $phone,
            'email' => $email,
            'smns_email' => $smnsEmail,
            'primary_number' => $phone,
            'nationality' => 'Ugandan',
            'school_college' => 'School of Theology',
            'department' => 'Theology',
            'intake' => 'August 2025',
            'academic_status' => $isGraduated ? 'Graduated' : 'Active',
            'discipline_status' => 'Good Standing',
            'address' => 'FIAT VOLUNTAS TUA Cohort',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'guardian_name' => 'Registrar FVT',
            'guardian_relation' => 'Guardian',
            'guardian_phone' => $guardianPhone,
            'guardian_email' => 'registrar.fvt@smns.local',
            'emergency_contact_name' => 'Registrar FVT',
            'emergency_contact_phone' => $guardianPhone,
            'emergency_contact_relationship' => 'Guardian',
            'program_id' => $programId,
            'study_year' => $highestYear,
            'session' => 'Day',
            'current_semester' => $currentSemesterId,
            'year_of_study' => $highestYear,
            'level_year' => $highestYear,
            'entry_year' => 2025,
            'entry_semester_id' => (int)$semesterPlan[1][1],
            'entry_mode' => 'Regular',
            'enrollment_type' => 'Day',
            'status' => $isGraduated ? 'graduated' : 'active',
            'religion' => 'Christianity',
            'district' => 'Kampala',
        ];

        if (isset($existingStudents[$normalizedName]['id'])) {
            $studentPayload['id'] = (int)$existingStudents[$normalizedName]['id'];
            $updateStudent->execute($studentPayload);
            $studentId = (int)$existingStudents[$normalizedName]['id'];
        } else {
            $insertStudent->execute($studentPayload);
            $studentId = (int)$conn->lastInsertId();
            $existingStudents[$normalizedName] = [
                'id' => $studentId,
                'user_id' => $userId,
            ];
        }

        if ($isGraduated) {
            $graduationUpdate = $conn->prepare("
                UPDATE students
                SET graduation_date = :graduation_date,
                    graduation_semester_id = :graduation_semester_id,
                    graduation_award_title = :graduation_award_title,
                    graduation_classification = :graduation_classification
                WHERE id = :id
            ");
            $graduationUpdate->execute([
                'graduation_date' => '2028-12-15',
                'graduation_semester_id' => (int)$semesterPlan[3][2],
                'graduation_award_title' => 'FIAT VOLUNTAS TUA',
                'graduation_classification' => 'Second Class',
                'id' => $studentId,
            ]);

            $grantTranscript->execute([
                'student_id' => $studentId,
                'verified_by_user_id' => $adminUserId,
                'verified_at' => '2028-12-20 09:00:00',
                'notes' => 'Auto-seeded FVT transcript release cohort.',
            ]);
            $stats['transcript_rights']++;
        } else {
            $clearGraduation = $conn->prepare("
                UPDATE students
                SET graduation_date = NULL,
                    graduation_semester_id = NULL,
                    graduation_award_title = NULL,
                    graduation_classification = NULL
                WHERE id = :id
            ");
            $clearGraduation->execute(['id' => $studentId]);
        }

        foreach ($completedThrough as $slot) {
            [$yearOfStudy, $semesterNumber] = $slot;
            $semesterId = (int)$semesterPlan[$yearOfStudy][$semesterNumber];
            $requestDate = sprintf('%04d-%02d-01 08:00:00', 2025 + ($yearOfStudy - 1), $semesterNumber === 1 ? 2 : 8);
            $approvalDate = sprintf('%04d-%02d-03 10:00:00', 2025 + ($yearOfStudy - 1), $semesterNumber === 1 ? 2 : 8);

            $insertSemesterRegistration->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId,
                'year_of_study' => $yearOfStudy,
                'request_date' => $requestDate,
                'approved_by' => $adminUserId,
                'approval_date' => $approvalDate,
            ]);
            $stats['semester_registrations']++;

            $slotKey = $yearOfStudy . '-' . $semesterNumber;
            $slotCourses = $coursesBySlot[$slotKey] ?? [];
            foreach ($slotCourses as $courseIndex => $course) {
                $insertCourseRegistration->execute([
                    'student_id' => $studentId,
                    'course_id' => (int)$course['id'],
                    'semester_id' => $semesterId,
                    'registration_date' => substr($requestDate, 0, 10),
                    'approved_by' => $adminId,
                    'approved_date' => $approvalDate,
                    'remarks' => 'Auto-seeded FVT transcript cohort registration.',
                ]);
                $stats['course_registrations']++;

                $marks = buildMarks($index + 1, $courseIndex + 1, $yearOfStudy, $semesterNumber);
                $enteredBy = (int)($assignmentLecturerMap[$semesterId . ':' . (int)$course['id']] ?? $lecturerIds[0]);
                $publishDate = sprintf('%04d-%02d-20 09:30:00', 2025 + ($yearOfStudy - 1), $semesterNumber === 1 ? 7 : 12);

                $insertResult->execute([
                    'student_id' => $studentId,
                    'course_id' => (int)$course['id'],
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
                    'remarks' => 'Auto-seeded published result for FVT transcript cohort.',
                ]);
                $stats['results']++;
            }
        }

        $stats['students_processed']++;
    }

    $conn->commit();

    echo "FVT transcript cohort seeded successfully.\n";
    echo 'Students processed: ' . $stats['students_processed'] . "\n";
    echo 'Semester registrations upserted: ' . $stats['semester_registrations'] . "\n";
    echo 'Course registrations upserted: ' . $stats['course_registrations'] . "\n";
    echo 'Results upserted: ' . $stats['results'] . "\n";
    echo 'Transcript rights granted: ' . $stats['transcript_rights'] . "\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo 'Seed failed: ' . $e->getMessage() . "\n";
    exit(1);
}
