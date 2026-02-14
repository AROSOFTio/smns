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
$level = $_GET['level'] ?? '';

// Build query
$db = new Database();
$conn = $db->getConnection();

// Get courses organized by academic year and semester
$sql = "SELECT c.*
        FROM courses c
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (c.course_code LIKE :search_code OR c.course_name LIKE :search_name)";
    $params['search_code'] = "%$search%";
    $params['search_name'] = "%$search%";
}

if (!empty($level)) {
    $sql .= " AND c.level_year = :level";
    $params['level'] = $level;
}

$sql .= " ORDER BY c.level_year ASC, c.semester_offered ASC, c.course_code ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$allCourses = $stmt->fetchAll();

// Organize courses by academic year and semester
$organizedCourses = [];
foreach ($allCourses as $course) {
    $year = $course['level_year'];
    $semester = $course['semester_offered'];

    if (!isset($organizedCourses[$year])) {
        $organizedCourses[$year] = [];
    }
    if (!isset($organizedCourses[$year][$semester])) {
        $organizedCourses[$year][$semester] = [];
    }

    $organizedCourses[$year][$semester][] = $course;
}

// Get academic years for filter
$stmt = $conn->query("SELECT DISTINCT level_year FROM courses WHERE level_year IS NOT NULL AND level_year != '' ORDER BY level_year DESC");
$academicYears = $stmt->fetchAll();

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Courses List - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
/* Academic Year and Semester Organization */
.academic-year-section {
    margin-bottom: 2rem;
}

.academic-year-header {
    color: #2d3748;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.academic-year-header i {
    color: #4a5568;
}

.semester-section .card {
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.semester-section .card-header {
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 1rem 1.25rem;
}

.semester-section .card-header h5 {
    color: #2d3748;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.semester-section .card-header h5 i {
    color: #4a5568;
}

.semester-section .badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

/* Enhanced table styling */
.semester-section .table {
    margin-bottom: 0;
}

.semester-section .table thead th {
    background: #e5e7eb;
    border-bottom: 2px solid #e2e8f0;
    color: #374151;
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.75rem;
    vertical-align: middle;
}

.semester-section .table tbody td {
    padding: 0.75rem;
    vertical-align: middle;
    border-top: 1px solid #f1f5f9;
}

.semester-section .table tbody tr:nth-child(even) {
    background: #f9fafb;
}

.semester-section .table tbody tr:hover {
    background: #f8fafc;
}

/* Responsive table adjustments */
@media (max-width: 768px) {
    .semester-section .table-responsive {
        font-size: 0.875rem;
    }

    .semester-section .table thead th,
    .semester-section .table tbody td {
        padding: 0.5rem;
    }

    .academic-year-header {
        font-size: 1.25rem;
    }

    .semester-section .card-header h5 {
        font-size: 1rem;
    }
}
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

/* Allow year column to be compact */
.table-responsive table td:nth-child(3) {
    white-space: nowrap;
    text-align: center;
}

/* Enhanced filter form styling */
.card-body .input-group-text {
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #6b7280;
}

.card-body .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
}

