<?php
/**
 * Add Academic Year
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year_name = Security::sanitize($_POST['year_name'] ?? '');
        $end_date = $_POST['end_date'] ?? null;
        $status = Security::sanitize($_POST['status'] ?? 'active');

        if (empty($year_name) || empty($start_date) || empty($end_date)) {
            $error = 'Please fill required fields';
        } else {
            $stmt = $conn->prepare("INSERT INTO academic_years (year_name,start_date,end_date,status) VALUES (:year_name,:start_date,:end_date,:status)");
            $stmt->execute(['year_name'=>$year_name,'start_date'=>$start_date,'end_date'=>$end_date,'status'=>$status]);
            $success = 'Academic year added';
        }
    }

$pageTitle = 'Add Academic Year - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left"><h4>Add Academic Year</h4></div>
        <div class="topbar-right"><a href="list.php" class="btn btn-secondary">Back</a></div>
    </div>
    <div class="content-area">
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
        <div class="card"><div class="card-body">
            <form method="POST">
                <div class="form-group"><label>Year Name (e.g., 2025/2026)</label><input type="text" name="year_name" class="form-control" required></div>
                <div class="form-row"><div class="form-group col-md-6"><label>Start Date</label><input type="date" name="start_date" class="form-control" required></div>
                <div class="form-group col-md-6"><label>End Date</label><input type="date" name="end_date" class="form-control" required></div></div>
                <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option><option value="completed">Completed</option></select></div>
                <div class="text-right"><button type="submit" class="btn btn-primary">Add</button></div>
            </form>
        </div></div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>
