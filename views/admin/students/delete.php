<?php
/**
 * Delete or Anonymize Student - Admin
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId <= 0) {
    $session->setFlash('error', 'Invalid student ID.');
    header('Location: list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT s.*, p.program_name, u.username, u.email AS user_email
        FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        $session->setFlash('error', 'Student not found.');
        header('Location: list.php');
        exit;
    }
} catch (Exception $e) {
    $session->setFlash('error', 'Database error: ' . $e->getMessage());
    header('Location: list.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $session->setFlash('error', 'Invalid request.');
        header('Location: delete.php?id=' . $studentId);
        exit;
    }

    $actionMode = ($_POST['action_mode'] ?? 'anonymize') === 'hard_delete' ? 'hard_delete' : 'anonymize';
    $confirmDelete = strtoupper(trim((string)($_POST['confirm_delete'] ?? '')));
    $expectedPhrase = $actionMode === 'hard_delete' ? 'HARD DELETE' : 'ANONYMIZE';

    if ($confirmDelete !== $expectedPhrase) {
        $session->setFlash('error', 'Confirmation phrase mismatch. Type "' . $expectedPhrase . '" to proceed.');
        header('Location: delete.php?id=' . $studentId);
        exit;
    }

    try {
        $conn->beginTransaction();

        if ($actionMode === 'anonymize') {
            $suffix = date('YmdHis') . '_' . (int)$studentId;
            $anonStudentId = 'ANON-' . $suffix;
            $anonStudentEmail = 'anon.student.' . $suffix . '@redacted.local';
            $anonUsername = 'anon_user_' . (int)$student['user_id'] . '_' . date('His');
            $anonUserEmail = 'anon.user.' . (int)$student['user_id'] . '.' . date('His') . '@redacted.local';

            try {
                $randomSecret = bin2hex(random_bytes(24));
            } catch (Exception $e) {
                $randomSecret = hash('sha256', uniqid((string)$studentId, true) . microtime(true));
            }
            $disabledHash = password_hash($randomSecret, PASSWORD_DEFAULT);

            if (!empty($student['photo']) && file_exists('../../../' . $student['photo'])) {
                @unlink('../../../' . $student['photo']);
            }

            $userStmt = $conn->prepare("
                UPDATE users
                SET username = :username,
                    email = :email,
                    password_hash = :password_hash,
                    status = 'inactive',
                    failed_login_attempts = 0,
                    account_locked_until = NULL,
                    password_reset_token = NULL,
                    password_reset_expires = NULL,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $userStmt->execute([
                'username' => $anonUsername,
                'email' => $anonUserEmail,
                'password_hash' => $disabledHash,
                'id' => (int)$student['user_id']
            ]);

            $studentStmt = $conn->prepare("
                UPDATE students
                SET student_id = :student_id,
                    first_name = 'ANONYMIZED',
                    middle_name = NULL,
                    last_name = 'STUDENT',
                    date_of_birth = '1970-01-01',
                    phone = 'REDACTED',
                    email = :email,
                    address = NULL,
                    city = NULL,
                    country = NULL,
                    emergency_contact_name = NULL,
                    emergency_contact_phone = NULL,
                    emergency_contact_relationship = NULL,
                    photo = NULL,
                    status = 'withdrawn',
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $studentStmt->execute([
                'student_id' => $anonStudentId,
                'email' => $anonStudentEmail,
                'id' => $studentId
            ]);
        } else {
            if ((string)($student['status'] ?? '') === 'active') {
                throw new Exception('Hard delete is blocked for active students. Use anonymization or change status first.');
            }

            $deleteStudentStmt = $conn->prepare("DELETE FROM students WHERE id = :id");
            $deleteStudentStmt->execute(['id' => $studentId]);

            $deleteUserStmt = $conn->prepare("DELETE FROM users WHERE id = :user_id");
            $deleteUserStmt->execute(['user_id' => (int)$student['user_id']]);

            if (!empty($student['photo']) && file_exists('../../../' . $student['photo'])) {
                @unlink('../../../' . $student['photo']);
            }
        }

        $conn->commit();

        $logger = new Logger('admin_actions');
        $logger->info($actionMode === 'anonymize' ? 'Student data anonymized' : 'Student deleted', [
            'admin_id' => $currentUser['id'] ?? null,
            'admin_name' => trim((string)($currentUser['profile']['first_name'] ?? '') . ' ' . (string)($currentUser['profile']['last_name'] ?? '')),
            'student_record_id' => $studentId,
            'student_identifier_before' => $student['student_id'] ?? '',
            'student_name_before' => trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? '')),
            'action_mode' => $actionMode,
            'executed_at' => date('Y-m-d H:i:s')
        ]);

        if ($actionMode === 'anonymize') {
            $session->setFlash('success', 'Student profile anonymized successfully. Academic history remains for compliance and transcript integrity.');
        } else {
            $session->setFlash('success', 'Student hard deleted successfully.');
        }

        header('Location: list.php');
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $session->setFlash('error', 'Failed to process request: ' . $e->getMessage());
        header('Location: delete.php?id=' . $studentId);
        exit;
    }
}

$pageTitle = 'Delete or Anonymize Student - ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var mode = localStorage.getItem('smns_theme_mode');
                if (mode === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
            } catch (e) {}
        })();
    </script>
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="../../../assets/css/theme-shared.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
    <link rel="stylesheet" href="../../../assets/css/responsive-nav.css">
</head>
<body>
<style>
.delete-card {
    border: 2px solid #dc3545;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
}

.warning-icon {
    font-size: 4rem;
    color: #dc3545;
}

.student-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin: 20px 0;
}

.action-option {
    text-align: left;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 10px;
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Delete or Anonymize Student</h4>
        </div>
        <div class="topbar-right">
            <a href="view.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-info mr-2">
                <i class="fas fa-eye"></i> View Details
            </a>
            <a href="list.php" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="row justify-content-center">
            <div class="col-md-9">
                <div class="card delete-card">
                    <div class="card-body text-center">
                        <div class="warning-icon mb-4">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>

                        <h4 class="card-title text-danger mb-4">High-Risk Record Action</h4>

                        <p class="card-text mb-4">
                            Use anonymization for compliance whenever possible. Hard delete should be used only after formal approval.
                        </p>

                        <div class="student-info">
                            <h5><?php echo e(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?></h5>
                            <p class="mb-1"><strong>Student ID:</strong> <?php echo e($student['student_id'] ?? '-'); ?></p>
                            <p class="mb-1"><strong>Program:</strong> <?php echo e($student['program_name'] ?? '-'); ?></p>
                            <p class="mb-1"><strong>Student Email:</strong> <?php echo e($student['email'] ?? '-'); ?></p>
                            <p class="mb-1"><strong>User Email:</strong> <?php echo e($student['user_email'] ?? '-'); ?></p>
                            <p class="mb-0"><strong>Username:</strong> <?php echo e($student['username'] ?? '-'); ?></p>
                        </div>

                        <div class="alert alert-warning text-left">
                            <strong>Compliance guidance:</strong>
                            <ul class="mb-0 mt-2">
                                <li><strong>Anonymize</strong> removes direct personal identifiers and disables account access while preserving academic history.</li>
                                <li><strong>Hard delete</strong> permanently removes the student/user record and is blocked for active students.</li>
                            </ul>
                        </div>

                        <form method="POST" class="mt-4">
                            <?php echo csrfField(); ?>

                            <div class="action-option">
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="modeAnonymize" name="action_mode" value="anonymize" class="custom-control-input" checked>
                                    <label class="custom-control-label font-weight-bold" for="modeAnonymize">Anonymize Student (Recommended)</label>
                                </div>
                                <small class="text-muted">Replaces personal identity fields, disables login, and retains historical academic records.</small>
                            </div>

                            <div class="action-option">
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="modeHardDelete" name="action_mode" value="hard_delete" class="custom-control-input">
                                    <label class="custom-control-label font-weight-bold text-danger" for="modeHardDelete">Hard Delete Student</label>
                                </div>
                                <small class="text-muted">Permanent removal. Allowed only for non-active students after formal approval.</small>
                            </div>

                            <div class="form-group mt-3">
                                <label for="confirmDelete" class="font-weight-bold">
                                    Type "<span id="confirmPhrase">ANONYMIZE</span>" to confirm:
                                </label>
                                <input
                                    type="text"
                                    id="confirmDelete"
                                    name="confirm_delete"
                                    class="form-control text-center"
                                    required
                                    autocomplete="off"
                                    style="text-transform: uppercase;"
                                >
                            </div>

                            <div class="btn-group">
                                <button type="submit" id="submitActionBtn" class="btn btn-warning btn-lg">
                                    <i class="fas fa-user-shield"></i> Anonymize Student
                                </button>
                                <a href="view.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-secondary btn-lg ml-2">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('confirmDelete');
    var phraseSpan = document.getElementById('confirmPhrase');
    var submitBtn = document.getElementById('submitActionBtn');
    var radios = document.querySelectorAll('input[name="action_mode"]');

    function syncActionMode() {
        var mode = document.querySelector('input[name="action_mode"]:checked').value;
        var phrase = mode === 'hard_delete' ? 'HARD DELETE' : 'ANONYMIZE';
        phraseSpan.textContent = phrase;
        input.placeholder = phrase;
        if (mode === 'hard_delete') {
            submitBtn.className = 'btn btn-danger btn-lg';
            submitBtn.innerHTML = '<i class="fas fa-trash"></i> Hard Delete Student';
        } else {
            submitBtn.className = 'btn btn-warning btn-lg';
            submitBtn.innerHTML = '<i class="fas fa-user-shield"></i> Anonymize Student';
        }
        input.value = '';
    }

    input.addEventListener('input', function () {
        this.value = this.value.toUpperCase();
    });
    radios.forEach(function (radio) {
        radio.addEventListener('change', syncActionMode);
    });

    syncActionMode();
})();
</script>

<?php include '../../../includes/footer.php'; ?>
</body>
</html>
