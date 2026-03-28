<?php
/**
 * Admin - Results Audit Trail
 * View history of all mark changes for record keeping and complaint resolution
 */
require_once '../../../config.php';
require_once '../../../includes/functions.php';

$session = new Session('admin');
$auth    = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser  = $auth->getCurrentUser();
$db   = new Database();
$conn = $db->getConnection();

$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

// Ensure results_audit table exists
$conn->exec("CREATE TABLE IF NOT EXISTS `results_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `result_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `changed_by_user_id` int(11) NOT NULL,
  `change_type` enum('publish','edit') NOT NULL,
  `old_marks` longtext DEFAULT NULL,
  `new_marks` longtext NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `result_id` (`result_id`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Filters
$studentSearch = trim($_GET['student'] ?? '');
$courseSearch = trim($_GET['course'] ?? '');
$changeType = $_GET['change_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$export = strtolower(trim((string)($_GET['export'] ?? '')));

// Build query
$sql = "SELECT ra.*, 
               s.student_id AS reg_no, s.first_name AS student_first, s.last_name AS student_last,
               c.course_code, c.course_name, c.level_year AS course_level_year, c.program_id AS course_program_id,
               u.username AS changed_by_username,
               COALESCE(a.first_name, '') AS admin_first, COALESCE(a.last_name, '') AS admin_last,
               r.semester_id,
               sem.semester_number,
               sem.academic_year_id
        FROM results_audit ra
        LEFT JOIN students s ON ra.student_id = s.id
        LEFT JOIN courses c ON ra.course_id = c.id
        LEFT JOIN users u ON ra.changed_by_user_id = u.id
        LEFT JOIN admins a ON u.id = a.user_id
        LEFT JOIN results r ON ra.result_id = r.id
        LEFT JOIN semesters sem ON r.semester_id = sem.id
        WHERE 1=1";

$params = [];

if ($studentSearch) {
    $sql .= " AND (s.student_id LIKE :student_search1 OR CONCAT(s.first_name, ' ', s.last_name) LIKE :student_search2 OR CONCAT(s.last_name, ' ', s.first_name) LIKE :student_search3 OR s.first_name LIKE :student_search4 OR s.last_name LIKE :student_search5)";
    $params['student_search1'] = "%{$studentSearch}%";
    $params['student_search2'] = "%{$studentSearch}%";
    $params['student_search3'] = "%{$studentSearch}%";
    $params['student_search4'] = "%{$studentSearch}%";
    $params['student_search5'] = "%{$studentSearch}%";
}

if ($courseSearch) {
    $sql .= " AND (c.course_code LIKE :course1 OR c.course_name LIKE :course2)";
    $params['course1'] = "%{$courseSearch}%";
    $params['course2'] = "%{$courseSearch}%";
}

if ($changeType) {
    $sql .= " AND ra.change_type = :change_type";
    $params['change_type'] = $changeType;
}

if ($dateFrom) {
    $sql .= " AND DATE(ra.changed_at) >= :date_from";
    $params['date_from'] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(ra.changed_at) <= :date_to";
    $params['date_to'] = $dateTo;
}
if ($selectedAcademicYearId > 0) {
    $sql .= " AND sem.academic_year_id = :filter_ay";
    $params['filter_ay'] = (int)$selectedAcademicYearId;
}
if ($selectedSemesterNumber > 0) {
    $sql .= " AND sem.semester_number = :filter_sem_no";
    $params['filter_sem_no'] = (int)$selectedSemesterNumber;
}
if ($selectedProgramId > 0) {
    $sql .= " AND c.program_id = :filter_program";
    $params['filter_program'] = (int)$selectedProgramId;
}
if ($selectedLevelYear > 0) {
    $sql .= " AND c.level_year = :filter_level_year";
    $params['filter_level_year'] = (int)$selectedLevelYear;
}

$sql .= " ORDER BY ra.changed_at DESC LIMIT 500";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $auditRecords = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Audit Query Error: " . $e->getMessage());
    $session->setFlash('error', 'Error fetching audit records: ' . $e->getMessage());
    $auditRecords = [];
}

// =====================================================================
// PUBLISHED RESULTS SEARCH - For finding and editing any published marks
// =====================================================================
$publishedSearch = trim($_GET['published_student'] ?? '');
$publishedCourse = trim($_GET['published_course'] ?? '');
$publishedResults = [];

if ($publishedSearch || $publishedCourse) {
    $pubSql = "SELECT r.id AS result_id, r.student_id, r.course_id, r.semester_id,
                      r.assignment_marks, r.final_exam_marks, r.total_marks, r.grade, r.status,
                      s.student_id AS reg_no, s.first_name AS student_first, s.last_name AS student_last,
                      c.course_code, c.course_name, c.level_year AS course_level_year, c.program_id AS course_program_id,
                      sem.semester_number, sem.academic_year_id,
                      ay.year_name AS academic_year_name
               FROM results r
               LEFT JOIN students s ON r.student_id = s.id
               LEFT JOIN courses c ON r.course_id = c.id
               LEFT JOIN semesters sem ON r.semester_id = sem.id
               LEFT JOIN academic_years ay ON sem.academic_year_id = ay.id
               WHERE r.status = 'published'";
    
    $pubParams = [];
    
    if ($publishedSearch) {
        $pubSql .= " AND (s.student_id LIKE :ps1 OR s.first_name LIKE :ps2 OR s.last_name LIKE :ps3 OR CONCAT(s.first_name, ' ', s.last_name) LIKE :ps4 OR CONCAT(s.last_name, ' ', s.first_name) LIKE :ps5)";
        $pubParams['ps1'] = "%{$publishedSearch}%";
        $pubParams['ps2'] = "%{$publishedSearch}%";
        $pubParams['ps3'] = "%{$publishedSearch}%";
        $pubParams['ps4'] = "%{$publishedSearch}%";
        $pubParams['ps5'] = "%{$publishedSearch}%";
    }
    
    if ($publishedCourse) {
        $pubSql .= " AND (c.course_code LIKE :pc1 OR c.course_name LIKE :pc2)";
        $pubParams['pc1'] = "%{$publishedCourse}%";
        $pubParams['pc2'] = "%{$publishedCourse}%";
    }
    if ($selectedAcademicYearId > 0) {
        $pubSql .= " AND sem.academic_year_id = :pub_ay";
        $pubParams['pub_ay'] = (int)$selectedAcademicYearId;
    }
    if ($selectedSemesterNumber > 0) {
        $pubSql .= " AND sem.semester_number = :pub_sem_no";
        $pubParams['pub_sem_no'] = (int)$selectedSemesterNumber;
    }
    if ($selectedProgramId > 0) {
        $pubSql .= " AND c.program_id = :pub_program";
        $pubParams['pub_program'] = (int)$selectedProgramId;
    }
    if ($selectedLevelYear > 0) {
        $pubSql .= " AND c.level_year = :pub_level_year";
        $pubParams['pub_level_year'] = (int)$selectedLevelYear;
    }
    
    $pubSql .= " ORDER BY r.updated_at DESC LIMIT 200";
    
    try {
        $pubStmt = $conn->prepare($pubSql);
        $pubStmt->execute($pubParams);
        $publishedResults = $pubStmt->fetchAll();
    } catch (Exception $e) {
        error_log("Published Results Query Error: " . $e->getMessage());
    }
}

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : (($publishedSearch || $publishedCourse) ? 'published' : 'audit');
$auditUiSummary = [
    'total' => count($auditRecords),
    'edit' => 0,
    'publish' => 0,
    'published_search_hits' => count($publishedResults)
];
foreach ($auditRecords as $row) {
    $changeTypeKey = strtolower((string)($row['change_type'] ?? ''));
    if (isset($auditUiSummary[$changeTypeKey])) {
        $auditUiSummary[$changeTypeKey]++;
    }
}

if ($export === 'csv' || $export === 'excel') {
    $isExcel = ($export === 'excel');
    $filename = 'results_audit_' . $activeTab . '_' . date('Ymd_His') . ($isExcel ? '.xls' : '.csv');
    if ($isExcel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    if ($activeTab === 'published') {
        fputcsv($out, ['Result ID', 'Student PRN', 'Student Name', 'Course', 'Semester #', 'Academic Year ID', 'CW', 'Exam', 'Total', 'Grade', 'Status']);
        foreach ($publishedResults as $row) {
            $studentName = trim((string)(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? '')));
            $courseName = trim((string)(($row['course_code'] ?? '') . ' ' . ($row['course_name'] ?? '')));
            fputcsv($out, [
                (string)($row['result_id'] ?? ''),
                (string)($row['reg_no'] ?? ''),
                $studentName,
                $courseName,
                (string)($row['semester_number'] ?? ''),
                (string)($row['academic_year_id'] ?? ''),
                (string)($row['assignment_marks'] ?? ''),
                (string)($row['final_exam_marks'] ?? ''),
                (string)($row['total_marks'] ?? ''),
                (string)($row['grade'] ?? ''),
                (string)($row['status'] ?? ''),
            ]);
        }
    } else {
        fputcsv($out, ['Changed At', 'Change Type', 'Student PRN', 'Student Name', 'Course', 'Changed By', 'Reason', 'Old Marks JSON', 'New Marks JSON']);
        foreach ($auditRecords as $row) {
            $changedBy = trim((string)(($row['admin_first'] ?? '') . ' ' . ($row['admin_last'] ?? '')));
            if ($changedBy === '') {
                $changedBy = (string)($row['changed_by_username'] ?? 'Unknown');
            }
            $studentName = trim((string)(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? '')));
            $courseName = trim((string)(($row['course_code'] ?? '') . ' ' . ($row['course_name'] ?? '')));
            fputcsv($out, [
                (string)($row['changed_at'] ?? ''),
                (string)($row['change_type'] ?? ''),
                (string)($row['reg_no'] ?? ''),
                $studentName,
                $courseName,
                $changedBy,
                (string)($row['reason'] ?? ''),
                (string)($row['old_marks'] ?? ''),
                (string)($row['new_marks'] ?? ''),
            ]);
        }
    }

    fclose($out);
    exit;
}

$csvExportQuery = $_GET;
$csvExportQuery['export'] = 'csv';
$csvExportHref = '?' . http_build_query($csvExportQuery);

$excelExportQuery = $_GET;
$excelExportQuery['export'] = 'excel';
$excelExportHref = '?' . http_build_query($excelExportQuery);

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Results Audit Trail - ' . APP_NAME;
include '../../../includes/header.php';
?>

<style>
    /* Prevent horizontal scrolling */
    html, body {
        overflow-x: hidden;
        max-width: 100%;
    }
    
    .main-content {
        max-width: 100vw;
        overflow-x: hidden;
    }
    
    .content-area {
        max-width: 100%;
        overflow-x: hidden;
        padding: 15px;
    }
    
    /* Keep results tables fully visible without horizontal scrolling */
    .table-responsive,
    .table-responsive[style*='overflow-x: auto'] {
        overflow-x: visible !important;
        max-width: 100%;
    }
    
    .audit-table {
        margin-bottom: 0;
        font-size: 0.875rem;
        width: 100%;
        table-layout: fixed;
    }
    
    .audit-table th, 
    .audit-table td {
        vertical-align: middle;
        padding: 0.5rem;
        white-space: normal;
        word-wrap: break-word;
        overflow-wrap: anywhere;
    }
    
    /* Override inline min-width styles generated in table headers/cells */
    .audit-table th[style*='min-width'],
    .audit-table td[style*='min-width'] {
        min-width: 0 !important;
        width: auto !important;
        max-width: none !important;
    }

    /* Column widths tuned to fit viewport */
    .audit-table th:nth-child(1), .audit-table td:nth-child(1) { width: 5%; }  /* # */
    .audit-table th:nth-child(2), .audit-table td:nth-child(2) { width: 28%; } /* Student + Meta */
    .audit-table th:nth-child(3), .audit-table td:nth-child(3) { width: 12%; } /* Reg No */
    .audit-table th:nth-child(4), .audit-table td:nth-child(4) { width: 9%; }  /* CW */
    .audit-table th:nth-child(5), .audit-table td:nth-child(5) { width: 9%; }  /* Exam */
    .audit-table th:nth-child(6), .audit-table td:nth-child(6) { width: 9%; }  /* Total */
    .audit-table th:nth-child(7), .audit-table td:nth-child(7) { width: 9%; }  /* Grade */
    .audit-table th:nth-child(8), .audit-table td:nth-child(8) { width: 12%; } /* Actions */

    .audit-table td small {
        display: inline-block;
        max-width: 100%;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    
    /* Button styling */
    .btn-sm {
        padding: 0.2rem 0.4rem;
        font-size: 0.75rem;
        margin: 0.1rem;
    }
    
    /* Actions column */
    .audit-table td:last-child,
    .audit-table td[style*='white-space: nowrap'] {
        white-space: normal !important;
        text-align: center;
    }
    
    /* Tabs styling */
    .nav-tabs {
        flex-wrap: wrap;
    }
    
    .nav-tabs .nav-link {
        color: #495057;
        font-size: 0.9rem;
        padding: 0.5rem 1rem;
    }
    
    .nav-tabs .nav-link.active {
        font-weight: bold;
        background-color: #fff;
        border-color: #dee2e6 #dee2e6 #fff;
    }

    html[data-theme='dark'] .nav-tabs {
        border-bottom-color: #334155;
    }

    html[data-theme='dark'] .nav-tabs .nav-link {
        color: #cbd5e1;
        background: transparent;
        border-color: transparent;
    }

    html[data-theme='dark'] .nav-tabs .nav-link:hover,
    html[data-theme='dark'] .nav-tabs .nav-link:focus {
        color: #f8fafc;
        background: #1e293b;
        border-color: #334155 #334155 #334155;
    }

    html[data-theme='dark'] .nav-tabs .nav-link.active {
        color: #f8fafc;
        background: #1e293b;
        border-color: #475569 #475569 #1e293b;
        font-weight: 700;
    }
    
    /* Card body padding */
    .card-body {
        padding: 1rem;
    }
    
    /* Filter form responsive */
    @media (max-width: 992px) {
        .content-area {
            padding: 10px;
        }
        
        .card-body .row > div {
            margin-bottom: 0.5rem;
        }
        
        .audit-table {
            font-size: 0.75rem;
        }
        
        .audit-table th,
        .audit-table td {
            padding: 0.3rem;
        }
    }
    
    @media (max-width: 768px) {
        .topbar {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .nav-tabs .nav-link {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
        }
        
        .audit-table {
            font-size: 0.7rem;
        }
        
        .btn-sm {
            padding: 0.15rem 0.3rem;
            font-size: 0.7rem;
        }
    }
    
    @media (max-width: 576px) {
        .content-area {
            padding: 5px;
        }
        
        .card-body {
            padding: 0.5rem;
        }
        
        .audit-table th,
        .audit-table td {
            padding: 0.25rem;
            font-size: 0.65rem;
        }
        
        .btn-sm {
            padding: 0.1rem 0.25rem;
            font-size: 0.65rem;
        }
    }
</style>
<style>
.results-quick-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}
.results-stat-card {
    background: #fff;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    padding: 10px;
}
.results-stat-label {
    font-size: 0.72rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.results-stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}
.marks-table thead th {
    background: #eaf2ff;
    border-color: #cfe0ff;
    font-weight: 700;
    white-space: nowrap;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.marks-table td,
.marks-table th {
    vertical-align: middle !important;
    padding: 0.7rem 0.65rem;
}
.marks-table tbody tr:nth-child(even) {
    background: #f9fbff;
}
.marks-table tbody tr:hover {
    background: #eef6ff;
}
@media (max-width: 992px) {
    .results-quick-stats {
        grid-template-columns: repeat(2, minmax(130px, 1fr));
    }
}
@media (max-width: 576px) {
    .results-quick-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" style="max-width: 100vw; overflow-x: hidden;">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-history"></i> Results Audit Trail</h4>
        </div>
        <div class="topbar-right">
            <a href="<?php echo e($csvExportHref); ?>" class="btn btn-outline-primary btn-sm mr-2">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a href="<?php echo e($excelExportHref); ?>" class="btn btn-outline-success btn-sm mr-2">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
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
        <?php $resultsWorkflowActive = 'marks_audit'; include __DIR__ . '/_workflow_nav.php'; ?>
        <div class="results-quick-stats">
            <div class="results-stat-card">
                <div class="results-stat-label">Audit Records</div>
                <div class="results-stat-value"><?php echo (int)$auditUiSummary['total']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">Edit Changes</div>
                <div class="results-stat-value"><?php echo (int)$auditUiSummary['edit']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">Publish Actions</div>
                <div class="results-stat-value"><?php echo (int)$auditUiSummary['publish']; ?></div>
            </div>
            <div class="results-stat-card">
                <div class="results-stat-label">Published Search Hits</div>
                <div class="results-stat-value"><?php echo (int)$auditUiSummary['published_search_hits']; ?></div>
            </div>
        </div>
        <div class="results-helper-note mb-3">
            <strong>Workflow:</strong> Filter by Academic Year, Semester, Program, and Year of Study, then review mark changes or locate published rows for correction.
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'published' ? 'active' : ''; ?>" href="?tab=published&academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>&program_id=<?php echo (int)$selectedProgramId; ?>&level_year=<?php echo (int)$selectedLevelYear; ?>">
                    <i class="fas fa-search"></i> Search Published Results
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'audit' ? 'active' : ''; ?>" href="?tab=audit&academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>&program_id=<?php echo (int)$selectedProgramId; ?>&level_year=<?php echo (int)$selectedLevelYear; ?>">
                    <i class="fas fa-history"></i> Audit Trail
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'published'): ?>
        <!-- PUBLISHED RESULTS TAB -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-search"></i> Search Published Results for Editing
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Search for any published student results to make corrections. Changes will be logged in the audit trail.</p>
                <form method="GET" class="row">
                    <input type="hidden" name="tab" value="published">
                    <div class="col-md-3 col-sm-6 mb-2">
                        <label>Academic Year</label>
                        <select name="academic_year_id" class="form-control">
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo (int)$ay['id']; ?>" <?php echo ((int)$selectedAcademicYearId === (int)$ay['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($ay['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Semester</label>
                        <select name="semester_number" class="form-control">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo (int)$i; ?>" <?php echo ((int)$selectedSemesterNumber === (int)$i) ? 'selected' : ''; ?>>Semester <?php echo (int)$i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <label>Program</label>
                        <select name="program_id" class="form-control">
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>" <?php echo ((int)$selectedProgramId === (int)$program['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Year</label>
                        <select name="level_year" class="form-control">
                            <?php for ($y = 1; $y <= 4; $y++): ?>
                                <option value="<?php echo (int)$y; ?>" <?php echo ((int)$selectedLevelYear === (int)$y) ? 'selected' : ''; ?>>Year <?php echo (int)$y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-sm-12 mb-2">
                        <label>Student (Name or Reg#)</label>
                        <input type="text" name="published_student" class="form-control" value="<?php echo e($publishedSearch); ?>" placeholder="e.g. Kevin Birungi or 2024/U/001">
                    </div>
                    <div class="col-md-4 col-sm-12 mb-2">
                        <label>Course (Code or Name)</label>
                        <input type="text" name="published_course" class="form-control" value="<?php echo e($publishedCourse); ?>" placeholder="e.g. CSC101 or Programming">
                    </div>
                    <div class="col-md-4 col-sm-12 mb-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary mr-2"><i class="fas fa-search"></i> Search</button>
                        <a href="?tab=published&academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>&program_id=<?php echo (int)$selectedProgramId; ?>&level_year=<?php echo (int)$selectedLevelYear; ?>" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($publishedSearch || $publishedCourse): ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Published Results Found (<?php echo count($publishedResults); ?>)
            </div>
            <div class="card-body">
                <?php if (empty($publishedResults)): ?>
                    <p class="text-center text-muted">No published results found matching your search criteria.</p>
                <?php else: ?>
                    <div class="table-responsive" style="overflow-x: auto; max-width: 100%;">
                        <table class="table table-sm table-hover marks-table" style="margin-bottom: 0;">
                            <thead class="thead-light">
                                <tr>
                                    <th style="min-width: 40px;">#</th>
                                    <th style="min-width: 200px;">Student Name</th>
                                    <th style="min-width: 120px;">Reg. No.</th>
                                    <th style="min-width: 70px;" class="text-center">CW /40</th>
                                    <th style="min-width: 70px;" class="text-center">Exam /60</th>
                                    <th style="min-width: 60px;" class="text-center">Total</th>
                                    <th style="min-width: 60px;" class="text-center">Grade</th>
                                    <th style="min-width: 110px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIndex = 1; foreach ($publishedResults as $pr): ?>
                                    <?php
                                        $studentName = trim((string)(($pr['student_first'] ?? '') . ' ' . ($pr['student_last'] ?? '')));
                                        $courseLabel = trim((string)(($pr['course_code'] ?? '') . ' ' . ($pr['course_name'] ?? '')));
                                        $semesterLabel = trim((string)(($pr['academic_year_name'] ?? '') . ' - Sem ' . ($pr['semester_number'] ?? '')));
                                    ?>
                                    <tr>
                                        <td class="text-center"><?php echo $rowIndex++; ?></td>
                                        <td>
                                            <strong><?php echo e($studentName); ?></strong><br>
                                            <small class="text-muted">Course: <?php echo e($courseLabel); ?></small><br>
                                            <small class="text-muted"><?php echo e($semesterLabel); ?></small>
                                        </td>
                                        <td><?php echo e($pr['reg_no']); ?></td>
                                        <td class="text-center"><?php echo $pr['assignment_marks'] !== null ? round($pr['assignment_marks'], 1) : '-'; ?></td>
                                        <td class="text-center"><?php echo $pr['final_exam_marks'] !== null ? round($pr['final_exam_marks'], 1) : '-'; ?></td>
                                        <td class="text-center"><strong><?php echo $pr['total_marks'] !== null ? round($pr['total_marks']) : '-'; ?></strong></td>
                                        <td class="text-center"><span class="badge badge-info"><?php echo e($pr['grade']); ?></span></td>
                                        <td style="white-space: nowrap; min-width: 100px;">
                                            <a href="submitted.php?academic_year_id=<?php echo $pr['academic_year_id']; ?>&semester_number=<?php echo $pr['semester_number']; ?>&level_year=<?php echo (int)($pr['course_level_year'] ?? 0); ?>&program_id=<?php echo (int)($pr['course_program_id'] ?? 0); ?>&course_id=<?php echo $pr['course_id']; ?>" class="btn btn-sm btn-warning" title="Edit Marks">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="view-slip.php?student_id=<?php echo $pr['student_id']; ?>&academic_year_id=<?php echo $pr['academic_year_id']; ?>&semester_number=<?php echo $pr['semester_number']; ?>" class="btn btn-sm btn-info" title="View Full Results">
                                                <i class="fas fa-eye"></i>
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
        <?php endif; ?>

        <?php else: ?>
        <!-- AUDIT TRAIL TAB -->
        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filter Audit Records
            </div>
            <div class="card-body">
                <form method="GET" class="row">
                    <input type="hidden" name="tab" value="audit">
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Academic Year</label>
                        <select name="academic_year_id" class="form-control form-control-sm">
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo (int)$ay['id']; ?>" <?php echo ((int)$selectedAcademicYearId === (int)$ay['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($ay['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Semester</label>
                        <select name="semester_number" class="form-control form-control-sm">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo (int)$i; ?>" <?php echo ((int)$selectedSemesterNumber === (int)$i) ? 'selected' : ''; ?>>Semester <?php echo (int)$i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Program</label>
                        <select name="program_id" class="form-control form-control-sm">
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>" <?php echo ((int)$selectedProgramId === (int)$program['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Year</label>
                        <select name="level_year" class="form-control form-control-sm">
                            <?php for ($y = 1; $y <= 4; $y++): ?>
                                <option value="<?php echo (int)$y; ?>" <?php echo ((int)$selectedLevelYear === (int)$y) ? 'selected' : ''; ?>>Year <?php echo (int)$y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Student (Name/Reg#)</label>
                        <input type="text" name="student" class="form-control form-control-sm" value="<?php echo e($studentSearch); ?>" placeholder="Search student...">
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Course</label>
                        <input type="text" name="course" class="form-control form-control-sm" value="<?php echo e($courseSearch); ?>" placeholder="Course code/name...">
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>Change Type</label>
                        <select name="change_type" class="form-control form-control-sm">
                            <option value="">All</option>
                            <option value="edit" <?php echo $changeType === 'edit' ? 'selected' : ''; ?>>Edit</option>
                            <option value="publish" <?php echo $changeType === 'publish' ? 'selected' : ''; ?>>Publish</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo e($dateTo); ?>">
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm mr-2">Filter</button>
                        <a href="audit.php?tab=audit&academic_year_id=<?php echo (int)$selectedAcademicYearId; ?>&semester_number=<?php echo (int)$selectedSemesterNumber; ?>&program_id=<?php echo (int)$selectedProgramId; ?>&level_year=<?php echo (int)$selectedLevelYear; ?>" class="btn btn-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Audit Records -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-clipboard-list"></i> Audit Records (<?php echo count($auditRecords); ?>)</span>
            </div>
            <div class="card-body">
                <?php if (empty($auditRecords)): ?>
                    <p class="text-center text-muted">No audit records found.</p>
                <?php else: ?>
                    <div class="table-responsive" style="overflow-x: auto; max-width: 100%;">
                        <table class="table table-sm table-hover marks-table audit-table" style="margin-bottom: 0;">
                            <thead class="thead-light">
                                <tr>
                                    <th style="min-width: 40px;">#</th>
                                    <th style="min-width: 240px;">Student Name</th>
                                    <th style="min-width: 120px;">Reg. No.</th>
                                    <th style="min-width: 80px;" class="text-center">CW /40</th>
                                    <th style="min-width: 80px;" class="text-center">Exam /60</th>
                                    <th style="min-width: 70px;" class="text-center">Total</th>
                                    <th style="min-width: 70px;" class="text-center">Grade</th>
                                    <th style="min-width: 110px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $formatMark = static function ($value) {
                                        if ($value === null || $value === '') {
                                            return '-';
                                        }
                                        if (is_numeric($value)) {
                                            $val = (float)$value;
                                            $intVal = (int)$val;
                                            if (abs($val - $intVal) < 0.0001) {
                                                return (string)$intVal;
                                            }
                                            return number_format($val, 1);
                                        }
                                        return (string)$value;
                                    };
                                    $rowIndex = 1;
                                    foreach ($auditRecords as $record):
                                        $oldMarks = json_decode($record['old_marks'], true) ?: [];
                                        $newMarks = json_decode($record['new_marks'], true) ?: [];
                                        $changedBy = trim((string)($record['admin_first'] . ' ' . $record['admin_last']));
                                        if ($changedBy === '') {
                                            $changedBy = (string)($record['changed_by_username'] ?? 'Unknown');
                                        }
                                        $studentName = trim((string)(($record['student_first'] ?? '') . ' ' . ($record['student_last'] ?? '')));
                                        $courseLabel = trim((string)(($record['course_code'] ?? '') . ' ' . ($record['course_name'] ?? '')));
                                        $changeType = (string)($record['change_type'] ?? '');
                                ?>
                                    <tr>
                                        <td class="text-center"><?php echo $rowIndex++; ?></td>
                                        <td>
                                            <strong><?php echo e($studentName); ?></strong><br>
                                            <small class="text-muted">Course: <?php echo e($courseLabel); ?></small><br>
                                            <small class="text-muted">Date: <?php echo date('M d, Y H:i', strtotime($record['changed_at'])); ?></small><br>
                                            <small class="text-muted">Type: <span class="badge badge-<?php echo $changeType === 'publish' ? 'success' : 'warning'; ?>"><?php echo ucfirst($changeType); ?></span></small><br>
                                            <small class="text-muted">By: <?php echo e($changedBy); ?></small>
                                            <?php if (!empty($record['reason'])): ?>
                                                <br><small class="text-muted">Reason: <?php echo e($record['reason']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo e($record['reg_no']); ?>
                                        </td>
                                        <td class="text-center">
                                            <div><small class="text-muted">Old:</small> <?php echo e($formatMark($oldMarks['assignment_marks'] ?? null)); ?></div>
                                            <div><small class="text-muted">New:</small> <?php echo e($formatMark($newMarks['assignment_marks'] ?? null)); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <div><small class="text-muted">Old:</small> <?php echo e($formatMark($oldMarks['final_exam_marks'] ?? null)); ?></div>
                                            <div><small class="text-muted">New:</small> <?php echo e($formatMark($newMarks['final_exam_marks'] ?? null)); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <div><small class="text-muted">Old:</small> <?php echo e($formatMark($oldMarks['total_marks'] ?? null)); ?></div>
                                            <div><small class="text-muted">New:</small> <?php echo e($formatMark($newMarks['total_marks'] ?? null)); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <div><small class="text-muted">Old:</small> <?php echo e($formatMark($oldMarks['grade'] ?? null)); ?></div>
                                            <div><small class="text-muted">New:</small> <?php echo e($formatMark($newMarks['grade'] ?? null)); ?></div>
                                        </td>
                                        <td style="white-space: nowrap; min-width: 100px;">
                                            <a href="submitted.php?academic_year_id=<?php echo $record['academic_year_id']; ?>&semester_number=<?php echo $record['semester_number']; ?>&level_year=<?php echo (int)($record['course_level_year'] ?? 0); ?>&program_id=<?php echo (int)($record['course_program_id'] ?? 0); ?>&course_id=<?php echo $record['course_id']; ?>" class="btn btn-sm btn-primary" title="Edit Marks for this Course">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="view-slip.php?student_id=<?php echo $record['student_id']; ?>&academic_year_id=<?php echo $record['academic_year_id']; ?>&semester_number=<?php echo $record['semester_number']; ?>" class="btn btn-sm btn-info" title="View Student Results">
                                                <i class="fas fa-eye"></i>
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
        <?php endif; ?>
        <!-- End of Tabs -->
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
