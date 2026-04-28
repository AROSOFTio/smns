<?php
/**
 * Admin - Provisional Results (Review & Publish)
 *
 * Shows results that have been approved internally (status = approved)
 * so that admins can review and then publish them to the student portal
 * (status = published).
 */
require_once '../../../config.php';
require_once '../../../includes/functions.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser  = $auth->getCurrentUser();
$adminProfile = $currentUser['profile'] ?? [];

$db   = new Database();
$conn = $db->getConnection();

// Filters: Academic Year, Semester, Program, Year of Study, Course
$window = getAcademicCalendarDisplayWindowBounds();
$academicYearsStmt = $conn->prepare("SELECT id, year_name, start_date FROM academic_years WHERE start_date >= :start_date AND start_date <= :end_date ORDER BY start_date DESC");
$academicYearsStmt->execute($window);
$academicYears = $academicYearsStmt->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_REQUEST['academic_year_id']) ? (int) $_REQUEST['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_REQUEST['semester_number']) ? (int) $_REQUEST['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);
$requestedCourseId      = isset($_REQUEST['course_id']) ? (int) $_REQUEST['course_id'] : 0;
$requestedLevelYear     = isset($_REQUEST['level_year']) ? (int) $_REQUEST['level_year'] : 0;
$requestedProgramId     = isset($_REQUEST['program_id']) ? (int) $_REQUEST['program_id'] : 0;
$programs               = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

if ($requestedProgramId > 0) {
    $selectedProgramId = $requestedProgramId;
} elseif ($requestedCourseId > 0) {
    $programByCourseStmt = $conn->prepare("SELECT program_id FROM courses WHERE id = :id LIMIT 1");
    $programByCourseStmt->execute(['id' => $requestedCourseId]);
    $selectedProgramId = (int)($programByCourseStmt->fetchColumn() ?: 0);
} else {
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
    $levelSql = "SELECT DISTINCT c.level_year
                 FROM courses c
                 WHERE (
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
                              AND r.status IN ('approved','published')
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM results rp
                            INNER JOIN students sp2 ON sp2.id = rp.student_id
                            WHERE rp.course_id = c.id
                              AND rp.semester_id = :semester_id_rp
                              AND rp.status IN ('approved','published')
                              AND sp2.program_id = :program_id_rp
                        )
                 )";
    $levelParams = [
        'semester_number' => (int)$selectedSemesterNumber,
        'semester_id_cr' => (int)$semesterId,
        'semester_id_r' => (int)$semesterId,
        'semester_id_crp' => (int)$semesterId,
        'semester_id_rp' => (int)$semesterId,
        'program_id_crp' => (int)$selectedProgramId,
        'program_id_rp' => (int)$selectedProgramId,
    ];
    if ($selectedProgramId > 0) {
        $levelSql .= " AND c.program_id = :program_id";
        $levelParams['program_id'] = (int)$selectedProgramId;
    }
    $levelSql .= " ORDER BY c.level_year ASC";
    $levelStmt = $conn->prepare($levelSql);
    $levelStmt->execute($levelParams);
    $availableLevelYears = array_map('intval', $levelStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
} catch (Exception $e) {
    $availableLevelYears = [];
}
if ($requestedLevelYear > 0) {
    $selectedLevelYear = $requestedLevelYear;
} elseif ($requestedCourseId > 0) {
    $levelByCourseStmt = $conn->prepare("SELECT level_year FROM courses WHERE id = :id LIMIT 1");
    $levelByCourseStmt->execute(['id' => $requestedCourseId]);
    $selectedLevelYear = (int)($levelByCourseStmt->fetchColumn() ?: 0);
} else {
    $selectedLevelYear = !empty($availableLevelYears) ? (int)$availableLevelYears[0] : 1;
}
if (!empty($availableLevelYears) && !in_array((int)$selectedLevelYear, $availableLevelYears, true)) {
    $selectedLevelYear = (int)$availableLevelYears[0];
}
if (empty($availableLevelYears)) {
    $availableLevelYears = [1, 2, 3, 4];
}

