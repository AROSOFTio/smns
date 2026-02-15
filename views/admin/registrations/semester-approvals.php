<?php
/**
 * Admin - Semester Registration Approvals
 * Approve or reject student semester registration requests
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: semester-approvals.php');
        exit;
    }

    $registrationId = (int)($_POST['registration_id'] ?? 0);
    $action = $_POST['action'];

    if ($registrationId > 0 && in_array($action, ['approve', 'reject'])) {
        // Get registration details
        $detailStmt = $conn->prepare("
            SELECT sr.*, s.student_id, s.first_name, s.last_name, s.student_id AS student_number, s.program_id, s.level_year,
                   sem.semester_number, ay.year_name
            FROM semester_registrations sr
            JOIN students s ON sr.student_id = s.id
            JOIN semesters sem ON sr.semester_id = sem.id
            JOIN academic_years ay ON sem.academic_year_id = ay.id
            WHERE sr.id = :id
        ");
        $detailStmt->execute(['id' => $registrationId]);
        $registration = $detailStmt->fetch();

        if ($registration) {
            $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
            $currentUser = $auth->getCurrentUser();

            // Update registration status
            $updateStmt = $conn->prepare("
                UPDATE semester_registrations 
                SET status = :status, 
                    approved_by = :approved_by, 
                    approval_date = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                'status' => $newStatus,
                'approved_by' => $currentUser['id'],
                'id' => $registrationId
            ]);

            // If approved, auto-register the student to the semester's courses for their program/level
            if ($action === 'approve') {
                try {
                    $studentId = $registration['student_id'];
                    $semesterId = $registration['semester_id'];
                    $programId = $registration['program_id'] ?? null;
                    $levelYear = $registration['year_of_study'] ?? $registration['level_year'] ?? null;
                    $semNumber = $registration['semester_number'];

                    if ($programId && $levelYear) {
                        // resolve admin id from current user for course_registrations.approved_by (admins.id)
                        $adminId = null;
                        if (!empty($currentUser['id'])) {
                            $adminResolve = $conn->prepare("SELECT id FROM admins WHERE user_id = :uid LIMIT 1");
                            $adminResolve->execute(['uid' => $currentUser['id']]);
                            $admRow = $adminResolve->fetch();
                            $adminId = $admRow['id'] ?? null;
                        }

                        // First try to use course_assignments (courses explicitly assigned for the semester)
                        $insSqlAssign = "INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at, updated_at)
                                   SELECT :student_id, c.id, :semester_id, CURDATE(), 'approved', :approved_by, NOW(), NOW(), NOW()
                                   FROM course_assignments ca
                                   JOIN courses c ON ca.course_id = c.id
                                   WHERE ca.semester_id = :semester_id
                                     AND ca.status = 'active'
                                     AND c.status = 'active'
                                     AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id)
                        ";

                        $insStmtAssign = $conn->prepare($insSqlAssign);
                        $insStmtAssign->execute([
                            'student_id' => $studentId,
                            'semester_id' => $semesterId,
                            'approved_by' => $adminId
                        ]);

                        // If no assignments inserted (or regardless to ensure completeness), fall back to courses table
                        $insSql = "INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at, updated_at)
                                   SELECT :student_id, c.id, :semester_id, CURDATE(), 'approved', :approved_by, NOW(), NOW(), NOW()
                                   FROM courses c
                                   WHERE c.status = 'active'
                                     AND (c.semester_offered = :sem_num OR c.semester_offered = 3)
                                     AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id)
                        ";

                        // If program and level are available, narrow the fallback
                        if ($programId && $levelYear) {
                            $insSql = str_replace("WHERE c.status = 'active'", "WHERE c.program_id = :program_id AND c.level_year = :level_year AND c.status = 'active'", $insSql);
                        }

                        $insStmt = $conn->prepare($insSql);
                        $params = [
                            'student_id' => $studentId,
                            'semester_id' => $semesterId,
                            'approved_by' => $adminId,
                            'sem_num' => $semNumber
                        ];
                        if ($programId && $levelYear) {
                            $params['program_id'] = $programId;
                            $params['level_year'] = $levelYear;
                        }
                        $insStmt->execute($params);
                    }
                } catch (Exception $e) {
                    // don't block approval on registration errors; log if Logger available
                    if (class_exists('Logger')) {
                        try {
                            $logger = new Logger();
                            $logger->log($currentUser['id'] ?? null, 'auto_course_registration_error', 'registrations', 'Auto course registration failed: ' . $e->getMessage());
                        } catch (Exception $le) {
                            error_log('Auto course registration logging failed: ' . $le->getMessage());
                        }
                    } else {
                        error_log('Auto course registration failed: ' . $e->getMessage());
                    }
                }
            }

            // Get student's user_id for notification
            $userStmt = $conn->prepare("SELECT user_id FROM students WHERE id = :id");
            $userStmt->execute(['id' => $registration['student_id']]);
            $userRow = $userStmt->fetch();
            $studentUserId = $userRow['user_id'] ?? 0;

            if ($studentUserId > 0) {
                // Notify student
                $notifStmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, created_at) 
                    VALUES (:uid, :title, :msg, :type, :link, NOW())
                ");
                
                if ($action === 'approve') {
                    $notifStmt->execute([
                        'uid' => $studentUserId,
                        'title' => 'Semester Registration Approved',
                        'msg' => 'Your registration request for ' . $registration['year_name'] . ' - Semester ' . $registration['semester_number'] . ' has been approved. You can now select your courses.',
                        'type' => 'success',
                        'link' => BASE_URL . '/views/student/course-registration.php?semester_id=' . $registration['semester_id']
                    ]);
                } else {
                    $notifStmt->execute([
                        'uid' => $studentUserId,
                        'title' => 'Semester Registration Rejected',
                        'msg' => 'Your registration request for ' . $registration['year_name'] . ' - Semester ' . $registration['semester_number'] . ' has been rejected. Please contact administration for more details.',
                        'type' => 'warning',
                        'link' => BASE_URL . '/views/student/dashboard.php'
                    ]);
                }
            }

            $session->setFlash('success', 'Registration request ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully.');
            // Mark student as reported for this semester/year (ensure columns exist)
            if ($action === 'approve') {
                try {
                    $colCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'last_reported_semester_id'")->fetch();
                    if (!$colCheck) {
                        $conn->exec("ALTER TABLE students ADD COLUMN last_reported_semester_id INT NULL, ADD COLUMN last_reported_academic_year_id INT NULL, ADD COLUMN last_reported_at DATETIME NULL");
                    }

                    // Resolve academic_year_id for the semester
                    $ayStmt = $conn->prepare("SELECT academic_year_id FROM semesters WHERE id = :id LIMIT 1");
                    $ayStmt->execute(['id' => $registration['semester_id']]);
                    $academicYearId = $ayStmt->fetchColumn();

                    $upd = $conn->prepare("UPDATE students SET last_reported_semester_id = :sem, last_reported_academic_year_id = :ay, last_reported_at = NOW() WHERE id = :id");
                    $upd->execute([
                        'sem' => $registration['semester_id'],
                        'ay' => $academicYearId,
                        'id' => $registration['student_id']
                    ]);
                } catch (Exception $e) {
                    // non-fatal: log and continue
                    try { if (class_exists('Logger')) { (new Logger())->log($currentUser['id'] ?? null, 'report_mark_error', 'registrations', 'Failed to mark reported: ' . $e->getMessage()); } } catch(Exception $le) { error_log('Report mark failed: ' . $le->getMessage()); }
                }
            }
        } else {
            $session->setFlash('error', 'Registration request not found.');
        }
    }

    header('Location: semester-approvals.php');
    exit;
}

// Fetch all semester registration requests
$status = $_GET['status'] ?? 'pending';
$searchTerm = $_GET['search'] ?? '';

    $sql = "
    SELECT sr.*, 
           s.student_id, s.first_name, s.last_name, s.student_id AS student_number, s.level_year,
           sem.semester_number, ay.year_name,
           p.program_name,
           u.username as approved_by_username
    FROM semester_registrations sr
    JOIN students s ON sr.student_id = s.id
    JOIN semesters sem ON sr.semester_id = sem.id
    JOIN academic_years ay ON sem.academic_year_id = ay.id
    LEFT JOIN programs p ON s.program_id = p.id
    LEFT JOIN users u ON sr.approved_by = u.id
    WHERE 1=1
";

$params = [];

if ($status && $status !== 'all') {
    $sql .= " AND sr.status = :status";
    $params['status'] = $status;
}

if ($searchTerm) {
    $sql .= " AND (s.first_name LIKE :search OR s.last_name LIKE :search OR s.student_id LIKE :search)";
    $params['search'] = '%' . $searchTerm . '%';
}

$sql .= " ORDER BY sr.request_date DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Count pending requests
$countStmt = $conn->query("SELECT COUNT(*) FROM semester_registrations WHERE status = 'pending'");
$pendingCount = $countStmt->fetchColumn();

// Fetch approved (registered) students for display
$registeredSql = "
    SELECT sr.*, s.student_id, s.first_name, s.last_name, s.level_year, p.program_name, sem.semester_number, ay.year_name, sr.approval_date
    FROM semester_registrations sr
    JOIN students s ON sr.student_id = s.id
    JOIN semesters sem ON sr.semester_id = sem.id
    JOIN academic_years ay ON sem.academic_year_id = ay.id
    LEFT JOIN programs p ON s.program_id = p.id
    WHERE sr.status = 'approved'
    ORDER BY ay.start_date DESC, sem.semester_number, s.last_name, s.first_name
";
$registeredStmt = $conn->prepare($registeredSql);
$registeredStmt->execute();
$registeredStudents = $registeredStmt->fetchAll();

$pageTitle = 'Semester Registration Approvals - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Semester Registration Approvals</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    Registration Requests 
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge badge-warning"><?php echo $pendingCount; ?> Pending</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="mb-4">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control" onchange="this.form.submit()">
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Search Student</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Name or student number..." value="<?php echo e($searchTerm); ?>">
                                    <div class="input-group-append">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Registration Requests Table -->
                <?php if (empty($registrations)): ?>
                    <div class="alert alert-info">No registration requests found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student Number</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Request Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td><?php echo e($reg['first_name'] . ' ' . $reg['last_name']); ?></td>
                                        <td><?php echo e($reg['student_number']); ?></td>
                                        <td><?php echo e($reg['program_name'] ?? 'N/A'); ?></td>
                                        <td>Year <?php echo e($reg['level_year']); ?></td>
                                        <td><?php echo e($reg['year_name']); ?></td>
                                        <td>Semester <?php echo e($reg['semester_number']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($reg['request_date'])); ?></td>
                                        <td>
                                            <?php if ($reg['status'] === 'pending'): ?>
                                                <span class="badge badge-warning">Pending</span>
                                            <?php elseif ($reg['status'] === 'approved'): ?>
                                                <span class="badge badge-success">Approved</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($reg['status'] === 'pending'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to approve this registration?');">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                    <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" title="Approve">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to reject this registration?');">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger" title="Reject">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">
                                                    <?php echo $reg['approved_by_username'] ? 'By ' . e($reg['approved_by_username']) : 'Processed'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Registered Students (Approved) -->
                <hr />
                <h5 class="mt-4">Registered Students (Approved)</h5>
                <?php if (empty($registeredStudents)): ?>
                    <div class="alert alert-info">No registered students found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student Number</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Approved On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registeredStudents as $r): ?>
                                    <tr>
                                        <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                        <td><?php echo e($r['student_id']); ?></td>
                                        <td><?php echo e($r['program_name'] ?? 'N/A'); ?></td>
                                        <td>Year <?php echo e($r['level_year'] ?? $r['year_of_study'] ?? 'N/A'); ?></td>
                                        <td><?php echo e($r['year_name']); ?></td>
                                        <td>Semester <?php echo e($r['semester_number']); ?></td>
                                        <td><?php echo $r['approval_date'] ? date('d M Y H:i', strtotime($r['approval_date'])) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>