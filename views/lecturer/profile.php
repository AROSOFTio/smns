<?php
/**
 * Lecturer Profile Page
 */
require_once '../../config.php';

$session = new Session('lecturer');
$auth = new Auth('lecturer');

// Verify lecturer access using Auth helper (module-specific session keys)
if (!$auth->isLoggedIn() || $auth->getRole() !== 'lecturer') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=lecturer');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser || empty($currentUser['profile'])) {
    // Fallback safety: if profile missing, force re-login
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=lecturer');
    exit;
}

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

    <div class="content-area container py-3">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-left:4px solid #28a745;">
                <i class="fas fa-check-circle"></i> <?php echo e($session->getFlash('success')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-left:4px solid #dc3545;">
                <i class="fas fa-exclamation-circle"></i> <?php echo e($session->getFlash('error')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Lecturer Profile Header (mirrors student smartness) -->
        <div class="card mb-3">
            <div class="card-body" style="background:#f8f9fa;">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div style="width:120px; height:120px; border-radius:50%; overflow:hidden; background:#fff; border:4px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                            <?php if (!empty($lecturerProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $lecturerProfile['photo']; ?>" alt="Profile Photo" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; background:#4b5563; font-size:36px;">
                                    <?php echo strtoupper(substr($lecturerProfile['first_name'] ?? 'L',0,1) . substr($lecturerProfile['last_name'] ?? 'E',0,1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col">
                        <div class="text-right">
                            <h4 class="mb-1" style="text-transform:uppercase; font-weight:600; font-size:24px;">
                                <?php echo e($lecturerProfile['last_name'] ?? ''); ?>, <?php echo e($lecturerProfile['first_name'] ?? ''); ?>
                            </h4>
                            <p class="mb-1" style="font-size:16px; color:#666;">
                                <?php echo e($lecturerProfile['lecturer_id'] ?? '-'); ?>
                            </p>
                            <p class="mb-0" style="font-size:14px; color:#999;">
                                <strong><?php echo e($lecturerProfile['title'] ?? 'Lecturer'); ?></strong> &bullet; <?php echo e($lecturerProfile['department'] ?? ''); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Information Cards -->
        <div class="row">
            <!-- Left Column: Basic & Contact Information -->
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="mb-4" style="font-weight:600;">Personal information</h5>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label style="color:#666; font-size:0.9rem;">Lecturer ID</label>
                                    <input type="text" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['lecturer_id']); ?>" readonly>
                                </div>
                                <div class="form-group col-md-6">
                                    <label style="color:#666; font-size:0.9rem;">Status</label>
                                    <input type="text" class="form-control form-control-sm" value="<?php echo e(ucfirst($lecturerProfile['status'])); ?>" readonly>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>First name *</label>
                                    <input type="text" name="first_name" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['first_name']); ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Middle name</label>
                                    <input type="text" name="middle_name" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['middle_name']); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Last name *</label>
                                    <input type="text" name="last_name" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['last_name']); ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Email</label>
                                    <input type="email" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['email']); ?>" readonly>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['phone']); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Office location</label>
                                <input type="text" name="office_location" class="form-control form-control-sm" value="<?php echo e($lecturerProfile['office_location']); ?>">
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm mt-2">💾 Update profile</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Academic / Professional Information -->
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="mb-4" style="font-weight:600;">Academic & professional</h5>
                        <table class="table table-borderless mb-3" style="font-size:14px;">
                            <tbody>
                                <tr>
                                    <td width="40%" style="color:#666;">Title</td>
                                    <td style="font-weight:500;">&nbsp;<?php echo e($lecturerProfile['title'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">Department</td>
                                    <td style="font-weight:500;">&nbsp;<?php echo e($lecturerProfile['department'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">Designation</td>
                                    <td style="font-weight:500;">&nbsp;<?php echo e($lecturerProfile['designation'] ?? '-'); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <h6 style="font-weight:600; color:#374151;">Qualifications</h6>
                        <div class="border rounded p-2" style="font-size:13px; background:#f9fafb; min-height:80px;">
                            <?php echo nl2br(e($lecturerProfile['qualifications'] ?? 'Not provided')); ?>
                        </div>
                        <p class="text-muted mt-2 mb-0" style="font-size:12px;">Qualifications are managed by the academic office.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>