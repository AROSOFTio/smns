<?php
/**
 * Admin action: Send invite to student.
 */
require_once '../../../config.php';



$session = new Session('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    $session->setFlash('success', 'Invalid student id');
    header('Location: list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare('SELECT s.*, u.email, u.username FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = :id');
$stmt->execute(['id' => $id]);
$student = $stmt->fetch();
if (!$student) {
    $session->setFlash('success', 'Student not found');
    header('Location: list.php');
    exit;
}

$studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: 'Student';
$sent = false;
if (!empty($student['email'])) {
    $sent = Helper::sendTemplatedEmail('invite_notice', $student['email'], [
        'recipient_name' => $studentName,
        'role_label' => 'Student',
        'username' => $student['username'] ?? '-',
        'login_url' => BASE_URL . '/views/student/login.php',
        'custom_note' => 'This is an access reminder. If you forgot your password, request a reset from administration.'
    ]);
}

if ($sent) {
    $session->setFlash('success', 'Invite sent to ' . ($student['email'] ?? 'N/A'));
} else {
    $session->setFlash('error', 'Failed to send invite email. Verify SMTP and recipient address.');
}
header('Location: list.php');
exit;
