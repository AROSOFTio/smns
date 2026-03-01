<?php
// Include the functions.php file to ensure the `e()` function is available
require_once '../../../includes/functions.php';

// Ensure the e() function is defined
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Admin - Course Registrations (Pending & Approved)
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

function adminGetLatestGpaSummary(PDO $conn, int $studentId): array
{
    $result = [
        'semester_gpa' => null,
        'cumulative_gpa' => null,
        'promotion_metric' => null,
        'promotion_metric_label' => 'GPA',
    ];
    if ($studentId <= 0) {
        return $result;
    }
    try {
        $stmt = $conn->prepare("
            SELECT sg.semester_gpa, sg.cumulative_gpa
            FROM student_gpas sg
            INNER JOIN semesters s ON s.id = sg.semester_id
            WHERE sg.student_id = :student_id
            ORDER BY s.end_date DESC, s.start_date DESC, sg.id DESC
            LIMIT 1
        ");
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (empty($row)) {
            return $result;
        }

        $sgpa = (isset($row['semester_gpa']) && $row['semester_gpa'] !== '' && is_numeric($row['semester_gpa']))
            ? (float)$row['semester_gpa']
            : null;
        $cgpa = (isset($row['cumulative_gpa']) && $row['cumulative_gpa'] !== '' && is_numeric($row['cumulative_gpa']))
            ? (float)$row['cumulative_gpa']
            : null;

        $result['semester_gpa'] = $sgpa;
        $result['cumulative_gpa'] = $cgpa;
        $result['promotion_metric'] = $cgpa !== null ? $cgpa : $sgpa;
        $result['promotion_metric_label'] = $cgpa !== null ? 'CGPA' : 'SGPA';
    } catch (Exception $e) {
        // non-fatal
    }
    return $result;
}

function adminGetOutstandingRetakeCount(PDO $conn, int $studentId): int
{
    if ($studentId <= 0) {
        return 0;
    }
    try {
        $stmt = $conn->prepare("
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
        $stmt->execute(['student_id' => $studentId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function adminBuildProgressDecision(PDO $conn, int $studentId): array
{
    $decision = [
        'tone' => 'secondary',
        'title' => 'No GPA evidence',
        'detail' => 'No published GPA record yet. Manual review advised.',
        'metric_value' => null,
        'metric_label' => 'GPA',
        'retake_count' => 0,
        'requires_override' => false,
    ];

    if ($studentId <= 0) {
        return $decision;
    }

    $gpa = adminGetLatestGpaSummary($conn, $studentId);
    $retakeCount = adminGetOutstandingRetakeCount($conn, $studentId);
    $metric = isset($gpa['promotion_metric']) ? $gpa['promotion_metric'] : null;
    $metricLabel = (string)($gpa['promotion_metric_label'] ?? 'GPA');

    $decision['metric_value'] = $metric !== null ? (float)$metric : null;
    $decision['metric_label'] = $metricLabel;
    $decision['retake_count'] = (int)$retakeCount;

    if ($metric === null) {
        return $decision;
    }

    $metricValue = (float)$metric;
    if ($metricValue < 2.0) {
        $decision['tone'] = 'danger';
        $decision['title'] = 'Repeat required';
        $decision['detail'] = $metricLabel . ' ' . number_format($metricValue, 2) . ' (< 2.00). Keep in same semester.';
        $decision['requires_override'] = true;
        return $decision;
    }

    if ($retakeCount > 0) {
        $decision['tone'] = 'warning';
        $decision['title'] = 'Continue + Retake reminder';
        $decision['detail'] = $metricLabel . ' ' . number_format($metricValue, 2) . ' (>= 2.00), with ' . $retakeCount . ' outstanding retake(s).';
        return $decision;
    }

    $decision['tone'] = 'success';
    $decision['title'] = 'Continue';
    $decision['detail'] = $metricLabel . ' ' . number_format($metricValue, 2) . ' (>= 2.00).';
    return $decision;
}

// Handle actions: approve single student-semester or approve all for semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
    if ($overrideReason !== '') {
        $overrideReason = preg_replace('/\s+/', ' ', $overrideReason);
    }

    $overrideReasonMinLength = 10;
    $overrideReasonMaxLength = 500;
    $overrideReasonLength = static function ($value) {
        return function_exists('mb_strlen') ? mb_strlen((string)$value) : strlen((string)$value);
    };

    if ($action === 'approve' && !empty($_POST['student_id']) && !empty($_POST['semester_id'])) {
        $studentId = (int)$_POST['student_id'];
        $semesterId = (int)$_POST['semester_id'];
        $progressDecision = adminBuildProgressDecision($conn, $studentId);
        $needsOverride = !empty($progressDecision['requires_override']);

        if ($needsOverride && $overrideReason === '') {
            $session->setFlash('error', 'Approval blocked: this student is below 2.00. Enter an explicit override reason to approve.');
            header('Location: pending.php');
            exit;
        }
        if ($needsOverride && $overrideReasonLength($overrideReason) < $overrideReasonMinLength) {
            $session->setFlash('error', 'Override reason is too short. Please provide at least ' . $overrideReasonMinLength . ' characters.');
            header('Location: pending.php');
            exit;
        }
        if ($needsOverride && $overrideReasonLength($overrideReason) > $overrideReasonMaxLength) {
            $session->setFlash('error', 'Override reason is too long. Please keep it under ' . $overrideReasonMaxLength . ' characters.');
            header('Location: pending.php');
            exit;
        }

        $overrideNote = '';
        if ($needsOverride && $overrideReason !== '') {
            $overrideNote = 'Approval override (<2.00 rule): ' . $overrideReason;
        }

        // Approve the registration
        if ($overrideNote !== '') {
            $stmt = $conn->prepare("UPDATE course_registrations
                                    SET status = 'approved',
                                        approved_by = :admin_id,
                                        approved_date = NOW(),
                                        remarks = TRIM(CONCAT(COALESCE(remarks, ''), CASE WHEN COALESCE(remarks, '') = '' THEN '' ELSE '\n' END, :override_note))
                                    WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'pending'");
            $stmt->execute([
                'admin_id' => $_SESSION['admin_id'] ?? 1,
                'student_id' => $studentId,
                'semester_id' => $semesterId,
                'override_note' => $overrideNote
            ]);
        } else {
            $stmt = $conn->prepare("UPDATE course_registrations
                                    SET status = 'approved', approved_by = :admin_id, approved_date = NOW()
                                    WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'pending'");
            $stmt->execute([
                'admin_id' => $_SESSION['admin_id'] ?? 1,
                'student_id' => $studentId,
                'semester_id' => $semesterId
            ]);
        }

        // Auto-assign all required courses for this student/program/year/semester
        // Get student's program and year
        $stu = $conn->prepare("
            SELECT
                s.program_id,
                COALESCE(
                    (
                        SELECT sr.year_of_study
                        FROM semester_registrations sr
                        WHERE sr.student_id = s.id
                          AND sr.semester_id = :semid
                        ORDER BY sr.id DESC
                        LIMIT 1
                    ),
                    s.year_of_study,
                    s.level_year,
                    1
                ) AS year
            FROM students s
            WHERE s.id = :sid
            LIMIT 1
        ");
        $stu->execute([
            'sid' => $studentId,
            'semid' => $semesterId
        ]);
        $stuRow = $stu->fetch();
        if ($stuRow) {
            $programId = $stuRow['program_id'];
            $year = $stuRow['year'];
            // Get all required course assignments
            $ca = $conn->prepare("SELECT course_id FROM course_assignments WHERE program_id = :pid AND semester_id = :semid AND year_of_study = :year AND status = 'active'");
            $ca->execute(['pid' => $programId, 'semid' => $semesterId, 'year' => $year]);
            $courses = $ca->fetchAll(PDO::FETCH_COLUMN);
            if ($courses) {
                // Insert missing course_registrations
                $checkStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
                $insStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, remarks, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', :admin_id, NOW(), :remarks, NOW())");
                foreach ($courses as $cid) {
                    $checkStmt->execute(['student_id' => $studentId, 'course_id' => $cid, 'semester_id' => $semesterId]);
                    if (!$checkStmt->fetch()) {
                        $insStmt->execute([
                            'student_id' => $studentId,
                            'course_id' => $cid,
                            'semester_id' => $semesterId,
                            'admin_id' => $_SESSION['admin_id'] ?? 1,
                            'remarks' => $overrideNote !== '' ? $overrideNote : null
                        ]);
                    }
                }
            }
        }

        // Create a notification for the student (if user exists)
        $u = $conn->prepare("SELECT user_id FROM students WHERE id = :sid");
        $u->execute(['sid' => $studentId]);
        $urow = $u->fetch();
        if ($urow && $urow['user_id']) {
            $nid = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (:uid, :title, :msg, 'success', :link)");
            $nid->execute([
                'uid' => $urow['user_id'],
                'title' => 'Registration Approved',
                'msg' => 'Your course registration for the semester has been approved by administration. All required courses have been assigned.',
                'link' => BASE_URL . '/views/student/registrations.php?semester_id=' . $semesterId
            ]);
        }

        if ($overrideNote !== '') {
            $actorUserId = (int)($_SESSION['admin_user_id'] ?? $_SESSION['user_id'] ?? 0);
            if ($actorUserId > 0 && class_exists('Logger')) {
                try {
                    (new Logger())->log(
                        $actorUserId,
                        'registration_override_approval',
                        'registrations',
                        'Approved registration with override reason for student #' . $studentId . ', semester #' . $semesterId . '.',
                        [
                            'part' => 'registrations_approval',
                            'reason' => $overrideReason
                        ]
                    );
                } catch (Exception $e) {
                    // non-fatal
                }
            }
        }

        $session->setFlash(
            'success',
            $overrideNote !== ''
                ? 'Selected registrations approved using explicit override reason and courses assigned successfully.'
                : 'Selected registrations approved and courses assigned successfully.'
        );
        header('Location: pending.php');
        exit;
    }

    if ($action === 'approve_all' && !empty($_POST['semester_id'])) {
        $semesterId = (int)$_POST['semester_id'];
        $pendingStudentStmt = $conn->prepare("SELECT DISTINCT student_id FROM course_registrations WHERE semester_id = :semester_id AND status = 'pending'");
        $pendingStudentStmt->execute(['semester_id' => $semesterId]);
        $pendingStudentIds = array_values(array_unique(array_map('intval', $pendingStudentStmt->fetchAll(PDO::FETCH_COLUMN))));

        $blockedStudentIds = [];
        foreach ($pendingStudentIds as $sid) {
            if ($sid <= 0) {
                continue;
            }
            $decision = adminBuildProgressDecision($conn, $sid);
            if (!empty($decision['requires_override'])) {
                $blockedStudentIds[] = $sid;
            }
        }

        $hasBlockedStudents = !empty($blockedStudentIds);
        if ($hasBlockedStudents && $overrideReason === '') {
            $session->setFlash('error', 'Bulk approval blocked: ' . count($blockedStudentIds) . ' student(s) are below 2.00. Enter an explicit override reason to continue.');
            header('Location: pending.php');
            exit;
        }
        if ($hasBlockedStudents && $overrideReasonLength($overrideReason) < $overrideReasonMinLength) {
            $session->setFlash('error', 'Override reason is too short. Please provide at least ' . $overrideReasonMinLength . ' characters.');
            header('Location: pending.php');
            exit;
        }
        if ($hasBlockedStudents && $overrideReasonLength($overrideReason) > $overrideReasonMaxLength) {
            $session->setFlash('error', 'Override reason is too long. Please keep it under ' . $overrideReasonMaxLength . ' characters.');
            header('Location: pending.php');
            exit;
        }

        $overrideNote = '';
        if ($hasBlockedStudents && $overrideReason !== '') {
            $overrideNote = 'Bulk approval override (<2.00 rule): ' . $overrideReason;

            $inPlaceholders = [];
            $remarkParams = [
                'semester_id' => $semesterId,
                'override_note' => $overrideNote
            ];
            foreach ($blockedStudentIds as $idx => $sid) {
                $paramName = 'sid_' . $idx;
                $inPlaceholders[] = ':' . $paramName;
                $remarkParams[$paramName] = (int)$sid;
            }
            if (!empty($inPlaceholders)) {
                $remarkSql = "UPDATE course_registrations
                              SET remarks = TRIM(CONCAT(COALESCE(remarks, ''), CASE WHEN COALESCE(remarks, '') = '' THEN '' ELSE '\n' END, :override_note))
                              WHERE semester_id = :semester_id
                                AND status = 'pending'
                                AND student_id IN (" . implode(', ', $inPlaceholders) . ")";
                $remarkStmt = $conn->prepare($remarkSql);
                $remarkStmt->execute($remarkParams);
            }
        }

        $stmt = $conn->prepare("UPDATE course_registrations
                                SET status = 'approved', approved_by = :admin_id, approved_date = NOW()
                                WHERE semester_id = :semester_id AND status = 'pending'");
        $stmt->execute([
            'admin_id' => $_SESSION['admin_id'] ?? 1,
            'semester_id' => $semesterId
        ]);

        if ($overrideNote !== '') {
            $actorUserId = (int)($_SESSION['admin_user_id'] ?? $_SESSION['user_id'] ?? 0);
            if ($actorUserId > 0 && class_exists('Logger')) {
                try {
                    (new Logger())->log(
                        $actorUserId,
                        'registration_override_bulk_approval',
                        'registrations',
                        'Bulk approved registrations with override reason for semester #' . $semesterId . '.',
                        [
                            'part' => 'registrations_approval',
                            'reason' => $overrideReason,
                            'blocked_student_count' => count($blockedStudentIds)
                        ]
                    );
                } catch (Exception $e) {
                    // non-fatal
                }
            }
        }

        $session->setFlash(
            'success',
            $overrideNote !== ''
                ? 'All pending registrations for the semester have been approved using an override reason for ' . count($blockedStudentIds) . ' below-threshold student(s).'
                : 'All pending registrations for the semester have been approved.'
        );
        header('Location: pending.php');
        exit;
    }
}

// Optional semester filter from GET
$filterSemesterId = isset($_GET['semester_filter_id']) ? (int)$_GET['semester_filter_id'] : 0;

// Enrollment summary (admins need to see enrollments)
$enrollments = $conn->query("SELECT sem.id, sem.semester_number, sem.semester_name, ay.year_name, COUNT(cr.id) as total_registrations, SUM(CASE WHEN cr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count, SUM(CASE WHEN cr.status = 'approved' THEN 1 ELSE 0 END) AS approved_count FROM course_registrations cr JOIN semesters sem ON cr.semester_id = sem.id JOIN academic_years ay ON sem.academic_year_id = ay.id GROUP BY sem.id ORDER BY ay.start_date DESC, sem.semester_number DESC")->fetchAll();

// When no filter is selected, show all semesters so mixed cohorts are visible together.

// Fetch pending grouped by student + semester (apply optional semester filter)
$pendingSql = "SELECT sr.student_id, sr.semester_id, s.first_name, s.last_name, s.student_id as student_code, sem.semester_name, sr.status
               FROM semester_registrations sr
               JOIN students s ON sr.student_id = s.id
               JOIN semesters sem ON sr.semester_id = sem.id
               WHERE sr.status = 'pending' " . ($filterSemesterId ? "AND sr.semester_id = :filter " : "") . "
               ORDER BY sr.request_date DESC";
$pendingStmt = $conn->prepare($pendingSql);
if ($filterSemesterId) $pendingStmt->execute(['filter' => $filterSemesterId]); else $pendingStmt->execute();
$pending = $pendingStmt->fetchAll();

$pendingProgressMeta = [];
foreach ($pending as $row) {
    $sid = (int)($row['student_id'] ?? 0);
    if ($sid <= 0 || isset($pendingProgressMeta[$sid])) {
        continue;
    }
    $pendingProgressMeta[$sid] = adminBuildProgressDecision($conn, $sid);
}

// Fetch approved (reported) grouped by student + semester (apply optional semester filter)
$approvedSql = "SELECT cr.student_id, cr.semester_id, s.first_name, s.last_name, s.student_id as student_code, sem.semester_name, COUNT(*) as course_count
               FROM course_registrations cr
               JOIN students s ON cr.student_id = s.id
               JOIN semesters sem ON cr.semester_id = sem.id
               WHERE cr.status = 'approved' " . ($filterSemesterId ? "AND cr.semester_id = :filter " : "") . "
               GROUP BY cr.student_id, cr.semester_id
               ORDER BY cr.approved_date DESC";
$approvedStmt = $conn->prepare($approvedSql);
if ($filterSemesterId) $approvedStmt->execute(['filter' => $filterSemesterId]); else $approvedStmt->execute();
$approved = $approvedStmt->fetchAll();

$pageTitle = 'Registrations - ' . APP_NAME;
include '../../../includes/header.php';

$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');
$flashWarning = $session->getFlash('warning');
$flashInfo = $session->getFlash('info');
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>Course Registrations</h4>
        </div>
    </div>

        <div class="content-area container-fluid p-4">
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger"><?php echo e($flashError); ?></div>
            <?php endif; ?>
            <?php if ($flashWarning): ?>
                <div class="alert alert-warning"><?php echo e($flashWarning); ?></div>
            <?php endif; ?>
            <?php if ($flashInfo): ?>
                <div class="alert alert-info"><?php echo e($flashInfo); ?></div>
            <?php endif; ?>

            <!-- Enrollment Summary Cards -->
            <div class="row mb-4">
                <?php foreach ($enrollments as $e):
                    $isActive = ($filterSemesterId && $filterSemesterId == $e['id']);
                ?>
                <div class="col-md-3 mb-3">
                    <div class="card <?php echo $isActive ? 'border-primary' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="font-weight-bold"><?php echo e($e['year_name'] . ' - ' . $e['semester_name']); ?></div>
                                    <div class="small text-muted">Total: <?php echo e($e['total_registrations']); ?></div>
                                </div>
                                <span class="badge badge-warning" title="Pending">
                                    <?php echo e($e['pending_count']); ?> Pending
                                </span>
                            </div>
                            <div class="mt-2">
                                <span class="badge badge-success mr-2">Approved: <?php echo e($e['approved_count']); ?></span>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-primary ml-2" style="font-size:13px;vertical-align:middle;cursor:default;">Filtered</span>
                                <?php else: ?>
                                    <a href="pending.php?semester_filter_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-outline-primary ml-2">Filter</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pending Registrations Section -->
            <div class="card mb-4">
                <div class="card-body">
                    <h4 class="mb-3">Pending Registrations <span class="badge badge-warning align-middle"><?php echo count($pending); ?></span></h4>
                    <?php if (empty($pending)): ?>
                        <p class="text-muted">No pending registrations at this time.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                    <th>Semester</th>
                                    <th>Progression / Retake Rule</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($pending as $row): ?>
                                <?php
                                    $pSid = (int)($row['student_id'] ?? 0);
                                    $pMeta = $pendingProgressMeta[$pSid] ?? [
                                        'tone' => 'secondary',
                                        'title' => 'No GPA evidence',
                                        'detail' => 'Manual review advised.'
                                    ];
                                ?>
                                <tr>
                                    <td><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo e($row['student_code']); ?></td>
                                    <td><?php echo e($row['semester_name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo e($pMeta['tone']); ?>"><?php echo e($pMeta['title']); ?></span>
                                        <div class="small text-muted mt-1"><?php echo e($pMeta['detail']); ?></div>
                                    </td>
                                    <td><span class="badge badge-warning">Pending</span></td>
                                    <td>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Approve this registration?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $row['student_id']; ?>">
                                            <input type="hidden" name="semester_id" value="<?php echo $row['semester_id']; ?>">
                                            <?php if (!empty($pMeta['requires_override'])): ?>
                                                <textarea
                                                    name="override_reason"
                                                    class="form-control form-control-sm mb-2"
                                                    rows="2"
                                                    style="min-width:240px;"
                                                    placeholder="Required override reason (< 2.00). Minimum 10 characters."
                                                    required
                                                ></textarea>
                                            <?php else: ?>
                                                <input type="hidden" name="override_reason" value="">
                                            <?php endif; ?>
                                            <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <?php if (!empty($pMeta['requires_override'])): ?>
                                            <div class="small text-danger mt-1">Override reason is required to approve this record.</div>
                                        <?php endif; ?>
                                        <a href="view.php?student_id=<?php echo e($row['student_id']); ?>&semester_id=<?php echo e($row['semester_id']); ?>" class="btn btn-sm btn-info ml-1">View Details</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <div class="mt-3">
                            <form method="POST" onsubmit="return confirm('Approve ALL pending registrations for the selected semester?');" class="form-inline">
                                <?php echo csrfField(); ?>
                                <label class="mr-2">Approve all pending for semester:</label>
                                <select name="semester_id" class="form-control mr-2">
                                    <?php
                                    $sstmt = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id DESC");
                                    $semesters = $sstmt->fetchAll();
                                    foreach ($semesters as $sem) {
                                        echo "<option value=\"{$sem['id']}\">" . e($sem['semester_name']) . "</option>";
                                    }
                                    ?>
                                </select>
                                <input
                                    type="text"
                                    name="override_reason"
                                    class="form-control mr-2"
                                    style="min-width:280px;"
                                    placeholder="Required only if any selected student is below 2.00"
                                >
                                <button name="action" value="approve_all" class="btn btn-warning ml-2">Approve All</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <div class="card">
            <div class="card-body">
                <h5>Reported / Approved Registrations (Filtered)</h5>
                <?php if (empty($approved)): ?>
                    <p class="text-muted">No approved registrations found for this filter.</p>
                <?php else: ?>
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Semester</th>
                                <th>Courses Approved</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($approved as $row): ?>
                                <tr>
                                    <td><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo e($row['student_code']); ?></td>
                                    <td><?php echo e($row['semester_name']); ?></td>
                                    <td><?php echo e($row['course_count']); ?></td>
                                    <td>
                                        <?php
                                        $studentId = isset($row['student_id']) ? e($row['student_id']) : 'N/A';
                                        $semesterId = isset($row['semester_id']) ? e($row['semester_id']) : 'N/A';
                                        ?>
                                        <?php if ($studentId !== 'N/A' && $semesterId !== 'N/A'): ?>
                                            <a href="view.php?student_id=<?php echo $studentId; ?>&semester_id=<?php echo $semesterId; ?>" class="btn btn-sm btn-primary">View Courses</a>
                                        <?php else: ?>
                                            <span class="text-danger">Invalid Data</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Show all approved students across all semesters -->
        <div class="card mt-4">
            <div class="card-body">
                <h5>All Approved Students (All Semesters)</h5>
                <?php
                $allApprovedSql = "SELECT cr.student_id, s.first_name, s.last_name, s.student_id as student_code, sem.semester_name, COUNT(*) as course_count
                    FROM course_registrations cr
                    JOIN students s ON cr.student_id = s.id
                    JOIN semesters sem ON cr.semester_id = sem.id
                    WHERE cr.status = 'approved'
                    GROUP BY cr.student_id, cr.semester_id
                    ORDER BY s.last_name, s.first_name, sem.semester_name";
                $allApproved = $conn->query($allApprovedSql)->fetchAll();
                ?>
                <?php if (empty($allApproved)): ?>
                    <p class="text-muted">No approved students found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Semester</th>
                                <th>Courses Approved</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($allApproved as $row): ?>
                                <tr>
                                    <td><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo e($row['student_code']); ?></td>
                                    <td><?php echo e($row['semester_name']); ?></td>
                                    <td><?php echo e($row['course_count']); ?></td>
                                    <td>
                                        <?php
                                        $studentId = isset($row['student_id']) ? e($row['student_id']) : 'N/A';
                                        $semesterId = isset($row['semester_id']) ? e($row['semester_id']) : 'N/A';
                                        ?>
                                        <?php if ($studentId !== 'N/A' && $semesterId !== 'N/A'): ?>
                                            <a href="view.php?student_id=<?php echo $studentId; ?>&semester_id=<?php echo $semesterId; ?>" class="btn btn-sm btn-primary">View Courses</a>
                                        <?php else: ?>
                                            <span class="text-danger">Invalid Data</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
