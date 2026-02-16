<?php
/**
 * Edit Lecturer - Admin
 */
require_once dirname(__DIR__, 3) . '/config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$lecturerId = (int)($_GET['id'] ?? 0);

if (!$lecturerId) {
    $session->setFlash('error', 'Invalid lecturer ID');
    header('Location: list.php');
    exit;
}

// Get lecturer details
$stmt = $conn->prepare("
    SELECT l.*, u.username, u.email as user_email, u.status as user_status
    FROM lecturers l
    INNER JOIN users u ON l.user_id = u.id
    WHERE l.id = :id
");
$stmt->execute(['id' => $lecturerId]);
$lecturer = $stmt->fetch();

if (!$lecturer) {
    $session->setFlash('error', 'Lecturer not found');
    header('Location: list.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize inputs
    $title = Security::sanitize($_POST['title'] ?? '');
    $first_name = Security::sanitize($_POST['first_name'] ?? '');
    $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
    $last_name = Security::sanitize($_POST['last_name'] ?? '');
    $gender = Security::sanitize($_POST['gender'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $national_id = Security::sanitize($_POST['national_id'] ?? '');
    $phone = Security::sanitize($_POST['phone'] ?? '');
    $office_location = Security::sanitize($_POST['office_location'] ?? '');
    $department = Security::sanitize($_POST['department'] ?? '');
    $designation = Security::sanitize($_POST['designation'] ?? '');
    $specialization = Security::sanitize($_POST['specialization'] ?? '');
    $qualifications = Security::sanitize($_POST['qualifications'] ?? '');
    $employment_type = Security::sanitize($_POST['employment_type'] ?? '');
    $employment_date = $_POST['employment_date'] ?? '';
    $status = Security::sanitize($_POST['status'] ?? 'active');
    $user_status = Security::sanitize($_POST['user_status'] ?? 'active');

    // Validate required fields
    $missing = [];
    if (empty($first_name)) $missing[] = 'First Name';
    if (empty($last_name)) $missing[] = 'Last Name';
    if (empty($department)) $missing[] = 'Department';

    if ($missing) {
        $session->setFlash('error', 'Please fill in required fields: ' . implode(', ', $missing));
    } else {
        try {
            $conn->beginTransaction();

            // Update lecturer record
            $lecturerStmt = $conn->prepare("
                UPDATE lecturers SET
                    title = :title,
                    first_name = :first_name,
                    middle_name = :middle_name,
                    last_name = :last_name,
                    gender = :gender,
                    date_of_birth = :date_of_birth,
                    national_id = :national_id,
                    phone = :phone,
                    office_location = :office_location,
                    department = :department,
                    designation = :designation,
                    specialization = :specialization,
                    qualifications = :qualifications,
                    employment_type = :employment_type,
                    employment_date = :employment_date,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $lecturerStmt->execute([
                'title' => $title,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'gender' => $gender,
                'date_of_birth' => $date_of_birth ?: null,
                'national_id' => $national_id,
                'phone' => $phone,
                'office_location' => $office_location,
                'department' => $department,
                'designation' => $designation,
                'specialization' => $specialization,
                'qualifications' => $qualifications,
                'employment_type' => $employment_type,
                'employment_date' => $employment_date ?: null,
                'status' => $status,
                'id' => $lecturerId
            ]);

            // Update user status
            $userStmt = $conn->prepare("UPDATE users SET status = :status WHERE id = :id");
            $userStmt->execute(['status' => $user_status, 'id' => $lecturer['user_id']]);

            // Handle photo upload
            if (!empty($_FILES['photo']['name'])) {
                $uploadDir = UPLOAD_PATH . '/lecturers/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $target = $uploadDir . $lecturer['lecturer_id'] . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    $rel = 'uploads/lecturers/' . $lecturer['lecturer_id'] . '.' . $ext;
                    $photoStmt = $conn->prepare("UPDATE lecturers SET photo = :photo WHERE id = :id");
                    $photoStmt->execute(['photo' => $rel, 'id' => $lecturerId]);
                }
            }

            $conn->commit();
            $session->setFlash('success', 'Lecturer updated successfully');
            header('Location: view.php?id=' . $lecturerId);
            exit;

        } catch (Exception $e) {
            $conn->rollBack();
            $session->setFlash('error', 'Error updating lecturer: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Edit Lecturer - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>Edit Lecturer</h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <a href="view.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-secondary mr-2">👁️ View</a>
            <a href="list.php" class="btn btn-secondary mr-2">← Back to List</a>
            <?php include dirname(__DIR__, 3) . '/includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success">
                <?php echo e($session->getFlash('success')); ?>
            </div>
        <?php endif; ?>

        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo e($session->getFlash('error')); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <!-- Personal Information -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Personal Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Title</label>
                                <select name="title" class="form-control">
                                    <option value="">Select Title</option>
                                    <option value="Dr." <?php echo $lecturer['title'] == 'Dr.' ? 'selected' : ''; ?>>Dr.</option>
                                    <option value="Prof." <?php echo $lecturer['title'] == 'Prof.' ? 'selected' : ''; ?>>Prof.</option>
                                    <option value="Mr." <?php echo $lecturer['title'] == 'Mr.' ? 'selected' : ''; ?>>Mr.</option>
                                    <option value="Mrs." <?php echo $lecturer['title'] == 'Mrs.' ? 'selected' : ''; ?>>Mrs.</option>
                                    <option value="Ms." <?php echo $lecturer['title'] == 'Ms.' ? 'selected' : ''; ?>>Ms.</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>First Name *</label>
                                    <input type="text" name="first_name" class="form-control" value="<?php echo e($lecturer['first_name']); ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Middle Name</label>
                                    <input type="text" name="middle_name" class="form-control" value="<?php echo e($lecturer['middle_name']); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Last Name *</label>
                                    <input type="text" name="last_name" class="form-control" value="<?php echo e($lecturer['last_name']); ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Gender</label>
                                    <select name="gender" class="form-control">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo $lecturer['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $lecturer['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo $lecturer['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Date of Birth</label>
                                    <input type="date" name="date_of_birth" class="form-control" value="<?php echo e($lecturer['date_of_birth']); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" class="form-control" value="<?php echo e($lecturer['phone']); ?>">
                                </div>
                                <div class="form-group col-md-6">
                                    <label>National ID</label>
                                    <input type="text" name="national_id" class="form-control" value="<?php echo e($lecturer['national_id']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Professional Information -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Professional Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Department *</label>
                                <input type="text" name="department" class="form-control" value="<?php echo e($lecturer['department']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Designation</label>
                                <input type="text" name="designation" class="form-control" value="<?php echo e($lecturer['designation']); ?>">
                            </div>

                            <div class="form-group">
                                <label>Specialization</label>
                                <input type="text" name="specialization" class="form-control" value="<?php echo e($lecturer['specialization']); ?>">
                            </div>

                            <div class="form-group">
                                <label>Qualifications</label>
                                <textarea name="qualifications" class="form-control" rows="3"><?php echo e($lecturer['qualifications']); ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Employment Type</label>
                                    <select name="employment_type" class="form-control">
                                        <option value="Full-time" <?php echo $lecturer['employment_type'] == 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                                        <option value="Part-time" <?php echo $lecturer['employment_type'] == 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                                        <option value="Contract" <?php echo $lecturer['employment_type'] == 'Contract' ? 'selected' : ''; ?>>Contract</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Employment Date</label>
                                    <input type="date" name="employment_date" class="form-control" value="<?php echo e($lecturer['employment_date']); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Office Location</label>
                                <input type="text" name="office_location" class="form-control" value="<?php echo e($lecturer['office_location']); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status and Photo -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Status Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Lecturer Status</label>
                                    <select name="status" class="form-control">
                                        <option value="active" <?php echo $lecturer['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $lecturer['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="retired" <?php echo $lecturer['status'] == 'retired' ? 'selected' : ''; ?>>Retired</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>User Account Status</label>
                                    <select name="user_status" class="form-control">
                                        <option value="active" <?php echo $lecturer['user_status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $lecturer['user_status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="suspended" <?php echo $lecturer['user_status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Photo</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($lecturer['photo'])): ?>
                                <div class="mb-3">
                                    <img src="<?php echo BASE_URL . '/' . $lecturer['photo']; ?>" alt="Current Photo" class="img-fluid rounded" style="max-width: 150px;">
                                </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label>Upload New Photo</label>
                                <input type="file" name="photo" class="form-control" accept="image/*">
                                <small class="form-text text-muted">Leave empty to keep current photo</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" class="btn btn-primary">💾 Update Lecturer</button>
                <a href="view.php?id=<?php echo $lecturer['id']; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>