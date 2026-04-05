<?php
require_once __DIR__ . '/../config.php';

function ensureStudentProfileCompletionColumnsForBulk(PDO $conn) {
    $columns = [
        'religion' => 'VARCHAR(100) NULL',
        'district' => 'VARCHAR(100) NULL',
        'parish' => 'VARCHAR(100) NULL AFTER district',
        'nationality' => 'VARCHAR(100) NULL',
        'national_id' => 'VARCHAR(100) NULL',
        'passport' => 'VARCHAR(100) NULL',
        'guardian_name' => 'VARCHAR(200) NULL',
        'guardian_relation' => 'VARCHAR(100) NULL',
        'guardian_email' => 'VARCHAR(150) NULL',
        'guardian_phone' => 'VARCHAR(30) NULL',
        'profile_locked' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'profile_completed_at' => 'DATETIME NULL'
    ];

    foreach ($columns as $column => $definition) {
        try {
            $exists = $conn->query("SHOW COLUMNS FROM students LIKE '{$column}'")->fetch();
            if (!$exists) {
                $conn->exec("ALTER TABLE students ADD COLUMN {$column} {$definition}");
            }
        } catch (Exception $e) {
        }
    }
}

$db = new Database();
$conn = $db->getConnection();
ensureStudentProfileCompletionColumnsForBulk($conn);

$defaultPhotoPath = 'uploads/students/Sem.PNG';
$defaultGuardianName = 'Registrar FVT';
$defaultGuardianRelation = 'Guardian';
$defaultGuardianEmail = 'registrar.fvt@smns.local';
$defaultGuardianPhone = '+25678700052';
$defaultReligion = 'Catholic';
$defaultDistrict = 'Kampala';
$defaultParish = 'Nsambya Parish';
$defaultNationality = 'Ugandan';

$studentsStmt = $conn->query("
    SELECT
        id,
        student_id,
        phone,
        primary_number,
        religion,
        district,
        parish,
        nationality,
        country,
        national_id,
        passport,
        guardian_name,
        guardian_relation,
        guardian_email,
        guardian_phone,
        emergency_contact_name,
        emergency_contact_phone,
        emergency_contact_relationship,
        photo,
        profile_locked
    FROM students
    ORDER BY id ASC
");
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$updateStmt = $conn->prepare("
    UPDATE students
    SET
        phone = :phone,
        religion = :religion,
        district = :district,
        parish = :parish,
        nationality = :nationality,
        national_id = :national_id,
        passport = :passport,
        guardian_name = :guardian_name,
        guardian_relation = :guardian_relation,
        guardian_email = :guardian_email,
        guardian_phone = :guardian_phone,
        emergency_contact_name = :emergency_contact_name,
        emergency_contact_phone = :emergency_contact_phone,
        emergency_contact_relationship = :emergency_contact_relationship,
        photo = :photo,
        profile_locked = 1,
        profile_completed_at = COALESCE(profile_completed_at, NOW()),
        updated_at = NOW()
    WHERE id = :id
");

$updated = 0;
$conn->beginTransaction();

try {
    foreach ($students as $student) {
        $studentId = trim((string)($student['student_id'] ?? ''));
        $phone = trim((string)($student['phone'] ?? ''));
        if ($phone === '') {
            $phone = trim((string)($student['primary_number'] ?? ''));
        }
        if ($phone === '') {
            $phone = $defaultGuardianPhone;
        }

        $nationality = trim((string)($student['nationality'] ?? ''));
        if ($nationality === '') {
            $country = trim((string)($student['country'] ?? ''));
            $nationality = $country !== '' ? $country : $defaultNationality;
        }

        $nationalId = trim((string)($student['national_id'] ?? ''));
        if ($nationalId === '') {
            $ref = preg_replace('/[^A-Za-z0-9]/', '', $studentId);
            if ($ref === '') {
                $ref = 'STUDENT' . (int)$student['id'];
            }
            $nationalId = 'AUTO-' . $ref;
        }

        $passport = trim((string)($student['passport'] ?? ''));
        $district = trim((string)($student['district'] ?? ''));
        if ($district === '') {
            $district = $defaultDistrict;
        }

        $parish = trim((string)($student['parish'] ?? ''));
        if ($parish === '') {
            $parish = $defaultParish;
        }

        $religion = trim((string)($student['religion'] ?? ''));
        if ($religion === '') {
            $religion = $defaultReligion;
        }

        $guardianName = trim((string)($student['guardian_name'] ?? ''));
        if ($guardianName === '') {
            $guardianName = $defaultGuardianName;
        }

        $guardianRelation = trim((string)($student['guardian_relation'] ?? ''));
        if ($guardianRelation === '') {
            $guardianRelation = $defaultGuardianRelation;
        }

        $guardianEmail = trim((string)($student['guardian_email'] ?? ''));
        if ($guardianEmail === '') {
            $guardianEmail = $defaultGuardianEmail;
        }

        $guardianPhone = trim((string)($student['guardian_phone'] ?? ''));
        if ($guardianPhone === '') {
            $guardianPhone = $defaultGuardianPhone;
        }

        $nextKinName = trim((string)($student['emergency_contact_name'] ?? ''));
        if ($nextKinName === '') {
            $nextKinName = $defaultGuardianName;
        }

        $nextKinPhone = trim((string)($student['emergency_contact_phone'] ?? ''));
        if ($nextKinPhone === '') {
            $nextKinPhone = $defaultGuardianPhone;
        }

        $nextKinRelationship = trim((string)($student['emergency_contact_relationship'] ?? ''));
        if ($nextKinRelationship === '') {
            $nextKinRelationship = $defaultGuardianRelation;
        }

        $photo = trim((string)($student['photo'] ?? ''));
        if ($photo === '') {
            $photo = $defaultPhotoPath;
        }

        $updateStmt->execute([
            'phone' => $phone,
            'religion' => $religion,
            'district' => $district,
            'parish' => $parish,
            'nationality' => $nationality,
            'national_id' => $nationalId,
            'passport' => $passport !== '' ? $passport : null,
            'guardian_name' => $guardianName,
            'guardian_relation' => $guardianRelation,
            'guardian_email' => $guardianEmail,
            'guardian_phone' => $guardianPhone,
            'emergency_contact_name' => $nextKinName,
            'emergency_contact_phone' => $nextKinPhone,
            'emergency_contact_relationship' => $nextKinRelationship,
            'photo' => $photo,
            'id' => (int)$student['id'],
        ]);
        $updated++;
    }

    $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Completion failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Completed registration defaults for ' . $updated . ' student record(s).' . PHP_EOL;
echo 'Defaults used: nationality=' . $defaultNationality . ', guardian=' . $defaultGuardianName . ', phone=' . $defaultGuardianPhone . ', photo=' . $defaultPhotoPath . PHP_EOL;
