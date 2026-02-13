<?php
/**
 * Academic Years - List
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT * FROM academic_years ORDER BY start_date DESC");
$years = $stmt->fetchAll();

$pageTitle = 'Academic Years - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left"><h4>Academic Years</h4></div>
        <div class="topbar-right"><a href="add.php" class="btn btn-primary">Add Year</a></div>
    </div>
    <div class="content-area">
        <div class="card">
            <div class="card-body">
                <?php if (count($years) > 0): ?>
                    <table class="table table-sm">
                        <thead><tr><th>Year</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach($years as $y): ?>
                                <tr>
                                    <td><?php echo e($y['year_name']); ?></td>
                                    <td><?php echo e($y['start_date']); ?></td>
                                    <td><?php echo e($y['end_date']); ?></td>
                                    <td><?php echo e($y['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No academic years defined.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>
