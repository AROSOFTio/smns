<?php
/**
 * Courses List - Admin
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

$pageTitle = 'Courses List - ' . APP_NAME;
include '../../../includes/header.php';
?>

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
        
        <!-- Courses Table -->
        <div class="card">
            <div class="card-header">
                Courses (<?php echo count($courses); ?>)
            </div>
            <div class="card-body">
                <?php if (count($courses) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Program</th>
                                    <th>Level</th>
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
                                        <td>Year <?php echo $course['level_year']; ?></td>
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
