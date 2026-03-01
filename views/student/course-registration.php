<?php
/**
 * Student - Course Registration
 * Modified to require admin approval before showing courses
 */
require_once '../../config.php';

$session = new Session('student');
$auth = new Auth('student');

// Verify student access using Auth helper (module-specific session keys)
if (!$auth->isLoggedIn() || $auth->getRole() !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser || empty($currentUser['profile'])) {
    // Fallback safety: if profile missing, force re-login
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$studentProfile = $currentUser['profile'];

$db = new Database();
$conn = $db->getConnection();

// Semesters for selection
$sstmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.year_name DESC, s.semester_number DESC");
$semesters = $sstmt->fetchAll();

// Academic years for dropdown
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();

// Default semester & academic year context.
// Mixed cohorts: default each student to their own enrollment target context.
// No-history fallback still resolves to Semester 1 of active academic year.
$currentSemester = getStudentEnrollmentTargetContext($conn, (int)($studentProfile['id'] ?? 0));
$hasExplicitSemesterContext = isset($_GET['semester_id']) || isset($_GET['academic_year_id']) || isset($_GET['semester_number']);

$defaultSemesterId = 0;
$defaultAcademicYearId = 0;
$defaultSemesterNumber = 1;

if (!$hasExplicitSemesterContext && !empty($currentSemester['id'])) {
    $defaultSemesterId = (int)($currentSemester['id'] ?? 0);
    $defaultAcademicYearId = (int)($currentSemester['academic_year_id'] ?? 0);
    $defaultSemesterNumber = (int)($currentSemester['semester_number'] ?? 1);
} else {
    $defaultSemesterId = (int)($currentSemester['id'] ?? 0);
    $defaultAcademicYearId = (int)($currentSemester['academic_year_id'] ?? 0);
    $defaultSemesterNumber = (int)($currentSemester['semester_number'] ?? 1);
}

if ($defaultAcademicYearId <= 0) {
    $defaultAcademicYearId = (int)(Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0));
}

// Determine selected academic year & semester number from GET (or defaults above)
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int)$_GET['semester_number'] : $defaultSemesterNumber;
$selectedEnrollingAs = isset($_GET['enrolling_as']) ? trim((string)$_GET['enrolling_as']) : 'normal';
$selectedHasRetakes = isset($_GET['has_retakes']) ? trim((string)$_GET['has_retakes']) : 'no';
$regTab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'enroll';
if (!in_array($regTab, ['enroll', 'enrollment_history', 'registration_history', 'migrated_history'], true)) {
    $regTab = 'enroll';
}

// Resolve selected academic year name for display
$selectedAcademicYearName = '';
foreach ($academicYears as $ay) {
    if ((int)$ay['id'] === (int)$selectedAcademicYearId) {
        $selectedAcademicYearName = $ay['year_name'];
        break;
    }
}

$registeredProgramName = '-';
if (!empty($studentProfile['id'])) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => (int)$studentProfile['id']]);
        $programName = $progStmt->fetchColumn();
        if (!empty($programName)) {
            $registeredProgramName = $programName;
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
        $registeredProgramName = !empty($studentProfile['program_name']) ? $studentProfile['program_name'] : '-';
    }
}

// Validate semester number - only 1 or 2 are valid
if ($selectedSemesterNumber < 1 || $selectedSemesterNumber > 2) {
    $selectedSemesterNumber = 1;
}

// Update semesterId logic to use semester_offered
$semesterOffered = $selectedSemesterNumber; // Use semester_number directly

// Map academic_year + semester_number to a semester id
$semesterId = 0;
if (isset($_GET['semester_id']) && (int)$_GET['semester_id'] > 0) {
    $semesterId = (int)$_GET['semester_id'];
} else {
    $mapStmt = $conn->prepare("SELECT id FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1");
    $mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
    $row = $mapStmt->fetch();
    $semesterId = $row['id'] ?? $defaultSemesterId;
}

if (isset($_POST['semester_id'])) {
    $semesterId = (int)$_POST['semester_id'];
}

// Keep query parameters consistent with the resolved semester id.
if ($semesterId > 0) {
    $resolvedSem = $conn->prepare("SELECT academic_year_id, semester_number FROM semesters WHERE id = :id LIMIT 1");
    $resolvedSem->execute(['id' => $semesterId]);
    $resolvedSemRow = $resolvedSem->fetch(PDO::FETCH_ASSOC);
    if ($resolvedSemRow) {
        $selectedAcademicYearId = (int)$resolvedSemRow['academic_year_id'];
        $selectedSemesterNumber = (int)$resolvedSemRow['semester_number'];
    }
}

/**
 * Registration window guard for student self-enrollment actions.
 * Admin flows are intentionally not restricted by this guard.
 */
$getSemesterRegistrationWindow = function (int $targetSemesterId) use ($conn) {
    $data = [
        'semester_id' => $targetSemesterId,
        'configured' => false,
        'open' => false,
        'is_semester_active' => false,
        'registration_start_date' => null,
        'registration_end_date' => null,
        'semester_label' => 'selected semester'
    ];

    if ($targetSemesterId <= 0) {
        return $data;
    }

    try {
        $stmt = $conn->prepare("
            SELECT s.registration_start_date, s.registration_end_date, s.semester_name, s.status, ay.year_name
            FROM semesters s
            LEFT JOIN academic_years ay ON ay.id = s.academic_year_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $targetSemesterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $data;
        }

        $start = !empty($row['registration_start_date']) ? (string)$row['registration_start_date'] : null;
        $end = !empty($row['registration_end_date']) ? (string)$row['registration_end_date'] : null;
        $data['registration_start_date'] = $start;
        $data['registration_end_date'] = $end;
        $data['is_semester_active'] = strtolower((string)($row['status'] ?? 'inactive')) === 'active';
        $data['configured'] = ($start !== null && $end !== null);
        $data['semester_label'] = trim((string)($row['year_name'] ?? '') . ' - ' . (string)($row['semester_name'] ?? ''));

        // Policy: if admin marked semester active, allow self-enrollment even when date window has elapsed.
        if ($data['is_semester_active']) {
            $data['configured'] = true;
            $data['open'] = true;
        } elseif ($data['configured']) {
            $today = date('Y-m-d');
            $data['open'] = ($today >= $start && $today <= $end);
        }
    } catch (Exception $e) {
        // keep defaults if lookup fails
    }

    return $data;
};

$currentSemesterWindow = $getSemesterRegistrationWindow((int)$semesterId);
$isEnrollmentWindowOpen = (bool)$currentSemesterWindow['open'];

// Compute Year of study
// Allow overriding via GET (user-selectable)
$yearOfStudy = isset($_GET['year_of_study']) ? (int)$_GET['year_of_study'] : (int)($studentProfile['level_year'] ?? 1);

