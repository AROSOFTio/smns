<?php
/**
 * Admin - View Student Results Slip
 *
 * Shows a personal information header (provisional results slip style)
 * for a selected student, then lists their published results
 * for a selected academic year and semester.
 */
require_once '../../../config.php';
require_once '../../../includes/functions.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db          = new Database();
$conn        = $db->getConnection();

// ---------------------------------------------------------------------
// Get Student Profile
// ---------------------------------------------------------------------
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if (!$studentId) {
    $session->setFlash('error', 'No student selected.');
    header('Location: student-results.php');
    exit;
}

$studentStmt = $conn->prepare("SELECT * FROM students WHERE id = :id");
$studentStmt->execute(['id' => $studentId]);
$studentProfile = $studentStmt->fetch();

if (!$studentProfile) {
    $session->setFlash('error', 'Student not found.');
    header('Location: student-results.php');
    exit;
}

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
$semesterId   = $semesterRow['id'] ?? 0;
$semesterName = $semesterRow['semester_name'] ?? 'Not Set';

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

// Academic status as of print date
$academicStatus = 'Not Registered';
if ($semesterId) {
    try {
        $regStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM course_registrations WHERE student_id = :sid AND semester_id = :semid AND status = 'approved'");
        $regStmt->execute(['sid' => $studentProfile['id'], 'semid' => $semesterId]);
        $registered = (int) ($regStmt->fetch()['cnt'] ?? 0);

        if ($registered > 0) {
            $resStmt = $conn->prepare("SELECT COUNT(DISTINCT course_id) AS cnt FROM results WHERE student_id = :sid AND semester_id = :semid AND status = 'published'");
            $resStmt->execute(['sid' => $studentProfile['id'], 'semid' => $semesterId]);
            $published = (int) ($resStmt->fetch()['cnt'] ?? 0);

            if ($published >= $registered) $academicStatus = 'Complete';
            elseif ($published > 0) $academicStatus = 'In Progress';
            else $academicStatus = 'Incomplete';
        }
    } catch (Exception $e) {
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
                cr.course_id, c.course_code, c.course_name, c.credit_hours,
                r.assignment_marks, r.final_exam_marks, r.total_marks, r.grade, r.grade_points, r.status AS result_status
            FROM course_registrations cr
            INNER JOIN courses c ON cr.course_id = c.id
            LEFT JOIN results r ON r.student_id = cr.student_id AND r.course_id = cr.course_id AND r.semester_id = cr.semester_id
            WHERE cr.student_id = :sid AND cr.semester_id = :semid AND cr.status = 'approved'
            ORDER BY c.course_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['sid' => $studentProfile['id'], 'semid' => $semesterId]);
    $resultsData = $stmt->fetchAll();

    foreach ($resultsData as $row) {
        $results[] = $row;
        $totalRegisteredCourses++;
        if ($row['result_status'] === 'published') {
            $fullyPublishedCourses++;
            if (is_numeric($row['grade_points']) && is_numeric($row['credit_hours'])) {
                $gpaCredits += $row['credit_hours'];
                $gpaPoints  += $row['grade_points'] * $row['credit_hours'];
            }
        }
    }
}

$gpa = ($gpaCredits > 0) ? round($gpaPoints / $gpaCredits, 2) : 0.0;

// ---------------------------------------------------------------------
// CGPA Calculation
// ---------------------------------------------------------------------
$cgpaCredits = 0;
$cgpaPoints  = 0.0;

