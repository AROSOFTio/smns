<?php
/**
 * Lecturer Approvals - Admin
 * Approve or reject pending lecturer applications
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

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $lecturerId = (int)($_POST['lecturer_id'] ?? 0);
    $action = $_POST['action'];
    $adminNotes = Security::sanitize($_POST['admin_notes'] ?? '');

    if (!$lecturerId) {
        $session->setFlash('error', 'Invalid lecturer ID');
        header('Location: approvals.php');
        exit;
    }

    // Get lecturer details
    $lecturerStmt = $conn->prepare("
        SELECT l.*, u.username, u.email
        FROM lecturers l
        INNER JOIN users u ON l.user_id = u.id
        WHERE l.id = :id AND l.status = 'pending'
    ");
    $lecturerStmt->execute(['id' => $lecturerId]);
    $lecturer = $lecturerStmt->fetch();

    if (!$lecturer) {
        $session->setFlash('error', 'Lecturer not found or already processed');
        header('Location: approvals.php');
        exit;
    }

    $transactionStarted = false;
    try {
        $conn->beginTransaction();
        $transactionStarted = true;

        if ($action === 'approve') {
            // Generate temporary password for approved lecturer
            $tempPassword = Security::generatePassword(10);
            $passwordHash = Security::hashPassword($tempPassword);

            // Update user account to active and set new password
            $userStmt = $conn->prepare("
                UPDATE users SET
                    password_hash = :password_hash,
                    status = 'active',
                    updated_at = NOW()
                WHERE id = :user_id
            ");
            $userStmt->execute([
                'password_hash' => $passwordHash,
                'user_id' => $lecturer['user_id']
            ]);

            // Update lecturer status to active
            $lecturerStmt = $conn->prepare("
                UPDATE lecturers SET
                    status = 'active',
                    approved_at = NOW(),
                    approved_by = :approved_by,
                    admin_notes = :admin_notes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $lecturerStmt->execute([
                'approved_by' => $_SESSION['admin_id'] ?? 1,
                'admin_notes' => $adminNotes,
                'id' => $lecturerId
            ]);

            // Send approval email with credentials
            $fullName = trim($lecturer['title'] . ' ' . $lecturer['first_name'] . ' ' . $lecturer['last_name']);
            $mailSent = false;
            try {
                $mailSent = Helper::sendTemplatedEmail('approval_status', $lecturer['email'], [
                    'recipient_name' => $fullName,
                    'request_label' => 'Lecturer Application',
                    'status' => 'approved',
                    'admin_response' => $adminNotes,
                    'username' => $lecturer['username'],
                    'temporary_password' => $tempPassword,
                    'login_url' => BASE_URL . '/views/lecturer/login.php'
                ]);
            } catch (Exception $e) {
                error_log("Failed to send lecturer approval email: " . $e->getMessage());
            }

            $conn->commit();
            $session->setFlash('success', 'Lecturer approved successfully!' . ($mailSent ? ' Credentials emailed.' : ' Please communicate credentials manually.'));

        } elseif ($action === 'reject') {
            // Update lecturer status to rejected
            $lecturerStmt = $conn->prepare("
                UPDATE lecturers SET
                    status = 'rejected',
                    rejected_at = NOW(),
                    rejected_by = :rejected_by,
                    admin_notes = :admin_notes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $lecturerStmt->execute([
                'rejected_by' => $_SESSION['admin_id'] ?? 1,
                'admin_notes' => $adminNotes,
                'id' => $lecturerId
            ]);

            // Optionally deactivate user account
            $userStmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = :user_id");
            $userStmt->execute(['user_id' => $lecturer['user_id']]);

            // Send rejection email
            $fullName = trim($lecturer['title'] . ' ' . $lecturer['first_name'] . ' ' . $lecturer['last_name']);
            try {
                Helper::sendTemplatedEmail('approval_status', $lecturer['email'], [
                    'recipient_name' => $fullName,
                    'request_label' => 'Lecturer Application',
                    'status' => 'rejected',
                    'admin_response' => $adminNotes
                ]);
            } catch (Exception $e) {
                error_log("Failed to send lecturer rejection email: " . $e->getMessage());
            }

            $conn->commit();
            $session->setFlash('success', 'Lecturer application rejected.');
        }

    } catch (Exception $e) {
        if ($transactionStarted && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $session->setFlash('error', 'Error processing lecturer application: ' . $e->getMessage());
    }

    header('Location: approvals.php');
    exit;
}

// Get pending lecturers
$sql = "SELECT l.*, u.username, u.email, u.created_at as user_created_at
        FROM lecturers l
        INNER JOIN users u ON l.user_id = u.id
        WHERE l.status = 'pending'
        ORDER BY l.created_at DESC";

$stmt = $conn->query($sql);
$pendingLecturers = $stmt->fetchAll();

$pageTitle = 'Lecturer Approvals - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
/* AGGRESSIVE HORIZONTAL SCROLL PREVENTION */
* {
    box-sizing: border-box !important;
}

