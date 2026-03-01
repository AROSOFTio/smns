<?php
/**
 * Admin - Student Requests (Approve / Reject)
 */
require_once '../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$requestTypeLabels = [
    'change_programme' => 'Change Of Programme',
    'administrative_registration' => 'Administrative Registration',
    'accommodation' => 'Apply For Accommodation',
    'transcript_request' => 'Transcript Request',
    'new_id_card' => 'New ID Card',
    'semester_registration' => 'Semester Registration',
    'enable_registration' => 'Enable Registration',
    'student_record_access' => 'Student Record Access',
    'student_record_correction' => 'Student Record Correction',
    'data_deletion_anonymization' => 'Data Deletion / Anonymization',
];
$complianceRequestTypes = ['student_record_access', 'student_record_correction', 'data_deletion_anonymization'];
$formatRequestType = static function (string $type) use ($requestTypeLabels): string {
    if (isset($requestTypeLabels[$type])) {
        return (string)$requestTypeLabels[$type];
    }
    return ucwords(str_replace('_', ' ', $type));
};
$buildTranscriptChecklist = static function (array $eligibility): string {
    $parts = [
        'Completed studies: ' . (!empty($eligibility['completed_studies']) ? 'YES' : 'NO'),
        'No outstanding retakes: ' . (!empty($eligibility['has_no_retakes']) ? 'YES' : 'NO'),
        'Bills cleared: ' . (!empty($eligibility['bills_cleared']) ? 'YES' : 'NO'),
        'Discipline in good standing: ' . (!empty($eligibility['discipline_ok']) ? 'YES' : 'NO'),
    ];
    return implode(' | ', $parts);
};
$buildTranscriptUnfulfilled = static function (array $eligibility): array {
    if (!empty($eligibility['eligible'])) {
        return [];
    }
    if (!empty($eligibility['blocking_reasons'])) {
        return array_values((array)$eligibility['blocking_reasons']);
    }
    return ['Requirements not fulfilled.'];
};
$allowedViews = ['all', 'compliance', 'transcript'];
$requestView = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'all';
if (!in_array($requestView, $allowedViews, true)) {
    $requestView = 'all';
}
$allowedPageSizes = [25, 50, 100];
$rowsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($rowsPerPage, $allowedPageSizes, true)) {
    $rowsPerPage = 25;
}
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage <= 0) {
    $currentPage = 1;
}
$buildViewUrl = static function (string $view, int $page = 1, int $perPage = 25): string {
    $params = ['view' => $view];
    if ($page > 1) {
        $params['page'] = $page;
    }
    if ($perPage > 0) {
        $params['per_page'] = $perPage;
    }
    return 'student_requests.php?' . http_build_query($params);
};
$redirectUrl = $buildViewUrl($requestView, $currentPage, $rowsPerPage);

// Ensure student_requests table exists (safe to run each request)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        user_id INT NOT NULL,
        request_type VARCHAR(100) NOT NULL,
        semester_id INT NULL,
        year_of_study INT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_request_type (request_type),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore
}

// Ensure transcript rights table exists for transcript-release workflow.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS transcript_download_rights (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL UNIQUE,
        status ENUM('granted','revoked') NOT NULL DEFAULT 'revoked',
        verified_by_user_id INT NULL,
        verified_at DATETIME NULL,
        revoked_by_user_id INT NULL,
        revoked_at DATETIME NULL,
        one_time_download_used TINYINT(1) NOT NULL DEFAULT 0,
        one_time_download_used_at DATETIME NULL,
        one_time_download_format VARCHAR(16) NULL,
        download_count INT NOT NULL DEFAULT 0,
        last_downloaded_at DATETIME NULL,
        last_download_format VARCHAR(16) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore
}

