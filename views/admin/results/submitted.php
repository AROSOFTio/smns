<?php
/**
 * Admin - Results (Submitted / Exam Entry & Approval)
 *
 * Admins / Examiners can:
 *  - See results submitted by lecturers (coursework 40%).
 *  - Enter exam marks (60%) per student.
 *  - Approve results, which updates total marks and grade via DB triggers.
 *
 * Lecturers never see an interface to edit exam marks or final totals.
 */
require_once '../../../config.php';
require_once '../../../includes/functions.php';

$session = new Session('admin');
$auth    = new Auth('admin');

// Verify admin access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$adminProfile = $currentUser['profile'] ?? [];

$db   = new Database();
$conn = $db->getConnection();

// ---------------------------------------------------------------------
// Filters: Academic Year, Semester, Course
// ---------------------------------------------------------------------

$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_REQUEST['academic_year_id']) ? (int) $_REQUEST['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_REQUEST['semester_number']) ? (int) $_REQUEST['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);
$requestedCourseId      = isset($_REQUEST['course_id']) ? (int) $_REQUEST['course_id'] : 0;
$requestedLevelYear     = isset($_REQUEST['level_year']) ? (int) $_REQUEST['level_year'] : 0;
$requestedProgramId     = isset($_REQUEST['program_id']) ? (int) $_REQUEST['program_id'] : 0;
$programs               = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Map AY + semester number to semester row
$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

