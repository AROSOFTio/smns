<?php
/**
 * Student - My Results
 *
 * Shows a personal information header (provisional results slip style)
 * using the student's registered data, then lists published results
 * for a selected academic year and semester.
 */
require_once '../../config.php';

$session = new Session('student');
$auth    = new Auth('student');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser    = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

$db   = new Database();
$conn = $db->getConnection();

// ---------------------------------------------------------------------
// Filters: Academic Year + Semester (map to semester_id)
// ---------------------------------------------------------------------

$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_REQUEST['academic_year_id']) ? (int) $_REQUEST['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_REQUEST['semester_number']) ? (int) $_REQUEST['semester_number'] : (Helper::getCurrentSemester()['semester_number'] ?? 1);

$mapStmt = $conn->prepare('SELECT id, semester_name FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterRow  = $mapStmt->fetch();
$semesterId   = $semesterRow['id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
$semesterName = $semesterRow['semester_name'] ?? (Helper::getCurrentSemester()['semester_name'] ?? 'Current Semester');

// Resolve selected academic year name for header display
$selectedAcademicYearName = '';
foreach ($academicYears as $ay) {
    if ((int) $ay['id'] === (int) $selectedAcademicYearId) {
        $selectedAcademicYearName = $ay['year_name'];
        break;
    }
}

// ---------------------------------------------------------------------
// Supporting info: program / department, intake, academic status
// ---------------------------------------------------------------------

$programInfo = null;
if (!empty($studentProfile['program_id'])) {
    $pstmt = $conn->prepare("SELECT program_code, program_name, department FROM programs WHERE id = :id LIMIT 1");
    $pstmt->execute(['id' => $studentProfile['program_id']]);
    $programInfo = $pstmt->fetch();
}

// Intake label from entry_semester or entry_year
$entrySemester = null;
$intakeLabel   = '-';
if (!empty($studentProfile['entry_semester_id'])) {
    $es = $conn->prepare("SELECT s.semester_name, s.start_date, ay.year_name
                          FROM semesters s
                          JOIN academic_years ay ON s.academic_year_id = ay.id
                          WHERE s.id = :id
                          LIMIT 1");
    $es->execute(['id' => $studentProfile['entry_semester_id']]);
    $entrySemester = $es->fetch();
    if ($entrySemester && !empty($entrySemester['start_date'])) {
        $intakeLabel = date('M Y', strtotime($entrySemester['start_date']));
    }
} elseif (!empty($studentProfile['entry_year'])) {
    $intakeLabel = $studentProfile['entry_year'];
}

// Academic status as of print date: compare registered vs published for selected semester
$academicStatus = 'Not Registered';
if ($semesterId) {
    try {
        $regStmt = $conn->prepare("SELECT COUNT(*) AS cnt
                                   FROM course_registrations
                                   WHERE student_id = :sid AND semester_id = :semid AND status = 'approved'");
        $regStmt->execute([
            'sid'   => $studentProfile['id'],
            'semid' => $semesterId,
        ]);
        $registered = (int) ($regStmt->fetch()['cnt'] ?? 0);

        if ($registered > 0) {
            $resStmt = $conn->prepare("SELECT COUNT(DISTINCT course_id) AS cnt
                                       FROM results
                                       WHERE student_id = :sid AND semester_id = :semid AND status = 'published'");
            $resStmt->execute([
                'sid'   => $studentProfile['id'],
                'semid' => $semesterId,
            ]);
            $published = (int) ($resStmt->fetch()['cnt'] ?? 0);

            if ($published >= $registered) {
                $academicStatus = 'Complete';
            } elseif ($published > 0) {
                $academicStatus = 'In Progress';
            } else {
                $academicStatus = 'Incomplete';
            }
        }
    } catch (Exception $e) {
        // Fallback if any error
        $academicStatus = 'Incomplete';
    }
}

// ---------------------------------------------------------------------
// Fetch all registered courses for the semester + any results
// ---------------------------------------------------------------------

$results = [];
$totalRegisteredCourses = 0;
$fullyPublishedCourses  = 0;
$gpaCredits             = 0;
$gpaPoints              = 0.0;

if ($semesterId) {
    $sql = "SELECT 
                cr.course_id,
                c.course_code,
                c.course_name,
                c.credit_hours,
                r.assignment_marks,
                r.final_exam_marks,
                r.total_marks,
                r.grade,
                r.grade_points,
                r.status AS result_status
            FROM course_registrations cr
            INNER JOIN courses c ON cr.course_id = c.id
            LEFT JOIN results r
                ON r.student_id = cr.student_id
               AND r.course_id  = cr.course_id
               AND r.semester_id = cr.semester_id
            WHERE cr.student_id = :sid
              AND cr.semester_id = :semid
              AND cr.status = 'approved'
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        'sid'   => $studentProfile['id'],
        'semid' => $semesterId,
    ]);
    $results = $stmt->fetchAll();

    $totalRegisteredCourses = count($results);

    foreach ($results as $row) {
        if (!empty($row['result_status']) && $row['result_status'] === RESULT_PUBLISHED && $row['grade_points'] !== null) {
            $fullyPublishedCourses++;
            $gpaCredits += (int) ($row['credit_hours'] ?? 0);
            $gpaPoints  += ((float) $row['grade_points']) * (int) ($row['credit_hours'] ?? 0);
        }
    }
}

// Notifications for header bell
$stmt = $conn->prepare("SELECT * FROM notifications 
                        WHERE user_id = :user_id AND read_status = 'unread'
                        ORDER BY created_at DESC LIMIT 5");
$stmt->execute(['user_id' => $currentUser['id']]);
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'My Results - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<div class="main-content" id="mainContent" style="max-width:100vw; overflow-x:hidden;">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Results</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container py-4">
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>

        <h4 class="mb-3">Provisional results</h4>

        <!-- Student information header (like printed slip) -->
        <div class="card mb-3">
            <div class="card-body" style="font-size:14px;">
                <div class="row">
                    <div class="col-md-4 col-sm-6 mb-2">
                        <p><strong>Name:</strong> <?php echo e(strtoupper(trim(($studentProfile['first_name'] ?? '') . ' ' . ($studentProfile['last_name'] ?? '')))); ?></p>
                        <p><strong>Registration no:</strong> <?php echo e($studentProfile['student_id'] ?? '-'); ?></p>
                        <p><strong>Sex:</strong> <?php echo e($studentProfile['gender'] ?? '-'); ?></p>
                    </div>
                    <div class="col-md-4 col-sm-6 mb-2">
                        <p><strong>Nationality:</strong> <?php echo e($studentProfile['country'] ?? '-'); ?></p>
                        <p><strong>Date of birth:</strong> <?php echo !empty($studentProfile['date_of_birth']) ? date('Y-m-d', strtotime($studentProfile['date_of_birth'])) : '-'; ?></p>
                        <p><strong>Intake:</strong> <?php echo e($intakeLabel); ?></p>
                    </div>
                    <div class="col-md-4 col-sm-6 mb-2">
                        <p><strong>Entry mode:</strong> <?php echo e($studentProfile['entry_mode'] ?? 'Not set'); ?></p>
                        <p><strong>Programme:</strong> <?php echo e($programInfo['program_name'] ?? '-'); ?></p>
                        <p><strong>College/School:</strong> <?php echo e($programInfo['department'] ?? '-'); ?></p>
                    </div>
                </div>
                <hr>
                <p class="mb-0"><strong>Academic status as on print date:</strong> <?php echo e($academicStatus); ?></p>
            </div>
        </div>

        <!-- Filters for academic year / semester -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="form-inline flex-wrap">
                    <label class="mr-2 mb-1">Academic Year:</label>
                    <select name="academic_year_id" class="form-control form-control-sm mr-3 mb-1" onchange="this.form.submit();">
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2 mb-1">Semester:</label>
                    <select name="semester_number" class="form-control form-control-sm mr-3 mb-1" onchange="this.form.submit();">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $selectedSemesterNumber == $i ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
        </div>

        <!-- Results table in compact CW / EXM / TT format -->
        <div class="card">
            <div class="card-body">
                <?php if (!$semesterId): ?>
                    <p class="text-muted mb-0">No semester configured for the selected academic year / semester number.</p>
                <?php elseif (empty($results)): ?>
                    <p class="text-muted mb-0">No registered courses found for the selected semester.</p>
                <?php else: ?>
                    <div class="table-responsive" style="overflow-x:auto;">
                        <table class="table table-sm" style="font-size:12px; table-layout:fixed; width:100%;">
                            <thead>
                                <tr style="background:#e5e5e5; font-weight:600;">
                                    <th colspan="6">
                                        Year <?php echo e($studentProfile['level_year'] ?? '-'); ?> Semester <?php echo e($selectedSemesterNumber); ?>
                                    </th>
                                    <th colspan="2" class="text-right"><?php echo e($selectedAcademicYearName); ?></th>
                                </tr>
                                <tr>
                                    <th style="width:80px;">Course code</th>
                                    <th style="width:40%; word-wrap:break-word;">Course title</th>
                                    <th style="width:55px;" class="text-center">CW</th>
                                    <th style="width:55px;" class="text-center">EXM</th>
                                    <th style="width:55px;" class="text-center">TT</th>
                                    <th style="width:55px;" class="text-center">CU</th>
                                    <th style="width:55px;" class="text-center">LG</th>
                                    <th style="width:55px;" class="text-center">GP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $r): ?>
                                    <?php
                                        $status = $r['result_status'] ?? null;
                                        // Decide display codes for marks
                                        if ($status === RESULT_PUBLISHED) {
                                            $cw  = $r['assignment_marks'] !== null ? number_format($r['assignment_marks'], 0) : 'PA';
                                            $exm = $r['final_exam_marks'] !== null ? number_format($r['final_exam_marks'], 0) : 'PA';
                                            $tt  = $r['total_marks'] !== null ? number_format($r['total_marks'], 0) : 'PA';
                                            $lg  = $r['grade'] ?? 'PA';
                                            $gp  = $r['grade_points'] !== null ? number_format($r['grade_points'], 2) : 'PA';
                                        } elseif ($status !== null) {
                                            // Result exists but not yet published
                                            $cw = $exm = $tt = $lg = $gp = 'PA';
                                        } else {
                                            // No result row yet
                                            $cw = $exm = $tt = $lg = $gp = 'PA';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo e($r['course_code']); ?></td>
                                        <td><?php echo e($r['course_name']); ?></td>
                                        <td class="text-center"><?php echo e($cw); ?></td>
                                        <td class="text-center"><?php echo e($exm); ?></td>
                                        <td class="text-center"><?php echo e($tt); ?></td>
                                        <td class="text-center"><?php echo e($r['credit_hours']); ?></td>
                                        <td class="text-center"><?php echo e($lg); ?></td>
                                        <td class="text-center"><?php echo e($gp); ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php
                                // Semester GPA row (SGPA). Show numeric only when all courses are fully published
                                $sgpaDisplay = 'PA';
                                if ($totalRegisteredCourses > 0 && $fullyPublishedCourses === $totalRegisteredCourses && $gpaCredits > 0) {
                                    $sgpaDisplay = number_format($gpaPoints / $gpaCredits, 2);
                                }
                                ?>
                                <tr>
                                    <td colspan="6" class="text-right font-weight-bold">SGPA</td>
                                    <td colspan="2" class="text-center font-weight-bold"><?php echo e($sgpaDisplay); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3" style="font-size:12px;">
                        <p class="mb-1"><strong>Legend:</strong></p>
                        <p class="mb-0">PA = Pending Approval / Marks Not Entered &nbsp;&nbsp; FF = Financial Flag &nbsp;&nbsp; DF = Disciplinary Flag</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
