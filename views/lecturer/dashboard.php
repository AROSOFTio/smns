<?php
/**
 * Lecturer Dashboard
 */
require_once '../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize session and auth with lecturer module context
$session = new Session('lecturer');
$auth = new Auth('lecturer');

// Verify lecturer access (using module-specific session keys)
if (!isset($_SESSION['lecturer_logged_in']) || $_SESSION['lecturer_logged_in'] !== true || $_SESSION['lecturer_role'] !== 'lecturer') {
    header('Location: ' . BASE_URL . '/views/lecturer/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Current semester
$currentSemester = Helper::getCurrentSemester();

// Total courses assigned
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM course_assignments 
                        WHERE lecturer_id = :lecturer_id 
                        AND semester_id = :semester_id 
                        AND status = 'active'");
$stmt->execute([
    'lecturer_id' => $lecturerProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$totalCourses = $stmt->fetch()['count'];

// Total students
$stmt = $conn->prepare("SELECT COUNT(DISTINCT cr.student_id) as count 
                        FROM course_registrations cr
                        INNER JOIN course_assignments ca ON cr.course_id = ca.course_id
                        WHERE ca.lecturer_id = :lecturer_id 
                        AND ca.semester_id = :semester_id
                        AND cr.status = 'approved'");
$stmt->execute([
    'lecturer_id' => $lecturerProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$totalStudents = $stmt->fetch()['count'];

// Pending results
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM results r
                        WHERE r.entered_by = :lecturer_id 
                        AND r.status = 'draft'");
$stmt->execute(['lecturer_id' => $lecturerProfile['id']]);
$pendingResults = $stmt->fetch()['count'];

// My courses
$stmt = $conn->prepare("SELECT c.*, ca.id as assignment_id, s.semester_name
                        FROM course_assignments ca
                        INNER JOIN courses c ON ca.course_id = c.id
                        INNER JOIN semesters s ON ca.semester_id = s.id
                        WHERE ca.lecturer_id = :lecturer_id 
                        AND ca.semester_id = :semester_id
                        AND ca.status = 'active'");
$stmt->execute([
    'lecturer_id' => $lecturerProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$myCourses = $stmt->fetchAll();

// Notifications
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :user_id AND read_status = 'unread' ORDER BY created_at DESC LIMIT 5");
$stmt->execute(['user_id' => $currentUser['id']]);
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'Lecturer Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

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
                            <?php if (!empty($lecturerProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $lecturerProfile['photo']; ?>" alt="Profile Photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($lecturerProfile['first_name'], 0, 1) . substr($lecturerProfile['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong><?php echo e($lecturerProfile['first_name']); ?> <?php echo e($lecturerProfile['last_name']); ?></strong>
                            <br><small><?php echo e($lecturerProfile['lecturer_id']); ?></small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item">
                            <i>👤</i> My Profile
                        </a>
                        <a href="my-courses.php" class="dropdown-item">
                            <i>📚</i> My Courses
                        </a>
                        <a href="reports.php" class="dropdown-item">
                            <i>📁</i> Reports
                        </a>
                        <a href="change-password.php" class="dropdown-item">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/logout.php" class="dropdown-item logout-item">
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
        
        <!-- Welcome Section -->
        <div class="welcome-section mb-3">
            <h5>Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening'); ?>, <?php echo e($lecturerProfile['first_name']); ?>!</h5>
            <p class="text-muted mb-0" style="font-size:13px;">
                <strong>ID:</strong> <?php echo e($lecturerProfile['lecturer_id']); ?> &nbsp;|&nbsp;
                <strong>Dept:</strong> <?php echo e($lecturerProfile['department'] ?? 'N/A'); ?> &nbsp;|&nbsp;
                <?php echo e($lecturerProfile['specialization'] ?? ''); ?>
            </p>
        </div>
        
        <!-- Lecturer Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card courses">
                <div class="stat-icon">📚</div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalCourses); ?></h3>
                    <p>Assigned Courses</p>
                    <div class="stat-change neutral">—— Current Semester</div>
                </div>
            </div>
            
            <div class="stat-card students">
                <div class="stat-icon">👥</div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalStudents); ?></h3>
                    <p>Total Students</p>
                    <div class="stat-change positive">↗ Enrolled</div>
                </div>
            </div>
            
            <div class="stat-card results">
                <div class="stat-icon">📝</div>
                <div class="stat-details">
                    <h3><?php echo number_format($pendingResults); ?></h3>
                    <p>Pending Results</p>
                    <div class="stat-change <?php echo $pendingResults > 0 ? 'negative' : 'positive'; ?>">
                        <?php echo $pendingResults > 0 ? '⚠️ Needs Attention' : '✅ All Updated'; ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card semester">
                <div class="stat-icon">📅</div>
                <div class="stat-details">
                    <h3><?php echo $currentSemester['semester_name'] ?? 'N/A'; ?></h3>
                    <p>Current Semester</p>
                    <div class="stat-change positive">🎯 Active</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-3">
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/my-courses.php" class="btn btn-primary btn-sm btn-block">📚 My Courses</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php" class="btn btn-success btn-sm btn-block">✏️ Enter Results</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/view-results.php" class="btn btn-info btn-sm btn-block">👁️ View Results</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/reports.php" class="btn btn-warning btn-sm btn-block">📑 Reports</a>
            </div>
        </div>
        
        <!-- My Courses -->
        <div class="card">
            <div class="card-header">
                My Courses - <?php echo $currentSemester['semester_name'] ?? 'Current Semester'; ?>
            </div>
            <div class="card-body">
                <?php if (count($myCourses) > 0): ?>
                    <table class="table table-hover table-sm" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Credits</th>
                                <th>Level</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($myCourses as $course): ?>
                                <tr>
                                    <td><?php echo e($course['course_code']); ?></td>
                                    <td><?php echo e($course['course_name']); ?></td>
                                    <td><?php echo $course['credit_hours']; ?></td>
                                    <td>Year <?php echo $course['level_year']; ?></td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>/views/lecturer/course-details.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info py-0 px-2">View</a>
                                        <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-success py-0 px-2">Results</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center text-muted mb-0">No courses assigned for this semester</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
