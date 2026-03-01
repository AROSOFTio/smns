<?php
/**
 * Lecturers List - Admin
 */
require_once dirname(__DIR__, 3) . '/config.php';

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

// Handle POST request for truncating lecturers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'truncate_lecturers') {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $conn->beginTransaction();

        // Disable foreign key checks
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');

        // 1. Get user_ids for all lecturers to delete their user accounts
        $stmt = $conn->query("SELECT user_id FROM lecturers WHERE user_id IS NOT NULL");
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Get photos to delete from filesystem
        $stmt = $conn->query("SELECT photo FROM lecturers WHERE photo IS NOT NULL AND photo != ''");
        $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Truncate the lecturers table
        $conn->exec('TRUNCATE TABLE lecturers');

        // 4. Delete associated user accounts
        if (!empty($userIds)) {
            $inQuery = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($inQuery) AND role = 'lecturer'");
            $stmt->execute($userIds);
        }

        // 5. Delete uploaded photos
        $uploadDir = dirname(__DIR__, 3) . '/uploads/lecturers/';
        foreach ($photos as $photo) {
            $filePath = $uploadDir . basename($photo);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Re-enable foreign key checks
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');

        $conn->commit();
        $session->setFlash('success', 'All lecturers, their user accounts, and uploaded photos have been permanently deleted.');
    } catch (Exception $e) {
        // Rollback and re-enable keys on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        // Ensure foreign key checks are re-enabled even if the transaction fails
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
        
        // Log the error and set a flash message
        $detailed_error = "Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
        error_log("Lecturer truncation error: " . $detailed_error);
        $session->setFlash('error', 'An error occurred. ' . $detailed_error);
    }

    // Redirect back to the list to prevent form re-submission
    header('Location: list.php');
    exit;
}


$currentUser = $auth->getCurrentUser();

// Get filter parameters
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';
$designation = $_GET['designation'] ?? '';
$allowedPageSizes = [25, 50, 100];
$rowsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($rowsPerPage, $allowedPageSizes, true)) {
    $rowsPerPage = 25;
}
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage <= 0) {
    $currentPage = 1;
}

// Build query
$db = new Database();
$conn = $db->getConnection();

$fromWhereSql = " FROM lecturers l
        INNER JOIN users u ON l.user_id = u.id
        WHERE 1=1";

$params = [];

if ($search) {
    $fromWhereSql .= " AND (l.lecturer_id LIKE :search OR l.first_name LIKE :search 
              OR l.last_name LIKE :search OR l.email LIKE :search)";
    $params['search'] = "%$search%";
}

if ($department) {
    $fromWhereSql .= " AND l.department LIKE :department";
    $params['department'] = "%$department%";
}

if ($status) {
    $fromWhereSql .= " AND l.status = :status";
    $params['status'] = $status;
}

if ($designation) {
    $fromWhereSql .= " AND l.specialization LIKE :designation";
    $params['designation'] = "%$designation%";
}

$totalLecturers = 0;
try {
    $countStmt = $conn->prepare("SELECT COUNT(*)" . $fromWhereSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue(':' . $k, $v);
    }
    $countStmt->execute();
    $totalLecturers = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $totalLecturers = 0;
}
$totalPages = max(1, (int)ceil($totalLecturers / max($rowsPerPage, 1)));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $rowsPerPage;

$lecturers = [];
try {
    $sql = "SELECT l.*, u.status as user_status"
        . $fromWhereSql
        . " ORDER BY l.created_at DESC LIMIT :limit_rows OFFSET :offset_rows";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit_rows', $rowsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $lecturers = $stmt->fetchAll();
} catch (Exception $e) {
    $lecturers = [];
}
$displayStart = $totalLecturers > 0 ? ($offset + 1) : 0;
$displayEnd = $totalLecturers > 0 ? min($offset + count($lecturers), $totalLecturers) : 0;
$buildListUrl = static function (int $page, int $perPage, string $search, string $department, string $status, string $designation): string {
    $query = [];
    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($department !== '') {
        $query['department'] = $department;
    }
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($designation !== '') {
        $query['designation'] = $designation;
    }
    if ($page > 1) {
        $query['page'] = $page;
    }
    if ($perPage > 0) {
        $query['per_page'] = $perPage;
    }
    return 'list.php' . (!empty($query) ? ('?' . http_build_query($query)) : '');
};

// Get departments for filter
$stmt = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmt->fetchAll();

