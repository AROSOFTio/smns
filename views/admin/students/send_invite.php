<?php
/**
 * Admin action: Send invite to student (placeholder)
 * Currently sets a flash message and would normally email an invite link.
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

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
$stmt = $conn->prepare('SELECT s.*, u.email FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = :id');
$stmt->execute(['id' => $id]);
$student = $stmt->fetch();
if (!$student) {
    $session->setFlash('success', 'Student not found');
    header('Location: list.php');
    exit;
}

// Placeholder: in real system send an email with invite link or credentials
$session->setFlash('success', 'Invite sent to ' . ($student['email'] ?? 'N/A') . ' (placeholder)');
header('Location: list.php');
exit;
