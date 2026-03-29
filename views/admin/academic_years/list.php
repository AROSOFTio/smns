<?php
/**
 * Academic Years - List
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$allowedPageSizes = [25, 50, 100];
$rowsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($rowsPerPage, $allowedPageSizes, true)) {
    $rowsPerPage = 25;
}
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage <= 0) {
    $currentPage = 1;
}

$totalYears = 0;
try {
    $totalYears = (int)$conn->query("SELECT COUNT(*) FROM academic_years")->fetchColumn();
} catch (Exception $e) {
    $totalYears = 0;
}
$totalPages = max(1, (int)ceil($totalYears / max($rowsPerPage, 1)));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $rowsPerPage;
$years = [];
try {
    $stmt = $conn->prepare("SELECT * FROM academic_years ORDER BY start_date DESC LIMIT :limit_rows OFFSET :offset_rows");
    $stmt->bindValue(':limit_rows', $rowsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $years = $stmt->fetchAll();
} catch (Exception $e) {
    $years = [];
}
$displayStart = $totalYears > 0 ? ($offset + 1) : 0;
$displayEnd = $totalYears > 0 ? min($offset + count($years), $totalYears) : 0;
$buildListUrl = static function (int $page, int $perPage): string {
    $query = [];
    if ($page > 1) {
        $query['page'] = $page;
    }
    if ($perPage > 0) {
        $query['per_page'] = $perPage;
    }
    return 'list.php' . (!empty($query) ? ('?' . http_build_query($query)) : '');
};

$pageTitle = 'Academic Years - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<style>
.academic-years-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.academic-years-toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.academic-years-meta { color: #64748b; font-size: 12px; font-weight: 600; }
.academic-years-per-page label { margin-bottom: 0; font-size: 12px; font-weight: 600; color: #475569; }
.academic-years-table { width: 100%; table-layout: fixed; }
.academic-years-table th, .academic-years-table td { white-space: normal; word-break: break-word; }
.academic-years-table .col-year { width: 190px; }
.academic-years-table .col-start { width: 130px; }
.academic-years-table .col-end { width: 130px; }
.academic-years-table .col-status { width: 120px; }
.academic-years-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.academic-years-pagination .pagination { margin-bottom: 0; }
</style>
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left"><h4>Academic Years</h4></div>
        <div class="topbar-right"><a href="add.php" class="btn btn-primary">Add Year</a></div>
    </div>
    <div class="content-area">
        <div class="card">
            <div class="card-body">
                <div class="academic-years-toolbar mb-2">
                    <div class="academic-years-toolbar-right">
                        <span class="academic-years-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalYears; ?></span>
                        <form method="GET" class="form-inline academic-years-per-page">
                            <label for="yearsPerPage" class="mr-2">Rows</label>
                            <select id="yearsPerPage" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($allowedPageSizes as $size): ?>
                                    <option value="<?php echo (int)$size; ?>" <?php echo $rowsPerPage === (int)$size ? 'selected' : ''; ?>><?php echo (int)$size; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <?php if (count($years) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm academic-years-table">
                            <thead><tr><th class="col-year">Year</th><th class="col-start">Start</th><th class="col-end">End</th><th class="col-status">Status</th></tr></thead>
                            <tbody>
                                <?php foreach($years as $y): ?>
                                    <tr>
                                        <td><?php echo e($y['year_name']); ?></td>
                                        <td><?php echo e($y['start_date']); ?></td>
                                        <td><?php echo e($y['end_date']); ?></td>
                                        <td><?php echo e($y['status']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="academic-years-footer pt-2">
                        <div class="academic-years-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalYears; ?> academic years</div>
                        <div class="academic-years-pagination">
                            <ul class="pagination pagination-sm">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildListUrl(max(1, $currentPage - 1), $rowsPerPage)); ?>">Previous</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo e($buildListUrl($p, $rowsPerPage)); ?>"><?php echo (int)$p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildListUrl(min($totalPages, $currentPage + 1), $rowsPerPage)); ?>">Next</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <p>No academic years defined.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>
