<?php
/**
 * Admin - My Profile (view-only)
 */
require_once '../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$adminProfile = $currentUser['profile'] ?? [];

// Handle profile update (in-place edit + photo upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: profile.php'); exit;
    }

    $first_name = Security::sanitize($_POST['first_name'] ?? '');
    $last_name  = Security::sanitize($_POST['last_name'] ?? '');
    $phone      = Security::sanitize($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');

    $errors = [];
    if (empty($first_name) || empty($last_name)) {
        $errors[] = 'First name and last name are required.';
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Determine user/admin ids
    $adminId = $adminProfile['id'] ?? null;
    $userId = $adminProfile['user_id'] ?? $currentUser['id'];

    // Check email uniqueness (users table) if email changed
    if (!empty($email)) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = :email AND id != :uid');
        $stmt->execute(['email' => $email, 'uid' => $userId]);
        if ($stmt->fetch()) {
            $errors[] = 'Email already in use by another account.';
        }
    }

    // Handle photo upload
    $photo_path = $adminProfile['photo'] ?? null;
    if (!empty($_FILES['photo']['name'])) {
        $allowed_types = ['image/jpeg','image/png','image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB
        if (!in_array($_FILES['photo']['type'], $allowed_types)) {
            $errors[] = 'Only JPG, PNG and GIF images are allowed for profile photo.';
        } elseif ($_FILES['photo']['size'] > $max_size) {
            $errors[] = 'Profile photo must be <= 2MB.';
        } else {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = 'admin_' . ($userId ?: time()) . '_' . time() . '.' . $ext;
            $uploadDir = '../../uploads/admins/';
            $targetRel = 'uploads/admins/' . $filename;
            $target = __DIR__ . '/../../' . $targetRel; // resolve to project root

            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }

            if (@move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                // remove old photo file if present
                if (!empty($photo_path) && file_exists(__DIR__ . '/../../' . $photo_path)) {
                    @unlink(__DIR__ . '/../../' . $photo_path);
                }
                $photo_path = $targetRel;
            } else {
                $errors[] = 'Failed to save uploaded photo.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // ensure admins.photo column exists (add if missing)
            $col = $conn->query("SHOW COLUMNS FROM admins LIKE 'photo'")->fetch();
            if (!$col) {
                $conn->exec("ALTER TABLE admins ADD COLUMN photo VARCHAR(255) NULL AFTER email");
            }

            if ($adminId) {
                $ust = $conn->prepare("UPDATE admins SET first_name = :first_name, last_name = :last_name, phone = :phone, email = :email, photo = :photo, updated_at = NOW() WHERE id = :id");
                $ust->execute([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'phone' => $phone,
                    'email' => $email,
                    'photo' => $photo_path,
                    'id' => $adminId
                ]);
            } else {
                // create admin profile row if missing
                $ist = $conn->prepare("INSERT INTO admins (user_id, first_name, last_name, phone, email, photo) VALUES (:uid,:fn,:ln,:ph,:em,:photo)");
                $ist->execute(['uid' => $userId, 'fn' => $first_name, 'ln' => $last_name, 'ph' => $phone, 'em' => $email, 'photo' => $photo_path]);
                $adminId = $conn->lastInsertId();
            }

            // Update users.email if changed
            if (!empty($email)) {
                $ust2 = $conn->prepare("UPDATE users SET email = :email WHERE id = :uid");
                $ust2->execute(['email' => $email, 'uid' => $userId]);
            }

            $conn->commit();

            // Refresh profile in session
            $pstmt = $conn->prepare('SELECT * FROM admins WHERE user_id = :uid OR id = :id LIMIT 1');
            $pstmt->execute(['uid' => $userId, 'id' => $adminId]);
            $newProfile = $pstmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // update module-specific and legacy session keys
            $_SESSION['admin_profile'] = $newProfile;
            $_SESSION['profile'] = $newProfile;

            // log action
            try { (new Logger())->log($currentUser['id'] ?? $userId, 'update_profile', 'admin', 'Updated admin profile'); } catch (Exception $e) {}

            $session->setFlash('success', 'Profile updated successfully');
            header('Location: profile.php'); exit;

        } catch (Exception $e) {
            // Only roll back if a transaction is active to avoid "no active transaction" errors
            if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
                $conn->rollBack();
            }

            // Log the exact DB/exception message to activity logs for troubleshooting
            try {
                $logger = new Logger();
                $logger->log($currentUser['id'] ?? ($userId ?? 0), 'update_profile_failed', 'admin', 'Profile update failed: ' . $e->getMessage());
            } catch (Exception $logEx) {
                // don't break on logging failure
            }

            $session->setFlash('error', 'Failed to update profile. The error has been logged for review.');
            header('Location: profile.php'); exit;
        }
    } else {
        $session->setFlash('error', implode('; ', $errors));
        header('Location: profile.php'); exit;
    }
}

