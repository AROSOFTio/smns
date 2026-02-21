<?php
/**
 * Lecturer Draft Results View
 * Shows all draft coursework marks saved by this lecturer (not yet submitted)
 * Allows viewing and continuing to edit drafts from different semesters/years
 */

require_once '../../config.php';

$session = new Session('lecturer');
$auth = new Auth('lecturer');

// Check authentication
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/views/lecturer/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'] ?? [];

if (empty($lecturerProfile) || empty($lecturerProfile['id'])) {
    die('Lecturer profile not found.');
}

$db   = new Database();
$conn = $db->getConnection();

$pageTitle = 'Draft Results';

// Optional filters
$filterAcademicYear = isset($_REQUEST['academic_year']) ? (int)$_REQUEST['academic_year'] : 0;
$filterSemester = isset($_REQUEST['semester']) ? (int)$_REQUEST['semester'] : 0;
$filterCourse = isset($_REQUEST['course']) ? (int)$_REQUEST['course'] : 0;

$draftRedirectUrl = function($ay, $sem, $course) {
    $params = [];
    if ((int)$ay > 0) { $params['academic_year'] = (int)$ay; }
    if ((int)$sem > 0) { $params['semester'] = (int)$sem; }
    if ((int)$course > 0) { $params['course'] = (int)$course; }
    $qs = http_build_query($params);
    return 'draft-results.php' . ($qs ? ('?' . $qs) : '');
};

// Bulk submit drafts for this lecturer (filtered scope).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_submit_drafts'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: ' . $draftRedirectUrl($filterAcademicYear, $filterSemester, $filterCourse));
        exit;
    }

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

    try {
        $whereSql = "WHERE r.entered_by = :lecturer_id AND r.status = 'draft'";
        $params = ['lecturer_id' => (int)$lecturerProfile['id']];
        if ($filterAcademicYear > 0) {
            $whereSql .= " AND sem.academic_year_id = :ay";
            $params['ay'] = (int)$filterAcademicYear;
        }
        if ($filterSemester > 0) {
            $whereSql .= " AND r.semester_id = :sem";
            $params['sem'] = (int)$filterSemester;
        }
        if ($filterCourse > 0) {
            $whereSql .= " AND r.course_id = :course";
            $params['course'] = (int)$filterCourse;
        }

        $fetchSql = "SELECT r.id, r.student_id, r.course_id, r.assignment_marks, r.final_exam_marks, r.total_marks, r.grade, r.status
                     FROM results r
                     INNER JOIN semesters sem ON sem.id = r.semester_id
                     {$whereSql}";
        $fetchStmt = $conn->prepare($fetchSql);
        $fetchStmt->execute($params);
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $session->setFlash('info', 'No draft rows found for bulk submission.');
            header('Location: ' . $draftRedirectUrl($filterAcademicYear, $filterSemester, $filterCourse));
            exit;
        }

        $now = date('Y-m-d H:i:s');
        $changedByUserId = (int)($currentUser['id'] ?? 0);

        $conn->beginTransaction();
        $updStmt = $conn->prepare("UPDATE results SET status = 'submitted', submitted_date = :submitted_date, updated_at = :updated_at WHERE id = :id AND status = 'draft'");
        $newStmt = $conn->prepare("SELECT assignment_marks, final_exam_marks, total_marks, grade, status FROM results WHERE id = :id");
        $auditStmt = $conn->prepare("INSERT INTO results_audit (result_id, student_id, course_id, changed_by_user_id, change_type, old_marks, new_marks, reason) VALUES (:result_id, :student_id, :course_id, :user_id, 'edit', :old_marks, :new_marks, :reason)");

        $submittedCount = 0;
        foreach ($rows as $row) {
            $updStmt->execute([
                'submitted_date' => $now,
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
                'user_id' => $changedByUserId,
                'old_marks' => json_encode($row),
                'new_marks' => json_encode($newRow ?: $row),
                'reason' => 'Lecturer bulk submitted draft coursework for approval'
            ]);
            $submittedCount++;
        }
        $conn->commit();

        // Notify admins once for the batch submit.
        if ($submittedCount > 0) {
            $lecturerName = trim(($lecturerProfile['first_name'] ?? '') . ' ' . ($lecturerProfile['last_name'] ?? ''));
            $adminStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($admins)) {
                $notifTitle = 'Bulk Results Submitted for Review';
                $notifMsg = $lecturerName . ' has bulk-submitted ' . $submittedCount . ' coursework result row(s) for review.';
                $notifLink = BASE_URL . '/views/admin/results/submitted.php';
                $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");
                foreach ($admins as $admin) {
                    $notifStmt->execute([
                        'uid' => (int)$admin['id'],
                        'title' => $notifTitle,
                        'msg' => $notifMsg,
                        'link' => $notifLink
                    ]);
                }
            }
        }

        $session->setFlash('success', 'Bulk submit completed. ' . $submittedCount . ' draft result row(s) submitted for approval.');
        header('Location: ' . $draftRedirectUrl($filterAcademicYear, $filterSemester, $filterCourse));
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $session->setFlash('error', 'Bulk submit failed: ' . $e->getMessage());
        header('Location: ' . $draftRedirectUrl($filterAcademicYear, $filterSemester, $filterCourse));
        exit;
    }
}

