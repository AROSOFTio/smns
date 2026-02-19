<?php
/**
 * Student - My Results (Cumulative Academic Record)
 *
 * Shows ALL results from Year 1 Semester 1 through the current year,
 * organized by Year of Study → Semester.
 * Calculates SGPA per semester, running CGPA, and shows
 * Promotion (CGPA >= 2.00) or Retention (CGPA < 2.00) status.
 * Unpublished results display "PA" (Pending Assessment).
 */
require_once '../../config.php';

$session = new Session('student');
$auth    = new Auth('student');

// Verify student access
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser    = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

$db   = new Database();
$conn = $db->getConnection();

// ---------------------------------------------------------------------
// Supporting info: program / department, intake
// ---------------------------------------------------------------------

$programInfo = null;
if (!empty($studentProfile['program_id'])) {
    $pstmt = $conn->prepare("SELECT program_code, program_name, department FROM programs WHERE id = :id LIMIT 1");
    $pstmt->execute(['id' => $studentProfile['program_id']]);
    $programInfo = $pstmt->fetch();
}

$entrySemester = null;
$intakeLabel   = '-';
if (!empty($studentProfile['entry_semester_id'])) {
    $es = $conn->prepare("SELECT s.semester_name, s.start_date, ay.year_name
                          FROM semesters s
                          JOIN academic_years ay ON s.academic_year_id = ay.id
                          WHERE s.id = :id LIMIT 1");
    $es->execute(['id' => $studentProfile['entry_semester_id']]);
    $entrySemester = $es->fetch();
    if ($entrySemester && !empty($entrySemester['start_date'])) {
        $intakeLabel = date('M Y', strtotime($entrySemester['start_date']));
    }
} elseif (!empty($studentProfile['entry_year'])) {
    $intakeLabel = $studentProfile['entry_year'];
}

// ---------------------------------------------------------------------
// Fetch ALL registered courses + results across ALL semesters
// Use courses.level_year for year and courses.semester_offered for semester
// ---------------------------------------------------------------------

$allResultsSql = "
    SELECT
        cr.semester_id,
        s.semester_name,
        s.semester_number,
        ay.year_name                      AS academic_year,
        ay.start_date                     AS ay_start,
        COALESCE(sr.year_of_study, c.level_year, 1) AS year_of_study,
        s.semester_number                 AS course_semester,
        c.course_code,
        c.course_name,
        c.credit_hours,
        r.assignment_marks,
        r.final_exam_marks,
        r.total_marks,
        r.grade,
        r.grade_points,
        r.status                          AS result_status
    FROM course_registrations cr
    INNER JOIN (
        SELECT student_id, course_id, MAX(semester_id) AS latest_semester_id
        FROM course_registrations
        WHERE student_id = :sid1
          AND status IN ('pending', 'approved')
        GROUP BY student_id, course_id
    ) latest ON cr.student_id = latest.student_id
            AND cr.course_id  = latest.course_id
            AND cr.semester_id = latest.latest_semester_id
    INNER JOIN courses c    ON cr.course_id = c.id
    INNER JOIN semesters s  ON cr.semester_id = s.id
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    LEFT JOIN semester_registrations sr
        ON sr.student_id = cr.student_id AND sr.semester_id = cr.semester_id
    LEFT JOIN results r
        ON r.student_id  = cr.student_id
       AND r.course_id   = cr.course_id
       AND r.semester_id  = cr.semester_id
    WHERE cr.student_id = :sid2
      AND cr.status IN ('pending', 'approved')
    ORDER BY COALESCE(sr.year_of_study, c.level_year, 1) ASC, s.semester_number ASC, c.course_code ASC
";
$allStmt = $conn->prepare($allResultsSql);
$allStmt->execute(['sid1' => $studentProfile['id'], 'sid2' => $studentProfile['id']]);
$allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC);

// Group: [year_of_study][course_semester] => { academic_year, semester_name, courses[], semester_id }
// Deduplicate: track seen course_codes per block to avoid repeats
$grouped = [];
$seenCourses = []; // key => [course_code => true]
foreach ($allRows as $row) {
    $yos = (int)$row['year_of_study'] ?: 1;
    $sn  = (int)$row['course_semester'];
    // Normalize to Semester 1 or 2 per study year.
    // In our schema, semester_number runs 1..8 across all years,
    // so map odd numbers to 1 and even numbers to 2.
    if ($sn < 1) {
        $sn = 1;
    } elseif ($sn > 2) {
        $sn = ($sn % 2 === 1) ? 1 : 2;
    }
    $key = $yos . '-' . $sn;
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'year_of_study'   => $yos,
            'semester_number' => $sn,
            'academic_year'   => $row['academic_year'],
            'semester_name'   => $row['semester_name'],
            'semester_id'     => $row['semester_id'],
            'ay_start'        => $row['ay_start'],
            'courses'         => [],
        ];
        $seenCourses[$key] = [];
    }
    // Skip duplicate course codes within the same year-semester block
    $cc = $row['course_code'];
    if (isset($seenCourses[$key][$cc])) continue;
    $seenCourses[$key][$cc] = true;
    $grouped[$key]['courses'][] = $row;
}

