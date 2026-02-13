<?php
/**
 * Student Dashboard
 */
require_once '../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize session and auth with student module context
$session = new Session('student');
$auth = new Auth('student');

// Verify student access (using module-specific session keys)
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Student's courses this semester
$currentSemester = Helper::getCurrentSemester();
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM course_registrations 
                        WHERE student_id = :student_id 
                        AND semester_id = :semester_id 
                        AND status = 'approved'");
$stmt->execute([
    'student_id' => $studentProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$registeredCourses = $stmt->fetch()['count'];

// GPA
$stmt = $conn->prepare("SELECT cumulative_gpa FROM student_gpas 
                        WHERE student_id = :student_id 
                        ORDER BY id DESC LIMIT 1");
$stmt->execute(['student_id' => $studentProfile['id']]);
$gpaData = $stmt->fetch();
$cumulativeGPA = $gpaData['cumulative_gpa'] ?? 0.00;

// Outstanding balance
$stmt = $conn->prepare("SELECT balance FROM student_balances 
                        WHERE student_id = :student_id 
                        AND semester_id = :semester_id");
$stmt->execute([
    'student_id' => $studentProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$balanceData = $stmt->fetch();
$outstandingBalance = $balanceData['balance'] ?? 0.00;

// Recent results
$stmt = $conn->prepare("SELECT r.*, c.course_code, c.course_name, c.credit_hours, s.semester_name
                        FROM results r
                        INNER JOIN courses c ON r.course_id = c.id
                        INNER JOIN semesters s ON r.semester_id = s.id
                        WHERE r.student_id = :student_id AND r.status = 'published'
                        ORDER BY r.created_at DESC LIMIT 5");
$stmt->execute(['student_id' => $studentProfile['id']]);
$recentResults = $stmt->fetchAll();

// Notifications
$stmt = $conn->prepare("SELECT * FROM notifications 
                        WHERE user_id = :user_id AND read_status = 'unread'
                        ORDER BY created_at DESC LIMIT 5");
$stmt->execute(['user_id' => $currentUser['id']]);
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'Student Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

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
                            <?php if (!empty($studentProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile Photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($studentProfile['first_name'], 0, 1) . substr($studentProfile['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong><?php echo e($studentProfile['first_name']); ?> <?php echo e($studentProfile['last_name']); ?></strong>
                            <br><small><?php echo e($studentProfile['student_id']); ?></small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item">
                            <i>👤</i> My Profile
                        </a>
                        <!-- change-password moved to header quick dropdown -->
                        <a href="transcript.php" class="dropdown-item">
                            <i>📄</i> My Transcript
                        </a>
                        <a href="fees.php" class="dropdown-item">
                            <i>💰</i> Fee Statement
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/student/logout.php" class="dropdown-item logout-item">
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
            <h5>Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening'); ?>, <?php echo e($studentProfile['first_name']); ?>!</h5>
            <p class="text-muted mb-0" style="font-size:13px;">
                <strong>ID:</strong> <?php echo e($studentProfile['student_id']); ?> &nbsp;|&nbsp;
                <strong>Program:</strong> <?php echo e($studentProfile['program_name'] ?? 'N/A'); ?> &nbsp;|&nbsp;
                Year <?php echo e($studentProfile['level_year']); ?>
            </p>
        </div>
        
        <!-- Student Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card academic">
                <div class="stat-icon">📚</div>
                <div class="stat-details">
                    <h3><?php echo number_format($registeredCourses); ?></h3>
                    <p>Courses This Semester</p>
                    <div class="stat-change neutral">—— <?php echo $currentSemester['semester_name'] ?? 'N/A'; ?></div>
                </div>
            </div>
            
            <div class="stat-card gpa">
                <div class="stat-icon">🎯</div>
                <div class="stat-details">
                    <h3><?php echo number_format($cumulativeGPA, 2); ?></h3>
                    <p>Cumulative GPA</p>
                    <div class="stat-change <?php echo $cumulativeGPA >= 3.5 ? 'positive' : ($cumulativeGPA >= 2.5 ? 'neutral' : 'negative'); ?>">
                        <?php echo $cumulativeGPA >= 3.5 ? '⭐ Excellent' : ($cumulativeGPA >= 2.5 ? '👍 Good' : '⚠️ Needs Improvement'); ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card financial">
                <div class="stat-icon">💳</div>
                <div class="stat-details">
                    <h3>UGX <?php echo number_format($outstandingBalance, 0); ?></h3>
                    <p>Outstanding Balance</p>
                    <div class="stat-change <?php echo $outstandingBalance == 0 ? 'positive' : ($outstandingBalance < 500000 ? 'neutral' : 'negative'); ?>">
                        <?php echo $outstandingBalance == 0 ? '✅ Paid' : ($outstandingBalance < 500000 ? '⏰ Pending' : '🚨 Overdue'); ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card notifications">
                <div class="stat-icon">🔔</div>
                <div class="stat-details">
                    <h3><?php echo count($unreadNotifications); ?></h3>
                    <p>New Notifications</p>
                    <div class="stat-change <?php echo count($unreadNotifications) > 0 ? 'negative' : 'positive'; ?>">
                        <?php echo count($unreadNotifications) > 0 ? '📬 Unread' : '✅ Up to date'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-3">
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="btn btn-primary btn-sm btn-block">📝 Register Courses</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/student/results.php" class="btn btn-success btn-sm btn-block">📊 View Results</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/student/transcript.php" class="btn btn-info btn-sm btn-block">📄 Transcript</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/student/fees.php" class="btn btn-warning btn-sm btn-block">💰 View Fees</a>
            </div>
        </div>

        <!-- Current Semester -->
        <div class="row">
            <div class="col-md-6">
                <?php if ($currentSemester): ?>
                <div class="card">
                    <div class="card-header">
                        Current Semester
                    </div>
                    <div class="card-body">
                        <h5><?php echo e($currentSemester['semester_name']); ?></h5>
                        <p><strong>Status:</strong> <span class="badge badge-<?php echo Helper::getStatusColor($currentSemester['status']); ?>"><?php echo e($currentSemester['status']); ?></span></p>
                        <p><strong>Start:</strong> <?php echo Helper::formatDate($currentSemester['start_date']); ?></p>
                        <p><strong>End:</strong> <?php echo Helper::formatDate($currentSemester['end_date']); ?></p>
                        <?php if ($currentSemester['registration_start_date']): ?>
                        <p><strong>Registration:</strong> <?php echo Helper::formatDate($currentSemester['registration_start_date']); ?> - <?php echo Helper::formatDate($currentSemester['registration_end_date']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Results -->
            <div class="col-md-6">
                <?php if (count($recentResults) > 0): ?>
                <div class="card">
                    <div class="card-header">Recent Results</div>
                    <div class="card-body p-0">
                        <table class="table table-hover table-sm mb-0" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Marks</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentResults as $result): ?>
                                <tr>
                                    <td><?php echo e($result['course_code']); ?><br><small class="text-muted"><?php echo e($result['course_name']); ?></small></td>
                                    <td><?php echo number_format($result['total_marks'], 1); ?>%</td>
                                    <td><span class="badge badge-<?php echo Helper::getGPAColor($result['grade_points'] ?? 0); ?>"><?php echo e($result['grade'] ?? 'N/A'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center text-muted py-3">
                        <small>No results published yet</small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
