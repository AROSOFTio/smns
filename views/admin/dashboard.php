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

.foldable-body.folded {
    max-height: 0;
    opacity: 0;
    padding-top: 0;
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