// Sort by year_of_study ASC, semester_number ASC
uasort($grouped, function($a, $b) {
    if ($a['year_of_study'] !== $b['year_of_study']) return $a['year_of_study'] - $b['year_of_study'];
    return $a['semester_number'] - $b['semester_number'];
});

// ---------------------------------------------------------------------
// Calculate SGPA per block and running CGPA + promotion/retention
// ---------------------------------------------------------------------
$cumulativeCredits = 0;
$cumulativePoints  = 0.0;
$semesterBlocks    = [];

foreach ($grouped as $key => &$block) {
    $semCredits   = 0;
    $semPoints    = 0.0;
    $semTotal     = count($block['courses']);
    $semPublished = 0;

    foreach ($block['courses'] as $c) {
        if (!empty($c['result_status']) && $c['result_status'] === RESULT_PUBLISHED && $c['grade_points'] !== null) {
            $semPublished++;
            $cu          = (int)($c['credit_hours'] ?? 0);
            $semCredits += $cu;
            $semPoints  += ((float)$c['grade_points']) * $cu;
        }
    }

    $allPublished = ($semTotal > 0 && $semPublished === $semTotal && $semCredits > 0);

    // SGPA
    $block['sgpa']         = $allPublished ? ($semPoints / $semCredits) : null;
    $block['sgpa_display'] = $allPublished ? number_format($semPoints / $semCredits, 2) : 'PA';
    $block['total_credits']= $semCredits;

    // Running CGPA (only accumulate when fully published)
    if ($allPublished) {
        $cumulativeCredits += $semCredits;
        $cumulativePoints  += $semPoints;
    }

    $cgpaValue             = ($cumulativeCredits > 0) ? ($cumulativePoints / $cumulativeCredits) : null;
    $block['cgpa']         = $cgpaValue;
    $block['cgpa_display'] = ($cgpaValue !== null && $allPublished)
                                ? number_format($cgpaValue, 2)
                                : 'PA';

    // Promotion / Retention / Standing decision
    $block['decision'] = null;
    if ($allPublished && $cgpaValue !== null) {
        if ($block['semester_number'] == 2) {
            // End of academic year: decide promotion vs retention
            $block['decision'] = ($cgpaValue >= 2.00) ? 'Promoted' : 'Retained';
        } else {
            // Mid-year (Semester 1): show standing
            $block['decision'] = ($cgpaValue >= 2.00) ? 'Good Standing' : 'Probation';
        }
    }

    $semesterBlocks[$key] = $block;
}
unset($block);

// Overall academic status
$overallCGPA = ($cumulativeCredits > 0) ? ($cumulativePoints / $cumulativeCredits) : null;
if ($overallCGPA !== null) {
    if ($overallCGPA >= 2.00) {
        $academicStatus = 'Good Standing';
    } elseif ($overallCGPA >= 1.50) {
        $academicStatus = 'Probation';
    } else {
        $academicStatus = 'At Risk';
    }
} else {
    $academicStatus = empty($semesterBlocks) ? 'Not Registered' : 'In Progress';
}

