<?php
/**
 * Assigned Courses List - Admin
 * View all course assignments to lecturers
 */
require_once '../../../config.php';

// Simple session handling


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
$lecturer = $_GET['lecturer'] ?? '';
$semester = $_GET['semester'] ?? '';
$status = $_GET['status'] ?? '';

// Build query
$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT ca.*, c.course_code, c.course_name, c.credit_hours,
               l.lecturer_id, l.first_name, l.last_name, l.specialization,
               p.program_code, p.program_name,
               s.semester_name, ay.year_name
        FROM course_assignments ca
        INNER JOIN courses c ON ca.course_id = c.id
        INNER JOIN lecturers l ON ca.lecturer_id = l.id
        INNER JOIN programs p ON c.program_id = p.id
        INNER JOIN semesters s ON ca.semester_id = s.id
        INNER JOIN academic_years ay ON s.academic_year_id = ay.id
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (c.course_code LIKE :search OR c.course_name LIKE :search OR l.first_name LIKE :search OR l.last_name LIKE :search)";
    $params['search'] = "%$search%";
}

if ($lecturer) {
    $sql .= " AND ca.lecturer_id = :lecturer";
    $params['lecturer'] = $lecturer;
}

if ($semester) {
    $sql .= " AND ca.semester_id = :semester";
    $params['semester'] = $semester;
}

if ($status) {
    $sql .= " AND ca.status = :status";
    $params['status'] = $status;
}

$sql .= " ORDER BY ay.year_name DESC, s.semester_number DESC, c.course_code";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Get lecturers for filter
$stmt = $conn->query("SELECT * FROM lecturers WHERE status = 'active' ORDER BY first_name, last_name");
$lecturers = $stmt->fetchAll();

// Get semesters for filter
$stmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.year_name DESC, s.semester_number DESC");
$semesters = $stmt->fetchAll();

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Assigned Courses - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
/* Override global overflow-x hidden for table scrolling */
.content-area .table-responsive {
    overflow-x: auto !important;
    max-width: 100% !important;
}

.content-area .card-body {
    overflow-x: visible !important;
}

/* Ensure vertical scrolling works */
html, body {
    overflow-y: auto !important;
    height: auto !important;
}

.main-content {
    overflow-y: auto !important;
    min-height: 100vh !important;
}

.content-area {
    overflow-y: auto !important;
}

.table-responsive table td:nth-child(5),
.table-responsive table th:nth-child(6),
.table-responsive table td:nth-child(6) {
    display: none;
}

@media (max-width: 768px) {
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
            <h4>Course Assignments</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <a href="list.php" class="btn btn-secondary">← Back to Courses</a>
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
                        <div class="col-md-3">
                            <input type="text" name="search" class="form-control" placeholder="Search by course or lecturer..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="lecturer" class="form-control">
                                <option value="">All Lecturers</option>
                                <?php foreach($lecturers as $lect): ?>
                                    <option value="<?php echo $lect['id']; ?>" <?php echo $lecturer == $lect['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($lect['first_name'] . ' ' . $lect['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="semester" class="form-control">
                                <option value="">All Semesters</option>
                                <?php foreach($semesters as $sem): ?>
                                    <option value="<?php echo $sem['id']; ?>" <?php echo $semester == $sem['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($sem['year_name'] . ' - ' . $sem['semester_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="assigned.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assignments Table -->
        <div class="card">
            <div class="card-header">
                Course Assignments (<?php echo count($assignments); ?>)
            </div>
            <div class="card-body">
                <?php if (count($assignments) > 0): ?>
                    <div class="table-responsive" style="overflow-x: auto; max-width: 100%;">
                        <table class="table table-hover" style="min-width: 1000px;">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Lecturer</th>
                                    <th>Program</th>
                                    <th>Semester</th>
                                    <th>Assigned Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($assignment['course_code']); ?></strong>
                                            <br><small class="text-muted"><?php echo e($assignment['course_name']); ?> (<?php echo $assignment['credit_hours']; ?> credits)</small>
                                        </td>
                                        <td>
                                            <strong><?php echo e($assignment['first_name'] . ' ' . $assignment['last_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo e($assignment['lecturer_id']); ?>
                                            <?php if ($assignment['specialization']): ?>
                                                - <?php echo e($assignment['specialization']); ?>
                                            <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php echo e($assignment['program_code']); ?>
                                            <br><small class="text-muted"><?php echo e($assignment['program_name']); ?></small>
                                        </td>
                                        <td><?php echo e($assignment['year_name'] . ' - ' . $assignment['semester_name']); ?></td>
                                        <td><?php echo Helper::formatDate($assignment['assigned_date']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo Helper::getStatusColor($assignment['status']); ?>">
                                                <?php echo e(ucfirst($assignment['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view.php?id=<?php echo $assignment['course_id']; ?>" class="btn btn-sm btn-info" title="View Course">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit-assignment.php?id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-warning" title="Edit Assignment">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" title="Remove Assignment"
                                                    onclick="confirmRemove(<?php echo $assignment['id']; ?>, '<?php echo e($assignment['course_code']); ?>', '<?php echo e($assignment['first_name'] . ' ' . $assignment['last_name']); ?>')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-graduation-cap fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No Course Assignments Found</h5>
                        <p class="text-muted">No courses have been assigned to lecturers yet.</p>
                        <a href="assign.php" class="btn btn-primary">Assign First Course</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Remove Assignment Modal -->
<div class="modal fade" id="removeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Remove Course Assignment</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove this course assignment?</p>
                <div id="assignmentDetails"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a id="confirmRemoveBtn" href="#" class="btn btn-danger">Remove Assignment</a>
            </div>
        </div>
    </div>
</div>

<script>
function confirmRemove(assignmentId, courseCode, lecturerName) {
    document.getElementById('assignmentDetails').innerHTML = `
        <div class="alert alert-warning">
            <strong>Course:</strong> ${courseCode}<br>
            <strong>Lecturer:</strong> ${lecturerName}
        </div>
    `;
    document.getElementById('confirmRemoveBtn').href = 'remove-assignment.php?id=' + assignmentId;
    $('#removeModal').modal('show');
}
</script>

<?php include '../../../includes/footer.php'; ?>