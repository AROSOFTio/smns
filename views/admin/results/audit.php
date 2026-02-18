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

// Build query
$sql = "SELECT ra.*, 
               s.student_id AS reg_no, s.first_name AS student_first, s.last_name AS student_last,
               c.course_code, c.course_name,
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
                      c.course_code, c.course_name,
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

$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

$pageTitle = 'Results Audit Trail - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-history"></i> Results Audit Trail</h4>
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

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'published' ? 'active' : ''; ?>" href="?tab=published">
                    <i class="fas fa-search"></i> Search Published Results
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'audit' ? 'active' : ''; ?>" href="?tab=audit">
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
                    <div class="col-md-4">
                        <label>Student (Name or Reg#)</label>
                        <input type="text" name="published_student" class="form-control" value="<?php echo e($publishedSearch); ?>" placeholder="e.g. Kevin Birungi or 2024/U/001">
                    </div>
                    <div class="col-md-4">
                        <label>Course (Code or Name)</label>
                        <input type="text" name="published_course" class="form-control" value="<?php echo e($publishedCourse); ?>" placeholder="e.g. CSC101 or Programming">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary mr-2"><i class="fas fa-search"></i> Search</button>
                        <a href="?tab=published" class="btn btn-secondary">Reset</a>
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
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Semester</th>
                                    <th>CW (40%)</th>
                                    <th>Exam (60%)</th>
                                    <th>Total</th>
                                    <th>Grade</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($publishedResults as $pr): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($pr['reg_no']); ?></strong><br>
                                            <small><?php echo e($pr['student_first'] . ' ' . $pr['student_last']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo e($pr['course_code']); ?></strong><br>
                                            <small><?php echo e($pr['course_name']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo e($pr['academic_year_name']); ?><br>
                                            <small>Semester <?php echo e($pr['semester_number']); ?></small>
                                        </td>
                                        <td class="text-center"><?php echo $pr['assignment_marks'] !== null ? round($pr['assignment_marks'], 1) : '-'; ?></td>
                                        <td class="text-center"><?php echo $pr['final_exam_marks'] !== null ? round($pr['final_exam_marks'], 1) : '-'; ?></td>
                                        <td class="text-center"><strong><?php echo $pr['total_marks'] !== null ? round($pr['total_marks']) : '-'; ?></strong></td>
                                        <td class="text-center"><span class="badge badge-info"><?php echo e($pr['grade']); ?></span></td>
                                        <td style="white-space: nowrap;">
                                            <a href="submitted.php?academic_year_id=<?php echo $pr['academic_year_id']; ?>&semester_number=<?php echo $pr['semester_number']; ?>&course_id=<?php echo $pr['course_id']; ?>" class="btn btn-sm btn-warning" title="Edit Marks">
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
                    <div class="col-md-2">
                        <label>Student (Name/Reg#)</label>
                        <input type="text" name="student" class="form-control form-control-sm" value="<?php echo e($studentSearch); ?>" placeholder="Search student...">
                    </div>
                    <div class="col-md-2">
                        <label>Course</label>
                        <input type="text" name="course" class="form-control form-control-sm" value="<?php echo e($courseSearch); ?>" placeholder="Course code/name...">
                    </div>
                    <div class="col-md-2">
                        <label>Change Type</label>
                        <select name="change_type" class="form-control form-control-sm">
                            <option value="">All</option>
                            <option value="edit" <?php echo $changeType === 'edit' ? 'selected' : ''; ?>>Edit</option>
                            <option value="publish" <?php echo $changeType === 'publish' ? 'selected' : ''; ?>>Publish</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="col-md-2">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo e($dateTo); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm mr-2">Filter</button>
                        <a href="audit.php?tab=audit" class="btn btn-secondary btn-sm">Reset</a>
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
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Type</th>
                                    <th>Old Marks</th>
                                    <th>New Marks</th>
                                    <th>Changed By</th>
                                    <th>Reason</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditRecords as $record): 
                                    $oldMarks = json_decode($record['old_marks'], true) ?: [];
                                    $newMarks = json_decode($record['new_marks'], true) ?: [];
                                ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i', strtotime($record['changed_at'])); ?></td>
                                        <td>
                                            <strong><?php echo e($record['reg_no']); ?></strong><br>
                                            <small><?php echo e($record['student_first'] . ' ' . $record['student_last']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo e($record['course_code']); ?></strong><br>
                                            <small><?php echo e($record['course_name']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $record['change_type'] === 'publish' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($record['change_type']); ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php if (!empty($oldMarks)): ?>
                                                CW: <?php echo $oldMarks['assignment_marks'] ?? '-'; ?><br>
                                                Exam: <?php echo $oldMarks['final_exam_marks'] ?? '-'; ?><br>
                                                Total: <?php echo $oldMarks['total_marks'] ?? '-'; ?><br>
                                                Grade: <?php echo $oldMarks['grade'] ?? '-'; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php if (!empty($newMarks)): ?>
                                                CW: <?php echo $newMarks['assignment_marks'] ?? '-'; ?><br>
                                                Exam: <?php echo $newMarks['final_exam_marks'] ?? '-'; ?><br>
                                                Total: <?php echo $newMarks['total_marks'] ?? '-'; ?><br>
                                                Grade: <?php echo $newMarks['grade'] ?? '-'; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $changedBy = trim($record['admin_first'] . ' ' . $record['admin_last']);
                                            echo $changedBy ?: e($record['changed_by_username'] ?? 'Unknown');
                                            ?>
                                        </td>
                                        <td style="max-width: 150px; font-size: 0.85rem;">
                                            <?php echo e($record['reason'] ?? '-'); ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <a href="submitted.php?academic_year_id=<?php echo $record['academic_year_id']; ?>&semester_number=<?php echo $record['semester_number']; ?>&course_id=<?php echo $record['course_id']; ?>" class="btn btn-sm btn-primary" title="Edit Marks for this Course">
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

<style>
.content-area {
    max-width: 100%;
    overflow-x: hidden;
    padding: 15px;
}
.table-responsive {
    overflow-x: auto;
}
.table th, .table td {
    vertical-align: middle;
}
.btn-sm {
    padding: 0.2rem 0.4rem;
    font-size: 0.75rem;
}
.nav-tabs .nav-link {
    color: #495057;
}
.nav-tabs .nav-link.active {
    font-weight: bold;
    background-color: #fff;
    border-color: #dee2e6 #dee2e6 #fff;
}
</style>

<?php include '../../../includes/footer.php'; ?>
