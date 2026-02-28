<?php
// Student Password Reset Page
require_once '../../config.php';



$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_valid = Security::verifyCSRFToken($_POST['csrf_token'] ?? '');
    $username = Security::sanitize(trim($_POST['username'] ?? ''));
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$csrf_valid) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($username) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $policyErrors = [];
        if (!Security::validatePasswordPolicy($new_password, $policyErrors)) {
            $error = implode(' ', $policyErrors);
        }
        if (empty($error)) {
            $auth = new Auth('student');
            $user = $auth->usernameExists($username);
            if (!$user || $user['role'] !== 'student') {
                $error = 'Invalid username.';
            } else {
                // Update password using Database connection
                $db = new Database();
                $conn = $db->getConnection();
                if (Security::isPasswordReused($conn, (int)$user['id'], $new_password)) {
                    $error = 'You cannot reuse a recent password.';
                } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $stmt->execute(['hash' => $hash, 'id' => $user['id']]);
                $success = 'Password reset successful.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
        (function () {
            try {
                var mode = localStorage.getItem('smns_theme_mode');
                if (mode === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
            } catch (e) {}
        })();
    </script>
    <title>Reset Student Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/css/theme-shared.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">Reset Student Password</div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="form-group">
                            <label for="username">Student Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-success btn-block">Reset Password</button>
                    </form>
                    <a href="login.php" class="btn btn-link mt-2">Back to Login</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
