<?php
require_once '../../config.php';

$session = new Session('student');
$auth = new Auth('student');

// Must be logged in as student.
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || ($_SESSION['student_role'] ?? '') !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];

$requestType = trim((string)($_POST['request_type'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$csrf = $_POST['csrf_token'] ?? '';

$requestTypeLabels = [
    'change_programme' => 'Change Of Programme',
    'administrative_registration' => 'Administrative Registration',
    'accommodation' => 'Apply For Accommodation',
    'new_id_card' => 'New ID Card',
    'semester_registration' => 'Semester Registration',
    'enable_registration' => 'Enable Registration',
    'student_record_access' => 'Student Record Access',
    'student_record_correction' => 'Student Record Correction',
    'data_deletion_anonymization' => 'Data Deletion / Anonymization',
];
$formatRequestType = static function (string $type) use ($requestTypeLabels): string {
    if (isset($requestTypeLabels[$type])) {
        return (string)$requestTypeLabels[$type];
    }
    return ucwords(str_replace('_', ' ', $type));
};

if (!Security::verifyCSRFToken($csrf)) {
    $session->setFlash('error', 'Invalid CSRF token');
    header('Location: ' . BASE_URL . '/views/student/services.php?tab=apply');
    exit;
}

if ($requestType === '' || $reason === '' || !isset($requestTypeLabels[$requestType])) {
    $session->setFlash('error', 'Please select a request type and provide a reason.');
    header('Location: ' . BASE_URL . '/views/student/services.php?tab=apply');
    exit;
}

if (in_array($requestType, ['student_record_access', 'student_record_correction', 'data_deletion_anonymization'], true) && mb_strlen($reason) < 20) {
    $session->setFlash('error', 'Please provide at least 20 characters for compliance requests.');
    header('Location: ' . BASE_URL . '/views/student/services.php?tab=apply&request=' . urlencode($requestType));
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        user_id INT NOT NULL,
        request_type VARCHAR(100) NOT NULL,
        reason TEXT NOT NULL,
        semester_id INT NULL,
        year_of_study INT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_semester (semester_id),
        INDEX idx_request_type (request_type),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $colSemester = $conn->query("SHOW COLUMNS FROM student_requests LIKE 'semester_id'")->fetch();
    if (!$colSemester) {
        $conn->exec("ALTER TABLE student_requests ADD COLUMN semester_id INT NULL AFTER reason");
    }
    $colYear = $conn->query("SHOW COLUMNS FROM student_requests LIKE 'year_of_study'")->fetch();
    if (!$colYear) {
        $conn->exec("ALTER TABLE student_requests ADD COLUMN year_of_study INT NULL AFTER semester_id");
    }

    $semesterId = isset($_POST['semester_id']) && (int)$_POST['semester_id'] > 0 ? (int)$_POST['semester_id'] : null;
    if ($requestType === 'enable_registration' && $semesterId) {
        $dupStmt = $conn->prepare("SELECT id FROM student_requests WHERE student_id = :student_id AND request_type = :request_type AND semester_id = :semester_id AND status = 'pending' LIMIT 1");
        $dupStmt->execute([
            'student_id' => (int)($studentProfile['id'] ?? 0),
            'request_type' => $requestType,
            'semester_id' => $semesterId
        ]);
        if ($dupStmt->fetch()) {
            $session->setFlash('info', 'You already have a pending request to enable registration for the selected semester.');
            header('Location: ' . BASE_URL . '/views/student/services.php?tab=history');
            exit;
        }
    }

    if (in_array($requestType, ['student_record_access', 'student_record_correction', 'data_deletion_anonymization'], true)) {
        $dupCompliance = $conn->prepare("SELECT id FROM student_requests WHERE student_id = :student_id AND request_type = :request_type AND status = 'pending' LIMIT 1");
        $dupCompliance->execute([
            'student_id' => (int)($studentProfile['id'] ?? 0),
            'request_type' => $requestType
        ]);
        if ($dupCompliance->fetch()) {
            $session->setFlash('info', 'You already have a pending ' . $formatRequestType($requestType) . ' request.');
            header('Location: ' . BASE_URL . '/views/student/services.php?tab=history');
            exit;
        }
    }

    $ins = $conn->prepare("INSERT INTO student_requests (student_id, user_id, request_type, reason, semester_id, status, created_at) VALUES (:student_id, :user_id, :request_type, :reason, :semester_id, 'pending', NOW())");
    $ins->execute([
        'student_id' => (int)($studentProfile['id'] ?? 0),
        'user_id' => (int)($currentUser['id'] ?? 0),
        'request_type' => $requestType,
        'reason' => $reason,
        'semester_id' => $semesterId
    ]);

    $requestId = (int)$conn->lastInsertId();
    $semLabel = '';
    if ($semesterId) {
        $ss = $conn->prepare('SELECT s.semester_name, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
        $ss->execute(['id' => $semesterId]);
        $srow = $ss->fetch(PDO::FETCH_ASSOC);
        if ($srow) {
            $semLabel = ' (' . ($srow['year_name'] ?? '') . ' - ' . ($srow['semester_name'] ?? '') . ')';
        }
    }

    // Notify active admins.
    try {
        $admStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $adminUsers = $admStmt->fetchAll(PDO::FETCH_COLUMN);
        $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :message, 'info', :link, NOW())");
        $studentName = trim((string)($studentProfile['first_name'] ?? '') . ' ' . (string)($studentProfile['last_name'] ?? ''));
        $title = 'Student Request: ' . $formatRequestType($requestType);
        $message = $studentName . ' (Reg#: ' . ($studentProfile['student_id'] ?? '-') . ') submitted a request: ' . mb_substr($reason, 0, 250) . $semLabel;
        $link = BASE_URL . '/views/admin/student_requests.php';
        foreach ($adminUsers as $adminUserId) {
            $noteStmt->execute([
                'uid' => (int)$adminUserId,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);
        }
    } catch (Exception $e) {
        // Notification failure should not block request creation.
    }

    // Student confirmation notification.
    try {
        $sTitle = 'Request Submitted: ' . $formatRequestType($requestType);
        $sMessage = 'Your request for ' . $formatRequestType($requestType) . ' has been submitted and is pending review' . ($semLabel ? ' for ' . trim($semLabel, '()') : '') . '. Request ID: ' . $requestId;
        $sLink = BASE_URL . '/views/student/services.php?tab=history&request_id=' . $requestId;
        $snote = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :message, 'info', :link, NOW())");
        $snote->execute([
            'uid' => (int)($currentUser['id'] ?? 0),
            'title' => $sTitle,
            'message' => $sMessage,
            'link' => $sLink
        ]);
    } catch (Exception $e) {
        // Ignore.
    }

    $session->setFlash('success', 'Request submitted successfully. An administrator will review it shortly.');
    header('Location: ' . BASE_URL . '/views/student/services.php?tab=history');
    exit;
} catch (Exception $e) {
    error_log('Student request error: ' . $e->getMessage());
    $session->setFlash('error', 'Failed to submit request. Please try again later.');
    header('Location: ' . BASE_URL . '/views/student/services.php?tab=apply');
    exit;
}
