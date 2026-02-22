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
$report = $_GET['report'] ?? 'executive';
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

/**
 * Safe scalar query helper (returns fallback on errors).
 */
function safeScalar($conn, $sql, $params = [], $fallback = 0) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? $fallback : $value;
    } catch (Exception $e) {
        return $fallback;
    }
}

/**
 * Safe rows query helper (returns [] on errors).
 */
function safeRows($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
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
if ($report === 'executive') {
    $currentSemester = Helper::getCurrentSemester();
    $currentSemesterId = (int)($currentSemester['id'] ?? 0);
    if (!$semesterId && $currentSemesterId > 0) {
        $semesterId = $currentSemesterId;
    }

    $data['kpis'] = [
        'students_total' => (int)safeScalar($conn, "SELECT COUNT(*) FROM students"),
        'students_active' => (int)safeScalar($conn, "SELECT COUNT(*) FROM students WHERE status = 'active'"),
        'lecturers_total' => (int)safeScalar($conn, "SELECT COUNT(*) FROM lecturers"),
        'lecturers_active' => (int)safeScalar($conn, "SELECT COUNT(*) FROM lecturers WHERE status = 'active'"),
        'courses_total' => (int)safeScalar($conn, "SELECT COUNT(*) FROM courses"),
        'registrations_total' => (int)safeScalar($conn, "SELECT COUNT(*) FROM course_registrations"),
        'registrations_approved' => (int)safeScalar($conn, "SELECT COUNT(*) FROM course_registrations WHERE status = 'approved'"),
        'results_published' => (int)safeScalar($conn, "SELECT COUNT(*) FROM results WHERE status = 'published'"),
        'payments_collected' => (float)safeScalar($conn, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) BETWEEN :from AND :to", ['from' => $from, 'to' => $to], 0),
        'invoiced_total' => (float)safeScalar($conn, "SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE DATE(created_at) BETWEEN :from AND :to", ['from' => $from, 'to' => $to], 0),
        'outstanding_total' => (float)safeScalar($conn, "SELECT COALESCE(SUM(balance),0) FROM student_balances", [], 0),
        'pending_requests' => (int)safeScalar($conn, "SELECT COUNT(*) FROM student_requests WHERE status = 'pending'", [], 0),
        'email_failures_open' => (int)safeScalar($conn, "SELECT COUNT(*) FROM email_delivery_failures WHERE status IN ('failed','resend_failed')", [], 0)
    ];

    $monthsWindowStart = date('Y-m-01', strtotime('-11 months', strtotime($to)));
    $data['enrollment_12m'] = safeRows(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS cnt
         FROM students
         WHERE DATE(created_at) BETWEEN :from AND :to
         GROUP BY period
         ORDER BY period",
        ['from' => $monthsWindowStart, 'to' => $to]
    );
    $data['collections_12m'] = safeRows(
        $conn,
        "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS period, COALESCE(SUM(amount),0) AS total
         FROM payments
         WHERE DATE(payment_date) BETWEEN :from AND :to
         GROUP BY period
         ORDER BY period",
        ['from' => $monthsWindowStart, 'to' => $to]
    );
    $data['results_status'] = safeRows(
        $conn,
        "SELECT status, COUNT(*) AS cnt
         FROM results
         GROUP BY status
         ORDER BY cnt DESC"
    );

    $data['module_totals'] = [
        ['label' => 'Students', 'value' => (int)$data['kpis']['students_total']],
        ['label' => 'Lecturers', 'value' => (int)$data['kpis']['lecturers_total']],
        ['label' => 'Courses', 'value' => (int)$data['kpis']['courses_total']],
        ['label' => 'Registrations', 'value' => (int)$data['kpis']['registrations_total']],
        ['label' => 'Results', 'value' => (int)safeScalar($conn, "SELECT COUNT(*) FROM results")],
        ['label' => 'Invoices', 'value' => (int)safeScalar($conn, "SELECT COUNT(*) FROM invoices")]
    ];

    $data['program_mix'] = safeRows(
        $conn,
        "SELECT p.program_name, COUNT(s.id) AS cnt
         FROM students s
         LEFT JOIN programs p ON p.id = s.program_id
         GROUP BY p.id, p.program_name
         ORDER BY cnt DESC
         LIMIT 8"
    );
}

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
    if (!$semesterId) {
        $semesterId = (int)safeScalar($conn, "SELECT semester_id FROM course_assignments ORDER BY assigned_date DESC, id DESC LIMIT 1", [], 0);
    }
    $staffSemesterParams = [];
    $staffCourseFilter = '';
    $staffRegFilter = '';
    if ($semesterId > 0) {
        $staffCourseFilter = " AND ca.semester_id = :semester_id ";
        $staffRegFilter = " AND cr.semester_id = :semester_id ";
        $staffSemesterParams['semester_id'] = $semesterId;
    }

    $lecturerRows = safeRows(
        $conn,
        "SELECT l.id, CONCAT(l.first_name, ' ', l.last_name) AS staff_name,
                COUNT(DISTINCT ca.course_id) AS courses_assigned,
                COALESCE(SUM(crs.reg_count),0) AS students_registered
         FROM lecturers l
         LEFT JOIN course_assignments ca ON ca.lecturer_id = l.id $staffCourseFilter
         LEFT JOIN (
             SELECT course_id, COUNT(*) AS reg_count
             FROM course_registrations
             WHERE 1=1 $staffRegFilter
             GROUP BY course_id
         ) crs ON crs.course_id = ca.course_id
         GROUP BY l.id, staff_name
         ORDER BY courses_assigned DESC, students_registered DESC",
        $staffSemesterParams
    );

    $financeParams = ['from' => $from, 'to' => $to];
    $financeSemesterFilter = '';
    if ($semesterId) {
        $financeSemesterFilter = " AND p.semester_id = :semester_id ";
        $financeParams['semester_id'] = $semesterId;
    }

    $financeRows = safeRows(
        $conn,
        "SELECT u.id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(fs.first_name,''), ' ', COALESCE(fs.last_name,''))), ''), u.username) AS staff_name,
                COUNT(p.id) AS payments_processed,
                COALESCE(SUM(p.amount),0) AS amount_collected
         FROM users u
         LEFT JOIN finance_staff fs ON fs.user_id = u.id
         LEFT JOIN payments p
            ON p.received_by = u.id
           AND DATE(p.payment_date) BETWEEN :from AND :to
           $financeSemesterFilter
         WHERE u.role = 'finance' AND u.status = 'active'
         GROUP BY u.id, staff_name
         ORDER BY payments_processed DESC, amount_collected DESC",
        $financeParams
    );

    if (empty($financeRows)) {
        $financeRows = safeRows(
            $conn,
            "SELECT u.id,
                    u.username AS staff_name,
                    COUNT(p.id) AS payments_processed,
                    COALESCE(SUM(p.amount),0) AS amount_collected
             FROM users u
             LEFT JOIN payments p
                ON p.received_by = u.id
               AND DATE(p.payment_date) BETWEEN :from AND :to
               $financeSemesterFilter
             WHERE u.role = 'finance' AND u.status = 'active'
             GROUP BY u.id, u.username
             ORDER BY payments_processed DESC, amount_collected DESC",
            $financeParams
        );
    }

    $courseAssignmentRows = safeRows(
        $conn,
        "SELECT c.id AS course_id,
                c.course_code,
                c.course_name,
                COUNT(DISTINCT ca.lecturer_id) AS lecturer_count,
                GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) ORDER BY l.last_name SEPARATOR ', ') AS lecturers
         FROM course_assignments ca
         INNER JOIN courses c ON c.id = ca.course_id
         INNER JOIN lecturers l ON l.id = ca.lecturer_id
         WHERE (:semester_id = 0 OR ca.semester_id = :semester_id)
         GROUP BY c.id, c.course_code, c.course_name
         ORDER BY c.course_code ASC",
        ['semester_id' => (int)$semesterId]
    );

    $data['staff_lecturers'] = $lecturerRows;
    $data['staff_finance'] = $financeRows;
    $data['staff_course_assignments'] = $courseAssignmentRows;
    $data['staff'] = [];

    foreach ($lecturerRows as $row) {
        $data['staff'][] = [
            'staff_type' => 'Lecturer',
            'name' => $row['staff_name'],
            'primary_label' => 'Courses Assigned',
            'primary_value' => (int)$row['courses_assigned'],
            'secondary_label' => 'Students Registered',
            'secondary_value' => (int)$row['students_registered']
        ];
    }
    foreach ($financeRows as $row) {
        $data['staff'][] = [
            'staff_type' => 'Finance Operator',
            'name' => $row['staff_name'],
            'primary_label' => 'Payments Processed',
            'primary_value' => (int)$row['payments_processed'],
            'secondary_label' => 'Amount Collected',
            'secondary_value' => (float)$row['amount_collected']
        ];
    }

    $data['staff_totals'] = [
        'lecturers_count' => count($lecturerRows),
        'finance_count' => count($financeRows),
        'courses_assigned_total' => (int)array_sum(array_map(function ($r) { return (int)$r['courses_assigned']; }, $lecturerRows)),
        'students_registered_total' => (int)array_sum(array_map(function ($r) { return (int)$r['students_registered']; }, $lecturerRows)),
        'payments_processed_total' => (int)array_sum(array_map(function ($r) { return (int)$r['payments_processed']; }, $financeRows)),
        'amount_collected_total' => (float)array_sum(array_map(function ($r) { return (float)$r['amount_collected']; }, $financeRows))
    ];
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
    if ($report === 'executive') {
        fputcsv($out, ['Metric','Value']);
        fputcsv($out, ['Total Students', $data['kpis']['students_total'] ?? 0]);
        fputcsv($out, ['Active Students', $data['kpis']['students_active'] ?? 0]);
        fputcsv($out, ['Total Lecturers', $data['kpis']['lecturers_total'] ?? 0]);
        fputcsv($out, ['Active Lecturers', $data['kpis']['lecturers_active'] ?? 0]);
        fputcsv($out, ['Total Courses', $data['kpis']['courses_total'] ?? 0]);
        fputcsv($out, ['Registrations (All)', $data['kpis']['registrations_total'] ?? 0]);
        fputcsv($out, ['Registrations (Approved)', $data['kpis']['registrations_approved'] ?? 0]);
        fputcsv($out, ['Published Results', $data['kpis']['results_published'] ?? 0]);
        fputcsv($out, ['Payments Collected', Helper::formatCurrencyDual((float)($data['kpis']['payments_collected'] ?? 0), 'UGX')]);
        fputcsv($out, ['Total Invoiced', Helper::formatCurrencyDual((float)($data['kpis']['invoiced_total'] ?? 0), 'UGX')]);
        fputcsv($out, ['Outstanding Balance', Helper::formatCurrencyDual((float)($data['kpis']['outstanding_total'] ?? 0), 'UGX')]);
        fputcsv($out, ['Pending Student Requests', $data['kpis']['pending_requests'] ?? 0]);
        fputcsv($out, ['Open Email Failures', $data['kpis']['email_failures_open'] ?? 0]);
        fputcsv($out, []);
        fputcsv($out, ['Month', 'New Students', 'Collections (USD/UGX)']);
        $enrollMap = [];
        foreach (($data['enrollment_12m'] ?? []) as $r) $enrollMap[$r['period']] = (int)$r['cnt'];
        $collectMap = [];
        foreach (($data['collections_12m'] ?? []) as $r) $collectMap[$r['period']] = (float)$r['total'];
        $months = array_unique(array_merge(array_keys($enrollMap), array_keys($collectMap)));
        sort($months);
        foreach ($months as $m) {
            fputcsv($out, [$m, $enrollMap[$m] ?? 0, Helper::formatCurrencyDual((float)($collectMap[$m] ?? 0), 'UGX')]);
        }
    } elseif ($report === 'enrollment') {
        fputcsv($out, ['Period','New Students']);
        foreach ($data['series'] as $r) fputcsv($out, [$r['period'],$r['cnt']]);
        fputcsv($out, []);
        fputcsv($out, ['Program','Count']);
        foreach ($data['by_program'] as $r) fputcsv($out, [$r['program_name'],$r['cnt']]);
    } elseif ($report === 'financial') {
        fputcsv($out, ['Period','Collections (USD/UGX)']);
        foreach ($data['collections'] as $r) fputcsv($out, [$r['period'], Helper::formatCurrencyDual((float)$r['total'], 'UGX')]);
        fputcsv($out, []);
        fputcsv($out, ['Metric','Value']);
        fputcsv($out, ['Total Invoiced', Helper::formatCurrencyDual((float)$data['totals']['invoiced'], 'UGX')]);
        fputcsv($out, ['Total Collected', Helper::formatCurrencyDual((float)$data['totals']['collected'], 'UGX')]);
        fputcsv($out, ['Outstanding Balances', Helper::formatCurrencyDual((float)$data['totals']['outstanding'], 'UGX')]);
    } elseif ($report === 'system') {
        fputcsv($out, ['Semester','New Students','Registrations Total','Registrations Approved','Payments Collected','Invoices Issued','Outstanding Balances','Results Published','Courses Offered','Lecturers Assigned','Avg GPA']);
        foreach ($data['system'] as $r) {
            fputcsv($out, [
                $r['semester_name'],
                $r['new_students'],
                $r['registrations_total'],
                $r['registrations_approved'],
                Helper::formatCurrencyDual((float)$r['payments_collected'], 'UGX'),
                $r['invoices_issued'],
                Helper::formatCurrencyDual((float)$r['outstanding_balances'], 'UGX'),
                $r['results_published'],
                $r['courses_offered'],
                $r['lecturers_assigned'],
                $r['avg_gpa']
            ]);
        }
    } else {
        fputcsv($out, ['Staff Type','Name','Primary Metric','Primary Value','Secondary Metric','Secondary Value']);
        foreach (($data['staff'] ?? []) as $r) {
            $secondary = $r['secondary_label'] === 'Amount Collected'
                ? Helper::formatCurrencyDual((float)$r['secondary_value'], 'UGX')
                : $r['secondary_value'];
            fputcsv($out, [
                $r['staff_type'],
                $r['name'],
                $r['primary_label'],
                $r['primary_value'],
                $r['secondary_label'],
                $secondary
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Course Code', 'Course Name', 'Lecturers Assigned', 'Lecturer Count']);
        foreach (($data['staff_course_assignments'] ?? []) as $r) {
            fputcsv($out, [
                $r['course_code'] ?? '',
                $r['course_name'] ?? '',
                $r['lecturers'] ?? '',
                (int)($r['lecturer_count'] ?? 0)
            ]);
        }
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

    <style>
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }
        .main-content, .content-area {
            max-width: 100%;
            overflow-x: hidden;
        }
        .content-area .card {
            overflow: hidden;
        }
        .content-area .table-responsive {
            max-width: 100%;
            overflow-x: auto;
        }
        .content-area form.form-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
        }
        .content-area form.form-inline .form-control {
            max-width: 100%;
        }
        .content-area form.form-inline .ml-auto {
            margin-left: 0 !important;
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-start;
        }
        @media (min-width: 992px) {
            .content-area form.form-inline .ml-auto {
                justify-content: flex-end;
            }
        }
        .report-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #1d4ed8 100%);
            color: #f8fafc;
            border-radius: 16px;
            padding: 22px 24px;
            margin-bottom: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.22);
        }
        .report-hero h5 {
            margin: 0 0 8px 0;
            font-weight: 700;
            letter-spacing: 0.2px;
        }
        .report-hero p {
            margin: 0;
            color: rgba(248, 250, 252, 0.85);
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .kpi-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
            min-height: 92px;
        }
        .kpi-card .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }
        .kpi-card .value {
            font-size: 26px;
            line-height: 1;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 5px;
        }
        .kpi-card .sub {
            font-size: 12px;
            color: #475569;
            margin: 0;
        }
        .report-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
            margin-bottom: 16px;
        }
        .report-panel h6 {
            margin-bottom: 10px;
            font-weight: 700;
            color: #0f172a;
        }
        .report-canvas {
            height: 280px !important;
            width: 100% !important;
        }
        .mini-note {
            font-size: 12px;
            color: #64748b;
        }
        html[data-theme='dark'] .content-area.container-fluid {
            color: #e2e8f0;
        }
        html[data-theme='dark'] .content-area .card {
            background: #0b1220;
            border-color: #1e293b;
            box-shadow: 0 8px 24px rgba(2, 6, 23, 0.45);
        }
        html[data-theme='dark'] .report-hero {
            background: linear-gradient(135deg, #0b1220 0%, #1e293b 52%, #1d4ed8 100%);
            box-shadow: 0 14px 32px rgba(2, 6, 23, 0.5);
        }
        html[data-theme='dark'] .report-hero p {
            color: rgba(226, 232, 240, 0.9);
        }
        html[data-theme='dark'] .kpi-card {
            background: #111a2b;
            border-color: #243047;
            box-shadow: 0 6px 20px rgba(2, 6, 23, 0.45);
        }
        html[data-theme='dark'] .kpi-card .label {
            color: #93c5fd;
        }
        html[data-theme='dark'] .kpi-card .value {
            color: #f8fafc;
        }
        html[data-theme='dark'] .kpi-card .sub {
            color: #cbd5e1;
        }
        html[data-theme='dark'] .report-panel {
            background: #111a2b;
            border-color: #243047;
            box-shadow: 0 6px 20px rgba(2, 6, 23, 0.42);
        }
        html[data-theme='dark'] .report-panel h6 {
            color: #e2e8f0;
        }
        html[data-theme='dark'] .mini-note {
            color: #94a3b8;
        }
        html[data-theme='dark'] .content-area .table {
            color: #e2e8f0;
        }
        html[data-theme='dark'] .content-area .table thead th {
            background: #152238;
            border-color: #26354d;
            color: #cbd5e1;
        }
        html[data-theme='dark'] .content-area .table td {
            border-color: #223047;
        }
        html[data-theme='dark'] .content-area .table-hover tbody tr:hover {
            background: rgba(59, 130, 246, 0.14);
        }
        html[data-theme='dark'] .content-area a {
            color: #93c5fd;
        }
        html[data-theme='dark'] .content-area a:hover {
            color: #bfdbfe;
        }
        html[data-theme='dark'] .content-area .btn-outline-secondary {
            color: #cbd5e1;
            border-color: #334155;
        }
        html[data-theme='dark'] .content-area .btn-outline-secondary:hover {
            background: #1f2937;
            color: #f8fafc;
        }
    </style>

    <div class="content-area container-fluid p-4">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="form-inline mb-3">
                    <label class="mr-2">Report:</label>
                    <select name="report" class="form-control mr-2" onchange="this.form.submit()">
                        <option value="executive" <?php echo $report=='executive'?'selected':''; ?>>Executive Summary</option>
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

                <?php if ($report === 'executive'): ?>
                    <?php
                        $kpi = $data['kpis'] ?? [];
                        $approvalRate = (!empty($kpi['registrations_total']) && (int)$kpi['registrations_total'] > 0)
                            ? round(((int)$kpi['registrations_approved'] * 100) / (int)$kpi['registrations_total'], 1)
                            : 0;
                        $collectionRate = (!empty($kpi['invoiced_total']) && (float)$kpi['invoiced_total'] > 0)
                            ? round(((float)$kpi['payments_collected'] * 100) / (float)$kpi['invoiced_total'], 1)
                            : 0;
                    ?>
                    <div class="report-hero">
                        <h5>Executive Summary</h5>
                        <p>Cross-module snapshot for stakeholder demos, covering academics, finance, staffing, results, and operational risk from <?php echo e($from); ?> to <?php echo e($to); ?>.</p>
                    </div>

                    <div class="kpi-grid">
                        <div class="kpi-card">
                            <div class="label">Students</div>
                            <div class="value"><?php echo number_format((int)($kpi['students_total'] ?? 0)); ?></div>
                            <p class="sub">Active: <?php echo number_format((int)($kpi['students_active'] ?? 0)); ?></p>
                        </div>
                        <div class="kpi-card">
                            <div class="label">Lecturers</div>
                            <div class="value"><?php echo number_format((int)($kpi['lecturers_total'] ?? 0)); ?></div>
                            <p class="sub">Active: <?php echo number_format((int)($kpi['lecturers_active'] ?? 0)); ?></p>
                        </div>
                        <div class="kpi-card">
                            <div class="label">Courses</div>
                            <div class="value"><?php echo number_format((int)($kpi['courses_total'] ?? 0)); ?></div>
                            <p class="sub">Registrations: <?php echo number_format((int)($kpi['registrations_total'] ?? 0)); ?></p>
                        </div>
                        <div class="kpi-card">
                            <div class="label">Published Results</div>
                            <div class="value"><?php echo number_format((int)($kpi['results_published'] ?? 0)); ?></div>
                            <p class="sub">Approval rate: <?php echo e($approvalRate); ?>%</p>
                        </div>
                        <div class="kpi-card">
                            <div class="label">Collected Amount</div>
                            <div class="value" style="font-size:20px;"><?php echo Helper::formatCurrencyDual((float)($kpi['payments_collected'] ?? 0), 'UGX'); ?></div>
                            <p class="sub">Collection rate: <?php echo e($collectionRate); ?>%</p>
                        </div>
                        <div class="kpi-card">
                            <div class="label">Outstanding Balance</div>
                            <div class="value" style="font-size:20px;"><?php echo Helper::formatCurrencyDual((float)($kpi['outstanding_total'] ?? 0), 'UGX'); ?></div>
                            <p class="sub">Pending requests: <?php echo number_format((int)($kpi['pending_requests'] ?? 0)); ?></p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="report-panel">
                                <h6>Enrollment vs Collections (Last 12 Months)</h6>
                                <canvas id="executiveTrendChart" class="report-canvas"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="report-panel">
                                <h6>Module Footprint</h6>
                                <canvas id="executiveModuleChart" class="report-canvas"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="report-panel">
                                <h6>Result Status Distribution</h6>
                                <canvas id="executiveResultsChart" class="report-canvas"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="report-panel">
                                <h6>Program Mix (Top Programs)</h6>
                                <canvas id="executiveProgramChart" class="report-canvas"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="report-panel">
                        <h6>Operational Signals</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Indicator</th>
                                        <th>Value</th>
                                        <th>Commentary</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Approved Registrations</td>
                                        <td><?php echo number_format((int)($kpi['registrations_approved'] ?? 0)); ?> / <?php echo number_format((int)($kpi['registrations_total'] ?? 0)); ?></td>
                                        <td class="mini-note">Conversion from requests to approved course load.</td>
                                    </tr>
                                    <tr>
                                        <td>Invoice vs Collection</td>
                                        <td><?php echo Helper::formatCurrencyDual((float)($kpi['invoiced_total'] ?? 0), 'UGX'); ?> vs <?php echo Helper::formatCurrencyDual((float)($kpi['payments_collected'] ?? 0), 'UGX'); ?></td>
                                        <td class="mini-note">Cash realization over the selected reporting period.</td>
                                    </tr>
                                    <tr>
                                        <td>Pending Student Requests</td>
                                        <td><?php echo number_format((int)($kpi['pending_requests'] ?? 0)); ?></td>
                                        <td class="mini-note">Operational backlog needing admin action.</td>
                                    </tr>
                                    <tr>
                                        <td>Email Delivery Failures</td>
                                        <td><?php echo number_format((int)($kpi['email_failures_open'] ?? 0)); ?></td>
                                        <td class="mini-note">Communication risk for credentials and notifications.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($report === 'enrollment'): ?>
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
                        <div class="col-md-4"><div class="card p-3"><strong>Total Invoiced</strong><div><?php echo Helper::formatCurrencyDual((float)$data['totals']['invoiced'], 'UGX'); ?></div></div></div>
                        <div class="col-md-4"><div class="card p-3"><strong>Total Collected</strong><div><?php echo Helper::formatCurrencyDual((float)$data['totals']['collected'], 'UGX'); ?></div></div></div>
                        <div class="col-md-4"><div class="card p-3"><strong>Outstanding Balances</strong><div><?php echo Helper::formatCurrencyDual((float)$data['totals']['outstanding'], 'UGX'); ?></div></div></div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover data-table">
                            <thead><tr><th>Period</th><th>Collections</th></tr></thead>
                            <tbody>
                                <?php foreach($data['collections'] as $r): ?>
                                    <tr><td><?php echo e($r['period']); ?></td><td><?php echo Helper::formatCurrencyDual((float)$r['total'], 'UGX'); ?></td></tr>
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
                                        <td><?php echo Helper::formatCurrencyDual((float)$row['payments_collected'], 'UGX'); ?></td>
                                        <td><?php echo e($row['invoices_issued']); ?></td>
                                        <td><?php echo Helper::formatCurrencyDual((float)$row['outstanding_balances'], 'UGX'); ?></td>
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
                    <p class="text-muted">Includes teaching workload (lecturers) and transaction workload (finance operators) for the selected period.</p>

                    <?php $staffTotals = $data['staff_totals'] ?? []; ?>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="card p-3">
                                <strong>Lecturers</strong>
                                <div><?php echo number_format((int)($staffTotals['lecturers_count'] ?? 0)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card p-3">
                                <strong>Courses Assigned</strong>
                                <div><?php echo number_format((int)($staffTotals['courses_assigned_total'] ?? 0)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card p-3">
                                <strong>Finance Operators</strong>
                                <div><?php echo number_format((int)($staffTotals['finance_count'] ?? 0)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card p-3">
                                <strong>Payments Processed</strong>
                                <div><?php echo number_format((int)($staffTotals['payments_processed_total'] ?? 0)); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-7">
                            <h6>Lecturer Workload</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-hover data-table">
                                    <thead><tr><th>Lecturer</th><th>Courses Assigned</th><th>Students Registered</th></tr></thead>
                                    <tbody>
                                        <?php foreach(($data['staff_lecturers'] ?? []) as $r): ?>
                                            <tr>
                                                <td><a href="lecturer.php?lecturer_id=<?php echo $r['id']; ?>&semester_id=<?php echo $semesterId; ?>"><?php echo e($r['staff_name']); ?></a></td>
                                                <td><?php echo e($r['courses_assigned']); ?></td>
                                                <td><?php echo e($r['students_registered']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <h6>Finance Operator Workload</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-hover data-table">
                                    <thead><tr><th>Operator</th><th>Payments</th><th>Amount Collected</th></tr></thead>
                                    <tbody>
                                        <?php foreach(($data['staff_finance'] ?? []) as $r): ?>
                                            <tr>
                                                <td><?php echo e($r['staff_name']); ?></td>
                                                <td><?php echo e($r['payments_processed']); ?></td>
                                                <td><?php echo Helper::formatCurrencyDual((float)$r['amount_collected'], 'UGX'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <h6>Course Assignments by Lecturer</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover data-table">
                            <thead><tr><th>Course</th><th>Course Name</th><th>Lecturers Assigned</th><th>Count</th></tr></thead>
                            <tbody>
                                <?php foreach(($data['staff_course_assignments'] ?? []) as $r): ?>
                                    <tr>
                                        <td>
                                            <?php if ($semesterId > 0): ?>
                                                <a href="course_students.php?course_id=<?php echo (int)$r['course_id']; ?>&semester_id=<?php echo (int)$semesterId; ?>">
                                                    <?php echo e($r['course_code'] ?: ('Course #' . (int)$r['course_id'])); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo e($r['course_code'] ?: ('Course #' . (int)$r['course_id'])); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($r['course_name']); ?></td>
                                        <td><?php echo e($r['lecturers'] ?: 'Not assigned'); ?></td>
                                        <td><?php echo number_format((int)($r['lecturer_count'] ?? 0)); ?></td>
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
    const isDarkMode = document.documentElement.getAttribute('data-theme') === 'dark';
    if (window.Chart && isDarkMode) {
        Chart.defaults.color = '#cbd5e1';
        Chart.defaults.borderColor = 'rgba(148, 163, 184, 0.28)';
        Chart.defaults.plugins.legend.labels.color = '#cbd5e1';
    }

    // Enrollment chart data from PHP
    <?php if ($report === 'executive'): ?>
        const exEnrollMap = <?php
            $tmp = [];
            foreach (($data['enrollment_12m'] ?? []) as $r) { $tmp[$r['period']] = (int)$r['cnt']; }
            echo json_encode($tmp);
        ?>;
        const exCollectMap = <?php
            $tmp = [];
            foreach (($data['collections_12m'] ?? []) as $r) { $tmp[$r['period']] = (float)$r['total']; }
            echo json_encode($tmp);
        ?>;
        const exMonths = Array.from(new Set([].concat(Object.keys(exEnrollMap), Object.keys(exCollectMap)))).sort();
        const exEnrollment = exMonths.map(m => exEnrollMap[m] || 0);
        const exCollections = exMonths.map(m => exCollectMap[m] || 0);

        const trendCtx = document.getElementById('executiveTrendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: exMonths,
                    datasets: [
                        {
                            label: 'New Students',
                            data: exEnrollment,
                            yAxisID: 'y',
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37,99,235,0.12)',
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Collections',
                            data: exCollections,
                            yAxisID: 'y1',
                            borderColor: '#16a34a',
                            backgroundColor: 'rgba(22,163,74,0.12)',
                            tension: 0.3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y: { beginAtZero: true, position: 'left' },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
                    }
                }
            });
        }

        const modCtx = document.getElementById('executiveModuleChart');
        if (modCtx) {
            const moduleRows = <?php echo json_encode($data['module_totals'] ?? []); ?>;
            new Chart(modCtx, {
                type: 'doughnut',
                data: {
                    labels: moduleRows.map(r => r.label),
                    datasets: [{
                        data: moduleRows.map(r => Number(r.value || 0)),
                        backgroundColor: ['#2563eb','#0ea5e9','#14b8a6','#22c55e','#f59e0b','#a855f7']
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
            });
        }

        const resultCtx = document.getElementById('executiveResultsChart');
        if (resultCtx) {
            const statusRows = <?php echo json_encode($data['results_status'] ?? []); ?>;
            new Chart(resultCtx, {
                type: 'bar',
                data: {
                    labels: statusRows.map(r => (r.status || 'unknown').toUpperCase()),
                    datasets: [{
                        label: 'Results',
                        data: statusRows.map(r => Number(r.cnt || 0)),
                        backgroundColor: '#1d4ed8'
                    }]
                },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }

        const programCtx = document.getElementById('executiveProgramChart');
        if (programCtx) {
            const programRows = <?php echo json_encode($data['program_mix'] ?? []); ?>;
            new Chart(programCtx, {
                type: 'bar',
                data: {
                    labels: programRows.map(r => r.program_name || 'Unspecified'),
                    datasets: [{
                        label: 'Students',
                        data: programRows.map(r => Number(r.cnt || 0)),
                        backgroundColor: '#0ea5e9'
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: { legend: { display: false } }
                }
            });
        }
    <?php elseif ($report === 'enrollment'): ?>
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