html {
    overflow-x: hidden !important;
    width: 100% !important;
}

body {
    overflow-x: hidden !important;
    width: 100% !important;
    margin: 0 !important;
}

.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.content-area {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.container, .container-fluid {
    overflow-x: hidden !important;
    max-width: 100% !important;
}

/* Approvals specific styles */
.approvals-container {
    max-width: 1200px;
    margin: 0 auto;
}

.approvals-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    text-align: center;
}

.approvals-header h2 {
    margin: 0 0 10px 0;
    font-size: 28px;
    font-weight: 700;
}

.approvals-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 16px;
}

.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    text-align: center;
    border-left: 4px solid #667eea;
}

.stat-number {
    font-size: 32px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.stat-label {
    color: #666;
    font-size: 14px;
    font-weight: 500;
}

.applications-list {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.application-card {
    border-bottom: 1px solid #eee;
    padding: 25px;
    transition: background 0.3s;
}

.application-card:last-child {
    border-bottom: none;
}

.application-card:hover {
    background: #f8f9fa;
}

.application-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.applicant-name {
    font-size: 20px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.applicant-id {
    color: #666;
    font-size: 14px;
    margin: 5px 0 0 0;
}

.application-status {
    background: #ffc107;
    color: #000;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.application-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 12px;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}

.detail-value {
    font-size: 14px;
    color: #333;
    font-weight: 500;
}

.application-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    align-items: center;
}

.btn-approve {
    background: #28a745;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
}

.btn-approve:hover {
    background: #218838;
}

.btn-reject {
    background: #dc3545;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
}

.btn-reject:hover {
    background: #c82333;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
}

.modal-content {
    background: white;
    margin: 10% auto;
    padding: 30px;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.modal-header {
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
    margin-bottom: 20px;
}

.modal-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: #333;
}

.close {
    float: right;
    font-size: 28px;
    font-weight: bold;
    color: #aaa;
    cursor: pointer;
}

.close:hover {
    color: #000;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    resize: vertical;
    min-height: 80px;
}

.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
}

.modal-actions {
    text-align: right;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.btn-cancel {
    background: #6c757d;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    margin-right: 10px;
}

.btn-confirm {
    background: #007bff;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.btn-confirm.approve {
    background: #28a745;
}

.btn-confirm.reject {
    background: #dc3545;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 20px;
    color: #dee2e6;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: #333;
}

@media (max-width: 768px) {
    .application-details {
        grid-template-columns: 1fr;
    }

    .application-actions {
        flex-direction: column;
    }

    .btn-approve, .btn-reject {
        width: 100%;
    }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Lecturer Approvals</h4>
        </div>
        <div class="topbar-right">
            <a href="lecturers/list.php" class="btn btn-secondary">← Back to Lecturers</a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="approvals-container">
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

            <!-- Header -->
            <div class="approvals-header">
                <h2><i class="fas fa-user-check"></i> Lecturer Application Approvals</h2>
                <p>Review and approve pending lecturer applications</p>
            </div>

            <!-- Stats -->
            <div class="stats-bar">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($pendingLecturers); ?></div>
                    <div class="stat-label">Pending Approvals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as count FROM lecturers WHERE status = 'active'");
                        echo $stmt->fetch()['count'];
                        ?>
                    </div>
                    <div class="stat-label">Active Lecturers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as count FROM lecturers WHERE status = 'rejected'");
                        echo $stmt->fetch()['count'];
                        ?>
                    </div>
                    <div class="stat-label">Rejected Applications</div>
                </div>
            </div>

            <!-- Applications List -->
            <div class="applications-list">
                <?php if (!empty($pendingLecturers)): ?>
                    <?php foreach ($pendingLecturers as $lecturer): ?>
                        <div class="application-card">
                            <div class="application-header">
                                <div>
                                    <h3 class="applicant-name">
                                        <?php echo e($lecturer['title'] . ' ' . $lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>
                                    </h3>
                                    <p class="applicant-id">ID: <?php echo e($lecturer['lecturer_id']); ?></p>
                                </div>
                                <span class="application-status">Pending Review</span>
                            </div>

                            <div class="application-details">
                                <div class="detail-item">
                                    <span class="detail-label">Email</span>
                                    <span class="detail-value"><?php echo e($lecturer['email']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Department</span>
                                    <span class="detail-value"><?php echo e($lecturer['department']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Designation</span>
                                    <span class="detail-value"><?php echo e($lecturer['designation']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Applied Date</span>
                                    <span class="detail-value"><?php echo Helper::formatDate($lecturer['created_at']); ?></span>
                                </div>
                                <?php if ($lecturer['specialization']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Specialization</span>
                                    <span class="detail-value"><?php echo e($lecturer['specialization']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($lecturer['qualifications']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Qualifications</span>
                                    <span class="detail-value"><?php echo e(substr($lecturer['qualifications'], 0, 50)) . (strlen($lecturer['qualifications']) > 50 ? '...' : ''); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="application-actions">
                                <button class="btn-approve" onclick="openModal('approve', <?php echo $lecturer['id']; ?>, '<?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-reject" onclick="openModal('reject', <?php echo $lecturer['id']; ?>, '<?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Pending Applications</h3>
                        <p>All lecturer applications have been processed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div id="approvalModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle" class="modal-title">Approve Application</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>

        <form id="approvalForm" method="POST">
            <input type="hidden" name="lecturer_id" id="modalLecturerId">
            <input type="hidden" name="action" id="modalAction">

            <div class="form-group">
                <label for="admin_notes">Admin Notes (Optional)</label>
                <textarea name="admin_notes" id="admin_notes" placeholder="Add any notes about this decision..."></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" id="confirmBtn" class="btn-confirm">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(action, lecturerId, lecturerName) {
    const modal = document.getElementById('approvalModal');
    const title = document.getElementById('modalTitle');
    const confirmBtn = document.getElementById('confirmBtn');
    const form = document.getElementById('approvalForm');

    document.getElementById('modalLecturerId').value = lecturerId;
    document.getElementById('modalAction').value = action;

    if (action === 'approve') {
        title.textContent = 'Approve Application - ' + lecturerName;
        confirmBtn.textContent = 'Approve & Send Credentials';
        confirmBtn.className = 'btn-confirm approve';
    } else {
        title.textContent = 'Reject Application - ' + lecturerName;
        confirmBtn.textContent = 'Reject Application';
        confirmBtn.className = 'btn-confirm reject';
    }

    modal.style.display = 'block';
}

function closeModal() {
    document.getElementById('approvalModal').style.display = 'none';
    document.getElementById('admin_notes').value = '';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('approvalModal');
    if (event.target == modal) {
        closeModal();
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>
