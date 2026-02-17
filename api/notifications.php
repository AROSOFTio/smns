<?php
/**
 * Notifications API
 * Handles mark as read operations
 */
require_once '../config.php';

// Try each module's session to find the logged-in user.
// Each role uses a separate session cookie (e.g. SMNS_ADMIN_SESSION, SMNS_STUDENT_SESSION).
$userId = null;
$modules = ['admin', 'student', 'lecturer', 'finance'];

foreach ($modules as $mod) {
    $cookieName = 'SMNS_' . strtoupper($mod) . '_SESSION';
    if (!empty($_COOKIE[$cookieName])) {
        // Close any currently active session before switching
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name($cookieName);
        session_start();
        if (!empty($_SESSION[$mod . '_logged_in']) && $_SESSION[$mod . '_logged_in'] === true) {
            $userId = $_SESSION[$mod . '_user_id'] ?? null;
            if ($userId) {
                break;
            }
        }
        session_write_close();
    }
}

// Fallback: try default session name
if (!$userId) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    foreach ($modules as $mod) {
        if (!empty($_SESSION[$mod . '_logged_in']) && $_SESSION[$mod . '_logged_in'] === true) {
            $userId = $_SESSION[$mod . '_user_id'] ?? null;
            if ($userId) break;
        }
    }
}

if (!$userId) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$db = new Database();
$conn = $db->getConnection();

