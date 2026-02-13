<?php
/**
 * Admin - Assign Course to Lecturer
 * Assign a course to a lecturer for a specific semester
 */
require_once '../../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$errors = [];
$success = '';

// Get course ID from URL
$courseId = (int)($_GET['id'] ?? 0);

if (!$courseId) {
    header('Location: list.php?error=invalid_course');
    exit;
}

// Fetch course details
$stmt = $conn->prepare("
    SELECT c.*, p.program_name, p.program_code
    FROM courses c
    INNER JOIN programs p ON c.program_id = p.id
    WHERE c.id = :id
");
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: list.php?error=course_not_found');
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize inputs
    $lecturer_id = (int)($_POST['lecturer_id'] ?? 0);
    $semester_id = (int)($_POST['semester_id'] ?? 0);
    $assigned_date = $_POST['assigned_date'] ?? date('Y-m-d');
    $status = Security::sanitize($_POST['status'] ?? 'active');

    // Validate required fields
    $missing = [];
    if (empty($lecturer_id)) $missing[] = 'Lecturer';
    if (empty($semester_id)) $missing[] = 'Semester';

    if ($missing) {
        $errors[] = 'Please fill in required fields: ' . implode(', ', $missing);
    }

    // Check if assignment already exists
    if (!empty($lecturer_id) && !empty($semester_id)) {
        $checkStmt = $conn->prepare("
            SELECT id FROM course_assignments
            WHERE lecturer_id = :lecturer_id AND course_id = :course_id AND semester_id = :semester_id
        ");
        $checkStmt->execute([
            'lecturer_id' => $lecturer_id,
            'course_id' => $courseId,
            'semester_id' => $semester_id
        ]);

        if ($checkStmt->fetch()) {
            $errors[] = 'This course is already assigned to the selected lecturer for this semester.';
        }
    }

    if (empty($errors)) {
        try {
            // Insert course assignment
            $stmt = $conn->prepare("
                INSERT INTO course_assignments (
                    lecturer_id, course_id, semester_id, assigned_date, status
                ) VALUES (
                    :lecturer_id, :course_id, :semester_id, :assigned_date, :status
                )
            ");

            $stmt->execute([
                'lecturer_id' => $lecturer_id,
                'course_id' => $courseId,
                'semester_id' => $semester_id,
                'assigned_date' => $assigned_date,
                'status' => $status
            ]);

            // Get lecturer and semester details for success display
            $lecturerStmt = $conn->prepare("SELECT first_name, last_name, lecturer_id FROM lecturers WHERE id = :id");
            $lecturerStmt->execute(['id' => $lecturer_id]);
            $lecturer = $lecturerStmt->fetch();

            $semesterStmt = $conn->prepare("
                SELECT s.semester_name, ay.year_name
                FROM semesters s
                INNER JOIN academic_years ay ON s.academic_year_id = ay.id
                WHERE s.id = :id
            ");
            $semesterStmt->execute(['id' => $semester_id]);
            $semester = $semesterStmt->fetch();

            // Set success data for display
            $assignmentSuccess = [
                'course_code' => $course['course_code'],
                'course_name' => $course['course_name'],
                'lecturer_name' => $lecturer['first_name'] . ' ' . $lecturer['last_name'],
                'lecturer_id' => $lecturer['lecturer_id'],
                'semester_name' => $semester['semester_name'],
                'academic_year' => $semester['year_name'],
                'assigned_date' => date('F j, Y', strtotime($assigned_date)),
                'status' => ucfirst($status)
            ];

        } catch (Exception $e) {
            $errors[] = 'Failed to assign course: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Assign Course - ' . APP_NAME;
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
            <h4>Assign Course to Lecturer</h4>
        </div>
        <div class="topbar-right">
            <a href="view.php?id=<?php echo $courseId; ?>" class="btn btn-info">👁️ View Course</a>
            <a href="list.php" class="btn btn-secondary">← Back to Courses</a>
            <?php include '../../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <?php if (isset($assignmentSuccess)): ?>
            <div class="alert alert-success">
                <h5 class="alert-heading">✅ Course Assigned Successfully!</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title text-primary">
                                    <i class="fas fa-book"></i> Course Information
                                </h6>
                                <p class="mb-1"><strong>Course Code:</strong> <?php echo e($assignmentSuccess['course_code']); ?></p>
                                <p class="mb-1"><strong>Course Name:</strong> <?php echo e($assignmentSuccess['course_name']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title text-primary">
                                    <i class="fas fa-user-graduate"></i> Lecturer Information
                                </h6>
                                <p class="mb-1"><strong>Lecturer ID:</strong> <?php echo e($assignmentSuccess['lecturer_id']); ?></p>
                                <p class="mb-1"><strong>Name:</strong> <?php echo e($assignmentSuccess['lecturer_name']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title text-primary">
                                    <i class="fas fa-calendar-alt"></i> Academic Period
                                </h6>
                                <p class="mb-1"><strong>Semester:</strong> <?php echo e($assignmentSuccess['semester_name']); ?></p>
                                <p class="mb-1"><strong>Academic Year:</strong> <?php echo e($assignmentSuccess['academic_year']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title text-primary">
                                    <i class="fas fa-info-circle"></i> Assignment Details
                                </h6>
                                <p class="mb-1"><strong>Assigned Date:</strong> <?php echo e($assignmentSuccess['assigned_date']); ?></p>
                                <p class="mb-1"><strong>Status:</strong> <?php echo e($assignmentSuccess['status']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <a href="assign.php?id=<?php echo $courseId; ?>" class="btn btn-success">Assign Another Lecturer</a>
                    <a href="list.php" class="btn btn-primary">Back to Course List</a>
                </div>
            </div>
        <?php endif; ?>

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
                        <p><strong>Course Code:</strong> <?php echo e($course['course_code']); ?></p>
                        <p><strong>Course Name:</strong> <?php echo e($course['course_name']); ?></p>
                        <p><strong>Credit Hours:</strong> <?php echo $course['credit_hours']; ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Program:</strong> <?php echo e($course['program_code'] . ' - ' . $course['program_name']); ?></p>
                        <p><strong>Academic Year:</strong> Year <?php echo e($course['level_year']); ?></p>
                        <p><strong>Semester Offered:</strong>
                            <?php
                            $semesters_offered = ['', 'Semester 1', 'Semester 2', 'Both Semesters'];
                            echo $semesters_offered[$course['semester_offered']] ?? '';
                            ?>
                        </p>
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
                                            <?php echo (($_POST['lecturer_id'] ?? 0) == $lecturer['id']) ? 'selected' : ''; ?>>
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
                                            <?php echo (($_POST['semester_id'] ?? 0) == $semester['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($semester['year_name'] . ' - ' . $semester['semester_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Assignment Date</label>
                            <input type="date" name="assigned_date" class="form-control"
                                   value="<?php echo e($_POST['assigned_date'] ?? date('Y-m-d')); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo (($_POST['status'] ?? 'active') == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo (($_POST['status'] ?? 'active') == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo (($_POST['status'] ?? 'active') == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-right">
                <a href="list.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">🎓 Assign Course</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>