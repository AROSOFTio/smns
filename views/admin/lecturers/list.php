<?php
/**
 * Lecturers List - Admin
 */
require_once dirname(__DIR__, 3) . '/config.php';

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

// Handle POST request for truncating lecturers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'truncate_lecturers') {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $conn->beginTransaction();

        // Disable foreign key checks
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');

        // 1. Get user_ids for all lecturers to delete their user accounts
        $stmt = $conn->query("SELECT user_id FROM lecturers WHERE user_id IS NOT NULL");
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Get photos to delete from filesystem
        $stmt = $conn->query("SELECT photo FROM lecturers WHERE photo IS NOT NULL AND photo != ''");
        $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Truncate the lecturers table
        $conn->exec('TRUNCATE TABLE lecturers');

        // 4. Delete associated user accounts
        if (!empty($userIds)) {
            $inQuery = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($inQuery) AND role = 'lecturer'");
            $stmt->execute($userIds);
        }

        // 5. Delete uploaded photos
        $uploadDir = dirname(__DIR__, 3) . '/uploads/lecturers/';
        foreach ($photos as $photo) {
            $filePath = $uploadDir . basename($photo);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Re-enable foreign key checks
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');

        $conn->commit();
        $session->setFlash('success', 'All lecturers, their user accounts, and uploaded photos have been permanently deleted.');
    } catch (Exception $e) {
        // Rollback and re-enable keys on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        // Ensure foreign key checks are re-enabled even if the transaction fails
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
        
        // Log the error and set a flash message
        $detailed_error = "Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
        error_log("Lecturer truncation error: " . $detailed_error);
        $session->setFlash('error', 'An error occurred. ' . $detailed_error);
    }

    // Redirect back to the list to prevent form re-submission
    header('Location: list.php');
    exit;
}


$currentUser = $auth->getCurrentUser();

// Get filter parameters
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';
$designation = $_GET['designation'] ?? '';

// Build query
$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT l.*, u.status as user_status
        FROM lecturers l
        INNER JOIN users u ON l.user_id = u.id
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (l.lecturer_id LIKE :search OR l.first_name LIKE :search 
              OR l.last_name LIKE :search OR l.email LIKE :search)";
    $params['search'] = "%$search%";
}

if ($department) {
    $sql .= " AND l.department LIKE :department";
    $params['department'] = "%$department%";
}

if ($status) {
    $sql .= " AND l.status = :status";
    $params['status'] = $status;
}

if ($designation) {
    $sql .= " AND l.specialization LIKE :designation";
    $params['designation'] = "%$designation%";
}

$sql .= " ORDER BY l.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$lecturers = $stmt->fetchAll();

// Get departments for filter
$stmt = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmt->fetchAll();