$pageTitle = 'My Profile - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/admin/sidebar.php'; ?>

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
            <div class="user-info"></div>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="profile-photo-container mb-3">
                            <?php if (!empty($adminProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $adminProfile['photo']; ?>" alt="Profile Photo" class="img-fluid rounded-circle" style="width:140px; height:140px; object-fit:cover;">
                            <?php else: ?>
                                <div class="profile-photo-placeholder rounded-circle d-inline-flex align-items-center justify-content-center" style="width:140px; height:140px; background:var(--primary-color); color:#fff; font-size:2.5rem; font-weight:700;">
                                    <?php echo e(strtoupper(substr($adminProfile['first_name'] ?? 'A',0,1) . substr($adminProfile['last_name'] ?? 'D',0,1))); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h5 class="mb-0"><?php echo e(($adminProfile['first_name'] ?? '') . ' ' . ($adminProfile['last_name'] ?? '')); ?></h5>
                        <small class="text-muted d-block mb-2">Administrator</small>
                        <a href="change-password.php" class="btn btn-sm btn-outline-secondary">Change password</a>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <h6>Contact</h6>
                        <p class="mb-1"><strong>Email:</strong><br><?php echo e($adminProfile['email'] ?? $currentUser['email'] ?? ''); ?></p>
                        <p class="mb-0"><strong>Phone:</strong><br><?php echo e($adminProfile['phone'] ?? ''); ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h5>Profile Details</h5></div>
                    <div class="card-body">

                        <!-- Read-only view -->
                        <div id="profileView">
                            <div class="row mb-2">
                                <div class="col-md-4"><label>Admin ID</label><div class="form-control-plaintext"><?php echo e($adminProfile['id'] ?? '-'); ?></div></div>
                                <div class="col-md-4"><label>Username</label><div class="form-control-plaintext"><?php echo e($currentUser['username'] ?? ''); ?></div></div>
                                <div class="col-md-4"><label>Status</label><div class="form-control-plaintext"><?php echo e($adminProfile['status'] ?? 'active'); ?></div></div>
                            </div>

                            <div class="row mb-2">
                                <div class="col-md-6"><label>First name</label><div class="form-control-plaintext"><?php echo e($adminProfile['first_name'] ?? ''); ?></div></div>
                                <div class="col-md-6"><label>Last name</label><div class="form-control-plaintext"><?php echo e($adminProfile['last_name'] ?? ''); ?></div></div>
                            </div>

                            <div class="row mb-2">
                                <div class="col-md-6"><label>Email</label><div class="form-control-plaintext"><?php echo e($adminProfile['email'] ?? $currentUser['email'] ?? ''); ?></div></div>
                                <div class="col-md-6"><label>Phone</label><div class="form-control-plaintext"><?php echo e($adminProfile['phone'] ?? ''); ?></div></div>
                            </div>

                            <div class="row mb-2">
                                <div class="col-md-6"><label>Created</label><div class="form-control-plaintext"><?php echo e(!empty($adminProfile['created_at']) ? date('Y-m-d H:i', strtotime($adminProfile['created_at'])) : '-'); ?></div></div>
                                <div class="col-md-6"><label>Last updated</label><div class="form-control-plaintext"><?php echo e(!empty($adminProfile['updated_at']) ? date('Y-m-d H:i', strtotime($adminProfile['updated_at'])) : '-'); ?></div></div>
                            </div>

                            <div class="mt-3">
                                <button id="editProfileBtn" class="btn btn-sm btn-outline-primary">Edit profile</button>
                                <a href="dashboard.php" class="btn btn-sm btn-secondary">Back to dashboard</a>
                            </div>
                        </div>

                        <!-- Edit form (hidden by default) -->
                        <form id="profileEditForm" method="post" enctype="multipart/form-data" style="display:none;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="update_profile">

                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <label>First name *</label>
                                    <input type="text" name="first_name" class="form-control" required value="<?php echo e($adminProfile['first_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label>Last name *</label>
                                    <input type="text" name="last_name" class="form-control" required value="<?php echo e($adminProfile['last_name'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <label>Email *</label>
                                    <input type="email" name="email" class="form-control" required value="<?php echo e($adminProfile['email'] ?? $currentUser['email'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" class="form-control" value="<?php echo e($adminProfile['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row mb-2">
                                <div class="col-md-6">
                                    <label>Profile photo (JPG/PNG/GIF, &le; 2MB)</label>
                                    <input type="file" name="photo" accept="image/*" class="form-control-file">
                                </div>
                                <div class="col-md-6">
                                    <label>Preview</label>
                                    <?php if (!empty($adminProfile['photo'])): ?>
                                        <img id="photoPreview" src="<?php echo BASE_URL . '/' . e($adminProfile['photo']); ?>" style="max-width:120px;border-radius:6px;">
                                    <?php else: ?>
                                        <div id="photoPreview" style="width:120px;height:80px;background:#f0f0f0;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#999;">No photo</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group text-right">
                                <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="cancelEditBtn">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var editBtn = document.getElementById('editProfileBtn');
    var cancelBtn = document.getElementById('cancelEditBtn');
    var view = document.getElementById('profileView');
    var form = document.getElementById('profileEditForm');
    var fileInput = document.querySelector('input[name="photo"]');
    var preview = document.getElementById('photoPreview');

    if (editBtn && form && view) {
        editBtn.addEventListener('click', function(e) {
            e.preventDefault();
            view.style.display = 'none';
            form.style.display = 'block';
            // scroll to form
            form.scrollIntoView({behavior:'smooth', block:'center'});
        });
    }
    if (cancelBtn && form && view) {
        cancelBtn.addEventListener('click', function() {
            form.style.display = 'none';
            view.style.display = 'block';
        });
    }

    if (fileInput && preview) {
        fileInput.addEventListener('change', function(e) {
            var f = e.target.files[0];
            if (!f) return;
            var reader = new FileReader();
            reader.onload = function(ev) {
                if (preview.tagName === 'IMG') preview.src = ev.target.result;
                else preview.style.backgroundImage = 'url(' + ev.target.result + ')';
            };
            reader.readAsDataURL(f);
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>