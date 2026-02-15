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
        semester_id INT NULL,
        year_of_study INT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_request_type (request_type),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore
}

// Bulk approve semester registration requests (optional semester filter)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_all_semester_requests') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: student_requests.php'); exit;
    }

    $semesterFilter = isset($_POST['semester_id']) && (int)$_POST['semester_id'] > 0 ? (int)$_POST['semester_id'] : null;

    $sql = "SELECT * FROM student_requests WHERE request_type = 'semester_registration' AND status = 'pending'";
    $params = [];
    if ($semesterFilter) { $sql .= " AND semester_id = :semid"; $params['semid'] = $semesterFilter; }
    $rowsStmt = $conn->prepare($sql);
    $rowsStmt->execute($params);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $session->setFlash('info', 'No pending semester registration requests found.');
        header('Location: student_requests.php'); exit;
    }

    $updateReq = $conn->prepare("UPDATE student_requests SET status = 'approved', admin_response = :resp, updated_at = NOW() WHERE id = :id");
    $selectSR = $conn->prepare('SELECT id, status FROM semester_registrations WHERE student_id = :sid AND semester_id = :semid LIMIT 1');
    $updateSR = $conn->prepare('UPDATE semester_registrations SET status = "approved", approved_by = :admin, approval_date = NOW(), updated_at = NOW() WHERE id = :id');
    $insertSR = $conn->prepare('INSERT INTO semester_registrations (student_id, semester_id, status, request_date, approved_by, approval_date, created_at) VALUES (:sid, :semid, "approved", NOW(), :admin, NOW(), NOW())');
    $notifIns = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");

    $approvedCount = 0;
    $conn->beginTransaction();
    try {
        foreach ($rows as $r) {
            // update request
            $updateReq->execute(['resp' => 'Approved by admin (bulk)', 'id' => $r['id']]);

            $semId = $r['semester_id'] ?? null;
            $stuId = $r['student_id'] ?? null;
            $uid = $r['user_id'] ?? null;

            if ($semId && $stuId) {
                // ensure semester_registrations exists; insert/update accordingly
                $selectSR->execute(['sid' => $stuId, 'semid' => $semId]);
                $sr = $selectSR->fetch(PDO::FETCH_ASSOC);
                if ($sr) {
                    if ($sr['status'] !== 'approved') {
                        $updateSR->execute(['admin' => $_SESSION['admin_id'] ?? 1, 'id' => $sr['id']]);
                    }
                } else {
                    $insertSR->execute(['sid' => $stuId, 'semid' => $semId, 'admin' => $_SESSION['admin_id'] ?? 1]);
                }
                        // Auto-assign courses and mark student as reported when approved
                        try {
                            // fetch student's program and level
                            $sdet = $conn->prepare('SELECT program_id, level_year FROM students WHERE id = :id LIMIT 1');
                            $sdet->execute(['id' => $stuId]);
                            $sinfo = $sdet->fetch(PDO::FETCH_ASSOC);
                            $programId = $sinfo['program_id'] ?? null;
                            $levelYear = $sinfo['level_year'] ?? null;

                            // fetch semester number and academic year
                            $semInfoStmt = $conn->prepare('SELECT semester_number, academic_year_id FROM semesters WHERE id = :id LIMIT 1');
                            $semInfoStmt->execute(['id' => $semId]);
                            $semInfo = $semInfoStmt->fetch(PDO::FETCH_ASSOC);
                            $semNumber = $semInfo['semester_number'] ?? null;
                            $academicYearId = $semInfo['academic_year_id'] ?? null;

                            $adminId = $_SESSION['admin_id'] ?? null;

                            // First insert from course_assignments if available
                            $insAssign = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at, updated_at)
                                SELECT :student_id, c.id, :semester_id, CURDATE(), 'approved', :approved_by, NOW(), NOW(), NOW()
                                FROM course_assignments ca JOIN courses c ON ca.course_id = c.id
                                WHERE ca.semester_id = :semester_id AND ca.status = 'active' AND c.status = 'active'
                                AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id)");
                            $insAssign->execute(['student_id' => $stuId, 'semester_id' => $semId, 'approved_by' => $adminId]);

                            // Fallback to courses table (narrow by program/level if available)
                            $insFallback = "INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at, updated_at)
                                SELECT :student_id, c.id, :semester_id, CURDATE(), 'approved', :approved_by, NOW(), NOW(), NOW()
                                FROM courses c
                                WHERE c.status = 'active' AND (c.semester_offered = :sem_num OR c.semester_offered = 3)
                                AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id)";

                            if ($programId && $levelYear) {
                                $insFallback = str_replace("WHERE c.status = 'active'", "WHERE c.program_id = :program_id AND c.level_year = :level_year AND c.status = 'active'", $insFallback);
                            }

                            $params = ['student_id' => $stuId, 'semester_id' => $semId, 'approved_by' => $adminId, 'sem_num' => $semNumber];
                            if ($programId && $levelYear) { $params['program_id'] = $programId; $params['level_year'] = $levelYear; }
                            $insStmt = $conn->prepare($insFallback);
                            $insStmt->execute($params);

                            // Mark student as reported (ensure columns exist)
                            $colCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'last_reported_semester_id'")->fetch();
                            if (!$colCheck) {
                                $conn->exec("ALTER TABLE students ADD COLUMN last_reported_semester_id INT NULL, ADD COLUMN last_reported_academic_year_id INT NULL, ADD COLUMN last_reported_at DATETIME NULL");
                            }
                            $upd = $conn->prepare("UPDATE students SET last_reported_semester_id = :sem, last_reported_academic_year_id = :ay, last_reported_at = NOW() WHERE id = :id");
                            $upd->execute(['sem' => $semId, 'ay' => $academicYearId, 'id' => $stuId]);
                        } catch (Exception $e) {
                            try { if (class_exists('Logger')) (new Logger())->log($_SESSION['admin_id'] ?? null, 'auto_reg_error', 'student_requests', 'Auto-registration failed: ' . $e->getMessage()); } catch(Exception $le) { error_log('Auto reg error: ' . $le->getMessage()); }
                        }
            }

            // notify student (if user id available)
            if ($uid) {
                $notifIns->execute([
                    'uid' => $uid,
                    'title' => 'Semester Registration Approved',
                    'msg' => 'Your semester registration request has been approved by administration.',
                    'type' => 'success',
                    'link' => BASE_URL . '/views/student/course-registration.php?semester_id=' . ($semId ?? '')
                ]);
            }

            $approvedCount++;
        }
        $conn->commit();
        $session->setFlash('success', $approvedCount . ' semester registration request(s) approved.');
    } catch (Exception $e) {
        $conn->rollBack();
        error_log('Bulk approve error: ' . $e->getMessage());
        $session->setFlash('error', 'Failed to approve requests.');
    }

    header('Location: student_requests.php'); exit;
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

        // If this request was for semester registration and the admin approved it,
        // create or update a corresponding record in `semester_registrations` so
        // the student can immediately select courses.
        if (!empty($row['request_type']) && $row['request_type'] === 'semester_registration') {
            try {
                // ensure table exists (defensive)
                $conn->exec("CREATE TABLE IF NOT EXISTS semester_registrations (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    student_id INT NOT NULL,
                    semester_id INT NOT NULL,
                    status ENUM('pending','approved','rejected') DEFAULT 'pending',
                    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                    approved_by INT DEFAULT NULL,
                    approval_date DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_student_semester (student_id, semester_id),
                    KEY idx_student (student_id),
                    KEY idx_semester (semester_id),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            } catch (Exception $ex) {
                // ignore table-create failures here
            }

            $semId = $row['semester_id'] ?? null;
            $stuId = $row['student_id'] ?? null;
            $adminId = $_SESSION['admin_id'] ?? null;

            if ($semId && $stuId) {
                if ($newStatus === 'approved') {
                    // check existing
                    $sr = $conn->prepare('SELECT id, status FROM semester_registrations WHERE student_id = :sid AND semester_id = :semid LIMIT 1');
                    $sr->execute(['sid' => $stuId, 'semid' => $semId]);
                    $existingSR = $sr->fetch();

                    // use year_of_study from request if available, else try to extract from reason
                    $yearFromReason = $row['year_of_study'] ?? null;
                    if (!$yearFromReason && !empty($row['reason']) && preg_match('/Year of study:\s*Year\s*(\d{1,2})/i', $row['reason'], $m)) {
                        $y = (int)$m[1]; if ($y >= 1 && $y <= 10) $yearFromReason = $y;
                    }

                    if ($existingSR) {
                        if ($existingSR['status'] !== 'approved') {
                            $u2 = $conn->prepare('UPDATE semester_registrations SET status = "approved", year_of_study = :yos, approved_by = :admin, approval_date = NOW(), updated_at = NOW() WHERE id = :id');
                            $u2->execute(['yos' => $yearFromReason, 'admin' => $adminId, 'id' => $existingSR['id']]);
                        }
                    } else {
                        $i2 = $conn->prepare('INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, approved_by, approval_date, created_at) VALUES (:sid, :semid, :yos, "approved", NOW(), :admin, NOW(), NOW())');
                        $i2->execute(['sid' => $stuId, 'semid' => $semId, 'yos' => $yearFromReason, 'admin' => $adminId]);
                    }
                } elseif ($newStatus === 'rejected') {
                    // if rejected, mark any existing registration as rejected
                    $conn->prepare('UPDATE semester_registrations SET status = "rejected", updated_at = NOW() WHERE student_id = :sid AND semester_id = :semid')->execute(['sid' => $stuId, 'semid' => $semId]);
                }
            }
        }

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

// fetch semesters for bulk-approve selector
try {
    $sstmt = $conn->query("SELECT s.id, s.semester_name, s.semester_number, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.start_date DESC, s.semester_number DESC");
    $semesterOptions = $sstmt->fetchAll();
} catch (Exception $e) {
    $semesterOptions = [];
}

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
                <div class="p-3">
                    <form method="POST" class="form-inline mb-3" onsubmit="return confirm('Approve ALL pending semester registration requests' + (document.getElementById('bulkSemester').value ? ' for the selected semester?' : '?'))">
                        <?php echo csrfField(); ?>
                        <label class="mr-2 mb-2" style="font-weight:600;">Bulk approve semester requests:</label>
                        <select name="semester_id" id="bulkSemester" class="form-control mr-2 mb-2">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesterOptions as $so): ?>
                                <option value="<?php echo $so['id']; ?>"><?php echo e($so['year_name'] . ' - ' . $so['semester_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button name="action" value="approve_all_semester_requests" class="btn btn-sm btn-warning mb-2">Approve All</button>
                    </form>
                </div>
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