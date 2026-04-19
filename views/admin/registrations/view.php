<?php
/**
 * View registered courses for a student in a semester (Admin)
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

if (!$studentId || !$semesterId) {
    header('Location: pending.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

function adminGetStudentProgressDecision(PDO $conn, int $studentId): array
{
    $decision = [
        'tone' => 'secondary',
        'title' => 'No GPA evidence',
        'detail' => 'No published GPA record yet. Manual review advised.'
    ];
    if ($studentId <= 0) {
        return $decision;
    }

    $metric = null;
    $metricLabel = 'GPA';
    try {
        $gpaStmt = $conn->prepare("
            SELECT sg.semester_gpa, sg.cumulative_gpa
            FROM student_gpas sg
            INNER JOIN semesters s ON s.id = sg.semester_id
            WHERE sg.student_id = :student_id
            ORDER BY s.end_date DESC, s.start_date DESC, sg.id DESC
            LIMIT 1
        ");
        $gpaStmt->execute(['student_id' => $studentId]);
        $gpaRow = $gpaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($gpaRow)) {
            $sgpa = (isset($gpaRow['semester_gpa']) && $gpaRow['semester_gpa'] !== '' && is_numeric($gpaRow['semester_gpa']))
                ? (float)$gpaRow['semester_gpa']
                : null;
            $cgpa = (isset($gpaRow['cumulative_gpa']) && $gpaRow['cumulative_gpa'] !== '' && is_numeric($gpaRow['cumulative_gpa']))
                ? (float)$gpaRow['cumulative_gpa']
                : null;
            if ($cgpa !== null) {
                $metric = $cgpa;
                $metricLabel = 'CGPA';
            } elseif ($sgpa !== null) {
                $metric = $sgpa;
                $metricLabel = 'SGPA';
            }
        }
    } catch (Exception $e) {
        // keep defaults
    }

    if ($metric === null) {
        return $decision;
    }

    $retakeCount = 0;
    try {
        $retakeStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM results r
            INNER JOIN semesters s ON s.id = r.semester_id
            INNER JOIN academic_years ay ON ay.id = s.academic_year_id
            WHERE r.student_id = :student_id
              AND r.status = 'published'
              AND NOT EXISTS (
                    SELECT 1
                    FROM results r2
                    INNER JOIN semesters s2 ON s2.id = r2.semester_id
                    INNER JOIN academic_years ay2 ON ay2.id = s2.academic_year_id
                    WHERE r2.student_id = r.student_id
                      AND r2.course_id = r.course_id
                      AND r2.status IN ('submitted', 'approved', 'published')
                      AND (
                            ay2.start_date > ay.start_date
                            OR (ay2.start_date = ay.start_date AND s2.semester_number > s.semester_number)
                            OR (ay2.start_date = ay.start_date AND s2.semester_number = s.semester_number AND r2.id > r.id)
                      )
              )
              AND (
                    (r.grade_points IS NOT NULL AND r.grade_points < 2.0)
                    OR UPPER(COALESCE(r.grade, '')) IN ('E', 'F')
              )
        ");
        $retakeStmt->execute(['student_id' => $studentId]);
        $retakeCount = (int)$retakeStmt->fetchColumn();
    } catch (Exception $e) {
        $retakeCount = 0;
    }

    $metricValue = (float)$metric;
    if ($metricValue < 2.0) {
        return [
            'tone' => 'danger',
            'title' => 'Repeat required',
            'detail' => $metricLabel . ' ' . number_format($metricValue, 2) . ' (< 2.00). Keep student in same semester.'
        ];
    }
    if ($retakeCount > 0) {
        return [
            'tone' => 'warning',
            'title' => 'Continue + Retake reminder',
            'detail' => $metricLabel . ' ' . number_format($metricValue, 2) . ' (>= 2.00), with ' . $retakeCount . ' outstanding retake(s).'
        ];
    }
    return [
        'tone' => 'success',
        'title' => 'Continue',
        'detail' => $metricLabel . ' ' . number_format($metricValue, 2) . ' (>= 2.00).'
    ];
}

// Get student details
$stmt = $conn->prepare("SELECT s.*, u.email FROM students s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = :id");
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();

// Get semester name
$stmt = $conn->prepare("SELECT * FROM semesters WHERE id = :id");
$stmt->execute(['id' => $semesterId]);
$semester = $stmt->fetch();
$progressDecision = adminGetStudentProgressDecision($conn, $studentId);

// Get registrations
$registeredYear = 0;
try {
    $yrStmt = $conn->prepare("
        SELECT year_of_study
        FROM semester_registrations
        WHERE student_id = :student_id
          AND semester_id = :semester_id
          AND status = 'approved'
        ORDER BY id DESC
        LIMIT 1
    ");
    $yrStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
    $registeredYear = (int)$yrStmt->fetchColumn();
} catch (Exception $e) {
    $registeredYear = 0;
}

$sql = "SELECT cr.*, c.course_code, c.course_name, c.credit_hours
        FROM course_registrations cr
        JOIN courses c ON cr.course_id = c.id
        JOIN semesters s ON cr.semester_id = s.id
        WHERE cr.student_id = :student_id
          AND cr.semester_id = :semester_id
          AND (c.semester_offered = s.semester_number OR c.semester_offered = 3)";
$params = ['student_id' => $studentId, 'semester_id' => $semesterId];
if ($registeredYear > 0) {
    $sql .= " AND c.level_year = :registered_year";
    $params['registered_year'] = $registeredYear;
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$regs = $stmt->fetchAll();

$pageTitle = 'Registered Courses - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>Registered Courses</h4>
        </div>
    </div>

    <div class="content-area container p-4">
        <div class="card">
            <div class="card-body">
                <h5><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?> — <?php echo e($semester['semester_name'] ?? 'Semester'); ?></h5>
                <p class="text-muted">Student ID: <?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $student)); ?></p>
                <div class="mb-3">
                    <span class="badge badge-<?php echo e($progressDecision['tone'] ?? 'secondary'); ?>"><?php echo e($progressDecision['title'] ?? 'No GPA evidence'); ?></span>
                    <div class="small text-muted mt-1"><?php echo e($progressDecision['detail'] ?? 'Manual review advised.'); ?></div>
                </div>

                <?php if (empty($regs)): ?>
                    <p class="text-muted">No course registrations found for this student and semester.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Credit Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($regs as $r): ?>
                                    <tr>
                                        <td><?php echo e($r['course_code']); ?></td>
                                        <td><?php echo e($r['course_name']); ?></td>
                                        <td><?php echo e($r['credit_hours']); ?></td>
                                        <td><?php echo e($r['status']); ?></td>
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
