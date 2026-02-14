<?php
/**
 * Admin - Student Requests (Approve / Reject)
 */
require_once '../../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Ensure student_requests table exists (safe to run each request)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        user_id INT NOT NULL,
        request_type VARCHAR(100) NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore
}

// Handle action (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve','reject'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: student_requests.php'); exit;
    }

    $action = $_POST['action'];
    $reqId = intval($_POST['request_id'] ?? 0);
    $response = trim($_POST['admin_response'] ?? '');

    if ($reqId <= 0) {
        $session->setFlash('error', 'Invalid request id');
        header('Location: student_requests.php'); exit;
    }

    // Load request
    $rq = $conn->prepare('SELECT sr.*, s.first_name, s.last_name, s.student_id as reg_no, u.email as user_email FROM student_requests sr LEFT JOIN students s ON sr.student_id = s.id LEFT JOIN users u ON sr.user_id = u.id WHERE sr.id = :id LIMIT 1');
    $rq->execute(['id' => $reqId]);
    $row = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $session->setFlash('error', 'Request not found');
        header('Location: student_requests.php'); exit;
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    if ($row['status'] === $newStatus) {
        $session->setFlash('info', 'Request already ' . $newStatus);
        header('Location: student_requests.php'); exit;
    }

    try {
        $u = $conn->prepare('UPDATE student_requests SET status = :status, admin_response = :resp, updated_at = NOW() WHERE id = :id');
        $u->execute(['status' => $newStatus, 'resp' => $response, 'id' => $reqId]);

        // Prepare student notification content (include admin response)
        $noteTitle = $action === 'approve' ? 'Request Approved' : 'Request Rejected';
        $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Student';
        $noteMsg = $noteTitle . ': ' . ucfirst(str_replace('_',' ', $row['request_type'])) . '. Admin response: ' . ($response ?: '-');
        $noteLink = BASE_URL . '/views/student/dashboard.php?request_id=' . $reqId;

        // First try to UPDATE the student's original submission notification (if present)
        try {
            $upd = $conn->prepare("UPDATE notifications SET title = :title, message = :msg, type = :type, link = :link, read_status = 'unread', created_at = NOW(), read_at = NULL WHERE user_id = :uid AND (link LIKE :link_like OR message LIKE :msg_like)");
            $upd->execute([
                'title' => $noteTitle,
                'msg' => $noteMsg,
                'type' => $action === 'approve' ? 'success' : 'warning',
                'link' => $noteLink,
                'uid' => $row['user_id'],
                'link_like' => '%request_id=' . $reqId . '%',
                'msg_like' => '%Request ID: ' . $reqId . '%'
            ]);

            if ($upd->rowCount() === 0) {
                // No existing student notification found for this request — insert a new one
                $nstmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                $nstmt->execute([
                    'uid' => $row['user_id'],
                    'title' => $noteTitle,
                    'msg' => $noteMsg,
                    'type' => $action === 'approve' ? 'success' : 'warning',
                    'link' => $noteLink
                ]);
            }
        } catch (Exception $e) {
            // fallback: insert notification
            try {
                $nstmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                $nstmt->execute([
                    'uid' => $row['user_id'],
                    'title' => $noteTitle,
                    'msg' => $noteMsg,
                    'type' => $action === 'approve' ? 'success' : 'warning',
                    'link' => $noteLink
                ]);
            } catch (Exception $ex) {
                // swallow
            }
        }

        $session->setFlash('success', 'Request ' . $newStatus . ' successfully');
    } catch (Exception $e) {
        error_log('Error updating student request: ' . $e->getMessage());
        $session->setFlash('error', 'Failed to update request');
    }

    header('Location: student_requests.php'); exit;
}

// Fetch requests
$stmt = $conn->prepare('SELECT sr.*, s.first_name, s.last_name, s.student_id as reg_no, u.email as user_email FROM student_requests sr LEFT JOIN students s ON sr.student_id = s.id LEFT JOIN users u ON sr.user_id = u.id ORDER BY sr.created_at DESC');
$stmt->execute();
$requests = $stmt->fetchAll();

// Enrich requests with semester label when semester_id is present
foreach ($requests as &$rq) {
    $rq['semester_label'] = '';
    if (!empty($rq['semester_id'])) {
        try {
            $ss = $conn->prepare('SELECT s.semester_name, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
            $ss->execute(['id' => $rq['semester_id']]);
            $sr = $ss->fetch();
            if ($sr) $rq['semester_label'] = ($sr['year_name'] ?? '') . ' - ' . ($sr['semester_name'] ?? '');
        } catch (Exception $e) {
            // ignore
        }
    }
}
unset($rq);

$pageTitle = 'Student Requests - Admin - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Student Requests</h4>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Pending / Recent Requests</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-sm" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Reg #</th>
                                <th>Type</th>
                                <th>Semester</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($requests)): ?>
                                <?php foreach ($requests as $r): ?>
                                    <tr>
                                        <td><?php echo e(Helper::formatDateTime($r['created_at'], 'M d, Y H:i')); ?></td>
                                        <td><?php echo e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); ?></td>
                                        <td><?php echo e($r['reg_no'] ?? '-'); ?></td>
                                        <td><?php echo e(ucwords(str_replace('_',' ', $r['request_type']))); ?></td>                                        <td><?php echo e($r['semester_label'] ?: '-'); ?></td>                                        <td><?php echo e(mb_substr($r['reason'],0,120)); ?><?php echo strlen($r['reason'])>120 ? '...' : ''; ?></td>
                                        <td><span class="badge badge-<?php echo $r['status']==='pending' ? 'warning' : ($r['status']==='approved' ? 'success' : 'secondary'); ?>"><?php echo e(ucfirst($r['status'])); ?></span></td>
                                        <td>
                                            <?php if ($r['status'] === 'pending'): ?>
                                            <form method="POST" action="" style="display:inline-block; margin-right:6px;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo e($r['id']); ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="text" name="admin_response" placeholder="Optional response" class="form-control form-control-sm mb-1" style="width:220px; display:inline-block;">
                                                <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                            </form>

                                            <form method="POST" action="" style="display:inline-block;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo e($r['id']); ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="text" name="admin_response" placeholder="Optional response" class="form-control form-control-sm mb-1" style="width:220px; display:inline-block;">
                                                <button class="btn btn-sm btn-danger" type="submit">Reject</button>
                                            </form>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled>No actions</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No requests found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>