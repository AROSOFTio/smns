<?php
/**
 * Students List - Admin (Fixed Version)
 */
require_once '../../../config.php';

// Simple session handling


// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get filter parameters
$search = $_GET['search'] ?? '';
$program = $_GET['program'] ?? '';
$status = $_GET['status'] ?? '';
$level = $_GET['level'] ?? '';
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

$fromWhereSql = " FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE 1=1";

$params = [];

if ($search) {
    $fromWhereSql .= " AND (s.student_id LIKE :search OR s.first_name LIKE :search 
              OR s.last_name LIKE :search OR s.email LIKE :search)";
    $params['search'] = "%$search%";
}

if ($program) {
    $fromWhereSql .= " AND s.program_id = :program";
    $params['program'] = $program;
}

if ($status) {
    $fromWhereSql .= " AND s.status = :status";
    $params['status'] = $status;
}

if ($level) {
    $fromWhereSql .= " AND s.level_year = :level";
    $params['level'] = $level;
}

$totalStudents = 0;
try {
    $countStmt = $conn->prepare("SELECT COUNT(*)" . $fromWhereSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue(':' . $k, $v);
    }
    $countStmt->execute();
    $totalStudents = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $totalStudents = 0;
}
$totalPages = max(1, (int)ceil($totalStudents / max($rowsPerPage, 1)));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $rowsPerPage;

$students = [];
try {
    $sql = "SELECT s.*, p.program_code, p.program_name, u.status as user_status"
        . $fromWhereSql
        . " ORDER BY s.created_at DESC LIMIT :limit_rows OFFSET :offset_rows";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit_rows', $rowsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $students = $stmt->fetchAll();
} catch (Exception $e) {
    $students = [];
}
$displayStart = $totalStudents > 0 ? ($offset + 1) : 0;
$displayEnd = $totalStudents > 0 ? min($offset + count($students), $totalStudents) : 0;
$buildListUrl = static function (int $page, int $perPage, string $search, string $program, string $status, string $level): string {
    $query = [];
    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($program !== '') {
        $query['program'] = $program;
    }
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($level !== '') {
        $query['level'] = $level;
    }
    if ($page > 1) {
        $query['page'] = $page;
    }
    if ($perPage > 0) {
        $query['per_page'] = $perPage;
    }
    return 'list.php' . (!empty($query) ? ('?' . http_build_query($query)) : '');
};

