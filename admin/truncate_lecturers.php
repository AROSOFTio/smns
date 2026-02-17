<?php
// Web-based admin tool to truncate all lecturers
require_once '../config.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmation = trim($_POST['confirmation'] ?? '');

    if ($confirmation !== 'TRUNCATE') {
        $error = "Type TRUNCATE exactly to confirm.";
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            // Danger: removes ALL rows from lecturers table
            $conn->exec('TRUNCATE TABLE lecturers');
            $success = 'All lecturers have been removed from the lecturers table.';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Truncate Lecturers Table</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="container" style="max-width:500px;margin:40px auto;">
        <h2>Truncate Lecturers Table</h2>
        <p style="color:red;font-weight:bold;">
            WARNING: This will permanently delete ALL rows from the lecturers table.
            This cannot be undone. Make sure you have a backup.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Type <strong>TRUNCATE</strong> to confirm:</label>
                <input type="text" name="confirmation" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-danger">Truncate Lecturers</button>
        </form>
    </div>
</body>
</html>
