<?php
/**
 * Students List - Admin
 */
require_once '../../../config.php';

// Initialize with admin role for session isolation
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
Security::requireRole('admin');
$currentUser = $auth->getCurrentUser();

// Get filter parameters
$search = $_GET['search'] ?? '';
$program = $_GET['program'] ?? '';
$status = $_GET['status'] ?? '';
$level = $_GET['level'] ?? '';

// Build query
$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT s.*, p.program_code, p.program_name, u.status as user_status
        FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (s.student_id LIKE :search OR s.first_name LIKE :search 
              OR s.last_name LIKE :search OR s.email LIKE :search)";
    $params['search'] = "%$search%";
}

if ($program) {
    $sql .= " AND s.program_id = :program";
    $params['program'] = $program;
}

if ($status) {
    $sql .= " AND s.status = :status";
    $params['status'] = $status;
}

if ($level) {
    $sql .= " AND s.level_year = :level";
    $params['level'] = $level;
}

$sql .= " ORDER BY s.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get programs for filter
$stmt = $conn->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
$programs = $stmt->fetchAll();

$pageTitle = 'Students List - ' . APP_NAME;
include '../../../includes/header.php';
?>

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
            <a href="add.php" class="btn btn-primary">➕ Add New Student</a>
            <div class="user-dropdown">
                <button class="user-dropdown-toggle" id="userDropdown">
                    <div class="user-avatar-sm">
                        <?php echo strtoupper(substr($currentUser['profile']['first_name'] ?? 'A', 0, 1) . substr($currentUser['profile']['last_name'] ?? 'D', 0, 1)); ?>
                    </div>
                    <i class="dropdown-arrow">▼</i>
                </button>
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <a href="../dashboard.php" class="dropdown-item">
                        <i>🏠</i> Dashboard
                    </a>
                    <a href="../profile.php" class="dropdown-item">
                        <i>👤</i> Profile
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo BASE_URL; ?>/views/auth/logout.php" class="dropdown-item logout-item">
                        <i>🚪</i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success">
                <?php echo e($session->getFlash('success')); ?>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="card">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="row">
                        <div class="col-md-3">
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="program" class="form-control">
                                <option value="">All Programs</option>
                                <?php foreach($programs as $prog): ?>
                                    <option value="<?php echo $prog['id']; ?>" <?php echo $program == $prog['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($prog['program_code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="graduated" <?php echo $status == 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                                <option value="suspended" <?php echo $status == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="level" class="form-control">
                                <option value="">All Levels</option>
                                <option value="1" <?php echo $level == '1' ? 'selected' : ''; ?>>Year 1</option>
                                <option value="2" <?php echo $level == '2' ? 'selected' : ''; ?>>Year 2</option>
                                <option value="3" <?php echo $level == '3' ? 'selected' : ''; ?>>Year 3</option>
                                <option value="4" <?php echo $level == '4' ? 'selected' : ''; ?>>Year 4</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="list.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="card">
            <div class="card-header">
                Students (<?php echo count($students); ?>)
            </div>
            <div class="card-body">
                <?php if (count($students) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Program</th>
                                    <th>Level</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): ?>
                                    <tr>
                                        <td><strong><?php echo e($student['student_id']); ?></strong></td>
                                        <td>
                                            <?php echo e($student['first_name'] . ' ' . $student['last_name']); ?>
                                            <br><small class="text-muted"><?php echo e($student['gender']); ?></small>
                                        </td>
                                        <td><?php echo e($student['email']); ?></td>
                                        <td>
                                            <?php echo e($student['program_code']); ?>
                                            <br><small class="text-muted"><?php echo e($student['program_name']); ?></small>
                                        </td>
                                        <td>Year <?php echo $student['level_year']; ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo Helper::getStatusColor($student['status']); ?>">
                                                <?php echo e($student['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-info">View</a>
                                            <a href="edit.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No students found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
