<?php
/**
 * Super-admin user removal across all modules.
 */
require_once __DIR__ . '/../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=session_expired&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();
$isSuperAdmin = false;

try {
    $isSuperAdmin = FeeStructureGovernance::isSuperAdmin($conn, (int)($currentUser['id'] ?? 0));
} catch (Exception $e) {
    $isSuperAdmin = false;
}

if (!$isSuperAdmin) {
    setFlash('error', 'Only the configured super admin can remove users across modules.');
    header('Location: add.php');
    exit;
}

$userId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', 'Invalid user ID.');
    header('Location: add.php');
    exit;
}

if ($userId === (int)($currentUser['id'] ?? 0)) {
    setFlash('error', 'You cannot remove your own super admin account while signed in.');
    header('Location: add.php');
    exit;
}

function smnsDeleteUserColumnExists(PDO $conn, $table, $column) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
        $stmt->execute(['column_name' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function smnsDeleteFetchUser(PDO $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT
            u.*,
            a.first_name AS admin_first_name,
            a.last_name AS admin_last_name,
            l.first_name AS lecturer_first_name,
            l.last_name AS lecturer_last_name,
            l.lecturer_id,
            s.first_name AS student_first_name,
            s.middle_name AS student_middle_name,
            s.last_name AS student_last_name,
            s.student_id,
            f.first_name AS finance_first_name,
            f.last_name AS finance_last_name
        FROM users u
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN lecturers l ON l.user_id = u.id
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN finance_staff f ON f.user_id = u.id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function smnsDeleteDisplayName(array $user) {
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        $name = trim((string)($user['admin_first_name'] ?? '') . ' ' . (string)($user['admin_last_name'] ?? ''));
    } elseif ($role === 'lecturer') {
        $name = trim((string)($user['lecturer_first_name'] ?? '') . ' ' . (string)($user['lecturer_last_name'] ?? ''));
    } elseif ($role === 'student') {
        $name = trim((string)($user['student_first_name'] ?? '') . ' ' . (string)($user['student_middle_name'] ?? '') . ' ' . (string)($user['student_last_name'] ?? ''));
    } elseif ($role === 'finance') {
        $name = trim((string)($user['finance_first_name'] ?? '') . ' ' . (string)($user['finance_last_name'] ?? ''));
    } else {
        $name = '';
    }
    return $name !== '' ? $name : (string)($user['username'] ?? 'Unknown User');
}

function smnsDeleteIdentifier(array $user) {
    if ((string)($user['role'] ?? '') === 'student') {
        return (string)($user['student_id'] ?? '');
    }
    if ((string)($user['role'] ?? '') === 'lecturer') {
        return (string)($user['lecturer_id'] ?? '');
    }
    return 'User #' . (int)($user['id'] ?? 0);
}

function smnsDeactivateProfile(PDO $conn, array $user) {
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'student') {
        $stmt = $conn->prepare("UPDATE students SET status = 'withdrawn', updated_at = NOW() WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    } elseif ($role === 'lecturer') {
        $stmt = $conn->prepare("UPDATE lecturers SET status = 'inactive', updated_at = NOW() WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    }
}

$user = smnsDeleteFetchUser($conn, $userId);
if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: add.php');
    exit;
}

$configuredSuperAdminId = (int)FeeStructureGovernance::resolveSuperAdminUserId($conn);
if ($userId === $configuredSuperAdminId) {
    setFlash('error', 'The configured super admin account is protected. Assign another super admin first if this account must be removed.');
    header('Location: add.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request token. Refresh and try again.');
        header('Location: delete.php?id=' . $userId);
        exit;
    }

    $mode = (string)($_POST['action_mode'] ?? 'remove_access');
    $mode = $mode === 'hard_delete' ? 'hard_delete' : 'remove_access';
    $expectedPhrase = $mode === 'hard_delete' ? 'HARD DELETE USER' : 'REMOVE USER';
    $confirm = strtoupper(trim((string)($_POST['confirm_delete'] ?? '')));

    if ($confirm !== $expectedPhrase) {
        setFlash('error', 'Confirmation phrase mismatch. Type "' . $expectedPhrase . '" to proceed.');
        header('Location: delete.php?id=' . $userId);
        exit;
    }

    try {
        $conn->beginTransaction();

        if ($mode === 'remove_access') {
            $suffix = date('YmdHis') . '_' . $userId;
            $disabledUsername = 'removed_user_' . $suffix;
            $disabledEmail = 'removed.user.' . $suffix . '@redacted.local';
            $disabledHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

            $sql = "
                UPDATE users
                SET username = :username,
                    email = :email,
                    password_hash = :password_hash,
                    status = 'inactive',
                    failed_login_attempts = 0,
                    account_locked_until = NULL,
                    password_reset_token = NULL,
                    password_reset_expires = NULL
            ";
            $params = [
                'username' => $disabledUsername,
                'email' => $disabledEmail,
                'password_hash' => $disabledHash,
                'id' => $userId
            ];
            if (smnsDeleteUserColumnExists($conn, 'users', 'require_password_change')) {
                $sql .= ", require_password_change = 0";
            }
            if (smnsDeleteUserColumnExists($conn, 'users', 'updated_at')) {
                $sql .= ", updated_at = NOW()";
            }
            $sql .= " WHERE id = :id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            smnsDeactivateProfile($conn, $user);
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
        }

        try {
            $logger = new Logger();
            $logger->log(
                (int)($currentUser['id'] ?? 0),
                $mode === 'hard_delete' ? 'delete' : 'remove_access',
                'users',
                ($mode === 'hard_delete' ? 'Hard deleted' : 'Removed access for') . ' user: ' . (string)($user['username'] ?? '') . ' (' . (string)($user['role'] ?? '') . ')'
            );
        } catch (Exception $e) {
        }

        $conn->commit();
        setFlash('success', $mode === 'hard_delete'
            ? 'User hard deleted successfully.'
            : 'User access removed successfully. Records are preserved for audit and reporting.');
        header('Location: add.php');
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        setFlash('error', 'Remove failed: ' . $e->getMessage());
        header('Location: delete.php?id=' . $userId);
        exit;
    }
}

$flashError = getFlash('error');
$displayName = smnsDeleteDisplayName($user);
$identifier = smnsDeleteIdentifier($user);

$pageTitle = 'Remove User - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include __DIR__ . '/../../../includes/header.php';
?>

<?php include __DIR__ . '/../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left">
            <h4>
                <a href="add.php" class="btn btn-link"><i class="fas fa-arrow-left"></i> Back to Manage Users</a>
                Remove User
            </h4>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <?php include __DIR__ . '/../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="container-fluid">
            <?php if ($flashError): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo e($flashError); ?></div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-user-slash"></i> Super Admin User Removal</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <strong>Target:</strong> <?php echo e($displayName); ?>
                                <br>
                                <strong>Username:</strong> <?php echo e((string)$user['username']); ?>
                                <br>
                                <strong>Email:</strong> <?php echo e((string)$user['email']); ?>
                                <br>
                                <strong>Role:</strong> <?php echo e(ucfirst((string)$user['role'])); ?>
                                <br>
                                <strong>Identifier:</strong> <?php echo e($identifier ?: '-'); ?>
                            </div>

                            <form method="POST" action="">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$userId; ?>">

                                <div class="form-group">
                                    <label for="action_mode">Removal action</label>
                                    <select class="form-control" id="action_mode" name="action_mode">
                                        <option value="remove_access">Remove access only, preserve records</option>
                                        <option value="hard_delete">Hard delete account and cascaded module profile</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        Remove access is recommended. Hard delete may fail if protected records reference this user.
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_delete">Confirmation phrase</label>
                                    <input type="text" class="form-control" id="confirm_delete" name="confirm_delete" placeholder="REMOVE USER" required>
                                    <small class="form-text text-muted">
                                        Type <strong>REMOVE USER</strong> for access removal, or <strong>HARD DELETE USER</strong> for hard delete.
                                    </small>
                                </div>

                                <div class="d-flex flex-wrap justify-content-between">
                                    <a href="add.php" class="btn btn-secondary mb-2"><i class="fas fa-times"></i> Cancel</a>
                                    <button type="submit" class="btn btn-danger mb-2">
                                        <i class="fas fa-trash-alt"></i> Confirm Removal
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.main-content,
.content-area,
.container-fluid {
    max-width: 100%;
    overflow-x: hidden;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var actionMode = document.getElementById('action_mode');
    var confirmInput = document.getElementById('confirm_delete');
    if (!actionMode || !confirmInput) return;

    function updatePlaceholder() {
        confirmInput.value = '';
        confirmInput.placeholder = actionMode.value === 'hard_delete' ? 'HARD DELETE USER' : 'REMOVE USER';
    }

    actionMode.addEventListener('change', updatePlaceholder);
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
