<?php
/**
 * Lecturer Dashboard
 */
require_once '../../config.php';

// Initialize session and auth with lecturer module context
$session = new Session('lecturer');
$auth = new Auth('lecturer');

// Verify lecturer access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'lecturer') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=lecturer');
    exit;
}

$currentUser = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Current semester
$currentSemester = Helper::getCurrentSemester();
$currentAcademicYearLabel = 'N/A';
if (!empty($currentSemester['academic_year_id'])) {
    $ayStmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => (int)$currentSemester['academic_year_id']]);
    $currentAcademicYearLabel = $ayStmt->fetchColumn() ?: 'N/A';
}

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

<style>
.lecturer-dashboard {
    background: #f8fafc;
    color: #0f172a;
    min-height: 100vh;
}
.lecturer-dashboard .topbar {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
}
.lecturer-dashboard .topbar h4 {
    color: #0f172a;
    font-weight: 700;
}
.lecturer-dashboard .topbar .time-display {
    font-weight: 700;
    color: #0f172a;
}
.lecturer-dashboard .topbar .date-display {
    color: #64748b;
    font-size: 0.78rem;
}
.lecturer-dashboard .content-area {
    padding: 22px 26px;
}
.dashboard-hero {
    padding: 16px 18px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
}
.dashboard-hero h5 {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 6px;
    color: #0f172a;
}
.dashboard-hero .meta-line {
    color: #475569;
    font-size: 0.9rem;
}
.hero-pill {
    background: #f1f5f9;
    color: #0f172a;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 6px 12px;
    font-weight: 700;
    font-size: 0.75rem;
    letter-spacing: 0.03em;
}
.hero-pill span {
    color: #2563eb;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(180px, 1fr));
    gap: 16px;
    margin-top: 16px;
}
.stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 16px;
    display: flex;
    gap: 14px;
    align-items: center;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}
