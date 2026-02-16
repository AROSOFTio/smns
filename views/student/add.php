<?php
/**
 * Student Self-Registration
 * Allows students to create their own accounts
 */
require_once '../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $program_id = intval($_POST['program_id'] ?? 0);

    if (empty($first_name) || empty($last_name) || empty($email)) {
        $errors[] = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            // Check if email exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $errors[] = 'Email already registered.';
            } else {
                $conn->beginTransaction();

                // Generate username
                $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0]));
                $username = $base;
                $suffix = 1;
                while ($conn->prepare("SELECT id FROM users WHERE username = :u")->execute(['u' => $username]) && $conn->fetch()) {
                    $username = $base . $suffix;
                    $suffix++;
                }

                // Generate password
                $tempPassword = bin2hex(random_bytes(5));

                // Create user
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, status, require_password_change, created_at) VALUES (:u, :e, :p, 'student', 'pending', 1, NOW())");
                $stmt->execute([
                    'u' => $username,
                    'e' => $email,
                    'p' => Security::hashPassword($tempPassword)
                ]);
                $userId = $conn->lastInsertId();

                // Create student
                $stmt = $conn->prepare("INSERT INTO students (user_id, first_name, last_name, email, program_id, status, created_at) VALUES (:uid, :fn, :ln, :e, :pid, 'pending', NOW())");
                $stmt->execute([
                    'uid' => $userId,
                    'fn' => $first_name,
                    'ln' => $last_name,
                    'e' => $email,
                    'pid' => $program_id ?: null
                ]);

                $conn->commit();

                $success = 'Registration successful. Username: ' . $username . ' Password: ' . $tempPassword . ' (Admin approval required)';
            }
        } catch (Exception $e) {
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}

// Fetch programs
$db = new Database();
$conn = $db->getConnection();
$programs = $conn->query("SELECT id, program_name FROM programs WHERE status='active' ORDER BY program_name")->fetchAll();

$pageTitle = 'Student Registration - ' . APP_NAME;
include '../../includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4>Register as Student</h4>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo e($success); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach($errors as $err) echo '<div>' . e($err) . '</div>'; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Program</label>
                            <select name="program_id" class="form-control">
                                <option value="">Select Program</option>
                                <?php foreach($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo e($p['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Register</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>