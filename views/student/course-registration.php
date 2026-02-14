<?php
/**
 * Student - Course Registration
 */
require_once '../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('student');
$auth = new Auth('student');

// Verify student access
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

$db = new Database();
$conn = $db->getConnection();

// Semesters for selection
$sstmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.year_name DESC, s.semester_number DESC");
$semesters = $sstmt->fetchAll();

// Default semester (current)
$currentSemester = Helper::getCurrentSemester();
$defaultSemesterId = $currentSemester['id'] ?? 0;

// Determine selected semester (GET or POST)
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : ($defaultSemesterId ?: 0);
if (isset($_POST['semester_id'])) {
    $semesterId = (int)$_POST['semester_id'];
}

// Handle registration submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Server-side: ensure registration window for the semester is open (if dates are configured)
    $semCheck = $conn->prepare("SELECT registration_start_date, registration_end_date FROM semesters WHERE id = :id");
    $semCheck->execute(['id' => $semesterId]);
    $semRow = $semCheck->fetch();
    if ($semRow && !empty($semRow['registration_start_date']) && !empty($semRow['registration_end_date'])) {
        $today = date('Y-m-d');
        if ($today < $semRow['registration_start_date'] || $today > $semRow['registration_end_date']) {
            $session->setFlash('error', 'Registration for the selected semester is currently closed.');
            header('Location: course-registration.php?semester_id=' . $semesterId);
            exit;
        }
    }

    $selected = $_POST['courses'] ?? [];
    if (!is_array($selected) || count($selected) === 0) {
        $session->setFlash('error', 'Please select at least one course to register.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    $inserted = 0;
    $duplicates = 0;
    $errors = 0;

    $checkStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");

    // Auto-approve registrations on student submit (configurable in Settings)
    $autoApprove = getSetting('auto_approve_registrations', '0') === '1';

    if ($autoApprove) {
        $insStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");
    } else {
        $insStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'pending', NOW())");
    }

    foreach ($selected as $cid) {
        $courseId = (int)$cid;
        if ($courseId <= 0) continue;

        // skip if already registered
        $checkStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $courseId, 'semester_id' => $semesterId]);
        if ($checkStmt->fetch()) {
            $duplicates++;
            continue;
        }

        try {
            $insStmt->execute([
                'student_id' => $studentProfile['id'],
                'course_id' => $courseId,
                'semester_id' => $semesterId
            ]);
            $inserted++;
        } catch (PDOException $e) {
            // duplicate or other DB error
            $errors++;
            continue;
        }
    }

    // Notifications & messages
    $msgParts = [];

    // If auto-approved, notify the student and optionally notify admins
    if ($inserted > 0 && $autoApprove) {
        try {
            // notify student
            $snote = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'success', :link, NOW())");
            $snote->execute([
                'uid' => $currentUser['id'],
                'title' => 'Course Registration Approved',
                'msg' => 'Your course registration has been received and approved.',
                'link' => BASE_URL . '/views/student/registrations.php?semester_id=' . $semesterId
            ]);

            // still notify admins that student registered (optional)
            $admStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $adminUsers = $admStmt->fetchAll(PDO::FETCH_COLUMN);
            $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");
            $title = 'Course Registration Submitted';
            $message = e($studentProfile['first_name'] . ' ' . $studentProfile['last_name']) . ' has registered for courses.';
            $link = BASE_URL . '/views/admin/registrations/pending.php';
            foreach ($adminUsers as $au) {
                $noteStmt->execute([
                    'uid' => $au,
                    'title' => $title,
                    'msg' => $message,
                    'link' => $link
                ]);
            }
        } catch (Exception $ex) {
            // swallow notification errors
        }

        $msgParts[] = "$inserted course(s) registered and auto-approved.";
    } elseif ($inserted > 0) {
        // non-auto-approve flow (kept for completeness)
        try {
            $admStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $adminUsers = $admStmt->fetchAll(PDO::FETCH_COLUMN);
            $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");
            $title = 'New Course Registration Submitted';
            $message = e($studentProfile['first_name'] . ' ' . $studentProfile['last_name']) . ' has submitted course registration.';
            $link = BASE_URL . '/views/admin/registrations/pending.php';
            foreach ($adminUsers as $au) {
                $noteStmt->execute([
                    'uid' => $au,
                    'title' => $title,
                    'msg' => $message,
                    'link' => $link
                ]);
            }
        } catch (Exception $ex) {
            // swallow notification errors
        }

        $msgParts[] = "$inserted course(s) submitted for approval.";
    }

    if ($duplicates) $msgParts[] = "$duplicates course(s) were already registered.";
    if ($errors) $msgParts[] = "$errors course(s) failed to register.";

    if (empty($msgParts)) $msgParts[] = 'No changes were made.';

    $session->setFlash('success', implode(' ', $msgParts));
    header('Location: course-registration.php?semester_id=' . $semesterId);
    exit;
}

