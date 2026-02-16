<?php
// Web-based admin tool to unlock a user account by username or email
require_once '../config.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    if (empty($identifier)) {
        $error = 'Please enter a username or email.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            $stmt = $conn->prepare("UPDATE users SET status = 'active', account_locked_until = NULL, failed_login_attempts = 0 WHERE username = :username OR email = :email");
            $stmt->execute(['username' => $identifier, 'email' => $identifier]);
            if ($stmt->rowCount() > 0) {
                $success = "User '$identifier' unlocked successfully.";
            } else {
                $error = "No user found with username or email '$identifier'.";
            }
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
    <title>Unlock User Account</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="container" style="max-width:400px;margin:40px auto;">
        <h2>Unlock User Account</h2>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username or Email</label>
                <input type="text" name="identifier" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Unlock Account</button>
        </form>
    </div>
</body>
</html>
