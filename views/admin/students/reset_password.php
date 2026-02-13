<?php
/**
 * Admin action: Reset student portal password
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$session = new Session('admin');
$auth = new Auth('admin');
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

$stmt = $conn->prepare('SELECT s.*, u.id as user_id FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = :id');
$stmt->execute(['id' => $id]);
$student = $stmt->fetch();
if (!$student) {
    $session->setFlash('success', 'Student not found');
    header('Location: list.php');
    exit;
}

$temp = Security::generatePassword(10);
$hash = Security::hashPassword($temp);
$ust = $conn->prepare('UPDATE users SET password_hash = :hash, require_password_change = 1 WHERE id = :id');
$ust->execute(['hash' => $hash, 'id' => $student['user_id']]);

$session->setFlash('success', 'Password reset. Temporary password: ' . $temp);
header('Location: list.php');
exit;
