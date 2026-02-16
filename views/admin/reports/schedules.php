<?php
/**
 * Admin - Scheduled Reports Management
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

// Ensure table exists
$conn->exec("CREATE TABLE IF NOT EXISTS scheduled_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    report_type ENUM('enrollment','financial','staff','system') NOT NULL,
    frequency ENUM('weekly','monthly') NOT NULL,
    schedule_value INT NULL,
    time_of_day TIME NOT NULL DEFAULT '00:00:00',
    recipients TEXT NOT NULL,
    filters TEXT NULL,
    last_sent_at DATETIME NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Ensure enum includes 'system' in case table existed before (silent attempt)
try {
    $conn->exec("ALTER TABLE scheduled_reports MODIFY COLUMN report_type ENUM('enrollment','financial','staff','system') NOT NULL");
} catch (Exception $e) {
    // ignore - DB may not support or column already matches
}

// Helpers
function sendScheduledReportNow($conn, $schedule) {
    // Generate CSV for schedule.report_type using filters
    $filters = json_decode($schedule['filters'] ?? '{}', true) ?: [];
    $type = $schedule['report_type'];
    $csv = '';

    if ($type === 'enrollment') {
        $from = $filters['from'] ?? date('Y-01-01');
        $to = $filters['to'] ?? date('Y-m-d');
        $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as period, COUNT(*) as cnt FROM students WHERE DATE(created_at) BETWEEN :from AND :to GROUP BY period ORDER BY period");
        $stmt->execute(['from' => $from, 'to' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $csv .= "Period,New Students\n";
        foreach ($rows as $r) $csv .= "{$r['period']},{$r['cnt']}\n";
    } elseif ($type === 'financial') {
        $from = $filters['from'] ?? date('Y-01-01');
        $to = $filters['to'] ?? date('Y-m-d');
        $stmt = $conn->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as period, COALESCE(SUM(amount),0) as total FROM payments WHERE DATE(payment_date) BETWEEN :from AND :to GROUP BY period ORDER BY period");
        $stmt->execute(['from' => $from, 'to' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $csv .= "Period,Collections\n";
        foreach ($rows as $r) $csv .= "{$r['period']},{$r['total']}\n";
    } elseif ($type === 'system') {
        // system overview: semester-by-semester metrics for configured academic year
        $semesterId = $filters['semester_id'] ?? 0;
        $ay = $filters['academic_year_id'] ?? 0;
        if ($ay) {
            $semSt = $conn->prepare("SELECT id, semester_name, start_date, end_date FROM semesters WHERE academic_year_id = :ay ORDER BY semester_number");
            $semSt->execute(['ay' => $ay]);
            $sems = $semSt->fetchAll(PDO::FETCH_ASSOC);
            $csv .= "Semester,New Students,Registrations Total,Registrations Approved,Payments Collected,Invoices Issued,Outstanding Balances,Results Published,Courses Offered,Lecturers Assigned,Avg GPA\n";
            foreach ($sems as $sem) {
                $sid = $sem['id'];
                $sStart = $sem['start_date']; $sEnd = $sem['end_date'];
                $ns = $conn->prepare("SELECT COUNT(*) FROM students WHERE (entry_semester_id = :sid OR (DATE(created_at) BETWEEN :start AND :end))"); $ns->execute(['sid'=>$sid,'start'=>$sStart,'end'=>$sEnd]); $newStudents = $ns->fetchColumn();
                $rt = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE semester_id = :sid"); $rt->execute(['sid'=>$sid]); $regTotal = $rt->fetchColumn();
                $ra = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE semester_id = :sid AND status = 'approved'"); $ra->execute(['sid'=>$sid]); $regApproved = $ra->fetchColumn();
                $pc = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE semester_id = :sid"); $pc->execute(['sid'=>$sid]); $paymentsCollected = $pc->fetchColumn();
                $inv = $conn->prepare("SELECT COUNT(*) FROM invoices WHERE semester_id = :sid"); $inv->execute(['sid'=>$sid]); $invoicesIssued = $inv->fetchColumn();
                $out = $conn->prepare("SELECT COALESCE(SUM(balance),0) FROM student_balances WHERE semester_id = :sid"); $out->execute(['sid'=>$sid]); $outstanding = $out->fetchColumn();
                $res = $conn->prepare("SELECT COUNT(*) FROM results WHERE semester_id = :sid AND status = 'published'"); $res->execute(['sid'=>$sid]); $resultsPublished = $res->fetchColumn();
                $co = $conn->prepare("SELECT COUNT(DISTINCT course_id) FROM course_assignments WHERE semester_id = :sid"); $co->execute(['sid'=>$sid]); $coursesOffered = $co->fetchColumn();
                $la = $conn->prepare("SELECT COUNT(DISTINCT lecturer_id) FROM course_assignments WHERE semester_id = :sid"); $la->execute(['sid'=>$sid]); $lecturersAssigned = $la->fetchColumn();
                $gpaS = $conn->prepare("SELECT AVG(semester_gpa) FROM student_gpas WHERE semester_id = :sid"); $gpaS->execute(['sid'=>$sid]); $avgGpa = $gpaS->fetchColumn();
                $csv .= "{$sem['semester_name']},{$newStudents},{$regTotal},{$regApproved},{$paymentsCollected},{$invoicesIssued},{$outstanding},{$resultsPublished},{$coursesOffered},{$lecturersAssigned},{$avgGpa}\n";
            }
        }
    } else {
        // staff -> list lecturers + courses assigned for semester
        $semesterId = $filters['semester_id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
        $stmt = $conn->prepare("SELECT l.id, CONCAT(l.first_name,' ',l.last_name) as lecturer, COUNT(DISTINCT ca.course_id) AS courses_assigned
                                 FROM lecturers l
                                 LEFT JOIN course_assignments ca ON ca.lecturer_id = l.id AND ca.semester_id = :sid
                                 GROUP BY l.id");
        $stmt->execute(['sid' => $semesterId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $csv .= "Lecturer,Courses Assigned\n";
        foreach ($rows as $r) $csv .= "{$r['lecturer']},{$r['courses_assigned']}\n";
    }

    // save CSV to downloads
    $filename = 'scheduled_report_' . $schedule['id'] . '_' . date('Ymd_His') . '.csv';
    $filePath = BASE_PATH . '/downloads/' . $filename;
    file_put_contents($filePath, $csv);

    // send email with link
    $recips = array_map('trim', explode(',', $schedule['recipients']));
    $to = implode(',', $recips);
    $subject = APP_NAME . ' - Scheduled Report: ' . $schedule['name'];
    $body = "The requested scheduled report is ready.\n\nDownload: " . BASE_URL . '/downloads/' . $filename . "\n\nRegards,\n" . APP_NAME;
    $headers = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>\r\n';
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

    $sent = mail($to, $subject, $body, $headers);

    // update last_sent_at
    $u = $conn->prepare("UPDATE scheduled_reports SET last_sent_at = NOW() WHERE id = :id");
    $u->execute(['id' => $schedule['id']]);

    return $sent;
}

// Handle actions (create, delete, toggle, send_now)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error','Invalid CSRF token');
        header('Location: schedules.php'); exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = Security::sanitize($_POST['name'] ?? '');
        $report_type = $_POST['report_type'] ?? 'enrollment';
        $frequency = $_POST['frequency'] ?? 'weekly';
        $schedule_value = (int)($_POST['schedule_value'] ?? 0);
        $time_of_day = $_POST['time_of_day'] ?? '00:00';
        $recipients = trim($_POST['recipients'] ?? '');
        $filters = [];
        $filters['program_id'] = (int)($_POST['program_id'] ?? 0);
        $filters['semester_id'] = (int)($_POST['semester_id'] ?? 0);
        $filters['academic_year_id'] = (int)($_POST['academic_year_id'] ?? 0);
        $filters['from'] = Security::sanitize($_POST['from'] ?? '');
        $filters['to'] = Security::sanitize($_POST['to'] ?? '');

        // validate recipients
        $emails = array_filter(array_map('trim', explode(',', $recipients)));
        foreach ($emails as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                setFlash('error', 'Invalid recipient email: ' . e($em));
                header('Location: schedules.php'); exit;
            }
        }

        $ins = $conn->prepare("INSERT INTO scheduled_reports (name, report_type, frequency, schedule_value, time_of_day, recipients, filters, active) VALUES (:name,:report_type,:frequency,:schedule_value,:time_of_day,:recipients,:filters,1)");
        $ins->execute([
            'name'=>$name,'report_type'=>$report_type,'frequency'=>$frequency,'schedule_value'=>$schedule_value,'time_of_day'=>$time_of_day . ':00','recipients'=>$recipients,'filters'=>json_encode($filters)
        ]);
        setFlash('success','Scheduled report created');
        header('Location: schedules.php'); exit;
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->prepare("DELETE FROM scheduled_reports WHERE id = :id")->execute(['id'=>$id]);
        setFlash('success','Schedule deleted'); header('Location: schedules.php'); exit;
    }

    if ($action === 'toggle' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $row = $conn->prepare("SELECT active FROM scheduled_reports WHERE id = :id")->execute(['id'=>$id]);
        $cur = $conn->prepare("SELECT active FROM scheduled_reports WHERE id = :id"); $cur->execute(['id'=>$id]); $val = $cur->fetchColumn();
        $new = $val ? 0 : 1;
        $conn->prepare("UPDATE scheduled_reports SET active = :a WHERE id = :id")->execute(['a'=>$new,'id'=>$id]);
        setFlash('success','Schedule updated'); header('Location: schedules.php'); exit;
    }

    if ($action === 'send_now' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $sstmt = $conn->prepare("SELECT * FROM scheduled_reports WHERE id = :id");
        $sstmt->execute(['id'=>$id]);
        $schedule = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($schedule) {
            $sent = sendScheduledReportNow($conn, $schedule);
            setFlash('success', $sent ? 'Report sent' : 'Report queued (mail function returned false)');

            // Log + notify admins
            try {
                $logger = new Logger();
                $currentUser = $auth->getCurrentUser();
                $logger->log($currentUser['id'] ?? 0, 'scheduled_report_sent', 'reports', 'Send now: ' . $schedule['name'] . ' -> ' . ($sent ? 'ok' : 'failed'));

                $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
                $title = ($sent ? 'Scheduled report sent' : 'Scheduled report failed') . ' — ' . $schedule['name'];
                $msg = ($sent ? 'Report generated and emailed.' : 'Report failed to send.');
                foreach ($admins as $a) { try { $noteStmt->execute(['uid'=>$a['id'],'title'=>$title,'msg'=>$msg,'type'=>$sent ? 'success' : 'error','link'=>BASE_URL . '/views/admin/reports/schedules.php']); } catch (Exception $e) {} }
            } catch (Exception $e) {}
        }
        header('Location: schedules.php'); exit;
    }
}

// Fetch schedules
$schedules = $conn->query("SELECT * FROM scheduled_reports ORDER BY active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Helpers for select options
$programs = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_ASSOC);
$semesters = $conn->query("SELECT s.id, CONCAT(ay.year_name,' - ', s.semester_name) AS label FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.year_name DESC, s.semester_number DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Scheduled Reports - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content" id="mainContent">
    <div class="topbar"><div class="topbar-left"><h4>Scheduled Reports</h4></div></div>
    <div class="content-area container p-4">
        <?php if ($session->getFlash('success')): ?><div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div><?php endif; ?>
        <?php if ($session->getFlash('error')): ?><div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div><?php endif; ?>

        <div class="card mb-3"><div class="card-body">
            <h5>Create Scheduled Report</h5>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <input type="text" name="name" class="form-control mr-2" placeholder="Name (eg. Weekly Enrollment)" required>
                <select name="report_type" class="form-control mr-2">
                    <option value="enrollment">Enrollment</option>
                    <option value="financial">Financial</option>
                    <option value="staff">Staff Workload</option>
                    <option value="system">System Overview</option>
                </select>
                <select name="frequency" class="form-control mr-2" id="freqSelect">
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
                <select name="schedule_value" class="form-control mr-2" id="schedVal">
                    <!-- default weekly days -->
                    <?php foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $i => $d): ?>
                        <option value="<?php echo $i; ?>"><?php echo $d; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="time" name="time_of_day" class="form-control mr-2" value="08:00" required>
                <input type="text" name="recipients" class="form-control mr-2" placeholder="Comma-separated emails" style="min-width:320px" required>
                <br><br>
                <div class="w-100 mt-2">
                    <label class="mr-2">Academic Year</label>
                    <select name="academic_year_id" class="form-control mr-2">
                        <option value="0">Any</option>
                        <?php foreach($academicYears as $ay): ?>
                            <option value="<?php echo $ay['id']; ?>"><?php echo e($ay['year_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2">Program</label>
                    <select name="program_id" class="form-control mr-2">
                        <option value="0">All</option>
                        <?php foreach($programs as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo e($p['program_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="mr-2">Semester</label>
                    <select name="semester_id" class="form-control mr-2">
                        <option value="0">Current</option>
                        <?php foreach($semesters as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo e($s['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="mr-2">From</label>
                    <input type="date" name="from" class="form-control mr-2">
                    <label class="mr-2">To</label>
                    <input type="date" name="to" class="form-control mr-2">
                    <button class="btn btn-primary ml-2">Create</button>
                </div>
            </form>
        </div></div>

        <div class="card">
            <div class="card-body">
                <h5>Existing Schedules</h5>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Name</th><th>Report</th><th>Frequency</th><th>Schedule</th><th>Time</th><th>Recipients</th><th>Last Sent</th><th>Active</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach($schedules as $s): ?>
                                <tr>
                                    <td><?php echo e($s['name']); ?></td>
                                    <td><?php echo e($s['report_type']); ?></td>
                                    <td><?php echo e($s['frequency']); ?></td>
                                    <td><?php echo $s['frequency']=='weekly' ? jddayofweek($s['schedule_value'],1) : 'Day ' . $s['schedule_value']; ?></td>
                                    <td><?php echo e(substr($s['time_of_day'],0,5)); ?></td>
                                    <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis"><?php echo e($s['recipients']); ?></td>
                                    <td><?php echo e($s['last_sent_at'] ?: '-'); ?></td>
                                    <td><?php echo $s['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td>
                                    <td>
                                        <form method="POST" style="display:inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                            <button name="action" value="toggle" class="btn btn-sm btn-warning">Toggle</button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Send now to recipients?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                            <button name="action" value="send_now" class="btn btn-sm btn-info">Send Now</button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete schedule?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                            <button name="action" value="delete" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>
<script>
// Switch schedule_value options when frequency changes
document.getElementById('freqSelect').addEventListener('change', function(){
    var sel = document.getElementById('schedVal');
    sel.innerHTML = '';
    if (this.value === 'weekly') {
        var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        days.forEach(function(d,i){ var o = document.createElement('option'); o.value = i; o.text = d; sel.appendChild(o); });
    } else {
        for (var i=1;i<=28;i++){ var o=document.createElement('option'); o.value=i; o.text='Day '+i; sel.appendChild(o); }
    }
});
</script>