// Courses offered in the selected semester/program/year with readiness counters
$coursesWithResults = [];
if ($semesterId) {
    $sql = "SELECT
                c.id,
                c.course_code,
                c.course_name,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN r.status = 'approved' AND r.final_exam_marks IS NOT NULL THEN 1 ELSE 0 END) AS ready_count
            FROM courses c
            LEFT JOIN results r
                   ON r.course_id = c.id
                  AND r.semester_id = :semester_id
                  AND r.status IN ('approved', 'published')
            WHERE c.level_year = :level_year
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
                       FROM results r2
                       WHERE r2.course_id = c.id
                         AND r2.semester_id = :semester_id_r
                         AND r2.status IN ('approved','published')
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM results rp
                       INNER JOIN students sp2 ON sp2.id = rp.student_id
                       WHERE rp.course_id = c.id
                         AND rp.semester_id = :semester_id_rp
                         AND rp.status IN ('approved','published')
                         AND sp2.program_id = :program_id_rp
                   )
              )";
    $params = [
        'semester_id' => (int)$semesterId,
        'semester_id_cr' => (int)$semesterId,
        'semester_id_r' => (int)$semesterId,
        'semester_id_crp' => (int)$semesterId,
        'semester_id_rp' => (int)$semesterId,
        'semester_number' => (int)$selectedSemesterNumber,
        'level_year' => (int)$selectedLevelYear,
        'program_id_crp' => (int)$selectedProgramId,
        'program_id_rp' => (int)$selectedProgramId,
    ];
    if ($selectedProgramId > 0) {
        $sql .= " AND c.program_id = :program_id";
        $params['program_id'] = (int)$selectedProgramId;
    }
    $sql .= "
            GROUP BY c.id, c.course_code, c.course_name
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $coursesWithResults = $stmt->fetchAll();

    // Fallback: if nothing returned, pull courses via registrations for this program/year/semester.
    if (empty($coursesWithResults)) {
        $fallbackSql = "SELECT
                c.id,
                c.course_code,
                c.course_name,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN r.status = 'approved' AND r.final_exam_marks IS NOT NULL THEN 1 ELSE 0 END) AS ready_count
            FROM course_registrations cr
            INNER JOIN students s ON s.id = cr.student_id
            INNER JOIN courses c ON c.id = cr.course_id
            LEFT JOIN results r
                   ON r.course_id = c.id
                  AND r.semester_id = :semester_id
                  AND r.status IN ('approved','published')
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
if ($selectedCourseId && !empty($coursesWithResults)) {
    $validIds = array_column($coursesWithResults, 'id');
    if (!in_array($selectedCourseId, $validIds, true)) {
        $selectedCourseId = 0;
    }
}
$autoSelectedCourse = false;
if ($selectedCourseId <= 0 && !empty($coursesWithResults)) {
    foreach ($coursesWithResults as $courseSummary) {
        if ((int)($courseSummary['ready_count'] ?? 0) > 0) {
            $selectedCourseId = (int)($courseSummary['id'] ?? 0);
            break;
        }
    }
    if ($selectedCourseId <= 0) {
        $selectedCourseId = (int)($coursesWithResults[0]['id'] ?? 0);
    }
    $autoSelectedCourse = $selectedCourseId > 0;
}

$hasPublishReadyCourses = false;
foreach ($coursesWithResults as $courseSummary) {
    if ((int)($courseSummary['ready_count'] ?? 0) > 0) {
        $hasPublishReadyCourses = true;
        break;
    }
}
$scopedCourseIds = array_values(array_map('intval', array_column($coursesWithResults, 'id')));

// Ensure results_audit table exists (auto-create)
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