// Bulk approve semester registration requests (optional semester filter)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_all_semester_requests') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: ' . $redirectUrl); exit;
    }

    $semesterFilter = isset($_POST['semester_id']) && (int)$_POST['semester_id'] > 0 ? (int)$_POST['semester_id'] : null;

    $sql = "SELECT sr.*, s.first_name, s.last_name, u.email AS user_email
            FROM student_requests sr
            LEFT JOIN students s ON sr.student_id = s.id
            LEFT JOIN users u ON sr.user_id = u.id
            WHERE sr.request_type = 'semester_registration' AND sr.status = 'pending'";
    $params = [];
    if ($semesterFilter) { $sql .= " AND semester_id = :semid"; $params['semid'] = $semesterFilter; }
    $rowsStmt = $conn->prepare($sql);
    $rowsStmt->execute($params);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $session->setFlash('info', 'No pending semester registration requests found.');
        header('Location: ' . $redirectUrl); exit;
    }

    $updateReq = $conn->prepare("UPDATE student_requests SET status = 'approved', admin_response = :resp, updated_at = NOW() WHERE id = :id");
    $selectSR = $conn->prepare('SELECT id, status FROM semester_registrations WHERE student_id = :sid AND semester_id = :semid LIMIT 1');
    $updateSR = $conn->prepare('UPDATE semester_registrations SET status = "approved", approved_by = :admin, approval_date = NOW(), updated_at = NOW() WHERE id = :id');
    $insertSR = $conn->prepare('INSERT INTO semester_registrations (student_id, semester_id, status, request_date, approved_by, approval_date, created_at) VALUES (:sid, :semid, "approved", NOW(), :admin, NOW(), NOW())');
    $notifIns = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");

    $approvedCount = 0;
    $conn->beginTransaction();
    try {
        foreach ($rows as $r) {
            // update request
            $updateReq->execute(['resp' => 'Approved by admin (bulk)', 'id' => $r['id']]);

            $semId = $r['semester_id'] ?? null;
            $stuId = $r['student_id'] ?? null;
            $uid = $r['user_id'] ?? null;

            if ($semId && $stuId) {
                // ensure semester_registrations exists; insert/update accordingly
                $selectSR->execute(['sid' => $stuId, 'semid' => $semId]);
                $sr = $selectSR->fetch(PDO::FETCH_ASSOC);
                if ($sr) {
                    if ($sr['status'] !== 'approved') {
                        $updateSR->execute(['admin' => $_SESSION['admin_id'] ?? 1, 'id' => $sr['id']]);
                    }
                } else {
                    $insertSR->execute(['sid' => $stuId, 'semid' => $semId, 'admin' => $_SESSION['admin_id'] ?? 1]);
                }
                        // Auto-assign courses and mark student as reported when approved
                        try {
                            // call central helper to auto-assign courses
                            if (!function_exists('auto_assign_courses')) require_once '../../includes/functions.php';
                            $sdet = $conn->prepare('SELECT program_id, level_year FROM students WHERE id = :id LIMIT 1');
                            $sdet->execute(['id' => $stuId]);
                            $sinfo = $sdet->fetch(PDO::FETCH_ASSOC);
                            $programId = $sinfo['program_id'] ?? null;
                            $levelYear = $sinfo['level_year'] ?? null;

                            auto_assign_courses($conn, $stuId, $semId, $_SESSION['admin_id'] ?? null, $programId, $levelYear);

                            // mark student as reported
                            $semInfoStmt = $conn->prepare('SELECT academic_year_id FROM semesters WHERE id = :id LIMIT 1');
                            $semInfoStmt->execute(['id' => $semId]);
                            $academicYearId = $semInfoStmt->fetchColumn();
                            $colCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'last_reported_semester_id'")->fetch();
                            if (!$colCheck) {
                                $conn->exec("ALTER TABLE students ADD COLUMN last_reported_semester_id INT NULL, ADD COLUMN last_reported_academic_year_id INT NULL, ADD COLUMN last_reported_at DATETIME NULL");
                            }
                            $upd = $conn->prepare("UPDATE students SET last_reported_semester_id = :sem, last_reported_academic_year_id = :ay, last_reported_at = NOW() WHERE id = :id");
                            $upd->execute(['sem' => $semId, 'ay' => $academicYearId, 'id' => $stuId]);
                        } catch (Exception $e) {
                            try { if (class_exists('Logger')) (new Logger())->log($_SESSION['admin_id'] ?? null, 'auto_reg_error', 'student_requests', 'Auto-registration failed: ' . $e->getMessage()); } catch(Exception $le) { error_log('Auto reg error: ' . $le->getMessage()); }
                        }
            }

            // notify student (if user id available)
            if ($uid) {
                $notifIns->execute([
                    'uid' => $uid,
                    'title' => 'Semester Registration Approved',
                    'msg' => 'Your semester registration request has been approved by administration.',
                    'type' => 'success',
                    'link' => BASE_URL . '/views/student/course-registration.php?semester_id=' . ($semId ?? '')
                ]);
            }

            if (!empty($r['user_email']) && filter_var($r['user_email'], FILTER_VALIDATE_EMAIL)) {
                Helper::sendTemplatedEmail('request_response', $r['user_email'], [
                    'recipient_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Student',
                    'request_type' => 'Semester Registration Request',
                    'status' => 'approved',
                    'admin_response' => 'Approved by admin (bulk)',
                    'request_id' => $r['id'] ?? '',
                    'action_url' => BASE_URL . '/views/student/course-registration.php?semester_id=' . ($semId ?? '')
                ]);
            }

            $approvedCount++;
        }
        $conn->commit();
        $session->setFlash('success', $approvedCount . ' semester registration request(s) approved.');
    } catch (Exception $e) {
        $conn->rollBack();
        error_log('Bulk approve error: ' . $e->getMessage());
        $session->setFlash('error', 'Failed to approve requests.');
    }

    header('Location: ' . $redirectUrl); exit;
}

