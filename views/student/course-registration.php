<?php
/**
 * Student - Course Registration
 * Modified to require admin approval before showing courses
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

// Map academic_year + semester_number to a semester id
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

// Compute Year of study
// Allow overriding via GET (user-selectable)
$yearOfStudy = isset($_GET['year_of_study']) ? (int)$_GET['year_of_study'] : (int)($studentProfile['level_year'] ?? 1);
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

// Check if student has an APPROVED semester registration for the selected semester
$approvalCheckStmt = $conn->prepare("
    SELECT sr.*, ay.year_name 
    FROM semester_registrations sr
    JOIN semesters s ON sr.semester_id = s.id
    JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE sr.student_id = :student_id 
    AND sr.semester_id = :semester_id 
    AND sr.status = 'approved'
    LIMIT 1
");
$approvalCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$semesterApproval = $approvalCheckStmt->fetch();

// Check if student has a pending request
$pendingCheckStmt = $conn->prepare("
    SELECT * FROM semester_registrations 
    WHERE student_id = :student_id 
    AND semester_id = :semester_id 
    AND status = 'pending'
    LIMIT 1
");
$pendingCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$pendingRequest = $pendingCheckStmt->fetch();

// Auto-create missing semester registration if not found
$regCheckStmt = $conn->prepare("SELECT id FROM semester_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
$regCheckStmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
if (!$regCheckStmt->fetch()) {
    $now = date('Y-m-d H:i:s');
    $autoRegStmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :status, :request_date, :created_at, :updated_at)");
    $autoRegStmt->execute([
        'student_id' => $studentProfile['id'],
        'semester_id' => $semesterId,
        'status' => 'pending',
        'request_date' => $now,
        'created_at' => $now,
        'updated_at' => $now
    ]);
}

// Handle semester registration request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_semester_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Check if already has a pending or approved registration
    $existingStmt = $conn->prepare("
        SELECT id, status FROM semester_registrations 
        WHERE student_id = :student_id AND semester_id = :semester_id
    ");
    $existingStmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'approved') {
            $session->setFlash('info', 'You are already registered for this semester.');
        } else {
            $session->setFlash('info', 'Your registration request is pending admin approval.');
        }
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Create semester registration request (include year_of_study)
    $insertStmt = $conn->prepare("
        INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at) 
        VALUES (:student_id, :semester_id, :year_of_study, 'pending', NOW(), NOW())
    ");
    
    try {
        $insertStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId,
            'year_of_study' => isset($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : $yearOfStudy
        ]);

        // Notify admins
        $admStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $adminUsers = $admStmt->fetchAll(PDO::FETCH_COLUMN);
        $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");
        
        $semInfo = null;
        foreach ($semesters as $s) {
            if ($s['id'] == $semesterId) { $semInfo = $s; break; }
        }
        $semLabel = ($semInfo ? $semInfo['year_name'] . ' - Semester ' . $semInfo['semester_number'] : 'Semester');
        
        $title = 'New Semester Registration Request';
        $message = e($studentProfile['first_name'] . ' ' . $studentProfile['last_name']) . ' has requested to register for ' . $semLabel;
        $link = BASE_URL . '/views/admin/registrations/semester-approvals.php';
        
        foreach ($adminUsers as $au) {
            $noteStmt->execute([
                'uid' => $au,
                'title' => $title,
                'msg' => $message,
                'link' => $link
            ]);
        }

        $session->setFlash('success', 'Registration request submitted successfully. You will be notified once approved by admin.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;

    } catch (PDOException $e) {
        $session->setFlash('error', 'Failed to submit registration request. Please try again.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }
}

// Handle course registration submission (only if semester registration is approved)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_course_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Verify semester registration is approved
    if (!$semesterApproval) {
        $session->setFlash('error', 'You must have an approved semester registration before registering for courses.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
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
    $insStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

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
            $errors++;
            continue;
        }
    }

    // Build notification message
    $msgParts = [];
    if ($inserted > 0) {
        $msgParts[] = "$inserted course(s) registered successfully.";
    }
    if ($duplicates) $msgParts[] = "$duplicates course(s) were already registered.";
    if ($errors) $msgParts[] = "$errors course(s) failed to register.";
    if (empty($msgParts)) $msgParts[] = 'No changes were made.';

    $session->setFlash('success', implode(' ', $msgParts));
    header('Location: course-registration.php?semester_id=' . $semesterId);
    exit;
}

// Fetch available courses ONLY if semester registration is approved
$courses = [];
if ($semesterId && $semesterApproval) {
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
if ($semesterId && $semesterApproval) {
    $rstmt = $conn->prepare("SELECT course_id, status FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    while ($r = $rstmt->fetch()) {
        $registered[$r['course_id']] = $r['status'];
    }
}

// Determine if student still needs to select any courses (i.e., there are available courses not yet registered)
$needsSelection = false;
if (!empty($courses)) {
    foreach ($courses as $c) {
        if (!isset($registered[$c['course_id']])) { $needsSelection = true; break; }
    }
}

// Fetch approved registrations (courses student is supposed to attend)
$approvedCourses = [];
if ($semesterId && $semesterApproval) {
    $ac = $conn->prepare("SELECT cr.course_id, c.course_code, c.course_name, c.credit_hours, c.level_year
                          FROM course_registrations cr
                          JOIN courses c ON cr.course_id = c.id
                          WHERE cr.student_id = :student_id AND cr.semester_id = :semester_id AND cr.status = 'approved'
                          ORDER BY c.course_code");
    $ac->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    $approvedCourses = $ac->fetchAll();
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
        <?php if ($session->getFlash('info')): ?>
            <div class="alert alert-info"><?php echo e($session->getFlash('info')); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="mb-3">
                    <?php echo csrfField(); ?>
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
                                                    <select id="year_of_study_select" name="year_of_study" class="form-control" onchange="this.form.submit();">
                                                        <?php for ($y = 1; $y <= 4; $y++): ?>
                                                            <option value="<?php echo $y; ?>" <?php echo $yearOfStudy == $y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                </div>
                            </div>
                        </div>

                        <?php if (!$semesterApproval && !$pendingRequest): ?>
                        <div class="ml-3 mt-2 mt-md-0">
                            <button type="button" id="requestRegisterBtn" class="btn btn-success" style="min-width:110px; height:48px;">
                                <i class="fas fa-paper-plane"></i> Register
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3">
                    </div>
                </form>

                <!-- Hidden POST form for semester registration (avoids nested forms) -->
                <form id="semesterRequestForm" method="POST" style="display:none;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="semester_id" id="hidden_semester_id" value="<?php echo $semesterId; ?>">
                    <input type="hidden" name="year_of_study" id="hidden_year_of_study" value="<?php echo $yearOfStudy; ?>">
                    <input type="hidden" name="action" value="request_semester_registration">
                </form>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var btn = document.getElementById('requestRegisterBtn');
                    var hiddenForm = document.getElementById('semesterRequestForm');
                    if (!btn || !hiddenForm) return;

                    btn.addEventListener('click', function(e) {
                        // show immediate client-side alert and disable the button to indicate action
                        var cardBody = btn.closest('.card-body') || document.querySelector('.content-area .card .card-body');
                        if (cardBody) {
                            var existing = document.getElementById('clientRegistrationAlert');
                            if (existing) existing.remove();
                            var alert = document.createElement('div');
                            alert.id = 'clientRegistrationAlert';
                            alert.className = 'alert alert-info';
                            alert.role = 'alert';
                            alert.innerText = 'Sending registration request to administrator...';
                            cardBody.insertBefore(alert, cardBody.firstChild);
                        }

                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Requesting...';

                        // copy year_of_study into hidden form if present
                        try {
                            var yosSel = document.getElementById('year_of_study_select');
                            var hiddenY = document.getElementById('hidden_year_of_study');
                            if (yosSel && hiddenY) hiddenY.value = yosSel.value;
                        } catch (ex) { console.error(ex); }

                        try { hiddenForm.submit(); } catch (err) { console.error(err); }
                    });
                });
                </script>

                <?php if ($pendingRequest): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock"></i> Your registration request for this semester is pending admin approval. You will be notified once it is processed.
                    </div>
                <?php elseif (!$semesterApproval): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Click the <strong>Register</strong> button above to request registration for this semester. Once approved by admin, you will be able to select your courses.
                    </div>
                <?php else: ?>
                    <!-- Semester registration is approved - show courses -->
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> Your semester registration is approved. Please select your courses below.
                    </div>

                    <?php if (!empty($approvedCourses)): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5>Your Registered Courses</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Course Code</th>
                                                <th>Course Name</th>
                                                <th>Credits</th>
                                                <th>Level</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($approvedCourses as $ac): ?>
                                                <tr>
                                                    <td><?php echo e($ac['course_code']); ?></td>
                                                    <td><?php echo e($ac['course_name']); ?></td>
                                                    <td><?php echo e($ac['credit_hours']); ?></td>
                                                    <td><?php echo 'Year ' . e($ac['level_year']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($needsSelection): ?>
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
                                                    <input type="checkbox" name="courses[]" value="<?php echo $c['course_id']; ?>" <?php echo $isReg ? 'disabled checked' : ''; ?>>
                                                </td>
                                                <td><?php echo e($c['course_code']); ?></td>
                                                <td><?php echo e($c['course_name']); ?></td>
                                                <td><?php echo e($c['credit_hours']); ?></td>
                                                <td><?php echo e($c['level_year']); ?></td>
                                                <td>
                                                    <?php if ($isReg): ?>
                                                        <span class="badge badge-success">Registered</span>
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
                                <button name="action" value="submit_course_registration" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Submit Course Selection
                                </button>
                            </div>
                        </form>
                        </form>
                    <?php else: ?>
                        <?php if (empty($courses)): ?>
                            <div class="alert alert-info">No courses are available for selection.</div>
                        <?php else: ?>
                            <div class="alert alert-secondary">All courses for this semester have been assigned to you and are shown above.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5>Registration Process</h5>
                <ol class="mb-0">
                    <li>Select your Academic Year and Semester above</li>
                    <li>Click the <strong>Register</strong> button to request semester registration</li>
                    <li>Wait for admin approval (you will receive a notification)</li>
                    <li>Once approved, select your courses and submit</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>