$selectedProgramId = $requestedProgramId;
if ($selectedProgramId <= 0 && $requestedCourseId > 0) {
    try {
        $courseProgramStmt = $conn->prepare("SELECT program_id FROM courses WHERE id = :id LIMIT 1");
        $courseProgramStmt->execute(['id' => $requestedCourseId]);
        $selectedProgramId = (int)($courseProgramStmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        $selectedProgramId = 0;
    }
}
if ($selectedProgramId <= 0) {
    $selectedProgramId = (int)($programs[0]['id'] ?? 0);
}
if (!empty($programs)) {
    $programIds = array_map('intval', array_column($programs, 'id'));
    if (!in_array((int)$selectedProgramId, $programIds, true)) {
        $selectedProgramId = (int)$programs[0]['id'];
    }
}

$availableLevelYears = [];
try {
    $levelStmt = $conn->prepare("SELECT DISTINCT c.level_year
                                 FROM courses c
                                 WHERE c.program_id = :program_id
                                   AND (
                                        (c.status = 'active' AND (c.semester_offered = :semester_number OR c.semester_offered = 3))
                                        OR EXISTS (
                                            SELECT 1
                                            FROM course_registrations cr
                                            WHERE cr.course_id = c.id
                                              AND cr.semester_id = :semester_id_cr
                                              AND cr.status IN ('approved','registered','pending','submitted')
                                        )
                                        OR EXISTS (
                                            SELECT 1
                                            FROM course_registrations crp
                                            INNER JOIN students sp ON sp.id = crp.student_id
                                            WHERE crp.course_id = c.id
                                              AND crp.semester_id = :semester_id_crp
                                              AND crp.status IN ('approved','registered','pending','submitted')
                                              AND sp.program_id = :program_id_crp
                                        )
                                        OR EXISTS (
                                            SELECT 1
                                            FROM results r
                                            WHERE r.course_id = c.id
                                              AND r.semester_id = :semester_id_r
                                              AND r.status IN ('submitted','approved','published')
                                        )
                                        OR EXISTS (
                                            SELECT 1
                                            FROM results rp
                                            INNER JOIN students sp2 ON sp2.id = rp.student_id
                                            WHERE rp.course_id = c.id
                                              AND rp.semester_id = :semester_id_rp
                                              AND rp.status IN ('submitted','approved','published')
                                              AND sp2.program_id = :program_id_rp
                                        )
                                   )
                                 ORDER BY c.level_year ASC");
    $levelStmt->execute([
        'semester_number' => (int)$selectedSemesterNumber,
        'program_id' => (int)$selectedProgramId,
        'semester_id_cr' => (int)$semesterId,
        'semester_id_crp' => (int)$semesterId,
        'program_id_crp' => (int)$selectedProgramId,
        'semester_id_r' => (int)$semesterId,
        'semester_id_rp' => (int)$semesterId,
        'program_id_rp' => (int)$selectedProgramId,
    ]);
    $availableLevelYears = array_map('intval', $levelStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
} catch (Exception $e) {
    $availableLevelYears = [];
}

$selectedLevelYear = $requestedLevelYear;
if ($selectedLevelYear <= 0 && $requestedCourseId > 0) {
    try {
        $courseYearStmt = $conn->prepare("SELECT level_year FROM courses WHERE id = :id LIMIT 1");
        $courseYearStmt->execute(['id' => $requestedCourseId]);
        $selectedLevelYear = (int)($courseYearStmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        $selectedLevelYear = 0;
    }
}
if ($selectedLevelYear <= 0) {
    $selectedLevelYear = !empty($availableLevelYears) ? (int)$availableLevelYears[0] : 1;
}
if (!empty($availableLevelYears) && !in_array((int)$selectedLevelYear, $availableLevelYears, true)) {
    $selectedLevelYear = (int)$availableLevelYears[0];
}
if (empty($availableLevelYears)) {
    $availableLevelYears = [1, 2, 3, 4];
}

// Courses offered in the selected semester + year with progress counters
$coursesWithResults = [];
if ($semesterId) {
    $sql = "SELECT
                c.id,
                c.course_code,
                c.course_name,
                SUM(CASE WHEN r.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) AS published_count,
                SUM(CASE WHEN r.final_exam_marks IS NOT NULL THEN 1 ELSE 0 END) AS with_exam_count
            FROM courses c
            LEFT JOIN results r
                   ON r.course_id = c.id
                  AND r.semester_id = :semester_id
                  AND r.status IN ('submitted', 'approved', 'published')
            WHERE c.level_year = :level_year
              AND (
                   (
                       c.program_id = :program_id
                       AND (
                           (c.status = 'active' AND (c.semester_offered = :semester_number OR c.semester_offered = 3))
                           OR EXISTS (
                               SELECT 1
                               FROM course_registrations cr
                               WHERE cr.course_id = c.id
                                 AND cr.semester_id = :semester_id_cr
                                 AND cr.status IN ('approved','registered','pending','submitted')
                           )
                           OR EXISTS (
                               SELECT 1
                               FROM results r2
                               WHERE r2.course_id = c.id
                                 AND r2.semester_id = :semester_id_r
                                 AND r2.status IN ('submitted','approved','published')
                           )
                       )
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM course_registrations crp
                       INNER JOIN students sp ON sp.id = crp.student_id
                       WHERE crp.course_id = c.id
                         AND crp.semester_id = :semester_id_crp
                         AND crp.status IN ('approved','registered','pending','submitted')
                         AND sp.program_id = :program_id_crp
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM results rp
                       INNER JOIN students sp2 ON sp2.id = rp.student_id
                       WHERE rp.course_id = c.id
                         AND rp.semester_id = :semester_id_rp
                         AND rp.status IN ('submitted','approved','published')
                         AND sp2.program_id = :program_id_rp
                   )
              )
            GROUP BY c.id, c.course_code, c.course_name
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => (int)$semesterId,
        'semester_id_cr' => (int)$semesterId,
        'semester_id_r' => (int)$semesterId,
        'semester_id_crp' => (int)$semesterId,
        'semester_id_rp' => (int)$semesterId,
        'semester_number' => (int)$selectedSemesterNumber,
        'level_year' => (int)$selectedLevelYear,
        'program_id' => (int)$selectedProgramId,
        'program_id_crp' => (int)$selectedProgramId,
        'program_id_rp' => (int)$selectedProgramId,
    ]);
    $coursesWithResults = $stmt->fetchAll();

    // Fallback: if nothing returned, pull courses via registrations for this program/year/semester.
    if (empty($coursesWithResults)) {
        $fallbackSql = "SELECT
                c.id,
                c.course_code,
                c.course_name,
                SUM(CASE WHEN r.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) AS published_count,
                SUM(CASE WHEN r.final_exam_marks IS NOT NULL THEN 1 ELSE 0 END) AS with_exam_count
            FROM course_registrations cr
            INNER JOIN students s ON s.id = cr.student_id
            INNER JOIN courses c ON c.id = cr.course_id
            LEFT JOIN results r
                   ON r.course_id = c.id
                  AND r.semester_id = :semester_id
                  AND r.status IN ('submitted','approved','published')
            WHERE cr.semester_id = :semester_id_cr
              AND cr.status IN ('approved','registered','pending','submitted')
              AND s.program_id = :program_id
              AND c.level_year = :level_year
            GROUP BY c.id, c.course_code, c.course_name
            ORDER BY c.course_code";
        $fallbackStmt = $conn->prepare($fallbackSql);
        $fallbackStmt->execute([
            'semester_id' => (int)$semesterId,
            'semester_id_cr' => (int)$semesterId,
            'program_id' => (int)$selectedProgramId,
            'level_year' => (int)$selectedLevelYear,
        ]);
        $coursesWithResults = $fallbackStmt->fetchAll() ?: [];
    }
}

$selectedCourseId = $requestedCourseId;

// If a course was explicitly requested but not in the list (e.g., program mismatch),
// try to load it if it has registrations/results for the selected program/year/semester.
if ($selectedCourseId > 0) {
    $existingIds = array_map('intval', array_column($coursesWithResults, 'id'));
    if (!in_array((int)$selectedCourseId, $existingIds, true)) {
        $fallbackSql = "SELECT
                c.id,
                c.course_code,
                c.course_name,
                SUM(CASE WHEN r.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) AS published_count,
                SUM(CASE WHEN r.final_exam_marks IS NOT NULL THEN 1 ELSE 0 END) AS with_exam_count
            FROM courses c
            LEFT JOIN results r
                   ON r.course_id = c.id
                  AND r.semester_id = :semester_id
                  AND r.status IN ('submitted', 'approved', 'published')
            WHERE c.id = :course_id
              AND c.level_year = :level_year
              AND (
                   c.program_id = :program_id
                   OR EXISTS (
                       SELECT 1
                       FROM course_registrations crp
                       INNER JOIN students sp ON sp.id = crp.student_id
                       WHERE crp.course_id = c.id
                         AND crp.semester_id = :semester_id_crp
                         AND crp.status IN ('approved','registered','pending','submitted')
                         AND sp.program_id = :program_id_crp
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM results rp
                       INNER JOIN students sp2 ON sp2.id = rp.student_id
                       WHERE rp.course_id = c.id
                         AND rp.semester_id = :semester_id_rp
                         AND rp.status IN ('submitted','approved','published')
                         AND sp2.program_id = :program_id_rp
                   )
              )
            GROUP BY c.id, c.course_code, c.course_name
            LIMIT 1";
        $fallbackStmt = $conn->prepare($fallbackSql);
        $fallbackStmt->execute([
            'semester_id' => (int)$semesterId,
            'course_id' => (int)$selectedCourseId,
            'level_year' => (int)$selectedLevelYear,
            'program_id' => (int)$selectedProgramId,
            'semester_id_crp' => (int)$semesterId,
            'semester_id_rp' => (int)$semesterId,
            'program_id_crp' => (int)$selectedProgramId,
            'program_id_rp' => (int)$selectedProgramId,
        ]);
        $fallbackCourse = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
        if ($fallbackCourse) {
            $coursesWithResults[] = $fallbackCourse;
        }
    }
}

// Ensure selected course is valid
if ($selectedCourseId && !empty($coursesWithResults)) {
    $validIds = array_column($coursesWithResults, 'id');
    if (!in_array($selectedCourseId, $validIds, true)) {
        $selectedCourseId = 0;
    }
}
$hasResultRowsInSemester = false;
foreach ($coursesWithResults as $courseSummary) {
    $rowsForCourse = (int)($courseSummary['submitted_count'] ?? 0)
        + (int)($courseSummary['approved_count'] ?? 0)
        + (int)($courseSummary['published_count'] ?? 0);
    if ($rowsForCourse > 0) {
        $hasResultRowsInSemester = true;
        break;
    }
}

$autoSelectedCourse = false;
if ($selectedCourseId <= 0 && !empty($coursesWithResults)) {
    foreach ($coursesWithResults as $courseSummary) {
        $rowsForCourse = (int)($courseSummary['submitted_count'] ?? 0)
            + (int)($courseSummary['approved_count'] ?? 0)
            + (int)($courseSummary['published_count'] ?? 0);
        if ($rowsForCourse > 0) {
            $selectedCourseId = (int)($courseSummary['id'] ?? 0);
            break;
        }
    }
    if ($selectedCourseId <= 0) {
        $selectedCourseId = (int)($coursesWithResults[0]['id'] ?? 0);
    }
    $autoSelectedCourse = $selectedCourseId > 0;
}

// ---------------------------------------------------------------------
// Ensure results_audit table exists (auto-create)
// ---------------------------------------------------------------------
$conn->exec("CREATE TABLE IF NOT EXISTS `results_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `result_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `changed_by_user_id` int(11) NOT NULL,
  `change_type` enum('publish','edit') NOT NULL,
  `old_marks` longtext DEFAULT NULL,
  `new_marks` longtext NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `result_id` (`result_id`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$redirectSubmitted = function($ayId, $semNo, $courseId = 0, $levelYear = 0, $programId = 0) {
    $url = 'submitted.php?academic_year_id=' . (int)$ayId . '&semester_number=' . (int)$semNo;
    if ((int)$programId > 0) {
        $url .= '&program_id=' . (int)$programId;
    }
    if ((int)$levelYear > 0) {
        $url .= '&level_year=' . (int)$levelYear;
    }
    if ((int)$courseId > 0) {
        $url .= '&course_id=' . (int)$courseId;
    }
    return $url;
};

// ---------------------------------------------------------------------
// Handle POST: Bulk Approve Submitted Results
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['bulk_approve_course']) || isset($_POST['bulk_approve_semester'])) && $semesterId) {
    $scopeCourseId = isset($_POST['bulk_approve_course']) ? (int)($_POST['course_id'] ?? 0) : 0;
    $adminId = $adminProfile['id'] ?? null;
    $adminUserId = (int)($currentUser['id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    if (!$adminId) {
        $session->setFlash('error', 'Admin profile not found. Cannot bulk approve results.');
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $scopeCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    }

    if (isset($_POST['bulk_approve_course']) && $scopeCourseId <= 0) {
        $session->setFlash('error', 'Please select a course before bulk approval.');
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, 0, $selectedLevelYear, $selectedProgramId));
        exit;
    }

    try {
        $conn->beginTransaction();

        $whereSql = "WHERE semester_id = :semester_id AND status = 'submitted' AND final_exam_marks IS NOT NULL";
        $params = ['semester_id' => (int)$semesterId];
        if ($scopeCourseId > 0) {
            $whereSql .= " AND course_id = :course_id";
            $params['course_id'] = $scopeCourseId;
        }

        $fetchSql = "SELECT id, student_id, course_id, assignment_marks, final_exam_marks, total_marks, grade, status FROM results {$whereSql}";
        $fetchStmt = $conn->prepare($fetchSql);
        $fetchStmt->execute($params);
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $conn->rollBack();
            $session->setFlash('info', 'No submitted results with exam marks were found for bulk approval.');
            header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $scopeCourseId, $selectedLevelYear, $selectedProgramId));
            exit;
        }

        $updStmt = $conn->prepare("UPDATE results SET status = 'approved', approved_by = :admin_id, approved_date = :approved_date, updated_at = :updated_at WHERE id = :id AND status = 'submitted'");
        $newStmt = $conn->prepare("SELECT assignment_marks, final_exam_marks, total_marks, grade, status FROM results WHERE id = :id");
        $auditStmt = $conn->prepare("INSERT INTO results_audit (result_id, student_id, course_id, changed_by_user_id, change_type, old_marks, new_marks, reason) VALUES (:result_id, :student_id, :course_id, :user_id, 'edit', :old_marks, :new_marks, :reason)");

        $approvedCount = 0;
        foreach ($rows as $row) {
            $updStmt->execute([
                'admin_id' => $adminId,
                'approved_date' => $now,
                'updated_at' => $now,
                'id' => (int)$row['id']
            ]);
            if ($updStmt->rowCount() < 1) {
                continue;
            }

            $newStmt->execute(['id' => (int)$row['id']]);
            $newRow = $newStmt->fetch(PDO::FETCH_ASSOC);
            $auditStmt->execute([
                'result_id' => (int)$row['id'],
                'student_id' => (int)$row['student_id'],
                'course_id' => (int)$row['course_id'],
                'user_id' => $adminUserId,
                'old_marks' => json_encode($row),
                'new_marks' => json_encode($newRow ?: $row),
                'reason' => isset($_POST['bulk_approve_course'])
                    ? 'Bulk approved submitted results for selected course'
                    : 'Bulk approved submitted results for current semester'
            ]);
            $approvedCount++;
        }

        $conn->commit();
        try {
            $logger = new Logger();
            $logger->log(
                (int)($currentUser['id'] ?? 0),
                'bulk_approve_results',
                'results',
                'Bulk approved ' . (int)$approvedCount . ' submitted result row(s).',
                [
                    'part' => 'results_approval',
                    'where' => '/views/admin/results/submitted.php',
                    'target' => $scopeCourseId > 0 ? ('course#' . (int)$scopeCourseId) : ('semester#' . (int)$semesterId)
                ]
            );
        } catch (Exception $logEx) {
            // Non-fatal.
        }
        $session->setFlash('success', 'Bulk approval completed. ' . $approvedCount . ' result(s) moved to Approved status.');
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $scopeCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $session->setFlash('error', 'Bulk approval failed: ' . $e->getMessage());
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $scopeCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    }
}

