<?php
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($studentId <= 0) {
    die('Invalid student ID.');
}

// No financial checks here for admin view
// The admin should be able to see the transcript regardless of outstanding balances.

$studentStmt = $conn->prepare("SELECT * FROM students WHERE id = :id");
$studentStmt->execute(['id' => $studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student not found.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Transcript - Admin View</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body>
    <div class="container py-5">
        <?php include_once '../../student/transcript_template.php'; ?>
    </div>
</body>
</html>