// Ensure per-user read and archive tables exist
$conn->exec("CREATE TABLE IF NOT EXISTS notifications_read (
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME NOT NULL,
    PRIMARY KEY(notification_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->exec("CREATE TABLE IF NOT EXISTS notification_archive (
    id INT PRIMARY KEY AUTO_INCREMENT,
    notification_id INT NULL,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

switch ($action) {
    case 'fetch':
        // Fetch notifications for the current user (honour per-user read markers for broadcasts)
        $limit = intval($_GET['limit'] ?? 10);
        $notifications = fetchUnreadNotificationsForUser($userId, $limit);

        // Format notifications for JSON response
        $formattedNotifications = [];
        foreach ($notifications as $notif) {
            // extract actionable operation if link uses special prefix
            $action = null;
            if (!empty($notif['link']) && strpos($notif['link'], 'action:') === 0) {
                $action = substr($notif['link'], strlen('action:'));
            }

            $formattedNotifications[] = [
                'id' => $notif['id'],
                'type' => $notif['type'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'link' => (!empty($notif['link']) && strpos($notif['link'], 'action:') === 0) ? null : $notif['link'],
                'action' => $action,
                'created_at' => $notif['created_at'],
                'time_ago' => Helper::timeAgo($notif['created_at'])
            ];
        }

        echo json_encode([
            'success' => true,
            'count' => count($formattedNotifications),
            'notifications' => $formattedNotifications
        ]);
        break;

    case 'mark_all_read':
        // Mark all unread for current user only (personal + broadcast handled per-user)
        $isAdmin = !empty($_SESSION['admin_logged_in']);
        // Mark personal notifications for this user
        $pstmt = $conn->prepare("UPDATE notifications SET read_status = 'read', read_at = NOW() WHERE user_id = :uid AND read_status = 'unread'");
        $pstmt->execute(['uid' => $userId]);

        // For broadcast notifications (user_id IS NULL or 0) insert into notifications_read for this user
        $bstmt = $conn->prepare("SELECT id FROM notifications WHERE (user_id IS NULL OR user_id = 0)");
        $bstmt->execute();
        $ins = $conn->prepare("INSERT IGNORE INTO notifications_read (notification_id, user_id, read_at) VALUES (:nid, :uid, NOW())");
        while ($row = $bstmt->fetch(PDO::FETCH_ASSOC)) {
            try { $ins->execute(['nid' => $row['id'], 'uid' => $userId]); } catch (Exception $e) { }
        }
        echo json_encode(['success' => true]);
        break;

    case 'mark_read':
        $id = intval($_GET['id'] ?? 0);
        if ($id > 0) {
            // Check if notification is personal or broadcast
            $nstmt = $conn->prepare("SELECT user_id FROM notifications WHERE id = :id");
            $nstmt->execute(['id' => $id]);
            $row = $nstmt->fetch(PDO::FETCH_ASSOC);
            $isBroadcast = !$row || is_null($row['user_id']) || $row['user_id'] == 0;

            if ($isBroadcast) {
                // insert per-user read marker
                $ins = $conn->prepare("INSERT IGNORE INTO notifications_read (notification_id, user_id, read_at) VALUES (:nid, :uid, NOW())");
                $ins->execute(['nid' => $id, 'uid' => $userId]);
            } else {
                // personal notification — update row
                $stmt = $conn->prepare("UPDATE notifications SET read_status = 'read', read_at = NOW() WHERE id = :id AND user_id = :uid");
                $stmt->execute(['id' => $id, 'uid' => $userId]);
            }

            // Auto-save (archive) this notification for the current user when it's marked read
            try {
                $n = $conn->prepare('SELECT id, title, message, link FROM notifications WHERE id = :id');
                $n->execute(['id' => $id]);
                $nrow = $n->fetch(PDO::FETCH_ASSOC);
                if ($nrow) {
                    $chk = $conn->prepare('SELECT id FROM notification_archive WHERE notification_id = :nid AND user_id = :uid LIMIT 1');
                    $chk->execute(['nid' => $nrow['id'], 'uid' => $userId]);
                    if (!$chk->fetch()) {
                        $a = $conn->prepare('INSERT INTO notification_archive (notification_id, user_id, title, message, link) VALUES (:nid, :uid, :title, :msg, :link)');
                        $a->execute(['nid' => $nrow['id'], 'uid' => $userId, 'title' => $nrow['title'], 'msg' => $nrow['message'], 'link' => $nrow['link']]);
                    }
                }
            } catch (Exception $e) { /* ignore archive errors */ }

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        }
        break;

    case 'execute':
        // Execute an admin-only operation triggered from a notification action
        if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }

        $op = $_POST['op'] ?? $_GET['op'] ?? '';
        $op = preg_replace('/[^a-z0-9_:\-]/i', '', $op);
        $result = ['success' => false, 'message' => 'Unknown operation'];

        switch ($op) {
            case 'run_backup':
                $script = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup_cron.php';
                if (is_file($script)) {
                    @exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
                    if ($code === 0) {
                        $result = ['success' => true, 'message' => 'Backup executed'];
                    } else {
                        $result = ['success' => false, 'message' => 'Backup script failed'];
                    }
                } else {
                    $result = ['success' => false, 'message' => 'Backup script not found'];
                }
                break;

            case 'approve_pending_registrations':
                // Approve all pending semester registrations
                try {
                    $stmt = $conn->prepare("UPDATE semester_registrations SET status = 'approved', approved_by = :admin_id, approved_at = NOW() WHERE status = 'pending'");
                    $stmt->execute(['admin_id' => $userId]);
                    $affected = $stmt->rowCount();
                    $result = ['success' => true, 'message' => "Approved {$affected} pending semester registrations."];
                    // Save a notification for this admin
                    $insN = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at, read_status) VALUES (:uid, :title, :msg, 'success', :link, NOW(), 'unread')");
                    $insN->execute([
                        'uid' => $userId,
                        'title' => 'Semester Approvals Completed',
                        'msg' => "You approved {$affected} pending semester registrations.",
                        'link' => BASE_URL . '/views/admin/registrations/pending.php'
                    ]);
                } catch (Exception $e) {
                    $result = ['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()];
                }
                break;

            case 'purge_backups':
                $backupDir = BASE_PATH . DIRECTORY_SEPARATOR . 'database backup';
                $deleted = 0;
                $retentionDays = (int)getSetting('backup_retention_days', defined('BACKUP_RETENTION_DAYS') ? BACKUP_RETENTION_DAYS : 30);
                $maxFiles = (int)getSetting('backup_retention_max_files', defined('BACKUP_RETENTION_MAX_FILES') ? BACKUP_RETENTION_MAX_FILES : 50);
                if (is_dir($backupDir)) {
                    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql');
                    foreach ($files as $f) {
                        if (filemtime($f) < strtotime("-{$retentionDays} days")) { @unlink($f) && $deleted++; }
                    }
                    usort($files, function($a,$b){ return filemtime($a) - filemtime($b); });
                    while (count($files) > $maxFiles) { $f = array_shift($files); if (is_file($f)) { @unlink($f) && $deleted++; } }
                }
                $result = ['success' => true, 'message' => "Purged {$deleted} backup files"];                
                break;

            case 'purge_logs':
                try {
                    $days = (int)getSetting('log_retention_days', defined('LOG_RETENTION_DAYS') ? LOG_RETENTION_DAYS : 90);
                    $pdo = (new Database())->getConnection();
                    $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
                    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
                    $stmt->execute();
                    $deleted = $stmt->rowCount();
                    $result = ['success' => true, 'message' => "Purged {$deleted} activity log entries"]; 
                } catch (Exception $e) {
                    $result = ['success' => false, 'message' => 'Log purge failed: ' . $e->getMessage()];
                }
                break;

            case 'clear_cache':
                $cacheDir = BASE_PATH . DIRECTORY_SEPARATOR . 'cache';
                $removed = 0;
                if (is_dir($cacheDir)) {
                    $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*');
                    foreach ($files as $f) {
                        if (is_file($f) && basename($f) !== '.gitkeep') { @unlink($f) && $removed++; }
                        if (is_dir($f)) { $inner = glob($f . DIRECTORY_SEPARATOR . '*'); if (empty($inner)) { @rmdir($f); } }
                    }
                }
                $result = ['success' => true, 'message' => "Cache cleared ({$removed} files removed)"];
                break;

            case 'resend_report':
                // op format: resend_report:ID
                if (preg_match('/^resend_report:(\d+)$/i', $op, $m)) {
                    $sid = (int)$m[1];
                    $sstmt = $conn->prepare("SELECT * FROM scheduled_reports WHERE id = :id");
                    $sstmt->execute(['id'=>$sid]);
                    $sched = $sstmt->fetch(PDO::FETCH_ASSOC);
                    if ($sched) {
                        // replicate sendScheduledReportNow logic (minimal)
                        $filters = json_decode($sched['filters'] ?? '{}', true) ?: [];
                        $type = $sched['report_type'];
                        $csv = '';
                        try {
                            if ($type === 'enrollment') {
                                $from = $filters['from'] ?? date('Y-01-01');
                                $to = $filters['to'] ?? date('Y-m-d');
                                $stmt2 = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as period, COUNT(*) as cnt FROM students WHERE DATE(created_at) BETWEEN :from AND :to GROUP BY period ORDER BY period");
                                $stmt2->execute(['from'=>$from,'to'=>$to]); $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                $csv .= "Period,New Students\n"; foreach ($rows as $r) $csv .= "{$r['period']},{$r['cnt']}\n";
                            } elseif ($type === 'financial') {
                                $from = $filters['from'] ?? date('Y-01-01'); $to = $filters['to'] ?? date('Y-m-d');
                                $stmt2 = $conn->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as period, COALESCE(SUM(amount),0) as total FROM payments WHERE DATE(payment_date) BETWEEN :from AND :to GROUP BY period ORDER BY period");
                                $stmt2->execute(['from'=>$from,'to'=>$to]); $rows=$stmt2->fetchAll(PDO::FETCH_ASSOC);
                                $csv .= "Period,Collections\n"; foreach ($rows as $r) $csv .= "{$r['period']},{$r['total']}\n";
                            } elseif ($type === 'system') {
                                $ay = $filters['academic_year_id'] ?? 0;
                                if ($ay) {
                                    $semSt = $conn->prepare("SELECT id, semester_name, start_date, end_date FROM semesters WHERE academic_year_id = :ay ORDER BY semester_number");
                                    $semSt->execute(['ay'=>$ay]); $sems = $semSt->fetchAll(PDO::FETCH_ASSOC);
                                    $csv .= "Semester,New Students,Registrations Total,Registrations Approved,Payments Collected,Invoices Issued,Outstanding Balances,Results Published,Courses Offered,Lecturers Assigned,Avg GPA\n";
                                    foreach ($sems as $sem) {
                                        $sid = $sem['id']; $sStart = $sem['start_date']; $sEnd = $sem['end_date'];
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
                                // staff type or other - skip detailed resend
                                $csv = 'Resend not supported for this report type via notification.';
                            }

                            $filename = 'scheduled_report_resend_' . $sched['id'] . '_' . date('Ymd_His') . '.csv';
                            $filePath = BASE_PATH . '/downloads/' . $filename;
                            file_put_contents($filePath, $csv);
                            $recips = array_filter(array_map('trim', explode(',', $sched['recipients'])));
                            if (!empty($recips)) {
                                $sub = APP_NAME . ' - Resent Scheduled Report: ' . $sched['name'];
                                $body = "A scheduled report was re-sent. Download: " . BASE_URL . '/downloads/' . $filename;
                                $hdr = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>\r\n' . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
                                @mail(implode(',', $recips), $sub, $body, $hdr);
                            }

                            $result = ['success'=>true,'message'=>'Report resent'];
                        } catch (Exception $e) { $result = ['success'=>false,'message'=>$e->getMessage()]; }
                    } else {
                        $result = ['success'=>false,'message'=>'Schedule not found'];
                    }
                }
                break;

            case 'run_cron':
                // op format: run_cron:scriptname
                if (preg_match('/^run_cron:([a-z0-9_\-]+)$/i', $op, $m)) {
                    $scriptName = $m[1];
                    $allowed = ['backup_cron','send_scheduled_reports'];
                    if (in_array($scriptName, $allowed)) {
                        $script = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $scriptName . '.php';
                        if (is_file($script)) {
                            @exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>&1', $out2, $code2);
                            if ($code2 === 0) { $result = ['success'=>true,'message'=>'Script executed']; }
                            else { $result = ['success'=>false,'message'=>'Script returned error']; }
                        } else { $result = ['success'=>false,'message'=>'Script not found']; }
                    } else { $result = ['success'=>false,'message'=>'Script not allowed']; }
                }
                break;

            default:
                $result = ['success' => false, 'message' => 'Unsupported operation'];
                break;
        }

        // Log the admin operation
        try {
            $logger = new Logger();
            $currentUser = (new Auth('admin'))->getCurrentUser();
            $logger->log($currentUser['id'] ?? 0, 'notification_action', 'system', 'Executed action: ' . $op . ' - ' . json_encode($result));

            // Email + notify admins about this execution
            $adminEmails = [];
            $rows = $conn->query("SELECT email, id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $adminEmails[] = $r['email']; $nidUsers[] = $r['id']; }
            if (!empty($adminEmails)) {
                $sub = APP_NAME . ' - Admin action executed: ' . $op;
                $bdy = 'User ' . ($currentUser['username'] ?? 'admin') . ' executed ' . $op . "\n\nResult: " . ($result['message'] ?? json_encode($result));
                @mail(implode(',', $adminEmails), $sub, $bdy, 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>\r\n');

                // insert per-admin notification
                $insN = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid,:title,:msg,:type,:link,NOW())");
                foreach ($rows as $r) {
                    try { $insN->execute(['uid'=>$r['id'],'title'=>'Action executed: ' . $op,'msg'=>$bdy,'type'=>($result['success'] ? 'success' : 'error'),'link'=>BASE_URL . '/views/admin/system/health.php']); } catch (Exception $e) {}
                }
            }
        } catch (Exception $e) { /* ignore */ }

        echo json_encode($result);
        break;

    case 'archive':
        $notificationId = intval($_GET['id'] ?? 0);
        if ($notificationId > 0) {
            $conn->beginTransaction();
            try {
                // 1. Find the original notification
                $stmt = $conn->prepare("SELECT * FROM notifications WHERE id = :id");
                $stmt->execute(['id' => $notificationId]);
                $notification = $stmt->fetch();

                if ($notification) {
                    // 2. Insert into archive
                    $stmt = $conn->prepare("INSERT INTO notification_archive (notification_id, user_id, title, message, link) VALUES (:nid, :uid, :title, :msg, :link)");
                    $stmt->execute([
                        'nid' => $notification['id'],
                        'uid' => $userId,
                        'title' => $notification['title'],
                        'msg' => $notification['message'],
                        'link' => $notification['link']
                    ]);

                    // 3. Mark as read (to remove from unread count)
                    $stmt = $conn->prepare("INSERT IGNORE INTO notifications_read (notification_id, user_id, read_at) VALUES (:nid, :uid, NOW())");
                    $stmt->execute(['nid' => $notificationId, 'uid' => $userId]);
                    
                    $conn->commit();
                    echo json_encode(['success' => true]);
                } else {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Notification not found']);
                }
            } catch (Exception $e) {
                $conn->rollBack();
                error_log("Archive error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Failed to archive notification.']);
            }
        }
        break;

    case 'delete_archive':
        $archiveId = intval($_GET['id'] ?? 0);
        if ($archiveId > 0) {
            $stmt = $conn->prepare("DELETE FROM notification_archive WHERE id = :id AND user_id = :uid");
            $stmt->execute(['id' => $archiveId, 'uid' => $userId]);
            echo json_encode(['success' => true]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