// ---------------------------------------------------------------------
// Handle POST: Enter Exam Marks (60%) - Provisional Save
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam']) && $semesterId) {
    $selectedCourseId = (int) ($_POST['course_id'] ?? 0);

    if ($selectedCourseId <= 0) {
        $session->setFlash('error', 'Invalid course selection.');
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, 0, $selectedLevelYear, $selectedProgramId));
        exit;
    }

    $examData = $_POST['exam']; // [result_id => exam_mark]
    $editReason = trim($_POST['edit_reason'] ?? '');
    $now      = date('Y-m-d H:i:s');
    $adminId  = $adminProfile['id'] ?? null;
    $adminUserId = $currentUser['id'] ?? 0;

    if (!$adminId) {
        $session->setFlash('error', 'Admin profile not found. Cannot save results.');
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $selectedCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    }
    if ($editReason === '') {
        $session->setFlash('error', 'Change reason is required for any exam-mark update.');
        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $selectedCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    }

    $conn->beginTransaction();

    try {
        $updatedCount = 0;
        $revertedPublishedCount = 0;
        foreach ($examData as $resultId => $examRaw) {
            $resultId = (int) $resultId;
            if ($resultId <= 0) {
                continue;
            }

            $examMark = trim($examRaw) === '' ? null : (float) $examRaw;

            if ($examMark !== null) {
                if ($examMark < 0 || $examMark > 60) {
                    throw new Exception('Exam marks must be between 0 and 60.');
                }
            }

            // If no exam entered, skip (do not modify row)
            if ($examMark === null) {
                continue;
            }

            // Fetch old marks before updating for audit log
            $oldStmt = $conn->prepare("SELECT student_id, course_id, assignment_marks, final_exam_marks, total_marks, grade, status, approved_by, approved_date, published_date FROM results WHERE id = :id");
            $oldStmt->execute(['id' => $resultId]);
            $oldResult = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldResult) {
                continue;
            }
            $examUnchanged = ((float)($oldResult['final_exam_marks'] ?? -1)) === (float)$examMark;
            $alreadySubmittedOnly = (string)($oldResult['status'] ?? '') === 'submitted'
                && empty($oldResult['approved_by'])
                && empty($oldResult['approved_date'])
                && empty($oldResult['published_date']);
            if ($examUnchanged && $alreadySubmittedOnly) {
                continue;
            }
            $wasPublished = ((string)($oldResult['status'] ?? '') === 'published');
            // Any exam-mark edit must return to submitted so it is re-audited
            // before being published on the student portal.
            $newStatus = 'submitted';

            // Update only exam mark; triggers handle total + grade.
            $updateSql = "UPDATE results
                          SET final_exam_marks = :exam,
                              approved_by      = NULL,
                              approved_date    = NULL,
                              published_date   = NULL,
                              status           = :status,
                              updated_at       = :updated_at
                          WHERE id = :id
                            AND semester_id = :semester_id
                            AND course_id   = :course_id";
            $u = $conn->prepare($updateSql);
            $u->execute([
                'exam'         => $examMark,
                'status'       => $newStatus,
                'updated_at'   => $now,
                'id'           => $resultId,
                'semester_id'  => $semesterId,
                'course_id'    => $selectedCourseId,
            ]);
            if ($u->rowCount() > 0) {
                $updatedCount++;
                if ($wasPublished) {
                    $revertedPublishedCount++;
                }
                // Fetch new marks after update
                $newStmt = $conn->prepare("SELECT assignment_marks, final_exam_marks, total_marks, grade, status FROM results WHERE id = :id");
                $newStmt->execute(['id' => $resultId]);
                $newResult = $newStmt->fetch(PDO::FETCH_ASSOC);

                // Log to audit table
                $auditStmt = $conn->prepare("INSERT INTO results_audit (result_id, student_id, course_id, changed_by_user_id, change_type, old_marks, new_marks, reason) VALUES (:result_id, :student_id, :course_id, :user_id, 'edit', :old_marks, :new_marks, :reason)");
                $auditStmt->execute([
                    'result_id'   => $resultId,
                    'student_id'  => $oldResult['student_id'],
                    'course_id'   => $selectedCourseId,
                    'user_id'     => $adminUserId,
                    'old_marks'   => json_encode($oldResult),
                    'new_marks'   => json_encode($newResult),
                    'reason'      => $editReason
                ]);
            }
        }

    $conn->commit();
    try {
        $logger = new Logger();
        $logger->log(
            (int)($currentUser['id'] ?? 0),
            'update_exam_marks',
            'results',
            'Updated exam marks for ' . (int)$updatedCount . ' result row(s); reverted published rows: ' . (int)$revertedPublishedCount . '.',
            [
                'part' => 'exam_marks',
                'where' => '/views/admin/results/submitted.php',
                'target' => 'course#' . (int)$selectedCourseId
            ]
        );
    } catch (Exception $logEx) {
        // Non-fatal.
    }
    $successMsg = 'Exam marks saved to Submitted (audit pending). Updated rows: ' . (int)$updatedCount . '. Review and bulk approve before publishing from Provisional Results.';
    if ($revertedPublishedCount > 0) {
        $successMsg .= ' Any edited published rows are automatically removed from student portal until re-approved and re-published.';
    }
    $session->setFlash('success', $successMsg);

        header('Location: ' . $redirectSubmitted($selectedAcademicYearId, $selectedSemesterNumber, $selectedCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $session->setFlash('error', 'Error saving exam marks: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Fetch results (students) for display
// ---------------------------------------------------------------------

$results = [];
if ($semesterId && $selectedCourseId) {
    $sql = "SELECT r.id AS result_id,
                   s.id AS student_id,
                   s.first_name,
                   s.last_name,
                   s.student_id AS reg_no,
                   s.level_year,
                   p.program_name,
                   r.assignment_marks,
                   r.final_exam_marks,
                   r.total_marks,
                   r.grade,
                   r.status,
                   l.first_name AS lecturer_first_name,
                   l.last_name  AS lecturer_last_name
            FROM results r
            INNER JOIN students s ON r.student_id = s.id
            LEFT JOIN programs p ON s.program_id = p.id
            LEFT JOIN lecturers l ON r.entered_by = l.id
            WHERE r.semester_id = :semester_id
              AND r.course_id   = :course_id
            ORDER BY s.last_name, s.first_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => $semesterId,
        'course_id'   => $selectedCourseId,
    ]);
    $results = $stmt->fetchAll();
}
$submittedUiSummary = [
    'total' => 0,
    'with_exam' => 0,
    'submitted' => 0,
    'approved' => 0,
    'published' => 0
];
foreach ($results as $row) {
    $submittedUiSummary['total']++;
    if ($row['final_exam_marks'] !== null) {
        $submittedUiSummary['with_exam']++;
    }
    $statusKey = strtolower((string)($row['status'] ?? ''));
    if (isset($submittedUiSummary[$statusKey])) {
        $submittedUiSummary[$statusKey]++;
    }
}

// Notifications for header bell
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Results - Submitted / Exam Entry - ' . APP_NAME;
include '../../../includes/header.php';
?>
<style>
.results-quick-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(130px, 1fr));
    gap: 10px;
    margin-bottom: 8px;
}
.results-stat-card {
    background: #fff;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    padding: 8px 10px;
    min-height: 64px;
}
.results-stat-label {
    font-size: 0.72rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.results-stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}
