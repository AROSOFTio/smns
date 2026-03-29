<?php
/**
 * Module Change Tracker
 * Shows who changed what, where, and when across modules.
 */
require_once '../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (
    !isset($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true ||
    ($_SESSION['admin_role'] ?? '') !== 'admin'
) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();
$logger = new Logger();

function ctNormalizeDate($value, $fallback) {
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    $dt = date_create($raw);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function ctTableExists(PDO $conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute(['table_name' => (string)$tableName]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$from = ctNormalizeDate($_GET['from'] ?? '', $defaultFrom);
$to = ctNormalizeDate($_GET['to'] ?? '', $today);
if ($from > $to) {
    $swap = $from;
    $from = $to;
    $to = $swap;
}

$selectedModule = strtolower(trim((string)($_GET['module'] ?? '')));
$selectedAction = strtolower(trim((string)($_GET['action'] ?? '')));
$partFilter = trim((string)($_GET['part'] ?? ''));
$whereFilter = trim((string)($_GET['where'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 250);
if ($limit < 50) {
    $limit = 50;
}
if ($limit > 2000) {
    $limit = 2000;
}
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$hasActivityLogs = ctTableExists($conn, 'activity_logs');
$warning = '';
$moduleOptions = [];
$actionOptions = [];
$changes = [];

if (!$hasActivityLogs) {
    $warning = 'Table activity_logs not found. Change tracking is unavailable.';
} else {
    try {
        $moduleStmt = $conn->query("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL AND module <> '' ORDER BY module ASC");
        $moduleRows = $moduleStmt ? $moduleStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($moduleRows as $moduleName) {
            $moduleName = trim((string)$moduleName);
            if ($moduleName !== '') {
                $moduleOptions[] = $moduleName;
            }
        }

        $actionStmt = $conn->query("SELECT DISTINCT action FROM activity_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC");
        $actionRows = $actionStmt ? $actionStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($actionRows as $actionName) {
            $actionName = trim((string)$actionName);
            if ($actionName !== '') {
                $actionOptions[] = $actionName;
            }
        }
    } catch (Exception $e) {
        $warning = 'Failed to load filters: ' . $e->getMessage();
    }

    $changes = $logger->getModuleChangeTimeline([
        'from' => $from,
        'to' => $to,
        'module' => $selectedModule,
        'action' => $selectedAction,
        'part' => $partFilter,
        'where' => $whereFilter,
        'q' => $q
    ], $limit);
}

if ($hasActivityLogs && $export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="module_change_tracker_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time', 'Actor', 'Role', 'Module', 'Action', 'Part', 'Where', 'Target', 'Description', 'IP Address']);
    foreach ($changes as $row) {
        fputcsv($out, [
            (string)($row['created_at'] ?? ''),
            (string)($row['display_name'] ?? ($row['username'] ?? 'System')),
            (string)($row['user_role'] ?? ''),
            (string)($row['module'] ?? ''),
            (string)($row['action'] ?? ''),
            (string)($row['change_part'] ?? ''),
            (string)($row['change_where'] ?? ''),
            (string)($row['change_target'] ?? ''),
            (string)($row['description_plain'] ?? ''),
            (string)($row['ip_address'] ?? '')
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Module Change Tracker - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/admin/sidebar.php'; ?>
<style>
.change-tracker-page,
.change-tracker-page .content-area {
    max-width: 100%;
    overflow-x: hidden;
}

.change-tracker-page .card,
.change-tracker-page .table-wrap {
    max-width: 100%;
}

.change-tracker-page .table-wrap {
    overflow-x: hidden;
}

.change-tracker-page table {
    width: 100%;
    min-width: 0;
    table-layout: fixed;
}

.change-tracker-page th,
.change-tracker-page td {
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    vertical-align: top;
}

.change-tracker-page thead th[style*='width'] {
    width: auto !important;
}

.change-tracker-page .mono {
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    word-break: break-all;
    overflow-wrap: anywhere;
}

.change-tracker-page .meta-pill { font-size: 11px; font-weight: 600; }

@media (max-width: 768px) {
    .change-tracker-page .content-area {
        padding: 12px !important;
    }
}
</style>

<div class="main-content change-tracker-page">
    <div class="content-area container-fluid p-4">
        <?php if (!empty($warning)): ?>
            <div class="alert alert-warning"><?php echo e($warning); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-clipboard-list mr-1"></i> Module Change Tracker</h4>
                <div>
                    <a href="change-tracker.php?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </div>
            <div class="card-body">
                <form method="get" class="form-row">
                    <div class="form-group col-md-2">
                        <label>From</label>
                        <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label>To</label>
                        <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label>Module</label>
                        <select name="module" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($moduleOptions as $moduleName): ?>
                                <?php $moduleValue = strtolower((string)$moduleName); ?>
                                <option value="<?php echo e($moduleValue); ?>" <?php echo $selectedModule === $moduleValue ? 'selected' : ''; ?>>
                                    <?php echo e($moduleName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label>Action</label>
                        <select name="action" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($actionOptions as $actionName): ?>
                                <?php $actionValue = strtolower((string)$actionName); ?>
                                <option value="<?php echo e($actionValue); ?>" <?php echo $selectedAction === $actionValue ? 'selected' : ''; ?>>
                                    <?php echo e($actionName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label>Part</label>
                        <input type="text" name="part" class="form-control" value="<?php echo e($partFilter); ?>" placeholder="e.g. grading panel">
                    </div>
                    <div class="form-group col-md-2">
                        <label>Where</label>
                        <input type="text" name="where" class="form-control" value="<?php echo e($whereFilter); ?>" placeholder="/views/admin/...">
                    </div>
                    <div class="form-group col-md-8">
                        <label>Search Description/User</label>
                        <input type="text" name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="keyword">
                    </div>
                    <div class="form-group col-md-2">
                        <label>Limit</label>
                        <input type="number" name="limit" class="form-control" min="50" max="2000" value="<?php echo e((string)$limit); ?>">
                    </div>
                    <div class="form-group col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary mr-2"><i class="fas fa-filter"></i> Apply</button>
                        <a href="change-tracker.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Results</strong>
                <span class="badge badge-info"><?php echo number_format((int)count($changes)); ?> row(s)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-wrap">
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 170px;">Time</th>
                                <th style="width: 170px;">Actor</th>
                                <th style="width: 90px;">Role</th>
                                <th style="width: 120px;">Module</th>
                                <th style="width: 95px;">Action</th>
                                <th style="width: 170px;">Part</th>
                                <th style="width: 260px;">Where</th>
                                <th style="width: 180px;">Target</th>
                                <th>Description</th>
                                <th style="width: 130px;">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($changes)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No change records found for the selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($changes as $row): ?>
                                    <tr>
                                        <td><?php echo e(Helper::formatDateTime((string)($row['created_at'] ?? ''), 'M d, Y h:i:s A')); ?></td>
                                        <td><?php echo e($row['display_name'] ?? ($row['username'] ?? 'System')); ?></td>
                                        <td><span class="badge badge-secondary meta-pill"><?php echo e($row['user_role'] ?? '-'); ?></span></td>
                                        <td><span class="badge badge-light border"><?php echo e($row['module'] ?? '-'); ?></span></td>
                                        <td><span class="badge badge-primary meta-pill"><?php echo e(strtoupper((string)($row['action'] ?? '-'))); ?></span></td>
                                        <td><?php echo e($row['change_part'] ?? '-'); ?></td>
                                        <td class="mono"><?php echo e($row['change_where'] ?? '-'); ?></td>
                                        <td><?php echo e($row['change_target'] ?? '-'); ?></td>
                                        <td><?php echo e($row['description_plain'] ?? '-'); ?></td>
                                        <td class="mono"><?php echo e($row['ip_address'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
