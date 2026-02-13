<?php
/**
 * Courses List - Admin
 */
require_once '../../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get filter parameters
$search = $_GET['search'] ?? '';
$program = $_GET['program'] ?? '';
$level = $_GET['level'] ?? '';

// Build query
$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT c.*, p.program_code, p.program_name
        FROM courses c
        INNER JOIN programs p ON c.program_id = p.id
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (c.course_code LIKE :search OR c.course_name LIKE :search)";
    $params['search'] = "%$search%";
}

if ($program) {
    $sql .= " AND c.program_id = :program";
    $params['program'] = $program;
}

if ($level) {
    $sql .= " AND c.level_year = :level";
    $params['level'] = $level;
}

$sql .= " ORDER BY c.course_code";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll();

// Get programs for filter
$stmt = $conn->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
$programs = $stmt->fetchAll();

// Get academic years for filter
$stmt = $conn->query("SELECT DISTINCT level_year FROM courses WHERE level_year IS NOT NULL AND level_year != '' ORDER BY level_year DESC");
$academicYears = $stmt->fetchAll();

// Notifications (admin sees all system notifications)
$stmt = $conn->prepare("SELECT * FROM notifications WHERE read_status = 'unread' ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'Courses List - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
/* Override global overflow-x hidden for table scrolling */
.content-area .table-responsive {
    overflow-x: auto !important;
    max-width: 100% !important;
}

.content-area .card-body {
    overflow-x: visible !important;
}

/* Ensure vertical scrolling works */
html, body {
    overflow-y: auto !important;
    height: auto !important;
}

.main-content {
    overflow-y: auto !important;
    min-height: 100vh !important;
}

.content-area {
    overflow-y: visible !important;
}

.table-responsive table {
    min-width: 800px;
    white-space: nowrap;
}

/* Ensure table cells don't break words unnecessarily */
.table-responsive table th,
.table-responsive table td {
    white-space: nowrap;
    padding: 8px 12px;
}

/* Allow course names to wrap if needed */
.table-responsive table td:nth-child(2) {
    white-space: normal;
    max-width: 200px;
    word-wrap: break-word;
}

/* Allow program names to wrap */
.table-responsive table td:nth-child(3) {
    white-space: normal;
    max-width: 150px;
    word-wrap: break-word;
}

/* Mobile responsive adjustments */
@media (max-width: 768px) {
    .content-area .card-body .row .col-md-4,
    .content-area .card-body .row .col-md-3,
    .content-area .card-body .row .col-md-2 {
        margin-bottom: 10px;
    }
    
    .table-responsive table {
        min-width: 600px;
        font-size: 12px;
    }
    
    .table-responsive table th,
    .table-responsive table td {
        padding: 6px 8px;
    }
    
    /* Hide less critical columns on mobile */
    .table-responsive table th:nth-child(5),
    .table-responsive table td:nth-child(5),
    .table-responsive table th:nth-child(6),
    .table-responsive table td:nth-child(6) {
        display: none;
    }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Courses Management</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <a href="add.php" class="btn btn-primary">➕ Add New Course</a>
            <?php include '../../../includes/notification_bell.php'; ?>
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
                    <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
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
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Search by code or name..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-3">
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
                            <select name="level" class="form-control">
                                <option value="">All Levels</option>
                                <?php foreach($academicYears as $year): ?>
                                    <option value="<?php echo $year['level_year']; ?>" <?php echo $level == $year['level_year'] ? 'selected' : ''; ?>>
                                        Year <?php echo e($year['level_year']); ?>
                                    </option>
                                <?php endforeach; ?>
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
        
        <!-- Courses Table -->
        <div class="card">
            <div class="card-header">
                Courses (<?php echo count($courses); ?>)
            </div>
            <div class="card-body">
                <?php if (count($courses) > 0): ?>
                    <div class="table-responsive" style="overflow-x: auto; max-width: 100%;">
                        <table class="table table-hover" style="min-width: 800px;">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Program</th>
                                    <th>Level/Year</th>
                                    <th>Credit Hours</th>
                                    <th>Semester</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($courses as $course): ?>
                                    <tr>
                                        <td><strong><?php echo e($course['course_code']); ?></strong></td>
                                        <td><?php echo e($course['course_name']); ?></td>
                                        <td>
                                            <?php echo e($course['program_code']); ?>
                                            <br><small class="text-muted"><?php echo e($course['program_name']); ?></small>
                                        </td>
                                        <td>Year <?php echo e($course['level_year']); ?></td>
                                        <td><?php echo $course['credit_hours']; ?></td>
                                        <td>
                                            <?php
                                            $semesters = ['', 'Semester 1', 'Semester 2', 'Both'];
                                            echo $semesters[$course['semester_offered']] ?? '';
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo Helper::getStatusColor($course['status']); ?>">
                                                <?php echo e($course['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info">View</a>
                                            <a href="edit.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                            <a href="assign.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-success">Assign</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No courses found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
