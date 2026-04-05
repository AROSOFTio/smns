<?php
/**
 * Student Transcript (standard format with SGPA/CGPA)
 */
require_once '../../config.php';
require_once BASE_PATH . '/core/SimplePdfDocument.php';

$session = new Session('student');
$auth = new Auth('student');

if (
    !isset($_SESSION['student_logged_in']) ||
    $_SESSION['student_logged_in'] !== true ||
    ($_SESSION['student_role'] ?? '') !== 'student'
) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=student');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$studentId = (int)($studentProfile['id'] ?? 0);

if ($studentId <= 0) {
    header('Location: ' . BASE_URL . '/views/student/dashboard.php?error=invalid_student');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$transcriptIssuanceService = new TranscriptIssuanceService($conn);
$transcriptIssuanceService->ensureSchema();
$currentIssuedTranscript = null;

// Transcript download rights are controlled by admin verification.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS transcript_download_rights (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL UNIQUE,
        status ENUM('granted','revoked') NOT NULL DEFAULT 'revoked',
        verified_by_user_id INT NULL,
        verified_at DATETIME NULL,
        revoked_by_user_id INT NULL,
        revoked_at DATETIME NULL,
        one_time_download_used TINYINT(1) NOT NULL DEFAULT 0,
        one_time_download_used_at DATETIME NULL,
        one_time_download_format VARCHAR(16) NULL,
        download_count INT NOT NULL DEFAULT 0,
        last_downloaded_at DATETIME NULL,
        last_download_format VARCHAR(16) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $ensureColumn = function ($columnName, $alterSql) use ($conn) {
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM transcript_download_rights LIKE :col");
            $stmt->execute(['col' => $columnName]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $conn->exec($alterSql);
            }
        } catch (Exception $e) {
            // Non-fatal; legacy environments may skip one-time tracking.
        }
    };
    $ensureColumn('one_time_download_used', "ALTER TABLE transcript_download_rights ADD COLUMN one_time_download_used TINYINT(1) NOT NULL DEFAULT 0 AFTER revoked_at");
    $ensureColumn('one_time_download_used_at', "ALTER TABLE transcript_download_rights ADD COLUMN one_time_download_used_at DATETIME NULL AFTER one_time_download_used");
    $ensureColumn('one_time_download_format', "ALTER TABLE transcript_download_rights ADD COLUMN one_time_download_format VARCHAR(16) NULL AFTER one_time_download_used_at");
    $ensureColumn('download_count', "ALTER TABLE transcript_download_rights ADD COLUMN download_count INT NOT NULL DEFAULT 0 AFTER one_time_download_format");
    $ensureColumn('last_downloaded_at', "ALTER TABLE transcript_download_rights ADD COLUMN last_downloaded_at DATETIME NULL AFTER download_count");
    $ensureColumn('last_download_format', "ALTER TABLE transcript_download_rights ADD COLUMN last_download_format VARCHAR(16) NULL AFTER last_downloaded_at");
} catch (Exception $e) {
}

$transcriptDownloadRightsGranted = false;
$transcriptDownloadAlreadyUsed = false;
$transcriptDownloadUsedAt = '';
$transcriptDownloadUsedFormat = '';
try {
    $rightsStmt = $conn->prepare("SELECT * FROM transcript_download_rights WHERE student_id = :student_id LIMIT 1");
    $rightsStmt->execute(['student_id' => $studentId]);
    $rightsRow = $rightsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $transcriptDownloadRightsGranted = (bool)($rightsRow && ($rightsRow['status'] ?? '') === 'granted');
    $transcriptDownloadAlreadyUsed = (bool)($rightsRow && (int)($rightsRow['one_time_download_used'] ?? 0) === 1);
    $transcriptDownloadUsedAt = (string)($rightsRow['one_time_download_used_at'] ?? '');
    $transcriptDownloadUsedFormat = strtoupper((string)($rightsRow['one_time_download_format'] ?? ''));
} catch (Exception $e) {
    $transcriptDownloadRightsGranted = false;
    $transcriptDownloadAlreadyUsed = false;
    $transcriptDownloadUsedAt = '';
    $transcriptDownloadUsedFormat = '';
}
$transcriptEligibility = getStudentTranscriptEligibility($conn, $studentId);
$transcriptViewGranted = false;
$transcriptDownloadAvailable = false;
$transcriptPdfAvailable = false;

