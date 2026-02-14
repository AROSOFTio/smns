<?php
require_once '../../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$session = new Session('student');
$auth = new Auth('student');

// must be logged in as student
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

// Basic validation
$requestType = trim($_POST['request_type'] ?? '');
$reason = trim($_POST['reason'] ?? '');
$csrf = $_POST['csrf_token'] ?? '';

if (!Security::verifyCSRFToken($csrf)) {
    $session->setFlash('error', 'Invalid CSRF token');
    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;
}

if (empty($requestType) || empty($reason)) {
    $session->setFlash('error', 'Please select a request type and provide a reason.');
    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    // ensure table exists (safe to run repeatedly) — add semester_id for enrollment-related requests
    $conn->exec("CREATE TABLE IF NOT EXISTS student_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        user_id INT NOT NULL,
        request_type VARCHAR(100) NOT NULL,
        reason TEXT NOT NULL,
        semester_id INT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_semester (semester_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // If the table existed before adding semester_id, ensure the column exists
    $col = $conn->query("SHOW COLUMNS FROM student_requests LIKE 'semester_id'")->fetch();
    if (!$col) {
        $conn->exec("ALTER TABLE student_requests ADD COLUMN semester_id INT NULL AFTER reason, ADD INDEX idx_semester (semester_id)");
    }

    // server-side guard: prevent duplicate pending 'enable_registration' requests for the same semester
    $semesterId = isset($_POST['semester_id']) && (int)$_POST['semester_id'] > 0 ? (int)$_POST['semester_id'] : null;
    if ($requestType === 'enable_registration' && $semesterId) {
        $dupStmt = $conn->prepare("SELECT id FROM student_requests WHERE student_id = :student_id AND request_type = :rt AND semester_id = :semester_id AND status = 'pending' LIMIT 1");
        $dupStmt->execute(['student_id' => $studentProfile['id'], 'rt' => $requestType, 'semester_id' => $semesterId]);
        if ($dupStmt->fetch()) {
            $session->setFlash('info', 'You already have a pending request to enable registration for the selected semester.');
            header('Location: ' . BASE_URL . '/views/student/dashboard.php');
            exit;
        }
    }

    // insert request (include semester_id when available)
    $ins = $conn->prepare("INSERT INTO student_requests (student_id, user_id, request_type, reason, semester_id, status, created_at) VALUES (:student_id, :user_id, :request_type, :reason, :semester_id, 'pending', NOW())");
    $ins->execute([
        'student_id' => $studentProfile['id'],
        'user_id' => $currentUser['id'],
        'request_type' => $requestType,
        'reason' => $reason,
        'semester_id' => $semesterId
    ]);

    $requestId = $conn->lastInsertId();

    // notify all active admins
    try {
        $admStmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $adminUsers = $admStmt->fetchAll(PDO::FETCH_COLUMN);
        $noteStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");

        $studentName = e(trim(($studentProfile['first_name'] ?? '') . ' ' . ($studentProfile['last_name'] ?? '')));
        $title = "Student Request: " . ucfirst(str_replace('_',' ', $requestType));
        $semLabel = '';
        if ($semesterId) {
            $ss = $conn->prepare('SELECT s.semester_name, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
            $ss->execute(['id' => $semesterId]);
            $srow = $ss->fetch();
            if ($srow) $semLabel = ' (' . ($srow['year_name'] ?? '') . ' - ' . ($srow['semester_name'] ?? '') . ')';
        }

        $message = "{$studentName} (Reg#: " . ($studentProfile['student_id'] ?? '-') . ") submitted a request: " . substr($reason, 0, 250) . $semLabel;
        $link = BASE_URL . '/views/admin/student_requests.php'; // point admins to Student Requests page

        foreach ($adminUsers as $au) {
            $noteStmt->execute([
                'uid' => $au,
                'title' => $title,
                'msg' => $message,
                'link' => $link
            ]);
        }
    } catch (Exception $e) {
        // swallow notification errors
    }

    // create a confirmation notification for the student (so it can be updated later)
    try {
        $sTitle = 'Request Submitted: ' . ucfirst(str_replace('_',' ', $requestType));
        $sMsg = 'Your request for ' . ucfirst(str_replace('_',' ', $requestType)) . ' has been submitted and is pending review' . ($semLabel ? ' for ' . trim($semLabel, '()') : '') . '. Request ID: ' . $requestId;
        $sLink = BASE_URL . '/views/student/dashboard.php?request_id=' . $requestId;

        $snote = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (:uid, :title, :msg, 'info', :link, NOW())");
        $snote->execute([
            'uid' => $currentUser['id'],
            'title' => $sTitle,
            'msg' => $sMsg,
            'link' => $sLink
        ]);
    } catch (Exception $e) {
        // ignore
    }

    $session->setFlash('success', 'Request submitted successfully. An administrator will review it shortly.');
    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;

} catch (Exception $e) {
    error_log('Student request error: ' . $e->getMessage());
    $session->setFlash('error', 'Failed to submit request. Please try again later.');
    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;
}
