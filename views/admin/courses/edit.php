<?php
/**
 * Admin - Edit Course
 * Edit existing course information
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

// Fetch programs for dropdown
$stmt = $conn->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
$programs = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize inputs
    $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
    $course_name = Security::sanitize($_POST['course_name'] ?? '');
    $credit_hours = (int)($_POST['credit_hours'] ?? 3);
    $lecture_hours = (int)($_POST['lecture_hours'] ?? 0);
    $tutorial_hours = (int)($_POST['tutorial_hours'] ?? 0);
    $practical_hours = (int)($_POST['practical_hours'] ?? 0);
    $program_id = (int)($_POST['program_id'] ?? 0);
    $level_year = (int)($_POST['level_year'] ?? 1);
    $semester_offered = (int)($_POST['semester_offered'] ?? 1);
    $prerequisites = Security::sanitize($_POST['prerequisites'] ?? '');
    $description = Security::sanitize($_POST['description'] ?? '');
    $status = Security::sanitize($_POST['status'] ?? 'active');

    // Validate required fields
    $missing = [];
    if (empty($course_code)) $missing[] = 'Course Code';
    if (empty($course_name)) $missing[] = 'Course Name';
    if (empty($program_id)) $missing[] = 'Program';

    if ($missing) {
        $errors[] = 'Please fill in required fields: ' . implode(', ', $missing);
    }

    // Validate course code format
    if (!empty($course_code) && !preg_match('/^[A-Z]{2,4}\d{3,4}$/', $course_code)) {
        $errors[] = 'Course code should be in format like BTH101, MDIV501, etc.';
    }

    // Check if course code already exists (excluding current course)
    if (!empty($course_code)) {
        $codeCheck = $conn->prepare("SELECT id FROM courses WHERE course_code = :code AND id != :id");
        $codeCheck->execute(['code' => $course_code, 'id' => $courseId]);
        if ($codeCheck->fetch()) {
            $errors[] = 'This course code already exists in the system';
        }
    }

    // Validate credit hours
    if ($credit_hours < 1 || $credit_hours > 6) {
        $errors[] = 'Credit hours must be between 1 and 6';
    }

    // Validate level/year
    if ($level_year < 1 || $level_year > 4) {
        $errors[] = 'Level/Year must be between 1 and 4';
    }

    if (empty($errors)) {
        try {
            // Update course
            $stmt = $conn->prepare("
                UPDATE courses SET
                    course_code = :course_code,
                    course_name = :course_name,
                    credit_hours = :credit_hours,
                    lecture_hours = :lecture_hours,
                    tutorial_hours = :tutorial_hours,
                    practical_hours = :practical_hours,
                    program_id = :program_id,
                    level_year = :level_year,
                    semester_offered = :semester_offered,
                    prerequisites = :prerequisites,
                    description = :description,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'course_code' => $course_code,
                'course_name' => $course_name,
                'credit_hours' => $credit_hours,
                'lecture_hours' => $lecture_hours,
                'tutorial_hours' => $tutorial_hours,
                'practical_hours' => $practical_hours,
                'program_id' => $program_id,
                'level_year' => $level_year,
                'semester_offered' => $semester_offered,
                'prerequisites' => $prerequisites,
                'description' => $description,
                'status' => $status,
                'id' => $courseId
            ]);

            $session->setFlash('success', 'Course updated successfully!');
            header('Location: list.php');
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to update course: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Course - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Edit Course</h4>
        </div>
        <div class="topbar-right">
            <a href="view.php?id=<?php echo $courseId; ?>" class="btn btn-info">👁️ View Course</a>
            <a href="list.php" class="btn btn-secondary">← Back to Courses</a>
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

        <form method="POST" action="">
            <!-- Course Information -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-book"></i> Course Information</h5>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Course Code <span class="text-danger">*</span></label>
                            <input type="text" name="course_code" class="form-control" required
                                   placeholder="e.g., BTH101" value="<?php echo e($_POST['course_code'] ?? $course['course_code']); ?>"
                                   pattern="[A-Z]{2,4}\d{3,4}" title="Format: BTH101, MDIV501, etc.">
                            <small class="text-muted">Unique code like BTH101, MDIV501</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Course Name <span class="text-danger">*</span></label>
                            <input type="text" name="course_name" class="form-control" required
                                   placeholder="e.g., Introduction to Biblical Studies" value="<?php echo e($_POST['course_name'] ?? $course['course_name']); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Credit Hours</label>
                            <select name="credit_hours" class="form-control">
                                <option value="1" <?php echo (($_POST['credit_hours'] ?? $course['credit_hours']) == 1) ? 'selected' : ''; ?>>1 Credit</option>
                                <option value="2" <?php echo (($_POST['credit_hours'] ?? $course['credit_hours']) == 2) ? 'selected' : ''; ?>>2 Credits</option>
                                <option value="3" <?php echo (($_POST['credit_hours'] ?? $course['credit_hours']) == 3) ? 'selected' : ''; ?>>3 Credits</option>
                                <option value="4" <?php echo (($_POST['credit_hours'] ?? $course['credit_hours']) == 4) ? 'selected' : ''; ?>>4 Credits</option>
                                <option value="5" <?php echo (($_POST['credit_hours'] ?? $course['credit_hours']) == 5) ? 'selected' : ''; ?>>5 Credits</option>
                                <option value="6" <?php echo (($_POST['credit_hours'] ?? $course['credit_hours']) == 6) ? 'selected' : ''; ?>>6 Credits</option>
                            </select>
                        </div>
                    </div>

                    <!-- Course Hours Information -->
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Lecture Hours (LH)</label>
                            <input type="number" name="lecture_hours" class="form-control" min="0" max="100"
                                   placeholder="0" value="<?php echo e($_POST['lecture_hours'] ?? $course['lecture_hours']); ?>">
                            <small class="text-muted">Number of lecture hours per week</small>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Tutorial Hours (TH)</label>
                            <input type="number" name="tutorial_hours" class="form-control" min="0" max="100"
                                   placeholder="0" value="<?php echo e($_POST['tutorial_hours'] ?? $course['tutorial_hours']); ?>">
                            <small class="text-muted">Number of tutorial hours per week</small>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Practical Hours (PH)</label>
                            <input type="number" name="practical_hours" class="form-control" min="0" max="100"
                                   placeholder="0" value="<?php echo e($_POST['practical_hours'] ?? $course['practical_hours']); ?>">
                            <small class="text-muted">Number of practical hours per week</small>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Contact Hours (CH)</label>
                            <input type="text" class="form-control" readonly
                                   value="<?php echo e(($_POST['lecture_hours'] ?? $course['lecture_hours']) + ($_POST['tutorial_hours'] ?? $course['tutorial_hours']) + ($_POST['practical_hours'] ?? $course['practical_hours'])); ?>"
                                   id="contact_hours_display">
                            <small class="text-muted">Auto-calculated: LH + TH + PH</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Program <span class="text-danger">*</span></label>
                            <select name="program_id" class="form-control" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>"
                                            <?php echo (($_POST['program_id'] ?? $course['program_id']) == $program['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($program['program_code'] . ' - ' . $program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Level/Year</label>
                            <select name="level_year" class="form-control">
                                <option value="1" <?php echo (($_POST['level_year'] ?? $course['level_year']) == 1) ? 'selected' : ''; ?>>Year 1</option>
                                <option value="2" <?php echo (($_POST['level_year'] ?? $course['level_year']) == 2) ? 'selected' : ''; ?>>Year 2</option>
                                <option value="3" <?php echo (($_POST['level_year'] ?? $course['level_year']) == 3) ? 'selected' : ''; ?>>Year 3</option>
                                <option value="4" <?php echo (($_POST['level_year'] ?? $course['level_year']) == 4) ? 'selected' : ''; ?>>Year 4</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Semester Offered</label>
                            <select name="semester_offered" class="form-control">
                                <option value="1" <?php echo (($_POST['semester_offered'] ?? $course['semester_offered']) == 1) ? 'selected' : ''; ?>>Semester 1</option>
                                <option value="2" <?php echo (($_POST['semester_offered'] ?? $course['semester_offered']) == 2) ? 'selected' : ''; ?>>Semester 2</option>
                                <option value="3" <?php echo (($_POST['semester_offered'] ?? $course['semester_offered']) == 3) ? 'selected' : ''; ?>>Both Semesters</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Prerequisites</label>
                            <textarea name="prerequisites" class="form-control" rows="2"
                                      placeholder="List any prerequisite courses or requirements"><?php echo e($_POST['prerequisites'] ?? $course['prerequisites']); ?></textarea>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo (($_POST['status'] ?? $course['status']) == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (($_POST['status'] ?? $course['status']) == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Course Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Brief description of the course content and objectives"><?php echo e($_POST['description'] ?? $course['description']); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="text-right">
                <a href="list.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">💾 Update Course</button>
            </div>
        </form>

        <script>
        // Auto-calculate contact hours
        document.addEventListener('DOMContentLoaded', function() {
            const lectureHours = document.querySelector('input[name="lecture_hours"]');
            const tutorialHours = document.querySelector('input[name="tutorial_hours"]');
            const practicalHours = document.querySelector('input[name="practical_hours"]');
            const contactHoursDisplay = document.getElementById('contact_hours_display');

            function calculateContactHours() {
                const lh = parseInt(lectureHours.value) || 0;
                const th = parseInt(tutorialHours.value) || 0;
                const ph = parseInt(practicalHours.value) || 0;
                const total = lh + th + ph;
                contactHoursDisplay.value = total;
            }

            lectureHours.addEventListener('input', calculateContactHours);
            tutorialHours.addEventListener('input', calculateContactHours);
            practicalHours.addEventListener('input', calculateContactHours);

            // Initial calculation
            calculateContactHours();
        });
        </script>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>