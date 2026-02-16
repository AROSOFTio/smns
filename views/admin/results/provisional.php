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
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser  = $auth->getCurrentUser();
$adminProfile = $currentUser['profile'] ?? [];

$db   = new Database();
$conn = $db->getConnection();

// Filters: Academic Year, Semester, Course
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_REQUEST['academic_year_id']) ? (int) $_REQUEST['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_REQUEST['semester_number']) ? (int) $_REQUEST['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);

$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

// Courses that have approved (but not yet published) results in this semester
$coursesWithResults = [];
if ($semesterId) {
    $sql = "SELECT DISTINCT c.id, c.course_code, c.course_name
            FROM results r
            INNER JOIN courses c ON r.course_id = c.id
            WHERE r.semester_id = :semester_id
              AND r.status = 'approved'
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['semester_id' => $semesterId]);
    $coursesWithResults = $stmt->fetchAll();
}

$selectedCourseId = isset($_REQUEST['course_id']) ? (int) $_REQUEST['course_id'] : 0;
if ($selectedCourseId && !empty($coursesWithResults)) {
    $validIds = array_column($coursesWithResults, 'id');
    if (!in_array($selectedCourseId, $validIds, true)) {
        $selectedCourseId = 0;
    }
}

// Handle POST: Publish selected course results to students (status => published)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish']) && $semesterId) {
    $selectedCourseId = (int) ($_POST['course_id'] ?? 0);

    if ($selectedCourseId <= 0) {
        $session->setFlash('error', 'Invalid course selection for publishing.');
        header('Location: provisional.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    try {
        $conn->beginTransaction();

        $updateSql = "UPDATE results
                      SET status = 'published',
                          published_date = :published_date,
                          updated_at = :updated_at
                      WHERE semester_id = :semester_id
                        AND course_id   = :course_id
                        AND status      = 'approved'";
        $u = $conn->prepare($updateSql);
        $u->execute([
            'published_date' => $now,
            'updated_at'     => $now,
            'semester_id'    => $semesterId,
            'course_id'      => $selectedCourseId,
        ]);

        $conn->commit();
        $session->setFlash('success', 'Results published to student portals successfully.');

        header('Location: provisional.php?academic_year_id=' . $selectedAcademicYearId . '&semester_number=' . $selectedSemesterNumber . '&course_id=' . $selectedCourseId);
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
            ORDER BY s.last_name, s.first_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'semester_id' => $semesterId,
        'course_id'   => $selectedCourseId,
    ]);
    $results = $stmt->fetchAll();
}

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Provisional Results - Review & Publish - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent" style="max-width:100vw; overflow-x:hidden;">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Results Management - Provisional Review</h4>
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
            <div class="card-body" style="overflow-x:hidden;">
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
                    <p class="text-muted mb-0">No provisional (approved) results found for this semester.</p>
                <?php elseif (!$selectedCourseId): ?>
                    <p class="text-muted mb-0">Please select a course to review provisional results.</p>
                <?php else: ?>
                    <h5 class="mb-3">Provisional Results - <?php echo e($semesterName); ?></h5>

                    <?php if (empty($results)): ?>
                        <p class="text-muted mb-0">No approved results found for the selected course.</p>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="academic_year_id" value="<?php echo $selectedAcademicYearId; ?>">
                            <input type="hidden" name="semester_number" value="<?php echo $selectedSemesterNumber; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                            <div class="table-responsive" style="overflow-x:auto;">
                                <table class="table table-sm table-hover" style="table-layout:fixed; width:100%; word-wrap:break-word;">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;">#</th>
                                            <th style="min-width:120px;">Name</th>
                                            <th style="min-width:90px;">Reg #</th>
                                            <th style="min-width:110px;">Program</th>
                                            <th style="width:70px;">Year</th>
                                            <th style="width:110px;">Coursework (40%)</th>
                                            <th style="width:100px;">Exam (60%)</th>
                                            <th style="width:90px;">Total</th>
                                            <th style="width:80px;">Grade</th>
                                            <th style="width:80px;">Status</th>
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
                                                <td><?php echo $r['assignment_marks'] !== null ? number_format($r['assignment_marks'], 2) : '-'; ?></td>
                                                <td><?php echo $r['final_exam_marks'] !== null ? number_format($r['final_exam_marks'], 2) : '-'; ?></td>
                                                <td><?php echo $r['total_marks'] !== null ? number_format($r['total_marks'], 2) : '-'; ?></td>
                                                <td><?php echo $r['grade'] !== null ? e($r['grade']) : '-'; ?></td>
                                                <td><span class="badge badge-success">Approved</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3">
                                <button type="submit" name="publish" value="1" class="btn btn-primary btn-sm" onclick="return confirm('Publish these approved results to the student portals?');">Publish to Student Portal</button>
                                <p class="text-muted mt-2" style="font-size:12px;">
                                    Publishing will make these results visible on student dashboards. Ensure all marks are correct before publishing.
                                </p>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
