<?php
/**
 * Student - View Registrations (organized by Year and Semester)
 */
require_once '../../config.php';

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

// Fetch ALL registered courses grouped by course's level_year and semester_offered
$allCoursesSql = "
    SELECT
        cr.semester_id,
        cr.course_id,
        cr.status AS reg_status,
        cr.registration_date,
        c.course_code,
        c.course_name,
        c.credit_hours,
        c.level_year,
        c.semester_offered,
        s.semester_name,
        s.semester_number,
        ay.year_name AS academic_year,
        ay.start_date AS ay_start,
        COALESCE(c.level_year, 1) AS year_of_study,
        CASE 
            WHEN c.semester_offered = 3 THEN s.semester_number
            ELSE c.semester_offered
        END AS course_semester,
        r.assignment_marks,
        r.final_exam_marks,
        r.total_marks,
        r.grade,
        r.grade_points,
        r.status AS result_status
    FROM course_registrations cr
    INNER JOIN courses c ON cr.course_id = c.id
    INNER JOIN semesters s ON cr.semester_id = s.id
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    LEFT JOIN semester_registrations sr ON sr.student_id = cr.student_id AND sr.semester_id = cr.semester_id
    LEFT JOIN results r ON r.student_id = cr.student_id AND r.course_id = cr.course_id AND r.semester_id = cr.semester_id
    WHERE cr.student_id = :student_id
      AND (c.semester_offered = s.semester_number OR c.semester_offered = 3)
      AND (
            NOT EXISTS (
                SELECT 1
                FROM semester_registrations srx
                WHERE srx.student_id = cr.student_id
                  AND srx.semester_id = cr.semester_id
                  AND srx.status = 'approved'
            )
            OR c.level_year = (
                SELECT sry.year_of_study
                FROM semester_registrations sry
                WHERE sry.student_id = cr.student_id
                  AND sry.semester_id = cr.semester_id
                  AND sry.status = 'approved'
                ORDER BY sry.id DESC
                LIMIT 1
            )
      )
    ORDER BY c.level_year ASC, c.semester_offered ASC, c.course_code ASC
