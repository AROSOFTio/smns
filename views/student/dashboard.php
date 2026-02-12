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
                            <?php echo strtoupper(substr($studentProfile['first_name'], 0, 1) . substr($studentProfile['last_name'], 0, 1)); ?>
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
        <div class="welcome-section mb-4">
            <h2>Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening'); ?>, <?php echo e($studentProfile['first_name']); ?>!</h2>
            <p class="text-muted">Welcome to your student dashboard. Here's your academic overview.</p>
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
        
        <!-- Quick Actions Section -->
        <div class="quick-actions-section">
            <h3>Quick Actions</h3>
            <div class="action-grid">
                <a href="courses/registration.php" class="action-card">
                    <div class="action-icon">📝</div>
                    <h4>Course Registration</h4>
                    <p>Register for new courses this semester</p>
                </a>
                
                <a href="results/view.php" class="action-card">
                    <div class="action-icon">📊</div>
                    <h4>View Results</h4>
                    <p>Check your academic performance and grades</p>
                </a>
                
                <a href="fees/statement.php" class="action-card">
                    <div class="action-icon">💰</div>
                    <h4>Fee Statement</h4>
                    <p>View and pay outstanding fees</p>
                </a>
                
                <a href="timetable/view.php" class="action-card">
                    <div class="action-icon">🗓️</div>
                    <h4>Class Timetable</h4>
                    <p>View your class schedule and timings</p>
                </a>
            </div>
        </div>
        
        <!-- Welcome Message -->
        <div class="card">
            <div class="card-body">
                <h3>Welcome, <?php echo e($studentProfile['first_name']); ?>!</h3>
                <p><strong>Student ID:</strong> <?php echo e($studentProfile['student_id']); ?></p>
                <p><strong>Program:</strong> <?php echo e($studentProfile['program_name'] ?? 'N/A'); ?></p>
                <p><strong>Level:</strong> Year <?php echo e($studentProfile['level_year']); ?></p>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-card primary">
                    <p>Registered Courses</p>
                    <h3><?php echo $registeredCourses; ?></h3>
                    <small>This semester</small>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stats-card <?php echo Helper::getGPAColor($cumulativeGPA); ?>">
                    <p>Cumulative GPA</p>
                    <h3><?php echo number_format($cumulativeGPA, 2); ?></h3>
                    <small>Current standing</small>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stats-card <?php echo $outstandingBalance > 0 ? 'danger' : 'success'; ?>">
                    <p>Fee Balance</p>
                    <h3><?php echo Helper::formatCurrency($outstandingBalance); ?></h3>
                    <small><?php echo $outstandingBalance > 0 ? 'Outstanding' : 'Fully Paid'; ?></small>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Current Semester Info -->
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
            
            <!-- Quick Actions -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Quick Actions
                    </div>
                    <div class="card-body">
                        <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="btn btn-primary mb-2" style="width:100%">📝 Register for Courses</a>
                        <a href="<?php echo BASE_URL; ?>/views/student/results.php" class="btn btn-success mb-2" style="width:100%">📊 View Results</a>
                        <a href="<?php echo BASE_URL; ?>/views/student/transcript.php" class="btn btn-info mb-2" style="width:100%">📄 Download Transcript</a>
                        <a href="<?php echo BASE_URL; ?>/views/student/fees.php" class="btn btn-warning mb-2" style="width:100%">💰 View Fees</a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Results -->
        <?php if (count($recentResults) > 0): ?>
        <div class="card">
            <div class="card-header">
                Recent Results
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Semester</th>
                                <th>Total Marks</th>
                                <th>Grade</th>
                                <th>Credit Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentResults as $result): ?>
                                <tr>
                                    <td><?php echo e($result['course_code']); ?></td>
                                    <td><?php echo e($result['course_name']); ?></td>
                                    <td><?php echo e($result['semester_name']); ?></td>
                                    <td><?php echo number_format($result['total_marks'], 2); ?>%</td>
                                    <td><span class="badge badge-<?php echo Helper::getGPAColor($result['grade_points'] ?? 0); ?>"><?php echo e($result['grade'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo $result['credit_hours']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a href="<?php echo BASE_URL; ?>/views/student/results.php" class="btn btn-sm btn-primary">View All Results</a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Notifications -->
        <?php if (count($unreadNotifications) > 0): ?>
        <div class="card">
            <div class="card-header">
                Unread Notifications <span class="badge badge-danger"><?php echo count($unreadNotifications); ?></span>
            </div>
            <div class="card-body">
                <?php foreach($unreadNotifications as $notification): ?>
                    <div class="alert alert-<?php echo $notification['type']; ?>">
                        <strong><?php echo e($notification['title']); ?></strong><br>
                        <?php echo e($notification['message']); ?><br>
                        <small><?php echo Helper::timeAgo($notification['created_at']); ?></small>
                    </div>
                <?php endforeach; ?>
                <a href="<?php echo BASE_URL; ?>/views/student/notifications.php" class="btn btn-sm btn-primary">View All Notifications</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