// Get programs for filter
$stmt = $conn->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
$programs = $stmt->fetchAll();

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Students List - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
body { overflow-x: hidden; }
.main-content { max-width: 100%; overflow-x: hidden; }
.content-area { overflow-x: hidden; }
.table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
.students-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.students-toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.students-meta { color: #64748b; font-size: 12px; font-weight: 600; }
.students-per-page label { margin-bottom: 0; font-size: 12px; font-weight: 600; color: #475569; }
.students-table { width: 100%; table-layout: fixed; }
.students-table th, .students-table td { white-space: normal; word-break: break-word; vertical-align: top; }
.students-table .col-id { width: 120px; }
.students-table .col-name { width: 180px; }
.students-table .col-adm { width: 130px; }
.students-table .col-program { width: 170px; }
.students-table .col-level { width: 72px; }
.students-table .col-status { width: 96px; }
.students-table .col-actions { width: 220px; }
.students-name-wrap, .students-program-wrap { overflow: hidden; text-overflow: ellipsis; }
.btn-group .btn { padding: 0.25rem 0.5rem; font-size: 0.82rem; }
.dropdown-menu { min-width: 180px; z-index: 9999; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border: 1px solid #dee2e6; }
.students-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.students-pagination .pagination { margin-bottom: 0; }
@media (max-width: 1200px) {
    .students-table { font-size: 0.82rem; }
}
@media (max-width: 768px) {
    .students-table .col-adm,
    .students-table .col-program { width: 110px; }
    .students-table .col-actions { width: 190px; }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Students Management</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../../includes/notification_bell.php'; ?>
            <div class="user-dropdown">
                <button class="user-dropdown-toggle" id="userDropdown">
                    <div class="user-avatar-sm">
                        <?php echo strtoupper(substr($currentUser['profile']['first_name'] ?? 'A', 0, 1) . substr($currentUser['profile']['last_name'] ?? 'D', 0, 1)); ?>
                    </div>
                    <i class="dropdown-arrow">▼</i>
                </button>
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <div class="user-profile-meta">
                        <div class="user-fullname"><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></div>
                        <?php if (!empty($currentUser['profile']['email'])): ?>
                            <div class="user-email"><i class="fas fa-envelope"></i> <?php echo e($currentUser['profile']['email']); ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="../dashboard.php" class="dropdown-item">
                        <i>🏠</i> Dashboard
                    </a>
                    <a href="../profile.php" class="dropdown-item">
                        <i>👤</i> Profile
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $session->getFlash('success'); ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="" class="form-row">
                    <input type="hidden" name="per_page" value="<?php echo (int)$rowsPerPage; ?>">
                    <div class="col-md-3 mb-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search students..." value="<?php echo e($search); ?>">
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="program" class="form-control form-control-sm">
                            <option value="">All Programs</option>
                            <?php foreach($programs as $prog): ?>
                                <option value="<?php echo $prog['id']; ?>" <?php echo $program == $prog['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($prog['program_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="status" class="form-control form-control-sm">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="graduated" <?php echo $status == 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                            <option value="suspended" <?php echo $status == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="level" class="form-control form-control-sm">
                            <option value="">All Levels</option>
                            <option value="1" <?php echo $level == '1' ? 'selected' : ''; ?>>Year 1</option>
                            <option value="2" <?php echo $level == '2' ? 'selected' : ''; ?>>Year 2</option>
                            <option value="3" <?php echo $level == '3' ? 'selected' : ''; ?>>Year 3</option>
                            <option value="4" <?php echo $level == '4' ? 'selected' : ''; ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button type="submit" class="btn btn-primary btn-sm mr-1">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="<?php echo e($buildListUrl(1, $rowsPerPage, '', '', '', '')); ?>" class="btn btn-secondary btn-sm mr-1">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        <a href="<?php echo BASE_URL; ?>/views/admin/students/add.php" class="btn btn-success btn-sm">
                            <i class="fas fa-plus"></i> Add New
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="card">
            <div class="card-header">
                <div class="students-toolbar">
                    <span><i class="fas fa-users"></i> Students (<?php echo (int)$totalStudents; ?>)</span>
                    <div class="students-toolbar-right">
                        <span class="students-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalStudents; ?></span>
                        <form method="GET" class="form-inline students-per-page">
                            <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?php echo e($search); ?>"><?php endif; ?>
                            <?php if ($program !== ''): ?><input type="hidden" name="program" value="<?php echo e($program); ?>"><?php endif; ?>
                            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
                            <?php if ($level !== ''): ?><input type="hidden" name="level" value="<?php echo e($level); ?>"><?php endif; ?>
                            <label for="studentsPerPage" class="mr-2">Rows</label>
                            <select id="studentsPerPage" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($allowedPageSizes as $size): ?>
                                    <option value="<?php echo (int)$size; ?>" <?php echo $rowsPerPage === (int)$size ? 'selected' : ''; ?>><?php echo (int)$size; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (count($students) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0 students-table">
                            <thead class="thead-light">
                                <tr>
                                    <th class="col-id">Student ID</th>
                                    <th class="col-name">Name</th>
                                    <th class="col-adm">Admission #</th>
                                    <th class="col-program">Program</th>
                                    <th class="col-level">Level</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-actions text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): ?>
                                    <tr>
                                        <td><strong><?php echo e($student['student_id']); ?></strong></td>
                                        <td>
                                            <div class="students-name-wrap" title="<?php echo e($student['first_name'] . ' ' . $student['last_name']); ?>">
                                                <?php echo e($student['first_name'] . ' ' . $student['last_name']); ?>
                                            </div>
                                            <small class="text-muted"><?php echo e($student['gender']); ?></small>
                                        </td>
                                        <td><?php echo e($student['admission_number'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="students-program-wrap" title="<?php echo e($student['program_name']); ?>">
                                                <strong><?php echo e($student['program_code']); ?></strong>
                                            </div>
                                            <small class="text-muted d-block"><?php echo e($student['program_name']); ?></small>
                                        </td>
                                        <td><span class="badge badge-secondary">Y<?php echo $student['level_year']; ?></span></td>
                                        <td>
                                            <span class="badge badge-<?php echo Helper::getStatusColor($student['status']); ?>">
                                                <?php echo e(ucfirst($student['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="view.php?id=<?php echo $student['id']; ?>" class="btn btn-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $student['id']; ?>" class="btn btn-warning" title="Edit Student">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="audit.php?id=<?php echo $student['id']; ?>" class="btn btn-dark" title="Profile Audit">
                                                    <i class="fas fa-history"></i>
                                                </a>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown" title="More Actions" data-boundary="viewport">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <div class="dropdown-menu dropdown-menu-right" style="position: fixed !important; z-index: 9999 !important;">
                                                        <a class="dropdown-item" href="reset_password.php?id=<?php echo $student['id']; ?>">
                                                            <i class="fas fa-key"></i> Reset Password
                                                        </a>
                                                        <a class="dropdown-item" href="send_invite.php?id=<?php echo $student['id']; ?>">
                                                            <i class="fas fa-envelope"></i> Send Invite
                                                        </a>
                                                        <a class="dropdown-item" href="graduation-awards.php?id=<?php echo $student['id']; ?>">
                                                            <i class="fas fa-certificate"></i> Graduation & Awards
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-danger" href="delete.php?id=<?php echo $student['id']; ?>" onclick="return confirm('Are you sure you want to delete this student?')">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="students-footer p-3 border-top">
                        <div class="students-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalStudents; ?> students</div>
                        <div class="students-pagination">
                            <ul class="pagination pagination-sm">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildListUrl(max(1, $currentPage - 1), $rowsPerPage, $search, $program, $status, $level)); ?>">Previous</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo e($buildListUrl($p, $rowsPerPage, $search, $program, $status, $level)); ?>"><?php echo (int)$p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildListUrl(min($totalPages, $currentPage + 1), $rowsPerPage, $search, $program, $status, $level)); ?>">Next</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <p class="text-muted mb-0">No students found matching your criteria</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Fix dropdown positioning in table
$(document).ready(function() {
    $('.dropdown-toggle').on('click', function(e) {
        e.stopPropagation();
        
        // Close other dropdowns
        $('.dropdown-menu').not($(this).siblings('.dropdown-menu')).removeClass('show');
        
        // Toggle current dropdown
        var dropdown = $(this).siblings('.dropdown-menu');
        dropdown.toggleClass('show');
        
        // Position the dropdown
        if (dropdown.hasClass('show')) {
            var button = $(this);
            var buttonOffset = button.offset();
            var buttonHeight = button.outerHeight();
            var buttonWidth = button.outerWidth();
            
            dropdown.css({
                'position': 'fixed',
                'top': (buttonOffset.top + buttonHeight) + 'px',
                'left': (buttonOffset.left + buttonWidth - dropdown.outerWidth()) + 'px',
                'z-index': '9999'
            });
        }
    });
    
    // Close dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.btn-group').length) {
            $('.dropdown-menu').removeClass('show');
        }
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>
