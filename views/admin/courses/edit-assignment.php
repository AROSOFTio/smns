<?php
/**
 * Edit Course Assignment - Admin
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$assignmentId = (int)($_GET['id'] ?? 0);

if (!$assignmentId) {
    header('Location: assigned.php?error=invalid_assignment');
    exit;
}

// Fetch assignment details
$stmt = $conn->prepare("
    SELECT ca.*, c.course_code, c.course_name, c.program_id, p.program_name, p.program_code
    FROM course_assignments ca
    INNER JOIN courses c ON ca.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    WHERE ca.id = :id
");
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    header('Location: assigned.php?error=assignment_not_found');
    exit;
}

// Fetch active lecturers
$stmt = $conn->query("
    SELECT l.id, l.lecturer_id, l.first_name, l.last_name, l.email, l.specialization
    FROM lecturers l
    WHERE l.status = 'active'
    ORDER BY l.first_name, l.last_name
");
$lecturers = $stmt->fetchAll();

// Fetch active semesters
$stmt = $conn->query("
    SELECT s.id, s.semester_name, s.semester_number, ay.year_name
    FROM semesters s
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE s.status = 'active' AND ay.status = 'active'
    ORDER BY ay.year_name DESC, s.semester_number
");
$semesters = $stmt->fetchAll();

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lecturer_id = (int)($_POST['lecturer_id'] ?? 0);
    $semester_id = (int)($_POST['semester_id'] ?? 0);
    $assigned_date = $_POST['assigned_date'] ?? '';
    $status = Security::sanitize($_POST['status'] ?? 'active');

    // Validate required fields
    $missing = [];
    if (empty($lecturer_id)) $missing[] = 'Lecturer';
    if (empty($semester_id)) $missing[] = 'Semester';
    if (empty($assigned_date)) $missing[] = 'Assignment Date';

    if ($missing) {
        $errors[] = 'Please fill in required fields: ' . implode(', ', $missing);
    }

    // Check if assignment already exists (excluding current assignment)
    if (!empty($lecturer_id) && !empty($semester_id)) {
        $checkStmt = $conn->prepare("
            SELECT id FROM course_assignments
            WHERE lecturer_id = :lecturer_id AND course_id = :course_id AND semester_id = :semester_id AND id != :id
        ");
        $checkStmt->execute([
            'lecturer_id' => $lecturer_id,
            'course_id' => $assignment['course_id'],
            'semester_id' => $semester_id,
            'id' => $assignmentId
        ]);

        if ($checkStmt->fetch()) {
            $errors[] = 'This course is already assigned to the selected lecturer for this semester.';
        }
    }

    if (empty($errors)) {
        try {
            // Update assignment
            $stmt = $conn->prepare("
                UPDATE course_assignments SET
                    lecturer_id = :lecturer_id,
                    semester_id = :semester_id,
                    assigned_date = :assigned_date,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'lecturer_id' => $lecturer_id,
                'semester_id' => $semester_id,
                'assigned_date' => $assigned_date,
                'status' => $status,
                'id' => $assignmentId
            ]);

            $session->setFlash('success', 'Course assignment updated successfully!');
            header('Location: assigned.php');
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to update assignment: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Course Assignment - ' . APP_NAME;
include '../../../includes/header.php';
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

.form-control, input, select, textarea {
    max-width: 100% !important;
}

/* Card fixes */
.card {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.card-header, .card-body {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* Alert fixes */
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

.btn {
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
}

@media (max-width: 576px) {
    .form-row > [class*="col-"] {
        padding-left: 3px !important;
        padding-right: 3px !important;
    }
}
</style>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Edit Course Assignment</h4>
        </div>
        <div class="topbar-right">
            <a href="assigned.php" class="btn btn-secondary">← Back to Assignments</a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Course Information -->
        <div class="card mb-3">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-book"></i> Course Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Course Code:</strong> <?php echo e($assignment['course_code']); ?></p>
                        <p><strong>Course Name:</strong> <?php echo e($assignment['course_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Program:</strong> <?php echo e($assignment['program_code'] . ' - ' . $assignment['program_name']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="">

            <!-- Assignment Details -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-user-plus"></i> Assignment Details</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Lecturer <span class="text-danger">*</span></label>
                            <select name="lecturer_id" class="form-control" required>
                                <option value="">Select Lecturer</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?php echo $lecturer['id']; ?>"
                                            <?php echo (($_POST['lecturer_id'] ?? $assignment['lecturer_id']) == $lecturer['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($lecturer['first_name'] . ' ' . $lecturer['last_name'] . ' (' . $lecturer['lecturer_id'] . ')'); ?>
                                        <?php if ($lecturer['specialization']): ?>
                                            - <?php echo e($lecturer['specialization']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Semester <span class="text-danger">*</span></label>
                            <select name="semester_id" class="form-control" required>
                                <option value="">Select Semester</option>
                                <?php foreach ($semesters as $semester): ?>
                                    <option value="<?php echo $semester['id']; ?>"
                                            <?php echo (($_POST['semester_id'] ?? $assignment['semester_id']) == $semester['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($semester['year_name'] . ' - ' . $semester['semester_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Assignment Date <span class="text-danger">*</span></label>
                            <input type="date" name="assigned_date" class="form-control" required
                                   value="<?php echo e($_POST['assigned_date'] ?? $assignment['assigned_date']); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo (($_POST['status'] ?? $assignment['status']) == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo (($_POST['status'] ?? $assignment['status']) == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo (($_POST['status'] ?? $assignment['status']) == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-right">
                <a href="assigned.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">💾 Update Assignment</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>