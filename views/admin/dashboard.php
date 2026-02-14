<?php
/**
 * Admin Dashboard
 */
require_once '../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize session and auth with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

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

// Get assigned courses with lecturers
$stmt = $conn->prepare("
    SELECT ca.*, c.course_code, c.course_name, l.first_name, l.last_name, l.lecturer_id,
           s.semester_name, ay.year_name, p.program_code, p.program_name
    FROM course_assignments ca
    INNER JOIN courses c ON ca.course_id = c.id
    INNER JOIN lecturers l ON ca.lecturer_id = l.id
    INNER JOIN semesters s ON ca.semester_id = s.id
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    INNER JOIN programs p ON c.program_id = p.id
    WHERE ca.status = 'active' AND c.status = 'active' AND l.status = 'active'
    ORDER BY ca.assigned_date DESC
    LIMIT 4
");
$stmt->execute();
$assignedCourses = $stmt->fetchAll();

// Pending lecturer approvals
$stmt = $conn->query("SELECT COUNT(*) as count FROM lecturers WHERE status = 'pending'");
$pendingLecturerApprovals = $stmt->fetch()['count'];

// Recent activities
$logger = new Logger();
$recentActivities = $logger->getRecentActivities(10);

// Login sessions with duration
$loginSessions = $logger->getLoginSessions(15);

// Current semester
$currentSemester = Helper::getCurrentSemester();

// Notifications (admin sees all system notifications)
$stmt = $conn->prepare("SELECT * FROM notifications WHERE read_status = 'unread' ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$unreadNotifications = $stmt->fetchAll();

// Saved (archived) notifications for the current admin (dashboard widget)
// Ensure the archive table exists (safe to run multiple times)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS notification_archive (
        id INT PRIMARY KEY AUTO_INCREMENT,
        notification_id INT NULL,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        link VARCHAR(255) NULL,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore - if creation fails, we'll handle on select
}

try {
    $savedStmt = $conn->prepare("SELECT * FROM notification_archive WHERE user_id = :uid ORDER BY archived_at DESC LIMIT 6");
    $savedStmt->execute(['uid' => $currentUser['id']]);
    $savedNotifications = $savedStmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist or other DB issue — degrade gracefully
    $savedNotifications = [];
}

$pageTitle = 'Admin Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Dashboard</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar" style="width: 35px; height: 35px; font-size: 14px; margin-bottom: 5px;">
                            <?php echo strtoupper(substr($currentUser['profile']['first_name'] ?? 'A', 0, 1) . substr($currentUser['profile']['last_name'] ?? 'D', 0, 1)); ?>
                        </div>
                        <div style="text-align: center;">
                            <strong><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></strong>
                            <br><small>Admin</small>
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
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
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
        
        <!-- Enhanced Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon students-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalStudents); ?></h3>
                    <p>Total Students</p>
                    <div class="stat-change positive"><i class="fas fa-arrow-up"></i> Active</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon lecturers-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalLecturers); ?></h3>
                    <p>Total Lecturers</p>
                    <div class="stat-change positive"><i class="fas fa-arrow-up"></i> Active</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon courses-icon"><i class="fas fa-book"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalCourses); ?></h3>
                    <p>Total Courses</p>
                    <div class="stat-change neutral"><i class="fas fa-minus"></i> Current</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon pending-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($pendingRegistrations + $pendingResults); ?></h3>
                    <p>Pending Actions</p>
                    <div class="stat-change"><i class="fas fa-exclamation-circle"></i> Needs Review</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon approval-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($pendingLecturerApprovals); ?></h3>
                    <p>Lecturer Approvals</p>
                    <div class="stat-change <?php echo $pendingLecturerApprovals > 0 ? 'warning' : 'positive'; ?>">
                        <i class="fas fa-<?php echo $pendingLecturerApprovals > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                        <?php echo $pendingLecturerApprovals > 0 ? 'Pending' : 'All Clear'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions Section -->
        <div class="quick-actions-section">
            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            <div class="action-grid">
                <a href="users/add.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                    <h4>Add User</h4>
                    <p>Create new admin, lecturer, or student account</p>
                </a>
                
                <a href="courses/list.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-list-alt"></i></div>
                    <h4>Manage Courses</h4>
                    <p>View, edit, and organize course curriculum</p>
                </a>
                
                <a href="results/approve.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-check-circle"></i></div>
                    <h4>Approve Results</h4>
                    <p><?php echo $pendingResults; ?> results awaiting approval</p>
                </a>
                
                <a href="students/list.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-users"></i></div>
                    <h4>View Students</h4>
                    <p>Browse and manage student records</p>
                </a>
            </div>
        </div>

        <!-- Assigned Courses Section -->
        <div class="assigned-courses-section">
            <div class="section-header">
                <h3><i class="fas fa-graduation-cap"></i> Recent Courses</h3>
                <a href="courses/list.php" class="btn btn-sm btn-outline-primary">View All Courses</a>
            </div>
            <div class="assigned-courses-grid">
                <?php if (!empty($assignedCourses)): ?>
                    <?php foreach ($assignedCourses as $assignment): ?>
                        <div class="assignment-card">
                            <div class="assignment-header">
                                <div class="course-info">
                                    <h5><?php echo e($assignment['course_code']); ?></h5>
                                    <p><?php echo e($assignment['course_name']); ?></p>
                                    <small class="text-muted"><?php echo e($assignment['program_code'] . ' - ' . $assignment['program_name']); ?></small>
                                </div>
                                <div class="assignment-status">
                                    <span class="badge badge-<?php echo Helper::getStatusColor($assignment['status']); ?>">
                                        <?php echo e(ucfirst($assignment['status'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="assignment-details">
                                <div class="lecturer-info">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <strong><?php echo e($assignment['first_name'] . ' ' . $assignment['last_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo e($assignment['lecturer_id']); ?></small>
                                </div>
                                <div class="semester-info">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo e($assignment['year_name'] . ' - ' . $assignment['semester_name']); ?>
                                </div>
                                <div class="assignment-date">
                                    <i class="fas fa-clock"></i>
                                    <?php echo Helper::formatDate($assignment['assigned_date']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h4>No Course Assignments</h4>
                        <p>No courses have been assigned to lecturers yet.</p>
                        <a href="courses/assign.php" class="btn btn-primary">Assign First Course</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity & Sessions - Full Width, Collapsible -->
        <div class="row">
            <div class="col-md-7">
                <div class="recent-activity foldable-card">
                    <h3 class="foldable-header" data-target="activityBody">
                        <span><i class="fas fa-history"></i> Recent System Activity</span>
                        <i class="fas fa-chevron-up fold-arrow"></i>
                    </h3>
                    <div class="foldable-body" id="activityBody">
                    <?php if (!empty($recentActivities)): ?>
                        <?php foreach ($recentActivities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php 
                                    switch($activity['action']) {
                                        case 'login': echo '<i class="fas fa-sign-in-alt"></i>'; break;
                                        case 'logout': echo '<i class="fas fa-sign-out-alt"></i>'; break;
                                        case 'create': echo '<i class="fas fa-plus"></i>'; break;
                                        case 'update': echo '<i class="fas fa-edit"></i>'; break;
                                        case 'delete': echo '<i class="fas fa-trash"></i>'; break;
                                        case 'approve': echo '<i class="fas fa-check"></i>'; break;
                                        case 'submit': echo '<i class="fas fa-paper-plane"></i>'; break;
                                        default: echo '<i class="fas fa-circle"></i>';
                                    }
                                    ?>
                                </div>
                                <div class="activity-details">
                                    <h5><?php echo e($activity['description']); ?></h5>
                                    <p>
                                        <i class="fas fa-clock"></i> 
                                        <span class="activity-time" title="<?php echo Helper::formatDateTime($activity['created_at'], 'M d, Y g:i:s A'); ?>">
                                            <?php echo Helper::formatDateTime($activity['created_at'], 'g:i:s A'); ?>
                                        </span>
                                        <span class="activity-date"><?php echo Helper::formatDate($activity['created_at'], 'M d, Y'); ?></span>
                                        <span class="activity-module"><?php echo e($activity['module']); ?></span>
                                        <?php if (isset($activity['username'])): ?>
                                            <span class="activity-user"><i class="fas fa-user"></i> <?php echo e($activity['username']); ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h4>No Recent Activity</h4>
                            <p>System activities will appear here as they occur.</p>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-5">
                <!-- User Sessions Section -->
                <div class="user-sessions-card foldable-card">
                    <h3 class="foldable-header" data-target="sessionsBody">
                        <span><i class="fas fa-user-clock"></i> User Login Sessions</span>
                        <i class="fas fa-chevron-up fold-arrow"></i>
                    </h3>
                    <div class="foldable-body" id="sessionsBody">
                    <div class="table-responsive">
                        <table class="sessions-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Login Time</th>
                                    <th>Logout Time</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($loginSessions)): ?>
                                    <?php foreach ($loginSessions as $session): ?>
                                        <tr>
                                            <td>
                                                <span class="session-user">
                                                    <i class="fas fa-user"></i> <?php echo e($session['username'] ?? 'Unknown'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="session-time login">
                                                    <i class="fas fa-sign-in-alt"></i>
                                                    <?php echo Helper::formatDateTime($session['login_time'], 'M d, g:i:s A'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($session['logout_time']): ?>
                                                    <span class="session-time logout">
                                                        <i class="fas fa-sign-out-alt"></i>
                                                        <?php echo Helper::formatDateTime($session['logout_time'], 'M d, g:i:s A'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="session-active">
                                                        <i class="fas fa-circle"></i> Active
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($session['session_duration_minutes'] !== null): ?>
                                                    <span class="session-duration">
                                                        <?php 
                                                        $mins = $session['session_duration_minutes'];
                                                        if ($mins < 60) {
                                                            echo $mins . ' min';
                                                        } else {
                                                            $hours = floor($mins / 60);
                                                            $remainMins = $mins % 60;
                                                            echo $hours . 'h ' . $remainMins . 'm';
                                                        }
                                                        ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="session-duration active">--</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No login sessions found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Info Row -->
        <div class="row mt-3">
            <div class="col-md-4">
                <div class="system-status">
                    <h3><i class="fas fa-server"></i> System Status</h3>
                    <div class="status-item">
                        <span><i class="fas fa-database"></i> Database</span>
                        <span class="status-indicator online"><i class="fas fa-check-circle"></i> Online</span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-calendar"></i> Semester</span>
                        <span class="status-indicator online">
                            <?php echo $currentSemester['semester_name'] ?? 'Not Set'; ?>
                        </span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-clipboard-list"></i> Registrations</span>
                        <span class="status-indicator <?php echo $pendingRegistrations > 10 ? 'warning' : 'online'; ?>">
                            <?php echo $pendingRegistrations; ?> pending
                        </span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-chart-bar"></i> Results</span>
                        <span class="status-indicator <?php echo $pendingResults > 5 ? 'warning' : 'online'; ?>">
                            <?php echo $pendingResults; ?> pending
                        </span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-tachometer-alt"></i> System Load</span>
                        <span class="status-indicator online"><i class="fas fa-check-circle"></i> Normal</span>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="quick-links-card">
                    <h3><i class="fas fa-link"></i> Quick Links</h3>
                    <div class="quick-links-list">
                        <a href="system/health.php" class="quick-link-item">
                            <i class="fas fa-heartbeat"></i> System Health
                        </a>
                        <a href="students/list.php" class="quick-link-item">
                            <i class="fas fa-user-graduate"></i> View Students
                        </a>
                        <a href="lecturers/list.php" class="quick-link-item">
                            <i class="fas fa-chalkboard-teacher"></i> View Lecturers
                        </a>
                        <a href="courses/list.php" class="quick-link-item">
                            <i class="fas fa-book"></i> View Courses
                        </a>
                    </div>
                </div>
            </div>
                
            <div class="col-md-4">
                <!-- Current Semester -->
                <?php if ($currentSemester): ?>
                <div class="semester-card">
                    <h3><i class="fas fa-calendar-alt"></i> Current Semester</h3>
                    <div class="semester-info">
                        <div class="semester-name"><?php echo e($currentSemester['semester_name'] ?? 'Not Set'); ?></div>
                        <div class="semester-dates">
                            <span><i class="fas fa-play"></i> <?php echo Helper::formatDate($currentSemester['start_date'] ?? ''); ?></span>
                            <span><i class="fas fa-stop"></i> <?php echo Helper::formatDate($currentSemester['end_date'] ?? ''); ?></span>
                        </div>
                        <div class="semester-status">
                            <span class="badge badge-<?php echo Helper::getStatusColor($currentSemester['status'] ?? 'active'); ?>">
                                <?php echo ucfirst($currentSemester['status'] ?? 'Active'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Saved / Archived Notifications (dashboard widget) -->
                <div class="saved-notifications-card">
                    <h3><i class="fas fa-bookmark"></i> Saved Notifications</h3>
                    <div class="saved-list">
                        <?php if (!empty($savedNotifications)): ?>
                            <?php foreach ($savedNotifications as $s): ?>
                                <div class="saved-item">
                                    <div class="saved-meta">
                                        <strong><?php echo e($s['title']); ?></strong>
                                        <span class="saved-time"><?php echo Helper::timeAgo($s['archived_at']); ?></span>
                                    </div>
                                    <div class="saved-body">
                                        <p><?php echo e(mb_substr(strip_tags($s['message']), 0, 120)); ?></p>
                                        <?php if (!empty($s['link'])): ?>
                                            <a href="<?php echo e($s['link']); ?>" class="btn btn-sm btn-link">Open</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state text-muted">No saved notifications</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right mt-2"><a href="notifications/archive.php" class="btn btn-sm btn-outline-primary">View all saved</a></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Admin Dashboard Specific Styles */
.quick-links-card,
.semester-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    margin-bottom: 16px;
}

.quick-links-card h3,
.semester-card h3 {
    font-size: 14px;
    font-weight: 600;
    color: #1a1a2e;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.quick-links-card h3 i,
.semester-card h3 i {
    color: #667eea;
}

.quick-links-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.quick-link-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
}

/* Saved notifications widget */
.saved-notifications-card {
    background: white;
    border-radius: 12px;
    padding: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    margin-bottom: 16px;
    min-height: 180px;
}
.saved-notifications-card h3 { font-size:14px; font-weight:600; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.saved-list { display:flex; flex-direction:column; gap:10px; max-height:260px; overflow:auto; }
.saved-item { padding:8px; border-radius:8px; border:1px solid #f2f2f2; background:#fff; display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
.saved-meta strong { display:block; font-size:13px; }
.saved-meta .saved-time { color:#888; font-size:12px; }
.saved-body p { margin:6px 0 0; font-size:13px; color:#444; }
.saved-body .btn-link { padding:0; font-size:12px; }

    background: #f8f9fa;
    border-radius: 8px;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s;
    font-size: 13px;
    font-weight: 500;
}

.quick-link-item:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    transform: translateX(5px);
    text-decoration: none;
}

.quick-link-item i {
    width: 20px;
    text-align: center;
}

.semester-info {
    text-align: center;
}

.semester-name {
    font-size: 16px;
    font-weight: 600;
    color: #1a1a2e;
    margin-bottom: 10px;
}

.semester-dates {
    display: flex;
    justify-content: center;
    gap: 16px;
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 10px;
}

.semester-dates span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.semester-status .badge {
    padding: 6px 16px;
    font-size: 12px;
    border-radius: 20px;
}

.badge-active, .badge-success {
    background: #28a745;
    color: white;
}

/* Activity Items Styling */
.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e4102f, #c60f28);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    flex-shrink: 0;
    font-size: 12px;
}

.activity-details h5 {
    margin: 0 0 3px;
    font-size: 13px;
    font-weight: 600;
    color: #1a1a2e;
}

.activity-details p {
    margin: 0;
    font-size: 11px;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.activity-module {
    background: #e9ecef;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.activity-user {
    color: #e4102f;
    font-weight: 500;
}

.activity-time {
    font-weight: 600;
    color: #1a1a2e;
    font-family: 'Consolas', 'Monaco', monospace;
}

.activity-date {
    background: #f8f9fa;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    color: #6c757d;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 30px 16px;
    color: #6c757d;
}

.empty-state i {
    font-size: 36px;
    margin-bottom: 10px;
    color: #dee2e6;
}

.empty-state h4 {
    margin: 0 0 6px;
    color: #495057;
    font-size: 14px;
}

.empty-state p {
    margin: 0;
    font-size: 12px;
}

/* User Sessions Card */
.user-sessions-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    min-height: 300px;
}

.user-sessions-card h3 {
    margin: 0 0 0 0;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sessions-table {
    width: 100%;
    border-collapse: collapse;
}

.sessions-table th,
.sessions-table td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}

.sessions-table th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
}

.session-user {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #1a1a2e;
    font-weight: 500;
}

.session-time {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 12px;
}

.session-time.login {
    color: #28a745;
}

.session-time.logout {
    color: #dc3545;
}

.session-active {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #28a745;
    font-weight: 600;
    font-size: 12px;
}

.session-active i {
    font-size: 8px;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

.session-duration {
    background: #e9ecef;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    color: #495057;
}

.session-duration.active {
    background: transparent;
    color: #adb5bd;
}

/* System Status */
.system-status {
    background: white;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

.system-status h3 {
    margin: 0 0 14px;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.status-item:last-child {
    border-bottom: none;
}

.status-item span:first-child {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #495057;
    font-size: 12px;
}

.status-indicator {
    font-size: 11px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
}

.status-indicator.online {
    color: #28a745;
}

.status-indicator.warning {
    color: #ffc107;
}

.status-indicator.offline {
    color: #dc3545;
}

/* Recent Activity Card */
.recent-activity {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    min-height: 300px;
}

.recent-activity h3 {
    margin: 0 0 0 0;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Responsive fixes */
@media (max-width: 992px) {
    .col-md-8, .col-md-4 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .action-cards {
        grid-template-columns: 1fr;
    }
}

/* Foldable Card Styles */
.foldable-card {
    min-height: 60px;
}

.foldable-header {
    cursor: pointer;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0 !important;
    padding-bottom: 12px;
    border-bottom: 2px solid #f0f0f0;
    transition: margin 0.3s;
}

.foldable-header span {
    display: flex;
    align-items: center;
    gap: 8px;
}

.foldable-header:hover {
    color: #667eea;
}

.fold-arrow {
    font-size: 12px;
    color: #999;
    transition: transform 0.3s ease;
}

.foldable-header.collapsed .fold-arrow {
    transform: rotate(180deg);
}

.foldable-header.collapsed {
    border-bottom-color: transparent;
    margin-bottom: 0 !important;
    padding-bottom: 0;
}

.foldable-body {
    overflow: hidden;
    max-height: 2000px;
    transition: max-height 0.4s ease, opacity 0.3s ease, padding 0.3s ease;
    opacity: 1;
    padding-top: 10px;
}

/* Add scrolling for activity section */
.recent-activity .foldable-body {
    max-height: 400px;
    overflow-y: auto;
    overflow-x: hidden;
}

.recent-activity .foldable-body::-webkit-scrollbar {
    width: 6px;
}

.recent-activity .foldable-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.recent-activity .foldable-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.recent-activity .foldable-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Add scrolling for sessions section */
.user-sessions-card .foldable-body {
    max-height: 350px;
    overflow-y: auto;
    overflow-x: hidden;
}

.user-sessions-card .foldable-body::-webkit-scrollbar {
    width: 6px;
}

.user-sessions-card .foldable-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.user-sessions-card .foldable-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.user-sessions-card .foldable-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.foldable-body.folded {
    max-height: 0;
    opacity: 0;
    padding-top: 0;
}

/* Assigned Courses Section */
.assigned-courses-section {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.section-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-header h3 i {
    color: #667eea;
}

.assigned-courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

.assignment-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 16px;
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    position: relative;
}

.assignment-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-color: #667eea;
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.course-info h5 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 600;
    color: #1a1a2e;
}

.course-info p {
    margin: 0 0 4px 0;
    font-size: 13px;
    color: #495057;
    font-weight: 500;
}

.course-info small {
    color: #6c757d;
    font-size: 11px;
}

.assignment-status {
    flex-shrink: 0;
}

.assignment-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.lecturer-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #495057;
}

.lecturer-info i {
    color: #28a745;
    width: 14px;
}

.lecturer-info strong {
    color: #1a1a2e;
}

.semester-info,
.assignment-date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #6c757d;
}

.semester-info i,
.assignment-date i {
    width: 12px;
    color: #667eea;
}

/* Responsive adjustments for assigned courses */
@media (max-width: 768px) {
    .assigned-courses-grid {
        grid-template-columns: 1fr;
    }

    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .assignment-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
}
</style>

<script>
// Foldable card toggle
document.querySelectorAll('.foldable-header').forEach(function(header) {
    var targetId = header.getAttribute('data-target');
    var body = document.getElementById(targetId);
    // Restore state from localStorage (default: collapsed)
    var key = 'fold_' + targetId;
    var state = localStorage.getItem(key);
    if (state !== 'expanded') {
        header.classList.add('collapsed');
        body.classList.add('folded');
    }
    header.addEventListener('click', function() {
        header.classList.toggle('collapsed');
        body.classList.toggle('folded');
        localStorage.setItem(key, header.classList.contains('collapsed') ? 'collapsed' : 'expanded');
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
<?php
/**
 * Complete Admin Notification System - All-in-One File
 * 
 * USAGE:
 * 1. Include this file in your admin header: require_once 'admin-notifications-complete.php';
 * 2. Display the bell: echo renderAdminNotificationBell();
 * 3. Create notifications: createAdminNotification() or notifyAllAdmins()
 * 
 * FEATURES:
 * - Automatic database table creation
 * - Bell icon with badge
 * - Mark as read functionality
 * - API endpoints built-in
 * - Helper functions included
 */

// ============================================================================
// CONFIGURATION
// ============================================================================
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/smns'); // Change this to your base URL
}

// ============================================================================
// HANDLE API REQUESTS (Must be at the top before any output)
// ============================================================================
if (isset($_GET['notification_action'])) {
    handleNotificationAPI();
    exit;
}

// ============================================================================
// DATABASE SETUP & NOTIFICATION FETCHING
// ============================================================================
function setupNotificationsTable() {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $conn->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_type VARCHAR(20) NOT NULL DEFAULT 'admin',
                type VARCHAR(50) NOT NULL DEFAULT 'info',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                link VARCHAR(500) NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMP NULL,
                INDEX idx_user (user_id, user_type),
                INDEX idx_read (is_read),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        return true;
    } catch (Exception $e) {
        error_log("Error setting up notifications table: " . $e->getMessage());
        return false;
    }
}

function fetchAdminNotifications($adminId = null) {
    try {
        // Setup table if it doesn't exist
        setupNotificationsTable();
        
        if ($adminId === null) {
            $adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
        }
        
        if ($adminId <= 0) {
            return [];
        }
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            AND user_type = 'admin' 
            AND is_read = 0 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute(['user_id' => $adminId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// NOTIFICATION CREATION FUNCTIONS
// ============================================================================

/**
 * Create notification for specific admin
 */
function createAdminNotification($adminId, $type, $title, $message, $link = null) {
    try {
        setupNotificationsTable();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, user_type, type, title, message, link, created_at) 
            VALUES (:user_id, 'admin', :type, :title, :message, :link, NOW())
        ");
        
        return $stmt->execute([
            'user_id' => $adminId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link
        ]);
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all active admins
 */
function notifyAllAdmins($type, $title, $message, $link = null) {
    try {
        setupNotificationsTable();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Get all active admin IDs
        $stmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($admins)) {
            return false;
        }
        
        $insertStmt = $conn->prepare("
            INSERT INTO notifications (user_id, user_type, type, title, message, link, created_at) 
            VALUES (:user_id, 'admin', :type, :title, :message, :link, NOW())
        ");
        
        foreach ($admins as $adminId) {
            $insertStmt->execute([
                'user_id' => $adminId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error notifying all admins: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// API HANDLER
// ============================================================================
function handleNotificationAPI() {
    header('Content-Type: application/json');
    
    // Check authentication
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $action = $_GET['notification_action'] ?? '';
    $adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        switch ($action) {
            case 'mark_read':
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                $notifId = intval($data['notification_id'] ?? 0);
                
                if ($notifId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE id = :id AND user_id = :user_id AND user_type = 'admin'
                    ");
                    $stmt->execute(['id' => $notifId, 'user_id' => $adminId]);
                    
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                }
                break;
                
            case 'mark_all_read':
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE user_id = :user_id AND user_type = 'admin' AND is_read = 0
                ");
                $stmt->execute(['user_id' => $adminId]);
                
                echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("Notification API error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
}

// ============================================================================
// RENDER NOTIFICATION BELL
// ============================================================================
function renderAdminNotificationBell() {
    $notifications = fetchAdminNotifications();
    $count = count($notifications);
    $currentFile = basename($_SERVER['PHP_SELF']);
    
    ob_start();
    ?>
    
    <!-- Admin Notification Bell -->
    <div class="notification-wrapper">
        <button class="notification-bell" id="adminNotificationBell" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php if ($count > 0): ?>
                <span class="notification-badge"><?php echo $count; ?></span>
            <?php endif; ?>
        </button>
        
        <div class="notification-dropdown" id="adminNotificationDropdown">
            <div class="notification-header">
                <h6>Notifications</h6>
                <?php if ($count > 0): ?>
                    <a href="#" id="adminMarkAllRead">Mark all read</a>
                <?php endif; ?>
            </div>
            <div class="notification-list">
                <?php if ($count > 0): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>" 
                           class="notification-item unread" 
                           data-id="<?php echo $notif['id']; ?>"
                           onclick="markNotificationRead(<?php echo $notif['id']; ?>)">
                            <div class="notif-icon notif-<?php echo htmlspecialchars($notif['type']); ?>">
                                <?php
                                $iconMap = [
                                    'info' => 'info-circle',
                                    'success' => 'check-circle',
                                    'warning' => 'exclamation-triangle',
                                    'error' => 'times-circle',
                                    'student' => 'user-graduate',
                                    'finance' => 'dollar-sign',
                                    'system' => 'cog'
                                ];
                                $icon = $iconMap[$notif['type']] ?? 'bell';
                                ?>
                                <i class="fas fa-<?php echo $icon; ?>"></i>
                            </div>
                            <div class="notif-content">
                                <p class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                <p class="notif-text"><?php echo htmlspecialchars($notif['message']); ?></p>
                                <span class="notif-time"><?php echo Helper::timeAgo($notif['created_at']); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notification-empty">
                        <i class="fas fa-bell-slash"></i>
                        <p>No new notifications</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
    .notification-wrapper {
        position: relative;
        display: inline-block;
        margin-left: 15px;
    }
    .notification-bell {
        background: transparent;
        border: none;
        color: #fff;
        font-size: 20px;
        cursor: pointer;
        position: relative;
        padding: 8px 12px;
        transition: all 0.3s;
    }
    .notification-bell:hover {
        transform: scale(1.1);
        color: #ffc107;
    }
    .notification-badge {
        position: absolute;
        top: 2px;
        right: 2px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        padding: 2px 6px;
        font-size: 10px;
        font-weight: bold;
        min-width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }
    .notification-dropdown {
        position: absolute;
        top: 45px;
        right: 0;
        width: 360px;
        max-height: 500px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        display: none;
        z-index: 1000;
        overflow: hidden;
    }
    .notification-dropdown.show {
        display: block;
        animation: slideDown 0.3s ease;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .notification-header {
        padding: 15px;
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .notification-header h6 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }
    .notification-header a {
        font-size: 13px;
        color: #007bff;
        text-decoration: none;
    }
    .notification-header a:hover {
        text-decoration: underline;
    }
    .notification-list {
        max-height: 400px;
        overflow-y: auto;
    }
    .notification-item {
        display: flex;
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        text-decoration: none;
        color: inherit;
        transition: background 0.2s;
    }
    .notification-item:hover {
        background: #f8f9fa;
    }
    .notification-item.unread {
        background: #e7f3ff;
    }
    .notification-item.unread:hover {
        background: #d1e7ff;
    }
    .notif-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        margin-right: 12px;
        flex-shrink: 0;
    }
    .notif-icon.notif-info { background: #e7f3ff; color: #007bff; }
    .notif-icon.notif-success { background: #d4edda; color: #28a745; }
    .notif-icon.notif-warning { background: #fff3cd; color: #ffc107; }
    .notif-icon.notif-error { background: #f8d7da; color: #dc3545; }
    .notif-icon.notif-student { background: #e7f3ff; color: #17a2b8; }
    .notif-icon.notif-finance { background: #d4edda; color: #28a745; }
    .notif-icon.notif-system { background: #e2e3e5; color: #6c757d; }
    .notif-content {
        flex: 1;
    }
    .notif-title {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 600;
        color: #333;
    }
    .notif-text {
        margin: 0 0 4px 0;
        font-size: 13px;
        color: #666;
        line-height: 1.4;
    }
    .notif-time {
        font-size: 11px;
        color: #999;
    }
    .notification-empty {
        text-align: center;
        padding: 40px 20px;
        color: #999;
    }
    .notification-empty i {
        font-size: 48px;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    .notification-empty p {
        margin: 0;
        font-size: 14px;
    }
    </style>

    <script>
    (function() {
        const bell = document.getElementById('adminNotificationBell');
        const dropdown = document.getElementById('adminNotificationDropdown');
        const markAllBtn = document.getElementById('adminMarkAllRead');
        
        if (!bell || !dropdown) return;
        
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== bell) {
                dropdown.classList.remove('show');
            }
        });
        
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>?notification_action=mark_all_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                        const badge = bell.querySelector('.notification-badge');
                        if (badge) badge.remove();
                        markAllBtn.style.display = 'none';
                    }
                });
            });
        }
    })();

    function markNotificationRead(notifId) {
        fetch('<?php echo $_SERVER['PHP_SELF']; ?>?notification_action=mark_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ notification_id: notifId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    const count = parseInt(badge.textContent) - 1;
                    if (count <= 0) badge.remove();
                    else badge.textContent = count;
                }
            }
        });
    }

    // Auto-refresh recent activities
    let activityRefreshInterval;

    function refreshRecentActivities() {
        const activityBody = document.getElementById('activityBody');
        if (!activityBody) return;

        // Show loading indicator
        const originalContent = activityBody.innerHTML;
        activityBody.innerHTML = '<div class="text-center" style="padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Refreshing...</div>';

        fetch('<?php echo BASE_URL; ?>/api/activities.php?limit=10')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.activities && data.activities.length > 0) {
                    let html = '';
                    data.activities.forEach(activity => {
                        let icon = '';
                        switch(activity.action) {
                            case 'login': icon = '<i class="fas fa-sign-in-alt"></i>'; break;
                            case 'logout': icon = '<i class="fas fa-sign-out-alt"></i>'; break;
                            case 'create': icon = '<i class="fas fa-plus"></i>'; break;
                            case 'update': icon = '<i class="fas fa-edit"></i>'; break;
                            case 'delete': icon = '<i class="fas fa-trash"></i>'; break;
                            case 'approve': icon = '<i class="fas fa-check"></i>'; break;
                            case 'submit': icon = '<i class="fas fa-paper-plane"></i>'; break;
                            default: icon = '<i class="fas fa-circle"></i>';
                        }

                        const description = activity.description || 'Unknown activity';
                        const module = activity.module || 'system';
                        const time = activity.formatted_time || '';
                        const date = activity.formatted_date || '';
                        const username = activity.username || '';

                        html += `
                            <div class="activity-item">
                                <div class="activity-icon">
                                    ${icon}
                                </div>
                                <div class="activity-details">
                                    <h5>${description.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</h5>
                                    <p>
                                        <i class="fas fa-clock"></i>
                                        <span class="activity-time" title="${date} ${time}">
                                            ${time}
                                        </span>
                                        <span class="activity-date">${date}</span>
                                        <span class="activity-module">${module}</span>
                                        ${username ? `<span class="activity-user"><i class="fas fa-user"></i> ${username.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span>` : ''}
                                    </p>
                                </div>
                            </div>
                        `;
                    });
                    activityBody.innerHTML = html;
                } else {
                    // Restore original content if no activities or error
                    activityBody.innerHTML = originalContent;
                }
            })
            .catch(error => {
                console.log('Error refreshing activities:', error);
                // Restore original content on error
                activityBody.innerHTML = originalContent;
            });
    }

    // Auto-refresh every 30 seconds
    activityRefreshInterval = setInterval(refreshRecentActivities, 30000);

    // Initial refresh after 5 seconds
    setTimeout(refreshRecentActivities, 5000);

    // Clear interval when page unloads
    window.addEventListener('beforeunload', function() {
        if (activityRefreshInterval) {
            clearInterval(activityRefreshInterval);
        }
    });
    </script>
    
    <?php
    return ob_get_clean();
}

// ============================================================================
// USAGE EXAMPLES (Remove these in production)
// ============================================================================

/*
// EXAMPLE 1: Display the bell in your admin header
echo renderAdminNotificationBell();

// EXAMPLE 2: Create notification for specific admin
createAdminNotification(
    1, // admin ID
    'success', // type: info, success, warning, error, student, finance, system
    'New Student Registered',
    'John Doe has completed registration',
    '/views/admin/students/view.php?id=123'
);

// EXAMPLE 3: Notify all admins
notifyAllAdmins(
    'warning',
    'Low Balance Alert',
    'Student Jane Smith has a balance of UGX 50,000',
    '/views/admin/finance/students.php'
);

// EXAMPLE 4: In your student registration file
$conn->commit();
notifyAllAdmins(
    'student',
    'New Student Added',
    "$first_name $last_name (ID: $studentCode) has been added",
    BASE_URL . "/views/admin/students/view.php?id=$newStudentId"
);

// EXAMPLE 5: On payment received
$conn->commit();
notifyAllAdmins(
    'finance',
    'Payment Received',
    "$studentName paid UGX " . number_format($amount),
    BASE_URL . "/views/admin/finance/transaction.php?id=$transactionId"
);
*/

?>