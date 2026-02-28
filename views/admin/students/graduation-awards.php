<?php
/**
 * Graduation and Awards Tracking - Admin
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();

$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId <= 0) {
    header('Location: list.php?error=invalid_id');
    exit;
}

$studentStmt = $conn->prepare("
    SELECT s.*, p.program_code, p.program_name
    FROM students s
    LEFT JOIN programs p ON p.id = s.program_id
    WHERE s.id = :student_id
    LIMIT 1
");
$studentStmt->execute(['student_id' => $studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($student)) {
    header('Location: list.php?error=student_not_found');
    exit;
}

$semesters = [];
try {
    $semesters = $conn->query("
        SELECT s.id, s.semester_name, s.semester_number, ay.year_name
        FROM semesters s
        INNER JOIN academic_years ay ON ay.id = s.academic_year_id
        ORDER BY ay.start_date DESC, s.semester_number DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $semesters = [];
}

$calculateCgpa = static function (PDO $conn, int $studentId): ?float {
    try {
        $gpaStmt = $conn->prepare("
            SELECT cumulative_gpa
            FROM student_gpas
            WHERE student_id = :student_id
            ORDER BY semester_id DESC, id DESC
            LIMIT 1
        ");
        $gpaStmt->execute(['student_id' => $studentId]);
        $cached = $gpaStmt->fetchColumn();
        if ($cached !== false && $cached !== null && $cached !== '' && is_numeric($cached)) {
            return round((float)$cached, 2);
        }
    } catch (Exception $e) {
        // Continue to fallback.
    }

    try {
        $fallbackStmt = $conn->prepare("
            SELECT
                COALESCE(SUM(r.grade_points * c.credit_hours), 0) AS total_points,
                COALESCE(SUM(c.credit_hours), 0) AS total_credits
            FROM results r
            INNER JOIN courses c ON c.id = r.course_id
            WHERE r.student_id = :student_id
              AND r.status = 'published'
              AND r.grade_points IS NOT NULL
        ");
        $fallbackStmt->execute(['student_id' => $studentId]);
        $row = $fallbackStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $credits = (float)($row['total_credits'] ?? 0);
        if ($credits <= 0) {
            return null;
        }
        $points = (float)($row['total_points'] ?? 0);
        return round($points / $credits, 2);
    } catch (Exception $e) {
        return null;
    }
};

$latestCgpa = $calculateCgpa($conn, $studentId);

$awards = [];
try {
    $awardsStmt = $conn->prepare("
        SELECT
            a.*,
            u.username AS approved_by_username
        FROM student_graduation_awards a
        LEFT JOIN users u ON u.id = a.approved_by_user_id
        WHERE a.student_id = :student_id
        ORDER BY a.award_date DESC, a.id DESC
    ");
    $awardsStmt->execute(['student_id' => $studentId]);
    $awards = $awardsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $awards = [];
}

// Export awards register for this student.
$export = strtolower(trim((string)($_GET['export'] ?? '')));
if ($export === 'csv' || $export === 'excel') {
    $isExcel = ($export === 'excel');
    $filename = 'student_graduation_awards_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($student['student_id'] ?? ('student_' . $studentId))) . '_' . date('Ymd_His') . ($isExcel ? '.xls' : '.csv');
    if ($isExcel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', (string)($student['student_id'] ?? '')]);
    fputcsv($out, ['Student Name', trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''))]);
    fputcsv($out, ['Program', trim((string)($student['program_code'] ?? '') . ' - ' . (string)($student['program_name'] ?? ''))]);
    fputcsv($out, ['Status', (string)($student['status'] ?? '')]);
    fputcsv($out, ['Graduation Date', (string)($student['graduation_date'] ?? '')]);
    fputcsv($out, ['Graduation Award', (string)($student['graduation_award_title'] ?? '')]);
    fputcsv($out, ['Graduation Classification', (string)($student['graduation_classification'] ?? '')]);
    fputcsv($out, []);
    fputcsv($out, ['Award Date', 'Award Type', 'Award Title', 'Classification', 'CGPA', 'Approved By', 'Notes']);
    foreach ($awards as $award) {
        fputcsv($out, [
            (string)($award['award_date'] ?? ''),
            (string)($award['award_type'] ?? ''),
            (string)($award['award_title'] ?? ''),
            (string)($award['classification'] ?? ''),
            (string)($award['cgpa_at_award'] ?? ''),
            (string)($award['approved_by_username'] ?? ''),
            (string)($award['notes'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!Security::verifyCSRFToken($csrfToken)) {
        $session->setFlash('error', 'Invalid CSRF token. Please refresh and try again.');
        header('Location: graduation-awards.php?id=' . $studentId);
        exit;
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'save_profile') {
        $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
        $graduationDate = trim((string)($_POST['graduation_date'] ?? ''));
        $graduationSemesterId = (int)($_POST['graduation_semester_id'] ?? 0);
        $graduationAwardTitle = trim((string)($_POST['graduation_award_title'] ?? ''));
        $graduationClassification = trim((string)($_POST['graduation_classification'] ?? ''));

        $allowedStatus = ['active', 'graduated', 'withdrawn', 'suspended', 'inactive'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'active';
        }

        if ($graduationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $graduationDate)) {
            $session->setFlash('error', 'Graduation date must use YYYY-MM-DD format.');
            header('Location: graduation-awards.php?id=' . $studentId);
            exit;
        }

        $profileUpdate = $conn->prepare("
            UPDATE students
            SET
                status = :status,
                graduation_date = :graduation_date,
                graduation_semester_id = :graduation_semester_id,
                graduation_award_title = :graduation_award_title,
                graduation_classification = :graduation_classification,
                updated_at = NOW()
            WHERE id = :student_id
            LIMIT 1
        ");
        $profileUpdate->execute([
            'status' => $status,
            'graduation_date' => ($graduationDate !== '' ? $graduationDate : null),
            'graduation_semester_id' => ($graduationSemesterId > 0 ? $graduationSemesterId : null),
            'graduation_award_title' => ($graduationAwardTitle !== '' ? $graduationAwardTitle : null),
            'graduation_classification' => ($graduationClassification !== '' ? $graduationClassification : null),
            'student_id' => $studentId,
        ]);

        try {
            $logger = new Logger();
            $logger->log(
                (int)($currentUser['id'] ?? 0),
                'update_graduation_profile',
                'students',
                'Updated graduation profile for student ' . (string)($student['student_id'] ?? ('#' . $studentId)),
                [
                    'part' => 'graduation_awards',
                    'where' => '/views/admin/students/graduation-awards.php',
                    'target' => 'students#' . $studentId
                ]
            );
        } catch (Exception $e) {
            // Non-fatal.
        }

        $session->setFlash('success', 'Graduation profile updated.');
        header('Location: graduation-awards.php?id=' . $studentId);
        exit;
    }

    if ($action === 'add_award') {
        $awardType = strtolower(trim((string)($_POST['award_type'] ?? 'degree')));
        $awardTitle = trim((string)($_POST['award_title'] ?? ''));
        $classification = trim((string)($_POST['classification'] ?? ''));
        $awardDate = trim((string)($_POST['award_date'] ?? ''));
        $cgpaAtAward = trim((string)($_POST['cgpa_at_award'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $allowedTypes = ['degree', 'diploma', 'certificate', 'classification', 'honours', 'other'];
        if (!in_array($awardType, $allowedTypes, true)) {
            $awardType = 'degree';
        }
        if ($awardTitle === '' || $awardDate === '') {
            $session->setFlash('error', 'Award title and award date are required.');
            header('Location: graduation-awards.php?id=' . $studentId);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $awardDate)) {
            $session->setFlash('error', 'Award date must use YYYY-MM-DD format.');
            header('Location: graduation-awards.php?id=' . $studentId);
            exit;
        }

        $cgpaValue = null;
        if ($cgpaAtAward !== '') {
            if (!is_numeric($cgpaAtAward)) {
                $session->setFlash('error', 'CGPA at award must be numeric.');
                header('Location: graduation-awards.php?id=' . $studentId);
                exit;
            }
            $cgpaValue = round((float)$cgpaAtAward, 2);
        }

        $insertAward = $conn->prepare("
            INSERT INTO student_graduation_awards
                (student_id, award_type, award_title, classification, cgpa_at_award, award_date, approved_by_user_id, notes)
            VALUES
                (:student_id, :award_type, :award_title, :classification, :cgpa_at_award, :award_date, :approved_by_user_id, :notes)
        ");
        $insertAward->execute([
            'student_id' => $studentId,
            'award_type' => $awardType,
            'award_title' => $awardTitle,
            'classification' => ($classification !== '' ? $classification : null),
            'cgpa_at_award' => $cgpaValue,
            'award_date' => $awardDate,
            'approved_by_user_id' => (int)($currentUser['id'] ?? 0),
            'notes' => ($notes !== '' ? $notes : null),
        ]);

        try {
            $logger = new Logger();
            $logger->log(
                (int)($currentUser['id'] ?? 0),
                'create_graduation_award',
                'students',
                'Added graduation/award record "' . $awardTitle . '" for student ' . (string)($student['student_id'] ?? ('#' . $studentId)),
                [
                    'part' => 'graduation_awards',
                    'where' => '/views/admin/students/graduation-awards.php',
                    'target' => 'students#' . $studentId
                ]
            );
        } catch (Exception $e) {
            // Non-fatal.
        }

        $session->setFlash('success', 'Award record added.');
        header('Location: graduation-awards.php?id=' . $studentId);
        exit;
    }

    if ($action === 'delete_award') {
        $awardId = (int)($_POST['award_id'] ?? 0);
        if ($awardId > 0) {
            $deleteStmt = $conn->prepare("
                DELETE FROM student_graduation_awards
                WHERE id = :award_id AND student_id = :student_id
                LIMIT 1
            ");
            $deleteStmt->execute([
                'award_id' => $awardId,
                'student_id' => $studentId,
            ]);

            if ($deleteStmt->rowCount() > 0) {
                try {
                    $logger = new Logger();
                    $logger->log(
                        (int)($currentUser['id'] ?? 0),
                        'delete_graduation_award',
                        'students',
                        'Deleted graduation/award record #' . $awardId . ' for student ' . (string)($student['student_id'] ?? ('#' . $studentId)),
                        [
                            'part' => 'graduation_awards',
                            'where' => '/views/admin/students/graduation-awards.php',
                            'target' => 'students#' . $studentId
                        ]
                    );
                } catch (Exception $e) {
                    // Non-fatal.
                }
                $session->setFlash('success', 'Award record deleted.');
            } else {
                $session->setFlash('error', 'Award record not found.');
            }
        } else {
            $session->setFlash('error', 'Invalid award ID.');
        }
        header('Location: graduation-awards.php?id=' . $studentId);
        exit;
    }
}

$pageTitle = 'Graduation & Awards - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<style>
.ga-card { border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07); }
.ga-card .card-header { border-bottom: 1px solid #e2e8f0; }
.ga-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
.ga-summary-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
.ga-summary-item .label { font-size: 0.74rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; margin-bottom: 2px; }
.ga-summary-item .value { font-size: 0.94rem; color: #0f172a; font-weight: 700; }
html[data-theme='dark'] .ga-card { border-color: #243047; box-shadow: 0 8px 24px rgba(2, 6, 23, 0.55); }
html[data-theme='dark'] .ga-card .card-header { border-color: #243047; }
html[data-theme='dark'] .ga-summary-item { background: #111a2b; border-color: #243047; }
html[data-theme='dark'] .ga-summary-item .label { color: #93c5fd; }
html[data-theme='dark'] .ga-summary-item .value { color: #e2e8f0; }
</style>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Graduation & Awards Tracking</h4>
        </div>
        <div class="topbar-right">
            <a href="view.php?id=<?php echo (int)$studentId; ?>" class="btn btn-info btn-sm mr-2">
                <i class="fas fa-user"></i> Student Profile
            </a>
            <a href="?id=<?php echo (int)$studentId; ?>&export=csv" class="btn btn-outline-secondary btn-sm mr-2">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a href="?id=<?php echo (int)$studentId; ?>&export=excel" class="btn btn-outline-secondary btn-sm mr-2">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area p-4">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="ga-card card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-user-graduate"></i> Student Graduation Snapshot</h5>
            </div>
            <div class="card-body">
                <div class="ga-summary">
                    <div class="ga-summary-item">
                        <div class="label">Student</div>
                        <div class="value"><?php echo e(trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''))); ?></div>
                    </div>
                    <div class="ga-summary-item">
                        <div class="label">Student ID</div>
                        <div class="value"><?php echo e((string)($student['student_id'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="ga-summary-item">
                        <div class="label">Program</div>
                        <div class="value"><?php echo e(trim((string)($student['program_code'] ?? '') . ' - ' . (string)($student['program_name'] ?? ''))); ?></div>
                    </div>
                    <div class="ga-summary-item">
                        <div class="label">Current Status</div>
                        <div class="value"><?php echo e(ucfirst((string)($student['status'] ?? 'active'))); ?></div>
                    </div>
                    <div class="ga-summary-item">
                        <div class="label">Latest CGPA</div>
                        <div class="value"><?php echo $latestCgpa !== null ? e(number_format((float)$latestCgpa, 2)) : 'N/A'; ?></div>
                    </div>
                    <div class="ga-summary-item">
                        <div class="label">Awards Recorded</div>
                        <div class="value"><?php echo e((string)count($awards)); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="ga-card card mb-3">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-certificate"></i> Graduation Profile</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="save_profile">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control form-control-sm">
                                    <?php $statusVal = strtolower((string)($student['status'] ?? 'active')); ?>
                                    <option value="active" <?php echo $statusVal === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="graduated" <?php echo $statusVal === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                                    <option value="withdrawn" <?php echo $statusVal === 'withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                                    <option value="suspended" <?php echo $statusVal === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="inactive" <?php echo $statusVal === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Graduation Date</label>
                                <input type="date" name="graduation_date" class="form-control form-control-sm" value="<?php echo e((string)($student['graduation_date'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label>Graduation Semester</label>
                                <select name="graduation_semester_id" class="form-control form-control-sm">
                                    <option value="">Select Semester</option>
                                    <?php foreach ($semesters as $semester): ?>
                                        <option value="<?php echo (int)$semester['id']; ?>" <?php echo ((int)($student['graduation_semester_id'] ?? 0) === (int)$semester['id']) ? 'selected' : ''; ?>>
                                            <?php echo e((string)$semester['year_name'] . ' - ' . (string)$semester['semester_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Award Title</label>
                                <input type="text" name="graduation_award_title" class="form-control form-control-sm" maxlength="200" value="<?php echo e((string)($student['graduation_award_title'] ?? '')); ?>" placeholder="e.g., Bachelor of Theology">
                            </div>
                            <div class="form-group">
                                <label>Classification</label>
                                <input type="text" name="graduation_classification" class="form-control form-control-sm" maxlength="100" value="<?php echo e((string)($student['graduation_classification'] ?? '')); ?>" placeholder="e.g., Second Class Upper">
                            </div>
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="fas fa-save"></i> Save Graduation Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="ga-card card mb-3">
                    <div class="card-header bg-dark text-white">
                        <h6 class="mb-0"><i class="fas fa-plus-circle"></i> Add Award Record</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="add_award">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Award Type</label>
                                    <select name="award_type" class="form-control form-control-sm">
                                        <option value="degree">Degree</option>
                                        <option value="diploma">Diploma</option>
                                        <option value="certificate">Certificate</option>
                                        <option value="classification">Classification</option>
                                        <option value="honours">Honours</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-8">
                                    <label>Award Title <span class="text-danger">*</span></label>
                                    <input type="text" name="award_title" class="form-control form-control-sm" maxlength="200" required placeholder="e.g., Bachelor of Theology">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Award Date <span class="text-danger">*</span></label>
                                    <input type="date" name="award_date" class="form-control form-control-sm" required value="<?php echo e(date('Y-m-d')); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Classification</label>
                                    <input type="text" name="classification" class="form-control form-control-sm" maxlength="100" placeholder="e.g., First Class">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>CGPA at Award</label>
                                    <input type="number" step="0.01" min="0" max="5" name="cgpa_at_award" class="form-control form-control-sm" value="<?php echo $latestCgpa !== null ? e(number_format((float)$latestCgpa, 2, '.', '')) : ''; ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Optional remarks about this award record."></textarea>
                            </div>
                            <button type="submit" class="btn btn-dark btn-sm">
                                <i class="fas fa-plus"></i> Add Award
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="ga-card card">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0"><i class="fas fa-list"></i> Award History</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($awards)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-folder-open fa-2x mb-2"></i>
                        <p class="mb-0">No graduation/award records found for this student.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Class</th>
                                    <th>CGPA</th>
                                    <th>Approved By</th>
                                    <th>Notes</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($awards as $award): ?>
                                    <tr>
                                        <td><?php echo e((string)($award['award_date'] ?? '')); ?></td>
                                        <td><?php echo e(ucfirst((string)($award['award_type'] ?? ''))); ?></td>
                                        <td><?php echo e((string)($award['award_title'] ?? '')); ?></td>
                                        <td><?php echo e((string)($award['classification'] ?? '-')); ?></td>
                                        <td><?php echo ($award['cgpa_at_award'] !== null && $award['cgpa_at_award'] !== '') ? e(number_format((float)$award['cgpa_at_award'], 2)) : '-'; ?></td>
                                        <td><?php echo e((string)($award['approved_by_username'] ?? '-')); ?></td>
                                        <td><?php echo e((string)($award['notes'] ?? '-')); ?></td>
                                        <td class="text-center">
                                            <form method="POST" onsubmit="return confirm('Delete this award record?');" style="display:inline-block;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_award">
                                                <input type="hidden" name="award_id" value="<?php echo (int)$award['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
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
</body>
</html>