// Fetch academic years for filter
$academicYears = $conn->query("SELECT id, year_name FROM academic_years ORDER BY start_date DESC")->fetchAll();

// Fetch semesters for filter
$semesters = [];
if ($filterAcademicYear) {
    $stmt = $conn->prepare("SELECT id, semester_number, semester_name FROM semesters WHERE academic_year_id = :ay ORDER BY semester_number");
    $stmt->execute(['ay' => $filterAcademicYear]);
    $semesters = $stmt->fetchAll();
}

// Fetch courses taught by this lecturer
$coursesStmt = $conn->prepare("
    SELECT DISTINCT c.id, c.course_code, c.course_name
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.id
    WHERE ca.lecturer_id = :lid AND ca.status IN ('active','completed')
    ORDER BY c.course_code
");
$coursesStmt->execute(['lid' => $lecturerProfile['id']]);
$courses = $coursesStmt->fetchAll();

// Build query for all results entered by this lecturer (draft + submitted)
$sql = "
    SELECT 
        r.id,
        r.student_id,
        r.course_id,
        r.semester_id,
        r.assignment_marks AS cw_marks,
        r.status AS result_status,
        r.submitted_date,
        r.created_at,
        r.updated_at,
        s.first_name,
        s.last_name,
        s.student_id AS reg_no,
        s.level_year,
        c.course_code,
        c.course_name,
        sem.semester_name,
        sem.semester_number,
        sem.academic_year_id,
        ay.year_name
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN courses c ON r.course_id = c.id
    JOIN semesters sem ON r.semester_id = sem.id
    JOIN academic_years ay ON sem.academic_year_id = ay.id
    WHERE r.status IN ('draft', 'submitted')
      AND r.entered_by = :lecturer_id
";

$params = ['lecturer_id' => $lecturerProfile['id']];

if ($filterAcademicYear) {
    $sql .= " AND sem.academic_year_id = :ay";
    $params['ay'] = $filterAcademicYear;
}

if ($filterSemester) {
    $sql .= " AND r.semester_id = :sem";
    $params['sem'] = $filterSemester;
}

if ($filterCourse) {
    $sql .= " AND r.course_id = :course";
    $params['course'] = $filterCourse;
}

$sql .= " ORDER BY ay.start_date DESC, sem.semester_number DESC, c.course_code, s.last_name, s.first_name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$draftResults = $stmt->fetchAll();
$draftOnlyCount = 0;
$submittedOnlyCount = 0;
foreach ($draftResults as $row) {
    if (($row['result_status'] ?? '') === 'draft') {
        $draftOnlyCount++;
    } elseif (($row['result_status'] ?? '') === 'submitted') {
        $submittedOnlyCount++;
    }
}

// Group by course and semester for better organization
$groupedDrafts = [];
foreach ($draftResults as $result) {
    $key = $result['year_name'] . ' - ' . $result['semester_name'] . ' | ' . $result['course_code'] . ' - ' . $result['course_name'];
    if (!isset($groupedDrafts[$key])) {
        $groupedDrafts[$key] = [
            'academic_year' => $result['year_name'],
            'academic_year_id' => $result['academic_year_id'],
            'semester' => $result['semester_name'],
            'semester_number' => $result['semester_number'],
            'semester_id' => $result['semester_id'],
            'course_code' => $result['course_code'],
            'course_name' => $result['course_name'],
            'course_id' => $result['course_id'],
            'status' => $result['result_status'],
            'students' => [],
            'count_draft' => 0,
            'count_submitted' => 0
        ];
    }
    if (($result['result_status'] ?? '') === 'draft') {
        $groupedDrafts[$key]['count_draft']++;
    } elseif (($result['result_status'] ?? '') === 'submitted') {
        $groupedDrafts[$key]['count_submitted']++;
    }
    $groupedDrafts[$key]['students'][] = $result;
}

// Fetch unread notifications
$unreadNotifications = [];
if (!empty($currentUser['id'])) {
    $unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);
}

