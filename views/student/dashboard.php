<?php
/**
 * Student Dashboard
 */
require_once '../../config.php';

// Initialize session and auth with student module context
$session = new Session('student');
$auth = new Auth('student');

// Verify student access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Student's courses this semester
$currentSemester = Helper::getCurrentSemester();
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM course_registrations 
                        WHERE student_id = :student_id 
                        AND semester_id = :semester_id 
                        AND status = 'approved'");
$stmt->execute([
    'student_id' => $studentProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$registeredCourses = $stmt->fetch()['count'];

// GPA
$stmt = $conn->prepare("SELECT cumulative_gpa FROM student_gpas 
                        WHERE student_id = :student_id 
                        ORDER BY id DESC LIMIT 1");
$stmt->execute(['student_id' => $studentProfile['id']]);
$gpaData = $stmt->fetch();
$cumulativeGPA = $gpaData['cumulative_gpa'] ?? 0.00;

// Outstanding balance
$stmt = $conn->prepare("SELECT balance FROM student_balances 
                        WHERE student_id = :student_id 
                        AND semester_id = :semester_id");
$stmt->execute([
    'student_id' => $studentProfile['id'],
    'semester_id' => $currentSemester['id'] ?? 0
]);
$balanceData = $stmt->fetch();
$outstandingBalance = $balanceData['balance'] ?? 0.00;

// Recent results
$stmt = $conn->prepare("SELECT r.*, c.course_code, c.course_name, c.credit_hours, s.semester_name
                        FROM results r
                        INNER JOIN courses c ON r.course_id = c.id
                        INNER JOIN semesters s ON r.semester_id = s.id
                        WHERE r.student_id = :student_id AND r.status = 'published'
                        ORDER BY r.created_at DESC LIMIT 5");
$stmt->execute(['student_id' => $studentProfile['id']]);
$recentResults = $stmt->fetchAll();

// Notifications - use helper function that handles personal + broadcasts + archived
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

// Ensure student_requests table exists and fetch recent requests for this student
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        user_id INT NOT NULL,
        request_type VARCHAR(100) NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore table creation errors
}

try {
    $reqStmt = $conn->prepare("SELECT * FROM student_requests WHERE student_id = :sid ORDER BY created_at DESC LIMIT 6");
    $reqStmt->execute(['sid' => $studentProfile['id']]);
    $studentRequests = $reqStmt->fetchAll();
} catch (Exception $e) {
    $studentRequests = [];
}

// Check for pending registration for current semester
$pendingRegStmt = $conn->prepare("SELECT * FROM semester_registrations WHERE student_id = :sid AND semester_id = :semid AND status = 'pending'");
$pendingRegStmt->execute(['sid' => $studentProfile['id'], 'semid' => $currentSemester['id']]);
$pendingRegistration = $pendingRegStmt->fetch();

$pageTitle = 'Student Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/student/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Dashboard</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar">
                            <?php if (!empty($studentProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile Photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($studentProfile['first_name'], 0, 1) . substr($studentProfile['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong><?php echo e($studentProfile['first_name']); ?> <?php echo e($studentProfile['last_name']); ?></strong>
                            <br><small><?php echo e($studentProfile['student_id']); ?></small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <!-- change-password moved to header quick dropdown -->
                        <a href="transcript.php" class="dropdown-item">
                            <i>📄</i> My Transcript
                        </a>
                        <a href="fees.php" class="dropdown-item">
                            <i>💰</i> Fee Statement
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/student/logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success">
                <?php echo e($session->getFlash('success')); ?>
            </div>
        <?php endif; ?>
        

        <?php
        // --- Build extended profile details (derived from admin-provided admission data) ---
        $programInfo = null;
        if (!empty($studentProfile['program_id'])) {
            $pstmt = $conn->prepare("SELECT program_name, department FROM programs WHERE id = :id LIMIT 1");
            $pstmt->execute(['id' => $studentProfile['program_id']]);
            $programInfo = $pstmt->fetch();
        }

        // Resolve intake (use entry semester start date when available)
        $entrySemester = null;
        $intakeLabel = '-';
        if (!empty($studentProfile['entry_semester_id'])) {
            $es = $conn->prepare("SELECT s.semester_name, s.start_date, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1");
            $es->execute(['id' => $studentProfile['entry_semester_id']]);
            $entrySemester = $es->fetch();
            if ($entrySemester && !empty($entrySemester['start_date'])) {
                $intakeLabel = date('M', strtotime($entrySemester['start_date'])) . ' - ' . ($entrySemester['year_name'] ?? $studentProfile['entry_year']);
            }
        } elseif (!empty($studentProfile['entry_year'])) {
            $intakeLabel = $studentProfile['entry_year'];
        }

        // Academic status: consider student 'Reported' when there are approved registrations for current semester
        $academicStatus = 'Not Reported';
        try {
            $rstmt = $conn->prepare("SELECT COUNT(*) as cnt FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'approved'");
            $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $currentSemester['id'] ?? 0]);
            $regApproved = intval($rstmt->fetch()['cnt'] ?? 0);
            if ($regApproved > 0) $academicStatus = 'Reported';
        } catch (Exception $e) {
            // ignore — we'll show fallback value
        }

        // Disciplinary default
        $disciplinary = ($studentProfile['status'] === 'suspended') ? 'Suspended' : 'Clean';

        // Sponsorship (fallback)
        $sponsorship = $studentProfile['sponsorship'] ?? 'Not set';
        ?>

        <!-- Student Profile Header -->
        <div class="card mb-3">
            <div class="card-body" style="background:#f8f9fa;">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div style="width:120px; height:120px; border-radius:50%; overflow:hidden; background:#fff; border:4px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                            <?php if (!empty($studentProfile['photo'])): ?>
                                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile Photo" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; background:#667eea; font-size:36px;">
                                    <?php echo strtoupper(substr($studentProfile['first_name'] ?? 'A',0,1) . substr($studentProfile['last_name'] ?? 'D',0,1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col">
                        <div class="text-right">
                            <h4 class="mb-1" style="text-transform:uppercase; font-weight:600; font-size:18px;">
                                <?php echo e($studentProfile['last_name']); ?>, <?php echo e($studentProfile['first_name']); ?>
                            </h4>
                            <p class="mb-1" style="font-size:16px; color:#666;">
                                <?php echo e($studentProfile['student_id'] ?? '-'); ?>
                            </p>
                            <p class="mb-0" style="font-size:14px; color:#999;">
                                <strong>ADMISSION NO:</strong> <?php echo e($studentProfile['admission_number'] ?? '-'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab-like Navigation -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <ul class="nav nav-tabs border-0" style="border-bottom:2px solid #eee;" id="profileTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="profile-tab" data-toggle="tab" href="#profile-content" role="tab" style="border:none; border-bottom:3px solid #28a745; color:#28a745; font-weight:600; cursor:pointer;">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="requests-tab" data-toggle="tab" href="#requests-content" role="tab" style="border:none; color:#666; cursor:pointer;">Requests</a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content" id="profileTabContent">
            <!-- Profile Tab -->
            <div class="tab-pane fade show active" id="profile-content" role="tabpanel">
                <!-- Information Cards -->
                <div class="row">
                <!-- Academic Summary -->
            <!-- Left Column: Basic Information -->
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-4" style="font-weight:600;">Basic Information</h5>
                        
                        <table class="table table-borderless" style="font-size:14px;">
                            <tbody>
                                <tr>
                                    <td width="40%" style="color:#666;">Surname</td>
                                    <td style="font-weight:500;"><?php echo e($studentProfile['last_name'] ?? ''); ?></td>
                                    <td width="60"></td>
                                <tr>
                                    <td style="color:#000000;">SMNS email</td>
                                    <td style="color:#666;">Other names</td>
                                    <td style="font-weight:500;"><?php echo e(trim($studentProfile['first_name'] . ' ' . ($studentProfile['middle_name'] ?? ''))); ?></td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">Primary number</td>
                                    <td style="font-weight:500;"><?php echo e($studentProfile['phone'] ?? ''); ?></td>
                                    <td><a href="#" style="color:#28a745; font-size:13px;">Edit</a></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">Secondary number</td>
                                    <td style="font-weight:500;"><?php echo e($studentProfile['secondary_phone'] ?? ''); ?></td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">Email</td>
                                    <td style="font-weight:500;"><?php echo e($studentProfile['email'] ?? ''); ?></td>
                                    <td><a href="#" style="color:#28a745; font-size:13px;">Edit</a></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">SMNS email</td>
                                    <td style="font-weight:500;"><?php echo e($currentUser['email'] ?? ''); ?></td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="color:#666;">Nationality</td>
                                    <td style="font-weight:500;"><?php echo e($studentProfile['country'] ?? 'Ugandan'); ?></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Disciplinary & Financial Info -->
            <div class="col-md-6 mb-3">
                <!-- Disciplinary Status -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="mb-3" style="font-weight:600;">Disciplinary status</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span style="color:#666;">Status:</span>
                                <span class="ml-2" style="color:#28a745; font-weight:600;"><?php echo e($disciplinary); ?></span>
                            </div>
                            <a href="#" style="color:#28a745; font-size:14px;">History</a>
                        </div>
                    </div>
                </div>
                
                <!-- Financial Information -->
                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-3" style="font-weight:600;">Financial information</h5>
                        <p class="mb-2" style="font-size:14px;">
                            <strong>Sponsorship:</strong> <?php echo e($sponsorship); ?>
                        </p>
                        <div class="alert alert-<?php echo $outstandingBalance == 0 ? 'success' : 'warning'; ?> py-2 px-3 mb-0" style="font-size:13px;">
                            <strong>Outstanding Balance:</strong> UGX <?php echo number_format($outstandingBalance, 0); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Academic Information -->
        <div class="row">
            <div class="col-12 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-4" style="font-weight:600;">Academic information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless" style="font-size:14px;">
                                    <tbody>
                                        <tr>
                                            <td width="40%" style="color:#666;">Programme</td>
                                            <td style="font-weight:500;"><?php echo e($programInfo['program_name'] ?? $studentProfile['program_name'] ?? '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">School/College</td>
                                            <td style="font-weight:500;"><?php echo e(!empty($programInfo['department']) ? 'School of ' . $programInfo['department'] : '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Department</td>
                                            <td style="font-weight:500;"><?php echo e($programInfo['department'] ?? '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Campus</td>
                                            <td style="font-weight:500;"><?php echo e($studentProfile['campus'] ?? 'Main campus'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Study Year</td>
                                            <td style="font-weight:500;">Year <?php echo e($studentProfile['level_year'] ?? '-'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless" style="font-size:14px;">
                                    <tbody>
                                        <tr>
                                            <td width="40%" style="color:#666;">Current Semester</td>
                                            <td style="font-weight:500;"><?php echo e($currentSemester['semester_name'] ?? '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Intake</td>
                                            <td style="font-weight:500;"><?php echo e($intakeLabel); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Entry Mode</td>
                                            <td style="font-weight:500;"><?php echo e($studentProfile['entry_mode'] ?? '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Session</td>
                                            <td style="font-weight:500;"><?php echo e($studentProfile['enrollment_type'] ?? 'Day'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="color:#666;">Academic Status</td>
                                            <td>
                                                <span class="badge badge-<?php echo $academicStatus === 'Reported' ? 'success' : 'warning'; ?>">
                                                    <?php echo e($academicStatus); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Current Semester & Recent Results -->
        <div class="row">
            <div class="col-md-6">
                <?php if ($currentSemester): ?>
                <div class="card">
                    <div class="card-header">
                        Current Semester
                    </div>
                    <div class="card-body">
                        <h5><?php echo e($currentSemester['semester_name']); ?></h5>
                        <p><strong>Status:</strong> <span class="badge badge-<?php echo Helper::getStatusColor($currentSemester['status']); ?>"><?php echo e($currentSemester['status']); ?></span></p>
                        <p><strong>Start:</strong> <?php echo Helper::formatDate($currentSemester['start_date']); ?></p>
                        <p><strong>End:</strong> <?php echo Helper::formatDate($currentSemester['end_date']); ?></p>
                        <?php if ($currentSemester['registration_start_date']): ?>
                        <p><strong>Registration:</strong> <?php echo Helper::formatDate($currentSemester['registration_start_date']); ?> - <?php echo Helper::formatDate($currentSemester['registration_end_date']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Results -->
            <div class="col-md-6">
                <?php if (count($recentResults) > 0): ?>
                <div class="card">
                    <div class="card-header">Recent Results</div>
                    <div class="card-body p-0">
                        <table class="table table-hover table-sm mb-0" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Marks</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentResults as $result): ?>
                                <tr>
                                    <td><?php echo e($result['course_code']); ?><br><small class="text-muted"><?php echo e($result['course_name']); ?></small></td>
                                    <td><?php echo number_format($result['total_marks'], 1); ?>%</td>
                                    <td>
                                        <?php 
                                        // Use grade_points for color if available, otherwise fallback to grade letter
                                        $badgeColor = ($result['grade_points'] !== null && $result['grade_points'] > 0) 
                                            ? Helper::getGPAColor($result['grade_points']) 
                                            : Helper::getGradeColor($result['grade'] ?? '');
                                        ?>
                                        <span class="badge badge-<?php echo $badgeColor; ?>"><?php echo e($result['grade'] ?? 'N/A'); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center text-muted py-3">
                        <small>No results published yet</small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
            </div>
            <!-- End Profile Tab -->

            <!-- Requests Tab -->
            <div class="tab-pane fade" id="requests-content" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-4" style="font-weight:600;">Student requests</h5>
                        
                        <form id="studentRequestForm" method="POST" action="<?php echo BASE_URL; ?>/views/student/submit-request.php">
                                    <?php echo csrfField(); ?>
                            <div class="form-group">
                                <label for="requestType" style="font-weight:500;">Apply for:</label>
                                <select class="form-control" id="requestType" name="request_type" required style="font-size:14px;">
                                    <option value="">Click to select</option>
                                    <option value="transcript">Official Transcript</option>
                                    <option value="recommendation_letter">Letter of Recommendation</option>
                                    <option value="certificate">Course Completion Certificate</option>
                                    <option value="enrollment_verification">Enrollment Verification Letter</option>
                                    <option value="course_add_drop">Add/Drop Course Request</option>
                                    <option value="grade_appeal">Grade Appeal</option>
                                    <option value="semester_deferment">Semester Deferment</option>
                                    <option value="leave_of_absence">Leave of Absence</option>
                                    <option value="fee_payment_plan">Fee Payment Plan</option>
                                    <option value="id_card_replacement">Student ID Card Replacement</option>
                                    <option value="exam_special_arrangement">Special Exam Arrangement</option>
                                    <option value="internship_approval">Internship Approval</option>
                                    <option value="other">Other Request</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="requestReason" style="font-weight:500;">Describe your reason:</label>
                                <textarea class="form-control" id="requestReason" name="reason" rows="8" required style="font-size:14px; resize:vertical;" placeholder="Please provide detailed information about your request..."></textarea>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-success px-5" style="font-size:16px; border-radius:4px;">Submit</button>
                            </div>
                        </form>
                        
                        <!-- Previous Requests (if any) -->
                        <hr class="my-4">
                        <h6 class="mb-3" style="font-weight:600;">Recent Requests</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" style="font-size:13px;">
                                <thead style="background:#f8f9fa;">
                                    <tr>
                                        <th>Date</th>
                                        <th>Request Type</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($studentRequests)): ?>
                                    <?php foreach ($studentRequests as $r): ?>
                                        <tr>
                                            <td><?php echo e(Helper::formatDateTime($r['created_at'], 'M d, Y')); ?></td>
                                            <td><?php echo e(ucwords(str_replace('_',' ', $r['request_type']))); ?></td>
                                            <td><span class="badge badge-<?php echo $r['status'] === 'pending' ? 'warning' : ($r['status'] === 'approved' ? 'success' : 'secondary'); ?>"><?php echo e(ucfirst($r['status'])); ?></span></td>
                                            <td>
                                                <a href="javascript:void(0)" class="btn btn-sm btn-outline-secondary" onclick="alert(<?php echo json_encode(substr($r['reason'],0,100) . (strlen($r['reason'])>100? '...':'') ); ?>)">View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-3">No previous requests found</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Requests Tab -->
        </div>
    </div>
</div>

<script>
// Handle tab switching
document.addEventListener('DOMContentLoaded', function() {
    const profileTab = document.getElementById('profile-tab');
    const requestsTab = document.getElementById('requests-tab');
    
    profileTab.addEventListener('click', function(e) {
        e.preventDefault();
        // Update active styles
        profileTab.style.borderBottom = '3px solid #28a745';
        profileTab.style.color = '#28a745';
        profileTab.style.fontWeight = '600';
        requestsTab.style.borderBottom = 'none';
        requestsTab.style.color = '#666';
        requestsTab.style.fontWeight = 'normal';
        
        // Show/hide content
        document.getElementById('profile-content').classList.add('show', 'active');
        document.getElementById('requests-content').classList.remove('show', 'active');
    });
    
    requestsTab.addEventListener('click', function(e) {
        e.preventDefault();
        // Update active styles
        requestsTab.style.borderBottom = '3px solid #28a745';
        requestsTab.style.color = '#28a745';
        requestsTab.style.fontWeight = '600';
        profileTab.style.borderBottom = 'none';
        profileTab.style.color = '#666';
        profileTab.style.fontWeight = 'normal';
        
        // Show/hide content
        document.getElementById('requests-content').classList.add('show', 'active');
        document.getElementById('profile-content').classList.remove('show', 'active');
    });
});
</script>

<?php include '../../includes/footer.php'; ?>