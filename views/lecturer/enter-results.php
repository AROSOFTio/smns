<?php
/**
 * Lecturer - Enter Results (Coursework Only)
 * Lecturers can ONLY enter coursework (CW) marks out of 40%.
 * Exam (60%), final total and grade are reserved for examiner/admin.
 */
require_once '../../config.php';

$session = new Session('lecturer');
$auth    = new Auth('lecturer');

// Verify lecturer access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'lecturer') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=lecturer');
    exit;
}

$currentUser     = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];

$db   = new Database();
$conn = $db->getConnection();

// Ensure results_audit table exists for CW edit/submit tracking.
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

// ---------------------------------------------------------------------------
// Filters: Academic Year, Semester, Course (assigned to this lecturer)
// ---------------------------------------------------------------------------

$window = getAcademicCalendarDisplayWindowBounds();
$academicYearsStmt = $conn->prepare("SELECT id, year_name, start_date FROM academic_years WHERE start_date >= :start_date AND start_date <= :end_date ORDER BY start_date DESC");
$academicYearsStmt->execute($window);
$academicYears = $academicYearsStmt->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_REQUEST['academic_year_id']) ? (int) $_REQUEST['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_REQUEST['semester_number']) ? (int) $_REQUEST['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);
if ($selectedSemesterNumber < 1 || $selectedSemesterNumber > 2) {
    $selectedSemesterNumber = 1;
}

// Map AY + semester number to semester row
$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

// Courses assigned to this lecturer for the selected semester
$assignedCourses = [];
if ($semesterId) {
    $sql = "SELECT c.id, c.course_code, c.course_name
            FROM course_assignments ca
            INNER JOIN courses c ON ca.course_id = c.id
            WHERE ca.lecturer_id = :lecturer_id
              AND ca.semester_id  = :semester_id
              AND ca.status IN ('active','completed')
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'lecturer_id' => $lecturerProfile['id'],
        'semester_id' => $semesterId,
    ]);
    $assignedCourses = $stmt->fetchAll();
}

// Pre-select course from GET (e.g., from My Courses quick link)
$selectedCourseId = isset($_REQUEST['course_id']) ? (int) $_REQUEST['course_id'] : 0;

// Ensure selected course belongs to assignedCourses list
if ($selectedCourseId && !empty($assignedCourses)) {
    $courseIds = array_column($assignedCourses, 'id');
    if (!in_array($selectedCourseId, $courseIds, true)) {
        // Invalid course for this lecturer/semester
        $selectedCourseId = 0;
    }
}

