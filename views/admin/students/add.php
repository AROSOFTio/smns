<?php
/**
 * Admin - Add Student (Stepped)
 */
require_once '../../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Initialize step data storage in session
if (!isset($_SESSION['add_student_data'])) {
    $_SESSION['add_student_data'] = [];
}

$step = intval($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];
$success = '';

// Fetch programs and courses for selects
$progStmt = $conn->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
$programs = $progStmt->fetchAll();

$courseStmt = $conn->query("SELECT * FROM courses WHERE status = 'active' ORDER BY course_name");
$allCourses = $courseStmt->fetchAll();

$currentSemester = Helper::getCurrentSemester();

// Fetch semesters for entry selection
$semStmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY s.start_date DESC");
$semesters = $semStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save current step data to session
    foreach ($_POST as $key => $value) {
        if ($key !== 'csrf_token' && $key !== 'step' && $key !== 'action' && $key !== 'next') {
            $_SESSION['add_student_data'][$key] = $value;
        }
    }
    
    // Handle final submit
    if (isset($_POST['action']) && $_POST['action'] === 'complete') {
        // Get all data from session
        $data = $_SESSION['add_student_data'];
            
            // Debug: Check what email value we have
            // Uncomment next line if you need to debug
            // error_log("Email from session: '" . ($data['email'] ?? 'NOT SET') . "'");
            
            // Gather all fields (sanitized)
            $first_name = Security::sanitize($data['first_name'] ?? '');
            $middle_name = Security::sanitize($data['middle_name'] ?? '');
            $last_name = Security::sanitize($data['last_name'] ?? '');
            $dob = $data['date_of_birth'] ?? null;
            $gender = Security::sanitize($data['gender'] ?? '');
            $phone = Security::sanitize($data['phone'] ?? '');
            $email = trim($data['email'] ?? ''); // Don't sanitize email, just trim whitespace
            $address = Security::sanitize($data['address'] ?? '');
            $city = Security::sanitize($data['city'] ?? '');
            $country = Security::sanitize($data['country'] ?? '');
            $emergency_name = Security::sanitize($data['emergency_name'] ?? '');
            $emergency_phone = Security::sanitize($data['emergency_phone'] ?? '');
            $guardian_name = Security::sanitize($data['guardian_name'] ?? '');
            $guardian_relation = Security::sanitize($data['guardian_relation'] ?? '');
            $guardian_phone = Security::sanitize($data['guardian_phone'] ?? '');
            $guardian_email = trim($data['guardian_email'] ?? ''); // Don't sanitize email, just trim
            $program_id = intval($data['program_id'] ?? 0);
            $level_year = intval($data['level_year'] ?? 1);
            $entry_year = intval($data['entry_year'] ?? date('Y'));
            $entry_semester_id = intval($data['entry_semester_id'] ?? 0);
            $entry_mode = Security::sanitize($data['entry_mode'] ?? 'Entry Papers');
            $enrollment_type = Security::sanitize($data['enrollment_type'] ?? 'Day');

            // Portal/account will be generated automatically
            $account_status = 'active';

            // Validate required fields
            $missing = [];
            if (empty($first_name)) $missing[] = 'First Name';
            if (empty($last_name)) $missing[] = 'Last Name';
            if (empty($dob)) $missing[] = 'Date of Birth';
            if (empty($gender)) $missing[] = 'Gender';
            if (empty($email)) $missing[] = 'Email';
            if ($missing) {
                $errors[] = 'Please fill in required fields: ' . implode(', ', $missing) . '.';
            }
            
            // Simple email validation - just check basic format
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format. The email you entered appears to be malformed. Please check and try again. Current value: ' . htmlspecialchars($email);
            }
            
            // Check if email already exists (only check if not already in our current session data)
            if (!empty($email)) {
                $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = :email");
                $emailCheck->execute(['email' => $email]);
                if ($emailCheck->fetch()) {
                    $errors[] = 'This email address is already registered in the system. Please use a different email.';
                }
            }

            if (empty($errors)) {
                $transactionStarted = false;
                try {
                    $conn->beginTransaction();
                    $transactionStarted = true;

                    // Generate unique student code like 2026-STU-001
                    $year = date('Y');
                    $pattern = $year . '-STU-%';
                    $cstmt = $conn->prepare("SELECT COUNT(*) as cnt FROM students WHERE student_id LIKE :pattern");
                    $cstmt->execute(['pattern' => $pattern]);
                    $cnt = $cstmt->fetch()['cnt'] ?? 0;
                    $seq = intval($cnt) + 1;
                    $studentCode = $year . '-STU-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

                    // Ensure students.admission_number column exists
                    $colCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'admission_number'")->fetch();
                    if (!$colCheck) {
                        $conn->exec("ALTER TABLE students ADD COLUMN admission_number VARCHAR(50) UNIQUE NULL AFTER student_id");
                    }

                    // Ensure students.entry_mode column exists
                    $colCheckMode = $conn->query("SHOW COLUMNS FROM students LIKE 'entry_mode'")->fetch();
                    if (!$colCheckMode) {
                        $conn->exec("ALTER TABLE students ADD COLUMN entry_mode VARCHAR(100) NULL AFTER entry_semester_id");
                    }

                    // Ensure guardian columns exist
                    $colGuardian = $conn->query("SHOW COLUMNS FROM students LIKE 'guardian_name'")->fetch();
                    if (!$colGuardian) {
                        $conn->exec("ALTER TABLE students ADD COLUMN guardian_name VARCHAR(255) NULL AFTER country");
                        $conn->exec("ALTER TABLE students ADD COLUMN guardian_relation VARCHAR(100) NULL AFTER guardian_name");
                        $conn->exec("ALTER TABLE students ADD COLUMN guardian_phone VARCHAR(50) NULL AFTER guardian_relation");
                        $conn->exec("ALTER TABLE students ADD COLUMN guardian_email VARCHAR(150) NULL AFTER guardian_phone");
                    }

                    // Ensure enrollment_type column exists
                    $colEnrollment = $conn->query("SHOW COLUMNS FROM students LIKE 'enrollment_type'")->fetch();
                    if (!$colEnrollment) {
                        $conn->exec("ALTER TABLE students ADD COLUMN enrollment_type VARCHAR(50) DEFAULT 'Day' AFTER entry_mode");
                    }

                    // Generate system admission number like ADM-2026-0001
                    $admYear = $entry_year ?: date('Y');
                    $admPattern = 'ADM-' . $admYear . '-%';
                    $ac = $conn->prepare("SELECT COUNT(*) as cnt FROM students WHERE admission_number LIKE :pattern");
                    $ac->execute(['pattern' => $admPattern]);
                    $acnt = $ac->fetch()['cnt'] ?? 0;
                    $aseq = intval($acnt) + 1;
                    $admissionNumber = 'ADM-' . $admYear . '-' . str_pad($aseq, 4, '0', STR_PAD_LEFT);

                    // Generate portal username
                    $ucheck = new Auth();
                    $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0] ?? 'student')) ?: 'student';
                    $username = $base;
                    $suffix = 1;
                    while ($ucheck->usernameExists($username)) {
                        $username = $base . $suffix;
                        $suffix++;
                    }

                    // Generate temporary password
                    $tempPassword = Security::generatePassword(10);
                    $passwordHash = Security::hashPassword($tempPassword);

                    // Ensure require_password_change column exists
                    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'require_password_change'")->fetch();
                    if (!$col) {
                        $conn->exec("ALTER TABLE users ADD COLUMN require_password_change TINYINT(1) DEFAULT 0 AFTER account_locked_until");
                    }

                    // Create user (portal account)
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, status, require_password_change, created_at) VALUES (:username,:email,:password_hash,'student',:status, :require_change, NOW())");
                    $stmt->execute([
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => $passwordHash,
                        'status' => $account_status,
                        'require_change' => 1
                    ]);
                    $newUserId = $conn->lastInsertId();

                    // Insert student record
                    $sstmt = $conn->prepare("INSERT INTO students (user_id, student_id, admission_number, first_name, middle_name, last_name, date_of_birth, gender, phone, email, address, city, country, emergency_contact_name, emergency_contact_phone, guardian_name, guardian_relation, guardian_phone, guardian_email, program_id, level_year, entry_year, entry_semester_id, entry_mode, enrollment_type, status, created_at) VALUES (:user_id,:student_id,:admission_number,:first_name,:middle_name,:last_name,:dob,:gender,:phone,:email,:address,:city,:country,:emergency_name,:emergency_phone,:guardian_name,:guardian_relation,:guardian_phone,:guardian_email,:program_id,:level_year,:entry_year,:entry_semester_id,:entry_mode,:enrollment_type,:status,NOW())");
                    $sstmt->execute([
                        'user_id' => $newUserId,
                        'student_id' => $studentCode,
                        'admission_number' => $admissionNumber,
                        'first_name' => $first_name,
                        'middle_name' => $middle_name,
                        'last_name' => $last_name,
                        'dob' => $dob,
                        'gender' => $gender,
                        'phone' => $phone,
                        'email' => $email,
                        'address' => $address,
                        'city' => $city,
                        'country' => $country,
                        'emergency_name' => $emergency_name,
                        'emergency_phone' => $emergency_phone,
                        'guardian_name' => $guardian_name,
                        'guardian_relation' => $guardian_relation,
                        'guardian_phone' => $guardian_phone,
                        'guardian_email' => $guardian_email,
                        'program_id' => $program_id ?: null,
                        'level_year' => $level_year,
                        'entry_year' => $entry_year,
                        'entry_semester_id' => $entry_semester_id ?: null,
                        'entry_mode' => $entry_mode,
                        'enrollment_type' => $enrollment_type,
                        'status' => 'active'
                    ]);
                    $newStudentId = $conn->lastInsertId();

                    // Handle photo upload
                    if (!empty($_FILES['photo']['name'])) {
                        $uploadDir = UPLOAD_PATH . '/students/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                        $target = $uploadDir . $studentCode . '.' . $ext;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                            $rel = 'uploads/students/' . $studentCode . '.' . $ext;
                            $ust = $conn->prepare("UPDATE students SET photo = :photo WHERE id = :id");
                            $ust->execute(['photo' => $rel, 'id' => $newStudentId]);
                        }
                    }

                    $conn->commit();

                    // Attempt to send credentials via email
                    $mailSent = false;
                    $subject = APP_NAME . ' - Portal Credentials';
                    $message = "Hello {$first_name} {$last_name},\n\n" .
                               "Your student portal account has been created.\n" .
                               "Username: {$username}\n" .
                               "Temporary Password: {$tempPassword}\n\n" .
                               "Please log in at " . BASE_URL . "/views/student/login.php and change your password on first login.\n\n" .
                               "Regards,\n" . APP_NAME;
                    $headers = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>' . "\r\n" .
                               'Reply-To: ' . SMTP_FROM_EMAIL . "\r\n" .
                               'X-Mailer: PHP/' . phpversion();
                    try {
                        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $mailSent = mail($email, $subject, $message, $headers);
                        }
                    } catch (Exception $e) {
                        // ignore mail errors
                    }

                    // Store credentials in session for admin to view
                    $_SESSION['new_user_credentials'] = [
                        'student_name' => $first_name . ' ' . $last_name,
                        'student_id' => $studentCode,
                        'admission_number' => $admissionNumber,
                        'username' => $username,
                        'password' => $tempPassword,
                        'email' => $email,
                        'program' => $programName ?: 'Not selected',
                        'level' => $level_year,
                        'mail_sent' => $mailSent
                    ];
                    $_SESSION['new_user_type'] = 'student';

                    $session->setFlash('success', 'Student account created successfully! Redirecting to credentials page...');
                    header('Location: ../credentials.php');
                    exit;
                } catch (Exception $e) {
                    if ($transactionStarted && $conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $errors[] = 'Error creating student: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get data from session for form population
$formData = $_SESSION['add_student_data'] ?? [];

// Resolve program info
$program_id = intval($formData['program_id'] ?? 0);
$level_year = intval($formData['level_year'] ?? 1);
$programName = '';
if ($program_id && !empty($programs)) {
    foreach ($programs as $p) {
        if (intval($p['id']) === $program_id) { 
            $programName = $p['program_name']; 
            break; 
        }
    }
}

$pageTitle = 'Add Student - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left"><h4>Add Student</h4></div>
        <div class="topbar-right d-flex align-items-center">
            <a href="list.php" class="btn btn-secondary mr-2">Back to list</a>
            <!-- Change Password Dropdown -->
            <div class="dropdown">
                <button class="btn btn-warning dropdown-toggle" type="button" id="changepasswordToggle-admin" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-key"></i> Change Password
                </button>
                <div class="dropdown-menu dropdown-menu-right p-3" aria-labelledby="changepasswordToggle-admin" style="min-width:300px;">
                    <form id="changePasswordForm-admin">
                        <div class="form-group">
                            <label for="currentPassword-admin">Current Password</label>
                            <input type="password" class="form-control" id="currentPassword-admin" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label for="newPassword-admin">New Password</label>
                            <input type="password" class="form-control" id="newPassword-admin" name="new_password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword-admin">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirmPassword-admin" name="confirm_password" required>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                        <button type="submit" class="btn btn-primary btn-block">Change Password</button>
                        <div id="changePasswordMsg-admin" class="mt-2"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo $session->getFlash('success'); // Already sanitized HTML ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach($errors as $err) echo '<div>' . e($err) . '</div>'; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <!-- Progress Indicator -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between">
                        <div class="text-center flex-fill <?php echo $step >= 1 ? 'text-primary font-weight-bold' : 'text-muted'; ?>">
                            <div class="mb-2"><i class="fas fa-user fa-2x"></i></div>
                            <div>Step 1: Identity</div>
                        </div>
                        <div class="text-center flex-fill <?php echo $step >= 2 ? 'text-primary font-weight-bold' : 'text-muted'; ?>">
                            <div class="mb-2"><i class="fas fa-calendar fa-2x"></i></div>
                            <div>Step 2: Session</div>
                        </div>
                        <div class="text-center flex-fill <?php echo $step >= 3 ? 'text-primary font-weight-bold' : 'text-muted'; ?>">
                            <div class="mb-2"><i class="fas fa-check-circle fa-2x"></i></div>
                            <div>Step 3: Review</div>
                        </div>
                    </div>
                    <hr>
                </div>

                <form method="POST" action="" enctype="multipart/form-data" id="studentForm">
                    <input type="hidden" name="step" id="stepInput" value="<?php echo $step; ?>">

                    <?php if ($step == 1): ?>
                        <h5 class="mb-3">Step 1: Core Identity</h5>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" required value="<?php echo e($formData['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" value="<?php echo e($formData['middle_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" required value="<?php echo e($formData['last_name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" name="date_of_birth" class="form-control" required value="<?php echo e($formData['date_of_birth'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>Gender <span class="text-danger">*</span></label>
                                <select name="gender" class="form-control" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo ($formData['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($formData['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo ($formData['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Photo</label>
                                <input type="file" name="photo" accept="image/*" class="form-control-file">
                            </div>
                            <div class="form-group col-md-3">
                                <label>Program</label>
                                <select name="program_id" class="form-control">
                                    <option value="">Select program</option>
                                    <?php foreach($programs as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo ($formData['program_id'] ?? '') == $p['id'] ? 'selected' : ''; ?>><?php echo e($p['program_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Entry Year</label>
                                <input type="number" name="entry_year" class="form-control" value="<?php echo e($formData['entry_year'] ?? date('Y')); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Entry Semester</label>
                                <select name="entry_semester_id" class="form-control">
                                    <option value="">Select semester (optional)</option>
                                    <?php foreach($semesters as $sem): ?>
                                        <option value="<?php echo $sem['id']; ?>" <?php echo ($formData['entry_semester_id'] ?? '') == $sem['id'] ? 'selected' : ''; ?>><?php echo e($sem['year_name'] . ' - ' . $sem['semester_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-5">
                                <label>Entry Mode</label>
                                <select name="entry_mode" class="form-control">
                                    <option value="Entry Papers" <?php echo ($formData['entry_mode'] ?? 'Entry Papers') === 'Entry Papers' ? 'selected' : ''; ?>>Entry Papers</option>
                                    <option value="Direct Entry" <?php echo ($formData['entry_mode'] ?? '') === 'Direct Entry' ? 'selected' : ''; ?>>Direct Entry</option>
                                    <option value="Transfer" <?php echo ($formData['entry_mode'] ?? '') === 'Transfer' ? 'selected' : ''; ?>>Transfer</option>
                                    <option value="Mature Entry" <?php echo ($formData['entry_mode'] ?? '') === 'Mature Entry' ? 'selected' : ''; ?>>Mature Entry</option>
                                    <option value="Special Consideration" <?php echo ($formData['entry_mode'] ?? '') === 'Special Consideration' ? 'selected' : ''; ?>>Special Consideration</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row mt-2">
                            <div class="form-group col-md-4">
                                <label>Emergency Contact Name</label>
                                <input type="text" name="emergency_name" class="form-control" value="<?php echo e($formData['emergency_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Emergency Contact Phone</label>
                                <input type="text" name="emergency_phone" class="form-control" value="<?php echo e($formData['emergency_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Guardian Name</label>
                                <input type="text" name="guardian_name" class="form-control" value="<?php echo e($formData['guardian_name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Guardian Relation</label>
                                <input type="text" name="guardian_relation" class="form-control" value="<?php echo e($formData['guardian_relation'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Guardian Phone</label>
                                <input type="text" name="guardian_phone" class="form-control" value="<?php echo e($formData['guardian_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Guardian Email</label>
                                <input type="email" name="guardian_email" class="form-control" value="<?php echo e($formData['guardian_email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Level / Year</label>
                                <select name="level_year" class="form-control">
                                    <option value="1" <?php echo ($formData['level_year'] ?? 1) == 1 ? 'selected' : ''; ?>>Year 1</option>
                                    <option value="2" <?php echo ($formData['level_year'] ?? 1) == 2 ? 'selected' : ''; ?>>Year 2</option>
                                    <option value="3" <?php echo ($formData['level_year'] ?? 1) == 3 ? 'selected' : ''; ?>>Year 3</option>
                                    <option value="4" <?php echo ($formData['level_year'] ?? 1) == 4 ? 'selected' : ''; ?>>Year 4</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo e($formData['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-6">
                                <label>Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" required value="<?php echo e($formData['email'] ?? ''); ?>">
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle"></i> Enter student's personal email. Login credentials will be sent to this email.
                                </small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Address</label>
                                <input type="text" name="address" class="form-control" value="<?php echo e($formData['address'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>City</label>
                                <input type="text" name="city" class="form-control" value="<?php echo e($formData['city'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Country</label>
                                <input type="text" name="country" class="form-control" value="<?php echo e($formData['country'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="text-right mt-3">
                            <button type="submit" class="btn btn-primary" onclick="document.getElementById('stepInput').value=2">
                                Next <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>

                    <?php elseif ($step == 2): ?>
                        <h5 class="mb-3">Step 2: Session & Enrollment</h5>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Enrollment Type / Session</label>
                                <select name="enrollment_type" class="form-control">
                                    <option value="Day" <?php echo ($formData['enrollment_type'] ?? 'Day') === 'Day' ? 'selected' : ''; ?>>Day</option>
                                    <option value="Evening" <?php echo ($formData['enrollment_type'] ?? '') === 'Evening' ? 'selected' : ''; ?>>Evening</option>
                                    <option value="Weekend" <?php echo ($formData['enrollment_type'] ?? '') === 'Weekend' ? 'selected' : ''; ?>>Weekend</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label>Program / Level (Review)</label>
                                <div class="form-control-plaintext">
                                    <strong><?php echo e($programName ?: 'Not selected'); ?></strong> - Year <?php echo e($level_year); ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-right mt-3">
                            <button type="submit" class="btn btn-secondary" onclick="document.getElementById('stepInput').value=1">
                                <i class="fas fa-arrow-left mr-1"></i> Back
                            </button>
                            <button type="submit" class="btn btn-primary" onclick="document.getElementById('stepInput').value=3">
                                Next <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>

                    <?php else: ?>
                        <h5 class="mb-3">Step 3: Review & Create</h5>
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6>Student Information Summary:</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Name:</strong> <?php echo e(($formData['first_name'] ?? '') . ' ' . ($formData['middle_name'] ?? '') . ' ' . ($formData['last_name'] ?? '')); ?></p>
                                        <p><strong>Email:</strong> <?php echo e($formData['email'] ?? ''); ?></p>
                                        <p><strong>Phone:</strong> <?php echo e($formData['phone'] ?? 'N/A'); ?></p>
                                        <p><strong>Date of Birth:</strong> <?php echo e($formData['date_of_birth'] ?? 'N/A'); ?></p>
                                        <p><strong>Gender:</strong> <?php echo e($formData['gender'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Program:</strong> <?php echo e($programName ?: 'Not selected'); ?></p>
                                        <p><strong>Level/Year:</strong> Year <?php echo e($level_year); ?></p>
                                        <p><strong>Entry Year:</strong> <?php echo e($formData['entry_year'] ?? date('Y')); ?></p>
                                        <p><strong>Entry Mode:</strong> <?php echo e($formData['entry_mode'] ?? 'Entry Papers'); ?></p>
                                        <p><strong>Enrollment Type:</strong> <?php echo e($formData['enrollment_type'] ?? 'Day'); ?></p>
                                    </div>
                                </div>
                                <hr>
                                <p class="mb-0"><small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Portal credentials (username and temporary password) will be automatically generated and can be emailed to the student.
                                </small></p>
                            </div>
                        </div>
                        <div class="text-right mt-3">
                            <button type="submit" class="btn btn-secondary" onclick="document.getElementById('stepInput').value=2">
                                <i class="fas fa-arrow-left mr-1"></i> Back
                            </button>
                            <button type="submit" name="action" value="complete" class="btn btn-success">
                                <i class="fas fa-check mr-1"></i> Create Student
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
<script>
// Change Password AJAX for admin
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('changePasswordForm-admin');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var msg = document.getElementById('changePasswordMsg-admin');
            msg.innerHTML = '';
            var formData = new FormData(form);
            fetch('<?php echo BASE_URL; ?>/views/admin/change-password.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    msg.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
                    form.reset();
                } else {
                    msg.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Password change failed.') + '</div>';
                }
            })
            .catch(() => {
                msg.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
            });
        });
    }
});
</script>