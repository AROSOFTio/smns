<?php
/**
 * Admin Report - Lecturer detail (courses assigned + students per course)
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

$lecturerId = isset($_GET['lecturer_id']) ? (int)$_GET['lecturer_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$export = $_GET['export'] ?? '';

// Filters (level/year + registration status)
$levelYear = isset($_GET['level_year']) ? trim($_GET['level_year']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$levels = $conn->query("SELECT DISTINCT level_year FROM courses WHERE level_year IS NOT NULL ORDER BY level_year")->fetchAll(PDO::FETCH_COLUMN);

if (!$lecturerId) {
    $session->setFlash('error', 'Lecturer not specified.');
    header('Location: index.php?report=staff');
    exit;
}

// default semester
if (!$semesterId) {
    $currentSem = Helper::getCurrentSemester();
    $semesterId = $currentSem['id'] ?? 0;
}

// Lecturer info
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE id = :id");
$stmt->execute(['id' => $lecturerId]);
$lect = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lect) {
    $session->setFlash('error', 'Lecturer not found.');
    header('Location: index.php?report=staff');
    exit;
}

// Courses assigned this semester + registered student counts
$q = "SELECT ca.course_id, c.course_code, c.course_name, COALESCE(crs.reg_count,0) AS students_registered
      FROM course_assignments ca
      JOIN courses c ON ca.course_id = c.id
      LEFT JOIN (
          SELECT cr.course_id, COUNT(*) AS reg_count
          FROM course_registrations cr
          JOIN students s ON cr.student_id = s.id
          WHERE cr.semester_id = :semester_id
            AND (:statusFilter = '' OR cr.status = :statusFilter)
            AND (:levelYear = '' OR s.level_year = :levelYear)
          GROUP BY cr.course_id
      ) crs ON crs.course_id = ca.course_id
      WHERE ca.lecturer_id = :lecturer_id AND ca.semester_id = :semester_id
      ORDER BY c.course_code";
$cs = $conn->prepare($q);
$cs->execute(['lecturer_id' => $lecturerId, 'semester_id' => $semesterId, 'statusFilter' => $statusFilter, 'levelYear' => $levelYear]);
$courses = $cs->fetchAll(PDO::FETCH_ASSOC);

// EXPORT
if ($export && in_array($export, ['csv','excel'])) {
    $filename = 'lecturer_' . $lecturerId . '_courses_' . date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    if ($export === 'excel') header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.' . ($export === 'excel' ? 'xls' : 'csv') . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Course Code','Course Name','Students Registered']);
    foreach ($courses as $r) fputcsv($out, [$r['course_code'],$r['course_name'],$r['students_registered']]);
    fclose($out);
    exit;
}

$pageTitle = 'Lecturer Report - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Lecturer: <?php echo e($lect['first_name'] . ' ' . $lect['last_name']); ?></h4>
        </div>
        <div class="topbar-right">
            <a href="index.php?report=staff&semester_id=<?php echo $semesterId; ?>" class="btn btn-sm btn-secondary">← Back to Reports</a>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card mb-3">
            <div class="card-body">
                <p><strong>Email:</strong> <?php echo e($lect['email'] ?? 'N/A'); ?> — <strong>Semester:</strong> <?php echo e($semesterId ?: 'Current'); ?></p>

                <form method="GET" class="form-inline mb-2">
                    <input type="hidden" name="lecturer_id" value="<?php echo $lecturerId; ?>">
                    <input type="hidden" name="semester_id" value="<?php echo $semesterId; ?>">
                    <label class="mr-2">Level</label>
                    <select name="level_year" class="form-control mr-2">
                        <option value="">All</option>
                        <?php foreach($levels as $lv): ?>
                            <option value="<?php echo e($lv); ?>" <?php echo $levelYear == $lv ? 'selected' : ''; ?>><?php echo e($lv); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Registration</label>
                    <select name="status" class="form-control mr-2">
                        <option value="">All</option>
                        <option value="approved" <?php echo $statusFilter=='approved'?'selected':''; ?>>Approved</option>
                        <option value="pending" <?php echo $statusFilter=='pending'?'selected':''; ?>>Pending</option>
                        <option value="rejected" <?php echo $statusFilter=='rejected'?'selected':''; ?>>Rejected</option>
                    </select>

                    <button class="btn btn-sm btn-primary mr-3">Apply</button>

                    <a href="?lecturer_id=<?php echo $lecturerId; ?>&semester_id=<?php echo $semesterId; ?>&export=csv" class="btn btn-outline-secondary btn-sm">Export CSV</a>
                    <a href="?lecturer_id=<?php echo $lecturerId; ?>&semester_id=<?php echo $semesterId; ?>&export=excel" class="btn btn-outline-secondary btn-sm">Export Excel</a>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-hover data-table">
                        <thead><tr><th>Course Code</th><th>Course Name</th><th>Students Registered</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach($courses as $c): ?>
                                <tr>
                                    <td><?php echo e($c['course_code']); ?></td>
                                    <td><?php echo e($c['course_name']); ?></td>
                                    <td><?php echo e($c['students_registered']); ?></td>
                                    <td><a class="btn btn-sm btn-primary" href="course_students.php?course_id=<?php echo $c['course_id']; ?>&semester_id=<?php echo $semesterId; ?>&level_year=<?php echo urlencode($levelYear); ?>&status=<?php echo urlencode($statusFilter); ?>">View Students</a></td>
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