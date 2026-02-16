<?php
/**
 * Admin Report - Program detail (students enrolled in date range)
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

$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-01-01');
$to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$export = $_GET['export'] ?? '';

// Filters
$levelYear = isset($_GET['level_year']) ? trim($_GET['level_year']) : '';
$hasRegistrations = isset($_GET['has_registrations']) ? $_GET['has_registrations'] : 'all';

// Available levels for this program
$levelStmt = $conn->prepare("SELECT DISTINCT level_year FROM students WHERE program_id = :pid ORDER BY level_year");
$levelStmt->execute(['pid' => $programId]);
$availableLevels = $levelStmt->fetchAll(PDO::FETCH_COLUMN);

if (!$programId) {
    $session->setFlash('error', 'Program not specified.');
    header('Location: index.php?report=enrollment');
    exit;
}

// Program info
$pstmt = $conn->prepare("SELECT * FROM programs WHERE id = :id");
$pstmt->execute(['id' => $programId]);
$prog = $pstmt->fetch(PDO::FETCH_ASSOC);
if (!$prog) {
    $session->setFlash('error', 'Program not found.');
    header('Location: index.php?report=enrollment');
    exit;
}

// Students in program within date range (apply optional filters)
$sql = "SELECT s.id, s.student_id, s.first_name, s.last_name, s.level_year, DATE(s.created_at) as enrolled_on
        FROM students s
        WHERE s.program_id = :program_id
          AND DATE(s.created_at) BETWEEN :from AND :to";

if ($levelYear) {
    $sql .= " AND s.level_year = :level_year";
}

if ($hasRegistrations === 'yes') {
    $sql .= " AND EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = s.id AND DATE(cr.registration_date) BETWEEN :from AND :to)";
} elseif ($hasRegistrations === 'no') {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = s.id AND DATE(cr.registration_date) BETWEEN :from AND :to)";
}

$sql .= " ORDER BY s.created_at DESC";
$sstmt = $conn->prepare($sql);
$params = ['program_id' => $programId, 'from' => $from, 'to' => $to];
if ($levelYear) $params['level_year'] = $levelYear;
$sstmt->execute($params);
$students = $sstmt->fetchAll(PDO::FETCH_ASSOC);

// Counts by level
$lvstmt = $conn->prepare("SELECT s.level_year, COUNT(*) as cnt FROM students s WHERE s.program_id = :program_id AND DATE(s.created_at) BETWEEN :from AND :to GROUP BY s.level_year ORDER BY s.level_year");
$lvstmt->execute(['program_id' => $programId, 'from' => $from, 'to' => $to]);
$byLevel = $lvstmt->fetchAll(PDO::FETCH_ASSOC);

// Export
if ($export && in_array($export, ['csv','excel'])) {
    $filename = 'program_' . $programId . '_students_' . date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    if ($export === 'excel') header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.' . ($export === 'excel' ? 'xls' : 'csv') . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','Name','Level','Enrolled On']);
    foreach ($students as $s) fputcsv($out, [$s['student_id'],$s['first_name'] . ' ' . $s['last_name'],$s['level_year'],$s['enrolled_on']]);
    fclose($out);
    exit;
}

$pageTitle = 'Program Report - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Program: <?php echo e($prog['program_name']); ?></h4>
        </div>
        <div class="topbar-right">
            <a href="index.php?report=enrollment&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="btn btn-sm btn-secondary">← Back to Reports</a>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-2">
                    <input type="hidden" name="program_id" value="<?php echo $programId; ?>">
                    <label class="mr-2">Level</label>
                    <select name="level_year" class="form-control mr-2">
                        <option value="">All</option>
                        <?php foreach($availableLevels as $lv): ?>
                            <option value="<?php echo e($lv); ?>" <?php echo ($levelYear == $lv) ? 'selected' : ''; ?>><?php echo e($lv); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Has Registrations</label>
                    <select name="has_registrations" class="form-control mr-2">
                        <option value="all" <?php echo $hasRegistrations=='all'?'selected':''; ?>>All</option>
                        <option value="yes" <?php echo $hasRegistrations=='yes'?'selected':''; ?>>Yes</option>
                        <option value="no" <?php echo $hasRegistrations=='no'?'selected':''; ?>>No</option>
                    </select>

                    <label class="mr-2">From</label>
                    <input type="date" name="from" class="form-control mr-2" value="<?php echo e($from); ?>">
                    <label class="mr-2">To</label>
                    <input type="date" name="to" class="form-control mr-2" value="<?php echo e($to); ?>">

                    <button class="btn btn-sm btn-primary mr-2">Apply</button>

                    <a href="?program_id=<?php echo $programId; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&export=csv" class="btn btn-outline-secondary btn-sm">Export CSV</a>
                    <a href="?program_id=<?php echo $programId; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&export=excel" class="btn btn-outline-secondary btn-sm">Export Excel</a>
                </form>

                <p><strong>Students found:</strong> <?php echo count($students); ?> — <strong>Period:</strong> <?php echo e($from); ?> → <?php echo e($to); ?></p>

                <h6>Count by Level</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover">
                        <thead><tr><th>Level</th><th>Count</th></tr></thead>
                        <tbody>
                            <?php foreach($byLevel as $lv): ?>
                                <tr><td><?php echo e($lv['level_year'] ?: 'N/A'); ?></td><td><?php echo e($lv['cnt']); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h6>Students</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover data-table">
                        <thead><tr><th>Student ID</th><th>Name</th><th>Level</th><th>Enrolled On</th></tr></thead>
                        <tbody>
                            <?php foreach($students as $s): ?>
                                <tr>
                                    <td><?php echo e($s['student_id']); ?></td>
                                    <td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                    <td><?php echo e($s['level_year']); ?></td>
                                    <td><?php echo e($s['enrolled_on']); ?></td>
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