";
$stmt = $conn->prepare($allCoursesSql);
$stmt->execute(['student_id' => $studentProfile['id']]);
$allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by Year of Study -> Semester Number (using course's level_year and semester_offered)
$organizedRegistrations = [];
foreach ($allCourses as $course) {
    $yos = (int)($course['year_of_study'] ?: 1);
    $sn = (int)($course['course_semester'] ?: 1);
    if ($sn < 1 || $sn > 2) $sn = 1; // Normalize to 1 or 2
    
    if (!isset($organizedRegistrations[$yos])) {
        $organizedRegistrations[$yos] = [];
    }
    if (!isset($organizedRegistrations[$yos][$sn])) {
        $organizedRegistrations[$yos][$sn] = [
            'academic_year' => $course['academic_year'],
            'semester_name' => $course['semester_name'],
            'semester_id' => $course['semester_id'],
            'courses' => []
        ];
    }
    $organizedRegistrations[$yos][$sn]['courses'][] = $course;
}

// Sort by year and semester
ksort($organizedRegistrations);
foreach ($organizedRegistrations as &$semesters) {
    ksort($semesters);
}
unset($semesters);

// Fetch unread notifications for header bell
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'My Registrations - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<style>
.academic-year-header {
    color: #2d3748;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.academic-year-header i {
    color: #4a5568;
}
.year-section {
    margin-bottom: 1.5rem;
}
.year-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2d3748;
    margin: 1rem 0 0.5rem 0;
}
.semester-header {
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
    border: 1px solid #e2e8f0;
    border-bottom: none;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.semester-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #2d3748;
}
.semester-header .badge {
    font-size: 0.75rem;
}
.reg-table { 
    width: 100%; 
    border-collapse: collapse; 
    font-size: 12px; 
    border: 1px solid #e2e8f0; 
}
.reg-table thead th { 
    background: #f8fafc; 
    padding: 8px 10px; 
    font-size: 0.8rem; 
    color: #333; 
    border-bottom: 1px solid #e2e8f0; 
    font-weight: 600; 
}
.reg-table tbody td { 
    padding: 8px 10px; 
    border-bottom: 1px solid #edf2f7; 
    vertical-align: middle; 
}
.reg-table tbody tr:hover { 
    background: #f8fafc; 
}
.semester-block {
    margin-bottom: 1rem;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-radius: 4px;
    overflow: hidden;
}
.summary-row td {
    background: #f0f0f0;
    border-top: 2px solid #e2e8f0;
    font-weight: bold;
}
</style>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>My Registrations</h4>
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

        <div class="mb-3">
            <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Register Courses
            </a>
        </div>

        <?php if (empty($organizedRegistrations)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-folder-open fa-3x mb-3"></i>
                    <h5>No Registrations Yet</h5>
                    <p>You have not registered for any courses.</p>
                    <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="btn btn-primary">Register Now</a>
                </div>
            </div>
        <?php else: ?>

            <!-- Academic Year Header -->
            <h3 class="academic-year-header">
                <i class="fas fa-graduation-cap"></i>
                <?php
                $registrationAcademicYears = [];
                foreach ($organizedRegistrations as $yearSemesters) {
                    foreach ($yearSemesters as $semesterData) {
                        $label = trim((string)($semesterData['academic_year'] ?? ''));
                        if ($label !== '') {
                            $registrationAcademicYears[$label] = true;
                        }
                    }
                }
                $registrationAcademicYearText = implode(', ', array_keys($registrationAcademicYears));
                ?>
                <?php echo !empty($registrationAcademicYearText) ? 'Academic Year ' . e($registrationAcademicYearText) : 'Academic Year -'; ?>
            </h3>

            <?php 
            // Calculate running totals for CGPA
            $cumulativeCredits = 0;
            $cumulativePoints = 0.0;
            ?>

            <?php foreach ($organizedRegistrations as $yearNum => $semesters): ?>
                <div class="year-section">
                    <h4 class="year-title">Year <?php echo $yearNum; ?></h4>
                    
                    <?php foreach ($semesters as $semNum => $data): ?>
                        <div class="semester-block">
                            <div class="semester-header">
                                <i class="fas fa-calendar-alt"></i>
                                <h5>Semester <?php echo $semNum; ?></h5>
                                <span class="badge badge-primary"><?php echo count($data['courses']); ?> Courses</span>
                            </div>
                            <div class="table-responsive">
                                <table class="reg-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th class="text-center">Year</th>
                                            <th class="text-center">CU</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">CW</th>
                                            <th class="text-center">EXM</th>
                                            <th class="text-center">TT</th>
                                            <th class="text-center">LG</th>
                                            <th class="text-center">GP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalCredits = 0;
                                        $approvedCount = 0;
                                        $semesterCredits = 0;
                                        $semesterPoints = 0.0;
                                        $allPublished = true;
                                        
                                        foreach ($data['courses'] as $c): 
                                            $totalCredits += (int)$c['credit_hours'];
                                            if ($c['reg_status'] === 'approved') $approvedCount++;
                                            
                                            $rs = $c['result_status'] ?? null;
                                            if ($rs === 'published' && $c['grade_points'] !== null) {
                                                $cw  = $c['assignment_marks'] !== null ? number_format($c['assignment_marks'], 0) : '-';
                                                $exm = $c['final_exam_marks'] !== null ? number_format($c['final_exam_marks'], 0) : '-';
                                                $tt  = $c['total_marks'] !== null ? number_format($c['total_marks'], 0) : '-';
                                                $gr  = $c['grade'] ?? '-';
                                                $gp  = number_format($c['grade_points'], 1);
                                                
                                                // Add to semester totals
                                                $cu = (int)$c['credit_hours'];
                                                $semesterCredits += $cu;
                                                $semesterPoints += ((float)$c['grade_points']) * $cu;
                                            } else {
                                                $cw = $exm = $tt = $gr = $gp = '-';
                                                $allPublished = false;
                                            }
                                        ?>
                                        <tr>
                                            <td><strong><?php echo e($c['course_code']); ?></strong></td>
                                            <td><?php echo e($c['course_name']); ?></td>
                                            <td class="text-center">Year <?php echo $yearNum; ?></td>
                                            <td class="text-center"><?php echo e($c['credit_hours']); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php echo $c['reg_status'] === 'approved' ? 'success' : ($c['reg_status'] === 'pending' ? 'warning' : 'secondary'); ?>">
                                                    <?php echo e(ucfirst($c['reg_status'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-center"><?php echo $cw; ?></td>
                                            <td class="text-center"><?php echo $exm; ?></td>
                                            <td class="text-center"><?php echo $tt; ?></td>
                                            <td class="text-center"><?php echo $gr; ?></td>
                                            <td class="text-center"><?php echo $gp; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php
                                        // Calculate SGPA for this semester
                                        $sgpa = ($semesterCredits > 0) ? ($semesterPoints / $semesterCredits) : 0;
                                        $sgpaDisplay = ($semesterCredits > 0 && $allPublished) ? number_format($sgpa, 2) : 'PA';
                                        
                                        // Add to cumulative for CGPA (only if all published)
                                        if ($semesterCredits > 0 && $allPublished) {
                                            $cumulativeCredits += $semesterCredits;
                                            $cumulativePoints += $semesterPoints;
                                        }
                                        $cgpa = ($cumulativeCredits > 0) ? ($cumulativePoints / $cumulativeCredits) : 0;
                                        $cgpaDisplay = ($cumulativeCredits > 0) ? number_format($cgpa, 2) : 'PA';
                                        ?>
                                        
                                        <!-- SGPA/CGPA Row -->
                                        <tr class="summary-row">
                                            <td colspan="3" class="text-right"><strong>Total:</strong></td>
                                            <td class="text-center"><strong><?php echo $totalCredits; ?> CU</strong></td>
                                            <td class="text-center"><?php echo $approvedCount; ?>/<?php echo count($data['courses']); ?></td>
                                            <td colspan="3" class="text-right"><strong>SGPA:</strong></td>
                                            <td colspan="2" class="text-center"><strong><?php echo $sgpaDisplay; ?></strong></td>
                                        </tr>
                                        <tr class="summary-row" style="background:#e8f4e8;">
                                            <td colspan="8" class="text-right"><strong>Cumulative GPA (CGPA):</strong></td>
                                            <td colspan="2" class="text-center"><strong style="color:#166534;"><?php echo $cgpaDisplay; ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
