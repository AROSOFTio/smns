<?php
/**
 * Admin - Add Lecturer
 * Complete lecturer registration with identity, professional, and authentication fields
 */
require_once dirname(__DIR__, 3) . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize inputs
    $title = Security::sanitize($_POST['title'] ?? '');
    $first_name = Security::sanitize($_POST['first_name'] ?? '');
    $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
    $last_name = Security::sanitize($_POST['last_name'] ?? '');
    $gender = Security::sanitize($_POST['gender'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $national_id = Security::sanitize($_POST['national_id'] ?? '');
    $phone = Security::sanitize($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $office_location = Security::sanitize($_POST['office_location'] ?? '');
    $department = Security::sanitize($_POST['department'] ?? '');
    $designation = Security::sanitize($_POST['designation'] ?? '');
    $specialization = Security::sanitize($_POST['specialization'] ?? '');
    $qualifications = Security::sanitize($_POST['qualification'] ?? '');
    $employment_type = Security::sanitize($_POST['employment_type'] ?? 'Full-time');
    $employment_date = $_POST['employment_date'] ?? date('Y-m-d');
    $account_status = 'active';
    
    // Validate required fields
    $missing = [];
    if (empty($first_name)) $missing[] = 'First Name';
    if (empty($last_name)) $missing[] = 'Last Name';
    if (empty($gender)) $missing[] = 'Gender';
    if (empty($email)) $missing[] = 'Email';
    if (empty($designation)) $missing[] = 'Designation';
    if (empty($department)) $missing[] = 'Department';
    
    if ($missing) {
        $errors[] = 'Please fill in required fields: ' . implode(', ', $missing);
    }
    
    // Validate email
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Check if email already exists
    if (!empty($email)) {
        $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = :email");
        $emailCheck->execute(['email' => $email]);
        if ($emailCheck->fetch()) {
            $errors[] = 'This email is already registered in the system';
        }
    }
    
    if (empty($errors)) {
        try {
            // Generate unique lecturer ID (LEC-2025-001)
            $year = date('Y');
            $pattern = 'LEC-' . $year . '-%';
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM lecturers WHERE lecturer_id LIKE :pattern");
            $stmt->execute(['pattern' => $pattern]);
            $count = $stmt->fetch()['cnt'] ?? 0;
            $sequence = intval($count) + 1;
            $lecturerId = 'LEC-' . $year . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
            
            // Generate username from email
            $ucheck = new Auth();
            $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0] ?? 'lecturer')) ?: 'lecturer';
            $username = $base;
            $suffix = 1;
            while ($ucheck->usernameExists($username)) {
                $username = $base . $suffix;
                $suffix++;
            }
            
            // Generate temporary password
            $tempPassword = Security::generatePassword(10);
            $passwordHash = Security::hashPassword($tempPassword);
            
            // Create user account
            $userStmt = $conn->prepare("
                INSERT INTO users (username, email, password_hash, role, status, require_password_change, created_at) 
                VALUES (:username, :email, :password_hash, :role, :status, :require_password_change, NOW())
            ");
            $userStmt->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => 'lecturer',
                'status' => $account_status,
                'require_password_change' => 1
            ]);
            $userId = $conn->lastInsertId();
            
            // Insert lecturer record
            $lecturerStmt = $conn->prepare("
                INSERT INTO lecturers (
                    user_id, lecturer_id, title, first_name, middle_name, last_name, 
                    gender, date_of_birth, national_id, phone, email, office_location,
                    department, designation, specialization, qualifications,
                    employment_type, employment_date, status, created_at
                ) VALUES (
                    :user_id, :lecturer_id, :title, :first_name, :middle_name, :last_name,
                    :gender, :dob, :national_id, :phone, :email, :office_location,
                    :department, :designation, :specialization, :qualifications,
                    :employment_type, :employment_date, :status, NOW()
                )
            ");
            
            $lecturerStmt->execute([
                'user_id' => $userId,
                'lecturer_id' => $lecturerId,
                'title' => $title,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'gender' => $gender,
                'dob' => $date_of_birth ?: null,
                'national_id' => $national_id,
                'phone' => $phone,
                'email' => $email,
                'office_location' => $office_location,
                'department' => $department,
                'designation' => $designation,
                'specialization' => $specialization,
                'qualifications' => $qualifications,
                'employment_type' => $employment_type,
                'employment_date' => $employment_date ?: null,
                'status' => 'active'
            ]);
            
            $newLecturerId = $conn->lastInsertId();
            
            // Handle photo upload
            if (!empty($_FILES['photo']['name'])) {
                $uploadDir = UPLOAD_PATH . '/lecturers/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $target = $uploadDir . $lecturerId . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    $rel = 'uploads/lecturers/' . $lecturerId . '.' . $ext;
                    $photoStmt = $conn->prepare("UPDATE lecturers SET photo = :photo WHERE id = :id");
                    $photoStmt->execute(['photo' => $rel, 'id' => $newLecturerId]);
                }
            }
            
            // Attempt to send credentials via email
            $mailSent = false;
            $fullName = trim($title . ' ' . $first_name . ' ' . $last_name);
            $subject = APP_NAME . ' - Lecturer Portal Credentials';
            $message = "Dear {$fullName},\n\n" .
                       "Your lecturer portal account has been created.\n\n" .
                       "Lecturer ID: {$lecturerId}\n" .
                       "Username: {$username}\n" .
                       "Temporary Password: {$tempPassword}\n\n" .
                       "Portal URL: " . BASE_URL . "/views/lecturer/login.php\n\n" .
                       "You will be required to change your password on first login.\n\n" .
                       "Regards,\n" . APP_NAME;
            $headers = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>';
            
            try {
                $mailSent = @mail($email, $subject, $message, $headers);
            } catch (Exception $e) {
                error_log("Failed to send lecturer credentials email: " . $e->getMessage());
            }
            
            // Set success message with credentials
            $successMsg = "<strong>Lecturer added successfully!</strong><br><br>";
            $successMsg .= "<div style='background:#e7f3ff;padding:15px;border-radius:5px;border-left:4px solid #007bff;'>";
            $successMsg .= "<strong>Lecturer ID:</strong> <code>{$lecturerId}</code><br>";
            $successMsg .= "<strong>Username:</strong> <code>{$username}</code><br>";
            $successMsg .= "<strong>Temporary Password:</strong> <code>{$tempPassword}</code><br>";
            $successMsg .= "<strong>Portal:</strong> <a href='" . BASE_URL . "/views/lecturer/login.php'>Lecturer Login</a>";
            $successMsg .= "</div>";
            if ($mailSent) {
                $successMsg .= "<div style='color:#28a745;margin-top:10px;'><i class='fas fa-check-circle'></i> Credentials emailed to lecturer</div>";
            } else {
                $successMsg .= "<div style='color:#ffc107;margin-top:10px;'><i class='fas fa-exclamation-triangle'></i> Please communicate these credentials to the lecturer</div>";
            }
            
            $session->setFlash('success', $successMsg);
            header('Location: list.php');
            exit;
            
        } catch (Exception $e) {
            $errors[] = 'Error creating lecturer: ' . $e->getMessage();
        }
    }
}