// Get specializations for filter
$stmt = $conn->query("SELECT DISTINCT specialization FROM lecturers WHERE specialization IS NOT NULL AND specialization != '' ORDER BY specialization");
$specializations = $stmt->fetchAll();

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Lecturers List - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<style>
    /* Prevent horizontal scrolling */
    html, body {
        overflow-x: hidden;
        max-width: 100%;
    }
    
    .main-content {
        max-width: 100vw;
        overflow-x: hidden;
    }
    
    .content-area {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .topbar {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .topbar-right {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    /* Make topbar buttons smaller */
    .topbar-right .btn,
    .topbar-right form button {
        font-size: 0.8rem;
        padding: 0.35rem 0.65rem;
        line-height: 1.3;
    }
    
    .topbar-right .btn i {
        font-size: 0.75rem;
    }
    
    /* Responsive buttons */
    @media (max-width: 992px) {
        .topbar-right .btn,
        .topbar-right form button {
            font-size: 0.7rem;
            padding: 0.25rem 0.45rem;
        }
        
        .topbar-right form {
            margin-bottom: 0.5rem;
        }
    }
    
    @media (max-width: 768px) {
        .topbar {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .topbar-right {
            width: 100%;
            justify-content: flex-start;
        }
        
        .topbar-right .btn {
            margin-bottom: 0.25rem;
        }
    }
    
    /* Table responsiveness */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        max-width: 100%;
    }
    
    .table-responsive table {
        min-width: 100%;
        width: max-content;
    }
    
    .table td,
    .table th {
        overflow-wrap: break-word;
        word-wrap: break-word;
    }
    
    /* Adjust button sizes in actions column */
    .btn-xs {
        padding: 0.15rem 0.3rem;
        font-size: 0.7rem;
        line-height: 1.2;
        margin: 0.1rem;
    }

    .lecturer-success-message code {
        background: #f8f9fa;
        color: #212529;
        padding: 2px 6px;
        border-radius: 4px;
    }

    .lecturer-success-message .lecturer-credential-box {
        background: #e7f3ff;
        border-left: 4px solid #007bff;
        border-radius: 5px;
        color: #0f172a;
        margin-top: 8px;
        padding: 15px;
    }

    .lecturer-success-message .lecturer-credential-note {
        margin-top: 10px;
    }

    .lecturer-success-message .lecturer-credential-note.success {
        color: #28a745;
    }

    .lecturer-success-message .lecturer-credential-note.warning {
        color: #b45309;
    }

    .lecturer-success-message .lecturer-credential-note.error {
        color: #dc3545;
    }

    html[data-theme='dark'] .lecturer-success-message .lecturer-credential-box {
        background: #0f172a;
        border-left-color: #60a5fa;
        color: #e2e8f0;
    }

    html[data-theme='dark'] .lecturer-success-message code {
        background: #1f2937;
        border: 1px solid #334155;
        color: #f8fafc;
    }

    html[data-theme='dark'] .lecturer-success-message a {
        color: #93c5fd;
    }

    html[data-theme='dark'] .lecturer-success-message a:hover {
        color: #bfdbfe;
    }

    html[data-theme='dark'] .lecturer-success-message .lecturer-credential-note.success {
        color: #86efac;
    }

    html[data-theme='dark'] .lecturer-success-message .lecturer-credential-note.warning {
        color: #fcd34d;
    }

    html[data-theme='dark'] .lecturer-success-message .lecturer-credential-note.error {
        color: #fca5a5;
    }
    
    /* Actions column responsive */
    @media (max-width: 992px) {
        td[style*="white-space:nowrap"] {
            white-space: normal !important;
            min-width: 180px;
        }
        
        .btn-xs {
            margin-bottom: 0.2rem;
        }
    }
    
    /* Filter form responsive */
    @media (max-width: 768px) {
        .card-body .row .col-md-3,
        .card-body .row .col-md-2 {
            margin-bottom: 0.5rem;
        }
        
        .table-responsive {
            font-size: 0.75rem;
        }
        
        .table th,
        .table td {
            padding: 0.3rem !important;
            font-size: 0.75rem !important;
        }
    }
    
    @media (max-width: 576px) {
        .topbar-left h4 {
            font-size: 1rem !important;
        }
        
        .topbar-right .btn,
        .topbar-right form button {
            font-size: 0.65rem;
            padding: 0.2rem 0.35rem;
        }
        
        .table th,
        .table td {
            padding: 0.2rem !important;
            font-size: 0.7rem !important;
        }
        
        .btn-xs {
            padding: 0.1rem 0.2rem;
            font-size: 0.65rem;
        }
    }

    .lecturers-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .lecturers-toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .lecturers-meta { color: #64748b; font-size: 12px; font-weight: 600; }
    .lecturers-per-page label { margin-bottom: 0; font-size: 12px; font-weight: 600; color: #475569; }
    .lecturers-table { width: 100%; table-layout: fixed; }
    .lecturers-table th, .lecturers-table td { white-space: normal; word-break: break-word; vertical-align: top; }
    .lecturers-table .col-id { width: 120px; }
    .lecturers-table .col-name { width: 190px; }
    .lecturers-table .col-email { width: 180px; }
    .lecturers-table .col-dept { width: 130px; }
    .lecturers-table .col-spec { width: 150px; }
    .lecturers-table .col-status { width: 115px; }
    .lecturers-table .col-actions { width: 220px; }
    .lecturer-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; }
    .lecturers-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .lecturers-pagination .pagination { margin-bottom: 0; }
    @media (max-width: 992px) {
        .lecturers-table .col-email,
        .lecturers-table .col-spec { width: 140px; }
        .lecturers-table .col-actions { width: 190px; }
    }
</style>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content" style="max-width: 100vw; overflow-x: hidden;">
    <div class="topbar d-flex justify-content-between align-items-center" style="flex-wrap: wrap; gap: 0.5rem; padding: 0.75rem 1rem;">
        <div class="topbar-left">
            <h4 style="margin: 0;">Lecturers Management</h4>
        </div>
        <div class="topbar-right d-flex align-items-center" style="flex-wrap: wrap; gap: 0.5rem;">
            <a href="approvals.php" class="btn btn-sm btn-warning" style="margin: 0; font-size: 0.75rem; padding: 0.3rem 0.5rem;">⏳ Pending Approvals</a>
            <a href="add-lecturer.php" class="btn btn-sm btn-primary" style="margin: 0; font-size: 0.75rem; padding: 0.3rem 0.5rem;">➕ Add New Lecturer</a>
            
            <!-- Truncate Button -->
            <form method="POST" action="list.php" onsubmit="return confirm('DANGER: This will permanently delete ALL lecturers, their user accounts, and uploaded photos. This cannot be undone. Are you absolutely sure?');" style="display:inline; margin: 0;">
                <input type="hidden" name="action" value="truncate_lecturers">
                <button type="submit" class="btn btn-sm btn-danger" style="margin: 0; font-size: 0.75rem; padding: 0.3rem 0.5rem;">
                    <i class="fas fa-trash-alt"></i> Delete All Lecturers
                </button>
            </form>

            <?php include dirname(__DIR__, 3) . '/includes/notification_bell.php'; ?>
        </div>
    </div>
    
    <div class="content-area" style="max-width: 100%; overflow-x: hidden; padding: 1rem;">
        <?php 
        $successMessage = $session->getFlash('success');
        if ($successMessage): 
            $renderAsHtml = strpos($successMessage, '<strong>Lecturer added successfully!') === 0;
        ?>
            <div class="alert alert-success lecturer-success-message">
                <?php if ($renderAsHtml): ?>
                    <?php echo $successMessage; ?>
                <?php else: ?>
                    <?php echo e($successMessage); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php 
        $errorMessage = $session->getFlash('error');
        if ($errorMessage): 
        ?>
            <div class="alert alert-danger">
                <?php echo e($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="card" style="margin-bottom: 1rem;">
            <div class="card-body" style="padding: 1rem;">
                <form method="GET" action="">
                    <input type="hidden" name="per_page" value="<?php echo (int)$rowsPerPage; ?>">
                    <div class="row">
                        <div class="col-md-3 col-sm-6 mb-2">
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2">
                            <select name="department" class="form-control">
                                <option value="">All Departments</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['department']; ?>" <?php echo $department == $dept['department'] ? 'selected' : ''; ?>>
                                        <?php echo e($dept['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2">
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2">
                            <select name="designation" class="form-control">
                                <option value="">All Specializations</option>
                                <?php foreach($specializations as $spec): ?>
                                    <option value="<?php echo $spec['specialization']; ?>" <?php echo $designation == $spec['specialization'] ? 'selected' : ''; ?>>
                                        <?php echo e($spec['specialization']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-12 mb-2">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="<?php echo e($buildListUrl(1, $rowsPerPage, '', '', '', '')); ?>" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lecturers Table -->
        <div class="card" style="margin-bottom: 1rem;">
            <div class="card-header">
                <div class="lecturers-toolbar">
                    <span>Lecturers (<?php echo (int)$totalLecturers; ?>)</span>
                    <div class="lecturers-toolbar-right">
                        <span class="lecturers-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalLecturers; ?></span>
                        <form method="GET" class="form-inline lecturers-per-page">
                            <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?php echo e($search); ?>"><?php endif; ?>
                            <?php if ($department !== ''): ?><input type="hidden" name="department" value="<?php echo e($department); ?>"><?php endif; ?>
                            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
                            <?php if ($designation !== ''): ?><input type="hidden" name="designation" value="<?php echo e($designation); ?>"><?php endif; ?>
                            <label for="lecturersPerPage" class="mr-2">Rows</label>
                            <select id="lecturersPerPage" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($allowedPageSizes as $size): ?>
                                    <option value="<?php echo (int)$size; ?>" <?php echo $rowsPerPage === (int)$size ? 'selected' : ''; ?>><?php echo (int)$size; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body" style="overflow-x: hidden; padding: 0.5rem;">
                <?php if (count($lecturers) > 0): ?>
                    <div class="table-responsive" style="overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch;">
                        <table class="table table-hover table-sm lecturers-table" style="font-size: 0.875rem; margin-bottom: 0;">
                            <thead>
                                <tr>
                                    <th class="col-id">Lecturer ID</th>
                                    <th class="col-name">Name</th>
                                    <th class="col-email">Email</th>
                                    <th class="col-dept">Department</th>
                                    <th class="col-spec">Designation</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($lecturers as $lecturer): ?>
                                    <tr>
                                        <td><strong><?php echo e($lecturer['lecturer_id']); ?></strong></td>
                                        <td>
                                            <?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>
                                            <br><small class="text-muted" style="font-size: 0.75rem;"><?php echo e($lecturer['qualifications'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php echo e($lecturer['email']); ?>
                                        </td>
                                        <td><?php echo e($lecturer['department'] ?? 'N/A'); ?></td>
                                        <td><?php echo e($lecturer['specialization'] ?? 'N/A'); ?></td>
                                        <td style="font-size: 0.8rem;">
                                            <?php
                                            $statusClass = 'secondary';
                                            $statusColor = 'gray';
                                            switch($lecturer['status']) {
                                                case 'active':
                                                    $statusClass = 'success';
                                                    $statusColor = 'green';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'warning';
                                                    $statusColor = 'orange';
                                                    break;
                                                case 'rejected':
                                                    $statusClass = 'danger';
                                                    $statusColor = 'red';
                                                    break;
                                                case 'inactive':
                                                    $statusClass = 'secondary';
                                                    $statusColor = 'gray';
                                                    break;
                                            }
                                            ?>
                                            <span style="color:<?php echo $statusColor; ?>">●</span>
                                            <span class="badge badge-<?php echo $statusClass; ?>" style="font-size: 0.7rem; margin-left: 0.25rem;">
                                                <?php echo e(ucfirst($lecturer['status'])); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 0.25rem;">
                                            <div class="lecturer-actions">
                                                <a href="view.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-info btn-xs" title="View"><i class="fas fa-eye"></i></a>
                                                <a href="edit.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-warning btn-xs" title="Edit"><i class="fas fa-edit"></i></a>
                                                <a href="reset_password.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-secondary btn-xs" title="Reset Password"><i class="fas fa-key"></i></a>
                                                <a href="send_invite.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-primary btn-xs" title="Send Invite"><i class="fas fa-envelope"></i></a>
                                                <a href="../courses/list.php?lecturer_id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-success btn-xs" title="Assign Courses"><i class="fas fa-user-plus"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="lecturers-footer pt-2 px-1">
                        <div class="lecturers-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalLecturers; ?> lecturers</div>
                        <div class="lecturers-pagination">
                            <ul class="pagination pagination-sm">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildListUrl(max(1, $currentPage - 1), $rowsPerPage, $search, $department, $status, $designation)); ?>">Previous</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo e($buildListUrl($p, $rowsPerPage, $search, $department, $status, $designation)); ?>"><?php echo (int)$p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildListUrl(min($totalPages, $currentPage + 1), $rowsPerPage, $search, $department, $status, $designation)); ?>">Next</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-center">No lecturers found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