.results-filter {
    margin-bottom: 0.25rem;
}
.results-filter label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 2px;
}
.results-filter .form-control {
    height: 36px;
    border-radius: 10px;
    border-color: #d7e0ea;
}
.results-actions-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 6px;
}
.results-actions-bar .text-muted {
    font-size: 0.82rem;
}
.marks-table thead th {
    background: #eaf2ff;
    border-color: #cfe0ff;
    font-weight: 700;
    white-space: nowrap;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.marks-table td,
.marks-table th {
    vertical-align: middle !important;
    padding: 0.5rem 0.5rem;
}
.marks-table input.form-control {
    border-radius: 8px;
    font-weight: 700;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    height: 36px;
    max-width: 95px;
    margin: 0 auto;
}
.marks-table tbody tr:nth-child(even) {
    background: #f9fbff;
}
.marks-table tbody tr:hover {
    background: #eef6ff;
}
.marks-table {
    font-size: 0.84rem;
}
.marks-table th {
    font-size: 0.75rem;
}
.change-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.change-bar label {
    margin: 0;
    font-weight: 700;
    font-size: 0.82rem;
}
.change-bar .form-control {
    height: 34px;
    max-width: 360px;
}
.exam-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}
.exam-section-title h5 {
    margin: 0;
    font-weight: 700;
}
.exam-compact-note {
    font-size: 0.82rem;
    color: #64748b;
}
.exam-alert {
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    margin-bottom: 0.6rem;
}
.exam-alert details summary {
    cursor: pointer;
    font-weight: 600;
}
.exam-alert details p {
    margin: 0.4rem 0 0;
}
@media (max-width: 992px) {
    .results-quick-stats {
        grid-template-columns: repeat(2, minmax(130px, 1fr));
    }
}
@media (max-width: 576px) {
    .results-quick-stats {
        grid-template-columns: repeat(2, minmax(130px, 1fr));
    }
}
html[data-theme='dark'] .results-filter label {
    color: #cbd5e1;
}
html[data-theme='dark'] .results-filter .form-control {
    background: #0b1220;
    border-color: #334155;
    color: #e2e8f0;
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Results Management - Exam Entry & Approval</h4>
        </div>
        <div class="topbar-right">
            <a href="audit.php" class="btn btn-outline-secondary btn-sm mr-2" title="View Audit Trail">
                <i class="fas fa-history"></i> Audit Trail
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('info')): ?>
            <div class="alert alert-info"><?php echo e($session->getFlash('info')); ?></div>
        <?php endif; ?>
        <?php $resultsWorkflowActive = 'enter_approval'; include __DIR__ . '/_workflow_nav.php'; ?>

        <div class="results-quick-stats">
            <div class="results-stat-card">
                <div class="results-stat-label">Rows Loaded</div>
                <div class="results-stat-value"><?php echo (int)$submittedUiSummary['total']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">With Exam Marks</div>
                <div class="results-stat-value"><?php echo (int)$submittedUiSummary['with_exam']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">Approved</div>
                <div class="results-stat-value"><?php echo (int)$submittedUiSummary['approved']; ?></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body" style="padding: 14px 16px;">
                <form method="GET" class="row results-filter">
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
                        <label>Academic Year</label>
                        <select name="academic_year_id" class="form-control" onchange="this.form.submit();">
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                        <label>Semester</label>
                        <select name="semester_number" class="form-control" onchange="this.form.submit();">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-5 col-sm-12 mb-2">
                        <label>Program</label>
                        <select name="program_id" class="form-control" onchange="this.form.submit();">
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>" <?php echo ((int)$selectedProgramId === (int)$program['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                        <label>Year</label>
                        <select name="level_year" class="form-control" onchange="this.form.submit();">
                            <?php foreach ($availableLevelYears as $yearOption): ?>
                                <option value="<?php echo (int)$yearOption; ?>" <?php echo ((int)$selectedLevelYear === (int)$yearOption) ? 'selected' : ''; ?>>
                                    Year <?php echo (int)$yearOption; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-9 col-sm-12 mb-2">
                        <label>Course</label>
                        <select name="course_id" class="form-control" onchange="this.form.submit();">
                            <option value="">-- Select Course --</option>
                            <?php foreach ($coursesWithResults as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $selectedCourseId == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($c['course_code'] . ' - ' . $c['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <?php if ($semesterId): ?>
                    <form method="POST" class="results-actions-bar">
                        <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedAcademicYearId; ?>">
                        <input type="hidden" name="semester_number" value="<?php echo (int)$selectedSemesterNumber; ?>">
                        <input type="hidden" name="program_id" value="<?php echo (int)$selectedProgramId; ?>">
                        <input type="hidden" name="level_year" value="<?php echo (int)$selectedLevelYear; ?>">
                        <button type="submit" name="bulk_approve_semester" value="1" class="btn btn-outline-success btn-sm" onclick="return confirm('Bulk approve all submitted results with exam marks for this semester?');">
                            <i class="fas fa-check-double"></i> Bulk Approve Semester
                        </button>
                        <span class="text-muted">Approves submitted rows with exam marks in the selected semester.</span>
                    </form>
                <?php endif; ?>

                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($coursesWithResults)): ?>
                    <p class="text-muted mb-0">No active offered courses found for the selected Program / Year in this semester.</p>
                <?php elseif (!$hasResultRowsInSemester): ?>
                    <p class="text-muted mb-0">Courses are listed in the selected Program / Year scope, but no submitted/approved/published result rows exist for this semester yet.</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to enter exam marks and approve results.</p>
                <?php else: ?>
                    <div class="exam-section-title">
                        <h5>Exam Marks (60%)</h5>
                        <span class="exam-compact-note"><?php echo e($semesterName); ?></span>
                    </div>

                    <?php if (empty($results)): ?>
                        <p class="text-muted mb-0">No results found for the selected course.</p>
                    <?php else: ?>
                        <?php
                        // Check if any results are published to show a warning
                        $hasPublished = false;
                        foreach ($results as $r) {
                            if ($r['status'] === 'published') {
                                $hasPublished = true;
                                break;
                            }
                        }
                        if ($hasPublished): ?>
                            <div class="alert alert-warning exam-alert">
                                <details>
                                    <summary><i class="fas fa-exclamation-triangle"></i> Published rows - edits unpublish</summary>
                                    <p>Edits move rows to <strong>Submitted</strong> until re-approved and re-published.</p>
                                </details>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="academic_year_id" value="<?php echo $selectedAcademicYearId; ?>">
                            <input type="hidden" name="semester_number" value="<?php echo $selectedSemesterNumber; ?>">
                            <input type="hidden" name="program_id" value="<?php echo (int)$selectedProgramId; ?>">
                            <input type="hidden" name="level_year" value="<?php echo (int)$selectedLevelYear; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                            <div class="table-responsive">
                                <table class="table table-sm table-hover marks-table" style="table-layout:fixed; width:100%;">
                                    <thead>
                                        <tr>
                                            <th style="width:32px;">#</th>
                                            <th style="min-width:150px;">Student</th>
                                            <th style="min-width:90px;">Reg No</th>
                                            <th style="width:85px;" class="text-center">CW</th>
                                            <th style="width:90px;" class="text-center">Exam</th>
                                            <th style="width:75px;" class="text-center">Total</th>
                                            <th style="width:70px;" class="text-center">Grade</th>
                                            <th style="width:80px;" class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($results as $r): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                                <td><?php echo e($r['reg_no']); ?></td>
                                                <td class="text-center" data-cw="<?php echo $r['assignment_marks'] !== null ? htmlspecialchars($r['assignment_marks']) : '0'; ?>">
                                                    <?php echo $r['assignment_marks'] !== null ? number_format($r['assignment_marks'], 2) : '-'; ?>
                                                    <div class="text-muted" style="font-size:11px;">
                                                        <?php echo e(trim(($r['lecturer_first_name'] ?? '') . ' ' . ($r['lecturer_last_name'] ?? '')) ?: 'N/A'); ?>
                                                    </div>
                                                </td>
                                                <td class="text-center" style="max-width:120px;">
                                                    <input type="number" name="exam[<?php echo $r['result_id']; ?>]" class="form-control form-control-sm exam-input text-center" min="0" max="60" step="0.01" value="<?php echo $r['final_exam_marks'] !== null ? htmlspecialchars($r['final_exam_marks']) : ''; ?>" />
                                                </td>
                                                <td class="text-center total-cell" data-initial-total="<?php echo $r['total_marks'] !== null ? htmlspecialchars($r['total_marks']) : ''; ?>">
                                                    <span class="total-value"><?php echo $r['total_marks'] !== null ? number_format($r['total_marks'], 2) : '-'; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="grade-value"><?php echo $r['grade'] !== null ? e($r['grade']) : '-'; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($r['status'] === 'submitted'): ?>
                                                        <span class="badge badge-warning">Submitted</span>
                                                    <?php elseif ($r['status'] === 'approved'): ?>
                                                        <span class="badge badge-success">Approved</span>
                                                    <?php elseif ($r['status'] === 'published'): ?>
                                                        <span class="badge badge-primary">Published</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary"><?php echo e(ucfirst($r['status'])); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3">
                                <div class="change-bar">
                                    <label>Change Reason</label>
                                    <textarea name="edit_reason" class="form-control" rows="1" placeholder="Short reason (audit log)" required></textarea>
                                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Save exam marks? Changes will be logged in the audit trail.');">
                                        Save Exam Marks
                                    </button>
                                    <button type="submit" name="bulk_approve_course" value="1" class="btn btn-success btn-sm" onclick="return confirm('Bulk approve all submitted results with exam marks for this course?');">
                                        Bulk Approve This Course
                                    </button>
                                </div>
                                <p class="text-muted mt-2" style="font-size:12px;">
                                    CW by lecturers. Exam by admins. Edits to published rows return them to <strong>Submitted</strong> until re-approved.
                                </p>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Live total & grade updater for admin exam entry: CW (40%) + Exam (60%)
document.addEventListener('DOMContentLoaded', function() {
    var table = document.querySelector('.marks-table');
    if (!table) return;

    function calculateGrade(total) {
        if (isNaN(total)) return '-';
        if (total >= 90) return 'A+';
        if (total >= 80) return 'A';
        if (total >= 75) return 'B+';
        if (total >= 70) return 'B';
        if (total >= 65) return 'C+';
        if (total >= 60) return 'C';
        if (total >= 0)  return 'F';
        return '-';
    }

    function updateRowTotal(row) {
        var cwCell    = row.querySelector('[data-cw]');
        var examInput = row.querySelector('.exam-input');
        var totalCell = row.querySelector('.total-value');
        var gradeCell = row.querySelector('.grade-value');
        var totalWrapper = row.querySelector('.total-cell');
        if (!cwCell || !examInput || !totalCell || !gradeCell || !totalWrapper) return;

        var cw = parseFloat(cwCell.getAttribute('data-cw')) || 0;
        var exam = parseFloat(examInput.value);
        if (isNaN(exam)) {
            // If no exam entered, fall back to DB total (if any) already rendered
            var initial = totalWrapper.getAttribute('data-initial-total');
            if (initial === '' || initial === null) {
                totalCell.textContent = '-';
                gradeCell.textContent = '-';
            } else {
                var num = parseFloat(initial);
                if (isNaN(num)) {
                    totalCell.textContent = '-';
                    gradeCell.textContent = '-';
                } else {
                    totalCell.textContent = num.toFixed(2);
                    gradeCell.textContent = calculateGrade(num);
                }
            }
            return;
        }

        var total = cw + exam; // DB triggers still compute official total including any other components
        totalCell.textContent = total.toFixed(2);
        gradeCell.textContent = calculateGrade(total);
    }

    table.querySelectorAll('tbody tr').forEach(function(row) {
        var examInput = row.querySelector('.exam-input');
        if (!examInput) return;

        ['input', 'change'].forEach(function(evt) {
            examInput.addEventListener(evt, function() {
                updateRowTotal(row);
            });
        });
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>
