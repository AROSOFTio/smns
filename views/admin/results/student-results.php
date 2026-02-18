<?php
/**
 * Admin - Student Results List
 * Lists all students to allow an admin to select one and view their results slip.
 */
require_once '../../../config.php';
require_once '../../../includes/functions.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser  = $auth->getCurrentUser();
$db   = new Database();
$conn = $db->getConnection();

// Filters
$searchQuery = trim($_GET['search'] ?? '');

// Build query
$sql = "SELECT 
            s.id, s.student_id AS reg_no, s.first_name, s.last_name, s.smns_email,
            p.program_name,
            ay.year_name AS entry_year
        FROM students s
        LEFT JOIN programs p ON s.program_id = p.id
        LEFT JOIN academic_years ay ON s.entry_year = ay.id
        WHERE 1=1";

$params = [];

if ($searchQuery) {
    $sql .= " AND (s.student_id LIKE :search OR s.first_name LIKE :search OR s.last_name LIKE :search OR s.smns_email LIKE :search OR p.program_name LIKE :search)";
    $params['search'] = "%{$searchQuery}%";
}

$sql .= " ORDER BY s.first_name, s.last_name ASC LIMIT 1000";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Select Student for Results - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-user-graduate"></i> View Student Results</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-header">
                <i class="fas fa-search"></i> Find a Student
            </div>
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <div class="form-group mb-2 mr-sm-2">
                        <label for="search" class="sr-only">Search</label>
                        <input type="text" name="search" id="search" class="form-control" value="<?php echo e($searchQuery); ?>" placeholder="Search by Name, Reg#, Email, Program...">
                    </div>
                    <button type="submit" class="btn btn-primary mb-2">Search</button>
                    <?php if ($searchQuery): ?>
                        <a href="student-results.php" class="btn btn-secondary mb-2 ml-2">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Student List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-users"></i> Students (<?php echo count($students); ?>)</span>
            </div>
            <div class="card-body">
                <?php if (empty($students)): ?>
                    <p class="text-center text-muted">No students found matching your criteria.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Reg. Number</th>
                                    <th>Program</th>
                                    <th>Email</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td><?php echo e($student['reg_no']); ?></td>
                                        <td><?php echo e($student['program_name']); ?></td>
                                        <td><?php echo e($student['smns_email']); ?></td>
                                        <td>
                                            <a href="view-slip.php?student_id=<?php echo $student['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> View Results
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
