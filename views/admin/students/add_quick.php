<?php
/**
 * Quick Add Student - single page fallback for admins
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$errors = [];
$success = '';
$defaultStudentPassword = 'Password@2026';

// Fetch programs
$pstmt = $conn->query("SELECT id, program_name FROM programs WHERE status='active' ORDER BY program_name");
$programs = $pstmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $program_id = intval($_POST['program_id'] ?? 0) ?: null;
    $level_year = intval($_POST['level_year'] ?? 1);

    if ($first_name === '' || $last_name === '' || $email === '') {
        $errors[] = 'First name, last name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    } else {
        try {
            $conn->beginTransaction();

            // Check duplicate email
            $ucheck = $conn->prepare("SELECT id FROM users WHERE email = :email");
            $ucheck->execute(['email' => $email]);
            if ($ucheck->fetch()) {
                throw new Exception('Email already registered');
            }

            $sEmailCheck = $conn->prepare("SELECT id, student_id FROM students WHERE email = :email LIMIT 1");
            $sEmailCheck->execute(['email' => $email]);
            $existingByEmail = $sEmailCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingByEmail) {
                throw new Exception('Student email already exists under ID ' . $existingByEmail['student_id']);
            }

            $identityCheck = $conn->prepare("
                SELECT id, student_id
                FROM students
                WHERE LOWER(first_name) = LOWER(:first_name)
                  AND LOWER(last_name) = LOWER(:last_name)
                  AND program_id <=> :program_id
                  AND level_year = :level_year
                LIMIT 1
            ");
            $identityCheck->execute([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'program_id' => $program_id,
                'level_year' => $level_year
            ]);
            $possibleDuplicate = $identityCheck->fetch(PDO::FETCH_ASSOC);
            if ($possibleDuplicate) {
                throw new Exception('Possible duplicate student record detected (' . $possibleDuplicate['student_id'] . '). Use full Add Student form to verify identity details.');
            }

            // Create user
            $usernameBase = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0] ?? 'student')) ?: 'student';
            $username = $usernameBase;
            $suffix = 1;
            $authChk = new Auth();
            while ($authChk->usernameExists($username)) {
                $username = $usernameBase . $suffix;
                $suffix++;
            }

            $tempPassword = $defaultStudentPassword;
            $passwordHash = Security::hashPassword($tempPassword);

            $ust = $conn->prepare("INSERT INTO users (username,email,password_hash,role,status,require_password_change,created_at) VALUES (:username,:email,:hash,'student','active',0,NOW())");
            $ust->execute(['username' => $username, 'email' => $email, 'hash' => $passwordHash]);
            $newUserId = $conn->lastInsertId();

            // Generate permanent student code (PRN-like) with collision guard.
            $studentCode = generateStudentRegistrationNumber($conn);

            $sstmt = $conn->prepare("INSERT INTO students (user_id, student_id, first_name, last_name, email, program_id, level_year, status, created_at) VALUES (:user_id,:student_id,:first_name,:last_name,:email,:program_id,:level_year,'active',NOW())");
            $sstmt->execute([
                'user_id' => $newUserId,
                'student_id' => $studentCode,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'program_id' => $program_id,
                'level_year' => $level_year
            ]);

            $conn->commit();
            try {
                $mailSent = Helper::sendTemplatedEmail('credentials_issued', $email, [
                    'recipient_name' => trim($first_name . ' ' . $last_name),
                    'role_label' => 'Student',
                    'account_id_label' => 'Student ID',
                    'account_id_value' => $studentCode,
                    'username' => $username,
                    'temporary_password' => $tempPassword,
                    'login_url' => BASE_URL . '/views/student/login.php'
                ]);
            } catch (Exception $mailEx) {
                $mailSent = false;
            }

            $success = 'Student created. Username: ' . $username . ' Password: ' . $tempPassword;
            $success .= $mailSent
                ? ' Credentials were emailed to the student.'
                : ' Account created, but email delivery failed. Share credentials manually.';
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Failed to create student: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Quick Add Student - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar"><h4>Quick Add Student</h4></div>
    <div class="content-area">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger"><?php foreach($errors as $err) echo '<div>' . e($err) . '</div>'; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>First Name</label>
                            <input name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Last Name</label>
                            <input name="last_name" class="form-control" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Email</label>
                            <input name="email" type="email" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Program</label>
                            <select name="program_id" class="form-control"><option value="">--</option><?php foreach($programs as $p) echo '<option value="' . $p['id'] . '">' . e($p['program_name']) . '</option>'; ?></select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>Level</label>
                            <select name="level_year" class="form-control"><option>1</option><option>2</option><option>3</option><option>4</option></select>
                        </div>
                    </div>
                    <button class="btn btn-success">Create Student</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
