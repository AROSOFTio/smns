<?php
/**
 * Lecturer Profile Page
 */
require_once '../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('lecturer');
$auth = new Auth('lecturer');

// Verify lecturer access
if (!isset($_SESSION['lecturer_logged_in']) || $_SESSION['lecturer_logged_in'] !== true || $_SESSION['lecturer_role'] !== 'lecturer') {
    header('Location: login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];

$db = new Database();
$conn = $db->getConnection();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = Security::sanitize($_POST['first_name'] ?? '');
    $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
    $last_name = Security::sanitize($_POST['last_name'] ?? '');
    $phone = Security::sanitize($_POST['phone'] ?? '');
    $office_location = Security::sanitize($_POST['office_location'] ?? '');

    // Validate required fields
    $missing = [];
    if (empty($first_name)) $missing[] = 'First Name';
    if (empty($last_name)) $missing[] = 'Last Name';

    if ($missing) {
        $session->setFlash('error', 'Please fill in required fields: ' . implode(', ', $missing));
    } else {
        try {
            // Update lecturer profile
            $stmt = $conn->prepare("
                UPDATE lecturers SET
                    first_name = :first_name,
                    middle_name = :middle_name,
                    last_name = :last_name,
                    phone = :phone,
                    office_location = :office_location,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'office_location' => $office_location,
                'id' => $lecturerProfile['id']
            ]);

            $session->setFlash('success', 'Profile updated successfully');

            // Refresh profile data
            $currentUser = $auth->getCurrentUser();
            $lecturerProfile = $currentUser['profile'];

        } catch (Exception $e) {
            $session->setFlash('error', 'Error updating profile: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'My Profile - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/lecturer/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>My Profile</h4>
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
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item active">
                            <i>👤</i> My Profile
                        </a>
                        <a href="my-courses.php" class="dropdown-item">
                            <i>📚</i> My Courses
                        </a>
                        <a href="reports.php" class="dropdown-item">
                            <i>📁</i> Reports
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

        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger">
                <?php echo e($session->getFlash('error')); ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Profile Photo Section -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Profile Photo</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="profile-photo-container mb-3">
                            <?php if (!empty($lecturerProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $lecturerProfile['photo']; ?>" alt="Profile Photo" class="img-fluid rounded-circle" style="width: 150px; height: 150px; object-fit: cover;">
                            <?php else: ?>
                                <div class="profile-photo-placeholder rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 150px; height: 150px; background: var(--primary-color); color: white; font-size: 3rem; font-weight: bold;">
                                    <?php echo strtoupper(substr($lecturerProfile['first_name'], 0, 1) . substr($lecturerProfile['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small">Profile photo is managed by administrators</p>
                    </div>
                </div>
            </div>

            <!-- Profile Information -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Personal Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Lecturer ID</label>
                                        <input type="text" class="form-control" value="<?php echo e($lecturerProfile['lecturer_id']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Title</label>
                                        <input type="text" class="form-control" value="<?php echo e($lecturerProfile['title'] ?? ''); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <input type="text" class="form-control" value="<?php echo e(ucfirst($lecturerProfile['status'])); ?>" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>First Name *</label>
                                        <input type="text" name="first_name" class="form-control" value="<?php echo e($lecturerProfile['first_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Middle Name</label>
                                        <input type="text" name="middle_name" class="form-control" value="<?php echo e($lecturerProfile['middle_name']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Last Name *</label>
                                        <input type="text" name="last_name" class="form-control" value="<?php echo e($lecturerProfile['last_name']); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Email</label>
                                        <input type="email" class="form-control" value="<?php echo e($lecturerProfile['email']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="tel" name="phone" class="form-control" value="<?php echo e($lecturerProfile['phone']); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Department</label>
                                        <input type="text" class="form-control" value="<?php echo e($lecturerProfile['department']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Designation</label>
                                        <input type="text" class="form-control" value="<?php echo e($lecturerProfile['designation']); ?>" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Office Location</label>
                                <input type="text" name="office_location" class="form-control" value="<?php echo e($lecturerProfile['office_location']); ?>">
                            </div>

                            <div class="form-group">
                                <label>Qualifications</label>
                                <textarea class="form-control" rows="3" readonly><?php echo e($lecturerProfile['qualifications']); ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">💾 Update Profile</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>