$redirectProvisional = function($ayId, $semNo, $courseId = 0, $levelYear = 0, $programId = 0) {
    $url = 'provisional.php?academic_year_id=' . (int)$ayId . '&semester_number=' . (int)$semNo;
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

// Handle POST: Publish selected course or whole semester results to students (status => published)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['publish']) || isset($_POST['bulk_publish'])) && $semesterId) {
    $isBulkPublish = isset($_POST['bulk_publish']);
    $selectedCourseId = (int) ($_POST['course_id'] ?? 0);
    $auditConfirmed = isset($_POST['audit_confirm']) && (string)$_POST['audit_confirm'] === '1';

    if (!$isBulkPublish && $selectedCourseId <= 0) {
        $session->setFlash('error', 'Invalid course selection for publishing.');
        header('Location: ' . $redirectProvisional($selectedAcademicYearId, $selectedSemesterNumber, 0, $selectedLevelYear, $selectedProgramId));
        exit;
    }
    if (!$auditConfirmed) {
        $session->setFlash('error', 'Audit confirmation is required before publishing to student portal.');
        header('Location: ' . $redirectProvisional($selectedAcademicYearId, $selectedSemesterNumber, $selectedCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $adminUserId = $currentUser['id'] ?? 0;

    try {
        $conn->beginTransaction();

        $whereSql = "WHERE semester_id = :semester_id AND status = 'approved' AND final_exam_marks IS NOT NULL";
        $params = ['semester_id' => (int)$semesterId];
        if (!$isBulkPublish) {
            $whereSql .= " AND course_id = :course_id";
            $params['course_id'] = (int)$selectedCourseId;
        } else {
            if (empty($scopedCourseIds)) {
                $conn->rollBack();
                $session->setFlash('info', 'No scoped courses were found for publishing.');
                header('Location: ' . $redirectProvisional($selectedAcademicYearId, $selectedSemesterNumber, 0, $selectedLevelYear, $selectedProgramId));
                exit;
            }
            $placeholders = [];
            foreach ($scopedCourseIds as $idx => $cid) {
                $key = ':scoped_course_' . $idx;
                $placeholders[] = $key;
                $params['scoped_course_' . $idx] = (int)$cid;
            }
            $whereSql .= " AND course_id IN (" . implode(',', $placeholders) . ")";
        }

        // Fetch all results being published for audit logging
        $fetchSql = "SELECT id, student_id, course_id, assignment_marks, final_exam_marks, total_marks, grade, status 
                     FROM results {$whereSql}";
        $fetchStmt = $conn->prepare($fetchSql);
        $fetchStmt->execute($params);
        $resultsToPublish = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($resultsToPublish)) {
            $conn->rollBack();
            $session->setFlash('info', 'No approved results were found for publishing.');
            header('Location: ' . $redirectProvisional($selectedAcademicYearId, $selectedSemesterNumber, $selectedCourseId, $selectedLevelYear, $selectedProgramId));
            exit;
        }

        $updateSql = "UPDATE results
                      SET status = 'published',
                          published_date = :published_date,
                          updated_at = :updated_at
                      {$whereSql}";
        $u = $conn->prepare($updateSql);
        $uParams = $params;
        $uParams['published_date'] = $now;
        $uParams['updated_at'] = $now;
        $u->execute($uParams);

        // Log each result to audit table as 'publish'
        $auditStmt = $conn->prepare("INSERT INTO results_audit (result_id, student_id, course_id, changed_by_user_id, change_type, old_marks, new_marks, reason) VALUES (:result_id, :student_id, :course_id, :user_id, 'publish', :old_marks, :new_marks, :reason)");
        foreach ($resultsToPublish as $res) {
            $oldMarks = $res;
            $newMarks = $res;
            $newMarks['status'] = 'published';
            $auditStmt->execute([
                'result_id'   => $res['id'],
                'student_id'  => $res['student_id'],
                'course_id'   => $res['course_id'],
                'user_id'     => $adminUserId,
                'old_marks'   => json_encode($oldMarks),
                'new_marks'   => json_encode($newMarks),
                'reason'      => $isBulkPublish
                    ? 'Bulk published to student portal after audit confirmation (semester scope)'
                    : 'Published to student portal after audit confirmation'
            ]);
        }

        $conn->commit();
        try {
            $logger = new Logger();
            $logger->log(
                (int)($currentUser['id'] ?? 0),
                'publish_results',
                'results',
                'Published ' . (int)count($resultsToPublish) . ' approved result row(s) to student portal.',
                [
                    'part' => 'results_publish',
                    'where' => '/views/admin/results/provisional.php',
                    'target' => $isBulkPublish ? ('semester#' . (int)$semesterId) : ('course#' . (int)$selectedCourseId)
                ]
            );
        } catch (Exception $logEx) {
            // Non-fatal.
        }
        $session->setFlash('success', 'Publishing successful. ' . count($resultsToPublish) . ' result(s) are now visible on student portals.');

        header('Location: ' . $redirectProvisional($selectedAcademicYearId, $selectedSemesterNumber, $selectedCourseId, $selectedLevelYear, $selectedProgramId));
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $session->setFlash('error', 'Error publishing results: ' . $e->getMessage());
    }
}

// Fetch approved results for display
$results = [];
if ($semesterId && $selectedCourseId) {
    $sql = "SELECT r.id AS result_id,
                   s.student_id AS reg_no,
                   s.first_name,
                   s.last_name,
                   s.level_year,
                   p.program_name,
                   r.assignment_marks,
                   r.final_exam_marks,
                   r.total_marks,
                   r.grade,
                   r.status
            FROM results r
            INNER JOIN students s ON r.student_id = s.id
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE r.semester_id = :semester_id
              AND r.course_id   = :course_id
              AND r.status      = 'approved'
              AND r.final_exam_marks IS NOT NULL
            ORDER BY s.last_name, s.first_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => $semesterId,
        'course_id'   => $selectedCourseId,
    ]);
    $results = $stmt->fetchAll();
}
$provisionalUiSummary = [
    'submitted' => 0,
    'approved' => 0,
    'published' => 0,
    'ready_for_publish' => 0
];
if ($semesterId) {
    try {
        if (!empty($scopedCourseIds)) {
            $summaryParams = ['semester_id' => (int)$semesterId];
            $summaryIn = [];
            foreach ($scopedCourseIds as $idx => $cid) {
                $key = 'sum_course_' . $idx;
                $summaryIn[] = ':' . $key;
                $summaryParams[$key] = (int)$cid;
            }
            $summaryStmt = $conn->prepare("SELECT status, COUNT(*) AS total_rows
                FROM results
                WHERE semester_id = :semester_id
                  AND status IN ('submitted','approved','published')
                  AND course_id IN (" . implode(',', $summaryIn) . ")
                GROUP BY status");
            $summaryStmt->execute($summaryParams);
        } else {
            $summaryStmt = $conn->prepare("SELECT status, COUNT(*) AS total_rows
                FROM results
                WHERE 1 = 0
                GROUP BY status");
            $summaryStmt->execute();
        }
        $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($summaryRows as $sumRow) {
            $statusKey = strtolower((string)($sumRow['status'] ?? ''));
            if (isset($provisionalUiSummary[$statusKey])) {
                $provisionalUiSummary[$statusKey] = (int)($sumRow['total_rows'] ?? 0);
            }
        }

        if (!empty($scopedCourseIds)) {
            $readyParams = ['semester_id' => (int)$semesterId];
            $readyIn = [];
            foreach ($scopedCourseIds as $idx => $cid) {
                $key = 'ready_course_' . $idx;
                $readyIn[] = ':' . $key;
                $readyParams[$key] = (int)$cid;
            }
            $readyStmt = $conn->prepare("SELECT COUNT(*)
                FROM results
                WHERE semester_id = :semester_id
                  AND status = 'approved'
                  AND final_exam_marks IS NOT NULL
                  AND course_id IN (" . implode(',', $readyIn) . ")");
            $readyStmt->execute($readyParams);
        } else {
            $readyStmt = $conn->prepare("SELECT 0");
            $readyStmt->execute();
        }
        $provisionalUiSummary['ready_for_publish'] = (int)$readyStmt->fetchColumn();
    } catch (Exception $e) {
        // Non-fatal summary fallback.
    }
}

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Provisional Results - Review & Publish - ' . APP_NAME;
include '../../../includes/header.php';
?>
<style>
.results-quick-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(140px, 1fr));
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
.main-content {
    width: auto;
    max-width: none;
    min-width: 0;
    overflow-x: clip;
}
.content-area {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    overflow-x: clip;
}
.publish-note {
    font-size: 0.82rem;
    color: #64748b;
}
.publish-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}
.publish-title h5 {
    margin: 0;
    font-weight: 700;
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
            <h4>Results Management - Provisional Review</h4>
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
        <?php $resultsWorkflowActive = 'publish_result'; include __DIR__ . '/_workflow_nav.php'; ?>

        <div class="results-quick-stats">
            <div class="results-stat-card">
                <div class="results-stat-label">Approved</div>
                <div class="results-stat-value"><?php echo (int)$provisionalUiSummary['approved']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">Ready To Publish</div>
                <div class="results-stat-value"><?php echo (int)$provisionalUiSummary['ready_for_publish']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">Published</div>
                <div class="results-stat-value"><?php echo (int)$provisionalUiSummary['published']; ?></div>
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

                <?php if ($semesterId && $hasPublishReadyCourses): ?>
                    <form method="POST" class="results-actions-bar">
                        <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedAcademicYearId; ?>">
                        <input type="hidden" name="semester_number" value="<?php echo (int)$selectedSemesterNumber; ?>">
                        <input type="hidden" name="program_id" value="<?php echo (int)$selectedProgramId; ?>">
                        <input type="hidden" name="level_year" value="<?php echo (int)$selectedLevelYear; ?>">
                        <label class="mb-0">
                            <input type="checkbox" name="audit_confirm" value="1" required>
                            Audit complete
                        </label>
                        <button type="submit" name="bulk_publish" value="1" class="btn btn-outline-primary btn-sm" onclick="return confirm('Publish all approved results for this semester?');">
                            <i class="fas fa-bullhorn"></i> Publish Semester
                        </button>
                        <span class="text-muted">Pushes all ready rows.</span>
                    </form>
                <?php endif; ?>

                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($coursesWithResults)): ?>
                    <p class="text-muted mb-0">No active offered courses found for the selected Program / Year in this semester.</p>
                <?php elseif (!$hasPublishReadyCourses): ?>
                    <p class="text-muted mb-0">Courses are listed in the selected Program / Year scope, but none are ready to publish yet (approved with exam marks).</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to review provisional results.</p>
                <?php else: ?>
                    <div class="publish-title">
                        <h5>Provisional Results</h5>
                        <span class="publish-note"><?php echo e($semesterName); ?></span>
                    </div>

                    <?php if (empty($results)): ?>
                        <p class="text-muted mb-0">No approved results found for the selected course.</p>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="academic_year_id" value="<?php echo $selectedAcademicYearId; ?>">
                            <input type="hidden" name="semester_number" value="<?php echo $selectedSemesterNumber; ?>">
                            <input type="hidden" name="program_id" value="<?php echo (int)$selectedProgramId; ?>">
                            <input type="hidden" name="level_year" value="<?php echo (int)$selectedLevelYear; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                            <div class="table-responsive" style="overflow-x:auto;">
                                <table class="table table-sm table-hover marks-table" style="table-layout:fixed; width:100%; word-wrap:break-word;">
                                    <thead>
                                        <tr>
                                            <th style="width:32px;">#</th>
                                            <th style="min-width:140px;">Student</th>
                                            <th style="min-width:90px;">Reg No</th>
                                            <th style="width:85px;" class="text-center">CW</th>
                                            <th style="width:85px;" class="text-center">Exam</th>
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
                                                <td><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $r)); ?></td>
                                                <td class="text-center"><?php echo $r['assignment_marks'] !== null ? number_format($r['assignment_marks'], 2) : '-'; ?></td>
                                                <td class="text-center"><?php echo $r['final_exam_marks'] !== null ? number_format($r['final_exam_marks'], 2) : '-'; ?></td>
                                                <td class="text-center"><?php echo $r['total_marks'] !== null ? number_format($r['total_marks'], 2) : '-'; ?></td>
                                                <td class="text-center"><?php echo $r['grade'] !== null ? e($r['grade']) : '-'; ?></td>
                                                <td class="text-center"><span class="badge badge-success">Approved</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 results-actions-bar">
                                <label class="mb-0">
                                    <input type="checkbox" name="audit_confirm" value="1" required>
                                    Audit complete
                                </label>
                                <button type="submit" name="publish" value="1" class="btn btn-primary btn-sm" onclick="return confirm('Publish these results to student portals?');">Publish</button>
                                <span class="text-muted">Live on student dashboards.</span>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
