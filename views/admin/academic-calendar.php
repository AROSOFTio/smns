<?php
/**
 * Admin Academic Calendar Management
 * CRUD for semesters and announcements + audit log
 */
require_once '../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();
$logger = new Logger();
$communicationService = new AdminCommunicationService($conn, $logger);

// Keep academic years/semesters normalized (Aug/Jan intake model) and extend future years.
try {
    AcademicCalendarManager::ensureStandardCalendar($conn, 2025, 5);
} catch (Exception $e) {
    // Non-fatal: manual semester operations below remain available.
}

$allowedTabs = ['semesters', 'announcements', 'audit'];
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true) ? $_GET['tab'] : 'semesters';

$allowedAnnouncementAudience = ['all', 'students', 'lecturers', 'finance', 'admin'];
$allowedAnnouncementPriority = ['low', 'normal', 'high', 'urgent'];
$allowedStatus = ['active', 'inactive'];
$allowedSemesterStatus = ['active', 'inactive', 'completed'];

$redirectToTab = function ($tab = 'semesters') {
    $safeTab = in_array($tab, ['semesters', 'announcements', 'audit'], true) ? $tab : 'semesters';
    header('Location: academic-calendar.php?tab=' . $safeTab);
    exit;
};

$formatDateRange = function ($startDate, $endDate) {
    $start = !empty($startDate) ? (string)$startDate : '-';
    $end = !empty($endDate) ? (string)$endDate : '-';
    return $start . ' to ' . $end;
};

$normalizeNullableDate = function ($value) {
    $v = trim((string)$value);
    return $v === '' ? null : $v;
};

