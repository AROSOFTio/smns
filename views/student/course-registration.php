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

// Update semesterId logic to use semester_offered
$semesterOffered = $selectedSemesterNumber; // Use semester_number directly

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
// Specify table alias for the ambiguous 'status' column
$pendingCheckStmt = $conn->prepare("
    SELECT * FROM semester_registrations sr
    WHERE sr.student_id = :student_id 
    AND sr.semester_id = :semester_id 
    AND sr.status = 'pending'
    LIMIT 1
");
$pendingCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$pendingRequest = $pendingCheckStmt->fetch();

// Auto-create missing semester registration if not found
/*
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
*/

// Handle semester registration request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_semester_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Allow all students to register again (remove block for existing registration)
    // Optionally, you can keep a log of previous registrations if needed

    // Create semester registration request (include year_of_study)
    $insertStmt = $conn->prepare("
        INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at) 
        VALUES (:student_id, :semester_id, :year_of_study, 'pending', NOW(), NOW())
    ");
    
    try {
        // Immediately approve semester registration so students can register for courses
        $insertStmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :year_of_study, 'approved', NOW(), NOW(), NOW())");
        $insertStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId,
            'year_of_study' => isset($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : $yearOfStudy
        ]);

        // Auto-assign available courses for this semester to the student so they appear immediately
        try {
            $courseIds = [];

            // 1) Prefer admin-managed `course_assignments` if available
            try {
                $caStmt = $conn->prepare("SELECT ca.course_id FROM course_assignments ca WHERE ca.semester_id = :semester_id AND (ca.program_id = :program_id OR ca.program_id IS NULL) AND ca.year_of_study = :year_of_study AND ca.status = 'active'");
                $caStmt->execute(['semester_id' => $semesterId, 'program_id' => $studentProfile['program_id'] ?? 0, 'year_of_study' => $yearOfStudy]);
                $courseIds = $caStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $ignore) {
                // course_assignments may not exist; fall through
            }

            // 2) Fallback to `courses` table if no course_assignments found
            if (empty($courseIds)) {
                $coursesStmt = $conn->prepare("SELECT id FROM courses WHERE semester_offered = :semester_offered AND status = 'active' AND (program_id = :program_id OR program_id IS NULL) AND (level_year = :level_year OR level_year IS NULL)");
                $coursesStmt->execute(['semester_offered' => $selectedSemesterNumber, 'program_id' => $studentProfile['program_id'] ?? 0, 'level_year' => $yearOfStudy]);
                $courseIds = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // 3) If still empty, use demo courses (small hardcoded set)
            if (empty($courseIds)) {
                $demoCourses = [
                    ['id' => 0, 'course_code' => 'DEMO101', 'course_name' => 'Introductory Course', 'credit_hours' => 3],
                    ['id' => -1, 'course_code' => 'DEMO102', 'course_name' => 'Sample Course II', 'credit_hours' => 3]
                ];

                // Insert demo rows into course_registrations using placeholder course ids (-1,0) and skip duplicate checks
                $insCourseStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");
                foreach ($demoCourses as $dc) {
                    try {
                        $insCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $dc['id'], 'semester_id' => $semesterId]);
                    } catch (Exception $e) {
                        // ignore demo insert errors
                    }
                }
            } else {
                $checkCourseStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
                $insCourseStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

                foreach ($courseIds as $cid) {
                    $checkCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    if ($checkCourseStmt->fetch()) continue;
                    $insCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                }
            }
        } catch (PDOException $innerEx) {
            // non-fatal: if course auto-assign fails, continue — student can still select courses manually
        }

        $session->setFlash('success', 'Semester registration submitted and approved. Your courses for this semester have been assigned and are visible below.');
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

        // Server-side guard: if courses were already assigned (approved) or admin-managed
        // course_assignments exist for this semester/program/year, disallow manual selection.
        try {
            $assignedCountStmt = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'approved'");
            $assignedCountStmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
            if ($assignedCountStmt->fetchColumn() > 0) {
                $session->setFlash('error', 'Your courses have already been assigned for this semester. Selection is disabled.');
                header('Location: course-registration.php?semester_id=' . $semesterId);
                exit;
            }
        } catch (Exception $e) {
            // ignore and continue
        }

        try {
            $caCheck = $conn->prepare("SELECT COUNT(*) FROM course_assignments WHERE semester_id = :semester_id AND (program_id = :program_id OR program_id IS NULL) AND year_of_study = :year_of_study AND status = 'active'");
            $caCheck->execute(['semester_id' => $semesterId, 'program_id' => $studentProfile['program_id'] ?? 0, 'year_of_study' => $yearOfStudy]);
            if ($caCheck->fetchColumn() > 0) {
                $session->setFlash('error', 'Courses for this semester are assigned by the administration; manual selection is disabled.');
                header('Location: course-registration.php?semester_id=' . $semesterId);
                exit;
            }
        } catch (Exception $e) {
            // if course_assignments table missing, ignore
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

// Fetch available courses: prefer admin `course_assignments`, fall back to `courses`, then to demo list
$availableCourses = [];
$fromAssignments = false;
try {
    $caStmt = $conn->prepare("SELECT c.* FROM course_assignments ca JOIN courses c ON ca.course_id = c.id WHERE ca.semester_id = :semester_id AND (ca.program_id = :program_id OR ca.program_id IS NULL) AND ca.year_of_study = :year_of_study AND ca.status = 'active' AND c.status = 'active' ORDER BY c.course_code");
    $caStmt->execute(['semester_id' => $semesterId, 'program_id' => $studentProfile['program_id'] ?? 0, 'year_of_study' => $yearOfStudy]);
    $availableCourses = $caStmt->fetchAll();
    $fromAssignments = !empty($availableCourses);
} catch (Exception $e) {
    // ignore if course_assignments table missing
}

if (empty($availableCourses)) {
    $availableCoursesStmt = $conn->prepare("SELECT * FROM courses WHERE semester_offered = :semester_offered AND status = 'active' AND (program_id = :program_id OR program_id IS NULL) AND (level_year = :level_year OR level_year IS NULL)");
    $availableCoursesStmt->execute([
        'semester_offered' => $selectedSemesterNumber,
        'program_id' => $studentProfile['program_id'] ?? 0,
        'level_year' => $yearOfStudy
    ]);
    $availableCourses = $availableCoursesStmt->fetchAll();
}

// If still empty, use demo courses so student sees something
if (empty($availableCourses)) {
    $availableCourses = [
        ['id' => 0, 'course_code' => 'DEMO101', 'course_name' => 'Introductory Course', 'credit_hours' => 3, 'level_year' => $yearOfStudy, 'semester_offered' => $selectedSemesterNumber, 'status' => 'active'],
        ['id' => -1, 'course_code' => 'DEMO102', 'course_name' => 'Sample Course II', 'credit_hours' => 3, 'level_year' => $yearOfStudy, 'semester_offered' => $selectedSemesterNumber, 'status' => 'active']
    ];
}

// Already registered courses for this student & semester
$registered = [];
if ($semesterId && $semesterApproval) {
    $rstmt = $conn->prepare("SELECT course_id, status FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    while ($r = $rstmt->fetch()) {
        $registered[$r['course_id']] = $r['status'] ?? null; // Ensure 'course_id' exists
    }
}

// Determine if student still needs to select any courses (i.e., there are available courses not yet registered)
$needsSelection = false;
if (!empty($availableCourses)) {
    foreach ($availableCourses as $c) {
        if (!isset($registered[$c['id']])) { $needsSelection = true; break; }
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

// Determine whether selection should be allowed. If courses were assigned by admin
// (`course_assignments`) or the student already has approved course_registrations,
// do not allow manual selection — show read-only list instead.
$selectionAllowed = true;
if (!empty($approvedCourses) || $fromAssignments) {
    $selectionAllowed = false;
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
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="background:#ffb6d5;color:#2b0a2b;font-weight:600;">
                <?php echo e($session->getFlash('success')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="font-weight:600;">
                <?php echo e($session->getFlash('error')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($session->getFlash('info')): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert" style="font-weight:600;">
                <?php echo e($session->getFlash('info')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <form id="courseFilterForm" method="GET" class="mb-3">
                    <?php echo csrfField(); ?>
                    <div class="d-flex flex-wrap align-items-center">
                        <div style="flex:1; min-width:280px; max-width:880px;">
                            <div class="form-row">
                                <div class="form-group col-12 col-md-4 d-flex align-items-center">
                                    <label class="mb-0 mr-3" style="min-width:140px; color:#374151; font-weight:600;">Academic year</label>
                                    <select name="academic_year_id" class="form-control">
                                        <?php foreach ($academicYears as $ay): ?>
                                            <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group col-12 col-md-4 d-flex align-items-center">
                                    <label class="mb-0 mr-3" style="min-width:140px; color:#374151; font-weight:600;">Semester</label>
                                    <select name="semester_number" class="form-control">
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div class="form-group col-12 col-md-4 d-flex align-items-center">
                                    <label class="mb-0 mr-3" style="min-width:140px; color:#374151; font-weight:600;">Year of study</label>
                                                    <select id="year_of_study_select" name="year_of_study" class="form-control">
                                                        <?php for ($y = 1; $y <= 4; $y++): ?>
                                                            <option value="<?php echo $y; ?>" <?php echo $yearOfStudy == $y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="ml-3 mt-2 mt-md-0">
                            <button type="button" id="requestRegisterBtn" class="btn btn-success sticky-register-btn" style="min-width:110px; height:48px;">
                                <i class="fas fa-paper-plane"></i> Register
                            </button>
                        </div>
                        <style>
                        /* Sticky Register Button Styles */
                        .sticky-register-btn {
                            position: fixed;
                            bottom: 30px;
                            right: 40px;
                            z-index: 2000;
                            background: #28a745;
                            color: #fff;
                            box-shadow: 0 4px 16px rgba(40,167,69,0.15);
                            border-radius: 32px;
                            font-size: 18px;
                            font-weight: 600;
                            padding: 16px 36px;
                            transition: background 0.3s, color 0.3s, box-shadow 0.3s;
                            opacity: 0.98;
                        }
                        .sticky-register-btn:active,
                        .sticky-register-btn:focus {
                            outline: none;
                            box-shadow: 0 0 0 4px rgba(40,167,69,0.15);
                        }
                        .sticky-register-btn.registered {
                            background: #90ee90 !important; /* Light green */
                            color: #155724 !important;
                            box-shadow: 0 2px 8px rgba(144,238,144,0.18);
                            cursor: default;
                        }
                        @media (max-width: 600px) {
                            .sticky-register-btn {
                                right: 10px;
                                left: 10px;
                                width: calc(100vw - 20px);
                                padding: 14px 0;
                                font-size: 16px;
                            }
                        }
                        </style>
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

                    // Always show the sticky register button on every page load
                    // Only turn it light green and disable if a registration was just submitted (success flash)
                    var justRegistered = <?php echo ($session->hasFlash('success') ? 'true' : 'false'); ?>;
                    if (justRegistered) {
                        btn.classList.add('registered');
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-check"></i> Registered';
                    } else {
                        btn.classList.remove('registered');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Register';
                    }

                    btn.addEventListener('click', function(e) {
                        // show immediate client-side alert and disable the button to indicate action
                        var cardBody = btn.closest('.card-body') || document.querySelector('.content-area .card .card-body');
                        if (cardBody) {
                                var existing = document.getElementById('clientRegistrationAlert');
                                if (existing) existing.remove();
                                var alert = document.createElement('div');
                                alert.id = 'clientRegistrationAlert';
                                // prominent pink alert, stay longer and dismissible
                                alert.className = 'alert alert-success alert-dismissible fade show';
                                alert.role = 'alert';
                                alert.style.background = '#ffb6d5';
                                alert.style.color = '#2b0a2b';
                                alert.style.fontWeight = '700';
                                alert.style.boxShadow = '0 6px 18px rgba(0,0,0,0.12)';
                                alert.innerText = 'Registering you for this semester...';
                                // add close button
                                var closeBtn = document.createElement('button');
                                closeBtn.type = 'button';
                                closeBtn.className = 'close';
                                closeBtn.innerHTML = '&times;';
                                closeBtn.style.border = 'none';
                                closeBtn.style.background = 'transparent';
                                closeBtn.style.fontSize = '22px';
                                closeBtn.onclick = function() { alert.remove(); };
                                alert.appendChild(closeBtn);
                                cardBody.insertBefore(alert, cardBody.firstChild);
                                // scroll into view so it's seen
                                alert.scrollIntoView({behavior: 'smooth', block: 'center'});
                                // keep visible longer (remove after 12s)
                                setTimeout(function(){ var el = document.getElementById('clientRegistrationAlert'); if (el) el.remove(); }, 12000);
                            }

                            btn.disabled = true;
                            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';

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

                <?php if (!$semesterApproval): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Click the <strong>Register</strong> button above to register for this semester. You will be able to select your courses immediately after registering.
                    </div>

                    <?php if (!empty($availableCourses)): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5>Courses Offered This Semester</h5>
                                <div class="table-responsive">
                                    <table id="coursesTable" class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>S/N</th>
                                                <th>Course Code</th>
                                                <th>Course Name</th>
                                                <th>Semester</th>
                                                <th>Year</th>
                                                <th>CU</th>
                                                <th>LH</th>
                                                <th>TH</th>
                                                <th>PH</th>
                                                <th>CH</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $sn=1; foreach ($availableCourses as $c): ?>
                                                <tr>
                                                    <td><?php echo $sn++; ?></td>
                                                    <td><?php echo e($c['course_code']); ?></td>
                                <script>
                                (function(){
                                    var form = document.getElementById('courseFilterForm');
                                    if (!form) return;
                                    var selects = form.querySelectorAll('select[name="academic_year_id"], select[name="semester_number"], select[name="year_of_study"]');
                                    var timeout = null;

                                    function saveFilters() {
                                        var data = {};
                                        selects.forEach(function(s){ data[s.name] = s.value; });
                                        try { localStorage.setItem('smns_course_filters', JSON.stringify(data)); } catch(e) {}
                                    }

                                    function debouncedSubmit() {
                                        clearTimeout(timeout);
                                        timeout = setTimeout(function(){
                                            saveFilters();
                                            form.submit();
                                        }, 450);
                                    }

                                    selects.forEach(function(s){ s.addEventListener('change', debouncedSubmit); });

                                    // Restore selections from localStorage if server didn't preserve them
                                    try {
                                        var stored = JSON.parse(localStorage.getItem('smns_course_filters') || '{}');
                                        if (stored && Object.keys(stored).length) {
                                            selects.forEach(function(s){ if (stored[s.name] && s.value !== stored[s.name]) s.value = stored[s.name]; });
                                        }
                                    } catch(e) {}
                                })();
                                </script>
                                                    <td><?php echo e($c['course_name']); ?></td>
                                                    <td><?php echo ($c['semester_offered'] == 3) ? 'Both' : 'Semester ' . e($c['semester_offered']); ?></td>
                                                    <td><?php echo e($c['level_year']); ?></td>
                                                    <td><?php echo e($c['credit_hours']); ?></td>
                                                    <td>0</td>
                                                    <td>0</td>
                                                    <td>0</td>
                                                    <td>0</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">No courses are available for this semester.</div>
                    <?php endif; ?>

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

                    <?php if ($needsSelection && $selectionAllowed): ?>
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
                                        <?php foreach ($availableCourses as $c): ?>
                                            <?php $isReg = isset($registered[$c['id']]); ?>
                                            <tr>
                                                <td style="width:40px;">
                                                    <input type="checkbox" name="courses[]" value="<?php echo $c['id']; ?>" <?php echo $isReg ? 'disabled checked' : ''; ?>>
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
                            <?php if (!$selectionAllowed && !empty($availableCourses)): ?>
                                <div class="alert alert-info">Courses for this semester have been assigned by the administration and cannot be changed. They will remain until the semester ends.</div>
                            <?php elseif (empty($availableCourses)): ?>
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
                    <li>Select your courses and submit (registration is approved immediately)</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>