$db = new Database();
$conn = $db->getConnection();

// Notifications (admin sees all system notifications)
$stmt = $conn->prepare("SELECT * FROM notifications WHERE read_status = 'unread' ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'Add Lecturer - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<style>
/* AGGRESSIVE HORIZONTAL SCROLL PREVENTION */
* {
    box-sizing: border-box !important;
}

html {
    overflow-x: hidden !important;
    width: 100% !important;
}

body {
    overflow-x: hidden !important;
    width: 100% !important;
    margin: 0 !important;
}

.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.content-area {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.container, .container-fluid {
    overflow-x: hidden !important;
    max-width: 100% !important;
}

/* Form fixes */
form {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.form-row {
    margin-left: 0 !important;
    margin-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

.form-row > [class*="col-"] {
    padding-left: 7.5px !important;
    padding-right: 7.5px !important;
}

.form-control, .form-control-file, input, select, textarea {
    max-width: 100% !important;
}

/* Card fixes */
.card {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.card-header, .card-body, .card-footer {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* Alert and other components */
.alert {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    word-wrap: break-word !important;
}

/* Topbar fixes */
.topbar {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    flex-wrap: wrap !important;
}

.btn, .btn-group {
    white-space: nowrap !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .form-row > [class*="col-"] {
        padding-left: 5px !important;
        padding-right: 5px !important;
    }
    
    .topbar-left h4 {
        font-size: 1rem !important;
    }
    
    .btn {
        font-size: 0.8rem !important;
        padding: 0.25rem 0.5rem !important;
    }
    
    .card-header h5 {
        font-size: 1rem !important;
    }
}

@media (max-width: 576px) {
    .form-row > [class*="col-"] {
        padding-left: 3px !important;
        padding-right: 3px !important;
    }
}
</style>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="topbar-left"><h4>Add New Lecturer</h4></div>
        <div class="topbar-right d-flex align-items-center">
            <a href="list.php" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> <span class="d-none d-md-inline">Back to List</span>
            </a>
            <?php include dirname(__DIR__, 3) . '/includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            
            <!-- 1. Identity & Personal Information -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-id-card"></i> 1. Identity & Personal Information</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-2 col-sm-6">
                            <label>Title</label>
                            <select name="title" class="form-control">
                                <option value="">Select</option>
                                <option value="Mr">Mr</option>
                                <option value="Mrs">Mrs</option>
                                <option value="Ms">Ms</option>
                                <option value="Dr">Dr</option>
                                <option value="Prof">Prof</option>
                                <option value="Rev">Rev</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                        <div class="form-group col-md-4 col-sm-6">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group col-md-3 col-sm-6">
                            <label>Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-control" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control">
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>National ID/Passport</label>
                            <input type="text" name="national_id" class="form-control" placeholder="e.g., CM12345678">
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>Photo</label>
                            <input type="file" name="photo" class="form-control-file" accept="image/*">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Contact Details -->
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-address-book"></i> 2. Contact Details</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4 col-sm-12">
                            <label>Institutional Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required placeholder="lecturer@institution.edu">
                            <small class="text-muted">This will be used for login</small>
                        </div>
                        <div class="form-group col-md-4 col-sm-6">
                            <label>Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="+256 700 000 000">
                        </div>
                        <div class="form-group col-md-4 col-sm-6">
                            <label>Office Location</label>
                            <input type="text" name="office_location" class="form-control" placeholder="e.g., Block A, Room 201">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Professional Details -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-briefcase"></i> 3. Professional Details</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4 col-sm-12">
                            <label>Department/Faculty <span class="text-danger">*</span></label>
                            <input type="text" name="department" class="form-control" required placeholder="e.g., Theology, Computer Science">
                        </div>
                        <div class="form-group col-md-4 col-sm-6">
                            <label>Designation <span class="text-danger">*</span></label>
                            <select name="designation" class="form-control" required>
                                <option value="">Select Designation</option>
                                <option value="Teaching Assistant">Teaching Assistant</option>
                                <option value="Assistant Lecturer">Assistant Lecturer</option>
                                <option value="Lecturer">Lecturer</option>
                                <option value="Senior Lecturer">Senior Lecturer</option>
                                <option value="Associate Professor">Associate Professor</option>
                                <option value="Professor">Professor</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4 col-sm-6">
                            <label>Qualification</label>
                            <select name="qualification" class="form-control">
                                <option value="">Select Qualification</option>
                                <option value="Bachelor's Degree">Bachelor's Degree</option>
                                <option value="Master's Degree">Master's Degree</option>
                                <option value="PhD">PhD</option>
                                <option value="Post-Doctorate">Post-Doctorate</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group col-md-6 col-sm-12">
                            <label>Area of Specialization</label>
                            <input type="text" name="specialization" class="form-control" placeholder="e.g., Computer Networks, Theology, etc.">
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>Employment Type</label>
                            <select name="employment_type" class="form-control">
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Visiting">Visiting</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3 col-sm-6">
                            <label>Employment Date</label>
                            <input type="date" name="employment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Account & Security Notice -->
            <div class="card mb-3">
                <div class="card-header bg-warning">
                    <h5 class="mb-0"><i class="fas fa-shield-alt"></i> 4. Portal Access & Security</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> Automatic Account Creation</h6>
                        <ul class="mb-0">
                            <li>A unique <strong>Lecturer ID</strong> will be generated automatically (e.g., LEC-2025-001)</li>
                            <li><strong>Username</strong> will be created from the email address</li>
                            <li>A secure <strong>temporary password</strong> will be generated</li>
                            <li>Lecturer will be required to <strong>change password on first login</strong></li>
                            <li>Account will be created with <strong>"Lecturer" role</strong> with appropriate permissions</li>
                            <li>Credentials will be sent to the institutional email address provided</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="text-right">
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Create Lecturer Account
                </button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>