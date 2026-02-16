<?php
/**
 * Admin Report - Course roster for a semester
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}
$db = new Database();
$conn = $db->getConnection();

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$levelYear = isset($_GET['level_year']) ? trim($_GET['level_year']) : '';
$export = $_GET['export'] ?? '';

if (!$courseId || !$semesterId) {
    $session->setFlash('error', 'Missing course or semester.');
    header('Location: index.php?report=staff');
    exit;
}

// Course info
$cstmt = $conn->prepare("SELECT course_code, course_name FROM courses WHERE id = :id");
$cstmt->execute(['id' => $courseId]);
$course = $cstmt->fetch(PDO::FETCH_ASSOC);

// Roster (apply optional filters)
$rstmt = $conn->prepare("SELECT s.student_id, s.first_name, s.last_name, cr.status, cr.registration_date
                         FROM course_registrations cr
                         JOIN students s ON cr.student_id = s.id
                         WHERE cr.course_id = :course_id AND cr.semester_id = :semester_id
                           AND (:levelYear = '' OR s.level_year = :levelYear)
                           AND (:statusFilter = '' OR cr.status = :statusFilter)
                         ORDER BY s.last_name, s.first_name");
$rstmt->execute(['course_id' => $courseId, 'semester_id' => $semesterId, 'levelYear' => $levelYear, 'statusFilter' => $statusFilter]);
$students = $rstmt->fetchAll(PDO::FETCH_ASSOC);

if ($export && in_array($export, ['csv','excel'])) {
    $filename = 'course_' . $courseId . '_roster_' . date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    if ($export === 'excel') header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.' . ($export === 'excel' ? 'xls' : 'csv') . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','Name','Status','Registration Date']);
    foreach ($students as $s) fputcsv($out, [$s['student_id'],$s['first_name'] . ' ' . $s['last_name'],$s['status'],$s['registration_date']]);
    fclose($out);
    exit;
}

$pageTitle = 'Course Roster - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Course: <?php echo e($course['course_code'] . ' — ' . $course['course_name']); ?></h4>
        </div>
        <div class="topbar-right">
            <a href="lecturer.php?semester_id=<?php echo $semesterId; ?>" class="btn btn-sm btn-secondary">← Back</a>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-2">
                    <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                    <input type="hidden" name="semester_id" value="<?php echo $semesterId; ?>">

                    <label class="mr-2">Level</label>
                    <select name="level_year" class="form-control mr-2">
                        <option value="">All</option>
                        <?php $lvStmt = $conn->query("SELECT DISTINCT level_year FROM students ORDER BY level_year"); foreach($lvStmt->fetchAll(PDO::FETCH_COLUMN) as $lv): ?>
                            <option value="<?php echo e($lv); ?>" <?php echo ($levelYear == $lv) ? 'selected' : ''; ?>><?php echo e($lv); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Status</label>
                    <select name="status" class="form-control mr-2">
                        <option value="">All</option>
                        <option value="approved" <?php echo $statusFilter=='approved'?'selected':''; ?>>Approved</option>
                        <option value="pending" <?php echo $statusFilter=='pending'?'selected':''; ?>>Pending</option>
                        <option value="rejected" <?php echo $statusFilter=='rejected'?'selected':''; ?>>Rejected</option>
                    </select>

                    <button class="btn btn-sm btn-primary mr-2">Apply</button>

                    <a href="?course_id=<?php echo $courseId; ?>&semester_id=<?php echo $semesterId; ?>&export=csv" class="btn btn-outline-secondary btn-sm">Export CSV</a>
                    <a href="?course_id=<?php echo $courseId; ?>&semester_id=<?php echo $semesterId; ?>&export=excel" class="btn btn-outline-secondary btn-sm">Export Excel</a>
                </form>

                <p><strong>Semester:</strong> <?php echo e($semesterId); ?> — <strong>Enrolled:</strong> <?php echo count($students); ?></p>

                <div class="table-responsive">
                    <table class="table table-sm table-hover data-table">
                        <thead><tr><th>Student ID</th><th>Name</th><th>Status</th><th>Registered On</th></tr></thead>
                        <tbody>
                            <?php foreach($students as $s): ?>
                                <tr>
                                    <td><?php echo e($s['student_id']); ?></td>
                                    <td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                    <td><?php echo e($s['status']); ?></td>
                                    <td><?php echo e($s['registration_date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>