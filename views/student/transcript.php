<?php
/**
 * Student Transcript (standard format with SGPA/CGPA)
 */
require_once '../../config.php';

$session = new Session('student');
$auth = new Auth('student');

if (
    !isset($_SESSION['student_logged_in']) ||
    $_SESSION['student_logged_in'] !== true ||
    ($_SESSION['student_role'] ?? '') !== 'student'
) {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
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
$transcriptViewGranted = $transcriptDownloadRightsGranted
    && (($transcriptEligibility['eligible'] ?? false) === true);
$transcriptDownloadAvailable = $transcriptViewGranted && !$transcriptDownloadAlreadyUsed;

// Student + program profile.
$studentStmt = $conn->prepare("
    SELECT
        s.*,
        p.program_code,
        p.program_name
    FROM students s
    LEFT JOIN programs p ON p.id = s.program_id
    WHERE s.id = :student_id
    LIMIT 1
");
$studentStmt->execute(['student_id' => $studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

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

$buildTranscriptSnapshot = function () use ($student, $terms, $finalCgpa, $cgpaClassification, $totalCreditsAttempted, $totalCreditsEarned, $graduationAward) {
    $canonicalTerms = [];
    foreach ($terms as $term) {
        $termRows = [];
        foreach ((array)($term['rows'] ?? []) as $courseRow) {
            $termRows[] = [
                'course_code' => (string)($courseRow['course_code'] ?? ''),
                'course_name' => (string)($courseRow['course_name'] ?? ''),
                'credit_hours' => number_format((float)($courseRow['credit_hours'] ?? 0), 2, '.', ''),
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
        if (!$currentIssuedTranscript) {
            $currentIssuedTranscript = $transcriptIssuanceService->issueTranscript(
                $studentId,
                $currentSnapshot,
                'print',
                isset($currentUser['id']) ? (int)$currentUser['id'] : null,
                'student_view'
            );
        }
    } catch (Exception $e) {
        $currentIssuedTranscript = null;
    }
}

// Export transcript dataset.
$export = strtolower(trim((string)($_GET['export'] ?? '')));
if ($export !== '' && !$transcriptDownloadAvailable) {
    if ($transcriptDownloadAlreadyUsed) {
        $when = $transcriptDownloadUsedAt !== '' ? date('Y-m-d H:i', strtotime($transcriptDownloadUsedAt)) : 'an earlier time';
        $format = $transcriptDownloadUsedFormat !== '' ? $transcriptDownloadUsedFormat : 'EXPORT';
        $session->setFlash('error', 'One-time transcript download was already used (' . $format . ') on ' . $when . '. Contact admin to re-enable.');
        header('Location: ' . BASE_URL . '/views/student/transcript.php');
        exit;
    }
    $blocking = !empty($transcriptEligibility['blocking_reasons']) ? implode(' ', $transcriptEligibility['blocking_reasons']) : '';
    $session->setFlash('error', 'Transcript export is unavailable. ' . trim('Admin approval and eligibility are required. ' . $blocking));
    header('Location: ' . BASE_URL . '/views/student/transcript.php');
    exit;
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
        <div class="sidebar-user-no">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
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
    margin: 0.45rem 0.45rem 0.2rem;
    background: #2b3c4f;
    border-radius: 8px;
    color: #fff;
    text-align: center;
    padding: 0.6rem 0.55rem 0.6rem;
}
.sidebar-user-card img {
    width: 62px;
    height: 72px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.35);
    margin-bottom: 0.3rem;
}
.sidebar-user-name { font-size: 0.82rem; line-height: 1.2; }
.sidebar-user-no { font-size: 0.9rem; font-weight: 700; }
.sidebar-portal-title { font-size: 0.66rem; letter-spacing: 0.08em; text-transform: uppercase; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 700; }
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
.transcript-card { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
.transcript-header { background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%); color: #fff; padding: 1rem 1.25rem; }
.transcript-title { margin: 0; font-weight: 700; letter-spacing: 0.4px; }
.transcript-subtitle { margin: 0.2rem 0 0; opacity: 0.9; font-size: 0.9rem; }
.profile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.75rem; padding: 1rem 1.25rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.profile-chip { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 0.75rem; }
.profile-chip .label { color: #64748b; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }
.profile-chip .value { color: #0f172a; font-size: 0.92rem; font-weight: 600; }
.term-section { padding: 1rem 1.25rem; border-bottom: 1px solid #eef2f7; }
.term-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 0.65rem; }
.term-title { margin: 0; font-size: 0.98rem; font-weight: 700; color: #1f2937; }
.term-meta { font-size: 0.8rem; color: #64748b; }
.transcript-table { width: 100%; border-collapse: collapse; }
.transcript-table th, .transcript-table td { border-bottom: 1px solid #edf2f7; padding: 0.46rem 0.48rem; font-size: 0.82rem; }
.transcript-table th { background: #f8fafc; color: #334155; font-weight: 700; }
.summary-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 0.7rem; }
.summary-badge { background: #f1f5f9; border: 1px solid #cbd5e1; color: #0f172a; border-radius: 999px; padding: 4px 10px; font-size: 0.76rem; font-weight: 600; }
.final-summary { padding: 1rem 1.25rem; background: #f8fafc; }
.final-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.7rem; }
.final-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 0.75rem; }
.final-item .label { color: #64748b; font-size: 0.76rem; text-transform: uppercase; }
.final-item .value { color: #0f172a; font-size: 1rem; font-weight: 700; }
.verification-panel { display:grid; grid-template-columns:minmax(180px,220px) 1fr; gap:1rem; padding:1rem 1.25rem; background:#fff; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; }
.verification-qr-wrap { display:flex; align-items:center; justify-content:center; min-height:180px; border:1px dashed #cbd5e1; border-radius:12px; background:#f8fafc; }
.verification-qr { width:160px; height:160px; }
.verification-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:.75rem; }
.verification-card { border:1px solid #e2e8f0; border-radius:10px; padding:.75rem .85rem; background:#f8fafc; }
.verification-card .label { color:#64748b; font-size:.73rem; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.verification-card .value { color:#0f172a; font-size:.88rem; font-weight:600; word-break:break-word; }
.verification-link { font-size:.8rem; color:#1d4ed8; word-break:break-all; }
.verification-note { margin-top:.5rem; color:#475569; font-size:.82rem; }
html[data-theme='dark'] .transcript-card { border-color: #243047; box-shadow: 0 10px 26px rgba(2, 6, 23, 0.5); }
html[data-theme='dark'] .profile-grid,
html[data-theme='dark'] .final-summary { background: #0f172a; border-color: #243047; }
html[data-theme='dark'] .profile-chip,
html[data-theme='dark'] .final-item { background: #111a2b; border-color: #243047; }
html[data-theme='dark'] .profile-chip .label,
html[data-theme='dark'] .term-meta,
html[data-theme='dark'] .final-item .label { color: #93c5fd; }
html[data-theme='dark'] .profile-chip .value,
html[data-theme='dark'] .term-title,
html[data-theme='dark'] .final-item .value { color: #e2e8f0; }
html[data-theme='dark'] .transcript-table th { background: #152238; color: #cbd5e1; border-color: #233147; }
html[data-theme='dark'] .transcript-table td { border-color: #233147; color: #dbeafe; }
html[data-theme='dark'] .summary-badge { background: #111827; border-color: #334155; color: #dbeafe; }
html[data-theme='dark'] .verification-panel { background:#0f172a; border-color:#243047; }
html[data-theme='dark'] .verification-qr-wrap,
html[data-theme='dark'] .verification-card { background:#111a2b; border-color:#243047; }
html[data-theme='dark'] .verification-card .label,
html[data-theme='dark'] .verification-note { color:#93c5fd; }
html[data-theme='dark'] .verification-card .value { color:#e2e8f0; }
html[data-theme='dark'] .verification-link { color:#93c5fd; }
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
@media (max-width: 900px) {
    .verification-panel { grid-template-columns: 1fr; }
}
@media print {
    .topbar,
    .student-sidebar,
    .notification-bell,
    .sidebar-toggle { display:none !important; }
    .main-content { margin-left:0 !important; width:100% !important; max-width:100% !important; }
    .content-area { padding:0 !important; }
    .transcript-card { box-shadow:none; border:1px solid #cbd5e1; }
    .verification-panel { page-break-inside:avoid; }
}
</style>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>My Transcript</h4>
        </div>
        <div class="topbar-right">
            <?php if ($transcriptDownloadAvailable): ?>
                <a href="?export=csv" class="btn btn-outline-secondary btn-sm mr-2"><i class="fas fa-file-csv"></i> Export CSV</a>
                <a href="?export=excel" class="btn btn-outline-secondary btn-sm mr-2"><i class="fas fa-file-excel"></i> Export Excel</a>
                <a href="?export=xml" class="btn btn-outline-secondary btn-sm mr-2"><i class="fas fa-code"></i> Export XML</a>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2" disabled title="<?php echo $transcriptDownloadAlreadyUsed ? 'One-time download already used' : 'Admin approval and eligibility required'; ?>">Export CSV</button>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2" disabled title="<?php echo $transcriptDownloadAlreadyUsed ? 'One-time download already used' : 'Admin approval and eligibility required'; ?>">Export Excel</button>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2" disabled title="<?php echo $transcriptDownloadAlreadyUsed ? 'One-time download already used' : 'Admin approval and eligibility required'; ?>">Export XML</button>
            <?php endif; ?>
            <?php if ($transcriptViewGranted): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2" onclick="window.print()"><i class="fas fa-print"></i> Official PDF (Print)</button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2" disabled title="Admin approval and eligibility required">Official PDF (Print)</button>
            <?php endif; ?>
            <?php include '../../includes/notification_bell.php'; ?>
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
            <div class="alert alert-info">Note: Official transcript export is available as a one-time download.</div>
        <?php elseif ($transcriptDownloadAlreadyUsed && $transcriptViewGranted): ?>
            <div class="alert alert-info">
                One-time transcript download already used<?php echo $transcriptDownloadUsedAt !== '' ? ' on ' . e(date('Y-m-d H:i', strtotime($transcriptDownloadUsedAt))) : ''; ?>.
                Contact admin if re-enable is required.
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
                        <li>No outstanding retakes: <?php echo !empty($transcriptEligibility['has_no_retakes']) ? 'YES' : 'NO'; ?><?php echo !empty($transcriptEligibility['retake_count']) ? ' (' . (int)$transcriptEligibility['retake_count'] . ')' : ''; ?></li>
                        <li>Bills cleared: <?php echo !empty($transcriptEligibility['bills_cleared']) ? 'YES' : 'NO'; ?></li>
                        <li>Discipline in good standing: <?php echo !empty($transcriptEligibility['discipline_ok']) ? 'YES' : 'NO'; ?><?php echo !empty($transcriptEligibility['discipline_status']) ? ' (' . e((string)$transcriptEligibility['discipline_status']) . ')' : ''; ?></li>
                        <li>Admin transcript rights granted: <?php echo $transcriptDownloadRightsGranted ? 'YES' : 'NO'; ?></li>
                        <li>One-time download available: <?php echo $transcriptDownloadAlreadyUsed ? 'NO' : 'YES'; ?></li>
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
            <div class="transcript-card">
                <div class="transcript-header">
                    <h5 class="transcript-title">Official Academic Transcript</h5>
                    <p class="transcript-subtitle">Generated on <?php echo e(date('Y-m-d H:i')); ?></p>
                </div>

                <div class="profile-grid">
                    <div class="profile-chip">
                        <div class="label">Student Name</div>
                        <div class="value"><?php echo e(trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''))); ?></div>
                    </div>
                    <div class="profile-chip">
                        <div class="label">Student ID</div>
                        <div class="value"><?php echo e((string)($student['student_id'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="profile-chip">
                        <div class="label">Program</div>
                        <div class="value"><?php echo e(trim((string)($student['program_code'] ?? '') . ' - ' . (string)($student['program_name'] ?? ''))); ?></div>
                    </div>
                    <div class="profile-chip">
                        <div class="label">Academic Status</div>
                        <div class="value"><?php echo e(ucfirst((string)($student['status'] ?? 'active'))); ?></div>
                    </div>
                </div>

                <?php if (!empty($currentIssuedTranscript)): ?>
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
                            <div class="verification-note">This transcript has a permanent verification record for student-led sharing and printed copies.</div>
                            <div class="verification-link mt-2"><?php echo e((string)($currentIssuedTranscript['verification_url'] ?? '')); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($terms as $term): ?>
                    <div class="term-section">
                        <div class="term-header">
                            <h6 class="term-title">
                                <?php echo e((string)($term['academic_year'] ?? '-')); ?> - <?php echo e((string)($term['semester_name'] ?? 'Semester')); ?>
                            </h6>
                            <span class="term-meta">Year <?php echo (int)($term['year_of_study'] ?? 1); ?> | Semester <?php echo (int)($term['semester_number'] ?? 1); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="transcript-table">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Title</th>
                                        <th class="text-center">CU</th>
                                        <th class="text-center">Grade</th>
                                        <th class="text-center">GP</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($term['rows'] as $courseRow): ?>
                                        <tr>
                                            <td><?php echo e((string)($courseRow['course_code'] ?? '')); ?></td>
                                            <td><?php echo e((string)($courseRow['course_name'] ?? '')); ?></td>
                                            <td class="text-center"><?php echo e((string)($courseRow['credit_hours'] ?? '0')); ?></td>
                                            <td class="text-center"><?php echo e((string)($courseRow['grade'] ?? '-')); ?></td>
                                            <td class="text-center"><?php echo ($courseRow['grade_points'] !== null && $courseRow['grade_points'] !== '') ? e(number_format((float)$courseRow['grade_points'], 2)) : '-'; ?></td>
                                            <td class="text-center"><?php echo e(ucfirst((string)($courseRow['result_status'] ?? 'pending'))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="summary-badges">
                            <span class="summary-badge">Attempted Credits: <?php echo e(number_format((float)$term['attempted_credits'], 0)); ?></span>
                            <span class="summary-badge">Earned Credits: <?php echo e(number_format((float)$term['earned_credits'], 0)); ?></span>
                            <span class="summary-badge">SGPA: <?php echo $term['sgpa'] !== null ? e(number_format((float)$term['sgpa'], 2)) : 'N/A'; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="final-summary">
                    <div class="final-summary-grid">
                        <div class="final-item">
                            <div class="label">Final CGPA</div>
                            <div class="value"><?php echo $finalCgpa !== null ? e(number_format((float)$finalCgpa, 2)) : 'N/A'; ?></div>
                        </div>
                        <div class="final-item">
                            <div class="label">Classification</div>
                            <div class="value"><?php echo e($cgpaClassification); ?></div>
                        </div>
                        <div class="final-item">
                            <div class="label">Attempted Credits</div>
                            <div class="value"><?php echo e(number_format((float)$totalCreditsAttempted, 0)); ?></div>
                        </div>
                        <div class="final-item">
                            <div class="label">Earned Credits</div>
                            <div class="value"><?php echo e(number_format((float)$totalCreditsEarned, 0)); ?></div>
                        </div>
                        <div class="final-item">
                            <div class="label">Award</div>
                            <div class="value"><?php echo e((string)($graduationAward['award_title'] ?? 'Pending')); ?></div>
                        </div>
                        <div class="final-item">
                            <div class="label">Award Class</div>
                            <div class="value"><?php echo e((string)($graduationAward['classification'] ?? $cgpaClassification)); ?></div>
                        </div>
                    </div>
                </div>
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
