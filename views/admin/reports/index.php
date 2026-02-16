<?php
/**
 * Admin Reports - Enrollment, Financial, Staff Workload
 * Supports: on-page charts/tables + exports (CSV / Excel) + printable PDF-friendly view
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}
$db = new Database();
$conn = $db->getConnection();

// Inputs / filters
$report = $_GET['report'] ?? 'enrollment';
$export = $_GET['export'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
// Academic year (used by System Overview)
$academicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;

// Helper: normalize dates
function normDate($d, $fallback = null) {
    if (!$d) return $fallback;
    $dt = date_create($d);
    return $dt ? $dt->format('Y-m-d') : $fallback;
}

// Default ranges
$today = date('Y-m-d');
if (!$to) $to = $today;
if (!$from) $from = date('Y-m-d', strtotime('-12 months', strtotime($to))); // 12 months default
$from = normDate($from, date('Y-01-01'));
$to = normDate($to, $today);

// Fetch auxiliaries
$programs = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name")->fetchAll();
$semesters = $conn->query("SELECT s.id, CONCAT(ay.year_name, ' - ', s.semester_name) AS label FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.year_name DESC, s.semester_number DESC")->fetchAll();
$academicYears = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetchAll();

// DATA QUERIES
$data = [];
if ($report === 'enrollment') {
    // Enrollment trends (new students by month)
    $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as period, COUNT(*) as cnt
                            FROM students
                            WHERE DATE(created_at) BETWEEN :from AND :to
                            " . ($programId ? " AND program_id = :program_id" : '') . "
                            GROUP BY period
                            ORDER BY period");
    $params = ['from' => $from, 'to' => $to];
    if ($programId) $params['program_id'] = $programId;
    $stmt->execute($params);
    $data['series'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Breakdown by program (top programs) — include program id for drill-down
    $bstmt = $conn->prepare("SELECT p.id AS program_id, p.program_name, COUNT(s.id) as cnt
                             FROM students s
                             LEFT JOIN programs p ON s.program_id = p.id
                             WHERE DATE(s.created_at) BETWEEN :from AND :to
                             GROUP BY p.id, p.program_name
                             ORDER BY cnt DESC
                             LIMIT 10");
    $bstmt->execute(['from' => $from, 'to' => $to]);
    $data['by_program'] = $bstmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($report === 'financial') {
    // Collections by month
    $stmt = $conn->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as period, COALESCE(SUM(amount),0) as total
                            FROM payments
                            WHERE DATE(payment_date) BETWEEN :from AND :to
                            GROUP BY period
                            ORDER BY period");
    $stmt->execute(['from' => $from, 'to' => $to]);
    $data['collections'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals
    $totals = [];
    $t1 = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) as invoiced FROM invoices WHERE DATE(created_at) BETWEEN :from AND :to");
    $t1->execute(['from' => $from, 'to' => $to]); $totals['invoiced'] = $t1->fetchColumn();
    $t2 = $conn->prepare("SELECT COALESCE(SUM(amount),0) as collected FROM payments WHERE DATE(payment_date) BETWEEN :from AND :to");
    $t2->execute(['from' => $from, 'to' => $to]); $totals['collected'] = $t2->fetchColumn();
    $t3 = $conn->prepare("SELECT COALESCE(SUM(balance),0) as outstanding FROM student_balances");
    $t3->execute(); $totals['outstanding'] = $t3->fetchColumn();
    $data['totals'] = $totals;
}

if ($report === 'staff') {
    // Staff workload for a semester (default to current semester if none selected)
    if (!$semesterId) {
        $currentSem = Helper::getCurrentSemester();
        $semesterId = $currentSem['id'] ?? 0;
    }

    $stmt = $conn->prepare("SELECT l.id, CONCAT(l.first_name, ' ', l.last_name) as lecturer,
                                   COUNT(DISTINCT ca.course_id) AS courses_assigned,
                                   COALESCE(SUM(crs.reg_count),0) AS students_registered
                            FROM lecturers l
                            LEFT JOIN course_assignments ca ON ca.lecturer_id = l.id AND ca.semester_id = :semester_id
                            LEFT JOIN (
                                SELECT course_id, semester_id, COUNT(*) AS reg_count
                                FROM course_registrations
                                WHERE semester_id = :semester_id
                                GROUP BY course_id, semester_id
                            ) crs ON crs.course_id = ca.course_id AND crs.semester_id = ca.semester_id
                            GROUP BY l.id
                            ORDER BY courses_assigned DESC, students_registered DESC");
    $stmt->execute(['semester_id' => $semesterId]);
    $data['staff'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// System Overview (academic year → semester by semester)
if ($report === 'system') {
    // default academic year: use current semester's academic year if not provided
    if (!$academicYearId) {
        $curSem = Helper::getCurrentSemester();
        $academicYearId = $curSem['academic_year_id'] ?? 0;
    }

    $semStmt = $conn->prepare("SELECT id, semester_name, start_date, end_date, semester_number FROM semesters WHERE academic_year_id = :ay ORDER BY semester_number ASC");
    $semStmt->execute(['ay' => $academicYearId]);
    $yearSemesters = $semStmt->fetchAll(PDO::FETCH_ASSOC);

    $systemRows = [];
    foreach ($yearSemesters as $sem) {
        $sid = $sem['id'];
        $sStart = $sem['start_date'] ?? null;
        $sEnd = $sem['end_date'] ?? null;

        // new students (entry_semester_id OR created_at within semester)
        $nsStmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE (entry_semester_id = :sid OR (DATE(created_at) BETWEEN :start AND :end))");
        $nsStmt->execute(['sid' => $sid, 'start' => $sStart, 'end' => $sEnd]);
        $newStudents = (int)$nsStmt->fetchColumn();

        // registrations
        $regTotal = (int)$conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE semester_id = :sid")->execute(['sid' => $sid]) ? 0 : 0; // placeholder to avoid uninitialized
        $rt = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE semester_id = :sid"); $rt->execute(['sid'=>$sid]); $regTotal = (int)$rt->fetchColumn();
        $ra = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE semester_id = :sid AND status = 'approved'"); $ra->execute(['sid'=>$sid]); $regApproved = (int)$ra->fetchColumn();

        // financials
        $pc = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE semester_id = :sid"); $pc->execute(['sid'=>$sid]); $paymentsCollected = (float)$pc->fetchColumn();
        $inv = $conn->prepare("SELECT COUNT(*) FROM invoices WHERE semester_id = :sid"); $inv->execute(['sid'=>$sid]); $invoicesIssued = (int)$inv->fetchColumn();
        $out = $conn->prepare("SELECT COALESCE(SUM(balance),0) FROM student_balances WHERE semester_id = :sid"); $out->execute(['sid'=>$sid]); $outstanding = (float)$out->fetchColumn();

        // results
        $res = $conn->prepare("SELECT COUNT(*) FROM results WHERE semester_id = :sid AND status = 'published'"); $res->execute(['sid'=>$sid]); $resultsPublished = (int)$res->fetchColumn();

        // courses & lecturers
        $co = $conn->prepare("SELECT COUNT(DISTINCT course_id) FROM course_assignments WHERE semester_id = :sid"); $co->execute(['sid'=>$sid]); $coursesOffered = (int)$co->fetchColumn();
        $la = $conn->prepare("SELECT COUNT(DISTINCT lecturer_id) FROM course_assignments WHERE semester_id = :sid"); $la->execute(['sid'=>$sid]); $lecturersAssigned = (int)$la->fetchColumn();

        // avg GPA
        $gpaS = $conn->prepare("SELECT AVG(semester_gpa) FROM student_gpas WHERE semester_id = :sid"); $gpaS->execute(['sid'=>$sid]); $avgGpa = $gpaS->fetchColumn();
        $avgGpa = $avgGpa ? round((float)$avgGpa, 2) : null;

        $systemRows[] = [
            'semester_id' => $sid,
            'semester_name' => $sem['semester_name'],
            'new_students' => $newStudents,
            'registrations_total' => $regTotal,
            'registrations_approved' => $regApproved,
            'payments_collected' => $paymentsCollected,
            'invoices_issued' => $invoicesIssued,
            'outstanding_balances' => $outstanding,
            'results_published' => $resultsPublished,
            'courses_offered' => $coursesOffered,
            'lecturers_assigned' => $lecturersAssigned,
            'avg_gpa' => $avgGpa
        ];
    }

    $data['system'] = $systemRows;
}

// EXPORT handling (CSV / Excel)
if ($export && in_array($export, ['csv','excel'])) {
    // Build filename
    $filename = $report . '_report_' . date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    if ($export === 'excel') header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.' . ($export === 'excel' ? 'xls' : 'csv') . '"');

    $out = fopen('php://output', 'w');
    // Rows depend on report
    if ($report === 'enrollment') {
        fputcsv($out, ['Period','New Students']);
        foreach ($data['series'] as $r) fputcsv($out, [$r['period'],$r['cnt']]);
        fputcsv($out, []);
        fputcsv($out, ['Program','Count']);
        foreach ($data['by_program'] as $r) fputcsv($out, [$r['program_name'],$r['cnt']]);
    } elseif ($report === 'financial') {
        fputcsv($out, ['Period','Collections']);
        foreach ($data['collections'] as $r) fputcsv($out, [$r['period'],$r['total']]);
        fputcsv($out, []);
        fputcsv($out, ['Metric','Value']);
        fputcsv($out, ['Total Invoiced',$data['totals']['invoiced']]);
        fputcsv($out, ['Total Collected',$data['totals']['collected']]);
        fputcsv($out, ['Outstanding Balances',$data['totals']['outstanding']]);
    } elseif ($report === 'system') {
        fputcsv($out, ['Semester','New Students','Registrations Total','Registrations Approved','Payments Collected','Invoices Issued','Outstanding Balances','Results Published','Courses Offered','Lecturers Assigned','Avg GPA']);
        foreach ($data['system'] as $r) {
            fputcsv($out, [
                $r['semester_name'],
                $r['new_students'],
                $r['registrations_total'],
                $r['registrations_approved'],
                $r['payments_collected'],
                $r['invoices_issued'],
                $r['outstanding_balances'],
                $r['results_published'],
                $r['courses_offered'],
                $r['lecturers_assigned'],
                $r['avg_gpa']
            ]);
        }
    } else {
        fputcsv($out, ['Lecturer','Courses Assigned','Students Registered']);
        foreach ($data['staff'] as $r) fputcsv($out, [$r['lecturer'],$r['courses_assigned'],$r['students_registered']]);
    }
    fclose($out);
    exit;
}

// Render page
$pageTitle = 'Reports - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>Reports</h4>
        </div>
    </div>

    <div class="content-area container-fluid p-4">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-3">
                    <label class="mr-2">Report:</label>
                    <select name="report" class="form-control mr-2" onchange="this.form.submit()">
                        <option value="enrollment" <?php echo $report=='enrollment'?'selected':''; ?>>Enrollment Trends</option>
                        <option value="financial" <?php echo $report=='financial'?'selected':''; ?>>Financial Summary</option>
                        <option value="staff" <?php echo $report=='staff'?'selected':''; ?>>Staff Workload</option>
                        <option value="system" <?php echo $report=='system'?'selected':''; ?>>System Overview</option>
                    </select>

                    <label class="mr-2 ml-3">From</label>
                    <input type="date" name="from" class="form-control mr-2" value="<?php echo e($from); ?>">

                    <label class="mr-2">To</label>
                    <input type="date" name="to" class="form-control mr-2" value="<?php echo e($to); ?>">

                    <label class="mr-2 ml-3">Academic Year</label>
                    <select name="academic_year_id" class="form-control mr-2" onchange="this.form.submit()">
                        <option value="">(Current)</option>
                        <?php foreach($academicYears as $ay): ?>
                            <option value="<?php echo $ay['id']; ?>" <?php echo $academicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2 ml-3">Semester</label>
                    <select name="semester_id" class="form-control mr-2" onchange="this.form.submit()">
                        <option value="">(Current)</option>
                        <?php foreach($semesters as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $semesterId == $s['id'] ? 'selected' : ''; ?>><?php echo e($s['label']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2 ml-3">Program</label>
                    <select name="program_id" class="form-control mr-2" onchange="this.form.submit()">
                        <option value="">All Programs</option>
                        <?php foreach($programs as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $programId == $p['id'] ? 'selected' : ''; ?>><?php echo e($p['program_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="ml-auto">
                        <a href="schedules.php" class="btn btn-primary btn-sm mr-2">Schedules</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>" class="btn btn-outline-secondary">Export CSV</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'excel'])); ?>" class="btn btn-outline-secondary">Export Excel</a>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">PDF / Print</button>
                    </div>
                </form>

                <?php if ($report === 'enrollment'): ?>
                    <h5>Enrollment Trends (<?php echo e($from); ?> → <?php echo e($to); ?>)</h5>
                    <canvas id="enrollmentChart" style="height:240px;"></canvas>
                    <hr>
                    <h6>By Month</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover data-table">
                            <thead><tr><th>Period</th><th>New Students</th></tr></thead>
                            <tbody>
                                <?php foreach($data['series'] as $r): ?>
                                    <tr><td><?php echo e($r['period']); ?></td><td><?php echo e($r['cnt']); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h6 class="mt-3">Top Programs (<?php echo e($from); ?> → <?php echo e($to); ?>)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover data-table">
                            <thead><tr><th>Program</th><th>Count</th></tr></thead>
                            <tbody>
                                <?php foreach($data['by_program'] as $r): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($r['program_id'])): ?>
                                                <a href="program.php?program_id=<?php echo $r['program_id']; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>"><?php echo e($r['program_name'] ?: 'Unspecified'); ?></a>
                                            <?php else: ?>
                                                <?php echo e($r['program_name'] ?: 'Unspecified'); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($r['cnt']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($report === 'financial'): ?>
                    <h5>Financial Summary (<?php echo e($from); ?> → <?php echo e($to); ?>)</h5>
                    <canvas id="financialChart" style="height:240px;"></canvas>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-md-4"><div class="card p-3"><strong>Total Invoiced</strong><div><?php echo Helper::formatCurrency($data['totals']['invoiced']); ?></div></div></div>
                        <div class="col-md-4"><div class="card p-3"><strong>Total Collected</strong><div><?php echo Helper::formatCurrency($data['totals']['collected']); ?></div></div></div>
                        <div class="col-md-4"><div class="card p-3"><strong>Outstanding Balances</strong><div><?php echo Helper::formatCurrency($data['totals']['outstanding']); ?></div></div></div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover data-table">
                            <thead><tr><th>Period</th><th>Collections</th></tr></thead>
                            <tbody>
                                <?php foreach($data['collections'] as $r): ?>
                                    <tr><td><?php echo e($r['period']); ?></td><td><?php echo Helper::formatCurrency($r['total']); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($report === 'system'): ?>
                    <h5>System Overview — Academic Year: <?php echo e($academicYearId ? array_column($academicYears,'year_name','id')[$academicYearId] ?? $academicYearId : 'Current'); ?></h5>
                    <p class="text-muted">Consolidated metrics across modules for each semester in the selected academic year.</p>

                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover data-table">
                            <thead>
                                <tr>
                                    <th>Semester</th>
                                    <th>New Students</th>
                                    <th>Registrations (Total)</th>
                                    <th>Approved</th>
                                    <th>Payments Collected</th>
                                    <th>Invoices</th>
                                    <th>Outstanding</th>
                                    <th>Results Published</th>
                                    <th>Courses Offered</th>
                                    <th>Lecturers Assigned</th>
                                    <th>Avg GPA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($data['system'] as $row): ?>
                                    <tr>
                                        <td><?php echo e($row['semester_name']); ?></td>
                                        <td><?php echo e($row['new_students']); ?></td>
                                        <td><?php echo e($row['registrations_total']); ?></td>
                                        <td><?php echo e($row['registrations_approved']); ?></td>
                                        <td><?php echo Helper::formatCurrency($row['payments_collected']); ?></td>
                                        <td><?php echo e($row['invoices_issued']); ?></td>
                                        <td><?php echo Helper::formatCurrency($row['outstanding_balances']); ?></td>
                                        <td><?php echo e($row['results_published']); ?></td>
                                        <td><?php echo e($row['courses_offered']); ?></td>
                                        <td><?php echo e($row['lecturers_assigned']); ?></td>
                                        <td><?php echo e($row['avg_gpa'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mb-3">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>" class="btn btn-outline-secondary">Export CSV</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export'=>'excel'])); ?>" class="btn btn-outline-secondary">Export Excel</a>
                    </div>

                <?php else: ?>
                    <h5>Staff Workload — Semester: <?php echo e($semesterId ? array_column($semesters,'label','id')[$semesterId] ?? $semesterId : 'Current'); ?></h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover data-table">
                            <thead><tr><th>Lecturer</th><th>Courses Assigned</th><th>Students Registered</th></tr></thead>
                            <tbody>
                                <?php foreach($data['staff'] as $r): ?>
                                    <tr>
                                        <td><a href="lecturer.php?lecturer_id=<?php echo $r['id']; ?>&semester_id=<?php echo $semesterId; ?>"><?php echo e($r['lecturer']); ?></a></td>
                                        <td><?php echo e($r['courses_assigned']); ?></td>
                                        <td><?php echo e($r['students_registered']); ?></td>
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

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
(function(){
    // Enrollment chart data from PHP
    <?php if ($report === 'enrollment'): ?>
        const enrollmentLabels = <?php echo json_encode(array_column($data['series'],'period')); ?>;
        const enrollmentData = <?php echo json_encode(array_map(function($r){return (int)$r['cnt'];}, $data['series'])); ?>;
        const ctxE = document.getElementById('enrollmentChart');
        if (ctxE) new Chart(ctxE, { type: 'line', data: { labels: enrollmentLabels, datasets: [{ label: 'New Students', data: enrollmentData, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)', tension:0.3 }] }, options: { responsive:true } });
    <?php elseif ($report === 'financial'): ?>
        const finLabels = <?php echo json_encode(array_column($data['collections'],'period')); ?>;
        const finData = <?php echo json_encode(array_map(function($r){return (float)$r['total'];}, $data['collections'])); ?>;
        const ctxF = document.getElementById('financialChart');
        if (ctxF) new Chart(ctxF, { type: 'bar', data: { labels: finLabels, datasets: [{ label: 'Collections', data: finData, backgroundColor: '#10b981' }] }, options: { responsive:true } });
    <?php endif; ?>
})();
</script>

<?php include '../../../includes/footer.php'; ?>
