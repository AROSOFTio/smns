<?php
/**
 * Lecturer Dashboard
 */
require_once '../../config.php';

// Initialize with lecturer role for session isolation
$session = new Session('lecturer');
$auth = new Auth('lecturer');

// Verify lecturer access
Security::requireRole('lecturer');
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

$pageTitle = 'Lecturer Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

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
                            <?php echo strtoupper(substr($lecturerProfile['first_name'], 0, 1) . substr($lecturerProfile['last_name'], 0, 1)); ?>
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
        
        <!-- Welcome Message -->
        <div class="card">
            <div class="card-body">
                <h3>Welcome, <?php echo e($lecturerProfile['first_name']); ?>!</h3>
                <p><strong>Lecturer ID:</strong> <?php echo e($lecturerProfile['lecturer_id']); ?></p>
                <p><strong>Department:</strong> <?php echo e($lecturerProfile['department'] ?? 'N/A'); ?></p>
                <p><strong>Specialization:</strong> <?php echo e($lecturerProfile['specialization'] ?? 'N/A'); ?></p>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-card primary">
                    <p>Assigned Courses</p>
                    <h3><?php echo $totalCourses; ?></h3>
                    <small>This semester</small>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stats-card success">
                    <p>Total Students</p>
                    <h3><?php echo $totalStudents; ?></h3>
                    <small>Across all courses</small>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stats-card <?php echo $pendingResults > 0 ? 'warning' : 'info'; ?>">
                    <p>Pending Results</p>
                    <h3><?php echo $pendingResults; ?></h3>
                    <small>Not yet submitted</small>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Current Semester -->
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
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/my-courses.php" class="btn btn-primary mb-2" style="width:100%">📚 View My Courses</a>
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php" class="btn btn-success mb-2" style="width:100%">✏️ Enter Results</a>
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/view-results.php" class="btn btn-info mb-2" style="width:100%">👁️ View Submitted Results</a>
                        <a href="<?php echo BASE_URL; ?>/views/lecturer/reports.php" class="btn btn-warning mb-2" style="width:100%">📑 Generate Reports</a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- My Courses -->
        <div class="card">
            <div class="card-header">
                My Courses - <?php echo $currentSemester['semester_name'] ?? 'Current Semester'; ?>
            </div>
            <div class="card-body">
                <?php if (count($myCourses) > 0): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Credit Hours</th>
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
                                        <a href="<?php echo BASE_URL; ?>/views/lecturer/course-details.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info">View</a>
                                        <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-success">Enter Results</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center">No courses assigned for this semester</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
