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
    header('Location: ' . BASE_URL . '/views/lecturer/login.php?error=unauthorized');
    exit;
}

$currentUser     = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];

$db   = new Database();
$conn = $db->getConnection();

// ---------------------------------------------------------------------------
// Filters: Academic Year, Semester, Course (assigned to this lecturer)
// ---------------------------------------------------------------------------

$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_REQUEST['academic_year_id']) ? (int) $_REQUEST['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_REQUEST['semester_number']) ? (int) $_REQUEST['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);

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
              AND ca.status       = 'active'
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

    // Security: verify course is actually assigned to this lecturer in this semester
    if ($selectedCourseId) {
        $check = $conn->prepare('SELECT COUNT(*) FROM course_assignments WHERE lecturer_id = :lecturer AND course_id = :course AND semester_id = :semester AND status = "active"');
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
    }

    $cwData   = $_POST['cw'] ?? []; // [student_id => cw_mark]
    $now      = date('Y-m-d H:i:s');
    $isSubmit = isset($_POST['submit_results']);
    $newStatus = $isSubmit ? 'submitted' : 'draft';

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
        foreach ($cwData as $studentId => $cwMarkRaw) {
            $studentId = (int) $studentId;

            if ($studentId <= 0) {
                continue;
            }

            $cwMark = trim($cwMarkRaw) === '' ? null : (float) $cwMarkRaw;

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
            $existingStmt = $conn->prepare('SELECT id, assignment_marks, status FROM results WHERE student_id = :student AND course_id = :course AND semester_id = :semester LIMIT 1');
            $existingStmt->execute([
                'student'  => $studentId,
                'course'   => $selectedCourseId,
                'semester' => $semesterId,
            ]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
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
            
            $session->setFlash('success', 'Coursework marks submitted successfully for approval. <a href="' . BASE_URL . '/views/lecturer/draft-results.php" class="alert-link">View your submitted results</a>');
        } else {
            $session->setFlash('success', 'Coursework marks saved as draft. <a href="' . BASE_URL . '/views/lecturer/draft-results.php" class="alert-link">View all drafts</a>');
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
              AND cr.status IN ('pending', 'approved')
            ORDER BY s.last_name, s.first_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => $semesterId,
        'course_id'   => $selectedCourseId,
    ]);
    $students = $stmt->fetchAll();
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
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-3">
                    <label class="mr-2">Academic Year:</label>
                    <select name="academic_year_id" class="form-control mr-2" onchange="this.form.submit();">
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Semester:</label>
                    <select name="semester_number" class="form-control mr-2" onchange="this.form.submit();">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>

                    <label class="ml-3 mr-2">Course:</label>
                    <select name="course_id" class="form-control mr-2" onchange="this.form.submit();">
                        <option value="">-- Select Course --</option>
                        <?php foreach ($assignedCourses as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedCourseId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo e($c['course_code'] . ' - ' . $c['course_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($assignedCourses)): ?>
                    <p class="text-muted mb-0">No courses have been assigned to you for this semester.</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to enter coursework marks.</p>
                <?php else: ?>
                    <h5 class="mb-3">Coursework (out of 40) - <?php echo e($semesterName); ?></h5>

                    <?php if (empty($students)): ?>
                        <p class="text-muted mb-0">No students registered for this course yet.</p>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="academic_year_id" value="<?php echo $selectedAcademicYearId; ?>">
                            <input type="hidden" name="semester_number" value="<?php echo $selectedSemesterNumber; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Reg #</th>
                                            <th>Program</th>
                                            <th>Year</th>
                                            <th>CW (0–40)</th>
                                            <th>Status</th>
                                            <th>Exam / Final (Read-Only)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($students as $s): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                                <td><?php echo e($s['reg_no']); ?></td>
                                                <td><?php echo e($s['program_name'] ?? '-'); ?></td>
                                                <td><?php echo 'Year ' . e($s['level_year'] ?? '-'); ?></td>
                                                <td style="max-width:120px;">
                                                    <input type="number" name="cw[<?php echo $s['id']; ?>]" class="form-control form-control-sm" min="0" max="40" step="0.01" value="<?php echo $s['cw_marks'] !== null ? htmlspecialchars($s['cw_marks']) : ''; ?>" />
                                                </td>
                                                <td>
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
                                                <td style="font-size:12px;">
                                                    CW: <?php echo $s['cw_marks'] !== null ? number_format($s['cw_marks'], 2) : '-'; ?><br>
                                                    Exam: <?php echo $s['exam_marks'] !== null ? number_format($s['exam_marks'], 2) : '-'; ?><br>
                                                    Total: <?php echo $s['total_marks'] !== null ? number_format($s['total_marks'], 2) : '-'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3">
                                <button type="submit" name="save_draft" class="btn btn-secondary btn-sm">Save Draft</button>
                                <button type="submit" name="submit_results" class="btn btn-primary btn-sm" onclick="return confirm('Submit coursework marks for approval? You cannot edit after approval/publication.');">Submit for Approval</button>
                                <p class="text-muted mt-2" style="font-size:12px;">
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

<?php include '../../includes/footer.php'; ?>
