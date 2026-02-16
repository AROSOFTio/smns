<?php
/**
 * Remove Course Assignment - Admin
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$assignmentId = (int)($_GET['id'] ?? 0);

if (!$assignmentId) {
    $session->setFlash('error', 'Invalid assignment ID');
    header('Location: assigned.php');
    exit;
}

// Get assignment details for confirmation
$stmt = $conn->prepare("
    SELECT ca.*, c.course_code, c.course_name, l.first_name, l.last_name
    FROM course_assignments ca
    INNER JOIN courses c ON ca.course_id = c.id
    INNER JOIN lecturers l ON ca.lecturer_id = l.id
    WHERE ca.id = :id
");
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    $session->setFlash('error', 'Assignment not found');
    header('Location: assigned.php');
    exit;
}

// Delete the assignment
try {
    $stmt = $conn->prepare("DELETE FROM course_assignments WHERE id = :id");
    $stmt->execute(['id' => $assignmentId]);

    $session->setFlash('success', 'Course assignment removed successfully: ' .
        $assignment['course_code'] . ' - ' . $assignment['first_name'] . ' ' . $assignment['last_name']);

} catch (Exception $e) {
    $session->setFlash('error', 'Failed to remove assignment: ' . $e->getMessage());
}

header('Location: assigned.php');
exit;
?>