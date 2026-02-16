<?php
/**
 * Admin - Course Registrations (Pending & Approved)
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Handle actions: approve single student-semester or approve all for semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'approve' && !empty($_POST['student_id']) && !empty($_POST['semester_id'])) {
        $studentId = (int)$_POST['student_id'];
        $semesterId = (int)$_POST['semester_id'];

        $stmt = $conn->prepare("UPDATE course_registrations
                                SET status = 'approved', approved_by = :admin_id, approved_date = NOW()
                                WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'pending'");
        $stmt->execute([
            'admin_id' => $_SESSION['admin_id'] ?? 1,
            'student_id' => $studentId,
            'semester_id' => $semesterId
        ]);

        // Create a notification for the student (if user exists)
        $u = $conn->prepare("SELECT user_id FROM students WHERE id = :sid");
        $u->execute(['sid' => $studentId]);
        $urow = $u->fetch();
        if ($urow && $urow['user_id']) {
            $nid = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (:uid, :title, :msg, 'success', :link)");
            $nid->execute([
                'uid' => $urow['user_id'],
                'title' => 'Registration Approved',
                'msg' => 'Your course registration for the semester has been approved by administration.',
                'link' => BASE_URL . '/views/student/registrations.php?semester_id=' . $semesterId
            ]);
        }

        $session->setFlash('success', 'Selected registrations approved successfully.');
        header('Location: pending.php');
        exit;
    }

    if ($action === 'approve_all' && !empty($_POST['semester_id'])) {
        $semesterId = (int)$_POST['semester_id'];
        $stmt = $conn->prepare("UPDATE course_registrations
                                SET status = 'approved', approved_by = :admin_id, approved_date = NOW()
                                WHERE semester_id = :semester_id AND status = 'pending'");
        $stmt->execute([
            'admin_id' => $_SESSION['admin_id'] ?? 1,
            'semester_id' => $semesterId
        ]);

        $session->setFlash('success', 'All pending registrations for the semester have been approved.');
        header('Location: pending.php');
        exit;
    }
}

// Optional semester filter from GET
$filterSemesterId = isset($_GET['semester_filter_id']) ? (int)$_GET['semester_filter_id'] : 0;

// Enrollment summary (admins need to see enrollments)
$enrollments = $conn->query("SELECT sem.id, sem.semester_number, sem.semester_name, ay.year_name, COUNT(cr.id) as total_registrations, SUM(CASE WHEN cr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count, SUM(CASE WHEN cr.status = 'approved' THEN 1 ELSE 0 END) AS approved_count FROM course_registrations cr JOIN semesters sem ON cr.semester_id = sem.id JOIN academic_years ay ON sem.academic_year_id = ay.id GROUP BY sem.id ORDER BY ay.start_date DESC, sem.semester_number DESC")->fetchAll();

// If no filter selected and Semester 1 was removed, default the filter to the first non-Semester-1 enrollment
if (empty($filterSemesterId)) {
    foreach ($enrollments as $e) {
        if (isset($e['semester_number']) && (int)$e['semester_number'] === 1) continue; // skip Semester 1
        $filterSemesterId = (int)$e['id'];
        break;
    }
}

// Fetch pending grouped by student + semester (apply optional semester filter)
$pendingSql = "SELECT sr.student_id, sr.semester_id, s.first_name, s.last_name, s.student_id as student_code, sem.semester_name, sr.status
               FROM semester_registrations sr
               JOIN students s ON sr.student_id = s.id
               JOIN semesters sem ON sr.semester_id = sem.id
               WHERE sr.status = 'pending' " . ($filterSemesterId ? "AND sr.semester_id = :filter " : "") . "
               ORDER BY sr.request_date DESC";
$pendingStmt = $conn->prepare($pendingSql);
if ($filterSemesterId) $pendingStmt->execute(['filter' => $filterSemesterId]); else $pendingStmt->execute();
$pending = $pendingStmt->fetchAll();

// Fetch approved (reported) grouped by student + semester (apply optional semester filter)
$approvedSql = "SELECT cr.student_id, cr.semester_id, s.first_name, s.last_name, s.student_id as student_code, sem.semester_name, COUNT(*) as course_count
               FROM course_registrations cr
               JOIN students s ON cr.student_id = s.id
               JOIN semesters sem ON cr.semester_id = sem.id
               WHERE cr.status = 'approved' " . ($filterSemesterId ? "AND cr.semester_id = :filter " : "") . "
               GROUP BY cr.student_id, cr.semester_id
               ORDER BY cr.approved_date DESC";
$approvedStmt = $conn->prepare($approvedSql);
if ($filterSemesterId) $approvedStmt->execute(['filter' => $filterSemesterId]); else $approvedStmt->execute();
$approved = $approvedStmt->fetchAll();

$pageTitle = 'Registrations - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>Course Registrations</h4>
        </div>
    </div>

    <div class="content-area container-fluid p-4">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <!-- Enrollments summary (admin) -->
                <?php if (!empty($enrollments)): ?>
                    <div class="mb-3 d-flex flex-wrap">
                        <?php foreach ($enrollments as $e):
                                // remove Semester 1 card per request
                                if (isset($e['semester_number']) && (int)$e['semester_number'] === 1) continue;
                                $isActive = ($filterSemesterId && $filterSemesterId == $e['id']);
                        ?>
                            <div class="mr-3 mb-2 p-2 border rounded" style="min-width:220px;">
                                <strong><?php echo e($e['year_name'] . ' - ' . $e['semester_name']); ?></strong><br>
                                <small>Total: <?php echo e($e['total_registrations']); ?> · <span class="text-warning">Pending: <?php echo e($e['pending_count']); ?></span> · <span class="text-success">Approved: <?php echo e($e['approved_count']); ?></span></small>
                                <div class="mt-1">
                                    <a href="pending.php?semester_filter_id=<?php echo $e['id']; ?>" class="<?php echo $isActive ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-primary'; ?>" <?php echo $isActive ? 'aria-pressed="true"' : ''; ?>><?php echo $isActive ? 'Filtered' : 'Filter'; ?></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="mb-3"><small class="text-muted">No enrollments recorded yet.</small></div>
                <?php endif; ?>

                <h5>Pending Registrations</h5>
                <?php if (empty($pending)): ?> 
                <?php else: ?>
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Semester</th>
                                <th>Courses Pending</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending as $row): ?>
                                <tr>
                                    <td><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo e($row['student_code']); ?></td>
                                    <td><?php echo e($row['semester_name']); ?></td>
                                    <td><?php echo e($row['course_count']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Approve these registrations?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $row['student_id']; ?>">
                                            <input type="hidden" name="semester_id" value="<?php echo $row['semester_id']; ?>">
                                            <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <a href="view.php?student_id=<?php echo $row['student_id']; ?>&semester_id=<?php echo $row['semester_id']; ?>" class="btn btn-sm btn-info">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Bulk approve by semester (quick action) -->
                    <div class="mt-3">
                        <form method="POST" onsubmit="return confirm('Approve ALL pending registrations for the selected semester?');" class="form-inline">
                            <?php echo csrfField(); ?>
                            <label class="mr-2">Approve all pending for semester:</label>
                            <select name="semester_id" class="form-control mr-2">
                                <?php
                                $sstmt = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id DESC");
                                $semesters = $sstmt->fetchAll();
                                foreach ($semesters as $sem) {
                                    echo "<option value=\"{$sem['id']}\">" . e($sem['semester_name']) . "</option>";
                                }
                                ?>
                            </select>
                            <button name="action" value="approve_all" class="btn btn-warning">Approve All</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5>Reported / Approved Registrations</h5>
                <?php if (empty($approved)): ?>
                    <p class="text-muted">No approved registrations found.</p>
                <?php else: ?>
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Semester</th>
                                <th>Courses Approved</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($approved as $row): ?>
                                <tr>
                                    <td><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo e($row['student_code']); ?></td>
                                    <td><?php echo e($row['semester_name']); ?></td>
                                    <td><?php echo e($row['course_count']); ?></td>
                                    <td>
                                        <a href="view.php?student_id=<?php echo $row['student_id']; ?>&semester_id=<?php echo $row['semester_id']; ?>" class="btn btn-sm btn-primary">View Courses</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>