// Handle action (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve','reject'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: ' . $redirectUrl); exit;
    }

    $action = $_POST['action'];
    $reqId = intval($_POST['request_id'] ?? 0);
    $response = trim($_POST['admin_response'] ?? '');

    if ($reqId <= 0) {
        $session->setFlash('error', 'Invalid request id');
        header('Location: ' . $redirectUrl); exit;
    }

    // Load request
    $rq = $conn->prepare('SELECT sr.*, s.first_name, s.last_name, s.student_id as reg_no, u.email as user_email FROM student_requests sr LEFT JOIN students s ON sr.student_id = s.id LEFT JOIN users u ON sr.user_id = u.id WHERE sr.id = :id LIMIT 1');
    $rq->execute(['id' => $reqId]);
    $row = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $session->setFlash('error', 'Request not found');
        header('Location: ' . $redirectUrl); exit;
    }
    $isComplianceRequest = in_array((string)($row['request_type'] ?? ''), $complianceRequestTypes, true);
    $isTranscriptRequest = ((string)($row['request_type'] ?? '') === 'transcript_request');
    if ($isComplianceRequest && $response === '') {
        $session->setFlash('error', 'Admin response is required for compliance requests.');
        header('Location: ' . $redirectUrl); exit;
    }
    if ($isTranscriptRequest && $action === 'approve') {
        $eligibilityNow = getStudentTranscriptEligibility($conn, (int)($row['student_id'] ?? 0));
        $checklistNow = $buildTranscriptChecklist((array)$eligibilityNow);
        if (empty($eligibilityNow['eligible'])) {
            $reasonNow = !empty($eligibilityNow['blocking_reasons'])
                ? implode(' ', (array)$eligibilityNow['blocking_reasons'])
                : 'Transcript requirements are not fully met.';
            $systemBlockMsg = 'System check: ' . $checklistNow . '. Eligibility check: NOT MET. ' . $reasonNow;
            try {
                $conn->prepare('UPDATE student_requests SET admin_response = :resp, updated_at = NOW() WHERE id = :id')
                    ->execute(['resp' => $systemBlockMsg, 'id' => $reqId]);
            } catch (Exception $e) {
                // ignore
            }
            try {
                $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :message, 'warning', :link, NOW())");
                $notifStmt->execute([
                    'uid' => (int)($row['user_id'] ?? 0),
                    'title' => 'Transcript Request Update',
                    'message' => 'Transcript release is pending. ' . $systemBlockMsg,
                    'link' => BASE_URL . '/views/student/services.php?tab=history&request_id=' . $reqId
                ]);
            } catch (Exception $e) {
                // ignore
            }
            try {
                if (class_exists('Logger')) {
                    (new Logger())->log(
                        (int)($_SESSION['admin_id'] ?? 0),
                        'transcript_release_blocked',
                        'student_requests',
                        'Transcript release blocked for request #' . $reqId . ' (student_id: ' . (int)($row['student_id'] ?? 0) . '). ' . $systemBlockMsg
                    );
                }
            } catch (Exception $e) {
                error_log('Transcript release blocked log error: ' . $e->getMessage());
            }
            $session->setFlash('error', 'Transcript cannot be released yet. ' . $reasonNow);
            header('Location: ' . $redirectUrl); exit;
        }

        if ($response === '') {
            $response = 'Eligibility verified. Verified transcript released to student.';
        }
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    if ($row['status'] === $newStatus) {
        $session->setFlash('info', 'Request already ' . $newStatus);
        header('Location: ' . $redirectUrl); exit;
    }

    try {
        $u = $conn->prepare('UPDATE student_requests SET status = :status, admin_response = :resp, updated_at = NOW() WHERE id = :id');
        $u->execute(['status' => $newStatus, 'resp' => $response, 'id' => $reqId]);

        if ($isTranscriptRequest && $newStatus === 'approved') {
            $releaseStmt = $conn->prepare("
                INSERT INTO transcript_download_rights (
                    student_id, status, verified_by_user_id, verified_at,
                    one_time_download_used, one_time_download_used_at, one_time_download_format,
                    notes
                ) VALUES (
                    :student_id, 'granted', :admin_id, NOW(),
                    0, NULL, NULL,
                    :notes
                )
                ON DUPLICATE KEY UPDATE
                    status = 'granted',
                    verified_by_user_id = VALUES(verified_by_user_id),
                    verified_at = VALUES(verified_at),
                    one_time_download_used = 0,
                    one_time_download_used_at = NULL,
                    one_time_download_format = NULL,
                    notes = VALUES(notes),
                    revoked_by_user_id = NULL,
                    revoked_at = NULL
            ");
            $releaseStmt->execute([
                'student_id' => (int)($row['student_id'] ?? 0),
                'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
                'notes' => 'Released from Transcript Request #' . $reqId
            ]);
            try {
                if (class_exists('Logger')) {
                    (new Logger())->log(
                        (int)($_SESSION['admin_id'] ?? 0),
                        'transcript_released',
                        'student_requests',
                        'Transcript released for request #' . $reqId . ' (student_id: ' . (int)($row['student_id'] ?? 0) . ').'
                    );
                }
            } catch (Exception $e) {
                error_log('Transcript release log error: ' . $e->getMessage());
            }
        }

        // If this request was for semester registration and the admin approved it,
        // create or update a corresponding record in `semester_registrations` so
        // the student can immediately select courses.
        if (!empty($row['request_type']) && $row['request_type'] === 'semester_registration') {
            try {
                // ensure table exists (defensive)
                $conn->exec("CREATE TABLE IF NOT EXISTS semester_registrations (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    student_id INT NOT NULL,
                    semester_id INT NOT NULL,
                    status ENUM('pending','approved','rejected') DEFAULT 'pending',
                    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                    approved_by INT DEFAULT NULL,
                    approval_date DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_student_semester (student_id, semester_id),
                    KEY idx_student (student_id),
                    KEY idx_semester (semester_id),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            } catch (Exception $ex) {
                // ignore table-create failures here
            }

            $semId = $row['semester_id'] ?? null;
            $stuId = $row['student_id'] ?? null;
            $adminId = $_SESSION['admin_id'] ?? null;

            if ($semId && $stuId) {
                if ($newStatus === 'approved') {
                    // check existing
                    $sr = $conn->prepare('SELECT id, status FROM semester_registrations WHERE student_id = :sid AND semester_id = :semid LIMIT 1');
                    $sr->execute(['sid' => $stuId, 'semid' => $semId]);
                    $existingSR = $sr->fetch();

                    // use year_of_study from request if available, else try to extract from reason
                    $yearFromReason = $row['year_of_study'] ?? null;
                    if (!$yearFromReason && !empty($row['reason']) && preg_match('/Year of study:\s*Year\s*(\d{1,2})/i', $row['reason'], $m)) {
                        $y = (int)$m[1]; if ($y >= 1 && $y <= 10) $yearFromReason = $y;
                    }

                    if ($existingSR) {
                        if ($existingSR['status'] !== 'approved') {
                            $u2 = $conn->prepare('UPDATE semester_registrations SET status = "approved", year_of_study = :yos, approved_by = :admin, approval_date = NOW(), updated_at = NOW() WHERE id = :id');
                            $u2->execute(['yos' => $yearFromReason, 'admin' => $adminId, 'id' => $existingSR['id']]);
                        }
                    } else {
                        $i2 = $conn->prepare('INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, approved_by, approval_date, created_at) VALUES (:sid, :semid, :yos, "approved", NOW(), :admin, NOW(), NOW())');
                        $i2->execute(['sid' => $stuId, 'semid' => $semId, 'yos' => $yearFromReason, 'admin' => $adminId]);
                    }
                } elseif ($newStatus === 'rejected') {
                    // if rejected, mark any existing registration as rejected
                    $conn->prepare('UPDATE semester_registrations SET status = "rejected", updated_at = NOW() WHERE student_id = :sid AND semester_id = :semid')->execute(['sid' => $stuId, 'semid' => $semId]);
                }
            }
        }

        // Prepare student notification content (include admin response)
        $noteTitle = $action === 'approve' ? 'Request Approved' : 'Request Rejected';
        $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Student';
        $noteMsg = $noteTitle . ': ' . $formatRequestType((string)$row['request_type']) . '. Admin response: ' . ($response ?: '-');
        $noteLink = BASE_URL . '/views/student/services.php?tab=history&request_id=' . $reqId;
        if ($isTranscriptRequest && $newStatus === 'approved') {
            $noteMsg .= ' Your verified transcript has been released to your portal.';
            $noteLink = BASE_URL . '/views/student/transcript.php';
        }

        // First try to UPDATE the student's original submission notification (if present)
        try {
            $upd = $conn->prepare("UPDATE notifications SET title = :title, message = :msg, type = :type, link = :link, read_status = 'unread', created_at = NOW(), read_at = NULL WHERE user_id = :uid AND (link LIKE :link_like OR message LIKE :msg_like)");
            $upd->execute([
                'title' => $noteTitle,
                'msg' => $noteMsg,
                'type' => $action === 'approve' ? 'success' : 'warning',
                'link' => $noteLink,
                'uid' => $row['user_id'],
                'link_like' => '%request_id=' . $reqId . '%',
                'msg_like' => '%Request ID: ' . $reqId . '%'
            ]);

            if ($upd->rowCount() === 0) {
                // No existing student notification found for this request — insert a new one
                $nstmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                $nstmt->execute([
                    'uid' => $row['user_id'],
                    'title' => $noteTitle,
                    'msg' => $noteMsg,
                    'type' => $action === 'approve' ? 'success' : 'warning',
                    'link' => $noteLink
                ]);
            }
        } catch (Exception $e) {
            // fallback: insert notification
            try {
                $nstmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, :type, :link, NOW())");
                $nstmt->execute([
                    'uid' => $row['user_id'],
                    'title' => $noteTitle,
                    'msg' => $noteMsg,
                    'type' => $action === 'approve' ? 'success' : 'warning',
                    'link' => $noteLink
                ]);
            } catch (Exception $ex) {
                // swallow
            }
        }

        if (!empty($row['user_email']) && filter_var($row['user_email'], FILTER_VALIDATE_EMAIL)) {
            Helper::sendTemplatedEmail('request_response', $row['user_email'], [
                'recipient_name' => $studentName,
                'request_type' => $formatRequestType((string)$row['request_type']),
                'status' => $newStatus,
                'admin_response' => $response,
                'request_id' => $reqId,
                'action_url' => $noteLink
            ]);
        }

        $session->setFlash('success', 'Request ' . $newStatus . ' successfully');
    } catch (Exception $e) {
        error_log('Error updating student request: ' . $e->getMessage());
        $session->setFlash('error', 'Failed to update request');
    }

    header('Location: ' . $redirectUrl); exit;
}

