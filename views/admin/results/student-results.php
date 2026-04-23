<?php
/**
 * Admin - Student Results List
 * Lists all students to allow an admin to select one and view their results slip.
 */
require_once '../../../config.php';
require_once '../../../includes/functions.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser  = $auth->getCurrentUser();
$db   = new Database();
$conn = $db->getConnection();

// Filters
$searchQuery = trim($_GET['search'] ?? '');
$window = getAcademicCalendarDisplayWindowBounds();
$academicYearsStmt = $conn->prepare("SELECT id, year_name, start_date FROM academic_years WHERE start_date >= :start_date AND start_date <= :end_date ORDER BY start_date DESC");
$academicYearsStmt->execute($window);
$academicYears = $academicYearsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$defaultAcademicYearId  = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : (int)$defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int)$_GET['semester_number'] : (int)(Helper::getCurrentSemester()['semester_number'] ?? 1);
$programs = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedProgramId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : (int)($programs[0]['id'] ?? 0);
$selectedLevelYear = isset($_GET['level_year']) ? (int)$_GET['level_year'] : 1;
if (!empty($programs)) {
    $programIds = array_map('intval', array_column($programs, 'id'));
    if (!in_array((int)$selectedProgramId, $programIds, true)) {
        $selectedProgramId = (int)$programs[0]['id'];
    }
}

$mapStmt = $conn->prepare('SELECT id FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1');
$mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
$semesterId = (int)($mapStmt->fetchColumn() ?: 0);

// Build scoped query
$sql = "SELECT DISTINCT
            s.id, s.student_id AS reg_no, s.first_name, s.last_name, s.smns_email,
            p.program_name,
            ay.year_name AS entry_year
        FROM students s
        LEFT JOIN programs p ON s.program_id = p.id
        LEFT JOIN academic_years ay ON s.entry_year = ay.id
        INNER JOIN results r ON r.student_id = s.id
        INNER JOIN courses c ON c.id = r.course_id
        WHERE r.semester_id = :semester_id
          AND c.level_year = :level_year
          AND (c.semester_offered = :semester_number OR c.semester_offered = 3)";

$params = [
    'semester_id' => (int)$semesterId,
    'level_year' => (int)$selectedLevelYear,
    'semester_number' => (int)$selectedSemesterNumber,
];

if ($selectedProgramId > 0) {
    $sql .= " AND c.program_id = :program_id";
    $params['program_id'] = (int)$selectedProgramId;
}

if ($searchQuery) {
    $sql .= " AND (
        s.student_id LIKE :search
        OR s.first_name LIKE :search
        OR s.last_name LIKE :search
        OR CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) LIKE :search
        OR CONCAT(COALESCE(s.last_name, ''), ' ', COALESCE(s.first_name, '')) LIKE :search
        OR s.smns_email LIKE :search
        OR COALESCE(p.program_name, '') LIKE :search
        OR COALESCE(ay.year_name, '') LIKE :search
    )";
    $params['search'] = "%{$searchQuery}%";
}

$sql .= " ORDER BY s.first_name, s.last_name ASC LIMIT 1000";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();
$studentUiSummary = [
    'listed' => count($students),
    'search_active' => $searchQuery !== '' ? 1 : 0,
    'semester_ready' => $semesterId > 0 ? 1 : 0
];

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Select Student for Results - ' . APP_NAME;
include '../../../includes/header.php';
?>
<style>
.results-student-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(150px, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}
.results-student-card {
    background: #fff;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    padding: 10px;
}
.results-student-label {
    font-size: 0.72rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.results-student-value {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}
@media (max-width: 576px) {
    .results-student-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-user-graduate"></i> View Student Results</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>
        <?php $resultsWorkflowActive = 'student_result'; include __DIR__ . '/_workflow_nav.php'; ?>
        <div class="results-student-stats">
            <div class="results-student-card">
                <div class="results-student-label">Students Listed</div>
                <div class="results-student-value"><?php echo (int)$studentUiSummary['listed']; ?></div>
            </div>
            <div class="results-student-card">
                <div class="results-student-label">Search Filter</div>
                <div class="results-student-value"><?php echo $studentUiSummary['search_active'] ? 'Active' : 'None'; ?></div>
            </div>
        </div>
        <div class="results-helper-note mb-3">
            <strong>Workflow:</strong> Select Academic Year, Semester, Program, and Year of Study first. The list then shows only students with results in that scope.
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-header">
                <i class="fas fa-search"></i> Find a Student
            </div>
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <label class="mr-2">Academic Year:</label>
                    <select name="academic_year_id" class="form-control mr-2 mb-2">
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?php echo (int)$ay['id']; ?>" <?php echo ((int)$selectedAcademicYearId === (int)$ay['id']) ? 'selected' : ''; ?>>
                                <?php echo e($ay['year_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Semester:</label>
                    <select name="semester_number" class="form-control mr-2 mb-2">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo (int)$i; ?>" <?php echo ((int)$selectedSemesterNumber === (int)$i) ? 'selected' : ''; ?>>
                                Semester <?php echo (int)$i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>

                    <label class="mr-2">Program:</label>
                    <select name="program_id" class="form-control mr-2 mb-2">
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo (int)$program['id']; ?>" <?php echo ((int)$selectedProgramId === (int)$program['id']) ? 'selected' : ''; ?>>
                                <?php echo e($program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Year:</label>
                    <select name="level_year" class="form-control mr-2 mb-2">
                        <?php for ($y = 1; $y <= 4; $y++): ?>
                            <option value="<?php echo (int)$y; ?>" <?php echo ((int)$selectedLevelYear === (int)$y) ? 'selected' : ''; ?>>
                                Year <?php echo (int)$y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>

                    <div class="form-group mb-2 mr-sm-2">
                        <label for="search" class="sr-only">Search</label>
                        <input type="text" name="search" id="search" class="form-control" value="<?php echo e($searchQuery); ?>" placeholder="Search by Name, Reg#, Email, Program...">
                    </div>
                    <button type="submit" class="btn btn-primary mb-2">Search</button>
                    <?php if ($searchQuery): ?>
                        <a href="student-results.php?academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>&program_id=<?php echo (int)$selectedProgramId; ?>&level_year=<?php echo (int)$selectedLevelYear; ?>" class="btn btn-secondary mb-2 ml-2">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Student List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-users"></i> Students (<?php echo count($students); ?>)</span>
            </div>
            <div class="card-body">
                <?php if (!$semesterId): ?>
                    <p class="text-center text-muted">No semester configured for the selected academic year and semester number.</p>
                <?php elseif (empty($students)): ?>
                    <p class="text-center text-muted">No students found matching your criteria.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Reg. Number</th>
                                    <th>Program</th>
                                    <th>Email</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNo = 1; foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo $rowNo++; ?></td>
                                        <td><?php echo e($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $student)); ?></td>
                                        <td><?php echo e($student['program_name']); ?></td>
                                        <td><?php echo e($student['smns_email']); ?></td>
                                        <td>
                                            <a href="view-slip.php?student_id=<?php echo $student['id']; ?>&academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> View Results
                                            </a>
                                        </td>
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