// Notifications
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'My Results - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<style>
.results-section-header {
    color: #2d3748;
    font-size: 1.4rem;
    font-weight: 700;
    margin-bottom: 1.25rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.results-section-header i { color: #4a5568; }

.year-section { margin-bottom: 2rem; }

.year-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2d3748;
    margin: 0.75rem 0 0.5rem 0;
    padding: 0.4rem 0.75rem;
    background: #edf2f7;
    border-left: 4px solid #4a5568;
    border-radius: 2px;
}

.results-card {
    margin-bottom: 1rem;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-radius: 4px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}

.semester-header {
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 0.6rem 1rem;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.semester-header h5 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #2d3748;
}
.semester-header .badge { font-size: 0.72rem; }

/* GPA pills in semester header */
.gpa-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 700;
}
.gpa-pill-sgpa { background: #dbeafe; color: #1e3a8a; border: 1px solid #93c5fd; }
.gpa-pill-cgpa { background: #f0fdf4; color: #14532d; border: 1px solid #86efac; }
.gpa-pill-pa   { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.decision-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.decision-promoted  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.decision-good      { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
.decision-probation { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.decision-retained  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.decision-pa        { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.results-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.results-table thead th {
    background: #f8fafc;
    padding: 7px 10px;
    font-size: 0.78rem;
    color: #333;
    border-bottom: 2px solid #e2e8f0;
    font-weight: 600;
    white-space: nowrap;
}
.results-table tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle;
}
.results-table tbody tr:last-child td { border-bottom: none; }
.results-table tbody tr:hover { background: #f8fafc; }

.sgpa-summary-row td {
    background: #f0f4f8;
    font-weight: 700;
    font-size: 0.8rem;
    border-top: 2px solid #cbd5e1;
    padding: 8px 10px;
}

.overall-card {
    background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
    border: 2px solid #86efac;
    border-radius: 8px;
    padding: 16px 20px;
}
.overall-card.at-risk   { background: linear-gradient(135deg, #fef2f2, #fff1f2); border-color: #fca5a5; }
.overall-card.probation { background: linear-gradient(135deg, #fffbeb, #fef3c7); border-color: #fcd34d; }
</style>

<div class="main-content" id="mainContent" style="max-width:100vw; overflow-x:hidden;">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>My Results</h4>
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

        <h4 class="mb-3">Provisional Results Slip</h4>

        <!-- Student Information Header -->
        <div class="card mb-3">
            <div class="card-body" style="font-size:14px;">
                <div class="row">
                    <div class="col-md-4 col-sm-6 mb-2">
                        <p class="mb-1"><strong>Name:</strong> <?php echo e(strtoupper(trim(($studentProfile['first_name'] ?? '') . ' ' . ($studentProfile['last_name'] ?? '')))); ?></p>
                        <p class="mb-1"><strong>Registration No:</strong> <?php echo e($studentProfile['student_id'] ?? '-'); ?></p>
                        <p class="mb-1"><strong>Sex:</strong> <?php echo e($studentProfile['gender'] ?? '-'); ?></p>
                    </div>
                    <div class="col-md-4 col-sm-6 mb-2">
                        <p class="mb-1"><strong>Nationality:</strong> <?php echo e($studentProfile['country'] ?? '-'); ?></p>
                        <p class="mb-1"><strong>Date of Birth:</strong> <?php echo !empty($studentProfile['date_of_birth']) ? date('Y-m-d', strtotime($studentProfile['date_of_birth'])) : '-'; ?></p>
                        <p class="mb-1"><strong>Intake:</strong> <?php echo e($intakeLabel); ?></p>
                    </div>
                    <div class="col-md-4 col-sm-6 mb-2">
                        <p class="mb-1"><strong>Entry Mode:</strong> <?php echo e($studentProfile['entry_mode'] ?? 'Not set'); ?></p>
                        <p class="mb-1"><strong>Programme:</strong> <?php echo e($programInfo['program_name'] ?? '-'); ?></p>
                        <p class="mb-1"><strong>College/School:</strong> <?php echo e($programInfo['department'] ?? '-'); ?></p>
                    </div>
                </div>
                <hr class="my-2">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <p class="mb-0 mr-4"><strong>Academic Status:</strong> <?php echo e($academicStatus); ?></p>
                    <?php if ($overallCGPA !== null): ?>
                        <p class="mb-0"><strong>Cumulative GPA:</strong>
                            <span class="badge badge-<?php echo Helper::getGPAColor($overallCGPA); ?>" style="font-size:0.9rem;">
                                <?php echo number_format($overallCGPA, 2); ?>
                            </span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Results by Year and Semester -->
        <?php if (empty($semesterBlocks)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-chart-line fa-3x mb-3"></i>
                    <h5>No Results Available</h5>
                    <p>You have no registered courses or results yet.</p>
                </div>
            </div>
        <?php else: ?>

            <?php
            // ----------------------------------------------------------------
            // Reorganize blocks: byYear[year_of_study][semester_number] = block
            // Also collect distinct academic_year labels per year_of_study
            // ----------------------------------------------------------------
            $byYear           = [];
            $yearLabels       = []; // year_of_study => display academic year string
            $baseAcademicYear = 2025; // Year 1 -> 2025/2026, Year 2 -> 2026/2027, etc.

            foreach ($semesterBlocks as $key => $block) {
                $yos = $block['year_of_study'];
                $sn  = $block['semester_number'];

                if (!isset($byYear[$yos])) {
                    $byYear[$yos] = [];
                }
                $byYear[$yos][$sn] = $block;

                // Derive academic year label purely from year_of_study
                if (!isset($yearLabels[$yos])) {
                    $startYear             = $baseAcademicYear + ((int)$yos - 1);
                    $endYear               = $startYear + 1;
                    $yearLabels[$yos]      = $startYear . '/' . $endYear;
                }
            }
            ksort($byYear);
            foreach ($byYear as &$sems) { ksort($sems); }
            unset($sems);

            // Collect all distinct academic year labels for the section heading
            $distinctAcademicYears = array_unique(array_values($yearLabels));
            $academicYearHeading   = !empty($distinctAcademicYears)
                                        ? implode(', ', $distinctAcademicYears)
                                        : 'Academic Record';
            ?>

            <!-- Dynamic Academic Year Section Header -->
            <h3 class="results-section-header">
                <i class="fas fa-graduation-cap"></i>
                Academic Year<?php echo count($distinctAcademicYears) > 1 ? 's' : ''; ?>:
                <?php echo e($academicYearHeading); ?>
            </h3>

            <?php foreach ($byYear as $yearNum => $semesters): ?>
                <div class="year-section">
                    <!-- Year heading, show its academic year label if available -->
                    <h4 class="year-title">
                        Year <?php echo (int)$yearNum; ?>
                        <?php if (!empty($yearLabels[$yearNum])): ?>
                            <small class="text-muted" style="font-weight:400; font-size:0.8rem; margin-left:6px;">
                                (<?php echo e($yearLabels[$yearNum]); ?>)
                            </small>
                        <?php endif; ?>
                    </h4>

                    <?php foreach ($semesters as $semNum => $block): ?>
                        <?php
                        // Determine decision badge CSS class
                        $decisionClass = 'decision-pa';
                        if ($block['decision'] === 'Promoted')      $decisionClass = 'decision-promoted';
                        elseif ($block['decision'] === 'Good Standing') $decisionClass = 'decision-good';
                        elseif ($block['decision'] === 'Probation') $decisionClass = 'decision-probation';
                        elseif ($block['decision'] === 'Retained')  $decisionClass = 'decision-retained';
                        ?>
                        <div class="results-card">
                            <!-- Semester header: title + course count + SGPA + CGPA + Decision -->
                            <div class="semester-header">
                                <i class="fas fa-calendar-alt" style="color:#4a5568;"></i>
                                <h5>Semester <?php echo (int)$semNum; ?></h5>

                                <span class="badge badge-primary">
                                    <?php echo count($block['courses']); ?> Course<?php echo count($block['courses']) !== 1 ? 's' : ''; ?>
                                </span>

                                <!-- SGPA pill -->
                                <?php if ($block['sgpa_display'] !== 'PA'): ?>
                                    <span class="gpa-pill gpa-pill-sgpa">
                                        SGPA:&nbsp;<?php echo e($block['sgpa_display']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="gpa-pill gpa-pill-pa">SGPA: PA</span>
                                <?php endif; ?>

                                <!-- CGPA pill -->
                                <?php if ($block['cgpa_display'] !== 'PA'): ?>
                                    <span class="gpa-pill gpa-pill-cgpa">
                                        CGPA:&nbsp;<?php echo e($block['cgpa_display']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="gpa-pill gpa-pill-pa">CGPA: PA</span>
                                <?php endif; ?>

                                <!-- Decision / Standing badge -->
                                <?php if ($block['decision'] !== null): ?>
                                    <span class="decision-badge <?php echo $decisionClass; ?>">
                                        <?php
                                        $icon = '';
                                        if ($block['decision'] === 'Promoted')       $icon = '✓ ';
                                        elseif ($block['decision'] === 'Good Standing') $icon = '✓ ';
                                        elseif ($block['decision'] === 'Probation')  $icon = '⚠ ';
                                        elseif ($block['decision'] === 'Retained')   $icon = '✗ ';
                                        echo $icon . e($block['decision']);
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="decision-badge decision-pa">Pending</span>
                                <?php endif; ?>
                            </div>

                            <!-- Course results table -->
                            <div class="table-responsive">
                                <table class="results-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Title</th>
                                            <th class="text-center">Year</th>
                                            <th class="text-center">CU</th>
                                            <th class="text-center">CW</th>
                                            <th class="text-center">EXM</th>
                                            <th class="text-center">TT</th>
                                            <th class="text-center">LG</th>
                                            <th class="text-center">GP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($block['courses'] as $c):
                                            $rs = $c['result_status'] ?? null;
                                            if ($rs === RESULT_PUBLISHED) {
                                                $cw  = $c['assignment_marks'] !== null ? number_format($c['assignment_marks'], 0) : 'PA';
                                                $exm = $c['final_exam_marks'] !== null ? number_format($c['final_exam_marks'], 0) : 'PA';
                                                $tt  = $c['total_marks']      !== null ? number_format($c['total_marks'],      0) : 'PA';
                                                $lg  = $c['grade']       ?? 'PA';
                                                $gp  = $c['grade_points'] !== null ? number_format($c['grade_points'], 1) : 'PA';
                                            } else {
                                                $cw = $exm = $tt = $lg = $gp = 'PA';
                                            }
                                        ?>
                                        <tr>
                                            <td><strong><?php echo e($c['course_code']); ?></strong></td>
                                            <td><?php echo e($c['course_name']); ?></td>
                                            <td class="text-center">Year <?php echo (int)$yearNum; ?></td>
                                            <td class="text-center"><?php echo e($c['credit_hours']); ?></td>
                                            <td class="text-center"><?php echo e($cw); ?></td>
                                            <td class="text-center"><?php echo e($exm); ?></td>
                                            <td class="text-center"><?php echo e($tt); ?></td>
                                            <td class="text-center"><?php echo e($lg); ?></td>
                                            <td class="text-center"><?php echo e($gp); ?></td>
                                        </tr>
                                        <?php endforeach; ?>

                                        <!-- Summary row: credits + SGPA -->
                                        <tr class="sgpa-summary-row">
                                            <td colspan="3" class="text-right">
                                                Semester Credits: <strong><?php echo (int)$block['total_credits']; ?> CU</strong>
                                            </td>
                                            <td colspan="3" class="text-right">SGPA:</td>
                                            <td colspan="3" class="text-center"><?php echo e($block['sgpa_display']); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <!-- Overall Summary Card -->
            <?php if ($overallCGPA !== null): ?>
                <div class="overall-card <?php echo $overallCGPA < 1.5 ? 'at-risk' : ($overallCGPA < 2.0 ? 'probation' : ''); ?> mt-3">
                    <div class="row align-items-center">
                        <div class="col-md-4 text-center mb-2">
                            <h6 class="mb-1" style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Cumulative GPA</h6>
                            <span style="font-size:2rem; font-weight:800; color:<?php echo $overallCGPA >= 2.0 ? '#16a34a' : ($overallCGPA >= 1.5 ? '#d97706' : '#dc2626'); ?>;">
                                <?php echo number_format($overallCGPA, 2); ?>
                            </span>
                        </div>
                        <div class="col-md-4 text-center mb-2">
                            <h6 class="mb-1" style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Total Credits Earned</h6>
                            <span style="font-size:1.5rem; font-weight:700; color:#1e40af;">
                                <?php echo (int)$cumulativeCredits; ?> CU
                            </span>
                        </div>
                        <div class="col-md-4 text-center mb-2">
                            <h6 class="mb-1" style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Academic Standing</h6>
                            <?php if ($overallCGPA >= 2.0): ?>
                                <span style="font-size:1.1rem; font-weight:700; color:#16a34a;"><i class="fas fa-check-circle"></i> Good Standing</span>
                            <?php elseif ($overallCGPA >= 1.5): ?>
                                <span style="font-size:1.1rem; font-weight:700; color:#d97706;"><i class="fas fa-exclamation-triangle"></i> Probation</span>
                            <?php else: ?>
                                <span style="font-size:1.1rem; font-weight:700; color:#dc2626;"><i class="fas fa-times-circle"></i> Suspension Risk</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- Legend -->
        <div class="mt-3" style="font-size:12px; color:#555;">
            <p class="mb-1"><strong>Legend:</strong></p>
            <p class="mb-1">
                <strong>PA</strong> = Pending Assessment &nbsp;|&nbsp;
                <strong>CW</strong> = Coursework &nbsp;|&nbsp;
                <strong>EXM</strong> = Exam &nbsp;|&nbsp;
                <strong>TT</strong> = Total &nbsp;|&nbsp;
                <strong>CU</strong> = Credit Units &nbsp;|&nbsp;
                <strong>LG</strong> = Letter Grade &nbsp;|&nbsp;
                <strong>GP</strong> = Grade Points
            </p>
            <p class="mb-0">
                <strong>Promotion Rule:</strong> CGPA &ge; 2.00 at end of academic year = <em>Promoted</em>.
                CGPA &lt; 2.00 = <em>Retained</em> in same year.
            </p>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>