// ---------------------------------------------------------------------------
// Handle POST: Save coursework (CW) marks only (0–40)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_draft']) || isset($_POST['submit_results'])) && $semesterId) {
    $selectedCourseId = (int) ($_POST['course_id'] ?? 0);

    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Security token expired. Please retry saving marks.');
        header('Location: ' . BASE_URL . '/views/lecturer/enter-results.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&course_id=' . $selectedCourseId);
        exit;
    }

    if ($selectedCourseId <= 0) {
        $session->setFlash('error', 'Please select a valid course before saving marks.');
        header('Location: ' . BASE_URL . '/views/lecturer/enter-results.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber);
        exit;
    }

    // Security: verify course is actually assigned to this lecturer in this semester
    $check = $conn->prepare("SELECT COUNT(*) FROM course_assignments WHERE lecturer_id = :lecturer AND course_id = :course AND semester_id = :semester AND status IN ('active','completed')");
    $check->execute([
        'lecturer' => $lecturerProfile['id'],
        'course'   => $selectedCourseId,
        'semester' => $semesterId,
    ]);
    if (!$check->fetchColumn()) {
        $session->setFlash('error', 'You are not assigned to this course for the selected semester.');
        header('Location: ' . BASE_URL . '/views/lecturer/enter-results.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber);
        exit;
    }

    $cwData   = $_POST['cw'] ?? []; // [student_id => cw_mark]
    $now      = date('Y-m-d H:i:s');
    $isSubmit = isset($_POST['submit_results']);
    $newStatus = $isSubmit ? 'submitted' : 'draft';
    $auditReason = $isSubmit
        ? 'Lecturer coursework submitted for admin approval'
        : 'Lecturer coursework saved as draft';
    $changedByUserId = (int)($currentUser['id'] ?? 0);

    // Check if there's any data to process
    if (empty($cwData)) {
        if ($isSubmit) {
            $session->setFlash('error', 'No coursework marks entered. Please enter marks before submitting.');
        } else {
            $session->setFlash('error', 'No coursework marks entered to save as draft.');
        }
        header('Location: ' . BASE_URL . '/views/lecturer/enter-results.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&course_id=' . $selectedCourseId);
        exit;
    }

    $conn->beginTransaction();

    try {
        $savedCount = 0;
        $lockedCount = 0;
        $inputCount = 0;
        $auditStmt = $conn->prepare("INSERT INTO results_audit (result_id, student_id, course_id, changed_by_user_id, change_type, old_marks, new_marks, reason) VALUES (:result_id, :student_id, :course_id, :user_id, 'edit', :old_marks, :new_marks, :reason)");

        foreach ($cwData as $studentId => $cwMarkRaw) {
            $studentId = (int) $studentId;

            if ($studentId <= 0) {
                continue;
            }

            $cwMark = trim($cwMarkRaw) === '' ? null : (float) $cwMarkRaw;
            if (trim((string)$cwMarkRaw) !== '') {
                $inputCount++;
            }

            if ($cwMark !== null) {
                if ($cwMark < 0 || $cwMark > 40) {
                    throw new Exception('Coursework marks must be between 0 and 40.');
                }
            }

            // If no CW entered, skip (do not create/update row)
            if ($cwMark === null) {
                continue;
            }

            // Check if draft result already exists (ignore submitted results)
            $existingStmt = $conn->prepare('SELECT id, assignment_marks, final_exam_marks, total_marks, grade, status FROM results WHERE student_id = :student AND course_id = :course AND semester_id = :semester LIMIT 1');
            $existingStmt->execute([
                'student'  => $studentId,
                'course'   => $selectedCourseId,
                'semester' => $semesterId,
            ]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            $resultId = 0;
            $oldMarks = null;
            $newMarks = null;
            $needsAudit = true;

            if ($existing) {
                $resultId = (int)($existing['id'] ?? 0);
                $oldMarks = $existing;

                // Lock lecturer editing once admin has approved/published.
                if (in_array((string)($existing['status'] ?? ''), ['approved', 'published'], true)) {
                    $lockedCount++;
                    continue;
                }

                // Update existing result row with new marks and status
                $updateSql = 'UPDATE results SET assignment_marks = :cw, entered_by = :lecturer, status = :status';
                $params = [
                    'cw'       => $cwMark,
                    'lecturer' => $lecturerProfile['id'],
                    'status'   => $newStatus,
                    'id'       => $existing['id'],
                ];

                if ($isSubmit) {
                    $updateSql .= ', submitted_date = :submitted_date';
                    $params['submitted_date'] = $now;
                }

                $updateSql .= ', updated_at = :updated_at WHERE id = :id';
                $params['updated_at'] = $now;
                $u = $conn->prepare($updateSql);
                $u->execute($params);
                if ($u->rowCount() > 0) {
                    $savedCount++;
                }

                $newStmt = $conn->prepare("SELECT assignment_marks, final_exam_marks, total_marks, grade, status FROM results WHERE id = :id");
                $newStmt->execute(['id' => $resultId]);
                $newMarks = $newStmt->fetch(PDO::FETCH_ASSOC);
                if ($newMarks && $oldMarks) {
                    $needsAudit = (
                        (string)($oldMarks['assignment_marks'] ?? '') !== (string)($newMarks['assignment_marks'] ?? '') ||
                        (string)($oldMarks['status'] ?? '') !== (string)($newMarks['status'] ?? '')
                    );
                }
            } else {
                // Insert new result row
                $insertSql = 'INSERT INTO results (student_id, course_id, semester_id, assignment_marks, status, entered_by, submitted_date, created_at, updated_at)
                              VALUES (:student, :course, :semester, :cw, :status, :lecturer, :submitted_date, :created_at, :updated_at)';
                $i = $conn->prepare($insertSql);
                $i->execute([
                    'student'        => $studentId,
                    'course'         => $selectedCourseId,
                    'semester'       => $semesterId,
                    'cw'             => $cwMark,
                    'status'         => $newStatus,
                    'lecturer'       => $lecturerProfile['id'],
                    'submitted_date' => $isSubmit ? $now : null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $resultId = (int)$conn->lastInsertId();
                if ($resultId > 0) {
                    $savedCount++;
                }

                $newStmt = $conn->prepare("SELECT assignment_marks, final_exam_marks, total_marks, grade, status FROM results WHERE id = :id");
                $newStmt->execute(['id' => $resultId]);
                $newMarks = $newStmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($resultId > 0 && $changedByUserId > 0 && $needsAudit && $newMarks) {
                $auditStmt->execute([
                    'result_id' => $resultId,
                    'student_id' => $studentId,
                    'course_id' => $selectedCourseId,
                    'user_id' => $changedByUserId,
                    'old_marks' => $oldMarks ? json_encode($oldMarks) : null,
                    'new_marks' => json_encode($newMarks),
                    'reason' => $auditReason
                ]);
            }
        }

        $conn->commit();

        // Notify all admins when results are submitted
        if ($isSubmit) {
            // Get course name for notification
            $courseStmt = $conn->prepare("SELECT course_code, course_name FROM courses WHERE id = :cid");
            $courseStmt->execute(['cid' => $selectedCourseId]);
            $courseInfo = $courseStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get lecturer name
            $lecturerName = trim($lecturerProfile['first_name'] . ' ' . $lecturerProfile['last_name']);
            
            // Get all active admin users
            $adminStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($courseInfo && !empty($admins)) {
                $courseName = $courseInfo['course_code'] . ' - ' . $courseInfo['course_name'];
                $notifTitle = 'Results Submitted for Review';
                $notifMsg = $lecturerName . ' has submitted coursework marks for ' . $courseName . '. Please review and approve.';
                $notifLink = BASE_URL . '/views/admin/results/submitted.php';
                
                // Insert notification for each admin
                $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");
                foreach ($admins as $admin) {
                    $notifStmt->execute([
                        'uid' => $admin['id'],
                        'title' => $notifTitle,
                        'msg' => $notifMsg,
                        'link' => $notifLink
                    ]);
                }
            }
            
            if ($savedCount > 0) {
                $session->setFlash('success', $savedCount . ' coursework row(s) submitted for approval. You can track progress in Draft Results.');
            } elseif ($lockedCount > 0) {
                $session->setFlash('info', 'No rows were submitted. ' . $lockedCount . ' row(s) are already approved/published and cannot be changed by lecturer.');
            } else {
                $session->setFlash('info', 'No coursework changes were detected to submit.');
            }
        } else {
            if ($savedCount > 0) {
                $session->setFlash('success', $savedCount . ' coursework row(s) saved as draft. Saved marks remain visible here and in Draft Results.');
            } elseif ($inputCount === 0) {
                $session->setFlash('info', 'No marks entered. You can save partial marks any time and continue later.');
            } elseif ($lockedCount > 0) {
                $session->setFlash('info', 'No rows were saved. ' . $lockedCount . ' row(s) are already approved/published and locked from lecturer edits.');
            } else {
                $session->setFlash('info', 'No coursework changes were detected.');
            }
        }

        header('Location: ' . BASE_URL . '/views/lecturer/enter-results.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&course_id=' . $selectedCourseId);
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $session->setFlash('error', 'Error saving coursework marks: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Fetch students + existing coursework (for display in form)
// ---------------------------------------------------------------------------

$students = [];
$statusSummary = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'published' => 0, 'no_mark' => 0];
$filledMarksCount = 0;
$lockedRowsCount = 0;
$lastSavedAt = null;
if ($semesterId && $selectedCourseId) {
    $sql = "SELECT s.id, s.first_name, s.last_name, s.student_id AS reg_no,
                   s.level_year, p.program_name,
                   r.assignment_marks AS cw_marks,
                   r.final_exam_marks AS exam_marks,
                   r.total_marks,
                   r.status
            FROM course_registrations cr
            INNER JOIN students s ON cr.student_id = s.id
            LEFT JOIN programs p ON s.program_id = p.id
            LEFT JOIN results r ON r.student_id = cr.student_id
                              AND r.course_id = cr.course_id
                              AND r.semester_id = cr.semester_id
            WHERE cr.semester_id = :semester_id
              AND cr.course_id   = :course_id
              AND cr.status IN ('pending', 'approved', 'registered', 'submitted')
            ORDER BY s.last_name, s.first_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => $semesterId,
        'course_id'   => $selectedCourseId,
    ]);
    $students = $stmt->fetchAll();

    // Fallback: keep lecturer's previously saved rows visible even if
    // registration rows are missing/out-of-sync.
    if (empty($students)) {
        $fallbackSql = "SELECT s.id, s.first_name, s.last_name, s.student_id AS reg_no,
                               s.level_year, p.program_name,
                               r.assignment_marks AS cw_marks,
                               r.final_exam_marks AS exam_marks,
                               r.total_marks,
                               r.status
                        FROM results r
                        INNER JOIN students s ON r.student_id = s.id
                        LEFT JOIN programs p ON s.program_id = p.id
                        WHERE r.semester_id = :semester_id
                          AND r.course_id   = :course_id
                          AND r.entered_by  = :lecturer_id
                        ORDER BY s.last_name, s.first_name";
        $fallbackStmt = $conn->prepare($fallbackSql);
        $fallbackStmt->execute([
            'semester_id' => $semesterId,
            'course_id' => $selectedCourseId,
            'lecturer_id' => (int)$lecturerProfile['id'],
        ]);
        $students = $fallbackStmt->fetchAll();
    }

    foreach ($students as $row) {
        $status = (string)($row['status'] ?? '');
        if ($status === 'draft') $statusSummary['draft']++;
        elseif ($status === 'submitted') $statusSummary['submitted']++;
        elseif ($status === 'approved') $statusSummary['approved']++;
        elseif ($status === 'published') $statusSummary['published']++;
        else $statusSummary['no_mark']++;
        if ($row['cw_marks'] !== null && $row['cw_marks'] !== '') {
            $filledMarksCount++;
        }
        if (in_array($status, ['approved', 'published'], true)) {
            $lockedRowsCount++;
        }
    }

    $lastSavedStmt = $conn->prepare("
        SELECT MAX(updated_at)
        FROM results
        WHERE semester_id = :semester_id
          AND course_id = :course_id
          AND entered_by = :lecturer_id
    ");
    $lastSavedStmt->execute([
        'semester_id' => $semesterId,
        'course_id' => $selectedCourseId,
        'lecturer_id' => (int)$lecturerProfile['id']
    ]);
    $lastSavedAt = $lastSavedStmt->fetchColumn() ?: null;
}

$pageTitle = 'Enter Results (Coursework) - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Enter Coursework (40%)</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <?php
            $flashError = $session->getFlash('error');
            $flashSuccess = $session->getFlash('success');
            $flashInfo = $session->getFlash('info');
        ?>
        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger"><?php echo e($flashError); ?></div>
        <?php endif; ?>
        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if (!empty($flashInfo)): ?>
            <div class="alert alert-info"><?php echo e($flashInfo); ?></div>
        <?php endif; ?>

        <div class="card mb-3 marks-page-card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap mb-2">
                    <div>
                        <div class="h5 mb-1">Upload Coursework Marks</div>
                        <div class="text-muted" style="font-size:13px;">
                            Lecturer: <?php echo e($lecturerProfile['full_name'] ?? ($lecturerProfile['first_name'] ?? '')); ?>
                            <?php if (!empty($lecturerProfile['department'])): ?>
                                | Dept: <?php echo e($lecturerProfile['department']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <form method="GET" class="row marks-filter mb-3">
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
                        <label class="mb-1">Academic Year</label>
                        <select name="academic_year_id" class="form-control" onchange="this.form.submit();">
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                        <label class="mb-1">Semester</label>
                        <select name="semester_number" class="form-control" onchange="this.form.submit();">
                            <?php for ($i = 1; $i <= 2; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-lg-7 col-md-5 col-sm-12 mb-2">
                        <label class="mb-1">Course</label>
                        <select name="course_id" class="form-control" onchange="this.form.submit();">
                            <option value="">-- Select Course --</option>
                            <?php foreach ($assignedCourses as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $selectedCourseId == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($c['course_code'] . ' - ' . $c['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($assignedCourses)): ?>
                    <p class="text-muted mb-0">No courses have been assigned to you for this semester.</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to enter coursework marks.</p>
                <?php else: ?>
                    <h6 class="mb-3 text-muted">Coursework (out of 40) - <?php echo e($semesterName); ?></h6>
                    <div class="alert alert-light border mb-3" style="font-size:13px;">
                        <strong>Draft Flow:</strong> You can save partial marks and continue later. Drafts remain visible here and in
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/draft-results.php">Draft Results</a>.
                        <?php if (!empty($lastSavedAt)): ?>
                            <span class="ml-2 text-muted">Last saved: <?php echo e(date('M d, Y H:i:s', strtotime($lastSavedAt))); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap mb-3 status-badges">
                        <span class="badge badge-secondary">Draft: <?php echo (int)$statusSummary['draft']; ?></span>
                        <span class="badge badge-info">Submitted: <?php echo (int)$statusSummary['submitted']; ?></span>
                        <span class="badge badge-success">Approved: <?php echo (int)$statusSummary['approved']; ?></span>
                        <span class="badge badge-primary">Published: <?php echo (int)$statusSummary['published']; ?></span>
                        <span class="badge badge-light border">No Mark: <?php echo (int)$statusSummary['no_mark']; ?></span>
                        <span class="badge badge-dark">Filled CW: <?php echo (int)$filledMarksCount; ?></span>
                        <span class="badge badge-warning text-dark">Locked: <?php echo (int)$lockedRowsCount; ?></span>
                    </div>

                    <?php if (empty($students)): ?>
                        <div class="alert alert-warning mb-0">
                            No student marks were found for this course and semester.
                            If students are expected, confirm course registration and assignment mappings.
                        </div>
                    <?php else: ?>
                        <div class="student-search-bar mb-3">
                            <div class="student-search-input-wrap">
                                <i class="fas fa-search student-search-icon"></i>
                                <input type="text" class="form-control student-search-input" data-student-search-input="enter-results-table" placeholder="Search student name, reg number, mark, or status">
                            </div>
                            <div class="student-search-meta">
                                Showing <span data-student-search-count="enter-results-table"><?php echo count($students); ?></span> of <?php echo count($students); ?> students
                            </div>
                        </div>
                        <form method="POST" autocomplete="off" data-lpignore="true">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="academic_year_id" value="<?php echo $selectedAcademicYearId; ?>">
                            <input type="hidden" name="semester_number" value="<?php echo $selectedSemesterNumber; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                            <div class="table-responsive">
                                <table class="table table-sm table-hover marks-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student Name</th>
                                            <th>Reg. No.</th>
                                            <th class="text-center">CW Mark /40</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($students as $s): ?>
                                            <tr class="<?php echo $s['cw_marks'] !== null ? 'has-cw-row' : ''; ?>" data-student-search-row="enter-results-table">
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                                <td><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $s)); ?></td>
                                                <td class="text-center" style="max-width:140px;">
                                                    <input type="number" name="cw[<?php echo $s['id']; ?>]" class="form-control form-control-sm text-center" min="0" max="40" step="0.01" value="<?php echo $s['cw_marks'] !== null ? htmlspecialchars($s['cw_marks']) : ''; ?>" <?php echo in_array((string)($s['status'] ?? ''), ['approved','published'], true) ? 'readonly' : ''; ?> />
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($s['status'] === 'submitted'): ?>
                                                        <span class="badge badge-info">Submitted</span>
                                                    <?php elseif ($s['status'] === 'approved'): ?>
                                                        <span class="badge badge-success">Approved</span>
                                                    <?php elseif ($s['status'] === 'published'): ?>
                                                        <span class="badge badge-primary">Published</span>
                                                    <?php elseif ($s['status'] === 'draft' && $s['cw_marks'] !== null): ?>
                                                        <span class="badge badge-secondary">Draft</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 result-actions">
                                <button type="submit" name="save_draft" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-save mr-1"></i> Save Draft (Partial Allowed)
                                </button>
                                <button type="submit" name="submit_results" class="btn btn-primary btn-sm" onclick="return confirm('Submit saved coursework marks for approval? You cannot edit after approval/publication.');">
                                    <i class="fas fa-paper-plane mr-1"></i> Submit Saved Marks for Approval
                                </button>
                                <p class="text-muted mt-2 mb-0" style="font-size:12px;">
                                    Note: You can only enter Coursework (CW) marks out of 40. Exam marks (60%), final total and grade will be entered and approved by the examiner/auditor. You cannot modify exam or final marks.
                                </p>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5>Policy</h5>
                <p class="text-muted" style="font-size:13px;">
                    This page strictly enforces the 40% / 60% policy:
                    lecturers record only coursework marks (40%), while exam marks and final grades (60% + total) are reserved for examiners/auditors via the admin results module.
                </p>
            </div>
        </div>
    </div>
</div>

<style>
.marks-page-card {
    border-radius: 14px;
    border-color: #e5e7eb;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}
.marks-page-card .card-body {
    padding: 22px 22px 18px;
}
.marks-filter label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
}
.marks-filter .form-control {
    border-radius: 10px;
    border-color: #d7e0ea;
    height: 40px;
}
.status-badges {
    gap: 8px;
}
.student-search-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
}
.student-search-input-wrap {
    position: relative;
    flex: 1 1 320px;
    max-width: 520px;
}
.student-search-input {
    padding-left: 38px;
    border-radius: 10px;
}
.student-search-icon {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
}
.student-search-meta {
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 600;
}
.status-badges .badge {
    font-size: 0.72rem;
    padding: 0.45rem 0.7rem;
    border-radius: 8px;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.has-cw-row {
    background: #f7fbff;
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
    padding: 0.7rem 0.65rem;
}

.marks-table input.form-control {
    border-radius: 8px;
    font-weight: 700;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    height: 36px;
    max-width: 120px;
    margin: 0 auto;
}
.marks-table tbody tr:nth-child(even) {
    background: #f9fbff;
}
.marks-table tbody tr:hover {
    background: #eef6ff;
}

.result-actions {
    position: sticky;
    bottom: 8px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
}

html[data-theme='dark'] .table.table-sm thead th {
    background: #1e293b;
    color: #f8fafc;
    border-color: #334155;
}

html[data-theme='dark'] .table.table-sm tbody td {
    color: #e5e7eb;
    border-color: #334155;
}

html[data-theme='dark'] .table.table-hover tbody tr:hover {
    background: #1e293b !important;
}

html[data-theme='dark'] .has-cw-row {
    background: #132235;
}

html[data-theme='dark'] .marks-page-card {
    border-color: #1f2937;
    box-shadow: 0 8px 20px rgba(2, 6, 23, 0.45);
}

html[data-theme='dark'] .table.table-sm .text-muted {
    color: #cbd5e1 !important;
}

html[data-theme='dark'] .table.table-sm input.form-control {
    background: #0b1220;
    border-color: #475569;
    color: #e2e8f0;
}

html[data-theme='dark'] .table.table-sm input.form-control[readonly] {
    background: #1e293b;
    color: #dbeafe;
    border-color: #64748b;
    opacity: 1;
}

html[data-theme='dark'] .result-actions {
    background: #0f172a;
    border-color: #334155;
    box-shadow: 0 2px 10px rgba(2, 6, 23, 0.45);
}
html[data-theme='dark'] .marks-filter label {
    color: #cbd5e1;
}
html[data-theme='dark'] .marks-filter .form-control {
    background: #0b1220;
    border-color: #334155;
    color: #e2e8f0;
}
html[data-theme='dark'] .student-search-icon,
html[data-theme='dark'] .student-search-meta {
    color: #94a3b8;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-student-search-input]').forEach(function (input) {
        var tableId = input.getAttribute('data-student-search-input');
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-student-search-row="' + tableId + '"]'));
        var countNode = document.querySelector('[data-student-search-count="' + tableId + '"]');
        var applyFilter = function () {
            var query = input.value.trim().toLowerCase();
            var visible = 0;
            rows.forEach(function (row) {
                var matches = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
                row.style.display = matches ? '' : 'none';
                if (matches) {
                    visible++;
                }
            });
            if (countNode) {
                countNode.textContent = String(visible);
            }
        };
        input.addEventListener('input', applyFilter);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
