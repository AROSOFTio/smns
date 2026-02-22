<?php
/**
 * CLI script: send_scheduled_reports.php
 * Run from cron (example):
 * 0 * * * * php /path/to/smns/scripts/send_scheduled_reports.php >> /var/log/smns/scheduled_reports.log 2>&1
 */
require_once __DIR__ . '/../config.php';
$db = new Database();
$conn = $db->getConnection();

// Fetch active schedules
$sstmt = $conn->query("SELECT * FROM scheduled_reports WHERE active = 1");
$schedules = $sstmt->fetchAll(PDO::FETCH_ASSOC);
$now = new DateTime();
$today = (int)$now->format('j');
$wday = (int)$now->format('w');
$hour = (int)$now->format('H');

foreach ($schedules as $s) {
    $due = false;
    $schTime = new DateTime($s['time_of_day']);
    $schHour = (int)$schTime->format('H');

    if ($s['frequency'] === 'weekly') {
        $dow = (int)$s['schedule_value'];
        if ($dow === $wday) {
            // not sent today
            $last = $s['last_sent_at'] ? new DateTime($s['last_sent_at']) : null;
            if (!$last || $last->format('Y-m-d') !== $now->format('Y-m-d')) {
                if ($hour >= $schHour) $due = true;
            }
        }
    } else {
        $dom = (int)$s['schedule_value'];
        if ($dom === $today) {
            $last = $s['last_sent_at'] ? new DateTime($s['last_sent_at']) : null;
            $lastMonth = $last ? (int)$last->format('n') : 0;
            if (!$last || ($lastMonth !== (int)$now->format('n') || (int)$last->format('Y') !== (int)$now->format('Y'))) {
                if ($hour >= $schHour) $due = true;
            }
        }
    }

    if ($due) {
        echo "[".date('Y-m-d H:i:s')."] Sending scheduled report: {$s['name']} (id={$s['id']})\n";
        // reuse logic similar to admin schedules page
        $filters = json_decode($s['filters'] ?? '{}', true) ?: [];
        $type = $s['report_type'];
        $csv = '';
        $csvEscape = function ($value) {
            $str = str_replace('"', '""', (string)$value);
            return '"' . $str . '"';
        };

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
            $csv .= "Period,Collections (USD/UGX)\n";
            foreach ($rows as $r) {
                $csv .= implode(',', [
                    $csvEscape($r['period']),
                    $csvEscape(Helper::formatCurrencyDual((float)$r['total'], 'UGX'))
                ]) . "\n";
            }
        } elseif ($type === 'system') {
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
                    $csv .= implode(',', [
                        $csvEscape($sem['semester_name']),
                        $csvEscape($newStudents),
                        $csvEscape($regTotal),
                        $csvEscape($regApproved),
                        $csvEscape(Helper::formatCurrencyDual((float)$paymentsCollected, 'UGX')),
                        $csvEscape($invoicesIssued),
                        $csvEscape(Helper::formatCurrencyDual((float)$outstanding, 'UGX')),
                        $csvEscape($resultsPublished),
                        $csvEscape($coursesOffered),
                        $csvEscape($lecturersAssigned),
                        $csvEscape($avgGpa)
                    ]) . "\n";
                }
            }
        } else {
            $semesterId = $filters['semester_id'] ?? (Helper::getCurrentSemester()['id'] ?? 0);
            if (!$semesterId) {
                try {
                    $semesterId = (int)($conn->query("SELECT semester_id FROM course_assignments ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
                } catch (Exception $e) {
                    $semesterId = 0;
                }
            }
            $from = $filters['from'] ?? date('Y-01-01');
            $to = $filters['to'] ?? date('Y-m-d');
            $stmt = $conn->prepare("SELECT l.id,
                                           CONCAT(l.first_name,' ',l.last_name) AS staff_name,
                                           COUNT(DISTINCT ca.course_id) AS courses_assigned,
                                           COALESCE(SUM(crs.reg_count),0) AS students_registered
                                    FROM lecturers l
                                    LEFT JOIN course_assignments ca ON ca.lecturer_id = l.id AND ca.semester_id = :sid
                                    LEFT JOIN (
                                        SELECT course_id, semester_id, COUNT(*) AS reg_count
                                        FROM course_registrations
                                        WHERE semester_id = :sid
                                        GROUP BY course_id, semester_id
                                    ) crs ON crs.course_id = ca.course_id AND crs.semester_id = ca.semester_id
                                    GROUP BY l.id, staff_name");
            $stmt->execute(['sid' => $semesterId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $fstmt = $conn->prepare("SELECT u.id,
                                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(fs.first_name,''), ' ', COALESCE(fs.last_name,''))), ''), u.username) AS staff_name,
                                            COUNT(p.id) AS payments_processed,
                                            COALESCE(SUM(p.amount),0) AS amount_collected
                                     FROM users u
                                     LEFT JOIN finance_staff fs ON fs.user_id = u.id
                                     LEFT JOIN payments p ON p.received_by = u.id
                                        AND DATE(p.payment_date) BETWEEN :from AND :to
                                        AND (:sid = 0 OR p.semester_id = :sid)
                                     WHERE u.role = 'finance' AND u.status = 'active'
                                     GROUP BY u.id, staff_name
                                     ORDER BY payments_processed DESC");
            $fstmt->execute(['from' => $from, 'to' => $to, 'sid' => $semesterId]);
            $financeRows = $fstmt->fetchAll(PDO::FETCH_ASSOC);

            $astmt = $conn->prepare("SELECT c.course_code,
                                            c.course_name,
                                            COUNT(DISTINCT ca.lecturer_id) AS lecturer_count,
                                            GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) ORDER BY l.last_name SEPARATOR ', ') AS lecturers
                                     FROM course_assignments ca
                                     INNER JOIN courses c ON c.id = ca.course_id
                                     INNER JOIN lecturers l ON l.id = ca.lecturer_id
                                     WHERE (:sid = 0 OR ca.semester_id = :sid)
                                     GROUP BY c.id, c.course_code, c.course_name
                                     ORDER BY c.course_code ASC");
            $astmt->execute(['sid' => $semesterId]);
            $assignmentRows = $astmt->fetchAll(PDO::FETCH_ASSOC);

            $csv .= "Staff Type,Name,Primary Metric,Primary Value,Secondary Metric,Secondary Value\n";
            foreach ($rows as $r) {
                $csv .= implode(',', [
                    $csvEscape('Lecturer'),
                    $csvEscape($r['staff_name']),
                    $csvEscape('Courses Assigned'),
                    $csvEscape($r['courses_assigned']),
                    $csvEscape('Students Registered'),
                    $csvEscape($r['students_registered'])
                ]) . "\n";
            }
            foreach ($financeRows as $r) {
                $csv .= implode(',', [
                    $csvEscape('Finance Operator'),
                    $csvEscape($r['staff_name']),
                    $csvEscape('Payments Processed'),
                    $csvEscape($r['payments_processed']),
                    $csvEscape('Amount Collected'),
                    $csvEscape(Helper::formatCurrencyDual((float)$r['amount_collected'], 'UGX'))
                ]) . "\n";
            }
            $csv .= "\nCourse Code,Course Name,Lecturers Assigned,Lecturer Count\n";
            foreach ($assignmentRows as $r) {
                $csv .= implode(',', [
                    $csvEscape($r['course_code']),
                    $csvEscape($r['course_name']),
                    $csvEscape($r['lecturers']),
                    $csvEscape($r['lecturer_count'])
                ]) . "\n";
            }
        }

        $filename = 'scheduled_report_' . $s['id'] . '_' . date('Ymd_His') . '.csv';
        $filePath = BASE_PATH . '/downloads/' . $filename;
        file_put_contents($filePath, $csv);

        $recips = array_map('trim', explode(',', $s['recipients']));
        $to = implode(',', $recips);
        $downloadUrl = BASE_URL . '/downloads/' . $filename;
        if ($type === 'financial') {
            $sent = Helper::sendTemplatedEmail('finance_alert', $to, [
                'recipient_name' => 'Finance Team',
                'alert_title' => $s['name'],
                'alert_message' => 'A scheduled financial report is ready for review.',
                'reference' => 'Schedule #' . $s['id'],
                'action_url' => $downloadUrl
            ]);
        } else {
            $sent = Helper::sendTemplatedEmail('scheduled_report', $to, [
                'recipient_name' => 'Team',
                'report_name' => $s['name'],
                'report_type' => $type,
                'download_url' => $downloadUrl
            ]);
        }

        // update last_sent_at
        $u = $conn->prepare("UPDATE scheduled_reports SET last_sent_at = NOW() WHERE id = :id");
        $u->execute(['id' => $s['id']]);

        // create admin notifications for audit and quick access
        try {
            $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
            $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
            $title = ($sent ? 'Scheduled report sent' : 'Scheduled report failed') . ' — ' . $s['name'];
            $msg = ($sent ? 'Report generated and emailed. ' : 'Report failed to send. ') . 'Download: ' . BASE_URL . '/downloads/' . $filename;
            $link = BASE_URL . '/downloads/' . $filename;
            foreach ($admins as $a) {
                try { $noteStmt->execute(['uid' => $a['id'], 'title' => $title, 'msg' => $msg, 'type' => $sent ? 'success' : 'error', 'link' => $link]); } catch (Exception $ex) { }
            }
        } catch (Exception $e) {
            // ignore notification errors
        }

        echo " -> done (mail: " . ($sent ? 'ok' : 'failed') . ")\n";
    }
}

echo "Completed at " . date('Y-m-d H:i:s') . "\n";
