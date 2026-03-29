<?php
/**
 * Student Profile Audit History - Admin
 * Dedicated screen to review immutable student profile correction history.
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentId = (int)($_GET['id'] ?? 0);
if ($studentId <= 0) {
    header('Location: list.php?error=invalid_id');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

function ensureStudentProfileAuditTable(PDO $conn): void
{
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS student_profile_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            changed_by_user_id INT NOT NULL,
            change_type ENUM('profile_update','status_update') NOT NULL DEFAULT 'profile_update',
            old_data LONGTEXT NULL,
            new_data LONGTEXT NOT NULL,
            changed_fields LONGTEXT NULL,
            reason TEXT NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student_id (student_id),
            KEY idx_changed_by_user_id (changed_by_user_id),
            KEY idx_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // Keep page functional even if schema update is restricted.
    }
}

ensureStudentProfileAuditTable($conn);

try {
    $studentStmt = $conn->prepare("
        SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.status, s.email, s.program_id,
               p.program_code, p.program_name
        FROM students s
        LEFT JOIN programs p ON s.program_id = p.id
        WHERE s.id = :id
        LIMIT 1
    ");
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        header('Location: list.php?error=student_not_found');
        exit;
    }
} catch (Exception $e) {
    header('Location: list.php?error=database_error');
    exit;
}

$changeType = trim((string)($_GET['change_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$reasonSearch = trim((string)($_GET['reason'] ?? ''));
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$sql = "
    SELECT spa.*,
           u.username AS changed_by_username,
           COALESCE(a.first_name, '') AS admin_first_name,
           COALESCE(a.last_name, '') AS admin_last_name
    FROM student_profile_audit spa
    LEFT JOIN users u ON spa.changed_by_user_id = u.id
    LEFT JOIN admins a ON u.id = a.user_id
    WHERE spa.student_id = :student_id
";
$params = ['student_id' => $studentId];

if ($changeType !== '' && in_array($changeType, ['profile_update', 'status_update'], true)) {
    $sql .= " AND spa.change_type = :change_type";
    $params['change_type'] = $changeType;
}
if ($dateFrom !== '') {
    $sql .= " AND DATE(spa.changed_at) >= :date_from";
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= " AND DATE(spa.changed_at) <= :date_to";
    $params['date_to'] = $dateTo;
}
if ($reasonSearch !== '') {
    $sql .= " AND spa.reason LIKE :reason";
    $params['reason'] = '%' . $reasonSearch . '%';
}

$sql .= " ORDER BY spa.changed_at DESC LIMIT 500";

try {
    $auditStmt = $conn->prepare($sql);
    $auditStmt->execute($params);
    $auditRecords = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $auditRecords = [];
    $session->setFlash('error', 'Unable to load profile audit history.');
}

$summary = [
    'total' => count($auditRecords),
    'profile_update' => 0,
    'status_update' => 0
];
foreach ($auditRecords as $row) {
    $key = (string)($row['change_type'] ?? '');
    if (isset($summary[$key])) {
        $summary[$key]++;
    }
}

if ($export === 'csv' || $export === 'excel') {
    $isExcel = ($export === 'excel');
    $filename = 'student_profile_audit_' . (string)($student['student_id'] ?? $studentId) . '_' . date('Ymd_His') . ($isExcel ? '.xls' : '.csv');
    if ($isExcel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Changed At', 'Change Type', 'Changed By', 'Reason', 'Changed Fields', 'Student DB ID', 'Student PRN']);
    foreach ($auditRecords as $record) {
        $changedBy = trim((string)(($record['admin_first_name'] ?? '') . ' ' . ($record['admin_last_name'] ?? '')));
        if ($changedBy === '') {
            $changedBy = (string)($record['changed_by_username'] ?? 'Unknown');
        }
        $changedFields = json_decode((string)($record['changed_fields'] ?? ''), true);
        $fieldList = '';
        if (is_array($changedFields) && !empty($changedFields)) {
            $fieldList = implode(', ', array_keys($changedFields));
        }
        fputcsv($out, [
            (string)($record['changed_at'] ?? ''),
            (string)($record['change_type'] ?? ''),
            $changedBy,
            (string)($record['reason'] ?? ''),
            $fieldList,
            (string)$studentId,
            (string)($student['student_id'] ?? '')
        ]);
    }
    fclose($out);
    exit;
}

$csvExportQuery = $_GET;
$csvExportQuery['export'] = 'csv';
$csvExportHref = '?' . http_build_query($csvExportQuery);

$excelExportQuery = $_GET;
$excelExportQuery['export'] = 'excel';
$excelExportHref = '?' . http_build_query($excelExportQuery);

$pageTitle = 'Student Profile Audit - ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var mode = localStorage.getItem('smns_theme_mode');
                if (mode === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
            } catch (e) {}
        })();
    </script>
    <title><?php echo e($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="../../../assets/css/theme-shared.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="../../../assets/css/responsive-nav.css">
</head>
<body>
<?php include '../../../includes/admin/sidebar.php'; ?>

<style>
.audit-card {
    border: none;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
}
.audit-stat {
    border-radius: 10px;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}
.audit-json {
    max-height: 220px;
    overflow: auto;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 6px;
    padding: 10px;
    font-size: 12px;
    margin-bottom: 0;
}
html[data-theme='dark'] .audit-stat {
    background: #1e293b;
    border-color: #334155;
}
</style>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Student Profile Audit History</h4>
        </div>
        <div class="topbar-right">
            <a href="<?php echo e($csvExportHref); ?>" class="btn btn-outline-primary btn-sm mr-2">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a href="<?php echo e($excelExportHref); ?>" class="btn btn-outline-success btn-sm mr-2">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <a href="view.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to Student
            </a>
            <a href="edit.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-warning mr-2">
                <i class="fas fa-edit"></i> Edit Student
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card audit-card mb-3">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="mb-1"><?php echo e(trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></h5>
                        <div class="text-muted">
                            <strong><?php echo e($student['student_id'] ?? ''); ?></strong>
                            <?php if (!empty($student['program_code']) || !empty($student['program_name'])): ?>
                                | <?php echo e(($student['program_code'] ?? '') . ' ' . ($student['program_name'] ?? '')); ?>
                            <?php endif; ?>
                            | Status: <?php echo e(ucfirst((string)($student['status'] ?? 'unknown'))); ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-right mt-2 mt-md-0">
                        <span class="badge badge-info p-2">Records: <?php echo (int)$summary['total']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card audit-card mb-3">
            <div class="card-body">
                <form method="GET" class="form-row">
                    <input type="hidden" name="id" value="<?php echo (int)$studentId; ?>">
                    <div class="col-md-2 mb-2">
                        <select name="change_type" class="form-control form-control-sm">
                            <option value="">All Change Types</option>
                            <option value="profile_update" <?php echo $changeType === 'profile_update' ? 'selected' : ''; ?>>Profile Update</option>
                            <option value="status_update" <?php echo $changeType === 'status_update' ? 'selected' : ''; ?>>Status Update</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="col-md-2 mb-2">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo e($dateTo); ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Search reason..." value="<?php echo e($reasonSearch); ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <button type="submit" class="btn btn-primary btn-sm mr-1"><i class="fas fa-filter"></i> Filter</button>
                        <a href="audit.php?id=<?php echo (int)$studentId; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-redo"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4 mb-2">
                <div class="audit-stat"><strong>Total Entries:</strong> <?php echo (int)$summary['total']; ?></div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="audit-stat"><strong>Profile Updates:</strong> <?php echo (int)$summary['profile_update']; ?></div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="audit-stat"><strong>Status Updates:</strong> <?php echo (int)$summary['status_update']; ?></div>
            </div>
        </div>

        <div class="card audit-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-history"></i> Correction History</span>
                <small class="text-muted">Latest first, max 500 rows</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($auditRecords)): ?>
                    <div class="p-4 text-center text-muted">No audit history found for this student.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 160px;">Date/Time</th>
                                    <th style="width: 120px;">Type</th>
                                    <th style="width: 160px;">Changed By</th>
                                    <th>Reason</th>
                                    <th style="width: 220px;">Changed Fields</th>
                                    <th style="width: 180px;">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditRecords as $record): ?>
                                    <?php
                                        $changedBy = trim((string)($record['admin_first_name'] . ' ' . $record['admin_last_name']));
                                        if ($changedBy === '') {
                                            $changedBy = (string)($record['changed_by_username'] ?? 'Unknown');
                                        }
                                        $changedFields = json_decode((string)($record['changed_fields'] ?? ''), true);
                                        if (!is_array($changedFields)) {
                                            $changedFields = [];
                                        }
                                        $oldDataPretty = json_encode(json_decode((string)($record['old_data'] ?? ''), true), JSON_PRETTY_PRINT);
                                        $newDataPretty = json_encode(json_decode((string)($record['new_data'] ?? ''), true), JSON_PRETTY_PRINT);
                                    ?>
                                    <tr>
                                        <td><?php echo e(Helper::formatDateTime((string)$record['changed_at'], 'Y-m-d H:i:s')); ?></td>
                                        <td>
                                            <?php if (($record['change_type'] ?? '') === 'status_update'): ?>
                                                <span class="badge badge-warning">Status Update</span>
                                            <?php else: ?>
                                                <span class="badge badge-info">Profile Update</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($changedBy); ?></td>
                                        <td><?php echo e((string)($record['reason'] ?? '')); ?></td>
                                        <td>
                                            <?php if (empty($changedFields)): ?>
                                                <span class="text-muted">N/A</span>
                                            <?php else: ?>
                                                <?php foreach (array_keys($changedFields) as $fieldName): ?>
                                                    <span class="badge badge-light mr-1 mb-1"><?php echo e((string)$fieldName); ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <details>
                                                <summary>View</summary>
                                                <?php if (!empty($changedFields)): ?>
                                                    <div class="mt-2 mb-2">
                                                        <?php foreach ($changedFields as $fieldName => $delta): ?>
                                                            <div class="small mb-1">
                                                                <strong><?php echo e((string)$fieldName); ?>:</strong>
                                                                <span class="text-muted"><?php echo e((string)($delta['old'] ?? '')); ?></span>
                                                                ->
                                                                <span><?php echo e((string)($delta['new'] ?? '')); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($oldDataPretty): ?>
                                                    <div class="small text-muted mb-1">Old Snapshot</div>
                                                    <pre class="audit-json"><?php echo e($oldDataPretty); ?></pre>
                                                <?php endif; ?>
                                                <?php if ($newDataPretty): ?>
                                                    <div class="small text-muted mb-1">New Snapshot</div>
                                                    <pre class="audit-json"><?php echo e($newDataPretty); ?></pre>
                                                <?php endif; ?>
                                            </details>
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
