<?php
// Add error reporting for development (remove or set to 0 in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}
$db = new Database();
$conn = $db->getConnection();
$progStmt = $conn->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
$programs = $progStmt->fetchAll();
$semStmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY s.start_date DESC");
$semesters = $semStmt->fetchAll();
$errors = [];
$formData = $_POST;
$success = '';
$mailStatus = '';
$defaultStudentPassword = 'Password@2026';

function generateAdmissionNumber($conn) {
    $year = date('Y');
    $prefix = 'ADM' . $year;
    try {
        $stmt = $conn->prepare("SELECT admission_number FROM students WHERE admission_number LIKE :prefix ORDER BY admission_number DESC LIMIT 1");
        $stmt->execute(['prefix' => $prefix . '%']);
        $lastAdmission = (string)($stmt->fetchColumn() ?: '');
        $nextNumber = 1;
        if ($lastAdmission !== '' && preg_match('/(\d+)$/', $lastAdmission, $m)) {
            $nextNumber = ((int)$m[1]) + 1;
        }
        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        $stmt = $conn->query("SELECT COUNT(*) FROM students WHERE YEAR(created_at) = " . (int)$year);
        $count = (int)$stmt->fetchColumn() + 1;
        return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
$registration_number = generateStudentRegistrationNumber($conn);

// Assign student_id to registration_number
$student_id = $registration_number;

// Helper: Safe value fetch
function safeVal($arr, $key, $default = '') {
    return isset($arr[$key]) ? htmlspecialchars($arr[$key]) : $default;
}
// Helper: Validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
// Helper: Validate date
function isValidDate($date) {
    return (bool)strtotime($date);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admission_number = generateAdmissionNumber($conn);
    $registration_number = generateStudentRegistrationNumber($conn);
    $student_id = $registration_number;
    $student_reg = $registration_number;
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $dob = $_POST['date_of_birth'] ?? null;
    $gender = $_POST['gender'] ?? '';
    $program_id = $_POST['program'] ?? '';
    $study_year = $_POST['study_year'] ?? 1;
    $session_val = $_POST['session'] ?? '';
    $entry_mode = $_POST['entry_mode'] ?? '';
    $current_semester = $_POST['current_semester'] ?? '';
    // Additional fields
    $other_name = trim($_POST['other_name'] ?? '');
    $primary_number = trim($_POST['primary_number'] ?? '');
    $secondary_number = trim($_POST['secondary_number'] ?? '');
    $smns_email = trim($_POST['smns_email'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $school_college = trim($_POST['school_college'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $intake = trim($_POST['intake'] ?? '');
    $academic_status = $_POST['academic_status'] ?? '';
    $discipline_status = $_POST['discipline_status'] ?? '';
    $financial_information = trim($_POST['financial_information'] ?? '');

    // Validation
    if (!$first_name) $errors[] = 'First Name required';
    if (!$last_name) $errors[] = 'Last Name required';
    if (!$email) $errors[] = 'Email required';
    if ($email && !isValidEmail($email)) $errors[] = 'Invalid email format';
    if (!$dob) $errors[] = 'Date of Birth required';
    if ($dob && !isValidDate($dob)) $errors[] = 'Invalid date of birth';
    if (!$gender) $errors[] = 'Gender required';
    if (!$program_id) $errors[] = 'Program required';
    if (!$primary_number) $errors[] = 'Primary Number required';
    if (!$study_year || !is_numeric($study_year) || $study_year < 1) $errors[] = 'Valid Study Year required';
    if (!$session_val) $errors[] = 'Session required';
    if (!$entry_mode) $errors[] = 'Entry Mode required';
    if (!$current_semester) $errors[] = 'Current Semester required';
    if (!$academic_status) $errors[] = 'Academic Status required';

    // Duplicate controls for student identity/data integrity.
    if (!$errors) {
        $emailUserStmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $emailUserStmt->execute(['email' => $email]);
        if ($emailUserStmt->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'Email address is already used by another account.';
        }

        $emailStudentStmt = $conn->prepare("SELECT id, student_id FROM students WHERE email = :email LIMIT 1");
        $emailStudentStmt->execute(['email' => $email]);
        $existingStudentByEmail = $emailStudentStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingStudentByEmail) {
            $errors[] = 'A student record with this email already exists (' . $existingStudentByEmail['student_id'] . ').';
        }

        if ($dob && $program_id) {
            $identityStmt = $conn->prepare("
                SELECT id, student_id
                FROM students
                WHERE LOWER(first_name) = LOWER(:first_name)
                  AND LOWER(last_name) = LOWER(:last_name)
                  AND date_of_birth = :date_of_birth
                  AND program_id = :program_id
                LIMIT 1
            ");
            $identityStmt->execute([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'date_of_birth' => $dob,
                'program_id' => $program_id
            ]);
            $existingByIdentity = $identityStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingByIdentity) {
                $errors[] = 'Possible duplicate student identity found (' . $existingByIdentity['student_id'] . ').';
            }
        }

        if ($primary_number !== '') {
            $phoneStmt = $conn->prepare("SELECT id, student_id FROM students WHERE primary_number = :primary_number LIMIT 1");
            $phoneStmt->execute(['primary_number' => $primary_number]);
            $existingByPhone = $phoneStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingByPhone) {
                $errors[] = 'Primary phone number is already linked to student ' . $existingByPhone['student_id'] . '.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            $usernameBase = strtolower((string)preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0] ?? ''));
            if ($usernameBase === '') {
                $usernameBase = 'student';
            }
            $username = $usernameBase;
            $suffix = 1;
            while (true) {
                $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = :u");
                $checkStmt->execute(['u' => $username]);
                if (!$checkStmt->fetch()) break;
                $username = $usernameBase . $suffix;
                $suffix++;
            }
            $password = $defaultStudentPassword;
            $hash = Security::hashPassword($password);
            $stmt = $conn->prepare("INSERT INTO users (username,email,password_hash,role,status,created_at) VALUES (:u,:e,:p,'student','active',NOW())");
            $stmt->execute(['u' => $username, 'e' => $email, 'p' => $hash]);
            $userId = $conn->lastInsertId();
            $stmt2 = $conn->prepare("INSERT INTO students (user_id,admission_number,student_id,first_name,other_name,last_name,email,smns_email,primary_number,secondary_number,nationality,school_college,department,program_id,study_year,session,entry_mode,current_semester,intake,academic_status,discipline_status,financial_information,date_of_birth,gender,status,created_at) VALUES (:uid,:adm,:reg,:fn,:on,:ln,:em,:smns,:pn,:sn,:nat,:sc,:dept,:program_id,:study_year,:session,:entry_mode,:current_semester,:intake,:academic_status,:discipline_status,:financial_information,:dob,:g,'active',NOW())");
            $stmt2->execute([
                'uid' => $userId,
                'adm' => $admission_number,
                'reg' => $student_reg,
                'fn' => $first_name,
                'on' => $other_name,
                'ln' => $last_name,
                'em' => $email,
                'smns' => $smns_email,
                'pn' => $primary_number,
                'sn' => $secondary_number,
                'nat' => $nationality,
                'sc' => $school_college,
                'dept' => $department,
                'program_id' => $program_id,
                'study_year' => $study_year,
                'session' => $session_val,
                'entry_mode' => $entry_mode,
                'current_semester' => $current_semester,
                'intake' => $intake,
                'academic_status' => $academic_status,
                'discipline_status' => $discipline_status,
                'financial_information' => $financial_information,
                'dob' => $dob,
                'g' => $gender
            ]);
            $conn->commit();
            try {
                $mailSent = Helper::sendTemplatedEmail('credentials_issued', $email, [
                    'recipient_name' => trim($first_name . ' ' . $last_name),
                    'role_label' => 'Student',
                    'account_id_label' => 'Student ID',
                    'account_id_value' => $student_reg,
                    'username' => $username,
                    'temporary_password' => $password,
                    'login_url' => BASE_URL . '/views/student/login.php'
                ]);
                $mailStatus = $mailSent ? 'Credentials were emailed to the student.' : 'Account created, but email delivery failed. Share credentials manually.';
            } catch (Exception $mailEx) {
                $mailStatus = 'Account created, but email delivery failed. Share credentials manually.';
            }
            $success = 'Student added successfully!';
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'student_id') !== false) {
                $errors[] = 'Student ID conflict detected. Please submit again.';
            } else {
                $errors[] = 'Database error: ' . $msg;
            }
        }
    }
}
$pageTitle = 'Add Student - ' . APP_NAME;
include '../../../includes/header.php';
include '../../../includes/admin/sidebar.php';
?>
<div class="main-content">
    <div class="content-area">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach($errors as $err): ?>
                    <div><?php echo htmlspecialchars($err); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <?php if (!empty($mailStatus)): ?>
                <div class="alert alert-info"><?php echo htmlspecialchars($mailStatus); ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($success && isset($username) && isset($password)): ?>
            <div class="alert alert-info">
                <strong>Login Credentials:</strong><br>
                Username: <b><?php echo htmlspecialchars($username); ?></b><br>
                Password: <b><?php echo htmlspecialchars($password); ?></b>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>First Name *</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Other Name</label>
                            <input type="text" name="other_name" class="form-control" value="<?php echo htmlspecialchars($formData['other_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Date of Birth *</label>
                            <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($formData['date_of_birth'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Gender *</label>
                            <select name="gender" class="form-control" required>
                                <option value="">Select</option>
                                <option value="Male" <?php echo ($formData['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($formData['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($formData['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Primary Number *</label>
                            <input type="text" name="primary_number" class="form-control" value="<?php echo htmlspecialchars($formData['primary_number'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Secondary Number</label>
                            <input type="text" name="secondary_number" class="form-control" value="<?php echo htmlspecialchars($formData['secondary_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Email *</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>SMNS Email</label>
                            <input type="email" name="smns_email" class="form-control" value="<?php echo htmlspecialchars($formData['smns_email'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Nationality</label>
                            <input type="text" name="nationality" class="form-control" value="<?php echo htmlspecialchars($formData['nationality'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Program *</label>
                            <select name="program" class="form-control" required>
                                <option value="">Select Program</option>
                                <?php foreach($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo ($formData['program'] ?? '') == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>School/College</label>
                            <input type="text" name="school_college" class="form-control" value="<?php echo htmlspecialchars($formData['school_college'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Department</label>
                            <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($formData['department'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Study Year *</label>
                            <input type="number" name="study_year" class="form-control" value="<?php echo htmlspecialchars($formData['study_year'] ?? '1'); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Session *</label>
                            <select name="session" class="form-control" required>
                                <option value="">Select Session</option>
                                <option value="Day" <?php echo ($formData['session'] ?? '') == 'Day' ? 'selected' : ''; ?>>Day</option>
                                <option value="Evening" <?php echo ($formData['session'] ?? '') == 'Evening' ? 'selected' : ''; ?>>Evening</option>
                                <option value="Weekend" <?php echo ($formData['session'] ?? '') == 'Weekend' ? 'selected' : ''; ?>>Weekend</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Entry Mode *</label>
                            <select name="entry_mode" class="form-control" required>
                                <option value="">Select Entry Mode</option>
                                <option value="Entry Papers" <?php echo ($formData['entry_mode'] ?? '') == 'Entry Papers' ? 'selected' : ''; ?>>Entry Papers</option>
                                <option value="Direct Entry" <?php echo ($formData['entry_mode'] ?? '') == 'Direct Entry' ? 'selected' : ''; ?>>Direct Entry</option>
                                <option value="Transfer" <?php echo ($formData['entry_mode'] ?? '') == 'Transfer' ? 'selected' : ''; ?>>Transfer</option>
                                <option value="Mature Entry" <?php echo ($formData['entry_mode'] ?? '') == 'Mature Entry' ? 'selected' : ''; ?>>Mature Entry</option>
                                <option value="Special Consideration" <?php echo ($formData['entry_mode'] ?? '') == 'Special Consideration' ? 'selected' : ''; ?>>Special Consideration</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Current Semester *</label>
                            <select name="current_semester" class="form-control" required>
                                <option value="">Select Semester</option>
                                <?php foreach($semesters as $sem): ?>
                                    <option value="<?php echo $sem['id']; ?>" <?php echo ($formData['current_semester'] ?? '') == $sem['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sem['year_name'] . ' - ' . $sem['semester_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Intake</label>
                            <input type="text" name="intake" class="form-control" value="<?php echo htmlspecialchars($formData['intake'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Academic Status *</label>
                            <select name="academic_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="Active" <?php echo ($formData['academic_status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Suspended" <?php echo ($formData['academic_status'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="Withdrawn" <?php echo ($formData['academic_status'] ?? '') == 'Withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                                <option value="Graduated" <?php echo ($formData['academic_status'] ?? '') == 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Discipline Status</label>
                            <select name="discipline_status" class="form-control">
                                <option value="">Select Discipline Status</option>
                                <option value="Good Standing" <?php echo ($formData['discipline_status'] ?? '') == 'Good Standing' ? 'selected' : ''; ?>>Good Standing</option>
                                <option value="Probation" <?php echo ($formData['discipline_status'] ?? '') == 'Probation' ? 'selected' : ''; ?>>Probation</option>
                                <option value="Suspended" <?php echo ($formData['discipline_status'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="Expelled" <?php echo ($formData['discipline_status'] ?? '') == 'Expelled' ? 'selected' : ''; ?>>Expelled</option>
                            </select>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Financial Information</label>
                            <textarea name="financial_information" class="form-control" rows="2"><?php echo htmlspecialchars($formData['financial_information'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success">Add Student</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>
