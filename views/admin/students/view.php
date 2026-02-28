<?php
/**
 * View Student Details - Admin
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get student ID from URL
$studentId = $_GET['id'] ?? null;
if (!$studentId) {
    header('Location: list.php?error=invalid_id');
    exit;
}

// Get student details
$db = new Database();
$conn = $db->getConnection();

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS transcript_download_rights (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL UNIQUE,
        status ENUM('granted','revoked') NOT NULL DEFAULT 'revoked',
        verified_by_user_id INT NULL,
        verified_at DATETIME NULL,
        revoked_by_user_id INT NULL,
        revoked_at DATETIME NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
}

try {
    $stmt = $conn->prepare("
        SELECT s.*, p.program_code, p.program_name, u.username, u.status as user_status, u.created_at as user_created_at, u.last_login, u.failed_login_attempts
        FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.id = :id
    ");
    $stmt->execute(['id' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        header('Location: list.php?error=student_not_found');
        exit;
    }

    // Count successful logins from activity logs.
    $student['total_logins'] = 0;
    try {
        $loginCountStmt = $conn->prepare("
            SELECT COUNT(*) AS total_logins
            FROM activity_logs
            WHERE user_id = :user_id AND action = 'login'
        ");
        $loginCountStmt->execute(['user_id' => $student['user_id']]);
        $loginCount = $loginCountStmt->fetch(PDO::FETCH_ASSOC);
        $student['total_logins'] = (int)($loginCount['total_logins'] ?? 0);
    } catch (Exception $e) {
        $student['total_logins'] = 0;
    }
} catch (Exception $e) {
    header('Location: list.php?error=database_error');
    exit;
}

// Admin-controlled transcript download rights.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transcript_rights_action'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: view.php?id=' . urlencode((string)$studentId));
        exit;
    }

    $rightsAction = (string)($_POST['transcript_rights_action'] ?? '');
    $rightsNotes = trim((string)($_POST['transcript_rights_notes'] ?? ''));

    try {
        if ($rightsAction === 'grant') {
            $stmt = $conn->prepare("
                INSERT INTO transcript_download_rights (student_id, status, verified_by_user_id, verified_at, notes)
                VALUES (:student_id, 'granted', :admin_id, NOW(), :notes)
                ON DUPLICATE KEY UPDATE
                    status = 'granted',
                    verified_by_user_id = VALUES(verified_by_user_id),
                    verified_at = VALUES(verified_at),
                    notes = VALUES(notes),
                    revoked_by_user_id = NULL,
                    revoked_at = NULL
            ");
            $stmt->execute([
                'student_id' => (int)$student['id'],
                'admin_id' => (int)($currentUser['id'] ?? 0),
                'notes' => $rightsNotes
            ]);
            $session->setFlash('success', 'Transcript download rights granted.');
        } elseif ($rightsAction === 'revoke') {
            $stmt = $conn->prepare("
                INSERT INTO transcript_download_rights (student_id, status, revoked_by_user_id, revoked_at, notes)
                VALUES (:student_id, 'revoked', :admin_id, NOW(), :notes)
                ON DUPLICATE KEY UPDATE
                    status = 'revoked',
                    revoked_by_user_id = VALUES(revoked_by_user_id),
                    revoked_at = VALUES(revoked_at),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                'student_id' => (int)$student['id'],
                'admin_id' => (int)($currentUser['id'] ?? 0),
                'notes' => $rightsNotes
            ]);
            $session->setFlash('success', 'Transcript download rights revoked.');
        } else {
            $session->setFlash('error', 'Invalid transcript rights action.');
        }
    } catch (Exception $e) {
        $session->setFlash('error', 'Failed to update transcript rights.');
    }

    header('Location: view.php?id=' . urlencode((string)$studentId));
    exit;
}

$transcriptRights = null;
try {
    $rightsStmt = $conn->prepare("SELECT * FROM transcript_download_rights WHERE student_id = :student_id LIMIT 1");
    $rightsStmt->execute(['student_id' => (int)$student['id']]);
    $transcriptRights = $rightsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $transcriptRights = null;
}
$transcriptRightsGranted = (bool)($transcriptRights && ($transcriptRights['status'] ?? '') === 'granted');

// Check if student is registered for the current semester
$currentSemester = Helper::getCurrentSemester();
$registered = false;
if ($currentSemester) {
    $regStmt = $conn->prepare("SELECT * FROM semester_registrations WHERE student_id = :sid AND semester_id = :semid AND status = 'approved'");
    $regStmt->execute(['sid' => $student['id'], 'semid' => $currentSemester['id']]);
    $registered = $regStmt->fetch() ? true : false;
}

$pageTitle = 'View Student - ' . APP_NAME;
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
/* Custom styles for student view */
.student-photo {
    max-width: 150px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.info-card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.info-label {
    font-weight: 600;
    color: #495057;
    min-width: 140px;
}

.status-badge {
    font-size: 0.875rem;
    padding: 0.375rem 0.75rem;
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Student Details</h4>
        </div>
        <div class="topbar-right">
            <a href="list.php" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="graduation-awards.php?id=<?php echo $student['id']; ?>" class="btn btn-primary mr-2">
                <i class="fas fa-certificate"></i> Graduation & Awards
            </a>
            <a href="audit.php?id=<?php echo $student['id']; ?>" class="btn btn-dark mr-2">
                <i class="fas fa-history"></i> Profile Audit
            </a>
            <a href="edit.php?id=<?php echo $student['id']; ?>" class="btn btn-warning mr-2">
                <i class="fas fa-edit"></i> Edit Student
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $session->getFlash('success'); ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $session->getFlash('error'); ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Student Photo and Basic Info -->
            <div class="col-md-4">
                <div class="card info-card">
                    <div class="card-body text-center">
                        <?php if (!empty($student['photo'])): ?>
                            <img src="<?php echo BASE_URL . '/' . $student['photo']; ?>" alt="Student Photo" class="student-photo mb-3">
                        <?php else: ?>
                            <div class="student-photo-placeholder rounded mb-3 d-inline-flex align-items-center justify-content-center bg-primary text-white" style="width: 150px; height: 150px; font-size: 3rem; font-weight: bold;">
                                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>

                        <h5 class="mb-1"><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?></h5>
                        <p class="text-muted mb-2"><?php echo e($student['student_id']); ?></p>
                        <span class="badge status-badge badge-<?php echo Helper::getStatusColor($student['status']); ?>">
                            <?php echo e(ucfirst($student['status'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Personal Information -->
            <div class="col-md-8">
                <div class="card info-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user"></i> Personal Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Full Name:</span>
                                    <?php echo e((!empty($student['title']) ? $student['title'] . ' ' : '') . $student['first_name'] . ' ' . (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Gender:</span>
                                    <?php echo e(ucfirst($student['gender'])); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Date of Birth:</span>
                                    <?php echo e(!empty($student['date_of_birth']) ? Helper::formatDate($student['date_of_birth'], 'M d, Y') : 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">National ID:</span>
                                    <?php echo e($student['national_id'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Phone:</span>
                                    <?php echo e($student['phone']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Email:</span>
                                    <?php echo e($student['email']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Address:</span>
                                    <?php echo e($student['address'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Parish:</span>
                                    <?php echo e($student['parish'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Emergency Contact:</span>
                                    <?php echo e($student['emergency_contact'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="card info-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Academic Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Student ID:</span>
                                    <strong><?php echo e($student['student_id']); ?></strong>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Admission Number:</span>
                                    <?php echo e($student['admission_number'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Program:</span>
                                    <strong><?php echo e($student['program_code']); ?> - <?php echo e($student['program_name']); ?></strong>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Level:</span>
                                    Year <?php echo e($student['level_year']); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Enrollment Date:</span>
                                    <?php echo e(!empty($student['enrollment_date']) ? Helper::formatDate($student['enrollment_date'], 'M d, Y') : 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Graduation Date:</span>
                                    <?php echo e(!empty($student['graduation_date']) ? Helper::formatDate($student['graduation_date'], 'M d, Y') : 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Specialization:</span>
                                    <?php echo e($student['specialization'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Qualifications:</span>
                                    <?php echo e($student['qualifications'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Award:</span>
                                    <?php echo e($student['graduation_award_title'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Classification:</span>
                                    <?php echo e($student['graduation_classification'] ?? 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Account Status:</span>
                                    <span class="badge badge-<?php echo $student['user_status'] === 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo e(ucfirst($student['user_status'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information -->
                <div class="card info-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Account Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Username:</span>
                                    <?php echo e($student['username']); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Account Created:</span>
                                    <?php echo e(Helper::formatDateTime($student['user_created_at'], 'M d, Y g:i A')); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <span class="info-label">Last Login:</span>
                                    <?php echo e($student['last_login'] ? Helper::formatDateTime($student['last_login'], 'M d, Y g:i A') : 'Never'); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Failed Login Attempts:</span>
                                    <?php echo e($student['failed_login_attempts'] ?? 0); ?>
                                </div>
                                <div class="mb-3">
                                    <span class="info-label">Total Logins:</span>
                                    <?php echo e($student['total_logins'] ?? 0); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card info-card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-file-signature"></i> Transcript Download Rights</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <strong>Current Status:</strong>
                            <?php if ($transcriptRightsGranted): ?>
                                <span class="badge badge-success">GRANTED</span>
                            <?php else: ?>
                                <span class="badge badge-danger">LOCKED</span>
                            <?php endif; ?>
                        </p>
                        <p class="text-muted mb-3">Student transcript export/official PDF is blocked until rights are granted here by admin.</p>

                        <?php if (!empty($transcriptRights['verified_at'])): ?>
                            <div class="small text-muted mb-1">Last Verified: <?php echo e(Helper::formatDateTime($transcriptRights['verified_at'], 'M d, Y g:i A')); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($transcriptRights['revoked_at'])): ?>
                            <div class="small text-muted mb-2">Last Revoked: <?php echo e(Helper::formatDateTime($transcriptRights['revoked_at'], 'M d, Y g:i A')); ?></div>
                        <?php endif; ?>

                        <form method="POST" class="form-inline">
                            <?php echo csrfField(); ?>
                            <input type="text" name="transcript_rights_notes" class="form-control form-control-sm mr-2 mb-2" style="min-width:280px;" placeholder="Optional note (reason/reference)">
                            <?php if ($transcriptRightsGranted): ?>
                                <button type="submit" name="transcript_rights_action" value="revoke" class="btn btn-sm btn-danger mb-2">Revoke Rights</button>
                            <?php else: ?>
                                <button type="submit" name="transcript_rights_action" value="grant" class="btn btn-sm btn-success mb-2">Grant Rights</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="edit.php?id=<?php echo $student['id']; ?>" class="btn btn-warning mr-2">
                    <i class="fas fa-edit"></i> Edit Student
                </a>
                <a href="reset_password.php?id=<?php echo $student['id']; ?>" class="btn btn-info mr-2">
                    <i class="fas fa-key"></i> Reset Password
                </a>
                <a href="send_invite.php?id=<?php echo $student['id']; ?>" class="btn btn-success mr-2">
                    <i class="fas fa-envelope"></i> Send Invite
                </a>
                <a href="audit.php?id=<?php echo $student['id']; ?>" class="btn btn-dark mr-2">
                    <i class="fas fa-history"></i> Profile Audit
                </a>
                <a href="graduation-awards.php?id=<?php echo $student['id']; ?>" class="btn btn-primary mr-2">
                    <i class="fas fa-certificate"></i> Graduation & Awards
                </a>
                <button type="button" class="btn btn-danger" onclick="deleteStudent(<?php echo $student['id']; ?>)">
                    <i class="fas fa-trash"></i> Delete Student
                </button>
            </div>
        </div>

        <!-- Re-registration Prompt -->
        <?php if (!$registered): ?>
            <div class="alert alert-warning text-center">
                This student is not registered for the current semester.<br>
                Enrollment must be initiated by the student from the student portal using <strong>ENROLL NOW</strong>.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function deleteStudent(studentId) {
    if (confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + studentId;
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>
</body>
</html>