$flashSuccess = trim((string)$session->getFlash('success'));
$flashError = trim((string)$session->getFlash('error'));
$flashInfo = trim((string)$session->getFlash('info'));

$pageTitle = 'Draft Results - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Draft Results</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar">
                            <?php if (!empty($lecturerProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $lecturerProfile['photo']; ?>" alt="Profile Photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($lecturerProfile['first_name'], 0, 1) . substr($lecturerProfile['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-name">
                            <?php echo e($lecturerProfile['first_name'] . ' ' . $lecturerProfile['last_name']); ?>
                        </div>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">
            <?php if ($flashSuccess !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo e($flashSuccess); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($flashError !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo e($flashError); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if ($flashInfo !== ''): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle"></i> <?php echo e($flashInfo); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header" style="background: linear-gradient(135deg, #0f4c81 0%, #1a7f64 100%); color: white; border-bottom: none;">
                    <h5 class="mb-0" style="font-weight: 500;">
                        <i class="fas fa-filter mr-2"></i> Filter Draft Results
                    </h5>
                </div>
                <div class="card-body" style="background-color: #f8f9fa;">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-3 mb-3">
                            <label for="academic_year" class="form-label font-weight-medium" style="color: #495057;">Academic Year</label>
                            <select name="academic_year" id="academic_year" class="form-control" onchange="this.form.submit()">
                                <option value="">All Years</option>
                                <?php foreach ($academicYears as $ay): ?>
                                    <option value="<?php echo $ay['id']; ?>" <?php echo $filterAcademicYear == $ay['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($ay['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($filterAcademicYear && !empty($semesters)): ?>
                        <div class="col-md-3 mb-3">
                            <label for="semester" class="form-label font-weight-medium" style="color: #495057;">Semester</label>
                            <select name="semester" id="semester" class="form-control" onchange="this.form.submit()">
                                <option value="">All Semesters</option>
                                <?php foreach ($semesters as $sem): ?>
                                    <option value="<?php echo $sem['id']; ?>" <?php echo $filterSemester == $sem['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($sem['semester_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4 mb-3">
                            <label for="course" class="form-label font-weight-medium" style="color: #495057;">Course</label>
                            <select name="course" id="course" class="form-control" onchange="this.form.submit()">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($c['course_code'] . ' - ' . $c['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($filterAcademicYear || $filterSemester || $filterCourse): ?>
                        <div class="col-md-2 mb-3">
                            <label class="form-label" style="opacity: 0;">Clear</label>
                            <div>
                                <a href="draft-results.php" class="btn btn-outline-secondary btn-block">
                                    <i class="fas fa-times mr-1"></i> Clear
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>

                    <div class="d-flex flex-wrap mb-2" style="gap:8px;">
                        <span class="badge badge-warning" style="font-size:12px;">Draft Rows: <?php echo (int)$draftOnlyCount; ?></span>
                        <span class="badge badge-info" style="font-size:12px;">Submitted Rows: <?php echo (int)$submittedOnlyCount; ?></span>
                        <span class="badge badge-light border" style="font-size:12px;">Total Rows: <?php echo (int)count($draftResults); ?></span>
                    </div>
                    <small class="text-muted d-block mb-2">
                        Workflow: Save partial marks in Enter Results, review here anytime, then submit single-course or bulk when ready.
                    </small>

                    <?php if ($draftOnlyCount > 0): ?>
                        <form method="POST" class="mt-2">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="academic_year" value="<?php echo (int)$filterAcademicYear; ?>">
                            <input type="hidden" name="semester" value="<?php echo (int)$filterSemester; ?>">
                            <input type="hidden" name="course" value="<?php echo (int)$filterCourse; ?>">
                            <button type="submit" name="bulk_submit_drafts" value="1" class="btn btn-success btn-sm" onclick="return confirm('Submit all currently filtered draft rows for admin approval?');">
                                <i class="fas fa-paper-plane mr-1"></i> Bulk Submit Drafts (<?php echo (int)$draftOnlyCount; ?>)
                            </button>
                            <small class="text-muted ml-2">Submits all filtered draft coursework rows at once.</small>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($groupedDrafts)): ?>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> No draft results found. 
                    <?php if ($filterAcademicYear || $filterSemester || $filterCourse): ?>
                        Try adjusting your filters or <a href="draft-results.php">view all drafts</a>.
                    <?php else: ?>
                        Draft results are automatically saved when you save coursework marks without submitting them.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> 
                    Showing <strong><?php echo count($draftResults); ?></strong> result(s) from <strong><?php echo count($groupedDrafts); ?></strong> course(s).
                    Your draft and submitted coursework marks are shown below for reference.
                </div>

                <?php foreach ($groupedDrafts as $key => $group): ?>
                    <div class="card mt-3">
                        <?php 
                            $isSubmitted = ($group['count_submitted'] > 0 && $group['count_draft'] === 0);
                            $headerBg = $isSubmitted 
                                ? 'background: linear-gradient(90deg, #28a745 0%, #218838 100%); color: white;' 
                                : 'background: linear-gradient(90deg, #ffc107 0%, #e0a800 100%); color: #212529;';
                        ?>
                        <div class="card-header" style="<?php echo $headerBg; ?> border-bottom: 1px solid #dee2e6;">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h6 class="mb-1" style="font-weight: 600; font-size: 16px;">
                                        <i class="fas fa-book-open mr-2 text-primary"></i>
                                        <?php echo e($group['course_code']); ?> - <?php echo e($group['course_name']); ?>
                                    </h6>
                                    <small class="text-muted" style="font-size: 13px;">
                                        <i class="fas fa-calendar-alt mr-1"></i>
                                        <?php echo e($group['academic_year']); ?> | <?php echo e($group['semester']); ?>
                                    </small>
                                </div>
                                <div class="col-md-4 text-right">
                                    <?php if ($isSubmitted): ?>
                                        <span class="badge badge-light" style="font-size: 12px; padding: 6px 12px;">
                                            <i class="fas fa-check-circle mr-1 text-success"></i> Submitted
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-dark" style="font-size: 12px; padding: 6px 12px;">
                                            <i class="fas fa-pencil-alt mr-1"></i> Draft In Progress
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge badge-warning" style="font-size: 12px; padding: 6px 12px;">
                                        Draft: <?php echo (int)$group['count_draft']; ?>
                                    </span>
                                    <span class="badge badge-info" style="font-size: 12px; padding: 6px 12px;">
                                        Submitted: <?php echo (int)$group['count_submitted']; ?>
                                    </span>
                                    <span class="badge badge-dark" style="font-size: 12px; padding: 6px 12px;">
                                        <i class="fas fa-users mr-1"></i>
                                        <?php echo count($group['students']); ?> Student<?php echo count($group['students']) != 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" style="font-size: 14px;">
                                    <thead style="background-color: #f8f9fa;">
                                        <tr>
                                            <th style="width: 18%; padding: 12px 8px; border-top: none;">Student ID</th>
                                            <th style="width: 30%; padding: 12px 8px; border-top: none;">Student Name</th>
                                            <th style="width: 12%; padding: 12px 8px; border-top: none;">Year</th>
                                            <th style="width: 12%; padding: 12px 8px; border-top: none;">CW Marks</th>
                                            <th style="width: 10%; padding: 12px 8px; border-top: none;">Status</th>
                                            <th style="width: 13%; padding: 12px 8px; border-top: none;">Last Updated</th>
                                            <th style="width: 10%; padding: 12px 8px; border-top: none;" class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group['students'] as $student): ?>
                                            <tr style="border-left: 3px solid transparent;">
                                                <td style="padding: 12px 8px; vertical-align: middle;">
                                                    <strong class="text-primary"><?php echo e($student['reg_no']); ?></strong>
                                                </td>
                                                <td style="padding: 12px 8px; vertical-align: middle;">
                                                    <?php echo e($student['first_name'] . ' ' . $student['last_name']); ?>
                                                </td>
                                                <td style="padding: 12px 8px; vertical-align: middle;">
                                                    <span class="badge badge-light" style="color: #495057;">Year <?php echo e($student['level_year']); ?></span>
                                                </td>
                                                <td style="padding: 12px 8px; vertical-align: middle;">
                                                    <span class="badge badge-info" style="font-size: 12px; padding: 4px 8px;">
                                                        <?php echo $student['cw_marks'] !== null ? number_format($student['cw_marks'], 1) . '/40' : '-/40'; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px 8px; vertical-align: middle;">
                                                    <?php if ($student['result_status'] === 'submitted'): ?>
                                                        <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check mr-1"></i>Submitted</span>
                                                    <?php elseif ($student['result_status'] === 'approved'): ?>
                                                        <span class="badge badge-primary" style="font-size: 11px;"><i class="fas fa-thumbs-up mr-1"></i>Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning" style="font-size: 11px;"><i class="fas fa-pencil-alt mr-1"></i>Draft</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 12px 8px; vertical-align: middle; font-size: 12px; color: #6c757d;">
                                                    <?php echo date('d M Y', strtotime($student['updated_at'])); ?><br>
                                                    <small style="color: #adb5bd;"><?php echo date('H:i', strtotime($student['updated_at'])); ?></small>
                                                </td>
                                                <td style="padding: 12px 8px; vertical-align: middle;" class="text-center">
                                                    <a href="enter-results.php?academic_year_id=<?php echo $group['academic_year_id']; ?>&semester_number=<?php echo $group['semester_number']; ?>&course_id=<?php echo $student['course_id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       title="Continue editing"
                                                       style="font-size: 12px; padding: 4px 8px;">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer" style="background-color: #f8f9fa; border-top: 1px solid #dee2e6; padding: 12px 15px;">
                            <div class="text-right">
                                <?php if ($isSubmitted): ?>
                                    <span class="text-success mr-2" style="font-size: 13px;"><i class="fas fa-check-circle mr-1"></i>Submitted for approval</span>
                                    <a href="enter-results.php?academic_year_id=<?php echo $group['academic_year_id']; ?>&semester_number=<?php echo $group['semester_number']; ?>&course_id=<?php echo $group['course_id']; ?>" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye mr-1"></i> View in Enter Results
                                    </a>
                                <?php else: ?>
                                    <a href="enter-results.php?academic_year_id=<?php echo $group['academic_year_id']; ?>&semester_number=<?php echo $group['semester_number']; ?>&course_id=<?php echo $group['course_id']; ?>" 
                                       class="btn btn-success btn-sm">
                                        <i class="fas fa-paper-plane mr-1"></i> Continue & Submit This Course
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.card {
    border: 1px solid #e3e6f0;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
}

.card-header {
    border-radius: 8px 8px 0 0 !important;
}

.form-label {
    font-size: 14px;
    margin-bottom: 5px;
    font-weight: 500;
}

.font-weight-medium {
    font-weight: 500 !important;
}

.table tbody tr:hover {
    background-color: #f8f9fa !important;
    border-left: 3px solid #007bff !important;
    transition: all 0.2s ease;
}

.badge-info {
    background-color: #17a2b8;
    color: white;
}

.badge-light {
    background-color: #f8f9fa;
    color: #495057;
    border: 1px solid #dee2e6;
}

.btn-outline-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 123, 255, 0.2);
}

.alert-info {
    background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
    border: 1px solid #b6d4da;
    color: #0c5460;
}

.content-area {
    padding: 20px;
}

html[data-theme='dark'] .content-area .card {
    background: #0f172a;
    border-color: #334155;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
}

html[data-theme='dark'] .content-area .card-body,
html[data-theme='dark'] .content-area .card-footer,
html[data-theme='dark'] .content-area .table thead[style*='background-color: #f8f9fa;'],
html[data-theme='dark'] .content-area .card-body[style*='background-color:#f8f9fa'],
html[data-theme='dark'] .content-area .card-body[style*='background-color: #f8f9fa'],
html[data-theme='dark'] .content-area .card-footer[style*='background-color:#f8f9fa'],
html[data-theme='dark'] .content-area .card-footer[style*='background-color: #f8f9fa'] {
    background: #0f172a !important;
    color: #e5e7eb;
}

html[data-theme='dark'] .content-area .form-label,
html[data-theme='dark'] .content-area label,
html[data-theme='dark'] .content-area [style*='color:#495057'],
html[data-theme='dark'] .content-area [style*='color: #495057'] {
    color: #e5e7eb !important;
}

html[data-theme='dark'] .content-area .text-muted,
html[data-theme='dark'] .content-area small,
html[data-theme='dark'] .content-area [style*='color:#6c757d'],
html[data-theme='dark'] .content-area [style*='color: #6c757d'],
html[data-theme='dark'] .content-area [style*='color:#adb5bd'],
html[data-theme='dark'] .content-area [style*='color: #adb5bd'] {
    color: #94a3b8 !important;
}

html[data-theme='dark'] .content-area .form-control {
    background: #0b1220;
    border-color: #475569;
    color: #e2e8f0;
}

html[data-theme='dark'] .content-area .form-control:focus {
    background: #0b1220;
    border-color: #60a5fa;
    color: #f8fafc;
    box-shadow: 0 0 0 0.2rem rgba(96, 165, 250, 0.2);
}

html[data-theme='dark'] .content-area .badge-light {
    background-color: #1f2937 !important;
    border-color: #475569 !important;
    color: #e2e8f0 !important;
}

html[data-theme='dark'] .content-area .table,
html[data-theme='dark'] .content-area .table th,
html[data-theme='dark'] .content-area .table td {
    color: #e5e7eb;
    border-color: #334155;
}

html[data-theme='dark'] .content-area .table thead th {
    background: #1e293b !important;
    color: #f8fafc;
}

html[data-theme='dark'] .content-area .table tbody tr:hover {
    background-color: #1e293b !important;
    border-left-color: #60a5fa !important;
}

html[data-theme='dark'] .content-area .alert-info {
    background: linear-gradient(135deg, #082f49 0%, #0f172a 100%);
    border-color: #155e75;
    color: #bae6fd;
}

html[data-theme='dark'] .content-area .alert-info a {
    color: #7dd3fc;
}

@media (max-width: 768px) {
    .col-md-8, .col-md-4 {
        text-align: center;
        margin-bottom: 10px;
    }
    
    .table-responsive {
        font-size: 12px;
    }
    
    .btn-sm {
        font-size: 10px;
        padding: 2px 6px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Alert timing is handled globally in assets/js/navigation.js
});
</script>

<?php include '../../includes/footer.php'; ?>