$cgpaStmt = $conn->prepare("SELECT r.grade_points, c.credit_hours
                            FROM results r
                            JOIN courses c ON r.course_id = c.id
                            WHERE r.student_id = :sid AND r.status = 'published'");
$cgpaStmt->execute(['sid' => $studentProfile['id']]);
$allResults = $cgpaStmt->fetchAll();

foreach ($allResults as $res) {
    if (is_numeric($res['grade_points']) && is_numeric($res['credit_hours'])) {
        $cgpaCredits += $res['credit_hours'];
        $cgpaPoints  += $res['grade_points'] * $res['credit_hours'];
    }
}
$cgpa = ($cgpaCredits > 0) ? round($cgpaPoints / $cgpaCredits, 2) : 0.0;


$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);
$pageTitle = 'Student Results Slip - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-file-invoice"></i> Student Results Slip</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="student-results.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Student List</a>
            <button onclick="window.print();" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Print Results</button>
        </div>

        <!-- Filter Form -->
        <div class="card mb-3 no-print">
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                    <div class="form-group mb-2 mr-sm-2">
                        <label for="academic_year_id" class="mr-2">Academic Year:</label>
                        <select name="academic_year_id" id="academic_year_id" class="form-control">
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo $ay['id']; ?>" <?php echo ($ay['id'] == $selectedAcademicYearId) ? 'selected' : ''; ?>>
                                    <?php echo e($ay['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-2 mr-sm-2">
                        <label for="semester_number" class="mr-2">Semester:</label>
                        <select name="semester_number" id="semester_number" class="form-control">
                            <option value="1" <?php echo ($selectedSemesterNumber == 1) ? 'selected' : ''; ?>>Semester 1</option>
                            <option value="2" <?php echo ($selectedSemesterNumber == 2) ? 'selected' : ''; ?>>Semester 2</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary mb-2">View</button>
                </form>
            </div>
        </div>

        <!-- Results Slip -->
        <div class="card" id="results-slip">
            <div class="card-body">
                <div class="results-header text-center mb-4">
                    <img src="<?php echo BASE_URL; ?>/assets/img/logo.png" alt="University Logo" style="max-width: 100px;">
                    <h4 class="mt-2 mb-0"><?php echo e(APP_NAME); ?></h4>
                    <p class="mb-0">Office of the Academic Registrar</p>
                    <h5>PROVISIONAL SEMESTER RESULTS</h5>
                </div>

                <!-- Student Details -->
                <table class="table table-sm table-bordered student-details-table mb-4">
                    <tbody>
                        <tr>
                            <th>Student Name:</th>
                            <td><?php echo e(strtoupper($studentProfile['first_name'] . ' ' . $studentProfile['other_name'] . ' ' . $studentProfile['last_name'])); ?></td>
                            <th>Reg No:</th>
                            <td><?php echo e(strtoupper($studentProfile['student_id'])); ?></td>
                        </tr>
                        <tr>
                            <th>Program:</th>
                            <td><?php echo e(strtoupper($programInfo['program_name'] ?? '-')); ?></td>
                            <th>Intake:</th>
                            <td><?php echo e(strtoupper($intakeLabel)); ?></td>
                        </tr>
                        <tr>
                            <th>Academic Year:</th>
                            <td><?php echo e($selectedAcademicYearName); ?></td>
                            <th>Semester:</th>
                            <td><?php echo e(strtoupper($semesterName)); ?></td>
                        </tr>
                         <tr>
                            <th>Date of Print:</th>
                            <td><?php echo date('d-M-Y'); ?></td>
                            <th>Academic Status:</th>
                            <td><?php echo e(strtoupper($academicStatus)); ?></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Results Table -->
                <?php if (empty($results)): ?>
                    <p class="text-center text-muted mt-4">No results found for the selected semester. The student may not have registered for courses or results have not been published.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered results-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>COURSE CODE</th>
                                    <th>COURSE TITLE</th>
                                    <th>CW</th>
                                    <th>EXM</th>
                                    <th>TT</th>
                                    <th>CU</th>
                                    <th>LG</th>
                                    <th>GP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr>
                                        <td><?php echo e($result['course_code']); ?></td>
                                        <td><?php echo e($result['course_name']); ?></td>
                                        <?php if ($result['result_status'] === 'published'): ?>
                                            <td class="text-center"><?php echo $result['assignment_marks'] !== null ? round($result['assignment_marks']) : '-'; ?></td>
                                            <td class="text-center"><?php echo $result['final_exam_marks'] !== null ? round($result['final_exam_marks']) : '-'; ?></td>
                                            <td class="text-center"><?php echo $result['total_marks'] !== null ? round($result['total_marks']) : '-'; ?></td>
                                            <td class="text-center"><?php echo e($result['credit_hours']); ?></td>
                                            <td class="text-center"><?php echo e($result['grade']); ?></td>
                                            <td class="text-center"><?php echo e(number_format($result['grade_points'], 2)); ?></td>
                                        <?php else: ?>
                                            <td colspan="6" class="text-center text-muted"><i>Not Published</i></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- GPA/CGPA Summary -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <table class="table table-sm table-bordered summary-table">
                            <tr>
                                <th>SEMESTER GPA</th>
                                <td><?php echo number_format($gpa, 2); ?></td>
                            </tr>
                            <tr>
                                <th>CUMULATIVE GPA (CGPA)</th>
                                <td><?php echo number_format($cgpa, 2); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6 text-right">
                        <p class="mt-4">_________________________<br><strong>ACADEMIC REGISTRAR</strong></p>
                    </div>
                </div>

                <!-- Key to Grades -->
                <div class="key-to-grades mt-4">
                    <h6>Key to Abbreviations & Grades:</h6>
                    <p style="font-size: 0.8rem;">
                        <strong>CW</strong> = Coursework/Assignment (40%), 
                        <strong>EXM</strong> = Exam (60%), 
                        <strong>TT</strong> = Total Marks (100%), 
                        <strong>CU</strong> = Credit Units, 
                        <strong>LG</strong> = Letter Grade, 
                        <strong>GP</strong> = Grade Points
                    </p>
                    <p style="font-size: 0.8rem;">
                        <strong>A</strong> (80-100, GP 5.0), 
                        <strong>B+</strong> (75-79, GP 4.5), 
                        <strong>B</strong> (70-74, GP 4.0), 
                        <strong>C+</strong> (65-69, GP 3.5), 
                        <strong>C</strong> (60-64, GP 3.0), 
                        <strong>D+</strong> (55-59, GP 2.5), 
                        <strong>D</strong> (50-54, GP 2.0), 
                        <strong>E</strong> (40-49, GP 1.0, Retake), 
                        <strong>F</strong> (0-39, GP 0.0, Retake)
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
body, html {
    overflow-x: hidden;
    max-width: 100%;
}
.main-content {
    max-width: 100%;
    overflow-x: hidden;
}
.content-area {
    max-width: 100%;
    overflow-x: hidden;
}
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.results-table {
    width: 100%;
    table-layout: auto;
    font-size: 0.85rem;
}
.results-table th, .results-table td {
    white-space: nowrap;
    padding: 0.5rem 0.3rem;
}
@media print {
    .no-print, .no-print * { display: none !important; }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .results-header img {
        max-width: 80px !important;
    }
}
.student-details-table th { width: 15%; }
.results-table th, .results-table td { text-align: center; }
.results-table th:nth-child(2), .results-table td:nth-child(2) { text-align: left; word-wrap: break-word; white-space: normal; max-width: 200px; }
</style>

<?php include '../../../includes/footer.php'; ?>
