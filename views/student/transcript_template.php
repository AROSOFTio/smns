<?php
/**
 * Student Transcript Template
 *
 * Expects $studentId to be defined.
 * Expects $conn (PDO connection) to be available.
 */

if (!isset($studentId) || !isset($conn)) {
    echo '<div class="alert alert-danger">Required variables for transcript template are not set.</div>';
    return;
}

// The rest of the file is the logic and HTML from the original transcript.php
// ...

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
$window = getAcademicCalendarDisplayWindowBounds();
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
      AND ay.start_date >= :start_date
      AND ay.start_date <= :end_date
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
$transcriptStmt->execute([
    'student_id' => $studentId,
    'start_date' => $window['start_date'],
    'end_date' => $window['end_date'],
]);
$rawRows = $transcriptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rawRows as &$row) {
    $row['academic_year'] = resolveStudentAcademicYearDisplayLabel(
        $conn,
        $studentId,
        (int)($row['year_of_study'] ?? 1),
        (string)($row['academic_year'] ?? '-'),
        $student
    );
}
unset($row);

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
$institutionLogoPath = BASE_URL . '/assets/img/sem.PNG?v=' . urlencode((string)APP_VERSION);

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

?>
<div class="transcript-container">
    <div class="transcript-header">
        <img src="<?php echo e($institutionLogoPath); ?>" alt="Logo" class="transcript-logo">
        <div class="transcript-header-text">
            <h1><?php echo e($institutionName); ?></h1>
            <p>OFFICE OF THE ACADEMIC REGISTRAR</p>
            <h2>ACADEMIC TRANSCRIPT</h2>
        </div>
    </div>

    <table class="transcript-meta">
        <tr>
            <td><strong>NAME:</strong> <?php echo e(strtoupper(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')))); ?></td>
            <td><strong>REG NO:</strong> <?php echo e(strtoupper((string)($student['student_id'] ?? ''))); ?></td>
        </tr>
        <tr>
            <td><strong>PROGRAMME:</strong> <?php echo e(strtoupper((string)($student['program_name'] ?? ''))); ?></td>
            <td><strong>SEX:</strong> <?php echo e(strtoupper((string)($student['gender'] ?? ''))); ?></td>
        </tr>
    </table>

    <div class="transcript-body">
        <?php foreach ($academicYearGroups as $yearGroup): ?>
            <div class="academic-year-row">
                <?php
                $visibleSlots = $getVisibleSemesterSlots($yearGroup);
                $colClass = count($visibleSlots) === 1 ? 'col-md-12' : 'col-md-6';
                ?>
                <?php foreach ($visibleSlots as $semesterSlot): ?>
                    <?php $semester = $yearGroup['semesters'][$semesterSlot]; ?>
                    <div class="<?php echo $colClass; ?>">
                        <div class="semester-box">
                            <div class="semester-header">
                                <strong>YEAR <?php echo e((int)($yearGroup['year_of_study'] ?? 1)); ?>: <?php echo e(strtoupper((string)($yearGroup['academic_year'] ?? ''))); ?> - SEMESTER <?php echo e($toRoman($semester['semester_number'])); ?></strong>
                            </div>
                            <table class="semester-table">
                                <thead>
                                <tr>
                                    <th>CODE</th>
                                    <th>COURSE TITLE</th>
                                    <th>CR</th>
                                    <th>MK</th>
                                    <th>GR</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($semester['rows'] as $course): ?>
                                    <tr>
                                        <td><?php echo e($course['course_code']); ?></td>
                                        <td><?php echo e($course['course_name']); ?></td>
                                        <td><?php echo e(number_format($course['credit_hours'], 0)); ?></td>
                                        <td><?php echo e($course['marks'] ?? '-'); ?></td>
                                        <td><?php echo e($course['grade'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="transcript-summary">
        <div class="grading-scale">
            <h6>GRADING SCALE</h6>
            <p>A: 80-100 (5.0) | B+: 75-79 (4.5) | B: 70-74 (4.0) | C+: 65-69 (3.5) | C: 60-64 (3.0) | D: 50-59 (2.0) | E: 40-49 (1.0) | F: 0-39 (0.0)</p>
        </div>
        <div class="cgpa-summary">
            <h6>CUMULATIVE SUMMARY</h6>
            <p>
                <strong>CGPA:</strong> <?php echo $finalCgpa !== null ? number_format($finalCgpa, 2) : 'N/A'; ?> |
                <strong>CLASS:</strong> <?php echo e($cgpaClassification); ?> |
                <strong>CREDITS:</strong> <?php echo e(number_format($totalCreditsEarned, 0)); ?> / <?php echo e(number_format($totalCreditsAttempted, 0)); ?>

            </p>
            <?php if ($graduationAward): ?>
                <p>
                    <strong>AWARD:</strong> <?php echo e(strtoupper((string)($graduationAward['award_title'] ?? ''))); ?> (<?php echo e(strtoupper((string)($graduationAward['classification'] ?? ''))); ?>)
                    <br>
                    <strong>DATE OF AWARD:</strong> <?php echo e(strtoupper(Helper::formatDate((string)($graduationAward['award_date'] ?? '')))); ?>

                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="transcript-footer">
        <div class="signature">
            <p>_________________________</p>
            <p>ACADEMIC REGISTRAR</p>
        </div>
        <div class="date-issued">
            <p><strong>Date Issued:</strong> <?php echo date('F d, Y'); ?></p>
        </div>
    </div>
</div>
<style>
    .transcript-container {
        font-family: "Times New Roman", Times, serif;
        padding: 20px;
        border: 1px solid #ccc;
        background: #fff;
        max-width: 800px;
        margin: 20px auto;
    }
    .transcript-header { text-align: center; margin-bottom: 20px; }
    .transcript-header-text h1 { font-size: 20px; font-weight: bold; margin: 0; }
    .transcript-header-text p { font-size: 14px; margin: 0; }
    .transcript-header-text h2 { font-size: 16px; font-weight: bold; margin-top: 5px; }
    .transcript-logo { width: 80px; margin-bottom: 10px; }
    .transcript-meta { width: 100%; margin-bottom: 20px; font-size: 12px; }
    .transcript-body { border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 10px 0; }
    .academic-year-row { display: flex; margin-bottom: 10px; }
    .semester-box { border: 1px solid #999; padding: 5px; height: 100%; }
    .semester-header { font-size: 11px; text-align: center; margin-bottom: 5px; }
    .semester-table { width: 100%; font-size: 10px; }
    .semester-table th, .semester-table td { padding: 2px; text-align: left; }
    .semester-table th { border-bottom: 1px solid #000; }
    .transcript-summary { margin-top: 10px; font-size: 12px; }
    .grading-scale, .cgpa-summary { margin-bottom: 10px; }
    .transcript-footer { margin-top: 30px; display: flex; justify-content: space-between; align-items: flex-end; font-size: 12px; }
</style>