$getYearName = function ($academicYearId) use ($conn) {
    if ($academicYearId <= 0) {
        return '';
    }
    $stmt = $conn->prepare('SELECT year_name FROM academic_years WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $academicYearId]);
    return (string)($stmt->fetchColumn() ?: '');
};

$getAdminProfileId = function () use ($conn, $currentUser) {
    $profileId = (int)($currentUser['profile']['id'] ?? 0);
    if ($profileId > 0) {
        return $profileId;
    }
    $stmt = $conn->prepare('SELECT id FROM admins WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => (int)($currentUser['id'] ?? 0)]);
    return (int)($stmt->fetchColumn() ?: 0);
};

if (!isset($_SESSION['announcement_submit_tokens']) || !is_array($_SESSION['announcement_submit_tokens'])) {
    $_SESSION['announcement_submit_tokens'] = [];
}
foreach ($_SESSION['announcement_submit_tokens'] as $token => $issuedAt) {
    if (!is_string($token) || !is_numeric($issuedAt) || (time() - (int)$issuedAt) > 1800) {
        unset($_SESSION['announcement_submit_tokens'][$token]);
    }
}
$announcementSubmitToken = bin2hex(random_bytes(16));
$_SESSION['announcement_submit_tokens'][$announcementSubmitToken] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTab = $_POST['active_tab'] ?? $activeTab;
    $postedTab = in_array($postedTab, $allowedTabs, true) ? $postedTab : 'semesters';

    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        $redirectToTab($postedTab);
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_semester') {
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterName = trim((string)($_POST['semester_name'] ?? ''));
            $semesterNumber = (int)($_POST['semester_number'] ?? 0);
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $registrationStart = $normalizeNullableDate($_POST['registration_start_date'] ?? null);
            $registrationEnd = $normalizeNullableDate($_POST['registration_end_date'] ?? null);
            $status = (string)($_POST['status'] ?? 'inactive');

            if ($academicYearId <= 0 || $semesterName === '' || $semesterNumber <= 0 || $startDate === '' || $endDate === '') {
                throw new Exception('Please fill all required semester fields.');
            }
            if (!in_array($semesterNumber, [1, 2], true)) {
                throw new Exception('Semester number must be 1 or 2.');
            }
            if (!in_array($status, $allowedSemesterStatus, true)) {
                throw new Exception('Invalid semester status.');
            }
            if ($startDate > $endDate) {
                throw new Exception('Semester end date cannot be before start date.');
            }
            if (($registrationStart === null) xor ($registrationEnd === null)) {
                throw new Exception('Provide both registration start and end dates, or leave both empty.');
            }
            if ($registrationStart !== null && $registrationEnd !== null) {
                if ($registrationStart > $registrationEnd) {
                    throw new Exception('Registration end date cannot be before registration start date.');
                }
                if ($registrationStart < $startDate || $registrationEnd > $endDate) {
                    throw new Exception('Registration window must fall within semester start/end dates.');
                }
            }

            $yearName = $getYearName($academicYearId);

            if ($semesterId > 0) {
                $oldStmt = $conn->prepare("SELECT s.*, ay.year_name FROM semesters s INNER JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1");
                $oldStmt->execute(['id' => $semesterId]);
                $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    throw new Exception('Semester record not found.');
                }

                $updateStmt = $conn->prepare("UPDATE semesters SET academic_year_id = :academic_year_id, semester_name = :semester_name, semester_number = :semester_number, start_date = :start_date, end_date = :end_date, registration_start_date = :registration_start_date, registration_end_date = :registration_end_date, status = :status WHERE id = :id");
                $updateStmt->execute([
                    'academic_year_id' => $academicYearId,
                    'semester_name' => $semesterName,
                    'semester_number' => $semesterNumber,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'registration_start_date' => $registrationStart,
                    'registration_end_date' => $registrationEnd,
                    'status' => $status,
                    'id' => $semesterId
                ]);

                $desc = 'Updated semester [' . ($old['year_name'] ?? '-') . ' - ' . ($old['semester_name'] ?? '-') . ']'
                    . ' to [' . ($yearName ?: '-') . ' - ' . $semesterName . ']'
                    . '; dates ' . $formatDateRange($old['start_date'] ?? '', $old['end_date'] ?? '') . ' -> ' . $formatDateRange($startDate, $endDate)
                    . '; registration ' . $formatDateRange($old['registration_start_date'] ?? '', $old['registration_end_date'] ?? '') . ' -> ' . $formatDateRange($registrationStart, $registrationEnd)
                    . '; status ' . (($old['status'] ?? '-') . ' -> ' . $status);

                $logger->log((int)($currentUser['id'] ?? 0), 'update', 'academic_calendar', $desc);
                $session->setFlash('success', 'Semester updated successfully.');
            } else {
                $insertStmt = $conn->prepare("INSERT INTO semesters (academic_year_id, semester_name, semester_number, start_date, end_date, registration_start_date, registration_end_date, status) VALUES (:academic_year_id, :semester_name, :semester_number, :start_date, :end_date, :registration_start_date, :registration_end_date, :status)");
                $insertStmt->execute([
                    'academic_year_id' => $academicYearId,
                    'semester_name' => $semesterName,
                    'semester_number' => $semesterNumber,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'registration_start_date' => $registrationStart,
                    'registration_end_date' => $registrationEnd,
                    'status' => $status
                ]);

                $desc = 'Created semester [' . ($yearName ?: '-') . ' - ' . $semesterName . ']'
                    . '; dates ' . $formatDateRange($startDate, $endDate)
                    . '; registration ' . $formatDateRange($registrationStart, $registrationEnd)
                    . '; status ' . $status;
                $logger->log((int)($currentUser['id'] ?? 0), 'create', 'academic_calendar', $desc);
                $session->setFlash('success', 'Semester created successfully.');
            }
        } elseif ($action === 'delete_semester') {
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                throw new Exception('Invalid semester ID.');
            }

            $stmt = $conn->prepare("SELECT s.*, ay.year_name FROM semesters s INNER JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1");
            $stmt->execute(['id' => $semesterId]);
            $semester = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$semester) {
                throw new Exception('Semester not found.');
            }
            if (($semester['status'] ?? '') === 'active') {
                throw new Exception('Cannot delete an active semester. Deactivate it first.');
            }

            $dependencyTables = [
                'course_assignments' => 'semester_id',
                'course_registrations' => 'semester_id',
                'semester_registrations' => 'semester_id',
                'results' => 'semester_id',
                'student_balances' => 'semester_id',
                'invoices' => 'semester_id',
                'student_gpas' => 'semester_id',
                'payments' => 'semester_id'
            ];

            $blocking = [];
            foreach ($dependencyTables as $table => $column) {
                try {
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :semester_id");
                    $countStmt->execute(['semester_id' => $semesterId]);
                    $count = (int)$countStmt->fetchColumn();
                    if ($count > 0) {
                        $blocking[] = $table . ' (' . $count . ')';
                    }
                } catch (Exception $depEx) {
                    $msg = (string)$depEx->getMessage();
                    if (stripos($msg, 'Base table or view not found') !== false || stripos($msg, 'doesn\'t exist') !== false) {
                        continue;
                    }
                    throw $depEx;
                }
            }

            if (!empty($blocking)) {
                throw new Exception('Semester cannot be deleted because it is used by: ' . implode(', ', $blocking) . '.');
            }

            $delStmt = $conn->prepare('DELETE FROM semesters WHERE id = :id');
            $delStmt->execute(['id' => $semesterId]);

            $desc = 'Deleted semester [' . ($semester['year_name'] ?? '-') . ' - ' . ($semester['semester_name'] ?? '-') . ']'
                . '; dates ' . $formatDateRange($semester['start_date'] ?? '', $semester['end_date'] ?? '')
                . '; registration ' . $formatDateRange($semester['registration_start_date'] ?? '', $semester['registration_end_date'] ?? '')
                . '; status ' . ($semester['status'] ?? '-');
            $logger->log((int)($currentUser['id'] ?? 0), 'delete', 'academic_calendar', $desc);
            $session->setFlash('success', 'Semester deleted successfully.');
        } elseif ($action === 'save_announcement') {
            $announcementId = (int)($_POST['announcement_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            $targetAudience = (string)($_POST['target_audience'] ?? 'all');
            $priority = (string)($_POST['priority'] ?? 'normal');
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = $normalizeNullableDate($_POST['end_date'] ?? null);
            $status = (string)($_POST['status'] ?? 'active');
            $sendToStudentsNow = isset($_POST['send_to_students_now']) && (string)($_POST['send_to_students_now'] ?? '') === '1';
            $sendChannelPortal = isset($_POST['send_channel_portal']);
            $sendChannelEmail = isset($_POST['send_channel_email']);
            $submittedToken = trim((string)($_POST['announcement_submit_token'] ?? ''));

            if ($submittedToken === '' || !isset($_SESSION['announcement_submit_tokens'][$submittedToken])) {
                throw new Exception('Duplicate or expired announcement submission detected. Please submit once and wait for completion.');
            }
            unset($_SESSION['announcement_submit_tokens'][$submittedToken]);

            if ($title === '' || $content === '' || $startDate === '' || $endDate === null) {
                throw new Exception('Please fill all required announcement fields.');
            }
            if (!in_array($targetAudience, $allowedAnnouncementAudience, true)) {
                throw new Exception('Invalid announcement target audience.');
            }
            if (!in_array($priority, $allowedAnnouncementPriority, true)) {
                throw new Exception('Invalid announcement priority.');
            }
            if (!in_array($status, $allowedStatus, true)) {
                throw new Exception('Invalid announcement status.');
            }
            if ($endDate !== null && $endDate < $startDate) {
                throw new Exception('Announcement end date cannot be before start date.');
            }
            if ($sendToStudentsNow) {
                if (!in_array($targetAudience, ['all', 'students'], true)) {
                    throw new Exception('Immediate student push is allowed only for announcements targeted to All or Students.');
                }
                if ($status !== 'active') {
                    throw new Exception('Only active announcements can be pushed to students.');
                }
                if (!$sendChannelPortal && !$sendChannelEmail) {
                    throw new Exception('Select at least one student delivery channel (portal/email).');
                }
            }

            if ($announcementId > 0) {
                $oldStmt = $conn->prepare('SELECT * FROM announcements WHERE id = :id LIMIT 1');
                $oldStmt->execute(['id' => $announcementId]);
                $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    throw new Exception('Announcement not found.');
                }

                $updateStmt = $conn->prepare("UPDATE announcements SET title = :title, content = :content, target_audience = :target_audience, priority = :priority, start_date = :start_date, end_date = :end_date, status = :status WHERE id = :id");
                $updateStmt->execute([
                    'title' => $title,
                    'content' => $content,
                    'target_audience' => $targetAudience,
                    'priority' => $priority,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $status,
                    'id' => $announcementId
                ]);

                $desc = 'Updated announcement #' . $announcementId
                    . ' title [' . ($old['title'] ?? '-') . '] -> [' . $title . ']'
                    . '; audience ' . (($old['target_audience'] ?? '-') . ' -> ' . $targetAudience)
                    . '; dates ' . $formatDateRange($old['start_date'] ?? '', $old['end_date'] ?? '') . ' -> ' . $formatDateRange($startDate, $endDate)
                    . '; status ' . (($old['status'] ?? '-') . ' -> ' . $status);
                $logger->log((int)($currentUser['id'] ?? 0), 'update', 'academic_calendar', $desc);

                $successMessage = 'Announcement updated successfully.';
                if ($sendToStudentsNow) {
                    $sendResult = $communicationService->sendAnnouncementToStudents(
                        (int)($currentUser['id'] ?? 0),
                        [
                            'id' => $announcementId,
                            'title' => $title,
                            'content' => $content,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'target_audience' => $targetAudience
                        ],
                        [
                            'source' => 'calendar_announcement',
                            'send_portal' => $sendChannelPortal,
                            'send_email' => $sendChannelEmail,
                            'communication_type' => 'academic_announcement',
                            'notification_type' => $priority === 'urgent' ? 'warning' : 'info',
                            'email_source_page' => '/views/admin/academic-calendar.php',
                            'queue_delivery' => true
                        ]
                    );
                    $successMessage .= ' Student communication queued as job #'
                        . (int)($sendResult['queue_job_id'] ?? 0)
                        . ' (communication #' . (int)($sendResult['communication_id'] ?? 0) . ')'
                        . ' for ' . (int)($sendResult['total_recipients'] ?? 0) . ' recipient(s).';
                }
                $session->setFlash('success', $successMessage);
            } else {
                $adminProfileId = $getAdminProfileId();
                if ($adminProfileId <= 0) {
                    throw new Exception('Admin profile not found for announcement creator.');
                }

                $insertStmt = $conn->prepare("INSERT INTO announcements (title, content, target_audience, priority, start_date, end_date, status, created_by) VALUES (:title, :content, :target_audience, :priority, :start_date, :end_date, :status, :created_by)");
                $insertStmt->execute([
                    'title' => $title,
                    'content' => $content,
                    'target_audience' => $targetAudience,
                    'priority' => $priority,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $status,
                    'created_by' => $adminProfileId
                ]);

                $announcementId = (int)$conn->lastInsertId();
                $desc = 'Created announcement #' . $announcementId
                    . ' [' . $title . ']'
                    . '; audience ' . $targetAudience
                    . '; priority ' . $priority
                    . '; dates ' . $formatDateRange($startDate, $endDate)
                    . '; status ' . $status;
                $logger->log((int)($currentUser['id'] ?? 0), 'create', 'academic_calendar', $desc);

                $successMessage = 'Announcement created successfully.';
                if ($sendToStudentsNow) {
                    $sendResult = $communicationService->sendAnnouncementToStudents(
                        (int)($currentUser['id'] ?? 0),
                        [
                            'id' => $announcementId,
                            'title' => $title,
                            'content' => $content,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'target_audience' => $targetAudience
                        ],
                        [
                            'source' => 'calendar_announcement',
                            'send_portal' => $sendChannelPortal,
                            'send_email' => $sendChannelEmail,
                            'communication_type' => 'academic_announcement',
                            'notification_type' => $priority === 'urgent' ? 'warning' : 'info',
                            'email_source_page' => '/views/admin/academic-calendar.php',
                            'queue_delivery' => true
                        ]
                    );
                    $successMessage .= ' Student communication queued as job #'
                        . (int)($sendResult['queue_job_id'] ?? 0)
                        . ' (communication #' . (int)($sendResult['communication_id'] ?? 0) . ')'
                        . ' for ' . (int)($sendResult['total_recipients'] ?? 0) . ' recipient(s).';
                }
                $session->setFlash('success', $successMessage);
            }
        } elseif ($action === 'delete_announcement') {
            $announcementId = (int)($_POST['announcement_id'] ?? 0);
            if ($announcementId <= 0) {
                throw new Exception('Invalid announcement ID.');
            }

            $oldStmt = $conn->prepare('SELECT * FROM announcements WHERE id = :id LIMIT 1');
            $oldStmt->execute(['id' => $announcementId]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new Exception('Announcement not found.');
            }

            $deleteStmt = $conn->prepare('DELETE FROM announcements WHERE id = :id');
            $deleteStmt->execute(['id' => $announcementId]);

            $desc = 'Deleted announcement #' . $announcementId
                . ' [' . ($old['title'] ?? '-') . ']'
                . '; audience ' . ($old['target_audience'] ?? '-')
                . '; dates ' . $formatDateRange($old['start_date'] ?? '', $old['end_date'] ?? '')
                . '; status ' . ($old['status'] ?? '-');
            $logger->log((int)($currentUser['id'] ?? 0), 'delete', 'academic_calendar', $desc);
            $session->setFlash('success', 'Announcement deleted successfully.');
        }
    } catch (Exception $e) {
        $session->setFlash('error', $e->getMessage());
    }

    $redirectToTab($postedTab);
}