// Fetch available courses for the semester (prefer course_assignments)
$courses = [];
if ($semesterId) {
    $courseSql = "SELECT ca.course_id, c.course_code, c.course_name, c.credit_hours, c.level_year
                  FROM course_assignments ca
                  INNER JOIN courses c ON ca.course_id = c.id
                  WHERE ca.semester_id = :semester_id
                    AND (c.program_id = :program_id OR c.program_id IS NULL)
                  ORDER BY c.course_code";
    $cstmt = $conn->prepare($courseSql);
    $cstmt->execute(['semester_id' => $semesterId, 'program_id' => $studentProfile['program_id'] ?? 0]);
    $courses = $cstmt->fetchAll();

    // Fallback: if no assignments found, show courses by level/program
    if (empty($courses)) {
        $fallbackSql = "SELECT id as course_id, course_code, course_name, credit_hours, level_year FROM courses WHERE (program_id = :program_id OR program_id IS NULL) AND (level_year = :level_year OR level_year IS NULL) ORDER BY course_code";
        $fb = $conn->prepare($fallbackSql);
        $fb->execute(['program_id' => $studentProfile['program_id'] ?? 0, 'level_year' => $studentProfile['level_year'] ?? 0]);
        $courses = $fb->fetchAll();
    }
}

// Already registered courses for this student & semester
$registered = [];
if ($semesterId) {
    $rstmt = $conn->prepare("SELECT course_id, status FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    while ($r = $rstmt->fetch()) {
        $registered[$r['course_id']] = $r['status'];
    }
}

$pageTitle = 'Course Registration - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Course Registration</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-3">
                    <label class="mr-2">Select Semester:</label>
                    <select name="semester_id" class="form-control mr-2" onchange="this.form.submit();">
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?php echo $sem['id']; ?>" <?php echo $semesterId == $sem['id'] ? 'selected' : ''; ?>><?php echo e($sem['year_name'] . ' - ' . $sem['semester_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="<?php echo BASE_URL; ?>/views/student/registrations.php?semester_id=<?php echo $semesterId; ?>" class="btn btn-secondary">View My Registrations</a>
                </form>

                <?php if (!$semesterId): ?>
                    <p class="text-muted">No semester selected.</p>
                <?php else: ?>
                    <?php
                    // Check registration window
                    $semInfo = null;
                    foreach ($semesters as $s) {
                        if ($s['id'] == $semesterId) { $semInfo = $s; break; }
                    }
                    $canRegister = true;
                    if ($semInfo && !empty($semInfo['registration_start_date']) && !empty($semInfo['registration_end_date'])) {
                        $today = date('Y-m-d');
                        if ($today < $semInfo['registration_start_date'] || $today > $semInfo['registration_end_date']) {
                            $canRegister = false;
                        }
                    }
                    ?>

                    <?php if (!$canRegister): ?>
                        <div class="alert alert-warning">Registration for the selected semester is currently closed.</div>
                    <?php endif; ?>

                    <?php if (empty($courses)): ?>
                        <p class="text-muted">No courses available for registration in the selected semester.</p>
                    <?php else: ?>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="semester_id" value="<?php echo $semesterId; ?>">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Credits</th>
                                            <th>Level</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($courses as $c): ?>
                                            <?php $isReg = isset($registered[$c['course_id']]); ?>
                                            <tr>
                                                <td style="width:40px;">
                                                    <input type="checkbox" name="courses[]" value="<?php echo $c['course_id']; ?>" <?php echo $isReg ? 'disabled' : ''; ?>>
                                                </td>
                                                <td><?php echo e($c['course_code']); ?></td>
                                                <td><?php echo e($c['course_name']); ?></td>
                                                <td><?php echo e($c['credit_hours']); ?></td>
                                                <td><?php echo e($c['level_year']); ?></td>
                                                <td>
                                                    <?php if ($isReg): ?>
                                                        <span class="badge badge-info"><?php echo e(ucfirst($registered[$c['course_id']])); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not registered</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3">
                                <button name="action" value="submit_registration" class="btn btn-primary" <?php echo $canRegister ? '' : 'disabled'; ?>>Submit Registration</button>
                                <a href="<?php echo BASE_URL; ?>/views/student/registrations.php?semester_id=<?php echo $semesterId; ?>" class="btn btn-secondary">View My Registrations</a>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5>Help</h5>
                <p class="text-muted">Select courses and click <strong>Submit Registration</strong>. Submitted registrations will be marked <em>pending</em> until approved by administration.</p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php';
