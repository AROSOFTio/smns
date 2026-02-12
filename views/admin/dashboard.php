<?php
/**
 * Admin Dashboard
 */
require_once '../../config.php';

// Initialize with admin role for session isolation
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
Security::requireRole('admin');
$currentUser = $auth->getCurrentUser();

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Total students
$stmt = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
$totalStudents = $stmt->fetch()['count'];

// Total lecturers
$stmt = $conn->query("SELECT COUNT(*) as count FROM lecturers WHERE status = 'active'");
$totalLecturers = $stmt->fetch()['count'];

// Total courses
$stmt = $conn->query("SELECT COUNT(*) as count FROM courses WHERE status = 'active'");
$totalCourses = $stmt->fetch()['count'];

// Pending registrations
$stmt = $conn->query("SELECT COUNT(*) as count FROM course_registrations WHERE status = 'pending'");
$pendingRegistrations = $stmt->fetch()['count'];

// Pending results
$stmt = $conn->query("SELECT COUNT(*) as count FROM results WHERE status = 'submitted'");
$pendingResults = $stmt->fetch()['count'];

// Recent activities
$logger = new Logger();
$recentActivities = $logger->getRecentActivities(10);

// Current semester
$currentSemester = Helper::getCurrentSemester();

$pageTitle = 'Admin Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Dashboard</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($currentUser['profile']['first_name'] ?? 'A', 0, 1) . substr($currentUser['profile']['last_name'] ?? 'D', 0, 1)); ?>
                        </div>
                        <div>
                            <strong><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></strong>
                            <br><small>Administrator</small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item">
                            <i>👤</i> My Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i>⚙️</i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/auth/logout.php" class="dropdown-item logout-item">
                            <i>🚪</i> Logout
                        </a>
                    </div>
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
        
        <!-- Stats Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="stats-card primary">
                    <p>Total Students</p>
                    <h3><?php echo number_format($totalStudents); ?></h3>
                    <small>Active students enrolled</small>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stats-card success">
                    <p>Total Lecturers</p>
                    <h3><?php echo number_format($totalLecturers); ?></h3>
                    <small>Active teaching staff</small>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stats-card info">
                    <p>Total Courses</p>
                    <h3><?php echo number_format($totalCourses); ?></h3>
                    <small>Available courses</small>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stats-card warning">
                    <p>Pending Items</p>
                    <h3><?php echo number_format($pendingRegistrations + $pendingResults); ?></h3>
                    <small>Require attention</small>
                </div>
            </div>
        </div>
        
        <!-- Current Semester Info -->
        <?php if ($currentSemester): ?>
        <div class="card">
            <div class="card-header">
                Current Semester Information
            </div>
            <div class="card-body">
                <h4><?php echo e($currentSemester['semester_name']); ?></h4>
                <p><strong>Start Date:</strong> <?php echo Helper::formatDate($currentSemester['start_date']); ?></p>
                <p><strong>End Date:</strong> <?php echo Helper::formatDate($currentSemester['end_date']); ?></p>
                <p><strong>Status:</strong> <span class="badge badge-<?php echo Helper::getStatusColor($currentSemester['status']); ?>"><?php echo e($currentSemester['status']); ?></span></p>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Pending Actions -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Pending Actions
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <tbody>
                                <tr>
                                    <td>Pending Course Registrations</td>
                                    <td class="text-right">
                                        <span class="badge badge-warning"><?php echo $pendingRegistrations; ?></span>
                                        <a href="<?php echo BASE_URL; ?>/views/admin/registrations/pending.php" class="btn btn-sm btn-primary">View</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Submitted Results (Awaiting Review)</td>
                                    <td class="text-right">
                                        <span class="badge badge-info"><?php echo $pendingResults; ?></span>
                                        <a href="<?php echo BASE_URL; ?>/views/admin/results/submitted.php" class="btn btn-sm btn-primary">Review</a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Quick Actions
                    </div>
                    <div class="card-body">
                        <a href="<?php echo BASE_URL; ?>/views/admin/students/add.php" class="btn btn-primary mb-2" style="width:100%">➕ Add New Student</a>
                        <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/add.php" class="btn btn-success mb-2" style="width:100%">➕ Add New Lecturer</a>
                        <a href="<?php echo BASE_URL; ?>/views/admin/courses/add.php" class="btn btn-info mb-2" style="width:100%">➕ Add New Course</a>
                        <a href="<?php echo BASE_URL; ?>/views/admin/announcements/add.php" class="btn btn-warning mb-2" style="width:100%">📢 Create Announcement</a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activities -->
        <div class="card">
            <div class="card-header">
                Recent System Activities
            </div>
            <div class="card-body">
                <?php if (count($recentActivities) > 0): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Description</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentActivities as $activity): ?>
                                <tr>
                                    <td><?php echo e($activity['username'] ?? 'System'); ?></td>
                                    <td><span class="badge badge-info"><?php echo e($activity['action']); ?></span></td>
                                    <td><?php echo e($activity['module']); ?></td>
                                    <td><?php echo e(Helper::truncate($activity['description'] ?? '', 50)); ?></td>
                                    <td><?php echo Helper::timeAgo($activity['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center">No recent activities</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
