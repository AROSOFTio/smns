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

// Academic years for dropdown
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();

// Default semester & academic year (current)
$currentSemester = Helper::getCurrentSemester();
$defaultSemesterId = $currentSemester['id'] ?? 0;
$defaultAcademicYearId = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);

// Determine selected academic year & semester number from GET (or fallbacks)
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int)$_GET['semester_number'] : ($currentSemester['semester_number'] ?? 1);

// Map academic_year + semester_number to a semester id (preserve old semester_id GET if provided)
$semesterId = 0;
if (isset($_GET['semester_id']) && (int)$_GET['semester_id'] > 0) {
    $semesterId = (int)$_GET['semester_id'];
} else {
    $mapStmt = $conn->prepare("SELECT id FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1");
    $mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
    $row = $mapStmt->fetch();
    $semesterId = $row['id'] ?? $defaultSemesterId;
}

if (isset($_POST['semester_id'])) {
    $semesterId = (int)$_POST['semester_id'];
}

// Compute Year of study (auto-generated from student's entry_year when available)
$yearOfStudy = (int)($studentProfile['level_year'] ?? 1);
if (!empty($studentProfile['entry_year']) && $selectedAcademicYearId) {
    $ayStmt = $conn->prepare("SELECT start_date FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => $selectedAcademicYearId]);
    $ayRow = $ayStmt->fetch();
    if ($ayRow && !empty($ayRow['start_date'])) {
        $startYear = (int)date('Y', strtotime($ayRow['start_date']));
        $entryYear = (int)$studentProfile['entry_year'];
        $calc = ($startYear - $entryYear) + 1;
        $yearOfStudy = max(1, min(10, $calc));
    }
} else {
    $yearOfStudy = (int)($studentProfile['level_year'] ?? $yearOfStudy);
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
        // allow continuing students to register even when window is closed
        if (!($isContinuing) && ($today < $semRow['registration_start_date'] || $today > $semRow['registration_end_date'])) {
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
    // ALSO: automatically approve registrations submitted by continuing students (Year > 1)
    $isContinuing = (isset($studentProfile['level_year']) && (int)$studentProfile['level_year'] > 1 && ($studentProfile['status'] ?? '') === 'active');
    $autoApprove = getSetting('auto_approve_registrations', '0') === '1' || $isContinuing;

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
    // After successful registration redirect student to My Courses and select the semester
    header('Location: my-courses.php?semester_id=' . $semesterId);
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
            <h4>Register for semester</h4>
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
                <form method="GET" class="mb-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" id="current_semester_id" value="<?php echo e($semesterId); ?>">
                    <div class="d-flex flex-wrap align-items-center">
                        <div style="flex:1; min-width:280px; max-width:880px;">
                            <div class="form-row">
                                <div class="form-group col-12 col-md-4 d-flex align-items-center">
                                    <label class="mb-0 mr-3" style="min-width:140px; color:#374151; font-weight:600;">Academic year</label>
                                    <select name="academic_year_id" class="form-control" onchange="this.form.submit();">
                                        <?php foreach ($academicYears as $ay): ?>
                                            <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group col-12 col-md-4 d-flex align-items-center">
                                    <label class="mb-0 mr-3" style="min-width:140px; color:#374151; font-weight:600;">Semester</label>
                                    <select name="semester_number" class="form-control" onchange="this.form.submit();">
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div class="form-group col-12 col-md-4 d-flex align-items-center">
                                    <label class="mb-0 mr-3" style="min-width:140px; color:#374151; font-weight:600;">Year of study</label>
                                    <input type="text" class="form-control" value="<?php echo 'Year ' . e($yearOfStudy); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="ml-3 mt-2 mt-md-0">
                            <button id="requestRegisterBtn" type="button" class="btn btn-success" style="min-width:110px; height:48px;">Register</button>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a href="<?php echo BASE_URL; ?>/views/student/registrations.php?semester_id=<?php echo $semesterId; ?>" class="text-muted mr-3">View My Registrations</a>
                        <a href="<?php echo BASE_URL; ?>/views/student/my-courses.php?academic_year_id=<?php echo $selectedAcademicYearId; ?>&semester_number=<?php echo $selectedSemesterNumber; ?>" class="text-muted">My Courses</a>
                    </div>
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
                        // allow continuing students to register even when the window is closed
                        if (!($isContinuing) && ($today < $semInfo['registration_start_date'] || $today > $semInfo['registration_end_date'])) {
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

<script>
document.addEventListener('DOMContentLoaded', function(){
    // map of registration dates for academic_year_id + '_' + semester_number
    const semesterMap = <?php
        $map = [];
        foreach ($semesters as $s) {
            $key = ($s['academic_year_id'] ?? '') . '_' . ($s['semester_number'] ?? '');
            $map[$key] = [
                'start' => $s['registration_start_date'] ?? '',
                'end' => $s['registration_end_date'] ?? '',
                'year_name' => $s['year_name'] ?? '',
                'semester_name' => $s['semester_name'] ?? ''
            ];
        }
        echo json_encode($map);
    ?>;

    const btn = document.getElementById('requestRegisterBtn');
    if (!btn) return;

    btn.addEventListener('click', function(e){
        const form = btn.closest('form');
        const aySelect = form.querySelector('select[name="academic_year_id"]');
        const snSelect = form.querySelector('select[name="semester_number"]');
        const ay = aySelect ? aySelect.value : '';
        const sn = snSelect ? snSelect.value : '';
        const key = ay + '_' + sn;
        const entry = semesterMap[key] || {};

        // determine if registration window is open (if dates configured)
        let registrationOpen = true;
        if (entry.start && entry.end) {
            const today = new Date().toISOString().slice(0,10);
            if (today < entry.start || today > entry.end) registrationOpen = false;
        }

        if (registrationOpen) {
            // registration is open — submit GET to show registration table
            form.submit();
            return;
        }

        // registration closed — confirm and send a student request to admin
        const label = (entry.year_name ? entry.year_name + ' - ' : '') + (entry.semester_name ? entry.semester_name : ('Semester ' + sn));
        if (!confirm('Registration for ' + label + ' is currently closed. Send a request to the administrator to enable registration?')) return;

        // send request via POST to submit-request.php
        btn.disabled = true;
        const originalText = btn.innerText;
        btn.innerText = 'Requesting...';

        const reason = 'Please enable registration for ' + label + '. Year of study: ' + (form.querySelector('input[readonly]') ? form.querySelector('input[readonly]').value : 'N/A');
        const fd = new FormData();
        fd.append('request_type', 'enable_registration');
        fd.append('reason', reason);
        // include current semester id so server can guard duplicates
        const semEl = document.getElementById('current_semester_id');
        if (semEl && semEl.value) fd.append('semester_id', semEl.value);
        const csrfEl = form.querySelector('input[name="csrf_token"]');
        if (csrfEl) fd.append('csrf_token', csrfEl.value);

        fetch('<?php echo BASE_URL; ?>/views/student/submit-request.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(res){
                // redirect to dashboard where flash message will be shown
                window.location = '<?php echo BASE_URL; ?>/views/student/dashboard.php';
            })
            .catch(function(err){
                alert('Failed to send request. Please try again later.');
                btn.disabled = false;
                btn.innerText = originalText;
            });
    });
});
</script>

<?php include '../../includes/footer.php';