// Only auto-calculate year of study if user hasn't explicitly selected one via the dropdown
if (!isset($_GET['year_of_study']) && !empty($studentProfile['entry_year']) && $selectedAcademicYearId) {
    $ayStmt = $conn->prepare("SELECT start_date FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => $selectedAcademicYearId]);
    $ayRow = $ayStmt->fetch();
    if ($ayRow && !empty($ayRow['start_date'])) {
        $startYear = (int)date('Y', strtotime($ayRow['start_date']));
        $entryYear = (int)$studentProfile['entry_year'];
        $calc = ($startYear - $entryYear) + 1;
        $yearOfStudy = max(1, min(10, $calc));
    } else {
        $yearOfStudy = (int)($studentProfile['level_year'] ?? $yearOfStudy);
    }
}

function getLatestStudentGpaSummary(PDO $conn, int $studentId): ?array
{
    if ($studentId <= 0) {
        return null;
    }
    try {
        $stmt = $conn->prepare("
            SELECT
                sg.semester_id,
                sg.semester_gpa,
                sg.cumulative_gpa,
                s.academic_year_id,
                s.semester_number,
                s.semester_name,
                ay.year_name
            FROM student_gpas sg
            INNER JOIN semesters s ON s.id = sg.semester_id
            INNER JOIN academic_years ay ON ay.id = s.academic_year_id
            WHERE sg.student_id = :student_id
            ORDER BY s.end_date DESC, s.start_date DESC, sg.id DESC
            LIMIT 1
        ");
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $sgpa = (isset($row['semester_gpa']) && $row['semester_gpa'] !== '' && is_numeric($row['semester_gpa']))
            ? (float)$row['semester_gpa']
            : null;
        $cgpa = (isset($row['cumulative_gpa']) && $row['cumulative_gpa'] !== '' && is_numeric($row['cumulative_gpa']))
            ? (float)$row['cumulative_gpa']
            : null;

        $promotionMetric = $cgpa !== null ? $cgpa : $sgpa;
        $promotionMetricLabel = $cgpa !== null ? 'CGPA' : 'SGPA';

        $row['semester_gpa_value'] = $sgpa;
        $row['cumulative_gpa_value'] = $cgpa;
        $row['promotion_metric'] = $promotionMetric;
        $row['promotion_metric_label'] = $promotionMetricLabel;

        return $row;
    } catch (Exception $e) {
        return null;
    }
}

function getRepeatSemesterDecision(PDO $conn, int $studentId, int $targetAcademicYearId = 0, int $targetSemesterNumber = 0) {
    if ($studentId <= 0) {
        return null;
    }
    try {
        $row = getLatestStudentGpaSummary($conn, $studentId);
        if (!$row) {
            return null;
        }

        $promotionMetric = isset($row['promotion_metric']) && $row['promotion_metric'] !== null
            ? (float)$row['promotion_metric']
            : null;
        if ($promotionMetric === null || $promotionMetric >= 2.0) {
            return null;
        }

        $yosStmt = $conn->prepare("
            SELECT year_of_study
            FROM semester_registrations
            WHERE student_id = :student_id AND semester_id = :semester_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $yosStmt->execute([
            'student_id' => $studentId,
            'semester_id' => (int)$row['semester_id']
        ]);
        $repeatYear = (int)$yosStmt->fetchColumn();
        if ($repeatYear <= 0) {
            $cyStmt = $conn->prepare("
                SELECT MAX(c.level_year)
                FROM course_registrations cr
                INNER JOIN courses c ON c.id = cr.course_id
                WHERE cr.student_id = :student_id
                  AND cr.semester_id = :semester_id
            ");
            $cyStmt->execute([
                'student_id' => $studentId,
                'semester_id' => (int)$row['semester_id']
            ]);
            $repeatYear = (int)$cyStmt->fetchColumn();
        }

        $repeatSemesterId = (int)$row['semester_id'];
        $repeatAcademicYearId = (int)$row['academic_year_id'];
        $repeatSemesterNumber = (int)$row['semester_number'];
        $repeatSemesterName = (string)($row['semester_name'] ?? '');
        $repeatYearName = (string)($row['year_name'] ?? '');

        // Force repeat to the immediate previous semester of the attempted semester.
        // Example: if attempting Semester 2, repeat Semester 1 of the same academic year.
        if ($targetAcademicYearId > 0 && $targetSemesterNumber >= 1 && $targetSemesterNumber <= 2) {
            if ($targetSemesterNumber === 2) {
                $prevStmt = $conn->prepare("
                    SELECT s.id, s.academic_year_id, s.semester_number, s.semester_name, ay.year_name
                    FROM semesters s
                    INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                    WHERE s.academic_year_id = :ay_id AND s.semester_number = 1
                    LIMIT 1
                ");
                $prevStmt->execute(['ay_id' => $targetAcademicYearId]);
                $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
                if ($prev) {
                    $repeatSemesterId = (int)$prev['id'];
                    $repeatAcademicYearId = (int)$prev['academic_year_id'];
                    $repeatSemesterNumber = (int)$prev['semester_number'];
                    $repeatSemesterName = (string)($prev['semester_name'] ?? $repeatSemesterName);
                    $repeatYearName = (string)($prev['year_name'] ?? $repeatYearName);
                }
            } else {
                $prevStmt = $conn->prepare("
                    SELECT s.id, s.academic_year_id, s.semester_number, s.semester_name, ay.year_name
                    FROM academic_years cay
                    INNER JOIN academic_years pay ON pay.start_date < cay.start_date
                    INNER JOIN semesters s ON s.academic_year_id = pay.id AND s.semester_number = 2
                    INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                    WHERE cay.id = :ay_id
                    ORDER BY pay.start_date DESC
                    LIMIT 1
                ");
                $prevStmt->execute(['ay_id' => $targetAcademicYearId]);
                $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
                if ($prev) {
                    $repeatSemesterId = (int)$prev['id'];
                    $repeatAcademicYearId = (int)$prev['academic_year_id'];
                    $repeatSemesterNumber = (int)$prev['semester_number'];
                    $repeatSemesterName = (string)($prev['semester_name'] ?? $repeatSemesterName);
                    $repeatYearName = (string)($prev['year_name'] ?? $repeatYearName);
                }
            }

            if ($repeatYear <= 0) {
                $prevYosStmt = $conn->prepare("
                    SELECT year_of_study
                    FROM semester_registrations
                    WHERE student_id = :student_id AND semester_id = :semester_id
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $prevYosStmt->execute([
                    'student_id' => $studentId,
                    'semester_id' => $repeatSemesterId
                ]);
                $repeatYear = (int)$prevYosStmt->fetchColumn();
            }
        }

        return [
            'semester_id' => $repeatSemesterId,
            'academic_year_id' => $repeatAcademicYearId,
            'semester_number' => $repeatSemesterNumber,
            'semester_name' => $repeatSemesterName,
            'year_name' => $repeatYearName,
            'promotion_metric' => $promotionMetric,
            'promotion_metric_label' => (string)($row['promotion_metric_label'] ?? 'GPA'),
            'semester_gpa' => $row['semester_gpa_value'] !== null ? (float)$row['semester_gpa_value'] : null,
            'cumulative_gpa' => $row['cumulative_gpa_value'] !== null ? (float)$row['cumulative_gpa_value'] : null,
            'year_of_study' => $repeatYear > 0 ? $repeatYear : null,
        ];
    } catch (Exception $e) {
        return null;
    }
}

function getOutstandingRetakeSummary(PDO $conn, int $studentId): array
{
    $summary = [
        'count' => 0,
        'courses' => [],
    ];
    if ($studentId <= 0) {
        return $summary;
    }

    try {
        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM results r
            INNER JOIN semesters s ON s.id = r.semester_id
            WHERE r.student_id = :student_id
              AND r.status = 'published'
              AND NOT EXISTS (
                    SELECT 1
                    FROM results r2
                    INNER JOIN semesters s2 ON s2.id = r2.semester_id
                    WHERE r2.student_id = r.student_id
                      AND r2.course_id = r.course_id
                      AND r2.status = 'published'
                      AND (
                            s2.end_date > s.end_date
                            OR (s2.end_date = s.end_date AND s2.start_date > s.start_date)
                            OR (s2.end_date = s.end_date AND s2.start_date = s.start_date AND r2.id > r.id)
                      )
              )
              AND (
                    (r.grade_points IS NOT NULL AND r.grade_points < 2.0)
                    OR UPPER(COALESCE(r.grade, '')) IN ('E', 'F')
              )
        ");
        $countStmt->execute(['student_id' => $studentId]);
        $summary['count'] = (int)$countStmt->fetchColumn();

        if ($summary['count'] > 0) {
            $courseStmt = $conn->prepare("
                SELECT c.course_code
                FROM results r
                INNER JOIN semesters s ON s.id = r.semester_id
                INNER JOIN courses c ON c.id = r.course_id
                WHERE r.student_id = :student_id
                  AND r.status = 'published'
                  AND NOT EXISTS (
                        SELECT 1
                        FROM results r2
                        INNER JOIN semesters s2 ON s2.id = r2.semester_id
                        WHERE r2.student_id = r.student_id
                          AND r2.course_id = r.course_id
                          AND r2.status = 'published'
                          AND (
                                s2.end_date > s.end_date
                                OR (s2.end_date = s.end_date AND s2.start_date > s.start_date)
                                OR (s2.end_date = s.end_date AND s2.start_date = s.start_date AND r2.id > r.id)
                          )
                  )
                  AND (
                        (r.grade_points IS NOT NULL AND r.grade_points < 2.0)
                        OR UPPER(COALESCE(r.grade, '')) IN ('E', 'F')
                  )
                ORDER BY c.course_code ASC
                LIMIT 5
            ");
            $courseStmt->execute(['student_id' => $studentId]);
            $summary['courses'] = array_values(array_filter(array_map('trim', $courseStmt->fetchAll(PDO::FETCH_COLUMN))));
        }
    } catch (Exception $e) {
        // Keep quiet; reminder is non-fatal.
    }

    return $summary;
}

$forcedRepeatDecision = getRepeatSemesterDecision(
    $conn,
    (int)$studentProfile['id'],
    (int)$selectedAcademicYearId,
    (int)$selectedSemesterNumber
);
$isRepeatLocked = false;
$repeatEnforcedNotice = '';
if ($forcedRepeatDecision) {
    $isRepeatLocked = true;
    $semesterId = (int)$forcedRepeatDecision['semester_id'];
    $selectedAcademicYearId = (int)$forcedRepeatDecision['academic_year_id'];
    $selectedSemesterNumber = (int)$forcedRepeatDecision['semester_number'];
    if (!empty($forcedRepeatDecision['year_of_study'])) {
        $yearOfStudy = (int)$forcedRepeatDecision['year_of_study'];
    }

    foreach ($academicYears as $ay) {
        if ((int)$ay['id'] === (int)$selectedAcademicYearId) {
            $selectedAcademicYearName = $ay['year_name'];
            break;
        }
    }

    $metricLabel = (string)($forcedRepeatDecision['promotion_metric_label'] ?? 'GPA');
    $metricValue = isset($forcedRepeatDecision['promotion_metric']) ? (float)$forcedRepeatDecision['promotion_metric'] : 0.0;
    $repeatEnforcedNotice =
        'Promotion requires GPA 2.00 or above. You are locked to repeat ' .
        (($forcedRepeatDecision['year_name'] ?? '') ?: 'the previous academic year') . ' - ' .
        (($forcedRepeatDecision['semester_name'] ?? '') ?: 'the previous semester') . '. ' .
        'Your latest ' . $metricLabel . ' is ' . number_format($metricValue, 2) . '.';
}

$latestGpaSummary = getLatestStudentGpaSummary($conn, (int)$studentProfile['id']);
$promotionMetricNow = (is_array($latestGpaSummary) && isset($latestGpaSummary['promotion_metric']) && $latestGpaSummary['promotion_metric'] !== null)
    ? (float)$latestGpaSummary['promotion_metric']
    : null;
$retakeSummary = getOutstandingRetakeSummary($conn, (int)$studentProfile['id']);
$retakeReminderNotice = '';
if (!$isRepeatLocked && $promotionMetricNow !== null && $promotionMetricNow >= 2.0 && (int)($retakeSummary['count'] ?? 0) > 0) {
    $retakeCount = (int)$retakeSummary['count'];
    $retakeList = !empty($retakeSummary['courses']) ? implode(', ', $retakeSummary['courses']) : '';
    $retakeReminderNotice = 'You are eligible to continue (GPA >= 2.00), but you still have '
        . $retakeCount . ' retake paper' . ($retakeCount === 1 ? '' : 's') . '.';
    if ($retakeList !== '') {
        $retakeReminderNotice .= ' Pending retakes: ' . $retakeList . ($retakeCount > count($retakeSummary['courses']) ? ' ...' : '') . '.';
    }
}
if (!isset($_GET['has_retakes']) && (int)($retakeSummary['count'] ?? 0) > 0) {
    $selectedHasRetakes = 'yes';
}

// Recompute window against final resolved semester context (important after repeat-lock override).
$currentSemesterWindow = $getSemesterRegistrationWindow((int)$semesterId);
$isEnrollmentWindowOpen = (bool)$currentSemesterWindow['open'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'enroll_now')) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php');
        exit;
    }

    $selectedAcademicYearId = isset($_POST['academic_year_id']) ? (int)$_POST['academic_year_id'] : $selectedAcademicYearId;
    $selectedSemesterNumber = isset($_POST['semester_number']) ? (int)$_POST['semester_number'] : $selectedSemesterNumber;
    if ($selectedSemesterNumber < 1 || $selectedSemesterNumber > 2) {
        $selectedSemesterNumber = 1;
    }
    $yearOfStudy = isset($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : $yearOfStudy;
    $yearOfStudy = max(1, min(10, $yearOfStudy));

    $mapStmt = $conn->prepare("SELECT id FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1");
    $mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
    $semesterId = (int)$mapStmt->fetchColumn();
    if ($semesterId <= 0) {
        $session->setFlash('error', 'Selected semester was not found. Please contact administration.');
        header('Location: course-registration.php');
        exit;
    }

    $repeatDecision = getRepeatSemesterDecision(
        $conn,
        (int)$studentProfile['id'],
        (int)$selectedAcademicYearId,
        (int)$selectedSemesterNumber
    );
    if ($repeatDecision && (int)$repeatDecision['semester_id'] !== $semesterId) {
        $selectedAcademicYearId = (int)$repeatDecision['academic_year_id'];
        $selectedSemesterNumber = (int)$repeatDecision['semester_number'];
        $semesterId = (int)$repeatDecision['semester_id'];
        if (!empty($repeatDecision['year_of_study'])) {
            $yearOfStudy = (int)$repeatDecision['year_of_study'];
        }
        $metricLabel = (string)($repeatDecision['promotion_metric_label'] ?? 'GPA');
        $metricValue = isset($repeatDecision['promotion_metric']) ? (float)$repeatDecision['promotion_metric'] : 0.0;
        $session->setFlash(
            'warning',
            'Promotion requires GPA 2.00 or above. Repeat semester enforced for ' .
            ($repeatDecision['year_name'] ?: 'selected year') . ' - ' . ($repeatDecision['semester_name'] ?: 'semester') .
            ' (' . $metricLabel . ': ' . number_format($metricValue, 2) . ').'
        );
    }

    // Strict lock: students cannot self-enroll outside registration window.
    $postWindow = $getSemesterRegistrationWindow((int)$semesterId);
    if (!$postWindow['configured'] || !$postWindow['open']) {
        $period = ($postWindow['registration_start_date'] && $postWindow['registration_end_date'])
            ? ($postWindow['registration_start_date'] . ' to ' . $postWindow['registration_end_date'])
            : 'not configured';
        $session->setFlash(
            'error',
            'Enrollment window is closed for ' . (($postWindow['semester_label'] ?: 'this semester')) .
            '. Allowed period: ' . $period . '. Please contact admin for manual enrollment.'
        );
        header('Location: course-registration.php?semester_id=' . $semesterId . '&academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&year_of_study=' . $yearOfStudy);
        exit;
    }

    try {
        $conn->beginTransaction();

        $latestRegStmt = $conn->prepare("
            SELECT id
            FROM semester_registrations
            WHERE student_id = :student_id
              AND semester_id = :semester_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $latestRegStmt->execute([
            'student_id' => (int)$studentProfile['id'],
            'semester_id' => (int)$semesterId
        ]);
        $latestRegId = (int)$latestRegStmt->fetchColumn();
        if ($latestRegId > 0) {
            $upReg = $conn->prepare("
                UPDATE semester_registrations
                SET year_of_study = :year_of_study,
                    status = 'approved',
                    request_date = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upReg->execute([
                'year_of_study' => (int)$yearOfStudy,
                'id' => $latestRegId
            ]);
        } else {
            $insertReg = $conn->prepare("
                INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at, updated_at)
                VALUES (:student_id, :semester_id, :year_of_study, 'approved', NOW(), NOW(), NOW())
            ");
            $insertReg->execute([
                'student_id' => (int)$studentProfile['id'],
                'semester_id' => (int)$semesterId,
                'year_of_study' => (int)$yearOfStudy
            ]);
        }

        $assignedOk = auto_assign_courses(
            $conn,
            (int)$studentProfile['id'],
            $semesterId,
            null,
            (int)($studentProfile['program_id'] ?? 0),
            $yearOfStudy
        );

        // Status columns can differ across deployments; do not fail enrollment if absent.
        try {
            $statusSnapshot = getStudentLifecycleStatus($conn, (int)$studentProfile['id'], $semesterId);
            $studentCols = [];
            $scStmt = $conn->query("SHOW COLUMNS FROM students");
            while ($sc = $scStmt->fetch(PDO::FETCH_ASSOC)) {
                $studentCols[] = strtolower((string)($sc['Field'] ?? ''));
            }
            $setParts = [];
            $params = ['student_id' => (int)$studentProfile['id']];
            if (in_array('enrollment_status', $studentCols, true)) {
                $setParts[] = "enrollment_status = :enrollment_status";
                $params['enrollment_status'] = $statusSnapshot['enrollment_status'] === 'enrolled' ? 'enrolled' : 'not_enrolled';
            }
            if (in_array('registration_status', $studentCols, true)) {
                $setParts[] = "registration_status = :registration_status";
                $params['registration_status'] = $statusSnapshot['registration_status'] === 'registered' ? 'registered' : 'not_registered';
            }
            if (in_array('updated_at', $studentCols, true)) {
                $setParts[] = "updated_at = NOW()";
            }
            if (!empty($setParts)) {
                $updateStudentStatus = $conn->prepare("UPDATE students SET " . implode(', ', $setParts) . " WHERE id = :student_id");
                $updateStudentStatus->execute($params);
            }
        } catch (Exception $e) {
            error_log('Enrollment status-sync warning: ' . $e->getMessage());
        }

        $conn->commit();
        if (!$session->hasFlash('warning')) {
            $session->setFlash('success', 'Enrollment completed successfully. Your semester courses are now available.');
        }
        $postGpaSummary = getLatestStudentGpaSummary($conn, (int)$studentProfile['id']);
        $postPromotionMetric = (is_array($postGpaSummary) && isset($postGpaSummary['promotion_metric']) && $postGpaSummary['promotion_metric'] !== null)
            ? (float)$postGpaSummary['promotion_metric']
            : null;
        $postRetakes = getOutstandingRetakeSummary($conn, (int)$studentProfile['id']);
        if ($postPromotionMetric !== null && $postPromotionMetric >= 2.0 && (int)($postRetakes['count'] ?? 0) > 0) {
            $retakeCount = (int)$postRetakes['count'];
            $retakeList = !empty($postRetakes['courses']) ? implode(', ', $postRetakes['courses']) : '';
            $msg = 'Reminder: continue with next semester, but complete your ' . $retakeCount
                . ' outstanding retake paper' . ($retakeCount === 1 ? '' : 's') . '.';
            if ($retakeList !== '') {
                $msg .= ' Pending: ' . $retakeList . ($retakeCount > count($postRetakes['courses']) ? ' ...' : '') . '.';
            }
            $session->setFlash('info', $msg);
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if ($session->hasFlash('warning')) {
            $session->setFlash('warning', null);
        }
        error_log('Enrollment action error: ' . $e->getMessage());
        $session->setFlash('error', 'Enrollment failed. Please try again or contact administration.');
    }

    header('Location: course-registration.php?semester_id=' . $semesterId . '&academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&year_of_study=' . $yearOfStudy);
    exit;
}

// Check if student has an APPROVED semester registration for the selected semester
$approvalCheckStmt = $conn->prepare("
    SELECT sr.*, ay.year_name 
    FROM semester_registrations sr
    JOIN semesters s ON sr.semester_id = s.id
    JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE sr.student_id = :student_id 
    AND sr.semester_id = :semester_id 
    AND sr.status = 'approved'
    ORDER BY sr.id DESC
    LIMIT 1
");
$approvalCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$semesterApproval = $approvalCheckStmt->fetch();
if ($semesterApproval && !isset($_GET['year_of_study'])) {
    $approvedYear = (int)($semesterApproval['year_of_study'] ?? 0);
    if ($approvedYear > 0) {
        $yearOfStudy = $approvedYear;
    }
}

// If approval exists but year_of_study has changed, reassign courses
if ($semesterApproval && isset($semesterApproval['year_of_study']) && (int)$semesterApproval['year_of_study'] !== (int)$yearOfStudy) {
    try {
        // Update year_of_study in semester_registrations
        $updateYearStmt = $conn->prepare("
            UPDATE semester_registrations 
            SET year_of_study = :year_of_study, updated_at = NOW()
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $updateYearStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId,
            'year_of_study' => $yearOfStudy
        ]);
        
        // Delete old course registrations for this semester
        $deleteCoursesStmt = $conn->prepare("
            DELETE FROM course_registrations 
            WHERE student_id = :student_id
              AND semester_id = :semester_id
              AND NOT EXISTS (
                  SELECT 1
                  FROM results r
                  WHERE r.student_id = course_registrations.student_id
                    AND r.course_id = course_registrations.course_id
                    AND r.semester_id = course_registrations.semester_id
              )
        ");
        $deleteCoursesStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId
        ]);
        
        // Re-assign courses for new year/semester using resilient fallback chain.
        auto_assign_courses(
            $conn,
            (int)$studentProfile['id'],
            (int)$semesterId,
            null,
            (int)($studentProfile['program_id'] ?? 0),
            (int)$yearOfStudy
        );
        $assignedCountStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM course_registrations
            WHERE student_id = :student_id
              AND semester_id = :semester_id
              AND status = 'approved'
              AND EXISTS (
                  SELECT 1
                  FROM courses c
                  WHERE c.id = course_registrations.course_id
                    AND c.level_year = :level_year
                    AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
              )
        ");
        $assignedCountStmt->execute([
            'student_id' => (int)$studentProfile['id'],
            'semester_id' => (int)$semesterId,
            'level_year' => (int)$yearOfStudy,
            'semester_offered' => (int)$selectedSemesterNumber
        ]);
        $assignedCount = (int)$assignedCountStmt->fetchColumn();
        if ($assignedCount > 0) {
            $session->setFlash('success', "Courses updated! $assignedCount course(s) assigned for Year $yearOfStudy, Semester $selectedSemesterNumber.");
        }
        
        // Update $semesterApproval with new year_of_study
        $semesterApproval['year_of_study'] = $yearOfStudy;
        
    } catch (Exception $e) {
        error_log("Course reassignment error: " . $e->getMessage());
        $session->setFlash('error', "Failed to update courses: " . $e->getMessage());
    }
}

// Self-heal: if selected semester has no approved courses, try assigning only for
// this selected semester/year/program without switching the semester context.
try {
    $approvedCountStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_registrations
        WHERE student_id = :student_id
          AND semester_id = :semester_id
          AND status = 'approved'
          AND EXISTS (
              SELECT 1
              FROM courses c
              WHERE c.id = course_registrations.course_id
                AND c.level_year = :level_year
                AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
          )
    ");
    $approvedCountStmt->execute([
        'student_id' => (int)$studentProfile['id'],
        'semester_id' => (int)$semesterId,
        'level_year' => (int)$yearOfStudy,
        'semester_offered' => (int)$selectedSemesterNumber
    ]);
    $currentApprovedCount = (int)$approvedCountStmt->fetchColumn();

    if ($currentApprovedCount === 0) {
        auto_assign_courses(
            $conn,
            (int)$studentProfile['id'],
            (int)$semesterId,
            null,
            (int)($studentProfile['program_id'] ?? 0),
            (int)$yearOfStudy
        );
    }
} catch (Exception $e) {
    error_log('Course-registration fallback warning: ' . $e->getMessage());
}

// AUTO-REGISTER: If no approval exists, automatically create one and assign courses
if (false && !$semesterApproval && $semesterId > 0) {
    try {
        // First check if there's ANY registration (pending, approved, rejected) for this semester
        $existingRegStmt = $conn->prepare("
            SELECT * FROM semester_registrations 
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $existingRegStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId
        ]);
        $existingReg = $existingRegStmt->fetch();
        
        if ($existingReg) {
            // Update existing registration to approved
            $updateRegStmt = $conn->prepare("
                UPDATE semester_registrations 
                SET status = 'approved', year_of_study = :year_of_study, updated_at = NOW()
                WHERE student_id = :student_id AND semester_id = :semester_id
            ");
            $updateRegStmt->execute([
                'student_id' => $studentProfile['id'],
                'semester_id' => $semesterId,
                'year_of_study' => $yearOfStudy
            ]);
        } else {
            // Create new semester registration
            $autoRegStmt = $conn->prepare("
                INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at, updated_at) 
                VALUES (:student_id, :semester_id, :year_of_study, 'approved', NOW(), NOW(), NOW())
            ");
            $autoRegStmt->execute([
                'student_id' => $studentProfile['id'],
                'semester_id' => $semesterId,
                'year_of_study' => $yearOfStudy
            ]);
        }

        // Auto-assign courses
        $courseIds = [];
        $assignedCount = 0;
        
        // Fetch courses matching student's year and semester
        $coursesStmt = $conn->prepare("
            SELECT id FROM courses 
            WHERE semester_offered = :semester_offered
            AND level_year = :level_year
            AND status = 'active' 
            AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
            ORDER BY course_code ASC
        ");
        $coursesStmt->execute([
            'semester_offered' => $selectedSemesterNumber, 
            'program_id' => $studentProfile['program_id'] ?? 0, 
            'level_year' => $yearOfStudy
        ]);
        $courseIds = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);

        // Relaxed search if no courses found
        if (empty($courseIds)) {
            $relaxedStmt = $conn->prepare("
                SELECT id FROM courses 
                WHERE semester_offered = :semester_offered
                AND level_year = :level_year
                AND status = 'active'
                ORDER BY course_code ASC
            ");
            $relaxedStmt->execute([
                'semester_offered' => $selectedSemesterNumber,
                'level_year' => $yearOfStudy
            ]);
            $courseIds = $relaxedStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Even more relaxed - get all active courses
        if (empty($courseIds)) {
            $allCoursesStmt = $conn->prepare("SELECT id FROM courses WHERE status = 'active' ORDER BY course_code ASC");
            $allCoursesStmt->execute();
            $courseIds = $allCoursesStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Assign courses to student
        if (!empty($courseIds)) {
            $checkCourseStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
            $insCourseStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

            foreach ($courseIds as $cid) {
                try {
                    $checkCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    if ($checkCourseStmt->fetch()) continue;
                    $insCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    $assignedCount++;
                } catch (Exception $courseEx) {
                    error_log("Failed to assign course $cid: " . $courseEx->getMessage());
                }
            }
        }

        // Re-fetch approval status
        $approvalCheckStmt->execute([
            'student_id' => $studentProfile['id'], 
            'semester_id' => $semesterId
        ]);
        $semesterApproval = $approvalCheckStmt->fetch();
        
        if ($assignedCount > 0) {
            $session->setFlash('success', "Welcome! You have been automatically registered. $assignedCount course(s) assigned.");
        } elseif (!empty($courseIds)) {
            $session->setFlash('info', "You have been registered. Courses are already in your list.");
        } else {
            $session->setFlash('info', "You have been registered. No courses available yet for Year $yearOfStudy, Semester $selectedSemesterNumber.");
        }
    } catch (Exception $e) {
        error_log("Auto-registration error: " . $e->getMessage());
        $session->setFlash('error', "Registration error: " . $e->getMessage() . ". Please contact administration.");
        
        // Try to re-fetch in case registration succeeded but course assignment failed
        $approvalCheckStmt->execute([
            'student_id' => $studentProfile['id'], 
            'semester_id' => $semesterId
        ]);
        $semesterApproval = $approvalCheckStmt->fetch();
    }
}

// Check if student has a pending request
// Specify table alias for the ambiguous 'status' column
$pendingCheckStmt = $conn->prepare("
    SELECT * FROM semester_registrations sr
    WHERE sr.student_id = :student_id 
    AND sr.semester_id = :semester_id 
    AND sr.status = 'pending'
    LIMIT 1
");
$pendingCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$pendingRequest = $pendingCheckStmt->fetch();

// Auto-create missing semester registration if not found
/*
$regCheckStmt = $conn->prepare("SELECT id FROM semester_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
$regCheckStmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
if (!$regCheckStmt->fetch()) {
    $now = date('Y-m-d H:i:s');
    $autoRegStmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :status, :request_date, :created_at, :updated_at)");
    $autoRegStmt->execute([
        'student_id' => $studentProfile['id'],
        'semester_id' => $semesterId,
        'status' => 'pending',
        'request_date' => $now,
        'created_at' => $now,
        'updated_at' => $now
    ]);
}
*/

// Handle semester registration request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_semester_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Strict lock: students cannot create/update semester registration outside configured window.
    $requestWindow = $getSemesterRegistrationWindow((int)$semesterId);
    if (!$requestWindow['configured'] || !$requestWindow['open']) {
        $period = ($requestWindow['registration_start_date'] && $requestWindow['registration_end_date'])
            ? ($requestWindow['registration_start_date'] . ' to ' . $requestWindow['registration_end_date'])
            : 'not configured';
        $session->setFlash(
            'error',
            'Registration window is closed for ' . (($requestWindow['semester_label'] ?: 'this semester')) .
            '. Allowed period: ' . $period . '. Please contact admin for manual enrollment.'
        );
        header('Location: course-registration.php?semester_id=' . $semesterId . '&academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&year_of_study=' . $yearOfStudy);
        exit;
    }

    // Allow all students to register again (remove block for existing registration)
    // Optionally, you can keep a log of previous registrations if needed

    // Create semester registration request (include year_of_study)
    $insertStmt = $conn->prepare("
        INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at) 
        VALUES (:student_id, :semester_id, :year_of_study, 'pending', NOW(), NOW())
    ");
    
    try {
        // Get the year_of_study from POST or use selected value
        $registrationYearOfStudy = isset($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : $yearOfStudy;
        if ($isRepeatLocked && !empty($forcedRepeatDecision['year_of_study'])) {
            $registrationYearOfStudy = (int)$forcedRepeatDecision['year_of_study'];
        }
        
        // Immediately approve semester registration so students can register for courses.
        // Update latest existing row instead of creating duplicates each time.
        $existingRegStmt = $conn->prepare("
            SELECT id
            FROM semester_registrations
            WHERE student_id = :student_id
              AND semester_id = :semester_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $existingRegStmt->execute([
            'student_id' => (int)$studentProfile['id'],
            'semester_id' => (int)$semesterId
        ]);
        $existingRegId = (int)$existingRegStmt->fetchColumn();
        if ($existingRegId > 0) {
            $upRegStmt = $conn->prepare("
                UPDATE semester_registrations
                SET year_of_study = :year_of_study,
                    status = 'approved',
                    request_date = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upRegStmt->execute([
                'year_of_study' => (int)$registrationYearOfStudy,
                'id' => $existingRegId
            ]);
        } else {
            $insertStmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :year_of_study, 'approved', NOW(), NOW(), NOW())");
            $insertStmt->execute([
                'student_id' => (int)$studentProfile['id'],
                'semester_id' => (int)$semesterId,
                'year_of_study' => (int)$registrationYearOfStudy
            ]);
        }

        // Auto-assign available courses for this semester to the student so they appear immediately
        $courseIds = [];
        $assignedCount = 0;
        try {

            // 1) First try: Strict matching - exact year, exact semester, matching program
            try {
                $coursesStmt = $conn->prepare("
                    SELECT id FROM courses 
                    WHERE semester_offered = :semester_offered
                    AND level_year = :level_year
                    AND status = 'active' 
                    AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
                    ORDER BY course_code ASC
                ");
                $coursesStmt->execute([
                    'semester_offered' => $selectedSemesterNumber, 
                    'program_id' => $studentProfile['program_id'] ?? 0, 
                    'level_year' => $registrationYearOfStudy
                ]);
                $courseIds = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Log for debugging
                error_log("Course search (strict): semester=$selectedSemesterNumber, year=$registrationYearOfStudy, program=" . ($studentProfile['program_id'] ?? 0) . ", found=" . count($courseIds));
            } catch (Exception $e) {
                error_log("Failed to fetch courses (strict): " . $e->getMessage());
            }

            // 2) Second try: Relaxed program matching
            if (empty($courseIds)) {
                try {
                    $relaxedStmt = $conn->prepare("
                        SELECT id FROM courses 
                        WHERE semester_offered = :semester_offered
                        AND level_year = :level_year
                        AND status = 'active'
                        ORDER BY course_code ASC
                    ");
                    $relaxedStmt->execute([
                        'semester_offered' => $selectedSemesterNumber,
                        'level_year' => $registrationYearOfStudy
                    ]);
                    $courseIds = $relaxedStmt->fetchAll(PDO::FETCH_COLUMN);
                    error_log("Course search (relaxed): found=" . count($courseIds));
                } catch (Exception $e) {
                    error_log("Failed to fetch courses (relaxed): " . $e->getMessage());
                }
            }

            // 3) If still no courses found, check course_assignments table
            if (empty($courseIds)) {
                try {
                    $caStmt = $conn->prepare("
                        SELECT ca.course_id FROM course_assignments ca 
                        JOIN courses c ON c.id = ca.course_id
                        WHERE ca.semester_id = :semester_id
                        AND ca.status = 'active'
                        AND c.status = 'active'
                        AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
                        AND c.level_year = :year_of_study
                        AND (c.program_id = :program_id OR c.program_id IS NULL OR c.program_id = 0)
                    ");
                    $caStmt->execute([
                        'semester_id' => $semesterId, 
                        'semester_offered' => $selectedSemesterNumber,
                        'program_id' => $studentProfile['program_id'] ?? 0, 
                        'year_of_study' => $registrationYearOfStudy
                    ]);
                    $courseIds = $caStmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $ignore) {
                    // course_assignments may not exist; fall through
                }
            }

            // 3) Assign found courses to the student
            if (!empty($courseIds)) {
                $checkCourseStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
                $insCourseStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

                foreach ($courseIds as $cid) {
                    $checkCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    if ($checkCourseStmt->fetch()) continue;
                    $insCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    $assignedCount++;
                }
                error_log("Assigned $assignedCount courses to student " . $studentProfile['id']);
            }
        } catch (PDOException $innerEx) {
            // non-fatal: if course auto-assign fails, continue — student can still select courses manually
            error_log("Course auto-assign error: " . $innerEx->getMessage());
        }

        // Set appropriate flash message
        if (!empty($courseIds) && $assignedCount > 0) {
            $session->setFlash('success', "Semester registration approved! $assignedCount course(s) have been assigned to you.");
        } elseif (!empty($courseIds)) {
            $session->setFlash('info', 'Semester registration approved! You are already registered for all available courses.');
        } else {
            $session->setFlash('info', 'Semester registration approved! No courses are currently available for your year and semester. Please contact administration.');
        }
        
        // Redirect with proper parameters to show registered courses
        $redirectUrl = 'course-registration.php?semester_id=' . $semesterId 
                     . '&academic_year_id=' . $selectedAcademicYearId 
                     . '&semester_number=' . $selectedSemesterNumber 
                     . '&year_of_study=' . $registrationYearOfStudy;
        header('Location: ' . $redirectUrl);
        exit;

    } catch (PDOException $e) {
        error_log("Semester registration error: " . $e->getMessage());
        $session->setFlash('error', 'Failed to submit registration request. Please try again.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }
}

// Handle course registration submission (only if semester registration is approved)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_course_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Verify semester registration is approved
    if (!$semesterApproval) {
        $session->setFlash('error', 'You must have an approved semester registration before registering for courses.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

        // Server-side guard: if courses were already assigned (approved) or admin-managed
        // course_assignments exist for this semester/program/year, disallow manual selection.
        try {
            $assignedCountStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM course_registrations cr
                INNER JOIN courses c ON c.id = cr.course_id
                WHERE cr.student_id = :student_id
                  AND cr.semester_id = :semester_id
                  AND cr.status = 'approved'
                  AND c.level_year = :year_of_study
                  AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
            ");
            $assignedCountStmt->execute([
                'student_id' => (int)$studentProfile['id'],
                'semester_id' => (int)$semesterId,
                'year_of_study' => (int)$yearOfStudy,
                'semester_offered' => (int)$selectedSemesterNumber
            ]);
            if ($assignedCountStmt->fetchColumn() > 0) {
                $session->setFlash('error', 'Your courses have already been assigned for this semester. Selection is disabled.');
                header('Location: course-registration.php?semester_id=' . $semesterId);
                exit;
            }
        } catch (Exception $e) {
            // ignore and continue
        }

        try {
            $caCheck = $conn->prepare("
                SELECT COUNT(*)
                FROM course_assignments ca
                INNER JOIN courses c ON c.id = ca.course_id
                WHERE ca.semester_id = :semester_id
                  AND ca.status = 'active'
                  AND c.status = 'active'
                  AND c.level_year = :year_of_study
                  AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
                  AND (c.program_id = :program_id OR c.program_id IS NULL OR c.program_id = 0)
            ");
            $caCheck->execute([
                'semester_id' => $semesterId,
                'semester_offered' => $selectedSemesterNumber,
                'program_id' => $studentProfile['program_id'] ?? 0,
                'year_of_study' => $yearOfStudy
            ]);
            if ($caCheck->fetchColumn() > 0) {
                $session->setFlash('error', 'Courses for this semester are assigned by the administration; manual selection is disabled.');
                header('Location: course-registration.php?semester_id=' . $semesterId);
                exit;
            }
        } catch (Exception $e) {
            // if course_assignments table missing, ignore
        }

    $selected = $_POST['courses'] ?? [];
    if (!is_array($selected) || count($selected) === 0) {
        $session->setFlash('error', 'Please select at least one course to register.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    $inserted = 0;
    $duplicates = 0;
    $errors = 0;

    $checkStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
    $insStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

    foreach ($selected as $cid) {
        $courseId = (int)$cid;
        if ($courseId <= 0) continue;

        // skip if already registered
        $checkStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $courseId, 'semester_id' => $semesterId]);
        if ($checkStmt->fetch()) {
            $duplicates++;
            continue;
        }

        try {
            $insStmt->execute([
                'student_id' => $studentProfile['id'],
                'course_id' => $courseId,
                'semester_id' => $semesterId
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors++;
            continue;
        }
    }

    // Build notification message
    $msgParts = [];
    if ($inserted > 0) {
        $msgParts[] = "$inserted course(s) registered successfully.";
    }
    if ($duplicates) $msgParts[] = "$duplicates course(s) were already registered.";
    if ($errors) $msgParts[] = "$errors course(s) failed to register.";
    if (empty($msgParts)) $msgParts[] = 'No changes were made.';

    $session->setFlash('success', implode(' ', $msgParts));
    header('Location: course-registration.php?semester_id=' . $semesterId);
    exit;
}

// Fetch available courses: only show courses matching student's specific year, semester, and program
$availableCourses = [];
$fromAssignments = false;

// 1) First try: Strict matching - exact year, exact semester, matching program
$availableCoursesStmt = $conn->prepare("
    SELECT * FROM courses 
    WHERE semester_offered = :semester_offered
    AND level_year = :level_year
    AND status = 'active' 
    AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
    ORDER BY course_code ASC
");
$availableCoursesStmt->execute([
    'semester_offered' => $selectedSemesterNumber,
    'program_id' => $studentProfile['program_id'] ?? 0,
    'level_year' => $yearOfStudy
]);
$availableCourses = $availableCoursesStmt->fetchAll();

// 2) Second try: Relaxed program matching (if student has no program or courses have no program restriction)
if (empty($availableCourses)) {
    $relaxedStmt = $conn->prepare("
        SELECT * FROM courses 
        WHERE semester_offered = :semester_offered
        AND level_year = :level_year
        AND status = 'active'
        ORDER BY course_code ASC
    ");
    $relaxedStmt->execute([
        'semester_offered' => $selectedSemesterNumber,
        'level_year' => $yearOfStudy
    ]);
    $availableCourses = $relaxedStmt->fetchAll();
}

// 3) If still no courses found, check course_assignments
if (empty($availableCourses)) {
    try {
        $caStmt = $conn->prepare("
            SELECT c.* FROM course_assignments ca 
            JOIN courses c ON ca.course_id = c.id 
            WHERE ca.semester_id = :semester_id 
            AND ca.status = 'active' 
            AND c.status = 'active' 
            AND c.level_year = :year_of_study
            AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
            AND (c.program_id = :program_id OR c.program_id IS NULL OR c.program_id = 0)
            ORDER BY c.course_code
        ");
        $caStmt->execute([
            'semester_id' => $semesterId, 
            'semester_offered' => $selectedSemesterNumber,
            'program_id' => $studentProfile['program_id'] ?? 0, 
            'year_of_study' => $yearOfStudy
        ]);
        $availableCourses = $caStmt->fetchAll();
        $fromAssignments = !empty($availableCourses);
    } catch (Exception $e) {
        // ignore if course_assignments table missing
    }
}

// Already registered courses for this student & semester
$registered = [];
$approvedRegistered = [];
if ($semesterId && $semesterApproval) {
    $rstmt = $conn->prepare("SELECT course_id, status FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    while ($r = $rstmt->fetch()) {
        $courseId = (int)($r['course_id'] ?? 0);
        if ($courseId <= 0) {
            continue;
        }
        $status = strtolower(trim((string)($r['status'] ?? '')));
        $registered[$courseId] = $status;
        if ($status === 'approved') {
            $approvedRegistered[$courseId] = true;
        }
    }
}

// Determine if student still needs to select any courses (i.e., there are available courses not yet registered)
$needsSelection = false;
if (!empty($availableCourses)) {
    foreach ($availableCourses as $c) {
        if (!isset($approvedRegistered[(int)$c['id']])) { $needsSelection = true; break; }
    }
}

// If semester is approved but only a partial set is registered, auto-top-up missing
// courses from the resolved semester/year course list so enrollment reflects the
// full academic management course list for that semester.
if ($semesterApproval && $semesterId > 0 && !empty($availableCourses) && $needsSelection) {
    try {
        $insertedTopUp = 0;
        $updatedTopUp = 0;
        $checkCourseStmt = $conn->prepare("
            SELECT id, status
            FROM course_registrations
            WHERE student_id = :student_id
              AND course_id = :course_id
              AND semester_id = :semester_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $updateCourseStmt = $conn->prepare("
            UPDATE course_registrations
            SET status = 'approved',
                approved_by = NULL,
                approved_date = NOW()
            WHERE id = :id
        ");
        $insCourseStmt = $conn->prepare("
            INSERT INTO course_registrations
                (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at)
            VALUES
                (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())
        ");

        foreach ($availableCourses as $courseRow) {
            $courseId = (int)($courseRow['id'] ?? 0);
            if ($courseId <= 0) {
                continue;
            }
            $checkCourseStmt->execute([
                'student_id' => (int)$studentProfile['id'],
                'course_id' => $courseId,
                'semester_id' => (int)$semesterId
            ]);
            $existing = $checkCourseStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existingStatus = strtolower(trim((string)($existing['status'] ?? '')));
                if ($existingStatus !== 'approved') {
                    $updateCourseStmt->execute(['id' => (int)$existing['id']]);
                    $updatedTopUp++;
                }
                continue;
            }

            $insCourseStmt->execute([
                'student_id' => (int)$studentProfile['id'],
                'course_id' => $courseId,
                'semester_id' => (int)$semesterId
            ]);
            $insertedTopUp++;
        }

        if ($insertedTopUp > 0) {
            $rstmt = $conn->prepare("
                SELECT course_id, status
                FROM course_registrations
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
            ");
            $rstmt->execute([
                'student_id' => (int)$studentProfile['id'],
                'semester_id' => (int)$semesterId
            ]);
            $registered = [];
            $approvedRegistered = [];
            while ($r = $rstmt->fetch()) {
                $cid = (int)($r['course_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $st = strtolower(trim((string)($r['status'] ?? '')));
                $registered[$cid] = $st;
                if ($st === 'approved') {
                    $approvedRegistered[$cid] = true;
                }
            }
            $needsSelection = false;
        }
    } catch (Exception $e) {
        error_log('Enrollment top-up warning: ' . $e->getMessage());
    }
}

// Fetch approved registrations (courses student is supposed to attend)
$approvedCourses = [];
if ($semesterId) {
    $ac = $conn->prepare("SELECT cr.course_id, c.course_code, c.course_name, c.credit_hours, c.level_year, c.semester_offered
                          FROM course_registrations cr
                          JOIN courses c ON cr.course_id = c.id
                          WHERE cr.student_id = :student_id 
                          AND cr.semester_id = :semester_id 
                          AND cr.status = 'approved'
                          AND c.level_year = :level_year
                          AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
                          ORDER BY c.course_code");
    $ac->execute([
        'student_id' => $studentProfile['id'], 
        'semester_id' => $semesterId,
        'level_year' => (int)$yearOfStudy,
        'semester_offered' => $selectedSemesterNumber
    ]);
    $approvedCourses = $ac->fetchAll();
}

if ($isRepeatLocked && $semesterId && empty($approvedCourses)) {
    try {
        $assignedRepeat = auto_assign_courses(
            $conn,
            (int)$studentProfile['id'],
            (int)$semesterId,
            null,
            (int)($studentProfile['program_id'] ?? 0),
            (int)$yearOfStudy
        );
        if ($assignedRepeat) {
            $ac = $conn->prepare("SELECT cr.course_id, c.course_code, c.course_name, c.credit_hours, c.level_year, c.semester_offered
                                  FROM course_registrations cr
                                  JOIN courses c ON cr.course_id = c.id
                                  WHERE cr.student_id = :student_id
                                  AND cr.semester_id = :semester_id
                                  AND cr.status = 'approved'
                                  AND c.level_year = :level_year
                                  AND (c.semester_offered = :semester_offered OR c.semester_offered = 3)
                                  ORDER BY c.course_code");
            $ac->execute([
                'student_id' => $studentProfile['id'],
                'semester_id' => $semesterId,
                'level_year' => (int)$yearOfStudy,
                'semester_offered' => $selectedSemesterNumber
            ]);
            $approvedCourses = $ac->fetchAll();
        }
    } catch (Exception $e) {
        error_log('Repeat auto-assignment warning: ' . $e->getMessage());
    }
}

// Determine whether selection should be allowed. If courses were assigned by admin
// (`course_assignments`) or the student already has approved course_registrations,
// do not allow manual selection — show read-only list instead.
$selectionAllowed = true;
if (!empty($approvedCourses) || $fromAssignments) {
    $selectionAllowed = false;
}

// Fetch unread notifications for header bell
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

// Navigation links with safe fallbacks for pages that may not exist yet.
$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

$enrollmentHistory = [];
if (!empty($studentProfile['id'])) {
    try {
        $ehStmt = $conn->prepare("
            SELECT
                sr.id,
                sr.semester_id,
                sr.year_of_study,
                sr.status,
                sr.request_date,
                sr.created_at,
                sr.updated_at,
                s.semester_number,
                s.semester_name,
                ay.year_name
            FROM semester_registrations sr
            INNER JOIN (
                SELECT semester_id, MAX(id) AS max_id
                FROM semester_registrations
                WHERE student_id = :student_id_group
                GROUP BY semester_id
            ) latest ON latest.max_id = sr.id
            INNER JOIN semesters s ON sr.semester_id = s.id
            INNER JOIN academic_years ay ON s.academic_year_id = ay.id
            WHERE sr.student_id = :student_id_where
            ORDER BY ay.start_date DESC, s.semester_number ASC
        ");
        $ehStmt->execute([
            'student_id_group' => (int)$studentProfile['id'],
            'student_id_where' => (int)$studentProfile['id']
        ]);
        $enrollmentHistory = $ehStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $enrollmentHistory = [];
    }
}

$pageTitle = 'Course Registration - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
    /* Prevent horizontal scrolling on the entire page */
    html, body {
        overflow-x: hidden;
        max-width: 100%;
    }
    
    .main-content {
        margin-left: 230px;
        width: calc(100vw - 230px);
        max-width: calc(100vw - 230px);
        min-height: 100vh;
        overflow-x: hidden;
        background: #f8fafc;
        transition: margin-left 0.25s ease, width 0.25s ease;
    }
    .main-content.full-width {
        margin-left: 0;
        width: 100vw;
        max-width: 100vw;
    }
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
    .student-sidebar.sidebar-collapsed {
        transform: translateX(-100%);
    }
    .student-sidebar ul { list-style: none; padding: 10px 8px; margin: 0; }
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
    .student-sidebar li a { color: inherit; text-decoration: none; display: block; }
    .student-sidebar li.active {
        background: #eaf2ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
        font-weight: 700;
    }
    .student-sidebar li:hover { background: #f1f5f9; color: #0f172a; }
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
.enroll-submenu { list-style:none; padding:0 0 0 10px; margin:0 0 6px 0; }
.enroll-submenu li { font-size:.79rem; margin-bottom:3px; }
.enroll-submenu li.active { background:#dceaf3; color:#0e7490; border-color:#bfddeb; }
.student-topbar {
        display: flex; align-items: center; justify-content: space-between;
        background: #fff; border-bottom: 1px solid #e5e7eb;
        padding: 0.5rem 1.2rem; position: sticky; top: 0; z-index: 10;
    }
    .student-profile-pic {
        width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;
    }
    
    .content-area.container {
        max-width: 100% !important;
        overflow-x: hidden;
        padding: 1rem;
    }
    
    /* Ensure cards don't overflow */
    .card {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .card-body {
        overflow-x: hidden;
    }
    
    /* Make form responsive */
    .form-row {
        margin-left: -5px;
        margin-right: -5px;
    }
    
    .form-row > .form-group {
        padding-left: 5px;
        padding-right: 5px;
    }
    
    /* Responsive adjustments for smaller screens */
    @media (max-width: 992px) {
        .content-area.container {
            padding: 0.75rem;
        }
        
        .card-body {
            padding: 1rem !important;
        }
    }
    
    @media (max-width: 768px) {
        .content-area.container {
            padding: 0.5rem;
        }
        
        .form-group.d-flex {
            flex-direction: column !important;
            align-items: flex-start !important;
        }
        
        .form-group label {
            margin-bottom: 0.5rem !important;
        }
        
        /* Reduce table font size on mobile */
        .table-responsive table {
            font-size: 0.7rem !important;
        }
        
        .table-responsive th,
        .table-responsive td {
            padding: 0.4rem 0.2rem !important;
            font-size: 0.7rem !important;
        }
        
        .card-body {
            padding: 0.75rem !important;
        }
        
        h3 {
            font-size: 1.25rem !important;
        }
        
        h4 {
            font-size: 1rem !important;
        }
        
        h5 {
            font-size: 0.9rem !important;
        }
    }
    
    /* Ensure table container has proper scroll behavior */
    .table-responsive {
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1rem;
    }

    .enroll-shell {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        overflow: hidden;
    }
    .enroll-shell-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 0.9rem 1.1rem;
        border-bottom: 1px solid #e5e7eb;
        background: #fafafa;
    }
    .enroll-tab {
        border: 1px solid #dbe1e8;
        border-bottom-color: #fff;
        background: #fff;
        color: #1f7aa8;
        border-radius: 8px 8px 0 0;
        padding: 8px 18px;
        font-size: 0.85rem;
        font-weight: 700;
    }
    .enroll-reload {
        border: 1px dashed #f87171;
        background: #fff;
        color: #ef4444;
        border-radius: 8px;
        padding: 7px 14px;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
    }
    .enroll-title-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 0.95rem 1.1rem;
        border-bottom: 1px solid #e5e7eb;
        background: #f7f7f8;
    }
    .enroll-title {
        font-size: 1rem;
        font-weight: 700;
        color: #4b5563;
    }
    .enroll-prog {
        font-size: 0.9rem;
        color: #52525b;
        font-weight: 700;
    }
    .enroll-form-row {
        display: grid;
        grid-template-columns: repeat(4, minmax(170px, 1fr));
        gap: 14px;
        padding: 1rem 1.1rem;
        border-bottom: 1px solid #e5e7eb;
        background: #fbfbfb;
    }
    .enroll-field label {
        display: block;
        font-size: 0.8rem;
        color: #4b5563;
        margin-bottom: 0.35rem;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .enroll-field .req { color: #dc2626; }
    .enroll-field select {
        width: 100%;
        min-height: 40px;
        border: 1px solid #cbd5e1;
        border-radius: 0;
        font-size: 0.95rem;
        padding: 7px 12px;
        background: #fff;
        color: #1f2937;
    }
    .enroll-action-row {
        display: flex;
        justify-content: flex-end;
        padding: 0.7rem 1.1rem;
        background: #fff;
    }
    .enroll-now-btn {
        border: 1px solid #1f7aa8;
        background: #1f7aa8;
        color: #fff;
        border-radius: 10px;
        padding: 10px 22px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
    }
    .history-shell { border:1px solid #e5e7eb; border-radius:10px; background:#fff; overflow:hidden; }
    .history-shell-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:0.9rem 1.1rem; border-bottom:1px solid #e5e7eb; background:#fafafa; }
    .history-title { color:#1f7aa8; font-size:1.15rem; font-weight:700; margin:0; }
    .history-list { padding:1rem; display:grid; gap:10px; }
    .history-item { border:1px solid #dbe1e8; border-radius:10px; background:#fff; overflow:hidden; }
    .history-item summary { list-style:none; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:8px; padding:0.85rem 1rem; font-weight:700; color:#b42318; background:#fff; }
    .history-item summary::-webkit-details-marker { display:none; }
    .history-item[open] summary { border-bottom:1px solid #e5e7eb; background:#f8fafc; }
    .history-summary-label { display:flex; align-items:center; gap:8px; }
    .history-body { padding:0.9rem 1rem 1rem; background:#fff; }
    .history-meta { display:grid; grid-template-columns:repeat(3,minmax(180px,1fr)); gap:10px; margin-bottom:10px; }
    .history-meta-card { border:1px solid #e5e7eb; border-radius:8px; background:#f8fafc; padding:10px 12px; font-size:0.85rem; color:#334155; }
    .history-meta-card strong { color:#111827; }
    .history-actions { display:flex; justify-content:flex-end; margin-bottom:10px; }
    .history-print { border:1px solid #cbd5e1; background:#fff; color:#3559a0; border-radius:8px; font-size:.83rem; font-weight:700; padding:8px 12px; cursor:pointer; }
    .history-status { border-radius:999px; padding:3px 10px; font-size:.75rem; font-weight:700; text-transform:uppercase; }
    .history-status.approved { background:#dcfce7; color:#166534; }
    .history-status.pending { background:#ffedd5; color:#9a3412; }
    .history-status.rejected { background:#fee2e2; color:#991b1b; }

    /* Dark mode overrides for enrollment, current courses, and history blocks */
    html[data-theme='dark'] .enroll-shell {
        background: var(--app-surface-1) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .enroll-shell-head,
    html[data-theme='dark'] .enroll-title-row,
    html[data-theme='dark'] .enroll-form-row,
    html[data-theme='dark'] .enroll-action-row {
        background: var(--app-surface-2) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .enroll-tab {
        background: var(--app-surface-1) !important;
        border-color: var(--app-border) !important;
        border-bottom-color: var(--app-surface-2) !important;
        color: #93c5fd !important;
    }
    html[data-theme='dark'] .enroll-title,
    html[data-theme='dark'] .enroll-prog,
    html[data-theme='dark'] .enroll-field label {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .enroll-field .req {
        color: #f87171 !important;
    }
    html[data-theme='dark'] .enroll-field select {
        background: var(--app-surface-2) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
        color-scheme: dark;
    }
    html[data-theme='dark'] .enroll-field select option,
    html[data-theme='dark'] .enroll-field select optgroup {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .enroll-field select:disabled {
        background: var(--app-surface-2) !important;
        color: var(--app-muted) !important;
        opacity: 1;
    }
    html[data-theme='dark'] .enroll-reload {
        background: var(--app-surface-1) !important;
        color: #fca5a5 !important;
        border-color: #ef4444 !important;
    }
    html[data-theme='dark'] .enroll-reload:hover {
        background: #3a1820 !important;
        color: #fecaca !important;
    }
    html[data-theme='dark'] .enroll-now-btn {
        background: #1f7aa8 !important;
        border-color: #1f7aa8 !important;
        color: #fff !important;
    }

    html[data-theme='dark'] .current-courses-card {
        background: var(--app-surface-1) !important;
        border: 1px solid var(--app-border) !important;
        box-shadow: 0 2px 10px rgba(2, 6, 23, 0.45) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-body {
        background: var(--app-surface-1) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-title,
    html[data-theme='dark'] .current-courses-card .current-year-title,
    html[data-theme='dark'] .current-courses-card .current-year-title i {
        color: #e5e7eb !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-study-info {
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-study-info span {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .current-courses-card .core-courses-section {
        background: var(--app-surface-2) !important;
    }
    html[data-theme='dark'] .current-courses-card .core-courses-title {
        background: #374151 !important;
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table-wrap {
        background: var(--app-surface-1) !important;
        border: 1px solid var(--app-border) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table thead {
        background: #1f2937 !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table th {
        color: #f8fafc !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table td {
        color: #e5e7eb !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table tr {
        background: transparent !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table tbody tr:nth-child(odd) {
        background: rgba(148, 163, 184, 0.06) !important;
    }
    html[data-theme='dark'] .current-courses-card .current-courses-table tbody tr:hover {
        background: rgba(59, 130, 246, 0.10) !important;
    }

    html[data-theme='dark'] .history-shell,
    html[data-theme='dark'] .history-item,
    html[data-theme='dark'] .history-body {
        background: var(--app-surface-1) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .history-shell-head {
        background: var(--app-surface-2) !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .history-title {
        color: #93c5fd !important;
    }
    html[data-theme='dark'] .history-item summary {
        background: var(--app-surface-1) !important;
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] .history-item[open] summary {
        background: var(--app-surface-2) !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .history-summary-label {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .history-meta-card {
        background: var(--app-surface-2) !important;
        border-color: var(--app-border) !important;
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .history-meta-card strong {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .history-print {
        background: var(--app-surface-2) !important;
        border-color: var(--app-border) !important;
        color: #93c5fd !important;
    }
    html[data-theme='dark'] .history-status.approved {
        background: #14532d !important;
        color: #bbf7d0 !important;
    }
    html[data-theme='dark'] .history-status.pending {
        background: #7c2d12 !important;
        color: #fdba74 !important;
    }
    html[data-theme='dark'] .history-status.rejected {
        background: #7f1d1d !important;
        color: #fecaca !important;
    }
    
    /* Additional fixes for narrow screens */
    @media (max-width: 576px) {
        .table-responsive th,
        .table-responsive td {
            padding: 0.3rem 0.15rem !important;
            font-size: 0.65rem !important;
        }
    }
    @media (max-width: 992px) {
        .enroll-form-row {
            grid-template-columns: 1fr;
        }
        .enroll-title-row {
            flex-direction: column;
            align-items: flex-start;
        }
        .history-meta { grid-template-columns:1fr; }
    }
</style>

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
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li class="active"><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <ul class="enroll-submenu">
            <li class="<?php echo $regTab === 'enroll' ? 'active' : ''; ?>"><a href="course-registration.php?tab=enroll">ENROLL OR REGISTER</a></li>
            <li class="<?php echo $regTab === 'enrollment_history' ? 'active' : ''; ?>"><a href="course-registration.php?tab=enrollment_history">ENROLLMENT HISTORY</a></li>
            <li class="<?php echo $regTab === 'registration_history' ? 'active' : ''; ?>"><a href="course-registration.php?tab=registration_history">REGISTRATION HISTORY</a></li>
            <li class="<?php echo $regTab === 'migrated_history' ? 'active' : ''; ?>"><a href="course-registration.php?tab=migrated_history">MIGRATED HISTORY</a></li>
        </ul>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY PROGRAMME</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="provisional-results.php">MY PROVISIONAL RESULTS</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content" id="mainContent" style="max-width: 100vw; overflow-x: hidden;">
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:0.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic">
            <?php endif; ?>
            <span style="font-size:0.98rem; color:#222; font-weight:600; white-space:nowrap;">
                <?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?>
            </span>
            <a href="<?php echo e($linkMailbox); ?>" title="My Mailbox" style="position:relative; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px solid #dbe3ef; border-radius:50%; color:#1f7aa8; text-decoration:none; background:#fff;">
                <i class="far fa-envelope"></i>
                <?php if ($mailUnreadCount > 0): ?>
                    <span style="position:absolute; top:-6px; right:-6px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:#ef4444; color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center;"><?php echo $mailUnreadCount > 99 ? '99+' : $mailUnreadCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="profile-dropdown" style="position:relative;">
                <button id="profileDropBtn" style="background:none; border:none; font-size:0.98rem; cursor:pointer; padding:0 6px;">
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div id="profileDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.08); min-width:140px; z-index:100;">
                    <a href="dashboard.php" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Profile</a>
                    <a href="services.php?tab=apply" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Services</a>
                    <a href="logout.php" style="display:block; padding:8px 14px; color:#dc2626; text-decoration:none; font-weight:600; font-size:0.92rem;">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <?php
        $flashSuccess = $session->getFlash('success');
        $flashError = $session->getFlash('error');
        $flashInfo = $session->getFlash('info');
        $flashWarning = $session->getFlash('warning');
    ?>
    <div class="content-area container p-4">
        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="background:#d4edda;border-color:#c3e6cb;color:#155724;border-left:4px solid #28a745;">
                <i class="fas fa-check-circle"></i> <?php echo e($flashSuccess); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-left:4px solid #dc3545;">
                <i class="fas fa-exclamation-circle"></i> <?php echo e($flashError); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($flashWarning)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert" style="border-left:4px solid #ffc107;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo e($flashWarning); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($flashInfo)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert" style="border-left:4px solid #17a2b8;">
                <i class="fas fa-info-circle"></i> <?php echo e($flashInfo); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($repeatEnforcedNotice)): ?>
            <div class="alert alert-warning" role="alert" style="border-left:4px solid #ffc107;">
                <i class="fas fa-redo"></i> <?php echo e($repeatEnforcedNotice); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($retakeReminderNotice)): ?>
            <div class="alert alert-info" role="alert" style="border-left:4px solid #17a2b8;">
                <i class="fas fa-book-reader"></i> <?php echo e($retakeReminderNotice); ?>
            </div>
        <?php endif; ?>
        <?php if (!$isEnrollmentWindowOpen): ?>
            <div class="alert alert-warning" role="alert" style="border-left:4px solid #ffc107;">
                <i class="fas fa-lock"></i>
                Enrollment window is currently closed for student self-service.
                <?php if (!empty($currentSemesterWindow['registration_start_date']) && !empty($currentSemesterWindow['registration_end_date'])): ?>
                    Allowed period: <strong><?php echo e($currentSemesterWindow['registration_start_date']); ?></strong> to <strong><?php echo e($currentSemesterWindow['registration_end_date']); ?></strong>.
                <?php else: ?>
                    Registration period is not configured for this semester.
                <?php endif; ?>
                Please contact admin for manual enrollment support.
            </div>
        <?php endif; ?>

        <?php if ($regTab === 'enrollment_history'): ?>
            <div class="history-shell mb-3">
                <div class="history-shell-head">
                    <h4 class="history-title">MY ENROLLMENT HISTORY (<?php echo count($enrollmentHistory); ?>)</h4>
                    <button type="button" class="enroll-reload" onclick="window.location.href='course-registration.php?tab=enrollment_history'">
                        <i class="fas fa-sync-alt mr-1"></i> RELOAD
                    </button>
                </div>
                <div class="history-list">
                    <?php if (empty($enrollmentHistory)): ?>
                        <div class="alert alert-info mb-0">No enrollment history found yet.</div>
                    <?php else: ?>
                        <?php foreach ($enrollmentHistory as $idx => $h): ?>
                            <?php
                                $hStatus = strtolower((string)($h['status'] ?? 'pending'));
                                if (!in_array($hStatus, ['approved', 'pending', 'rejected'], true)) {
                                    $hStatus = 'pending';
                                }
                                $hYear = (int)($h['year_of_study'] ?? 1);
                                $hSemNum = (int)($h['semester_number'] ?? 1);
                                $hSemLabel = $hSemNum === 2 ? 'SEMESTER II' : 'SEMESTER I';
                                $hAy = (string)($h['year_name'] ?? '-');
                            ?>
                            <details class="history-item" <?php echo $idx === 0 ? 'open' : ''; ?>>
                                <summary>
                                    <span class="history-summary-label">
                                        <i class="fas fa-user-graduate"></i>
                                        YEAR <?php echo $hYear; ?>, <?php echo $hSemLabel; ?> - <?php echo e($hAy); ?>
                                    </span>
                                    <span class="history-status <?php echo e($hStatus); ?>"><?php echo e(strtoupper($hStatus)); ?></span>
                                </summary>
                                <div class="history-body">
                                    <div class="history-actions">
                                        <button type="button" class="history-print" onclick="window.print();">
                                            <i class="fas fa-print mr-1"></i> PRINT PROOF OF ENROLLMENT
                                        </button>
                                    </div>
                                    <div class="history-meta">
                                        <div class="history-meta-card">
                                            <div><strong>ACADEMIC YEAR:</strong> <?php echo e($hAy); ?></div>
                                            <div><strong>SEMESTER:</strong> <?php echo e($hSemLabel); ?></div>
                                            <div><strong>STUDY YEAR:</strong> YEAR <?php echo $hYear; ?></div>
                                        </div>
                                        <div class="history-meta-card">
                                            <div><strong>ENROLLED AS:</strong> <?php echo e(ucfirst($selectedEnrollingAs)); ?></div>
                                            <div><strong>ENROLLED BY:</strong> SELF</div>
                                            <div><strong>STATUS:</strong> <?php echo e(strtoupper($hStatus)); ?></div>
                                        </div>
                                        <div class="history-meta-card">
                                            <div><strong>ENROLLMENT TOKEN:</strong> ENR<?php echo str_pad((string)($h['id'] ?? 0), 8, '0', STR_PAD_LEFT); ?></div>
                                            <div><strong>ENROLLED ON:</strong> <?php echo !empty($h['request_date']) ? e(date('D, M jS Y, g:i:s a', strtotime($h['request_date']))) : '-'; ?></div>
                                            <div><strong>UPDATED ON:</strong> <?php echo !empty($h['updated_at']) ? e(date('D, M jS Y, g:i:s a', strtotime($h['updated_at']))) : '-'; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($regTab === 'registration_history' || $regTab === 'migrated_history'): ?>
            <div class="history-shell mb-3">
                <div class="history-shell-head">
                    <h4 class="history-title"><?php echo $regTab === 'registration_history' ? 'REGISTRATION HISTORY' : 'MIGRATED HISTORY'; ?></h4>
                    <button type="button" class="enroll-reload" onclick="window.location.href='course-registration.php?tab=<?php echo e($regTab); ?>'">
                        <i class="fas fa-sync-alt mr-1"></i> RELOAD
                    </button>
                </div>
                <div class="history-list">
                    <div class="alert alert-info mb-0">
                        <?php echo $regTab === 'registration_history' ? 'Registration history will appear here once available.' : 'No migrated enrollment history found.'; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <div class="card mb-3">
            <div class="card-body" style="padding: 1rem;">
                <form id="courseFilterForm" method="POST" class="mb-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="enroll_now">
                    <input type="hidden" name="tab" value="enroll">
                    <div class="enroll-shell">
                        <div class="enroll-shell-head">
                            <div class="enroll-tab"><i class="fas fa-edit mr-2"></i>ENROLLMENT</div>
                            <button type="button" class="enroll-reload" onclick="window.location.href='course-registration.php'">
                                <i class="fas fa-sync-alt mr-1"></i> RELOAD
                            </button>
                        </div>
                        <div class="enroll-title-row">
                            <div class="enroll-title">
                                ENROLL FOR SEMESTER <?php echo $selectedSemesterNumber == 1 ? 'I' : 'II'; ?>, <?php echo e($selectedAcademicYearName ?: '-'); ?>
                            </div>
                            <div class="enroll-prog">PROG: <?php echo e(strtoupper($registeredProgramName)); ?></div>
                        </div>
                        <div class="enroll-form-row">
                            <div class="enroll-field">
                                <label>ACADEMIC YEAR <span class="req">*</span></label>
                                <select id="academic_year_id_input" name="academic_year_id" <?php echo $isRepeatLocked ? 'disabled' : ''; ?>>
                                    <?php foreach ($academicYears as $ay): ?>
                                        <option value="<?php echo (int)$ay['id']; ?>" <?php echo ((int)$selectedAcademicYearId === (int)$ay['id']) ? 'selected' : ''; ?>>
                                            <?php echo e((string)$ay['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($isRepeatLocked): ?>
                                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedAcademicYearId; ?>">
                                <?php endif; ?>
                            </div>
                            <div class="enroll-field">
                                <label>YEAR OF STUDY <span class="req">*</span></label>
                                <select id="year_of_study_select" name="year_of_study" <?php echo $isRepeatLocked ? 'disabled' : ''; ?>>
                                    <?php for ($y = 1; $y <= 4; $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $yearOfStudy == $y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <?php if ($isRepeatLocked): ?>
                                    <input type="hidden" name="year_of_study" value="<?php echo (int)$yearOfStudy; ?>">
                                <?php endif; ?>
                            </div>
                            <div class="enroll-field">
                                <label>SEMESTER <span class="req">*</span></label>
                                <select id="semester_number_select" name="semester_number" <?php echo $isRepeatLocked ? 'disabled' : ''; ?>>
                                    <option value="1" <?php echo $selectedSemesterNumber == 1 ? 'selected' : ''; ?>>Semester 1</option>
                                    <option value="2" <?php echo $selectedSemesterNumber == 2 ? 'selected' : ''; ?>>Semester 2</option>
                                </select>
                                <?php if ($isRepeatLocked): ?>
                                    <input type="hidden" name="semester_number" value="<?php echo (int)$selectedSemesterNumber; ?>">
                                <?php endif; ?>
                            </div>
                            <div class="enroll-field">
                                <label>ENROLLING AS? <span class="req">*</span></label>
                                <select id="enrolling_as_select" name="enrolling_as">
                                    <option value="normal" <?php echo $selectedEnrollingAs === 'normal' ? 'selected' : ''; ?>>Normal Student</option>
                                    <option value="private" <?php echo $selectedEnrollingAs === 'private' ? 'selected' : ''; ?>>Private Student</option>
                                    <option value="supplementary" <?php echo $selectedEnrollingAs === 'supplementary' ? 'selected' : ''; ?>>Supplementary</option>
                                </select>
                            </div>
                            <div class="enroll-field">
                                <label>HAVE RETAKES? <span class="req">*</span></label>
                                <select id="has_retakes_select" name="has_retakes">
                                    <option value="no" <?php echo $selectedHasRetakes === 'no' ? 'selected' : ''; ?>>No</option>
                                    <option value="yes" <?php echo $selectedHasRetakes === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="enroll-action-row">
                            <button type="submit" class="enroll-now-btn" <?php echo !$isEnrollmentWindowOpen ? 'disabled title="Enrollment window closed. Contact admin."' : ''; ?>>
                                <?php echo $isEnrollmentWindowOpen ? 'ENROLL NOW' : 'ENROLLMENT CLOSED'; ?>
                            </button>
                        </div>
                    </div>
                </form>

                <?php if (!$semesterApproval && empty($approvedCourses)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Not enrolled yet.</strong> Click <strong>ENROLL NOW</strong> above to enroll and load your semester courses.
                        <?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
                            <div class="mt-2">
                                <p>Debugging Information:</p>
                                <ul style="margin-bottom: 0;">
                                    <li>Student ID: <?php echo $studentProfile['id']; ?></li>
                                    <li>Semester ID: <?php echo $semesterId; ?> <?php if ($semesterId <= 0) echo '<strong>(INVALID - Must be greater than 0)</strong>'; ?></li>
                                    <li>Year of Study: <?php echo $yearOfStudy; ?></li>
                                    <li>Semester Number: <?php echo $selectedSemesterNumber; ?></li>
                                    <li>Academic Year ID: <?php echo $selectedAcademicYearId; ?></li>
                                </ul>
                            </div>
                            <?php if ($semesterId <= 0): ?>
                                <div class="mt-2">
                                    <strong>Issue:</strong> Invalid semester ID. The semester you selected may not exist in the database.
                                    <br>Please ensure:
                                    <ol>
                                        <li>The academic year "<?php echo $selectedAcademicYearId; ?>" exists in the academic_years table</li>
                                        <li>A semester with number "<?php echo $selectedSemesterNumber; ?>" exists for that academic year</li>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="mt-2">
                            <small>Please contact administration with this information.</small>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Semester registration is approved - show courses -->
                    <div class="card mb-4 current-courses-card" style="border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                        <div class="card-body current-courses-body" style="padding: 1.5rem;">
                            <h3 class="current-courses-title" style="margin-bottom: 1rem; font-weight: 700; color: #1a1a1a; font-size: 1.5rem;">Current Courses</h3>
                            
                            <!-- Academic Year Label -->
                            <div class="current-year-block" style="margin-bottom: 1rem;">
                                <h4 class="current-year-title" style="font-weight: 700; color: #2d3748; font-size: 1.1rem; border-bottom: 3px solid #e2e8f0; padding-bottom: 0.5rem;">
                                    <i class="fas fa-graduation-cap" style="color: #4a5568; margin-right: 0.5rem;"></i>
                                    <?php echo e($selectedAcademicYearName ?: ($currentSemester['academic_year'] ?? '-')); ?>
                                </h4>
                            </div>
                            
                            <!-- Study Info -->
                            <div class="current-study-info" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.5rem; padding: 0.75rem 0; border-bottom: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center;">
                                    <span style="font-weight: 400; color: #6b7280; margin-right: 0.5rem;">Study year:</span>
                                    <span style="font-weight: 600; color: #1f2937;"><?php echo $yearOfStudy; ?></span>
                                </div>
                                <div style="display: flex; align-items: center;">
                                    <span style="font-weight: 400; color: #6b7280; margin-right: 0.5rem;">Semester:</span>
                                    <span style="font-weight: 600; color: #1f2937;"><?php echo $selectedSemesterNumber == 1 ? 'I' : ($selectedSemesterNumber == 2 ? 'II' : $selectedSemesterNumber); ?></span>
                                </div>
                            </div>

                            <!-- Core courses section -->
                            <div class="core-courses-section" style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                                <h5 class="core-courses-title" style="margin-bottom: 1rem; padding: 0.5rem; background: #6b7280; color: white; border-radius: 4px; font-weight: 600; font-size: 1rem;">Core courses</h5>
                                
                                <div class="table-responsive current-courses-table-wrap" style="background: white; border-radius: 4px; overflow-x: hidden; overflow-y: visible; max-width: 100%;">
                                    <table class="table table-hover table-sm current-courses-table" style="margin-bottom: 0; width: 100%; table-layout: fixed; font-size: 0.8rem;">
                                        <thead style="background: #f3f4f6;">
                                            <tr>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 40px; text-align: center; font-size: 0.75rem;">S/N</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 80px; white-space: nowrap; font-size: 0.75rem;">Code</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; min-width: 160px; font-size: 0.75rem;">Course name</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 70px; white-space: nowrap; font-size: 0.75rem;">Sem.</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">Yr</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">CU</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">LH</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">TH</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">PH</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">CH</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($approvedCourses)): ?>
                                                <?php $sn = 1; foreach ($approvedCourses as $ac): ?>
                                                    <?php
                                                    // Fetch full course details to get hours
                                                    $fullCourseStmt = $conn->prepare("SELECT * FROM courses WHERE id = :id");
                                                    $fullCourseStmt->execute(['id' => $ac['course_id']]);
                                                    $fullCourse = $fullCourseStmt->fetch();
                                                    
                                                    $lh = $fullCourse['lecture_hours'] ?? 0;
                                                    $th = $fullCourse['tutorial_hours'] ?? 0;
                                                    $ph = $fullCourse['practical_hours'] ?? 0;
                                                    $ch = $lh + $th + $ph;
                                                    $semester_display = $fullCourse['semester_offered'] == 1 ? 'Sem I' : ($fullCourse['semester_offered'] == 2 ? 'Sem II' : 'Sem ' . $fullCourse['semester_offered']);
                                                    ?>
                                                    <tr style="border-bottom: 1px solid #f3f4f6;">
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $sn++; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; font-weight: 500; white-space: nowrap; font-size: 0.8rem;"><?php echo e($ac['course_code']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #374151; word-wrap: break-word; word-break: break-word; line-height: 1.3; font-size: 0.8rem;"><?php echo e($ac['course_name']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; white-space: nowrap; font-size: 0.8rem;"><?php echo $semester_display; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo e($ac['level_year']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo e($ac['credit_hours']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $lh; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $th; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $ph; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $ch; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" style="padding: 2rem; text-align: center; color: #6b7280;">
                                                        <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                                                        No courses are currently available for the resolved semester context. Try Reload; the system auto-falls back to previous eligible semester when applicable.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>


                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('menuBtn').addEventListener('click', function() {
    var sidebar = document.querySelector('.student-sidebar');
    var main = document.querySelector('.main-content');
    sidebar.classList.toggle('sidebar-collapsed');
    if (main) main.classList.toggle('full-width');
});

document.getElementById('profileDropBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    var menu = document.getElementById('profileDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function() {
    var menu = document.getElementById('profileDropMenu');
    if (menu) menu.style.display = 'none';
});

var semesterSelect = document.getElementById('semester_number_select');
var academicYearSelect = document.getElementById('academic_year_id_input');
function applyEnrollmentFilters() {
    if (!semesterSelect) return;
    if (semesterSelect.disabled) return;
        var params = new URLSearchParams(window.location.search);
        var academicYearInput = academicYearSelect;
        var yearSelect = document.getElementById('year_of_study_select');
        var enrollingAsSelect = document.getElementById('enrolling_as_select');
        var retakesSelect = document.getElementById('has_retakes_select');

        params.set('tab', 'enroll');
        params.delete('semester_id');
        params.set('semester_number', semesterSelect.value || '1');

        if (academicYearInput && academicYearInput.value) {
            params.set('academic_year_id', academicYearInput.value);
        }
        if (yearSelect && yearSelect.value) {
            params.set('year_of_study', yearSelect.value);
        }
        if (enrollingAsSelect && enrollingAsSelect.value) {
            params.set('enrolling_as', enrollingAsSelect.value);
        }
        if (retakesSelect && retakesSelect.value) {
            params.set('has_retakes', retakesSelect.value);
        }

        window.location.href = 'course-registration.php?' + params.toString();
}
if (semesterSelect && !semesterSelect.disabled) {
    semesterSelect.addEventListener('change', applyEnrollmentFilters);
}
if (academicYearSelect && !academicYearSelect.disabled) {
    academicYearSelect.addEventListener('change', applyEnrollmentFilters);
}
</script>

<?php include '../../includes/footer.php'; ?>

