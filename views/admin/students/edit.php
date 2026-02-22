<?php
/**
 * Edit Student - Admin
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get student ID from URL
$studentId = $_GET['id'] ?? null;
if (!$studentId) {
    header('Location: list.php?error=invalid_id');
    exit;
}

// Get student details
$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT s.*, p.program_code, p.program_name, u.username, u.email as user_email, u.status as user_status, u.created_at as user_created_at, u.last_login, u.failed_login_attempts as login_attempts
        FROM students s
        INNER JOIN programs p ON s.program_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.id = :id
    ");
    $stmt->execute(['id' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        header('Location: list.php?error=student_not_found');
        exit;
    }
} catch (Exception $e) {
    header('Location: list.php?error=database_error');
    exit;
}

// Get programs for dropdown
$programs = [];
try {
    $stmt = $conn->query("SELECT id, program_code, program_name FROM programs ORDER BY program_name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $programs = [];
}

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
    $address = Security::sanitize($_POST['address'] ?? '');
    $emergency_contact_name = Security::sanitize($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = Security::sanitize($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relationship = Security::sanitize($_POST['emergency_contact_relationship'] ?? '');
    $program_id = (int)($_POST['program_id'] ?? 0);
    $level_year = (int)($_POST['level_year'] ?? 1);
    $specialization = Security::sanitize($_POST['specialization'] ?? '');
    $qualifications = Security::sanitize($_POST['qualifications'] ?? '');
    $status = Security::sanitize($_POST['status'] ?? 'active');

    // Validate required fields
    $missing = [];
    if (empty($first_name)) $missing[] = 'First Name';
    if (empty($last_name)) $missing[] = 'Last Name';
    if (empty($gender)) $missing[] = 'Gender';
    if (empty($email)) $missing[] = 'Email';
    if (empty($phone)) $missing[] = 'Phone';
    if (!$program_id) $missing[] = 'Program';

    if (!empty($missing)) {
        $errors[] = 'Required fields missing: ' . implode(', ', $missing);
    }

    // Validate email format
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }

    // Check if email is unique (excluding current student)
    if (!empty($email)) {
        try {
            $stmt = $conn->prepare("SELECT id FROM students WHERE email = :email AND id != :id");
            $stmt->execute(['email' => $email, 'id' => $studentId]);
            if ($stmt->fetch()) {
                $errors[] = 'Email address already exists';
            }
        } catch (Exception $e) {
            $errors[] = 'Database error checking email uniqueness';
        }
    }

    // Ensure email is unique in users table as well
    if (!empty($email)) {
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
            $stmt->execute(['email' => $email, 'id' => $student['user_id']]);
            if ($stmt->fetch()) {
                $errors[] = 'Email address is already used by another account';
            }
        } catch (Exception $e) {
            $errors[] = 'Database error checking user email uniqueness';
        }
    }

    // Handle photo upload
    $photo_path = $student['photo']; // Keep existing photo by default
    if (!empty($_FILES['photo']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if (!in_array($_FILES['photo']['type'], $allowed_types)) {
            $errors[] = 'Invalid photo format. Only JPG, PNG, and GIF are allowed.';
        } elseif ($_FILES['photo']['size'] > $max_size) {
            $errors[] = 'Photo size too large. Maximum size is 2MB.';
        } else {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = 'student_' . $studentId . '_' . time() . '.' . $ext;
            $uploadDir = '../../../uploads/students/';
            $target = $uploadDir . $filename;

            // Create directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                $photo_path = 'uploads/students/' . $filename;

                // Delete old photo if it exists
                if (!empty($student['photo']) && file_exists('../../../' . $student['photo'])) {
                    unlink('../../../' . $student['photo']);
                }
            } else {
                $errors[] = 'Failed to upload photo';
            }
        }
    }

    // If no errors, update student
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            $previousEmail = trim((string)($student['user_email'] ?? $student['email'] ?? ''));
            $emailChanged = strcasecmp($previousEmail, $email) !== 0;
            $generatedPassword = '';
            $passwordHash = '';
            if ($emailChanged) {
                $generatedPassword = Security::generatePassword(10);
                $passwordHash = Security::hashPassword($generatedPassword);
            }

            // Update student table
            $stmt = $conn->prepare("
                UPDATE students SET
                    title = :title,
                    first_name = :first_name,
                    middle_name = :middle_name,
                    last_name = :last_name,
                    gender = :gender,
                    date_of_birth = :date_of_birth,
                    national_id = :national_id,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    emergency_contact_relationship = :emergency_contact_relationship,
                    program_id = :program_id,
                    level_year = :level_year,
                    specialization = :specialization,
                    qualifications = :qualifications,
                    photo = :photo,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'title' => $title,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'gender' => $gender,
                'date_of_birth' => $date_of_birth,
                'national_id' => $national_id,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'emergency_contact_name' => $emergency_contact_name,
                'emergency_contact_phone' => $emergency_contact_phone,
                'emergency_contact_relationship' => $emergency_contact_relationship,
                'program_id' => $program_id,
                'level_year' => $level_year,
                'specialization' => $specialization,
                'qualifications' => $qualifications,
                'photo' => $photo_path,
                'status' => $status,
                'id' => $studentId
            ]);

            // Keep users.email in sync and optionally rotate password when email is corrected.
            $userUpdateSql = "UPDATE users SET email = :email";
            $userParams = [
                'email' => $email,
                'user_id' => $student['user_id']
            ];
            if ($status !== $student['status']) {
                $userUpdateSql .= ", status = :status";
                $userParams['status'] = $status;
            }
            if ($emailChanged) {
                $userUpdateSql .= ", password_hash = :password_hash, require_password_change = 1";
                $userParams['password_hash'] = $passwordHash;
            }
            $userUpdateSql .= " WHERE id = :user_id";
            $stmt = $conn->prepare($userUpdateSql);
            $stmt->execute($userParams);

            $conn->commit();
            $successMessage = 'Student updated successfully';

            if ($emailChanged) {
                $mailSent = false;
                try {
                    $mailSent = Helper::sendTemplatedEmail('password_reset', $email, [
                        'recipient_name' => trim(($first_name ?: ($student['first_name'] ?? '')) . ' ' . ($last_name ?: ($student['last_name'] ?? ''))),
                        'username' => $student['username'],
                        'temporary_password' => $generatedPassword,
                        'login_url' => BASE_URL . '/views/student/login.php'
                    ]);
                } catch (Exception $mailEx) {
                    $mailSent = false;
                }

                if ($mailSent) {
                    $successMessage .= '. Email updated and new login credentials were sent to the new address.';
                } else {
                    $successMessage .= '. Email updated, but credential email failed. Share this temporary password manually: ' . $generatedPassword;
                }
            }

            $session->setFlash('success', $successMessage);
            header('Location: view.php?id=' . $studentId);
            exit;

        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Failed to update student: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Student - ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="../../../assets/css/responsive-nav.css">
</head>
<body>
<style>
/* Custom styles for student edit */
.current-photo {
    max-width: 120px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Edit Student</h4>
        </div>
        <div class="topbar-right">
            <a href="view.php?id=<?php echo $student['id']; ?>" class="btn btn-info mr-2">
                <i class="fas fa-eye"></i> View Details
            </a>
            <a href="list.php" class="btn btn-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php include '../../../includes/notification_bell.php'; ?>
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

            <!-- Personal Information -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-user"></i> Personal Information</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-2">
                            <label>Title</label>
                            <select name="title" class="form-control">
                                <option value="">Select</option>
                                <option value="Mr" <?php echo $student['title'] === 'Mr' ? 'selected' : ''; ?>>Mr</option>
                                <option value="Mrs" <?php echo $student['title'] === 'Mrs' ? 'selected' : ''; ?>>Mrs</option>
                                <option value="Ms" <?php echo $student['title'] === 'Ms' ? 'selected' : ''; ?>>Ms</option>
                                <option value="Dr" <?php echo $student['title'] === 'Dr' ? 'selected' : ''; ?>>Dr</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo e($student['first_name']); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" value="<?php echo e($student['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo e($student['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-control" required>
                                <option value="">Select Gender</option>
                                <option value="male" <?php echo $student['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $student['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" value="<?php echo e($student['date_of_birth'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>National ID</label>
                            <input type="text" name="national_id" class="form-control" value="<?php echo e($student['national_id'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Phone <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo e($student['phone']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?php echo e($student['email']); ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Emergency Contact Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo e($student['emergency_contact_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Emergency Contact Phone</label>
                            <input type="tel" name="emergency_contact_phone" class="form-control" value="<?php echo e($student['emergency_contact_phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Emergency Contact Relationship</label>
                            <input type="text" name="emergency_contact_relationship" class="form-control" value="<?php echo e($student['emergency_contact_relationship'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" class="form-control" rows="2"><?php echo e($student['address'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Academic Information -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Academic Information</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Program <span class="text-danger">*</span></label>
                            <select name="program_id" class="form-control" required>
                                <option value="">Select Program</option>
                                <?php foreach($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>" <?php echo $student['program_id'] == $program['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($program['program_code'] . ' - ' . $program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>Level/Year</label>
                            <select name="level_year" class="form-control">
                                <?php for($i = 1; $i <= 4; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $student['level_year'] == $i ? 'selected' : ''; ?>>Year <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Specialization</label>
                            <input type="text" name="specialization" class="form-control" value="<?php echo e($student['specialization'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo $student['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $student['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="suspended" <?php echo $student['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="graduated" <?php echo $student['status'] === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Qualifications</label>
                        <textarea name="qualifications" class="form-control" rows="2"><?php echo e($student['qualifications'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Photo Upload -->
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-camera"></i> Profile Photo</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Current Photo</label>
                            <div>
                                <?php if (!empty($student['photo'])): ?>
                                    <img src="<?php echo BASE_URL . '/' . $student['photo']; ?>" alt="Current Photo" class="current-photo">
                                <?php else: ?>
                                    <div class="current-photo-placeholder rounded d-inline-flex align-items-center justify-content-center bg-secondary text-white" style="width: 120px; height: 120px; font-size: 2rem; font-weight: bold;">
                                        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Upload New Photo</label>
                            <input type="file" name="photo" class="form-control-file" accept="image/*">
                            <small class="form-text text-muted">Leave empty to keep current photo. Max size: 2MB. Formats: JPG, PNG, GIF</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="form-group text-center">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Update Student
                </button>
                <a href="view.php?id=<?php echo $student['id']; ?>" class="btn btn-secondary btn-lg ml-2">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
</body>
</html>