// Fetch requests (optionally filtered by active view) with pagination.
$fromSql = ' FROM student_requests sr LEFT JOIN students s ON sr.student_id = s.id LEFT JOIN users u ON sr.user_id = u.id';
$whereSql = '';
$fetchParams = [];
if ($requestView === 'compliance') {
    $inParts = [];
    foreach ($complianceRequestTypes as $idx => $type) {
        $key = 'crt' . $idx;
        $inParts[] = ':' . $key;
        $fetchParams[$key] = $type;
    }
    $whereSql = ' WHERE sr.request_type IN (' . implode(', ', $inParts) . ')';
} elseif ($requestView === 'transcript') {
    $whereSql = ' WHERE sr.request_type = :transcript_type';
    $fetchParams['transcript_type'] = 'transcript_request';
}
$totalRows = 0;
try {
    $countStmt = $conn->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
    foreach ($fetchParams as $paramKey => $paramValue) {
        $countStmt->bindValue(':' . $paramKey, $paramValue);
    }
    $countStmt->execute();
    $totalRows = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $totalRows = 0;
}
$totalPages = max(1, (int)ceil($totalRows / max($rowsPerPage, 1)));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $rowsPerPage;
$requests = [];
try {
    $fetchSql = 'SELECT sr.*, s.first_name, s.last_name, s.student_id as reg_no, u.email as user_email'
        . $fromSql
        . $whereSql
        . ' ORDER BY sr.created_at DESC LIMIT :limit_rows OFFSET :offset_rows';
    $stmt = $conn->prepare($fetchSql);
    foreach ($fetchParams as $paramKey => $paramValue) {
        $stmt->bindValue(':' . $paramKey, $paramValue);
    }
    $stmt->bindValue(':limit_rows', $rowsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $requests = $stmt->fetchAll();
} catch (Exception $e) {
    $requests = [];
}

// fetch semesters for bulk-approve selector
try {
    $sstmt = $conn->query("SELECT s.id, s.semester_name, s.semester_number, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.start_date DESC, s.semester_number DESC");
    $semesterOptions = $sstmt->fetchAll();
} catch (Exception $e) {
    $semesterOptions = [];
}

// Enrich requests with semester label when semester_id is present
foreach ($requests as &$rq) {
    $rq['semester_label'] = '';
    $rq['system_check'] = '-';
    $rq['transcript_eligible'] = null;
    $rq['transcript_unfulfilled'] = [];
    $rq['transcript_check_items'] = [];
    if (!empty($rq['semester_id'])) {
        try {
            $ss = $conn->prepare('SELECT s.semester_name, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
            $ss->execute(['id' => $rq['semester_id']]);
            $sr = $ss->fetch();
            if ($sr) $rq['semester_label'] = ($sr['year_name'] ?? '') . ' - ' . ($sr['semester_name'] ?? '');
        } catch (Exception $e) {
            // ignore
        }
    }
    if ((string)($rq['request_type'] ?? '') === 'transcript_request') {
        try {
            $eligibility = getStudentTranscriptEligibility($conn, (int)($rq['student_id'] ?? 0));
            $rq['transcript_eligible'] = !empty($eligibility['eligible']);
            $rq['transcript_unfulfilled'] = $buildTranscriptUnfulfilled((array)$eligibility);
            $rq['transcript_check_items'] = [
                [
                    'label' => 'Completed studies',
                    'ok' => !empty($eligibility['completed_studies']),
                    'note' => ''
                ],
                [
                    'label' => 'No outstanding retakes',
                    'ok' => !empty($eligibility['has_no_retakes']),
                    'note' => !empty($eligibility['retake_count']) ? ((int)$eligibility['retake_count'] . ' pending') : ''
                ],
                [
                    'label' => 'Bills cleared',
                    'ok' => !empty($eligibility['bills_cleared']),
                    'note' => !empty($eligibility['bills_cleared']) ? '' : ('Outstanding UGX ' . number_format((float)($eligibility['outstanding_bills'] ?? 0)))
                ],
                [
                    'label' => 'Discipline in good standing',
                    'ok' => !empty($eligibility['discipline_ok']),
                    'note' => !empty($eligibility['discipline_status']) ? (string)$eligibility['discipline_status'] : ''
                ],
            ];
            $checklist = $buildTranscriptChecklist((array)$eligibility);
            if (!empty($eligibility['eligible'])) {
                $rq['system_check'] = $checklist . ' | READY FOR RELEASE';
            } else {
                $reason = !empty($eligibility['blocking_reasons']) ? implode(' ', (array)$eligibility['blocking_reasons']) : 'Requirements not fulfilled.';
                $rq['system_check'] = $checklist . ' | NOT MET: ' . $reason;
            }
        } catch (Exception $e) {
            $rq['transcript_eligible'] = false;
            $rq['system_check'] = 'Unable to evaluate transcript checklist at the moment.';
            $rq['transcript_unfulfilled'] = ['Unable to evaluate transcript requirements at the moment.'];
            $rq['transcript_check_items'] = [];
        }
    }
}
unset($rq);

$displayStart = $totalRows > 0 ? ($offset + 1) : 0;
$displayEnd = $totalRows > 0 ? min($offset + count($requests), $totalRows) : 0;
$showTypeColumn = ($requestView !== 'transcript');
$showSemesterColumn = ($requestView !== 'transcript');
$emptyColspan = 7 + ($showTypeColumn ? 1 : 0) + ($showSemesterColumn ? 1 : 0);

$pageTitle = ($requestView === 'transcript' ? 'Transcript - Admin - ' : 'Student Requests - Admin - ') . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/admin/sidebar.php'; ?>

<style>
.content-area { overflow-x: hidden; }
.requests-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.requests-toolbar-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.requests-toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.requests-meta { color: #64748b; font-size: 12px; font-weight: 600; }
.requests-table { width: 100%; table-layout: fixed; }
.requests-table th, .requests-table td { white-space: normal; word-break: break-word; vertical-align: top; }
.requests-table .col-date { width: 118px; }
.requests-table .col-student { width: 130px; }
.requests-table .col-reg { width: 92px; }
.requests-table .col-type { width: 128px; }
.requests-table .col-semester { width: 130px; }
.requests-table .col-reason { width: 180px; }
.requests-table .col-system { width: 380px; }
.requests-table .col-status { width: 90px; }
.requests-table .col-actions { width: 250px; }
.request-reason { margin: 0; color: #334155; }
.system-check-cell { color: #334155; position: relative; overflow: visible !important; }
.system-check-wrapper { position: relative; display: inline-block; margin-top: 6px; }
.system-check-trigger {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #2563eb;
    cursor: help;
    user-select: none;
    text-decoration: underline;
    text-decoration-style: dotted;
}
.system-check-popover {
    display: none;
    position: absolute;
    left: 0;
    top: calc(100% + 8px);
    width: min(520px, 75vw);
    max-width: 520px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.22);
    z-index: 1050;
}
.system-check-wrapper:hover .system-check-popover,
.system-check-wrapper:focus-within .system-check-popover {
    display: block;
}
.requests-table.transcript-view .col-date { width: 135px; }
.requests-table.transcript-view .col-student { width: 150px; }
.requests-table.transcript-view .col-reg { width: 115px; }
.requests-table.transcript-view .col-reason { width: 160px; }
.requests-table.transcript-view .col-system { width: 520px; }
.requests-table.transcript-view .col-actions { width: 180px; }
.transcript-checklist {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 10px;
}
.transcript-check-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 8px;
    background: #f8fafc;
}
.transcript-check-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.transcript-check-label {
    font-size: 12px;
    font-weight: 600;
    color: #334155;
}
.transcript-check-note {
    margin-top: 4px;
    font-size: 11px;
    color: #64748b;
}
.unfulfilled-panel {
    background: #fff1f2;
    color: #9f1239;
}
.request-actions-form { margin: 0; }
.request-actions-form .admin-response { width: 100%; margin-bottom: 6px; }
.request-action-buttons { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.requests-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.requests-per-page label { margin-bottom: 0; font-size: 12px; font-weight: 600; color: #475569; }
.requests-pagination .pagination { margin-bottom: 0; }
html[data-theme='dark'] .system-check-cell {
    color: #e2e8f0;
}
html[data-theme='dark'] .system-check-trigger {
    color: #93c5fd;
}
html[data-theme='dark'] .system-check-popover {
    background: #0f172a;
    border-color: #334155;
    box-shadow: 0 12px 30px rgba(2, 6, 23, 0.7);
}
html[data-theme='dark'] .transcript-check-item {
    border-color: #334155;
    background: #1e293b;
}
html[data-theme='dark'] .transcript-check-label {
    color: #e2e8f0;
}
html[data-theme='dark'] .transcript-check-note {
    color: #94a3b8;
}
html[data-theme='dark'] .unfulfilled-panel {
    background: rgba(127, 29, 29, 0.35);
    color: #fecaca;
    border-color: #7f1d1d !important;
}
@media (max-width: 991.98px) {
    .requests-table { font-size: 12px; }
    .requests-table .col-reg,
    .requests-table .col-semester { width: 80px; }
    .requests-table .col-actions { width: 220px; }
    .system-check-popover {
        width: min(420px, 92vw);
    }
    .transcript-checklist { grid-template-columns: 1fr; }
}
</style>

<div class="main-content">
        <div class="topbar">
        <div class="topbar-left">
            <h4><?php echo $requestView === 'transcript' ? 'Transcript' : 'Student Requests'; ?></h4>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Pending / Recent Requests</div>
            <div class="card-body p-0">
                <div class="p-3">
                    <div class="requests-toolbar mb-2">
                        <div class="requests-toolbar-left">
                            <a href="<?php echo e($buildViewUrl('all', 1, $rowsPerPage)); ?>" class="btn btn-sm <?php echo $requestView === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">All Requests</a>
                            <a href="<?php echo e($buildViewUrl('transcript', 1, $rowsPerPage)); ?>" class="btn btn-sm <?php echo $requestView === 'transcript' ? 'btn-primary' : 'btn-outline-primary'; ?>">Transcript</a>
                            <a href="<?php echo e($buildViewUrl('compliance', 1, $rowsPerPage)); ?>" class="btn btn-sm <?php echo $requestView === 'compliance' ? 'btn-primary' : 'btn-outline-primary'; ?>">Compliance / Legal</a>
                        </div>
                        <div class="requests-toolbar-right">
                            <span class="requests-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalRows; ?></span>
                            <form method="GET" class="form-inline requests-per-page">
                                <input type="hidden" name="view" value="<?php echo e($requestView); ?>">
                                <label for="perPageSelect" class="mr-2">Rows</label>
                                <select id="perPageSelect" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                                    <?php foreach ($allowedPageSizes as $size): ?>
                                        <option value="<?php echo (int)$size; ?>" <?php echo $rowsPerPage === (int)$size ? 'selected' : ''; ?>>
                                            <?php echo (int)$size; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    <?php if ($requestView === 'transcript'): ?>
                        <div class="alert alert-info mb-3">
                            Transcript requests are processed here. Approve only when the checklist is fully met to release transcript access to the student portal.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-3">
                            Compliance requests (record access, correction, deletion/anonymization) require documented admin responses.
                        </div>
                    <?php endif; ?>
                    <?php if ($requestView === 'all'): ?>
                        <form method="POST" class="form-inline mb-3" onsubmit="return confirm('Approve ALL pending semester registration requests' + (document.getElementById('bulkSemester').value ? ' for the selected semester?' : '?'))">
                            <?php echo csrfField(); ?>
                            <label class="mr-2 mb-2" style="font-weight:600;">Bulk approve semester requests:</label>
                            <select name="semester_id" id="bulkSemester" class="form-control mr-2 mb-2">
                                <option value="">All Semesters</option>
                                <?php foreach ($semesterOptions as $so): ?>
                                    <option value="<?php echo $so['id']; ?>"><?php echo e($so['year_name'] . ' - ' . $so['semester_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button name="action" value="approve_all_semester_requests" class="btn btn-sm btn-warning mb-2">Approve All</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-sm requests-table<?php echo $requestView === 'transcript' ? ' transcript-view' : ''; ?>" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th class="col-date">Date</th>
                                <th class="col-student">Student</th>
                                <th class="col-reg">Reg #</th>
                                <?php if ($showTypeColumn): ?><th class="col-type">Type</th><?php endif; ?>
                                <?php if ($showSemesterColumn): ?><th class="col-semester">Semester</th><?php endif; ?>
                                <th class="col-reason">Reason</th>
                                <th class="col-system">System Check</th>
                                <th class="col-status">Status</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($requests)): ?>
                                <?php foreach ($requests as $r): ?>
                                    <tr>
                                        <td><?php echo e(Helper::formatDateTime($r['created_at'], 'M d, Y H:i')); ?></td>
                                        <td><?php echo e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); ?></td>
                                        <td><?php echo e($r['reg_no'] ?? '-'); ?></td>
                                        <?php if ($showTypeColumn): ?><td><?php echo e($formatRequestType((string)$r['request_type'])); ?></td><?php endif; ?>
                                        <?php if ($showSemesterColumn): ?><td><?php echo e($r['semester_label'] ?: '-'); ?></td><?php endif; ?>
                                        <td><p class="request-reason"><?php echo e(mb_substr((string)($r['reason'] ?? ''), 0, 120)); ?><?php echo mb_strlen((string)($r['reason'] ?? '')) > 120 ? '...' : ''; ?></p></td>
                                        <td class="system-check-cell">
                                            <?php if ((string)($r['request_type'] ?? '') === 'transcript_request'): ?>
                                                <span class="badge badge-<?php echo !empty($r['transcript_eligible']) ? 'success' : 'danger'; ?> mb-1 mr-1">
                                                    <?php echo !empty($r['transcript_eligible']) ? 'READY' : 'NOT MET'; ?>
                                                </span>
                                                <div class="system-check-wrapper">
                                                    <span class="system-check-trigger" tabindex="0">System checks</span>
                                                    <div class="system-check-popover">
                                                        <?php if (!empty($r['transcript_check_items'])): ?>
                                                            <ul class="transcript-checklist">
                                                                <?php foreach ((array)$r['transcript_check_items'] as $checkItem): ?>
                                                                    <li class="transcript-check-item">
                                                                        <div class="transcript-check-top">
                                                                            <span class="transcript-check-label"><?php echo e((string)($checkItem['label'] ?? '')); ?></span>
                                                                            <span class="badge badge-<?php echo !empty($checkItem['ok']) ? 'success' : 'danger'; ?>">
                                                                                <?php echo !empty($checkItem['ok']) ? 'YES' : 'NO'; ?>
                                                                            </span>
                                                                        </div>
                                                                        <?php if (!empty($checkItem['note'])): ?>
                                                                            <div class="transcript-check-note"><?php echo e((string)$checkItem['note']); ?></div>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r['transcript_unfulfilled'])): ?>
                                                            <div class="mt-2 p-2 rounded border border-danger unfulfilled-panel">
                                                                <div class="font-weight-bold mb-1">Unfulfilled Requirements</div>
                                                                <ul class="mb-0 pl-3">
                                                                    <?php foreach ((array)$r['transcript_unfulfilled'] as $unmetItem): ?>
                                                                        <li><?php echo e((string)$unmetItem); ?></li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-<?php echo $r['status']==='pending' ? 'warning' : ($r['status']==='approved' ? 'success' : 'secondary'); ?>"><?php echo e(ucfirst($r['status'])); ?></span></td>
                                        <td>
                                            <?php if ($r['status'] === 'pending'): ?>
                                            <form method="POST" action="" class="request-actions-form">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo e($r['id']); ?>">
                                                <input type="text" name="admin_response" placeholder="Response (required for compliance requests)" class="form-control form-control-sm admin-response">
                                                <div class="request-action-buttons">
                                                    <button class="btn btn-sm btn-success" type="submit" name="action" value="approve" <?php echo ((string)($r['request_type'] ?? '') === 'transcript_request' && empty($r['transcript_eligible'])) ? 'disabled title="Transcript checklist not fulfilled yet."' : ''; ?>>
                                                        <?php echo ((string)($r['request_type'] ?? '') === 'transcript_request') ? 'Release' : 'Approve'; ?>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" type="submit" name="action" value="reject">Reject</button>
                                                </div>
                                            </form>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled>No actions</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="<?php echo (int)$emptyColspan; ?>" class="text-center text-muted py-3">No requests found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalRows > 0): ?>
                    <div class="requests-footer p-3 border-top">
                        <div class="requests-meta">Showing <?php echo (int)$displayStart; ?>-<?php echo (int)$displayEnd; ?> of <?php echo (int)$totalRows; ?> requests</div>
                        <div class="requests-pagination">
                            <ul class="pagination pagination-sm">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildViewUrl($requestView, max(1, $currentPage - 1), $rowsPerPage)); ?>">Previous</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo e($buildViewUrl($requestView, $p, $rowsPerPage)); ?>"><?php echo (int)$p; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($buildViewUrl($requestView, min($totalPages, $currentPage + 1), $rowsPerPage)); ?>">Next</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
