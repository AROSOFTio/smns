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

// Map AY + semester number to semester row
$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

// Courses that have submitted/draft results in this semester
$coursesWithResults = [];
if ($semesterId) {
    $sql = "SELECT DISTINCT c.id, c.course_code, c.course_name
            FROM results r
            INNER JOIN courses c ON r.course_id = c.id
            WHERE r.semester_id = :semester_id
              AND r.status IN ('submitted', 'approved')
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['semester_id' => $semesterId]);
    $coursesWithResults = $stmt->fetchAll();
}

$selectedCourseId = isset($_REQUEST['course_id']) ? (int) $_REQUEST['course_id'] : 0;

// Ensure selected course is valid
if ($selectedCourseId && !empty($coursesWithResults)) {
    $validIds = array_column($coursesWithResults, 'id');
    if (!in_array($selectedCourseId, $validIds, true)) {
        $selectedCourseId = 0;
    }
}

// ---------------------------------------------------------------------
// Handle POST: Enter Exam Marks (60%) - Provisional Save
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam']) && $semesterId) {
    $selectedCourseId = (int) ($_POST['course_id'] ?? 0);

    if ($selectedCourseId <= 0) {
        $session->setFlash('error', 'Invalid course selection.');
        header('Location: submitted.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber);
        exit;
    }

    $examData = $_POST['exam']; // [result_id => exam_mark]
    $now      = date('Y-m-d H:i:s');
    $adminId  = $adminProfile['id'] ?? null;

    if (!$adminId) {
        $session->setFlash('error', 'Admin profile not found. Cannot save results.');
        header('Location: submitted.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&course_id=' . $selectedCourseId);
        exit;
    }

    $conn->beginTransaction();

    try {
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

            // Update only exam mark; triggers handle total + grade. Keep status as approved (provisional).
            $updateSql = "UPDATE results
                          SET final_exam_marks = :exam,
                              approved_by      = :admin_id,
                              approved_date    = :approved_date,
                              status           = 'approved',
                              updated_at       = :updated_at
                          WHERE id = :id
                            AND semester_id = :semester_id
                            AND course_id   = :course_id";
            $u = $conn->prepare($updateSql);
            $u->execute([
                'exam'         => $examMark,
                'admin_id'     => $adminId,
                'approved_date'=> $now,
                'updated_at'   => $now,
                'id'           => $resultId,
                'semester_id'  => $semesterId,
                'course_id'    => $selectedCourseId,
            ]);
        }

    $conn->commit();
    $session->setFlash('success', 'Exam marks saved provisionally. Review and publish from Provisional Results.');

        header('Location: submitted.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&course_id=' . $selectedCourseId);
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

// Notifications for header bell
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Results - Submitted / Exam Entry - ' . APP_NAME;
include '../../../includes/header.php';
?>

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
                        <?php foreach ($coursesWithResults as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedCourseId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo e($c['course_code'] . ' - ' . $c['course_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($coursesWithResults)): ?>
                    <p class="text-muted mb-0">No submitted results found for this semester yet.</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to enter exam marks and approve results.</p>
                <?php else: ?>
                    <h5 class="mb-3">Exam Marks (60%) &amp; Approval - <?php echo e($semesterName); ?></h5>

                    <?php if (empty($results)): ?>
                        <p class="text-muted mb-0">No results found for the selected course.</p>
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
                                            <th>Lecturer CW (40%)</th>
                                            <th>Exam (0–60)</th>
                                            <th>Total / Grade</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($results as $r): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                                <td><?php echo e($r['reg_no']); ?></td>
                                                <td><?php echo e($r['program_name'] ?? '-'); ?></td>
                                                <td><?php echo 'Year ' . e($r['level_year'] ?? '-'); ?></td>
                                                <td style="font-size:12px;" data-cw="<?php echo $r['assignment_marks'] !== null ? htmlspecialchars($r['assignment_marks']) : '0'; ?>">
                                                    <?php echo $r['assignment_marks'] !== null ? number_format($r['assignment_marks'], 2) : '-'; ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        By: <?php echo e(trim(($r['lecturer_first_name'] ?? '') . ' ' . ($r['lecturer_last_name'] ?? '')) ?: 'N/A'); ?>
                                                    </small>
                                                </td>
                                                <td style="max-width:120px;">
                                                    <input type="number" name="exam[<?php echo $r['result_id']; ?>]" class="form-control form-control-sm exam-input" min="0" max="60" step="0.01" value="<?php echo $r['final_exam_marks'] !== null ? htmlspecialchars($r['final_exam_marks']) : ''; ?>" />
                                                </td>
                                                <td style="font-size:12px;" class="total-grade-cell" data-initial-total="<?php echo $r['total_marks'] !== null ? htmlspecialchars($r['total_marks']) : ''; ?>">
                                                    Total: <span class="total-value"><?php echo $r['total_marks'] !== null ? number_format($r['total_marks'], 2) : '-'; ?></span><br>
                                                    Grade: <span class="grade-value"><?php echo $r['grade'] !== null ? e($r['grade']) : '-'; ?></span>
                                                </td>
                                                <td>
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
                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Save exam marks provisionally for this course?');">Save Exam Marks (Provisional)</button>
                                <p class="text-muted mt-2" style="font-size:12px;">
                                    Policy: Lecturers provide only coursework (40%). Admins/examiners enter exam marks (60%) here.
                                    Saving here stores results as provisional (approved internally). Students will see results only after they are published.
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
    var table = document.querySelector('.table.table-sm.table-hover');
    if (!table) return;

    function calculateGrade(total) {
        if (isNaN(total)) return '-';
        if (total >= 80) return 'A';
        if (total >= 75) return 'B+';
        if (total >= 70) return 'B';
        if (total >= 65) return 'C+';
        if (total >= 60) return 'C';
        if (total >= 55) return 'D+';
        if (total >= 50) return 'D';
        if (total >= 0)  return 'F';
        return '-';
    }

    function updateRowTotal(row) {
        var cwCell    = row.querySelector('[data-cw]');
        var examInput = row.querySelector('.exam-input');
        var totalCell = row.querySelector('.total-grade-cell .total-value');
        var gradeCell = row.querySelector('.total-grade-cell .grade-value');
        if (!cwCell || !examInput || !totalCell || !gradeCell) return;

        var cw = parseFloat(cwCell.getAttribute('data-cw')) || 0;
        var exam = parseFloat(examInput.value);
        if (isNaN(exam)) {
            // If no exam entered, fall back to DB total (if any) already rendered
            var initial = row.querySelector('.total-grade-cell').getAttribute('data-initial-total');
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