// Get specializations for filter
$stmt = $conn->query("SELECT DISTINCT specialization FROM lecturers WHERE specialization IS NOT NULL AND specialization != '' ORDER BY specialization");
$specializations = $stmt->fetchAll();

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Lecturers List - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>Lecturers Management</h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <a href="approvals.php" class="btn btn-warning mr-2">⏳ Pending Approvals</a>
            <a href="add-lecturer.php" class="btn btn-primary mr-2">➕ Add New Lecturer</a>
            
            <!-- Truncate Button -->
            <form method="POST" action="list.php" onsubmit="return confirm('DANGER: This will permanently delete ALL lecturers, their user accounts, and uploaded photos. This cannot be undone. Are you absolutely sure?');" style="display:inline;">
                <input type="hidden" name="action" value="truncate_lecturers">
                <button type="submit" class="btn btn-danger mr-2">
                    <i class="fas fa-trash-alt"></i> Delete All Lecturers
                </button>
            </form>

            <?php include dirname(__DIR__, 3) . '/includes/notification_bell.php'; ?>
        </div>
    </div>
    
    <div class="content-area">
        <?php 
        $successMessage = $session->getFlash('success');
        if ($successMessage): 
        ?>
            <div class="alert alert-success">
                <?php echo e($successMessage); ?>
            </div>
        <?php endif; ?>
        
        <?php 
        $errorMessage = $session->getFlash('error');
        if ($errorMessage): 
        ?>
            <div class="alert alert-danger">
                <?php echo e($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="card">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="row">
                        <div class="col-md-3">
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="department" class="form-control">
                                <option value="">All Departments</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['department']; ?>" <?php echo $department == $dept['department'] ? 'selected' : ''; ?>>
                                        <?php echo e($dept['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="designation" class="form-control">
                                <option value="">All Specializations</option>
                                <?php foreach($specializations as $spec): ?>
                                    <option value="<?php echo $spec['specialization']; ?>" <?php echo $designation == $spec['specialization'] ? 'selected' : ''; ?>>
                                        <?php echo e($spec['specialization']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="list.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lecturers Table -->
        <div class="card">
            <div class="card-header">
                Lecturers (<?php echo count($lecturers); ?>)
            </div>
            <div class="card-body">
                <?php if (count($lecturers) > 0): ?>
                    <div class="table-responsive" style="overflow-x: auto; max-width: 100%;">
                        <table class="table table-hover table-sm" style="word-break:break-word; font-size: 0.875rem;">
                            <thead>
                                <tr>
                                    <th style="min-width:80px;">Lecturer ID</th>
                                    <th style="min-width:100px;">Name</th>
                                    <th style="min-width:120px;">Email</th>
                                    <th style="min-width:80px;">Department</th>
                                    <th style="min-width:90px;">Designation</th>
                                    <th style="min-width:60px;">Status</th>
                                    <th style="min-width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($lecturers as $lecturer): ?>
                                    <tr>
                                        <td><strong><?php echo e($lecturer['lecturer_id']); ?></strong></td>
                                        <td>
                                            <?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>
                                            <br><small class="text-muted" style="font-size: 0.75rem;"><?php echo e($lecturer['qualifications'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td style="word-break:break-all;max-width:120px; font-size: 0.8rem;">
                                            <?php echo e($lecturer['email']); ?>
                                        </td>
                                        <td><?php echo e($lecturer['department'] ?? 'N/A'); ?></td>
                                        <td><?php echo e($lecturer['specialization'] ?? 'N/A'); ?></td>
                                        <td style="font-size: 0.8rem; white-space: nowrap;">
                                            <?php
                                            $statusClass = 'secondary';
                                            $statusColor = 'gray';
                                            switch($lecturer['status']) {
                                                case 'active':
                                                    $statusClass = 'success';
                                                    $statusColor = 'green';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'warning';
                                                    $statusColor = 'orange';
                                                    break;
                                                case 'rejected':
                                                    $statusClass = 'danger';
                                                    $statusColor = 'red';
                                                    break;
                                                case 'inactive':
                                                    $statusClass = 'secondary';
                                                    $statusColor = 'gray';
                                                    break;
                                            }
                                            ?>
                                            <span style="color:<?php echo $statusColor; ?>">●</span>
                                            <span class="badge badge-<?php echo $statusClass; ?>" style="font-size: 0.7rem; margin-left: 0.25rem;">
                                                <?php echo e(ucfirst($lecturer['status'])); ?>
                                            </span>
                                        </td>
                                        <td style="white-space:nowrap; padding: 0.25rem;">
                                            <a href="view.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-info btn-xs" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-warning btn-xs" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="reset_password.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-secondary btn-xs" title="Reset Password"><i class="fas fa-key"></i></a>
                                            <a href="send_invite.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-primary btn-xs" title="Send Invite"><i class="fas fa-envelope"></i></a>
                                            <a href="../courses/list.php?lecturer_id=<?php echo $lecturer['id']; ?>" class="btn btn-sm btn-success btn-xs" title="Assign Courses"><i class="fas fa-user-plus"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No lecturers found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>