// Student + program profile.
$studentStmt = $conn->prepare("
    SELECT
        s.*,
        p.program_code,
        p.program_name,
        p.duration_years
    FROM students s
    LEFT JOIN programs p ON p.id = s.program_id
    WHERE s.id = :student_id
    LIMIT 1
");
$studentStmt->execute(['student_id' => $studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if (!empty($student)) {
    $effectiveProgram = getStudentEffectiveProgram($conn, $studentId, [
        'program_id' => (int)($student['program_id'] ?? 0),
        'program_code' => (string)($student['program_code'] ?? ''),
        'program_name' => (string)($student['program_name'] ?? ''),
    ]);
    $student['program_id'] = (int)($effectiveProgram['program_id'] ?? ($student['program_id'] ?? 0));
    $student['program_code'] = (string)($effectiveProgram['program_code'] ?? ($student['program_code'] ?? ''));
    $student['program_name'] = (string)($effectiveProgram['program_name'] ?? ($student['program_name'] ?? ''));
}

// Pull all registrations with best-available result rows.
$transcriptStmt = $conn->prepare("
    SELECT
        cr.semester_id,
        sem.semester_name,
        sem.semester_number,
        sem.start_date AS semester_start,
        ay.year_name AS academic_year,
        ay.start_date AS ay_start,
        c.id AS course_id,
        c.course_code,
        c.course_name,
        COALESCE(c.credit_hours, 0) AS credit_hours,
        COALESCE(c.level_year, sr.year_of_study, s.level_year, s.study_year, 1) AS year_of_study,
        r.total_marks,
        r.grade,
        r.grade_points,
        r.status AS result_status
    FROM course_registrations cr
    INNER JOIN students s ON s.id = cr.student_id
    INNER JOIN courses c ON c.id = cr.course_id
    INNER JOIN semesters sem ON sem.id = cr.semester_id
    INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
    LEFT JOIN semester_registrations sr
        ON sr.student_id = cr.student_id
       AND sr.semester_id = cr.semester_id
       AND sr.status = 'approved'
    LEFT JOIN results r
        ON r.student_id = cr.student_id
       AND r.course_id = cr.course_id
       AND r.semester_id = cr.semester_id
    WHERE cr.student_id = :student_id
      AND COALESCE(LOWER(cr.status), '') <> 'dropped'
      AND (c.semester_offered = sem.semester_number OR c.semester_offered = 3)
      AND (
            NOT EXISTS (
                SELECT 1
                FROM semester_registrations srx
                WHERE srx.student_id = cr.student_id
                  AND srx.semester_id = cr.semester_id
                  AND srx.status = 'approved'
            )
            OR c.level_year = (
                SELECT sry.year_of_study
                FROM semester_registrations sry
                WHERE sry.student_id = cr.student_id
                  AND sry.semester_id = cr.semester_id
                  AND sry.status = 'approved'
                ORDER BY sry.id DESC
                LIMIT 1
            )
      )
    ORDER BY ay.start_date ASC, sem.semester_number ASC, c.course_code ASC
");
$transcriptStmt->execute(['student_id' => $studentId]);
$rawRows = $transcriptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Keep one best row per semester/course to avoid duplicate attempts in transcript output.
$statusRank = [
    'published' => 5,
    'approved' => 4,
    'submitted' => 3,
    'draft' => 2,
    '' => 1,
];
$bestRows = [];
foreach ($rawRows as $row) {
    $key = (int)($row['semester_id'] ?? 0) . '|' . (int)($row['course_id'] ?? 0) . '|' . strtoupper((string)($row['course_code'] ?? ''));
    $status = strtolower(trim((string)($row['result_status'] ?? '')));
    $rank = $statusRank[$status] ?? 1;
    if ($row['total_marks'] !== null) {
        $rank += 2;
    }
    if ($row['grade_points'] !== null) {
        $rank += 2;
    }
    if (!isset($bestRows[$key]) || $rank > (int)$bestRows[$key]['_rank']) {
        $row['_rank'] = $rank;
        $bestRows[$key] = $row;
    }
}

// Group by semester.
$terms = [];
foreach ($bestRows as $row) {
    $semesterId = (int)($row['semester_id'] ?? 0);
    if ($semesterId <= 0) {
        continue;
    }
    if (!isset($terms[$semesterId])) {
        $terms[$semesterId] = [
            'semester_id' => $semesterId,
            'semester_name' => (string)($row['semester_name'] ?? ('Semester ' . (int)($row['semester_number'] ?? 1))),
            'semester_number' => (int)($row['semester_number'] ?? 1),
            'academic_year' => (string)($row['academic_year'] ?? '-'),
            'year_of_study' => (int)($row['year_of_study'] ?? 1),
            'semester_start' => (string)($row['semester_start'] ?? ''),
            'ay_start' => (string)($row['ay_start'] ?? ''),
            'rows' => [],
            'attempted_credits' => 0.0,
            'earned_credits' => 0.0,
            'grade_points' => 0.0,
            'sgpa' => null,
        ];
    }
    unset($row['_rank']);
    $terms[$semesterId]['rows'][] = $row;
}

usort($terms, function ($a, $b) {
    $keyA = (string)($a['ay_start'] ?? '') . '|' . str_pad((string)($a['semester_number'] ?? 0), 2, '0', STR_PAD_LEFT);
    $keyB = (string)($b['ay_start'] ?? '') . '|' . str_pad((string)($b['semester_number'] ?? 0), 2, '0', STR_PAD_LEFT);
    return strcmp($keyA, $keyB);
});

// Compute SGPA/CGPA from published results.
$cumulativeCredits = 0.0;
$cumulativePoints = 0.0;
$finalCgpa = null;
foreach ($terms as $idx => $term) {
    $attempted = 0.0;
    $earned = 0.0;
    $points = 0.0;

    foreach ($term['rows'] as $courseRow) {
        $credits = (float)($courseRow['credit_hours'] ?? 0);
        $gradePoint = ($courseRow['grade_points'] !== null && $courseRow['grade_points'] !== '')
            ? (float)$courseRow['grade_points']
            : null;
        $status = strtolower(trim((string)($courseRow['result_status'] ?? '')));

        if ($status !== 'published' || $credits <= 0 || $gradePoint === null) {
            continue;
        }

        $attempted += $credits;
        $points += ($gradePoint * $credits);
        if ($gradePoint > 0) {
            $earned += $credits;
        }
    }

    $terms[$idx]['attempted_credits'] = $attempted;
    $terms[$idx]['earned_credits'] = $earned;
    $terms[$idx]['grade_points'] = $points;
    $terms[$idx]['sgpa'] = $attempted > 0 ? round($points / $attempted, 2) : null;

    if ($attempted > 0) {
        $cumulativeCredits += $attempted;
        $cumulativePoints += $points;
        $finalCgpa = round($cumulativePoints / $cumulativeCredits, 2);
    }
}

$totalCreditsAttempted = $cumulativeCredits;
$totalCreditsEarned = 0.0;
foreach ($terms as $term) {
    $totalCreditsEarned += (float)($term['earned_credits'] ?? 0);
}

$programDurationYears = max(0, (int)($student['duration_years'] ?? 0));
$highestCompletedStudyYear = 0;
foreach ($terms as $term) {
    $highestCompletedStudyYear = max($highestCompletedStudyYear, (int)($term['year_of_study'] ?? 0));
}
$completedFullProgram = !empty($transcriptEligibility['completed_studies']);
if ($programDurationYears > 0) {
    $completedFullProgram = $completedFullProgram && $highestCompletedStudyYear >= $programDurationYears;
}
$transcriptEligibility['completed_full_program'] = $completedFullProgram;
if (!$completedFullProgram) {
    $transcriptEligibility['eligible'] = false;
    $requiredYearsLabel = $programDurationYears > 0 ? (string)$programDurationYears : 'required';
    $transcriptEligibility['blocking_reasons'][] = 'Student has not completed the full programme duration (' . $requiredYearsLabel . ' year' . ($requiredYearsLabel === '1' ? '' : 's') . ').';
}
$transcriptViewGranted = $transcriptDownloadRightsGranted
    && (($transcriptEligibility['eligible'] ?? false) === true);
$transcriptDownloadAvailable = $transcriptViewGranted && !$transcriptDownloadAlreadyUsed;
$transcriptPdfAvailable = $transcriptViewGranted;

$cgpaClassification = 'In Progress';
if ($finalCgpa !== null) {
    if ($finalCgpa >= 4.50) {
        $cgpaClassification = 'First Class Distinction';
    } elseif ($finalCgpa >= 4.00) {
        $cgpaClassification = 'Second Class Upper';
    } elseif ($finalCgpa >= 3.00) {
        $cgpaClassification = 'Second Class Lower';
    } elseif ($finalCgpa >= 2.00) {
        $cgpaClassification = 'Pass';
    } else {
        $cgpaClassification = 'Probation';
    }
}

$markToLetterGrade = static function ($mark): string {
    if ($mark === null || $mark === '' || !is_numeric($mark)) {
        return '';
    }
    $score = (float)$mark;
    if ($score >= 80) {
        return 'A';
    }
    if ($score >= 75) {
        return 'B+';
    }
    if ($score >= 70) {
        return 'B';
    }
    if ($score >= 65) {
        return 'C+';
    }
    if ($score >= 60) {
        return 'C';
    }
    if ($score >= 50) {
        return 'D';
    }
    if ($score >= 40) {
        return 'E';
    }
    return 'F';
};

$toRoman = static function (int $number): string {
    $map = [
        10 => 'X',
        9 => 'IX',
        5 => 'V',
        4 => 'IV',
        1 => 'I',
    ];
    $result = '';
    foreach ($map as $value => $roman) {
        while ($number >= $value) {
            $result .= $roman;
            $number -= $value;
        }
    }
    return $result !== '' ? $result : 'I';
};

// Graduation/award metadata (latest explicit record wins).
$graduationAward = null;
try {
    $awardStmt = $conn->prepare("
        SELECT award_type, award_title, classification, cgpa_at_award, award_date, notes
        FROM student_graduation_awards
        WHERE student_id = :student_id
        ORDER BY award_date DESC, id DESC
        LIMIT 1
    ");
    $awardStmt->execute(['student_id' => $studentId]);
    $graduationAward = $awardStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $graduationAward = null;
}

if (!$graduationAward && (!empty($student['graduation_date']) || !empty($student['graduation_award_title']))) {
    $graduationAward = [
        'award_type' => 'degree',
        'award_title' => (string)($student['graduation_award_title'] ?? ''),
        'classification' => (string)($student['graduation_classification'] ?? ''),
        'cgpa_at_award' => $finalCgpa,
        'award_date' => (string)($student['graduation_date'] ?? ''),
        'notes' => '',
    ];
}

$institutionName = (string)getSetting('institution_name', INSTITUTION_NAME);
$institutionEmail = (string)getSetting('institution_email', '');
$institutionPhone = (string)getSetting('institution_phone', '');
$institutionAddress = (string)getSetting('institution_address', '');
$institutionLogoPath = BASE_URL . '/assets/img/sem.PNG';

$academicYearGroups = [];
foreach ($terms as $term) {
    $academicYearKey = (string)($term['academic_year'] ?? '-');
    if (!isset($academicYearGroups[$academicYearKey])) {
        $academicYearGroups[$academicYearKey] = [
            'academic_year' => $academicYearKey,
            'year_of_study' => (int)($term['year_of_study'] ?? 1),
            'ay_start' => (string)($term['ay_start'] ?? ''),
            'semesters' => [
                1 => null,
                2 => null,
            ],
        ];
    }

    $semesterRows = [];
    $marksTotal = 0.0;
    $marksCount = 0;
    foreach ((array)($term['rows'] ?? []) as $courseRow) {
        $marks = ($courseRow['total_marks'] !== null && $courseRow['total_marks'] !== '' && is_numeric($courseRow['total_marks']))
            ? round((float)$courseRow['total_marks'])
            : null;
        $status = strtolower(trim((string)($courseRow['result_status'] ?? '')));
        if ($status === 'published' && $marks !== null) {
            $marksTotal += $marks;
            $marksCount++;
        }
        $semesterRows[] = [
            'course_code' => (string)($courseRow['course_code'] ?? ''),
            'course_name' => (string)($courseRow['course_name'] ?? ''),
            'credit_hours' => (float)($courseRow['credit_hours'] ?? 0),
            'marks' => $marks,
            'grade' => (string)($courseRow['grade'] ?? ''),
            'status' => (string)($courseRow['result_status'] ?? ''),
        ];
    }

    $semesterAverage = $marksCount > 0 ? round($marksTotal / $marksCount) : null;
    $semesterAverageGrade = $semesterAverage !== null ? $markToLetterGrade($semesterAverage) : '';
    $semesterNumber = (int)($term['semester_number'] ?? 1);
    if (!isset($academicYearGroups[$academicYearKey]['semesters'][$semesterNumber])) {
        $academicYearGroups[$academicYearKey]['semesters'][$semesterNumber] = [
            'semester_name' => (string)($term['semester_name'] ?? ('Semester ' . $semesterNumber)),
            'semester_number' => $semesterNumber,
            'rows' => $semesterRows,
            'average_mark' => $semesterAverage,
            'average_grade' => $semesterAverageGrade,
        ];
    }
}

usort($academicYearGroups, static function ($a, $b) {
    return strcmp((string)($a['ay_start'] ?? ''), (string)($b['ay_start'] ?? ''));
});

$getVisibleSemesterSlots = static function (array $yearGroup): array {
    $visibleSlots = [];
    foreach ([1, 2] as $semesterSlot) {
        if (($yearGroup['semesters'][$semesterSlot] ?? null) !== null) {
            $visibleSlots[] = $semesterSlot;
        }
    }

    return $visibleSlots !== [] ? $visibleSlots : [1];
};

$buildTranscriptSnapshot = function () use ($student, $terms, $finalCgpa, $cgpaClassification, $totalCreditsAttempted, $totalCreditsEarned, $graduationAward) {
    $canonicalTerms = [];
    foreach ($terms as $term) {
        $termRows = [];
        foreach ((array)($term['rows'] ?? []) as $courseRow) {
            $termRows[] = [
                'course_code' => (string)($courseRow['course_code'] ?? ''),
                'course_name' => (string)($courseRow['course_name'] ?? ''),
                'credit_hours' => number_format((float)($courseRow['credit_hours'] ?? 0), 2, '.', ''),
                'total_marks' => $courseRow['total_marks'] !== null && $courseRow['total_marks'] !== '' ? number_format((float)$courseRow['total_marks'], 0, '.', '') : '',
                'grade' => (string)($courseRow['grade'] ?? ''),
                'grade_points' => $courseRow['grade_points'] !== null && $courseRow['grade_points'] !== '' ? number_format((float)$courseRow['grade_points'], 2, '.', '') : '',
                'result_status' => (string)($courseRow['result_status'] ?? ''),
            ];
        }
        $canonicalTerms[] = [
            'academic_year' => (string)($term['academic_year'] ?? ''),
            'semester_name' => (string)($term['semester_name'] ?? ''),
            'semester_number' => (int)($term['semester_number'] ?? 0),
            'year_of_study' => (int)($term['year_of_study'] ?? 0),
            'attempted_credits' => number_format((float)($term['attempted_credits'] ?? 0), 2, '.', ''),
            'earned_credits' => number_format((float)($term['earned_credits'] ?? 0), 2, '.', ''),
            'sgpa' => $term['sgpa'] !== null ? number_format((float)$term['sgpa'], 2, '.', '') : '',
            'rows' => $termRows,
        ];
    }

    return [
        'institution' => [
            'name' => (string)getSetting('institution_name', INSTITUTION_NAME),
            'base_url' => (string)BASE_URL,
        ],
        'student' => [
            'student_id' => (string)($student['student_id'] ?? ''),
            'name' => trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? '')),
            'program_code' => (string)($student['program_code'] ?? ''),
            'program_name' => (string)($student['program_name'] ?? ''),
        ],
        'summary' => [
            'final_cgpa' => $finalCgpa !== null ? number_format((float)$finalCgpa, 2, '.', '') : '',
            'classification' => (string)$cgpaClassification,
            'total_attempted_credits' => number_format((float)$totalCreditsAttempted, 2, '.', ''),
            'total_earned_credits' => number_format((float)$totalCreditsEarned, 2, '.', ''),
            'award_title' => (string)($graduationAward['award_title'] ?? ''),
            'award_classification' => (string)($graduationAward['classification'] ?? ''),
            'award_date' => (string)($graduationAward['award_date'] ?? ''),
        ],
        'terms' => $canonicalTerms,
    ];
};

$issueTranscriptSnapshot = function (string $format) use ($transcriptIssuanceService, $studentId, $buildTranscriptSnapshot, $currentUser) {
    $snapshot = $buildTranscriptSnapshot();

    return $transcriptIssuanceService->issueTranscript(
        $studentId,
        $snapshot,
        $format,
        isset($currentUser['id']) ? (int)$currentUser['id'] : null,
        'student_export'
    );
};

if ($transcriptViewGranted && !empty($student)) {
    try {
        $currentSnapshot = $buildTranscriptSnapshot();
        $currentSnapshotHash = $transcriptIssuanceService->computeSnapshotHash($currentSnapshot);
        $currentIssuedTranscript = $transcriptIssuanceService->findLatestActiveIssuanceForHash($studentId, $currentSnapshotHash);
    } catch (Exception $e) {
        $currentIssuedTranscript = null;
    }
}

// Export transcript dataset.
$export = strtolower(trim((string)($_GET['export'] ?? '')));
if ($export !== '' && !$transcriptDownloadAvailable) {
    if ($export === 'pdf' && $transcriptPdfAvailable) {
        // PDF exports remain available even after the one-time structured export is used.
    } elseif ($transcriptDownloadAlreadyUsed) {
        $when = $transcriptDownloadUsedAt !== '' ? date('Y-m-d H:i', strtotime($transcriptDownloadUsedAt)) : 'an earlier time';
        $format = $transcriptDownloadUsedFormat !== '' ? $transcriptDownloadUsedFormat : 'EXPORT';
        $session->setFlash('error', 'One-time transcript download was already used (' . $format . ') on ' . $when . '. Contact admin to re-enable.');
        header('Location: ' . BASE_URL . '/views/student/transcript.php');
        exit;
    } else {
        $blocking = !empty($transcriptEligibility['blocking_reasons']) ? implode(' ', $transcriptEligibility['blocking_reasons']) : '';
        $session->setFlash('error', 'Transcript export is unavailable. ' . trim('Admin approval and eligibility are required. ' . $blocking));
        header('Location: ' . BASE_URL . '/views/student/transcript.php');
        exit;
    }
}
$markOneTimeDownloadUsed = function (string $format) use ($conn, $studentId, $session) {
    try {
        $stmt = $conn->prepare("
            UPDATE transcript_download_rights
            SET one_time_download_used = 1,
                one_time_download_used_at = NOW(),
                one_time_download_format = :format,
                download_count = COALESCE(download_count, 0) + 1,
                last_downloaded_at = NOW(),
                last_download_format = :format2
            WHERE student_id = :student_id
              AND status = 'granted'
              AND COALESCE(one_time_download_used, 0) = 0
            LIMIT 1
        ");
        $stmt->execute([
            'format' => strtoupper($format),
            'format2' => strtoupper($format),
            'student_id' => $studentId
        ]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
};
if ($export === 'xml') {
    if (!$markOneTimeDownloadUsed('xml')) {
        $session->setFlash('error', 'Transcript one-time download is no longer available. Contact admin if you need re-enable.');
        header('Location: ' . BASE_URL . '/views/student/transcript.php');
        exit;
    }
    $issuance = $issueTranscriptSnapshot('xml');
    $filename = 'transcript_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($student['student_id'] ?? ('student_' . $studentId))) . '_' . date('Ymd_His') . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $root = $dom->createElement('transcript');
    $root->setAttribute('generated_at', date('c'));
    $dom->appendChild($root);

    $studentNode = $dom->createElement('student');
    $studentNode->appendChild($dom->createElement('name', trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''))));
    $studentNode->appendChild($dom->createElement('student_id', (string)($student['student_id'] ?? '')));
    $studentNode->appendChild($dom->createElement('program_code', (string)($student['program_code'] ?? '')));
    $studentNode->appendChild($dom->createElement('program_name', (string)($student['program_name'] ?? '')));
    $root->appendChild($studentNode);

    $termsNode = $dom->createElement('terms');
    foreach ($terms as $term) {
        $termNode = $dom->createElement('term');
        $termNode->appendChild($dom->createElement('academic_year', (string)($term['academic_year'] ?? '')));
        $termNode->appendChild($dom->createElement('semester_name', (string)($term['semester_name'] ?? '')));
        $termNode->appendChild($dom->createElement('semester_number', (string)($term['semester_number'] ?? '')));
        $termNode->appendChild($dom->createElement('year_of_study', (string)($term['year_of_study'] ?? '')));
        $termNode->appendChild($dom->createElement('attempted_credits', (string)number_format((float)($term['attempted_credits'] ?? 0), 2, '.', '')));
        $termNode->appendChild($dom->createElement('earned_credits', (string)number_format((float)($term['earned_credits'] ?? 0), 2, '.', '')));
        $termNode->appendChild($dom->createElement('sgpa', $term['sgpa'] !== null ? (string)number_format((float)$term['sgpa'], 2, '.', '') : ''));

        $coursesNode = $dom->createElement('courses');
        foreach (($term['rows'] ?? []) as $courseRow) {
            $courseNode = $dom->createElement('course');
            $courseNode->appendChild($dom->createElement('course_code', (string)($courseRow['course_code'] ?? '')));
            $courseNode->appendChild($dom->createElement('course_name', (string)($courseRow['course_name'] ?? '')));
            $courseNode->appendChild($dom->createElement('credit_hours', (string)($courseRow['credit_hours'] ?? '0')));
            $courseNode->appendChild($dom->createElement('grade', (string)($courseRow['grade'] ?? '')));
            $courseNode->appendChild($dom->createElement('grade_points', ($courseRow['grade_points'] !== null && $courseRow['grade_points'] !== '') ? (string)$courseRow['grade_points'] : ''));
            $courseNode->appendChild($dom->createElement('result_status', (string)($courseRow['result_status'] ?? '')));
            $coursesNode->appendChild($courseNode);
        }
        $termNode->appendChild($coursesNode);
        $termsNode->appendChild($termNode);
    }
    $root->appendChild($termsNode);

    $summaryNode = $dom->createElement('summary');
    $summaryNode->appendChild($dom->createElement('final_cgpa', $finalCgpa !== null ? (string)number_format((float)$finalCgpa, 2, '.', '') : ''));
    $summaryNode->appendChild($dom->createElement('classification', (string)$cgpaClassification));
    $summaryNode->appendChild($dom->createElement('total_attempted_credits', (string)number_format((float)$totalCreditsAttempted, 2, '.', '')));
    $summaryNode->appendChild($dom->createElement('total_earned_credits', (string)number_format((float)$totalCreditsEarned, 2, '.', '')));
    $summaryNode->appendChild($dom->createElement('award_title', (string)($graduationAward['award_title'] ?? '')));
    $summaryNode->appendChild($dom->createElement('award_classification', (string)($graduationAward['classification'] ?? '')));
    $summaryNode->appendChild($dom->createElement('award_date', (string)($graduationAward['award_date'] ?? '')));
    $root->appendChild($summaryNode);

    $issuanceNode = $dom->createElement('verification');
    $issuanceNode->appendChild($dom->createElement('verification_code', (string)($issuance['verification_code'] ?? '')));
    $issuanceNode->appendChild($dom->createElement('verification_token', (string)($issuance['verification_token'] ?? '')));
    $issuanceNode->appendChild($dom->createElement('verification_url', (string)($issuance['verification_url'] ?? '')));
    $issuanceNode->appendChild($dom->createElement('transcript_hash', (string)($issuance['transcript_hash'] ?? '')));
    $issuanceNode->appendChild($dom->createElement('issued_at', (string)($issuance['issued_at'] ?? '')));
    $root->appendChild($issuanceNode);

    echo $dom->saveXML();
    exit;
}

if ($export === 'pdf') {
    if (!$transcriptPdfAvailable) {
        $blocking = !empty($transcriptEligibility['blocking_reasons']) ? implode(' ', $transcriptEligibility['blocking_reasons']) : '';
        $session->setFlash('error', 'Transcript PDF is unavailable. ' . trim('Admin approval and eligibility are required. ' . $blocking));
        header('Location: ' . BASE_URL . '/views/student/transcript.php');
        exit;
    }

    $issuance = $issueTranscriptSnapshot('pdf');
    $filename = 'transcript_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($student['student_id'] ?? ('student_' . $studentId))) . '_' . date('Ymd_His') . '.pdf';

    $pdf = new SimplePdfDocument();
    $pageWidth = 595.28;
    $pageHeight = 841.89;
    $pdf->addPage($pageWidth, $pageHeight);

    $truncatePdfText = static function (string $text, int $maxChars): string {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $maxChars ? rtrim((string)mb_substr($text, 0, max(0, $maxChars - 1))) . '.' : $text;
        }
        return strlen($text) > $maxChars ? rtrim(substr($text, 0, max(0, $maxChars - 1))) . '.' : $text;
    };

    $estimatePdfTextWidth = static function (string $text, float $fontSize, bool $bold = false): float {
        $length = function_exists('mb_strlen') ? (float)mb_strlen($text) : (float)strlen($text);
        $factor = $bold ? 0.57 : 0.52;
        return $length * $fontSize * $factor;
    };

    $drawPdfCentered = static function (SimplePdfDocument $pdfDoc, float $centerX, float $y, string $text, float $fontSize, string $style = '') use ($estimatePdfTextWidth): void {
        $textWidth = $estimatePdfTextWidth($text, $fontSize, strtoupper($style) === 'B');
        $pdfDoc->text($centerX - ($textWidth / 2), $y, $text, $fontSize, $style);
    };

    $drawPdfMeta = static function (SimplePdfDocument $pdfDoc, float $x, float $y, string $label, string $value, int $maxChars = 18) use ($truncatePdfText): void {
        $pdfDoc->text($x, $y, strtoupper($label), 5.2, 'B');
        $pdfDoc->text($x, $y + 8, $truncatePdfText($value, $maxChars), 6.4, '');
    };

    $margin = 14.0;
    $contentWidth = $pageWidth - ($margin * 2);

    $headerY = $margin;
    $headerH = 36.0;
    $centerX = $pageWidth / 2;
    $drawPdfCentered($pdf, $centerX, $headerY + 8, strtoupper($institutionName), 12.8, 'B');
    $drawPdfCentered($pdf, $centerX, $headerY + 17, 'OFFICE OF THE DEAN OF STUDIES', 6.1, 'B');
    $drawPdfCentered($pdf, $centerX, $headerY + 25, 'OFFICIAL ACADEMIC TRANSCRIPT', 7.3, 'B');
    $drawPdfCentered($pdf, $centerX, $headerY + 32, 'Academic Transcript', 5.2, '');
    $pdf->text($pageWidth - 112, $headerY + 7, $truncatePdfText($institutionAddress, 28), 5, '');
    $pdf->text($pageWidth - 112, $headerY + 15, 'Tel: ' . (string)$institutionPhone, 5, '');
    $pdf->text($pageWidth - 112, $headerY + 23, 'Email: ' . (string)$institutionEmail, 5, '');
    $pdf->text($pageWidth - 112, $headerY + 31, 'Date: ' . date('D j M Y'), 5, '');

    $metaY = $headerY + $headerH + 4;
    $metaGap = 6.0;
    $metaCols = 4;
    $metaW = ($contentWidth - ($metaGap * ($metaCols - 1))) / $metaCols;
    $studentName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
    $drawPdfMeta($pdf, $margin, $metaY, 'Name', $studentName, 22);
    $drawPdfMeta($pdf, $margin + $metaW + $metaGap, $metaY, 'Reg No', (string)($student['student_id'] ?? ($student['admission_number'] ?? 'N/A')), 18);
    $drawPdfMeta($pdf, $margin + (($metaW + $metaGap) * 2), $metaY, 'Sex', (string)($student['gender'] ?? 'N/A'), 10);
    $drawPdfMeta($pdf, $margin + (($metaW + $metaGap) * 3), $metaY, 'Nationality', (string)($student['country'] ?? 'N/A'), 14);
    $drawPdfMeta($pdf, $margin, $metaY + 16, 'Date Of Birth', !empty($student['date_of_birth']) ? (string)Helper::formatDate((string)$student['date_of_birth'], 'd-M-Y') : 'N/A', 14);
    $drawPdfMeta($pdf, $margin + $metaW + $metaGap, $metaY + 16, 'Intake', (string)($student['entry_year'] ?? 'N/A'), 10);
    $drawPdfMeta($pdf, $margin + (($metaW + $metaGap) * 2), $metaY + 16, 'Entry Mode', !empty($student['entry_semester_id']) ? 'Direct' : 'N/A', 12);
    $drawPdfMeta($pdf, $margin + (($metaW + $metaGap) * 3), $metaY + 16, 'Programme', trim((string)($student['program_code'] ?? '') . ' ' . (string)($student['program_name'] ?? 'N/A')), 20);

    $columnGap = 10.0;
    $leftX = $margin;
    $rightX = $margin + (($contentWidth - $columnGap) / 2) + $columnGap;
    $columnW = ($contentWidth - $columnGap) / 2;
    $leftY = $metaY + 36;
    $rightY = $metaY + 36;

    $drawSemesterBlock = static function (
        SimplePdfDocument $pdfDoc,
        float $x,
        float $y,
        float $width,
        array $block,
        callable $truncate
    ): float {
        $rows = (!empty($block['semester']['rows']) && is_array($block['semester']['rows'])) ? $block['semester']['rows'] : [];
        $rowHeight = 6.8;
        $sectionHeight = 15 + (count($rows) * $rowHeight) + 8;

        $pdfDoc->text($x, $y, 'ACADEMIC YEAR: ' . (string)($block['academic_year'] ?? '-'), 5.8, 'B');
        $pdfDoc->text($x + $width - 54, $y, (string)($block['stage'] ?? ''), 5.8, 'B');
        $pdfDoc->text($x, $y + 7, strtoupper((string)($block['semester_label'] ?? 'Semester')), 5.1, 'B');

        $headerY = $y + 12;
        $codeW = 42.0;
        $markW = 24.0;
        $gradeW = 20.0;
        $creditW = 18.0;
        $titleW = $width - $codeW - $markW - $gradeW - $creditW;

        $xCode = $x;
        $xTitle = $xCode + $codeW;
        $xMark = $xTitle + $titleW;
        $xGrade = $xMark + $markW;
        $xCredit = $xGrade + $gradeW;

        $pdfDoc->text($xCode, $headerY, 'CODE', 4.3, 'B');
        $pdfDoc->text($xTitle, $headerY, 'COURSE TITLE', 4.3, 'B');
        $pdfDoc->text($xMark, $headerY, 'MARK', 4.3, 'B');
        $pdfDoc->text($xGrade, $headerY, 'GRADE', 4.3, 'B');
        $pdfDoc->text($xCredit, $headerY, 'CR', 4.3, 'B');

        $currentRowY = $headerY + 6;
        foreach ($rows as $courseRow) {
            $pdfDoc->text($xCode, $currentRowY, $truncate((string)($courseRow['course_code'] ?? ''), 10), 4.75, '');
            $pdfDoc->text($xTitle, $currentRowY, $truncate((string)($courseRow['course_name'] ?? ''), 34), 4.75, '');
            $pdfDoc->text($xMark + 2, $currentRowY, ($courseRow['marks'] !== null ? (string)$courseRow['marks'] : '-'), 4.75, '');
            $pdfDoc->text($xGrade + 2, $currentRowY, (string)($courseRow['grade'] ?? '-'), 4.75, '');
            $pdfDoc->text($xCredit + 2, $currentRowY, number_format((float)($courseRow['credit_hours'] ?? 0), 0), 4.75, '');
            $currentRowY += $rowHeight;
        }

        $averageMark = ($block['semester']['average_mark'] ?? null) !== null ? (string)$block['semester']['average_mark'] : '-';
        $averageGrade = (string)($block['semester']['average_grade'] ?? '-');
        $pdfDoc->text($xCode, $currentRowY, 'AVERAGE', 4.75, 'B');
        $pdfDoc->text($xMark + 2, $currentRowY, $averageMark, 4.75, 'B');
        $pdfDoc->text($xGrade + 2, $currentRowY, $averageGrade, 4.75, 'B');

        return $sectionHeight;
    };

    foreach ($academicYearGroups as $yearGroup) {
        $rowY = max($leftY, $rightY);
        $rowHeight = 0.0;
        $visibleSemesterSlots = $getVisibleSemesterSlots($yearGroup);

        foreach (array_values($visibleSemesterSlots) as $slotIndex => $semesterSlot) {
            $semesterBlock = $yearGroup['semesters'][$semesterSlot] ?? null;
            $block = [
                'academic_year' => (string)($yearGroup['academic_year'] ?? '-'),
                'stage' => strtoupper((string)($student['program_code'] ?? 'PROGRAM') . ' ' . $toRoman((int)($yearGroup['year_of_study'] ?? 1))),
                'semester_label' => 'Semester ' . ($semesterSlot === 1 ? 'I' : 'II'),
                'semester' => $semesterBlock,
            ];
            $blockX = $slotIndex === 0 ? $leftX : $rightX;
            $usedHeight = $drawSemesterBlock($pdf, $blockX, $rowY, $columnW, $block, $truncatePdfText);
            $rowHeight = max($rowHeight, $usedHeight);
        }

        $leftY = $rowY + $rowHeight + 6;
        $rightY = $leftY;
    }

    $resultsBottomY = max($leftY, $rightY) + 2;
    $gradingY = min($resultsBottomY, $pageHeight - 110);
    $gradingW = ($contentWidth * 0.58);
    $awardW = $contentWidth - $gradingW - 8.0;
    $pdf->text($margin, $gradingY, 'GRADING KEY', 5.6, 'B');
    $pdf->text($margin, $gradingY + 10, 'A: 80-100 First Class | B+: 75-79 Second Class Upper', 4.8, '');
    $pdf->text($margin, $gradingY + 18, 'B: 70-74 Second Class Upper | C+: 65-69 Second Class Lower', 4.8, '');
    $pdf->text($margin, $gradingY + 26, 'C: 60-64 Pass | D: 50-59 Pass | E: 40-49 Fail | F: 0-39 Fail', 4.8, '');

    $awardX = $margin + $gradingW + 8.0;
    $pdf->text($awardX, $gradingY, 'AWARD SUMMARY', 5.6, 'B');
    $pdf->text($awardX, $gradingY + 10, 'Award: ' . $truncatePdfText((string)($graduationAward['award_title'] ?? ($student['program_name'] ?? 'Pending')), 30), 4.8, '');
    $pdf->text($awardX, $gradingY + 18, 'Grade: ' . $truncatePdfText((string)($graduationAward['classification'] ?? $cgpaClassification), 20), 4.8, '');
    $pdf->text($awardX, $gradingY + 26, 'CGPA: ' . ($finalCgpa !== null ? number_format((float)$finalCgpa, 2) : 'N/A'), 4.8, '');
    $pdf->text($awardX, $gradingY + 34, 'Credits: ' . number_format((float)$totalCreditsEarned, 0), 4.8, '');
    $pdf->text($awardX, $gradingY + 42, 'Award Date: ' . (!empty($graduationAward['award_date']) ? (string)Helper::formatDate((string)$graduationAward['award_date'], 'M d, Y') : 'Pending'), 4.8, '');

    $verificationY = $pageHeight - 38;
    $pdf->text($margin, $verificationY, 'VERIFICATION CODE', 4.8, 'B');
    $pdf->text($margin + 70, $verificationY, 'ISSUED AT', 4.8, 'B');
    $pdf->text($margin, $verificationY + 8, (string)($issuance['verification_code'] ?? ''), 4.8, '');
    $pdf->text($margin + 70, $verificationY + 8, (string)Helper::formatDateTime((string)($issuance['issued_at'] ?? ''), 'M d, Y g:i A'), 4.8, '');
    $pdf->text($margin, $verificationY + 18, 'Hash: ' . $truncatePdfText((string)($issuance['transcript_hash'] ?? ''), 76), 4.5, '');
    $pdf->text($margin, $verificationY + 26, 'Verify: ' . $truncatePdfText((string)($issuance['verification_url'] ?? ''), 88), 4.5, '');

    $pdfBinary = $pdf->outputString();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
}

if ($export === 'csv' || $export === 'excel') {
    if (!$markOneTimeDownloadUsed($export)) {
        $session->setFlash('error', 'Transcript one-time download is no longer available. Contact admin if you need re-enable.');
        header('Location: ' . BASE_URL . '/views/student/transcript.php');
        exit;
    }
    $issuance = $issueTranscriptSnapshot($export);
    $isExcel = ($export === 'excel');
    $filename = 'transcript_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($student['student_id'] ?? ('student_' . $studentId))) . '_' . date('Ymd_His') . ($isExcel ? '.xls' : '.csv');
    if ($isExcel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Name', trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''))]);
    fputcsv($out, ['Student ID', (string)($student['student_id'] ?? '')]);
    fputcsv($out, ['Program', trim((string)($student['program_code'] ?? '') . ' - ' . (string)($student['program_name'] ?? ''))]);
    fputcsv($out, ['Verification Code', (string)($issuance['verification_code'] ?? '')]);
    fputcsv($out, ['Verification Token', (string)($issuance['verification_token'] ?? '')]);
    fputcsv($out, ['Verification URL', (string)($issuance['verification_url'] ?? '')]);
    fputcsv($out, ['Transcript Hash', (string)($issuance['transcript_hash'] ?? '')]);
    fputcsv($out, []);
    fputcsv($out, ['Academic Year', 'Semester', 'Year of Study', 'Course Code', 'Course Name', 'Credit Hours', 'Grade', 'Grade Points', 'Result Status']);

    foreach ($terms as $term) {
        foreach ($term['rows'] as $courseRow) {
            fputcsv($out, [
                (string)($term['academic_year'] ?? ''),
                (string)($term['semester_name'] ?? ''),
                (string)($term['year_of_study'] ?? ''),
                (string)($courseRow['course_code'] ?? ''),
                (string)($courseRow['course_name'] ?? ''),
                (string)($courseRow['credit_hours'] ?? ''),
                (string)($courseRow['grade'] ?? ''),
                (string)($courseRow['grade_points'] ?? ''),
                (string)($courseRow['result_status'] ?? ''),
            ]);
        }
        fputcsv($out, [
            'TERM SUMMARY',
            (string)($term['semester_name'] ?? ''),
            '',
            '',
            '',
            'Attempted: ' . number_format((float)$term['attempted_credits'], 0),
            '',
            '',
            'SGPA: ' . ($term['sgpa'] !== null ? number_format((float)$term['sgpa'], 2) : 'N/A')
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ['Final CGPA', $finalCgpa !== null ? number_format((float)$finalCgpa, 2) : 'N/A']);
    fputcsv($out, ['Classification', $cgpaClassification]);
    fputcsv($out, ['Total Attempted Credits', number_format((float)$totalCreditsAttempted, 0)]);
    fputcsv($out, ['Total Earned Credits', number_format((float)$totalCreditsEarned, 0)]);
    if (!empty($graduationAward)) {
        fputcsv($out, ['Award Title', (string)($graduationAward['award_title'] ?? '')]);
        fputcsv($out, ['Award Classification', (string)($graduationAward['classification'] ?? '')]);
        fputcsv($out, ['Award Date', (string)($graduationAward['award_date'] ?? '')]);
    }

    fclose($out);
    exit;
}

$pageTitle = 'My Transcript - ' . APP_NAME;
include '../../includes/header.php';
?>

<div class="student-sidebar">
    <div class="sidebar-user-card">
        <div class="sidebar-portal-title">SMNS-STUDENT PORTAL</div>
        <?php if (!empty($studentProfile['photo'])): ?>
            <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile">
        <?php else: ?>
            <img src="/assets/img/student_sample.jpg" alt="Profile">
        <?php endif; ?>
        <div class="sidebar-user-name">
            <?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?>
        </div>
        <div class="sidebar-user-no"><?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
    </div>
    <ul>
        <li><a href="<?php echo BASE_URL; ?>/views/student/generate_prn.php">GENERATE PRN</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/course-registration.php">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/payments.php">PAYMENTS</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/my-courses.php">MY COURSES & RESULTS</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="<?php echo BASE_URL; ?>/views/student/services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="<?php echo BASE_URL; ?>/views/student/services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="<?php echo BASE_URL; ?>/views/student/services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo BASE_URL; ?>/views/student/dashboard.php">BIO DATA</a></li>
        <li class="active"><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/notifications.php">MY MAILBOX</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/academic-calendar.php">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<style>
body { background: #f8fafc; }
.student-sidebar {
    width: 230px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    min-height: 100vh;
    height: 100vh;
    overflow-y: auto;
    overflow-x: hidden;
    border-right: 1px solid #e5e7eb;
    position: fixed;
    left: 0;
    top: 0;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(15, 23, 42, 0.04);
    transition: transform 0.25s ease;
}
.student-sidebar ul {
    list-style: none;
    padding: 10px 8px;
    margin: 0;
}
.student-sidebar > ul { padding-bottom: 20px; }
.student-sidebar li {
    padding: 9px 12px;
    margin-bottom: 4px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 0.82rem;
    letter-spacing: 0.02em;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s ease;
}
.student-sidebar li a {
    color: inherit;
    text-decoration: none;
    display: block;
}
.student-sidebar li.active {
    background: #eaf2ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
    font-weight: 700;
}
.student-sidebar li:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.sidebar-user-card {
    margin: 0.4rem 0.45rem 0.15rem;
    background: linear-gradient(180deg, #31465d 0%, #243547 100%);
    border-radius: 10px;
    color: #fff;
    text-align: center;
    padding: 0.4rem 0.45rem 0.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.28rem;
}
.sidebar-user-card img { width: 126px; height: 126px; border-radius: 16px; object-fit: cover; border: 2px solid rgba(255,255,255,0.82); box-shadow: 0 8px 18px rgba(0,0,0,0.2); }
.sidebar-user-name { font-size: 0.8rem; line-height: 1.12; margin: 0; }
.sidebar-user-no { font-size: 1rem; font-weight: 700; line-height: 1.08; margin: 0; }
.sidebar-portal-title { font-size: 0.6rem; letter-spacing: 0.07em; text-transform: uppercase; color: #d7e3f3; margin-bottom: 0.12rem; font-weight: 700; }
.services-submenu {
    margin: 0 0 0.2rem 0;
    padding: 0 0 0 0.8rem;
    border-left: 2px solid #e2e8f0;
}
.services-submenu li {
    margin-bottom: 3px;
    font-size: 0.76rem;
    padding: 6px 8px;
}
.main-content {
    margin-left: 230px;
    width: calc(100vw - 230px);
    max-width: calc(100vw - 230px);
    min-height: 100vh;
    background: #f8fafc;
    transition: margin-left 0.25s ease, width 0.25s ease;
}
.student-sidebar.sidebar-collapsed {
    transform: translateX(-100%);
}
.main-content.full-width {
    margin-left: 0;
    width: 100vw;
    max-width: 100vw;
}
.topbar-right {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.55rem;
}
.transcript-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.42rem;
    min-height: 40px;
    padding: 0.52rem 0.95rem;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 700;
    text-decoration: none;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease;
}
.transcript-action:hover {
    transform: translateY(-1px);
    text-decoration: none;
}
.transcript-action-primary {
    color: #fff;
    background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
    border: 1px solid #1d4ed8;
    box-shadow: 0 12px 28px rgba(29, 78, 216, 0.24);
}
.transcript-action-primary:hover {
    color: #fff;
    box-shadow: 0 15px 30px rgba(29, 78, 216, 0.3);
}
.transcript-action-secondary {
    color: #1e293b;
    background: #fff;
    border: 1px solid #cbd5e1;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}
.transcript-action-secondary:hover {
    color: #0f172a;
    background: #f8fafc;
}
.transcript-action-disabled {
    opacity: 0.58;
    cursor: not-allowed;
    box-shadow: none;
}
.transcript-paper {
    background: #fff;
    border: 1px solid #d6dde8;
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.transcript-masthead {
    padding: 1rem 1.25rem 0.85rem;
    border-bottom: 3px double #1f2937;
}
.transcript-brand {
    display: grid;
    grid-template-columns: 88px 1fr 220px;
    gap: 0.9rem;
    align-items: center;
}
.transcript-logo-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
}
.transcript-logo {
    width: 74px;
    height: 74px;
    object-fit: contain;
}
.transcript-brand-center {
    text-align: center;
}
.transcript-school-name {
    margin: 0;
    font-size: 1.6rem;
    line-height: 1.1;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-weight: 800;
    color: #0f172a;
}
.transcript-office-title {
    margin: 0.28rem 0 0;
    font-size: 0.86rem;
    font-style: italic;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #1f2937;
}
.transcript-document-title {
    margin: 0.18rem 0 0;
    font-size: 1rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #111827;
}
.transcript-document-subtitle {
    margin: 0.14rem 0 0;
    font-size: 0.76rem;
    font-weight: 700;
    color: #334155;
}
.transcript-brand-side {
    font-size: 0.77rem;
    line-height: 1.45;
    color: #334155;
}
.transcript-meta-strip {
    display: grid;
    grid-template-columns: 92px repeat(4, minmax(0, 1fr));
    gap: 0.42rem 0.55rem;
    padding: 0.7rem 1.1rem;
    border-bottom: 1px solid #cfd8e3;
    background: #fafbfd;
    align-items: stretch;
}
.meta-photo-item {
    display: flex;
    align-items: stretch;
    justify-content: center;
    grid-row: 1 / span 2;
    padding: 0;
    min-height: 0;
    background: transparent;
    border-color: transparent;
}
.meta-photo-frame {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.35rem;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    background: linear-gradient(180deg, #ffffff 0%, #f6f9fc 100%);
}
.student-transcript-photo-wrap {
    width: 100%;
    aspect-ratio: 5 / 6;
    min-height: 118px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: linear-gradient(180deg, #ffffff 0%, #eef4fb 100%);
}
.student-transcript-photo {
    width: 100%;
    height: 100%;
    min-height: 118px;
    object-fit: contain;
    object-position: center;
    border: none;
    border-radius: 0;
    background: #fff;
    display: block;
}
.student-transcript-photo-placeholder {
    width: 100%;
    height: 100%;
    min-height: 118px;
    display: none;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 0.65rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    background: linear-gradient(180deg, #ffffff 0%, #eef4fb 100%);
}
.meta-photo-caption {
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    text-align: center;
    color: #64748b;
}
.meta-item {
    border: 1px solid #dbe4ee;
    padding: 0.48rem 0.62rem 0.42rem;
    min-height: 56px;
    background: #fff;
    border-radius: 10px;
}
.meta-label,
.transcript-footer-label {
    display: block;
    font-size: 0.7rem;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 0.18rem;
}
.meta-value,
.transcript-footer-value {
    color: #0f172a;
    font-size: 0.98rem;
    line-height: 1.22;
    font-weight: 700;
}
.year-sheet {
    padding: 0.85rem 1.1rem 1rem;
    border-bottom: 1px solid #dbe4ee;
}
.year-sheet:last-of-type {
    border-bottom: 0;
}
.year-heading {
    display: flex;
    justify-content: space-between;
    gap: 0.8rem;
    align-items: baseline;
    padding-bottom: 0.28rem;
    margin-bottom: 0.6rem;
    border-bottom: 1px solid #94a3b8;
}
.year-heading-title,
.year-heading-stage {
    font-size: 0.95rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #111827;
}
.year-semesters {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.8rem;
}
.semester-panel {
    border: 1px solid #b8c4d4;
    min-height: 100%;
}
.semester-panel-title {
    padding: 0.32rem 0.5rem;
    border-bottom: 1px solid #b8c4d4;
    background: #eff4f9;
    font-size: 0.78rem;
    font-weight: 800;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.transcript-table {
    width: 100%;
    border-collapse: collapse;
}
.transcript-table th,
.transcript-table td {
    border: 1px solid #cbd5e1;
    padding: 0.26rem 0.32rem;
    font-size: 0.75rem;
    vertical-align: top;
    color: #0f172a;
}
.transcript-table th {
    background: #f8fafc;
    font-weight: 800;
    text-transform: uppercase;
}
.transcript-table .col-code { width: 18%; }
.transcript-table .col-title { width: 54%; }
.transcript-table .col-mark,
.transcript-table .col-grade,
.transcript-table .col-credit { width: 9%; text-align: center; }
.average-row td {
    font-weight: 800;
    background: #f8fafc;
}
.empty-row td {
    height: 1.42rem;
}
.transcript-footer-band {
    display: grid;
    grid-template-columns: 1.1fr 0.9fr 0.8fr;
    gap: 1rem;
    padding: 0.95rem 1.25rem 1.1rem;
    border-top: 3px double #1f2937;
    background: #fafbfd;
}
.grading-key {
    font-size: 0.78rem;
    line-height: 1.55;
    color: #1f2937;
}
.grading-key-title {
    margin-bottom: 0.35rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.award-box {
    display: grid;
    gap: 0.45rem;
}
.award-line {
    display: flex;
    gap: 0.5rem;
    align-items: baseline;
}
.award-line strong {
    min-width: 112px;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.signature-box {
    display: grid;
    align-content: end;
    gap: 0.65rem;
    padding: 0.35rem 0.45rem 0.15rem;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}
.signature-space {
    min-height: 70px;
    border-bottom: 1.5px solid #334155;
}
.signature-label {
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #0f172a;
}
.signature-subtext {
    font-size: 0.72rem;
    line-height: 1.45;
    color: #475569;
}
.verification-panel {
    display: grid;
    grid-template-columns: minmax(160px, 190px) 1fr;
    gap: 0.8rem;
    padding: 0.95rem 1.25rem 1.15rem;
    border-top: 1px solid #dbe4ee;
    background: #fff;
}
.verification-qr-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 150px;
    border: 1px dashed #b8c4d4;
    background: #fafbfd;
}
.verification-qr { width: 140px; height: 140px; }
.verification-meta {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.55rem;
}
.verification-card {
    border: 1px solid #dbe4ee;
    padding: 0.5rem 0.6rem;
    background: #fafbfd;
}
.verification-card .label { color:#64748b; font-size:.69rem; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.verification-card .value { color:#0f172a; font-size:.84rem; font-weight:700; word-break:break-word; }
.verification-note { margin-top:.45rem; color:#475569; font-size:.79rem; }
.verification-link { margin-top:.28rem; font-size:.75rem; color:#1d4ed8; word-break:break-all; }
html[data-theme='dark'] .transcript-paper { background: #0f172a; border-color: #334155; box-shadow: 0 16px 34px rgba(2, 6, 23, 0.54); }
html[data-theme='dark'] .transcript-masthead,
html[data-theme='dark'] .year-heading,
html[data-theme='dark'] .transcript-footer-band { border-color: #475569; }
html[data-theme='dark'] .transcript-school-name,
html[data-theme='dark'] .transcript-office-title,
html[data-theme='dark'] .transcript-document-title,
html[data-theme='dark'] .year-heading-title,
html[data-theme='dark'] .year-heading-stage,
html[data-theme='dark'] .meta-value,
html[data-theme='dark'] .transcript-footer-value,
html[data-theme='dark'] .award-line,
html[data-theme='dark'] .grading-key,
html[data-theme='dark'] .transcript-table th,
html[data-theme='dark'] .transcript-table td,
html[data-theme='dark'] .verification-card .value { color: #e2e8f0; }
html[data-theme='dark'] .transcript-brand-side,
html[data-theme='dark'] .meta-label,
html[data-theme='dark'] .transcript-footer-label,
html[data-theme='dark'] .verification-card .label,
html[data-theme='dark'] .verification-note,
html[data-theme='dark'] .verification-link { color: #93c5fd; }
html[data-theme='dark'] .transcript-meta-strip,
html[data-theme='dark'] .transcript-footer-band,
html[data-theme='dark'] .verification-panel { background: #111827; border-color: #334155; }
html[data-theme='dark'] .meta-photo-frame,
html[data-theme='dark'] .signature-box,
html[data-theme='dark'] .meta-item,
html[data-theme='dark'] .verification-card {
    background: #0f172a;
    border-color: #334155;
}
html[data-theme='dark'] .meta-photo-caption,
html[data-theme='dark'] .signature-subtext { color: #93c5fd; }
html[data-theme='dark'] .signature-label { color: #e2e8f0; }
html[data-theme='dark'] .signature-space { border-color: #93c5fd; }
html[data-theme='dark'] .transcript-action-primary {
    color: #fff;
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border-color: #3b82f6;
}
html[data-theme='dark'] .transcript-action-secondary {
    color: #e2e8f0;
    background: #0f172a;
    border-color: #334155;
    box-shadow: none;
}
html[data-theme='dark'] .transcript-action-secondary:hover {
    color: #f8fafc;
    background: #172033;
}
html[data-theme='dark'] .meta-item,
html[data-theme='dark'] .semester-panel,
html[data-theme='dark'] .verification-card,
html[data-theme='dark'] .verification-qr-wrap { background: #0b1220; border-color: #334155; }
html[data-theme='dark'] .semester-panel-title,
html[data-theme='dark'] .transcript-table th,
html[data-theme='dark'] .average-row td { background: #152238; border-color: #334155; }
html[data-theme='dark'] .transcript-table td,
html[data-theme='dark'] .year-sheet { border-color: #334155; }
html[data-theme='dark'] .student-sidebar {
    background: #0f172a;
    border-right-color: #233147;
    box-shadow: 2px 0 14px rgba(2, 6, 23, 0.5);
}
html[data-theme='dark'] .student-sidebar li {
    color: #dbeafe;
}
html[data-theme='dark'] .student-sidebar li:hover {
    background: #172236;
    color: #eff6ff;
}
html[data-theme='dark'] .student-sidebar li.active {
    background: #1e3a8a;
    border-color: #3b82f6;
    color: #eff6ff;
}
html[data-theme='dark'] .services-submenu {
    border-left-color: #334155;
}
    .topbar { --key-btn-bg:#fff; --key-btn-border:#e5e7eb; --key-btn-color:#0f172a; }
    html[data-theme='dark'] .topbar { --key-btn-bg:rgba(15,23,42,0.9); --key-btn-border:rgba(148,163,184,0.5); --key-btn-color:#f8fafc; }
    html[data-theme='dark'] #keyDropMenu { background:#0f172a; color:#e2e8f0; border-color:#334155; box-shadow:0 2px 12px rgba(2,6,23,0.65); }
    html[data-theme='dark'] #keyDropMenu label { color:#e2e8f0; }
    html[data-theme='dark'] #keyDropMenu input.form-control { background:#0b1220; color:#e2e8f0; border-color:#334155; }
    html[data-theme='dark'] #keyDropMenu input.form-control::placeholder { color:#94a3b8; }
@media (max-width: 1100px) {
    .transcript-brand,
    .transcript-meta-strip,
    .year-semesters,
    .transcript-footer-band,
    .verification-panel,
    .verification-meta { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .transcript-school-name { font-size: 1.2rem; }
}
@media print {
    @page { size: A4 portrait; margin: 6mm; }
    html,
    body { width: 100% !important; height: auto !important; overflow: visible !important; }
    #sidebarToggle,
    .sidebar-toggle,
    .fa-bars,
    #themeToggle,
    .theme-toggle,
    .dark-mode-toggle,
    .floating-theme-toggle,
    .fa-moon,
    .fa-sun,
    [data-theme-toggle],
    .topbar-left,
    .topbar,
    .student-sidebar,
    .notification-bell,
    .profile-dropdown { display:none !important; }
    body,
    .main-content { background:#fff !important; }
    .main-content { margin-left:0 !important; width:100% !important; max-width:100% !important; }
    .content-area,
    .content-area.container {
        padding:0 !important;
        width:100% !important;
        max-width:100% !important;
        margin:0 !important;
    }
    .alert,
    .btn { display:none !important; }
    .transcript-paper {
        box-shadow:none;
        border:none;
        border-radius:0;
        width:100% !important;
        max-width:100% !important;
        transform:none !important;
        margin:0 !important;
        padding: 0 !important;
        min-height: 279mm;
        display: flex !important;
        flex-direction: column !important;
    }
    .transcript-masthead,
    .transcript-meta-strip,
    .transcript-footer-band,
    .verification-panel { flex: 0 0 auto; }
    .year-sheet,
    .verification-panel,
    .transcript-footer-band { page-break-inside: avoid; }
    .transcript-masthead { padding: 0.26rem 0.22rem 0.16rem; border-bottom: none; }
    .transcript-brand { grid-template-columns: 56px 1fr 124px; gap: 0.24rem; align-items: start; }
    .transcript-logo-wrap { align-items: flex-start; justify-content: flex-start; }
    .transcript-logo { width: 48px; height: 48px; }
    .transcript-school-name { font-size: 1.08rem; letter-spacing: 0.045em; }
    .transcript-office-title { font-size: 0.58rem; margin-top: 0.07rem; }
    .transcript-document-title { font-size: 0.72rem; margin-top: 0.09rem; }
    .transcript-document-subtitle { font-size: 0.52rem; margin-top: 0.03rem; }
    .transcript-brand-side { font-size: 0.47rem; line-height: 1.3; text-align: right; }
    .transcript-meta-strip {
        gap: 0.12rem 0.22rem;
        padding: 0.14rem 0.22rem 0.18rem;
        border-bottom: none;
        background: transparent;
        grid-template-columns: 54px repeat(4, minmax(0, 1fr));
    }
    .meta-photo-frame {
        gap: 0.12rem;
        padding: 0;
        border: none;
        border-radius: 0;
        background: transparent;
    }
    .student-transcript-photo-wrap {
        min-height: 56px;
        border: none;
        border-radius: 0;
        background: transparent;
    }
    .meta-photo-caption { display: none; }
    .meta-item { padding: 0.04rem 0; min-height: 20px; border: none; background: transparent; border-radius: 0; }
    .student-transcript-photo,
    .student-transcript-photo-placeholder { width: 46px; height: 56px; min-height: 56px; }
    .meta-label,
    .transcript-footer-label { font-size: 0.4rem; margin-bottom: 0.04rem; letter-spacing: 0.05em; }
    .meta-value,
    .transcript-footer-value { font-size: 0.58rem; font-weight: 700; line-height: 1.24; }
    .year-sheet {
        padding: 0.18rem 0.2rem 0.22rem;
        border-bottom: none;
        flex: 1 0 auto;
    }
    .year-sheet + .year-sheet { border-top: 0.45px solid #b9c1c9; }
    .year-heading { margin-bottom: 0.08rem; padding-bottom: 0; border-bottom: none; }
    .year-heading-title,
    .year-heading-stage { font-size: 0.56rem; letter-spacing: 0.03em; }
    .year-semesters { grid-template-columns: 1fr; gap: 0.12rem; }
    .semester-panel { border: none; }
    .semester-panel-title {
        padding: 0.02rem 0;
        margin-bottom: 0.04rem;
        font-size: 0.5rem;
        border-bottom: none;
        background: transparent;
        text-align: left;
        letter-spacing: 0.035em;
    }
    .transcript-table { table-layout: fixed; width: 100%; }
    .transcript-table td { font-size: 0.53rem; padding: 0.022rem 0.01rem; line-height: 1.18; border: none; }
    .transcript-table th {
        font-size: 0.43rem;
        padding: 0.016rem 0.01rem 0.018rem;
        line-height: 1.1;
        border: none;
        background: transparent;
    }
    .transcript-table .col-code { width: 10%; }
    .transcript-table .col-title { width: 69%; }
    .transcript-table .col-mark,
    .transcript-table .col-grade,
    .transcript-table .col-credit { width: 7%; }
    .transcript-table td:nth-child(3),
    .transcript-table td:nth-child(5),
    .transcript-table th:nth-child(3),
    .transcript-table th:nth-child(5) {
        text-align: center;
        padding-left: 0.002rem;
        padding-right: 0.002rem;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
        font-feature-settings: "tnum" 1;
    }
    .transcript-table td:nth-child(4),
    .transcript-table th:nth-child(4) {
        text-align: left;
        padding-left: 0.006rem;
        padding-right: 0.002rem;
        white-space: nowrap;
        letter-spacing: 0.01em;
    }
    .transcript-table td:nth-child(1),
    .transcript-table td:nth-child(3),
    .transcript-table td:nth-child(4),
    .transcript-table td:nth-child(5) {
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .empty-row { display:none; }
    .average-row td { padding-top: 0.04rem; padding-bottom: 0.035rem; background: transparent; }
    .transcript-footer-band {
        gap: 0.16rem;
        padding: 0.12rem 0.2rem 0.06rem;
        border-top: 0.45px solid #b9c1c9;
        background: transparent;
        grid-template-columns: 1.1fr 0.9fr 0.8fr;
        margin-top: auto;
    }
    .grading-key,
    .award-box { font-size: 0.41rem; line-height: 1.22; }
    .grading-key-title { font-size: 0.44rem; margin-bottom: 0.03rem; }
    .award-line { margin-bottom: 0.03rem; gap: 0.12rem; }
    .award-line strong { min-width: 52px; font-size: 0.41rem; }
    .signature-box {
        gap: 0.18rem;
        padding: 0.08rem 0.02rem 0 0.1rem;
        border: none;
        border-radius: 0;
        background: transparent;
    }
    .signature-space { min-height: 28px; border-bottom-width: 0.8px; }
    .signature-label { font-size: 0.38rem; }
    .signature-subtext { font-size: 0.33rem; line-height: 1.16; }
    .verification-panel {
        display: grid !important;
        gap: 0.08rem;
        padding: 0.06rem 0.2rem 0.03rem;
        grid-template-columns: 34px 1fr;
        border-top: 0.45px solid #b9c1c9;
        background: transparent;
    }
    .verification-qr-wrap {
        min-height: 30px;
        border: none;
        background: transparent;
        align-items: flex-start;
        justify-content: flex-start;
    }
    .verification-qr { width: 28px !important; height: 28px !important; }
    .verification-meta { gap: 0.1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .verification-card { padding: 0.05rem 0.09rem; border: none; background: transparent; }
    .verification-card .label { font-size: 0.33rem; margin-bottom: 0.015rem; }
    .verification-card .value,
    .verification-note,
    .verification-link { font-size: 0.32rem; line-height: 1.1; }
    .verification-note { margin-top: 0.03rem; }
    .verification-link { margin-top: 0.03rem; }
}
</style>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>My Transcript</h4>
        </div>
        <div class="topbar-right">
            <?php if ($transcriptPdfAvailable): ?>
                <a href="?export=pdf" class="btn btn-sm transcript-action transcript-action-primary"><i class="fas fa-file-pdf"></i> Download One-Page PDF</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm transcript-action transcript-action-primary transcript-action-disabled" disabled title="Admin approval and eligibility required">Download One-Page PDF</button>
            <?php endif; ?>
            <?php if ($transcriptDownloadAvailable): ?>
                <a href="?export=csv" class="btn btn-sm transcript-action transcript-action-secondary"><i class="fas fa-file-csv"></i> Export CSV</a>
                <a href="?export=excel" class="btn btn-sm transcript-action transcript-action-secondary"><i class="fas fa-file-excel"></i> Export Excel</a>
                <a href="?export=xml" class="btn btn-sm transcript-action transcript-action-secondary"><i class="fas fa-code"></i> Export XML</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm transcript-action transcript-action-secondary transcript-action-disabled" disabled title="<?php echo $transcriptDownloadAlreadyUsed ? 'One-time download already used' : 'Admin approval and eligibility required'; ?>">Export CSV</button>
                <button type="button" class="btn btn-sm transcript-action transcript-action-secondary transcript-action-disabled" disabled title="<?php echo $transcriptDownloadAlreadyUsed ? 'One-time download already used' : 'Admin approval and eligibility required'; ?>">Export Excel</button>
                <button type="button" class="btn btn-sm transcript-action transcript-action-secondary transcript-action-disabled" disabled title="<?php echo $transcriptDownloadAlreadyUsed ? 'One-time download already used' : 'Admin approval and eligibility required'; ?>">Export XML</button>
            <?php endif; ?>
            <?php if ($transcriptViewGranted): ?>
                <button type="button" class="btn btn-sm transcript-action transcript-action-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print View</button>
            <?php else: ?>
                <button type="button" class="btn btn-sm transcript-action transcript-action-secondary transcript-action-disabled" disabled title="Admin approval and eligibility required">Print View</button>
            <?php endif; ?>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="profile-dropdown" style="position:relative; margin-left:8px;">
                <button id="keyDropBtn" style="background:var(--key-btn-bg,#fff); border:1px solid var(--key-btn-border,#e5e7eb); border-radius:50%; width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:var(--key-btn-color,#0f172a);">
                    <i class="fas fa-key"></i>
                </button>
                <div id="keyDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.12); min-width:260px; padding:12px; z-index:100;">
                    <div style="font-weight:700; font-size:0.92rem; margin-bottom:8px; color:#0f172a;">
                        <i class="fas fa-key" style="margin-right:6px;"></i> Change Password
                    </div>
                    <form id="keyChangePasswordForm" method="POST" action="change-password.php">
                        <?php echo csrfField(); ?>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div id="keyChangePasswordMsg" style="display:none; font-size:0.82rem; margin-bottom:8px;"></div>
                        <button type="submit" class="btn btn-sm btn-primary btn-block">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area container p-4">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>
        <?php if ($transcriptDownloadAvailable): ?>
            <div class="alert alert-info">Note: CSV, Excel, and XML transcript export are available as a one-time download. PDF remains available while transcript access is granted.</div>
        <?php elseif ($transcriptDownloadAlreadyUsed && $transcriptViewGranted): ?>
            <div class="alert alert-info">
                One-time CSV/Excel/XML download already used<?php echo $transcriptDownloadUsedAt !== '' ? ' on ' . e(date('Y-m-d H:i', strtotime($transcriptDownloadUsedAt))) : ''; ?>.
                PDF download remains available. Contact admin if you need structured export re-enabled.
            </div>
        <?php endif; ?>
        <?php if (!$transcriptViewGranted): ?>
            <div class="alert alert-warning">
                Transcript access is locked until admin grants rights and eligibility is fully cleared.
            </div>
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-2">Transcript Eligibility Checklist</h6>
                    <ul class="mb-0 pl-3">
                        <li>Completed studies: <?php echo !empty($transcriptEligibility['completed_studies']) ? 'YES' : 'NO'; ?></li>
                        <li>Completed full programme duration: <?php echo !empty($transcriptEligibility['completed_full_program']) ? 'YES' : 'NO'; ?><?php echo $programDurationYears > 0 ? ' (' . (int)$programDurationYears . ' year programme)' : ''; ?></li>
                        <li>No outstanding retakes: <?php echo !empty($transcriptEligibility['has_no_retakes']) ? 'YES' : 'NO'; ?><?php echo !empty($transcriptEligibility['retake_count']) ? ' (' . (int)$transcriptEligibility['retake_count'] . ')' : ''; ?></li>
                        <li>Bills cleared: <?php echo !empty($transcriptEligibility['bills_cleared']) ? 'YES' : 'NO'; ?></li>
                        <li>Discipline in good standing: <?php echo !empty($transcriptEligibility['discipline_ok']) ? 'YES' : 'NO'; ?><?php echo !empty($transcriptEligibility['discipline_status']) ? ' (' . e((string)$transcriptEligibility['discipline_status']) . ')' : ''; ?></li>
                        <li>Admin transcript rights granted: <?php echo $transcriptDownloadRightsGranted ? 'YES' : 'NO'; ?></li>
                        <li>One-time CSV/Excel/XML download available: <?php echo $transcriptDownloadAlreadyUsed ? 'NO' : 'YES'; ?></li>
                        <li>PDF download available: <?php echo $transcriptPdfAvailable ? 'YES' : 'NO'; ?></li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($transcriptViewGranted && empty($terms)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-file-alt fa-3x mb-3"></i>
                    <h5>No Transcript Records Yet</h5>
                    <p>Your transcript will appear after course registrations and published results are available.</p>
                </div>
            </div>
        <?php elseif ($transcriptViewGranted): ?>
            <div class="transcript-paper">
                <div class="transcript-masthead">
                    <div class="transcript-brand">
                        <div class="transcript-logo-wrap">
                            <img src="<?php echo e($institutionLogoPath); ?>" alt="Institution Logo" class="transcript-logo">
                        </div>
                        <div class="transcript-brand-center">
                            <h5 class="transcript-school-name"><?php echo e($institutionName); ?></h5>
                            <div class="transcript-office-title">Office Of The Dean Of Studies</div>
                            <div class="transcript-document-title">Official Academic Transcript</div>
                            <div class="transcript-document-subtitle">Academic Transcript</div>
                        </div>
                        <div class="transcript-brand-side">
                            <?php if ($institutionAddress !== ''): ?><div><?php echo nl2br(e($institutionAddress)); ?></div><?php endif; ?>
                            <?php if ($institutionPhone !== ''): ?><div>Tel: <?php echo e($institutionPhone); ?></div><?php endif; ?>
                            <?php if ($institutionEmail !== ''): ?><div>Email: <?php echo e($institutionEmail); ?></div><?php endif; ?>
                            <div>Date: <?php echo e(date('D j M Y')); ?></div>
                        </div>
                    </div>
                </div>

                <div class="transcript-meta-strip">
                    <div class="meta-item meta-photo-item">
                        <div class="meta-photo-frame">
                            <div class="student-transcript-photo-wrap">
                                <?php if (!empty($student['photo'])): ?>
                                    <img src="<?php echo e(BASE_URL . '/' . ltrim((string)$student['photo'], '/')); ?>" alt="Student Photo" class="student-transcript-photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="student-transcript-photo-placeholder" style="display:none;">Student Photo</div>
                                <?php else: ?>
                                    <div class="student-transcript-photo-placeholder" style="display:flex;">Student Photo</div>
                                <?php endif; ?>
                            </div>
                            <div class="meta-photo-caption">Student Photo</div>
                        </div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Name</span>
                        <div class="meta-value"><?php echo e(trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''))); ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Reg No</span>
                        <div class="meta-value"><?php echo e((string)($student['student_id'] ?? ($student['admission_number'] ?? 'N/A'))); ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Sex</span>
                        <div class="meta-value"><?php echo e((string)($student['gender'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Nationality</span>
                        <div class="meta-value"><?php echo e((string)($student['country'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Date Of Birth</span>
                        <div class="meta-value"><?php echo !empty($student['date_of_birth']) ? e(Helper::formatDate((string)$student['date_of_birth'], 'd-M-Y')) : 'N/A'; ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Intake</span>
                        <div class="meta-value"><?php echo e((string)($student['entry_year'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Entry Mode</span>
                        <div class="meta-value"><?php echo e(!empty($student['entry_semester_id']) ? 'Direct' : 'N/A'); ?></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Programme</span>
                        <div class="meta-value"><?php echo e(trim((string)($student['program_code'] ?? '') . ' ' . (string)($student['program_name'] ?? 'N/A'))); ?></div>
                    </div>
                </div>

                <?php foreach ($academicYearGroups as $yearGroup): ?>
                    <div class="year-sheet">
                        <div class="year-heading">
                            <div class="year-heading-title">Academic Year: <?php echo e((string)($yearGroup['academic_year'] ?? '-')); ?></div>
                            <div class="year-heading-stage"><?php echo e(strtoupper((string)($student['program_code'] ?? 'PROGRAM') . ' ' . $toRoman((int)($yearGroup['year_of_study'] ?? 1)))); ?></div>
                        </div>

                        <div class="year-semesters">
                            <?php foreach ($getVisibleSemesterSlots($yearGroup) as $semesterSlot): ?>
                                <?php $semesterBlock = $yearGroup['semesters'][$semesterSlot] ?? null; ?>
                                <div class="semester-panel">
                                    <div class="semester-panel-title">Semester <?php echo $semesterSlot === 1 ? 'I' : 'II'; ?></div>
                                    <table class="transcript-table">
                                        <thead>
                                            <tr>
                                                <th class="col-code">Course Code</th>
                                                <th class="col-title">Course Title</th>
                                                <th class="col-mark">Marks</th>
                                                <th class="col-grade">Grade</th>
                                                <th class="col-credit">Credit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($semesterBlock && !empty($semesterBlock['rows'])): ?>
                                                <?php foreach ($semesterBlock['rows'] as $courseRow): ?>
                                                    <tr>
                                                        <td><?php echo e((string)($courseRow['course_code'] ?? '')); ?></td>
                                                        <td><?php echo e((string)($courseRow['course_name'] ?? '')); ?></td>
                                                        <td class="text-center"><?php echo $courseRow['marks'] !== null ? e((string)$courseRow['marks']) : '-'; ?></td>
                                                        <td class="text-center"><?php echo e((string)($courseRow['grade'] ?? '-')); ?></td>
                                                        <td class="text-center"><?php echo e(number_format((float)($courseRow['credit_hours'] ?? 0), 0)); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="average-row">
                                                    <td colspan="2">Average</td>
                                                    <td class="text-center"><?php echo $semesterBlock['average_mark'] !== null ? e((string)$semesterBlock['average_mark']) : '-'; ?></td>
                                                    <td class="text-center"><?php echo e((string)($semesterBlock['average_grade'] ?? '-')); ?></td>
                                                    <td class="text-center">-</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php for ($rowPad = 0; $rowPad < 10; $rowPad++): ?>
                                                    <tr class="empty-row">
                                                        <td>&nbsp;</td>
                                                        <td></td>
                                                        <td></td>
                                                        <td></td>
                                                        <td></td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="transcript-footer-band">
                    <div class="grading-key">
                        <div class="grading-key-title">Grading Key</div>
                        <div>80% - 100%: A First Class</div>
                        <div>75% - 79%: B+ Second Class Upper Division</div>
                        <div>70% - 74%: B Second Class Upper Division</div>
                        <div>65% - 69%: C+ Second Class Lower Division</div>
                        <div>60% - 64%: C Pass</div>
                        <div>50% - 59%: D Pass</div>
                        <div>40% - 49%: E Fail</div>
                        <div>Below 40%: F Fail</div>
                    </div>

                    <div class="award-box">
                        <div class="award-line">
                            <strong>Award:</strong>
                            <span class="transcript-footer-value"><?php echo e((string)($graduationAward['award_title'] ?? ($student['program_name'] ?? 'Pending'))); ?></span>
                        </div>
                        <div class="award-line">
                            <strong>Grade:</strong>
                            <span class="transcript-footer-value"><?php echo e((string)($graduationAward['classification'] ?? $cgpaClassification)); ?></span>
                        </div>
                        <div class="award-line">
                            <strong>CGPA:</strong>
                            <span class="transcript-footer-value"><?php echo $finalCgpa !== null ? e(number_format((float)$finalCgpa, 2)) : 'N/A'; ?></span>
                        </div>
                        <div class="award-line">
                            <strong>Credits:</strong>
                            <span class="transcript-footer-value"><?php echo e(number_format((float)$totalCreditsEarned, 0)); ?></span>
                        </div>
                        <div class="award-line">
                            <strong>Award Date:</strong>
                            <span class="transcript-footer-value"><?php echo !empty($graduationAward['award_date']) ? e(Helper::formatDate((string)$graduationAward['award_date'], 'M d, Y')) : 'Pending'; ?></span>
                        </div>
                    </div>

                    <div class="signature-box">
                        <div class="signature-space"></div>
                        <div class="signature-label">For Dean Of Studies</div>
                        <div class="signature-subtext">
                            Authorized signature and institutional stamp
                            <?php if (!empty($currentIssuedTranscript['issued_at'])): ?>
                                on <?php echo e(Helper::formatDate((string)$currentIssuedTranscript['issued_at'], 'M d, Y')); ?>
                            <?php endif; ?>.
                        </div>
                    </div>
                </div>

                <?php if ($transcriptViewGranted && !empty($transcriptEligibility['completed_full_program']) && !empty($currentIssuedTranscript)): ?>
                    <div class="verification-panel">
                        <div class="verification-qr-wrap">
                            <div id="transcriptQrCode" class="verification-qr" data-qr-url="<?php echo e((string)($currentIssuedTranscript['verification_url'] ?? '')); ?>"></div>
                        </div>
                        <div>
                            <div class="verification-meta">
                                <div class="verification-card">
                                    <div class="label">Verification Code</div>
                                    <div class="value"><?php echo e(implode('-', str_split((string)($currentIssuedTranscript['verification_code'] ?? ''), 4))); ?></div>
                                </div>
                                <div class="verification-card">
                                    <div class="label">Issued At</div>
                                    <div class="value"><?php echo e(Helper::formatDateTime((string)($currentIssuedTranscript['issued_at'] ?? ''), 'M d, Y g:i A')); ?></div>
                                </div>
                                <div class="verification-card">
                                    <div class="label">Transcript Hash</div>
                                    <div class="value"><?php echo e((string)($currentIssuedTranscript['transcript_hash'] ?? '')); ?></div>
                                </div>
                                <div class="verification-card">
                                    <div class="label">Verification Token</div>
                                    <div class="value"><?php echo e((string)($currentIssuedTranscript['verification_token'] ?? '')); ?></div>
                                </div>
                            </div>
                            <div class="verification-note">This transcript has a permanent verification record for print and digital confirmation.</div>
                            <div class="verification-link"><?php echo e((string)($currentIssuedTranscript['verification_url'] ?? '')); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.querySelector('.student-sidebar');
    var main = document.querySelector('.main-content');
    if (!toggle || !sidebar || !main) return;
    toggle.addEventListener('click', function() {
        sidebar.classList.toggle('sidebar-collapsed');
        main.classList.toggle('full-width');
    });

    var keyBtn = document.getElementById('keyDropBtn');
    if (keyBtn) {
        keyBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var menu = document.getElementById('keyDropMenu');
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        });
    }
    var keyForm = document.getElementById('keyChangePasswordForm');
    if (keyForm) {
        keyForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var msg = document.getElementById('keyChangePasswordMsg');
            var submitBtn = keyForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            if (msg) {
                msg.style.display = 'none';
                msg.textContent = '';
            }
            fetch('change-password.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(keyForm)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (msg) {
                    msg.style.display = 'block';
                    if (data && data.success) {
                        msg.style.color = '#166534';
                        msg.textContent = data.message || 'Password updated.';
                        keyForm.reset();
                    } else {
                        msg.style.color = '#b91c1c';
                        msg.textContent = (data && (data.error || data.message)) ? (data.error || data.message) : 'Unable to update password.';
                    }
                }
            })
            .catch(function() {
                if (msg) {
                    msg.style.display = 'block';
                    msg.style.color = '#b91c1c';
                    msg.textContent = 'Unable to update password.';
                }
            })
            .finally(function() {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }
    document.addEventListener('click', function() {
        var keyMenu = document.getElementById('keyDropMenu');
        if (keyMenu) keyMenu.style.display = 'none';
    });

    var qrHost = document.getElementById('transcriptQrCode');
    if (qrHost && typeof QRCode !== 'undefined') {
        var qrUrl = qrHost.getAttribute('data-qr-url') || '';
        if (qrUrl !== '') {
            new QRCode(qrHost, {
                text: qrUrl,
                width: 160,
                height: 160,
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
</body>
</html>