.stat-icon {
    width: 48px;
    height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(59, 130, 246, 0.12);
    color: #2563eb;
    font-size: 1.4rem;
}
.stat-card.students .stat-icon { background: rgba(16, 185, 129, 0.12); color: #059669; }
.stat-card.results .stat-icon { background: rgba(245, 158, 11, 0.14); color: #d97706; }
.stat-card.semester .stat-icon { background: rgba(139, 92, 246, 0.14); color: #7c3aed; }
.stat-details h3 {
    margin: 0;
    font-size: 1.4rem;
    color: #0f172a;
    font-weight: 700;
}
.stat-details p {
    margin: 2px 0 6px;
    color: #64748b;
    font-size: 0.85rem;
}
.stat-change {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
}
.stat-change.positive { background: rgba(16, 185, 129, 0.15); color: #047857; }
.stat-change.neutral { background: rgba(148, 163, 184, 0.18); color: #475569; }
.stat-change.negative { background: rgba(239, 68, 68, 0.15); color: #b91c1c; }
.quick-actions .btn {
    border-radius: 12px;
    font-weight: 700;
    padding: 10px 12px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
    border: none;
}
.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-action.action-courses { background: #4f46e5; color: #eef2ff; }
.btn-action.action-enter { background: #16a34a; color: #ecfdf5; }
.btn-action.action-view { background: #0ea5e9; color: #e0f2fe; }
.btn-action.action-reports { background: #f59e0b; color: #fff7ed; }
.courses-card {
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}
.courses-card .card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
    font-weight: 700;
}
.courses-card .table {
    color: #0f172a;
}
.courses-card .table thead th {
    background: #eef2f7;
    border-color: #e2e8f0;
    color: #334155;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.03em;
}
.courses-card .table tbody td {
    border-color: #e2e8f0;
}
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(180px, 1fr)); }
}
@media (max-width: 768px) {
    .stats-grid { grid-template-columns: 1fr; }
    .dashboard-hero { padding: 14px; }
}
html[data-theme='dark'] .lecturer-dashboard {
    background: radial-gradient(1200px 600px at 10% 0%, rgba(59, 130, 246, 0.18), transparent 60%),
                radial-gradient(900px 500px at 90% 10%, rgba(16, 185, 129, 0.18), transparent 55%),
                #0b1220;
    color: #e5e7eb;
}
html[data-theme='dark'] .lecturer-dashboard .topbar {
    background: rgba(12, 18, 33, 0.9);
    border-bottom: 1px solid #1f2937;
    color: #e5e7eb;
    box-shadow: 0 6px 18px rgba(2, 6, 23, 0.4);
}
html[data-theme='dark'] .lecturer-dashboard .topbar h4,
html[data-theme='dark'] .lecturer-dashboard .topbar .time-display {
    color: #f8fafc;
}
html[data-theme='dark'] .lecturer-dashboard .topbar .date-display {
    color: #94a3b8;
}
html[data-theme='dark'] .dashboard-hero {
    border-color: #1f2937;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.6));
    box-shadow: 0 12px 30px rgba(2, 6, 23, 0.35);
}
html[data-theme='dark'] .dashboard-hero h5 { color: #f8fafc; }
html[data-theme='dark'] .dashboard-hero .meta-line { color: #cbd5e1; }
html[data-theme='dark'] .hero-pill {
    background: rgba(255, 255, 255, 0.06);
    color: #e2e8f0;
    border-color: rgba(148, 163, 184, 0.35);
}
html[data-theme='dark'] .hero-pill span { color: #60a5fa; }
html[data-theme='dark'] .stat-card {
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.95), rgba(2, 6, 23, 0.85));
    border-color: #1f2937;
    box-shadow: 0 10px 24px rgba(2, 6, 23, 0.35);
}
html[data-theme='dark'] .stat-details h3 { color: #f8fafc; }
html[data-theme='dark'] .stat-details p { color: #94a3b8; }
html[data-theme='dark'] .stat-icon { background: rgba(59, 130, 246, 0.16); color: #93c5fd; }
html[data-theme='dark'] .stat-card.students .stat-icon { background: rgba(16, 185, 129, 0.16); color: #6ee7b7; }
html[data-theme='dark'] .stat-card.results .stat-icon { background: rgba(245, 158, 11, 0.16); color: #fcd34d; }
html[data-theme='dark'] .stat-card.semester .stat-icon { background: rgba(139, 92, 246, 0.18); color: #c4b5fd; }
html[data-theme='dark'] .stat-change.positive { background: rgba(16, 185, 129, 0.18); color: #6ee7b7; }
html[data-theme='dark'] .stat-change.neutral { background: rgba(148, 163, 184, 0.18); color: #cbd5e1; }
html[data-theme='dark'] .stat-change.negative { background: rgba(239, 68, 68, 0.18); color: #fca5a5; }
html[data-theme='dark'] .courses-card {
    border-color: #1f2937;
    background: rgba(15, 23, 42, 0.8);
    color: #e5e7eb;
    box-shadow: 0 12px 24px rgba(2, 6, 23, 0.35);
}
html[data-theme='dark'] .courses-card .card-header {
    background: rgba(15, 23, 42, 0.9);
    border-bottom-color: #1f2937;
    color: #f8fafc;
}
html[data-theme='dark'] .courses-card .table { color: #e5e7eb; }
html[data-theme='dark'] .courses-card .table thead th {
    background: #111827;
    border-color: #1f2937;
    color: #e2e8f0;
}
html[data-theme='dark'] .courses-card .table tbody td {
    border-color: #1f2937;
}
</style>

<div class="main-content lecturer-dashboard" id="mainContent">
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
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="my-courses.php" class="dropdown-item">
                            <i class="fas fa-book"></i> My Courses
                        </a>
                        <a href="reports.php" class="dropdown-item">
                            <i class="fas fa-file-alt"></i> Reports
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
        <div class="welcome-section mb-3 dashboard-hero">
            <h5>Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening'); ?>, <?php echo e($lecturerProfile['first_name']); ?>!</h5>
            <p class="meta-line mb-0">
                <strong>ID:</strong> <?php echo e($lecturerProfile['lecturer_id']); ?> &nbsp;|&nbsp;
                <strong>Dept:</strong> <?php echo e($lecturerProfile['department'] ?? 'N/A'); ?> &nbsp;|&nbsp;
                <?php echo e($lecturerProfile['specialization'] ?? ''); ?>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <span class="hero-pill">CURRENT YR. <span><?php echo e($currentAcademicYearLabel); ?></span></span>
                <span class="hero-pill">CURRENT SEM. <span><?php echo e($currentSemester['semester_name'] ?? 'N/A'); ?></span></span>
            </div>
        </div>
        
        <!-- Lecturer Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card courses">
                <div class="stat-icon"><i class="fas fa-book"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalCourses); ?></h3>
                    <p>Assigned Courses</p>
                    <div class="stat-change neutral"><?php echo $currentSemester['semester_name'] ?? 'Current Semester'; ?></div>
                </div>
            </div>
            
            <div class="stat-card students">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalStudents); ?></h3>
                    <p>Total Students</p>
                    <div class="stat-change positive">Enrolled</div>
                </div>
            </div>
            
            <div class="stat-card results">
                <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($pendingResults); ?></h3>
                    <p>Pending Results</p>
                    <div class="stat-change <?php echo $pendingResults > 0 ? 'negative' : 'positive'; ?>">
                        <?php echo $pendingResults > 0 ? 'Needs Attention' : 'All Updated'; ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card semester">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-details">
                    <h3><?php echo $currentSemester['semester_name'] ?? 'N/A'; ?></h3>
                    <p>Current Semester</p>
                    <div class="stat-change positive">Active</div>
                    <div class="stat-change neutral">Year: <?php echo e($currentAcademicYearLabel); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-3 quick-actions">
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/my-courses.php" class="btn btn-action action-courses btn-sm btn-block"><i class="fas fa-book-open"></i> My Courses</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php" class="btn btn-action action-enter btn-sm btn-block"><i class="fas fa-pen-nib"></i> Enter Results</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/view-results.php" class="btn btn-action action-view btn-sm btn-block"><i class="fas fa-chart-bar"></i> View Results</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/reports.php" class="btn btn-action action-reports btn-sm btn-block"><i class="fas fa-file-alt"></i> Reports</a>
            </div>
        </div>
        
        <!-- My Courses -->
        <div class="card courses-card">
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