.card-body .btn {
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.card-body .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.card-body .btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
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
                    <div class="user-profile-meta">
                        <div class="user-fullname"><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></div>
                        <?php if (!empty($currentUser['profile']['email'])): ?>
                            <div class="user-email"><i class="fas fa-envelope"></i> <?php echo e($currentUser['profile']['email']); ?></div>
                        <?php endif; ?>
                    </div>
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
                        <div class="col-md-6">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                                <input type="text" name="search" class="form-control" placeholder="Search by course code or name..." value="<?php echo e($search); ?>" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fas fa-graduation-cap"></i></span>
                                </div>
                                <select name="level" class="form-control">
                                    <option value="">All Levels</option>
                                    <?php foreach($academicYears as $year): ?>
                                        <option value="<?php echo $year['level_year']; ?>" <?php echo $level == $year['level_year'] ? 'selected' : ''; ?>>
                                            Year <?php echo e($year['level_year']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="list.php" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
                
                <!-- Active Filters Display -->
                <?php if (!empty($search) || !empty($level)): ?>
                <div class="mt-3">
                    <small class="text-muted">Active filters:</small>
                    <?php if (!empty($search)): ?>
                        <span class="badge badge-info mr-2">Search: "<?php echo e($search); ?>"</span>
                    <?php endif; ?>
                    <?php if (!empty($level)): ?>
                        <span class="badge badge-info">Year: <?php echo e($level); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Courses by Academic Year and Semester -->
        <?php if (!empty($organizedCourses)): ?>
            <?php foreach ($organizedCourses as $year => $semesters): ?>
                <div class="academic-year-section mb-4">
                    <h3 class="academic-year-header">
                        <i class="fas fa-graduation-cap"></i> Academic Year <?php echo $year; ?>
                    </h3>

                    <?php foreach ($semesters as $semester => $courses): ?>
                        <?php
                        $semesterNames = ['', 'Semester 1', 'Semester 2', 'Both Semesters'];
                        $semesterName = $semesterNames[$semester] ?? 'Unknown Semester';
                        ?>

                        <div class="semester-section mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        <i class="fas fa-calendar-alt"></i> <?php echo $semesterName; ?>
                                        <span class="badge badge-primary ml-2"><?php echo count($courses); ?> Courses</span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($courses)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover table-striped">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th>Course Code</th>
                                                        <th>Course Name</th>
                                                        <th>Year</th>
                                                        <th>CU</th>
                                                        <th>LH</th>
                                                        <th>TH</th>
                                                        <th>PH</th>
                                                        <th>CH</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($courses as $course): ?>
                                                        <tr>
                                                            <td><strong><?php echo e($course['course_code']); ?></strong></td>
                                                            <td><?php echo e($course['course_name']); ?></td>
                                                            <td>Year <?php echo e($course['level_year']); ?></td>
                                                            <td><span class="badge badge-info"><?php echo $course['credit_hours']; ?></span></td>
                                                            <td><?php echo $course['lecture_hours'] ?? 0; ?></td>
                                                            <td><?php echo $course['tutorial_hours'] ?? 0; ?></td>
                                                            <td><?php echo $course['practical_hours'] ?? 0; ?></td>
                                                            <td><strong><?php echo ($course['lecture_hours'] ?? 0) + ($course['tutorial_hours'] ?? 0) + ($course['practical_hours'] ?? 0); ?></strong></td>
                                                            <td>
                                                                <div class="btn-group btn-group-sm">
                                                                    <a href="view.php?id=<?php echo $course['id']; ?>" class="btn btn-outline-info btn-sm" title="View">
                                                                        <i class="fas fa-eye"></i>
                                                                    </a>
                                                                    <a href="edit.php?id=<?php echo $course['id']; ?>" class="btn btn-outline-warning btn-sm" title="Edit">
                                                                        <i class="fas fa-edit"></i>
                                                                    </a>
                                                                    <a href="assign.php?id=<?php echo $course['id']; ?>" class="btn btn-outline-success btn-sm" title="Assign">
                                                                        <i class="fas fa-user-plus"></i>
                                                                    </a>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-center text-muted">No courses found for this semester.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center">
                    <i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i>
                    <h4>No Courses Found</h4>
                    <p class="text-muted">No courses match your current filters or no courses have been added yet.</p>
                    <a href="add.php" class="btn btn-primary">Add First Course</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Enhanced form functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('input[name="search"]');
    const levelSelect = document.querySelector('select[name="level"]');
    const filterBtn = document.querySelector('button[type="submit"]');
    const resetBtn = document.querySelector('a[href="list.php"]');
    
    // Auto-submit form when level changes
    levelSelect.addEventListener('change', function() {
        // Only auto-submit if there's a search term or level selected
        if (searchInput.value.trim() || this.value) {
            this.form.submit();
        }
    });
    
    // Add loading state to filter button
    filterBtn.addEventListener('click', function() {
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...';
        this.disabled = true;
        this.form.submit();
    });
    
    // Focus search input on page load if no filters are active
    if (!searchInput.value && !levelSelect.value) {
        searchInput.focus();
    }
    
    // Clear URL parameters on reset (optional enhancement)
    resetBtn.addEventListener('click', function(e) {
        // Remove any query parameters by going to clean URL
        window.location.href = 'list.php';
        e.preventDefault();
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>