$academicYears = [];
$semesters = [];
$announcements = [];
$auditLogs = [];

try {
    $yearsStmt = $conn->query('SELECT id, year_name, status FROM academic_years ORDER BY start_date DESC, id DESC');
    $academicYears = $yearsStmt ? ($yearsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $academicYears = [];
}

try {
    $semStmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s INNER JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.start_date DESC, s.semester_number ASC, s.id DESC");
    $semesters = $semStmt ? ($semStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $semesters = [];
}

try {
    $annStmt = $conn->query("SELECT an.*, u.username AS creator_username, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ad.first_name, ad.last_name)), ''), u.username) AS creator_name FROM announcements an LEFT JOIN admins ad ON an.created_by = ad.id LEFT JOIN users u ON ad.user_id = u.id ORDER BY an.start_date DESC, an.id DESC");
    $announcements = $annStmt ? ($annStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $announcements = [];
}

try {
    $logStmt = $conn->query("SELECT al.*, u.username, u.role AS user_role, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', l.first_name, l.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''), u.username) AS actor_name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id LEFT JOIN admins a ON u.id = a.user_id LEFT JOIN lecturers l ON u.id = l.user_id LEFT JOIN finance_staff f ON u.id = f.user_id LEFT JOIN students s ON u.id = s.user_id WHERE al.module = 'academic_calendar' ORDER BY al.created_at DESC LIMIT 150");
    $auditLogs = $logStmt ? ($logStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $auditLogs = [];
}

$editSemester = null;
$editAnnouncement = null;

$editSemesterId = isset($_GET['edit_semester']) ? (int)$_GET['edit_semester'] : 0;
if ($editSemesterId > 0) {
    foreach ($semesters as $row) {
        if ((int)($row['id'] ?? 0) === $editSemesterId) {
            $editSemester = $row;
            break;
        }
    }
}

$editAnnouncementId = isset($_GET['edit_announcement']) ? (int)$_GET['edit_announcement'] : 0;
if ($editAnnouncementId > 0) {
    foreach ($announcements as $row) {
        if ((int)($row['id'] ?? 0) === $editAnnouncementId) {
            $editAnnouncement = $row;
            break;
        }
    }
}

$semesterForm = [
    'id' => (int)($editSemester['id'] ?? 0),
    'academic_year_id' => (int)($editSemester['academic_year_id'] ?? 0),
    'semester_name' => (string)($editSemester['semester_name'] ?? ''),
    'semester_number' => (int)($editSemester['semester_number'] ?? 1),
    'start_date' => (string)($editSemester['start_date'] ?? ''),
    'end_date' => (string)($editSemester['end_date'] ?? ''),
    'registration_start_date' => (string)($editSemester['registration_start_date'] ?? ''),
    'registration_end_date' => (string)($editSemester['registration_end_date'] ?? ''),
    'status' => (string)($editSemester['status'] ?? 'inactive')
];

$announcementForm = [
    'id' => (int)($editAnnouncement['id'] ?? 0),
    'title' => (string)($editAnnouncement['title'] ?? ''),
    'content' => (string)($editAnnouncement['content'] ?? ''),
    'target_audience' => (string)($editAnnouncement['target_audience'] ?? 'all'),
    'priority' => (string)($editAnnouncement['priority'] ?? 'normal'),
    'start_date' => (string)($editAnnouncement['start_date'] ?? ''),
    'end_date' => (string)($editAnnouncement['end_date'] ?? ''),
    'status' => (string)($editAnnouncement['status'] ?? 'active')
];

$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');

$shortText = function ($text, $limit = 160) {
    $plain = trim(strip_tags((string)$text));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit) . '...' : $plain;
    }
    return strlen($plain) > $limit ? substr($plain, 0, $limit) . '...' : $plain;
};

$badgeClassForStatus = function ($status) {
    $status = strtolower((string)$status);
    if ($status === 'active') {
        return 'badge-success';
    }
    if ($status === 'completed') {
        return 'badge-info';
    }
    return 'badge-secondary';
};

$badgeClassForAction = function ($action) {
    $action = strtolower((string)$action);
    if ($action === 'create') {
        return 'badge-success';
    }
    if ($action === 'update') {
        return 'badge-warning';
    }
    if ($action === 'delete') {
        return 'badge-danger';
    }
    return 'badge-secondary';
};

$pageTitle = 'Academic Calendar Management - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/admin/sidebar.php'; ?>

<style>
.calendar-admin-page,
.calendar-admin-page .calendar-content {
    max-width: 100%;
    overflow-x: hidden;
}

.calendar-content .card {
    max-width: 100%;
}

.calendar-content .nav-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.calendar-content .nav-tabs .nav-item {
    margin-bottom: 4px;
}

.calendar-content .table-responsive {
    overflow-x: hidden;
}

.calendar-content .calendar-table {
    width: 100%;
    table-layout: fixed;
}

.calendar-content .calendar-table th,
.calendar-content .calendar-table td {
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    vertical-align: top;
}

.calendar-content .calendar-table th:last-child,
.calendar-content .calendar-table td:last-child {
    width: 165px;
}

.calendar-content .action-group {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: flex-start;
}

@media (max-width: 992px) {
    .calendar-content {
        padding: 12px !important;
    }
}

@media (max-width: 768px) {
    .calendar-admin-page .topbar {
        align-items: flex-start;
    }

    .calendar-admin-page .topbar-right {
        margin-top: 8px;
    }

    .calendar-content .calendar-table th:last-child,
    .calendar-content .calendar-table td:last-child {
        width: auto;
    }
}
</style>

<div class="main-content calendar-admin-page">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-calendar-alt mr-1"></i> Academic Calendar Management</h4>
        </div>
        <div class="topbar-right">
            <a href="<?php echo BASE_URL; ?>/views/student/academic-calendar.php" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                <i class="fas fa-external-link-alt"></i> View Student Calendar
            </a>
        </div>
    </div>

    <div class="content-area container-fluid p-4 calendar-content">
        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger"><?php echo e($flashError); ?></div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'semesters' ? 'active' : ''; ?>" href="academic-calendar.php?tab=semesters">Semesters</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'announcements' ? 'active' : ''; ?>" href="academic-calendar.php?tab=announcements">Announcements</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'audit' ? 'active' : ''; ?>" href="academic-calendar.php?tab=audit">Audit Log</a>
            </li>
        </ul>

        <?php if ($activeTab === 'semesters'): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?php echo $semesterForm['id'] > 0 ? 'Edit Semester' : 'Add Semester'; ?></strong>
                    <?php if ($semesterForm['id'] > 0): ?>
                        <a href="academic-calendar.php?tab=semesters" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                        <input type="hidden" name="action" value="save_semester">
                        <input type="hidden" name="active_tab" value="semesters">
                        <input type="hidden" name="semester_id" value="<?php echo (int)$semesterForm['id']; ?>">

                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Academic Year</label>
                                <select name="academic_year_id" class="form-control" required>
                                    <option value="">Select year</option>
                                    <?php foreach ($academicYears as $y): ?>
                                        <option value="<?php echo (int)$y['id']; ?>" <?php echo (int)$semesterForm['academic_year_id'] === (int)$y['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($y['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Semester Name</label>
                                <input type="text" name="semester_name" class="form-control" value="<?php echo e($semesterForm['semester_name']); ?>" placeholder="Semester 1" required>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Semester No.</label>
                                <select name="semester_number" class="form-control" required>
                                    <option value="1" <?php echo (int)$semesterForm['semester_number'] === 1 ? 'selected' : ''; ?>>1</option>
                                    <option value="2" <?php echo (int)$semesterForm['semester_number'] === 2 ? 'selected' : ''; ?>>2</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Status</label>
                                <select name="status" class="form-control" required>
                                    <?php foreach ($allowedSemesterStatus as $st): ?>
                                        <option value="<?php echo e($st); ?>" <?php echo $semesterForm['status'] === $st ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($st)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Semester Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo e($semesterForm['start_date']); ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Semester End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo e($semesterForm['end_date']); ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Registration Start Date</label>
                                <input type="date" name="registration_start_date" class="form-control" value="<?php echo e($semesterForm['registration_start_date']); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>Registration End Date</label>
                                <input type="date" name="registration_end_date" class="form-control" value="<?php echo e($semesterForm['registration_end_date']); ?>">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $semesterForm['id'] > 0 ? 'Update Semester' : 'Create Semester'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Existing Semesters</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 calendar-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Semester Dates</th>
                                    <th>Registration Window</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($semesters)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">No semesters found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($semesters as $row): ?>
                                        <tr>
                                            <td><?php echo e($row['year_name']); ?></td>
                                            <td>
                                                <?php echo e($row['semester_name']); ?>
                                                <small class="text-muted d-block">No. <?php echo (int)$row['semester_number']; ?></small>
                                            </td>
                                            <td><?php echo e(($row['start_date'] ?? '-') . ' to ' . ($row['end_date'] ?? '-')); ?></td>
                                            <td><?php echo e(($row['registration_start_date'] ?: '-') . ' to ' . ($row['registration_end_date'] ?: '-')); ?></td>
                                            <td>
                                                <span class="badge <?php echo e($badgeClassForStatus($row['status'] ?? 'inactive')); ?>">
                                                    <?php echo e(ucfirst((string)($row['status'] ?? 'inactive'))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-group">
                                                    <a href="academic-calendar.php?tab=semesters&edit_semester=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                    <form method="post" onsubmit="return confirm('Delete this semester? This cannot be undone.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                                                        <input type="hidden" name="action" value="delete_semester">
                                                        <input type="hidden" name="active_tab" value="semesters">
                                                        <input type="hidden" name="semester_id" value="<?php echo (int)$row['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php elseif ($activeTab === 'announcements'): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?php echo $announcementForm['id'] > 0 ? 'Edit Announcement' : 'Add Announcement'; ?></strong>
                    <?php if ($announcementForm['id'] > 0): ?>
                        <a href="academic-calendar.php?tab=announcements" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post" id="announcementForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                        <input type="hidden" name="action" value="save_announcement">
                        <input type="hidden" name="active_tab" value="announcements">
                        <input type="hidden" name="announcement_id" value="<?php echo (int)$announcementForm['id']; ?>">
                        <input type="hidden" name="announcement_submit_token" value="<?php echo e($announcementSubmitToken); ?>">

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Title</label>
                                <input type="text" name="title" class="form-control" value="<?php echo e($announcementForm['title']); ?>" required>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Audience</label>
                                <select name="target_audience" class="form-control" required>
                                    <?php foreach ($allowedAnnouncementAudience as $aud): ?>
                                        <option value="<?php echo e($aud); ?>" <?php echo $announcementForm['target_audience'] === $aud ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($aud)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Priority</label>
                                <select name="priority" class="form-control" required>
                                    <?php foreach ($allowedAnnouncementPriority as $prio): ?>
                                        <option value="<?php echo e($prio); ?>" <?php echo $announcementForm['priority'] === $prio ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($prio)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Status</label>
                                <select name="status" class="form-control" required>
                                    <?php foreach ($allowedStatus as $st): ?>
                                        <option value="<?php echo e($st); ?>" <?php echo $announcementForm['status'] === $st ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($st)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Content</label>
                            <textarea name="content" class="form-control" rows="4" required><?php echo e($announcementForm['content']); ?></textarea>
                        </div>

                        <div class="card mb-3 border-light">
                            <div class="card-body py-2">
                                <label class="d-block mb-2"><strong>Student Communication Push</strong></label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="send_to_students_now" name="send_to_students_now" value="1">
                                    <label class="form-check-label" for="send_to_students_now">Send immediately to students after save</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="send_channel_portal" name="send_channel_portal" value="1" checked>
                                    <label class="form-check-label" for="send_channel_portal">Portal notification</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="send_channel_email" name="send_channel_email" value="1">
                                    <label class="form-check-label" for="send_channel_email">Email</label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Works only when Audience is <strong>All</strong> or <strong>Students</strong> and Status is <strong>Active</strong>.
                                    For targeted/filter-based broadcasts, use
                                    <a href="communications.php">Admin Communications</a>.
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo e($announcementForm['start_date']); ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo e($announcementForm['end_date']); ?>" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" id="announcementSubmitBtn">
                            <i class="fas fa-save"></i> <?php echo $announcementForm['id'] > 0 ? 'Update Announcement' : 'Create Announcement'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Existing Announcements</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 calendar-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Audience</th>
                                    <th>Priority</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($announcements)): ?>
                                    <tr><td colspan="7" class="text-center text-muted">No announcements found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($announcements as $row): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo e($row['title']); ?></strong>
                                                <small class="text-muted d-block"><?php echo e($shortText($row['content'] ?? '', 120)); ?></small>
                                            </td>
                                            <td><?php echo e(ucfirst((string)($row['target_audience'] ?? 'all'))); ?></td>
                                            <td><?php echo e(ucfirst((string)($row['priority'] ?? 'normal'))); ?></td>
                                            <td><?php echo e(($row['start_date'] ?? '-') . ' to ' . (($row['end_date'] ?? '') !== '' ? $row['end_date'] : '-')); ?></td>
                                            <td>
                                                <span class="badge <?php echo e($badgeClassForStatus($row['status'] ?? 'inactive')); ?>">
                                                    <?php echo e(ucfirst((string)($row['status'] ?? 'inactive'))); ?>
                                                </span>
                                            </td>
                                            <td><?php echo e($row['creator_name'] ?? ($row['creator_username'] ?? 'Unknown')); ?></td>
                                            <td>
                                                <div class="action-group">
                                                    <a href="academic-calendar.php?tab=announcements&edit_announcement=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                    <form method="post" onsubmit="return confirm('Delete this announcement? This cannot be undone.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                                                        <input type="hidden" name="action" value="delete_announcement">
                                                        <input type="hidden" name="active_tab" value="announcements">
                                                        <input type="hidden" name="announcement_id" value="<?php echo (int)$row['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Academic Calendar Audit Log</strong>
                    <small class="text-muted">Logs for module: academic_calendar</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 calendar-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Time</th>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($auditLogs)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No audit logs found for academic calendar actions.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <tr>
                                            <td><?php echo e(Helper::formatDateTime($log['created_at'] ?? '', 'M d, Y g:i:s A')); ?></td>
                                            <td>
                                                <?php echo e($log['actor_name'] ?? ($log['username'] ?? 'System')); ?>
                                                <?php if (!empty($log['user_role'])): ?>
                                                    <small class="text-muted d-block"><?php echo e(ucfirst((string)$log['user_role'])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo e($badgeClassForAction($log['action'] ?? '')); ?>">
                                                    <?php echo e(strtoupper((string)($log['action'] ?? '-'))); ?>
                                                </span>
                                            </td>
                                            <td><?php echo e($log['description'] ?? ''); ?></td>
                                            <td><?php echo e($log['ip_address'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var announcementForm = document.getElementById('announcementForm');
    if (!announcementForm) {
        return;
    }

    announcementForm.addEventListener('submit', function (e) {
        if (announcementForm.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }
        announcementForm.dataset.submitting = '1';

        var submitBtn = document.getElementById('announcementSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
