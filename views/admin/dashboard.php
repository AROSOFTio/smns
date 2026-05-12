<?php
/**
 * Admin Dashboard
 */
require_once '../../config.php';

// Don't start session manually - let Session class handle it with role-specific name
// Initialize session and auth with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Keep academic years/semesters normalized and ready for upcoming years too.
try {
    AcademicCalendarManager::ensureStandardCalendar($conn);
} catch (Exception $e) {
    // Non-fatal: dashboard can still render if sync is temporarily unavailable.
}

// Total students
$stmt = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
$totalStudents = $stmt->fetch()['count'];

// Total lecturers
$stmt = $conn->query("SELECT COUNT(*) as count FROM lecturers WHERE status = 'active'");
$totalLecturers = $stmt->fetch()['count'];

// Total courses
$stmt = $conn->query("SELECT COUNT(*) as count FROM courses WHERE status = 'active'");
$totalCourses = $stmt->fetch()['count'];

// Total system users (all roles)
$stmt = $conn->query("SELECT COUNT(*) as count FROM users");
$totalSystemUsers = $stmt->fetch()['count'];
$systemUserRoleCounts = [
    'admin' => 0,
    'lecturer' => 0,
    'student' => 0,
    'finance' => 0
];
$roleStmt = $conn->query("SELECT role, COUNT(*) AS count FROM users GROUP BY role");
foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $roleRow) {
    $roleKey = (string)($roleRow['role'] ?? '');
    if (isset($systemUserRoleCounts[$roleKey])) {
        $systemUserRoleCounts[$roleKey] = (int)$roleRow['count'];
    }
}

// Pending registrations
$stmt = $conn->query("SELECT COUNT(*) as count FROM course_registrations WHERE status = 'pending'");
$pendingRegistrations = $stmt->fetch()['count'];

// Pending results
$stmt = $conn->query("SELECT COUNT(*) as count FROM results WHERE status = 'submitted'");
$pendingResults = $stmt->fetch()['count'];

// Get assigned courses with lecturers
$stmt = $conn->prepare("
    SELECT ca.*, c.course_code, c.course_name, l.first_name, l.last_name, l.lecturer_id,
           s.semester_name, ay.year_name, p.program_code, p.program_name
    FROM course_assignments ca
    INNER JOIN courses c ON ca.course_id = c.id
    INNER JOIN lecturers l ON ca.lecturer_id = l.id
    INNER JOIN semesters s ON ca.semester_id = s.id
    INNER JOIN academic_years ay ON s.academic_year_id = ay.id
    INNER JOIN programs p ON c.program_id = p.id
    WHERE ca.status = 'active' AND c.status = 'active' AND l.status = 'active'
    ORDER BY ca.assigned_date DESC
");
$stmt->execute();
$assignedCourses = $stmt->fetchAll();

// Recent activities
$logger = new Logger();
$recentActivities = $logger->getRecentActivities(10);

// Login sessions with duration
$dashboardLoginSessionLimit = 50;
$loginSessions = $logger->getLoginSessions($dashboardLoginSessionLimit);

// Handle semester activation (admin-only)
// Also support undo (revert activation) via `undo_semester_id` (AJAX-friendly)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_semester_id'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: dashboard.php'); exit;
    }

    $undoId = intval($_POST['undo_semester_id']);
    try {
        // capture currently active semester to report as "previous"
        $currStmt = $conn->prepare("SELECT s.id, s.academic_year_id, s.semester_name, s.semester_number, s.start_date, s.end_date, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.status = 'active' ORDER BY s.start_date DESC, s.id DESC LIMIT 1");
        $currStmt->execute();
        $currentActive = $currStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // ensure target semester exists
        $targetStmt = $conn->prepare('SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
        $targetStmt->execute(['id' => $undoId]);
        $targetSem = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$targetSem) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Semester not found']); exit;
            }
            $session->setFlash('error', 'Semester not found');
            header('Location: dashboard.php'); exit;
        }

        $targetAcademicYearId = (int)($targetSem['academic_year_id'] ?? 0);
        if ($targetAcademicYearId <= 0) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Invalid academic year']); exit;
            }
            $session->setFlash('error', 'Invalid academic year');
            header('Location: dashboard.php'); exit;
        }

        // Revert activation context: keep only selected intake active.
        $conn->beginTransaction();
        $deactivateSemesterStmt = $conn->prepare("UPDATE semesters SET status = 'inactive' WHERE status = 'active' AND id <> :id");
        $deactivateSemesterStmt->execute(['id' => $undoId]);
        $activateSemesterStmt = $conn->prepare("UPDATE semesters SET status = 'active' WHERE id = :id");
        $activateSemesterStmt->execute(['id' => $undoId]);

        $deactivateYearStmt = $conn->prepare("UPDATE academic_years SET status = 'inactive' WHERE status = 'active' AND id <> :id");
        $deactivateYearStmt->execute(['id' => $targetAcademicYearId]);
        $uay = $conn->prepare("UPDATE academic_years SET status = 'active' WHERE id = :id");
        $uay->execute(['id' => $targetAcademicYearId]);
        $conn->commit();

        $session->setFlash('success', 'Intake activation reverted successfully.');

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $s = $conn->prepare('SELECT s.*, ay.year_name, ay.start_date AS academic_year_start_date, ay.end_date AS academic_year_end_date FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
            $s->execute(['id' => $undoId]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'action' => 'reverted', 'message' => 'Reverted to ' . (($row['year_name'] ?? '') . ' - ' . ($row['semester_name'] ?? '')), 'semester' => $row, 'previous' => $currentActive]);
            exit;
        }

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $session->setFlash('error', 'Failed to revert semester: ' . $e->getMessage());
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
        }
    }

    header('Location: dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_semester_id'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: dashboard.php'); exit;
    }

    $activateId = intval($_POST['activate_semester_id']);
    try {
        // capture previous active semester BEFORE making changes
        $prevStmt = $conn->prepare("SELECT s.id, s.academic_year_id, s.semester_name, s.semester_number, s.start_date, s.end_date, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.status = 'active' ORDER BY s.start_date DESC, s.id DESC LIMIT 1");
        $prevStmt->execute();
        $previousSem = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // ensure target semester exists before status updates
        $targetStmt = $conn->prepare('SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
        $targetStmt->execute(['id' => $activateId]);
        $targetSem = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$targetSem) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Semester not found']); exit;
            }
            $session->setFlash('error', 'Semester not found');
            header('Location: dashboard.php'); exit;
        }

        $targetAcademicYearId = (int)($targetSem['academic_year_id'] ?? 0);
        if ($targetAcademicYearId <= 0) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Invalid academic year']); exit;
            }
            $session->setFlash('error', 'Invalid academic year');
            header('Location: dashboard.php'); exit;
        }

        $conn->beginTransaction();
        // Deactivate all other intakes and activate only the selected intake.
        $deactivateSemesterStmt = $conn->prepare("UPDATE semesters SET status = 'inactive' WHERE status = 'active' AND id <> :id");
        $deactivateSemesterStmt->execute(['id' => $activateId]);
        $activateSemesterStmt = $conn->prepare("UPDATE semesters SET status = 'active' WHERE id = :id");
        $activateSemesterStmt->execute(['id' => $activateId]);

        // Ensure corresponding academic year is active.
        $deactivateYearStmt = $conn->prepare("UPDATE academic_years SET status = 'inactive' WHERE status = 'active' AND id <> :id");
        $deactivateYearStmt->execute(['id' => $targetAcademicYearId]);
        $uay = $conn->prepare("UPDATE academic_years SET status = 'active' WHERE id = :id");
        $uay->execute(['id' => $targetAcademicYearId]);

        $conn->commit();
        $session->setFlash('success', 'Intake activated successfully.');

        // If AJAX request, return JSON with updated semester and previous
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $s = $conn->prepare('SELECT s.*, ay.year_name, ay.start_date AS academic_year_start_date, ay.end_date AS academic_year_end_date FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = :id LIMIT 1');
            $s->execute(['id' => $activateId]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;

            // Determine action/message
            $action = 'activated';
            $message = '';
            $selectedIntakeLabel = ((int)($row['semester_number'] ?? 0) === 1) ? 'January Intake' : (((int)($row['semester_number'] ?? 0) === 2) ? 'August Intake' : 'Selected Intake');
            if ($previousSem && (int)($previousSem['id'] ?? 0) === $activateId) {
                $action = 'no_change';
                $message = ($row['year_name'] ?? '') . ' ' . $selectedIntakeLabel . ' is already active.';
            } else {
                $label = trim(($row['year_name'] ?? '') . ' - ' . $selectedIntakeLabel . ' (Semester 1 & Semester 2)');
                if ($previousSem) {
                    $prevIntakeLabel = ((int)($previousSem['semester_number'] ?? 0) === 1) ? 'January Intake' : (((int)($previousSem['semester_number'] ?? 0) === 2) ? 'August Intake' : 'Previous Intake');
                    $prevLabel = trim(($previousSem['year_name'] ?? '') . ' - ' . $prevIntakeLabel . ' (Semester 1 & Semester 2)');
                    $message = $label . ' activated; previous ' . $prevLabel . ' was deactivated.';
                } else {
                    $message = $label . ' activated.';
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'action' => $action, 'message' => $message, 'semester' => $row, 'previous' => $previousSem]);
            exit;
        }

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $session->setFlash('error', 'Failed to activate semester: ' . $e->getMessage());
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
        }
    }

    header('Location: dashboard.php'); exit;
}

// Current semester / academic year context
$currentSemester = Helper::getCurrentSemester();
$currentSemesterId = (int)($currentSemester['id'] ?? 0);
$currentAcademicYear = [];
if (!empty($currentSemester['academic_year_id'])) {
    $ayStmt = $conn->prepare("SELECT id, year_name, start_date, end_date, status FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => (int)$currentSemester['academic_year_id']]);
    $currentAcademicYear = $ayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
if (empty($currentAcademicYear)) {
    $currentAcademicYear = Helper::getCurrentAcademicYear() ?: [];
}
$currentAcademicYearName = (string)($currentAcademicYear['year_name'] ?? 'Not Set');
$activeSemesterNumbersInCurrentYear = [];
$activeSemesterNamesInCurrentYear = [];
if (!empty($currentAcademicYear['id'])) {
    try {
        $activeSemestersStmt = $conn->prepare("
            SELECT semester_number, semester_name
            FROM semesters
            WHERE academic_year_id = :academic_year_id
              AND status = 'active'
            ORDER BY semester_number ASC, id ASC
        ");
        $activeSemestersStmt->execute(['academic_year_id' => (int)$currentAcademicYear['id']]);
        while ($semRow = $activeSemestersStmt->fetch(PDO::FETCH_ASSOC)) {
            $num = (int)($semRow['semester_number'] ?? 0);
            if ($num > 0) {
                $activeSemesterNumbersInCurrentYear[] = $num;
            }
            $name = trim((string)($semRow['semester_name'] ?? ''));
            if ($name !== '') {
                $activeSemesterNamesInCurrentYear[] = $name;
            }
        }
    } catch (Exception $e) {
        // Non-fatal: status block will use fallback labels.
    }
}
$activeSemesterNumbersInCurrentYear = array_values(array_unique($activeSemesterNumbersInCurrentYear));
$activeSemesterNamesInCurrentYear = array_values(array_unique($activeSemesterNamesInCurrentYear));
$activeSemestersStatusText = 'No active semester';
if (!empty($currentSemester['id'])) {
    $activeSemestersStatusText = (((int)($currentSemester['semester_number'] ?? 0) === 1) ? 'January Intake' : 'August Intake') . ' Active (Semester 1 & Semester 2)';
} elseif (!empty($activeSemesterNamesInCurrentYear)) {
    $activeSemestersStatusText = implode(' & ', $activeSemesterNamesInCurrentYear) . ' Active';
}
$currentSemesterNumber = (int)($currentSemester['semester_number'] ?? 0);
$currentIntakeLabel = '';
if ($currentSemesterNumber === 1) {
    $currentIntakeLabel = 'January Intake';
} elseif ($currentSemesterNumber === 2) {
    $currentIntakeLabel = 'August Intake';
}
$currentSemesterTermLabel = 'Current Semester';
if (!empty($currentSemester['id'])) {
    $currentSemesterTermLabel = trim($currentIntakeLabel . ' - Semester 1 & Semester 2');
} else {
    $currentSemesterTermLabel = trim((string)($currentSemester['semester_name'] ?? 'Current Semester'));
    if ($currentIntakeLabel !== '') {
        $currentSemesterTermLabel .= ' - ' . $currentIntakeLabel;
    }
}
$currentContextStartDate = (string)($currentSemester['start_date'] ?? '');
$currentContextEndDate = (string)($currentSemester['end_date'] ?? '');
$usdUgxRate = (float)Helper::getUsdUgxRate();
if ($usdUgxRate <= 0) {
    $usdUgxRate = 3700.0;
}
$adminStudentBalances = [];
$adminOutstandingBalanceUgx = 0.0;
$adminStudentsWithBalance = 0;
$adminStudentCount = 0;
if ($currentSemesterId > 0) {
    $adminBalanceMonitor = getActiveStudentBalanceMonitor($conn, $currentSemesterId);
    $adminStudentBalances = (array)($adminBalanceMonitor['rows'] ?? []);
    $adminOutstandingBalanceUgx = (float)($adminBalanceMonitor['outstanding_total_ugx'] ?? 0.0);
    $adminStudentsWithBalance = (int)($adminBalanceMonitor['students_with_balance'] ?? 0);
    $adminStudentCount = (int)($adminBalanceMonitor['student_count'] ?? 0);
}
$adminOutstandingBalanceFull = (string)Helper::formatCurrency($adminOutstandingBalanceUgx, 'UGX', 0);
$absOutstanding = abs((float)$adminOutstandingBalanceUgx);
$adminOutstandingBalanceCompact = $adminOutstandingBalanceFull;
if ($absOutstanding >= 1000000000) {
    $adminOutstandingBalanceCompact = 'UGX ' . number_format($adminOutstandingBalanceUgx / 1000000000, 2) . 'B';
} elseif ($absOutstanding >= 1000000) {
    $adminOutstandingBalanceCompact = 'UGX ' . number_format($adminOutstandingBalanceUgx / 1000000, 2) . 'M';
} elseif ($absOutstanding >= 1000) {
    $adminOutstandingBalanceCompact = 'UGX ' . number_format($adminOutstandingBalanceUgx / 1000, 1) . 'K';
}

// Notifications (per-user + broadcast aware)
$currentUser = isset($currentUser) ? $currentUser : $auth->getCurrentUser();
$unreadNotifications = fetchUnreadNotificationsForUser($currentUser['id'], 10);

// Saved (archived) notifications for the current admin (dashboard widget)
// Ensure the archive table exists (safe to run multiple times)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS notification_archive (
        id INT PRIMARY KEY AUTO_INCREMENT,
        notification_id INT NULL,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        link VARCHAR(255) NULL,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // ignore - if creation fails, we'll handle on select
}

try {
    $savedStmt = $conn->prepare("SELECT * FROM notification_archive WHERE user_id = :uid ORDER BY archived_at DESC LIMIT 6");
    $savedStmt->execute(['uid' => $currentUser['id']]);
    $savedNotifications = $savedStmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist or other DB issue - degrade gracefully
    $savedNotifications = [];
}

$pageTitle = 'Admin Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar" style="width:24px;height:24px;padding:0;font-size:11px;">
                <i class="fas fa-bars" style="font-size:11px;line-height:1;"></i>
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
                        <div class="user-avatar" style="width: 35px; height: 35px; font-size: 14px; margin-bottom: 5px;">
                            <?php echo strtoupper(substr($currentUser['profile']['first_name'] ?? 'A', 0, 1) . substr($currentUser['profile']['last_name'] ?? 'D', 0, 1)); ?>
                        </div>
                        <div style="text-align: center;">
                            <strong><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></strong>
                            <br><small>Admin</small>
                        </div>
                        <i class="dropdown-arrow">&#9662;</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <div class="user-profile-meta">
                            <div class="user-fullname"><?php echo e($currentUser['profile']['first_name'] ?? ''); ?> <?php echo e($currentUser['profile']['last_name'] ?? ''); ?></div>
                            <?php if (!empty($currentUser['profile']['email'])): ?>
                                <div class="user-email"><i class="fas fa-envelope"></i> <?php echo e($currentUser['profile']['email']); ?></div>
                            <?php endif; ?>
                        </div>
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="<?php echo BASE_URL; ?>/views/admin/settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="dropdown-item logout-item">
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
        
        <!-- Enhanced Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon outstanding-icon"><i class="fas fa-balance-scale"></i></div>
                <div class="stat-details">
                    <h3 class="outstanding-value" title="<?php echo e($adminOutstandingBalanceFull); ?>"><?php echo e($adminOutstandingBalanceCompact); ?></h3>
                    <p>Total Outstanding Balance</p>
                    <div class="stat-change <?php echo $adminStudentsWithBalance > 0 ? 'warning' : 'positive'; ?>">
                        <i class="fas fa-<?php echo $adminStudentsWithBalance > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                        <span class="ticker-wrap" aria-label="Outstanding balance summary">
                            <span class="ticker-text balance-alert-text"><?php echo number_format((int)$adminStudentsWithBalance); ?> student(s) with balance</span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon students-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalStudents); ?></h3>
                    <p>Total Students</p>
                    <div class="stat-change positive"><i class="fas fa-arrow-up"></i> Active</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon lecturers-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalLecturers); ?></h3>
                    <p>Total Lecturers</p>
                    <div class="stat-change positive"><i class="fas fa-arrow-up"></i> Active</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon courses-icon"><i class="fas fa-book"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalCourses); ?></h3>
                    <p>Total Courses</p>
                    <div class="stat-change neutral"><i class="fas fa-minus"></i> Current</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon system-users-icon"><i class="fas fa-users"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalSystemUsers); ?></h3>
                    <p>System Users</p>
                    <div class="stat-change neutral system-users-breakdown">
                        <i class="fas fa-layer-group"></i>
                        <span class="role-pill">A: <?php echo (int)$systemUserRoleCounts['admin']; ?></span>
                        <span class="role-pill">L: <?php echo (int)$systemUserRoleCounts['lecturer']; ?></span>
                        <span class="role-pill">S: <?php echo (int)$systemUserRoleCounts['student']; ?></span>
                        <span class="role-pill">F: <?php echo (int)$systemUserRoleCounts['finance']; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon pending-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-details">
                    <h3><?php echo number_format($pendingRegistrations + $pendingResults); ?></h3>
                    <p>Pending Actions</p>
                    <div class="stat-change"><i class="fas fa-exclamation-circle"></i> Needs Review</div>
                </div>
            </div>

        </div>

        <!-- Quick Actions Section -->
        <div class="quick-actions-section">
            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            <div class="action-grid">
                <a href="users/add.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="action-copy">
                        <h4>Add User</h4>
                        <p>Create new admin, lecturer, or student account</p>
                    </div>
                </a>
                
                <a href="courses/list.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-list-alt"></i></div>
                    <div class="action-copy">
                        <h4>Manage Courses</h4>
                        <p>View, edit, and organize course curriculum</p>
                    </div>
                </a>
                
                <a href="results/submitted.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="action-copy">
                        <h4>Approve Results</h4>
                        <p><?php echo $pendingResults; ?> results awaiting approval</p>
                    </div>
                </a>
                
                <a href="students/list.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-users"></i></div>
                    <div class="action-copy">
                        <h4>View Students</h4>
                        <p>Browse and manage student records</p>
                    </div>
                </a>

                <a href="#student-balance-monitor" class="action-card">
                    <div class="action-icon"><i class="fas fa-balance-scale"></i></div>
                    <div class="action-copy">
                        <h4>Student Balances</h4>
                        <p>Monitor all student outstanding balances</p>
                    </div>
                </a>
            </div>
        </div>

        <div class="assigned-courses-section" id="student-balance-monitor">
            <div class="section-header">
                <h3><i class="fas fa-balance-scale"></i> Student Balance Monitor</h3>
                <span class="text-muted" style="font-size:12px;">
                    Academic Year:
                    <?php echo e($currentAcademicYearName ?: 'N/A'); ?>
                </span>
            </div>
            <?php if ($currentSemesterId <= 0): ?>
                <div class="alert alert-warning mb-0">
                    No active semester is configured, so student balance monitoring is currently unavailable.
                </div>
            <?php elseif (empty($adminStudentBalances)): ?>
                <div class="alert alert-info mb-0">
                    No active students found for balance monitoring.
                </div>
            <?php else: ?>
                <div class="mb-2" style="font-size:12px; color:#334155;">
                    <strong>Total Students:</strong> <?php echo number_format($adminStudentCount); ?>
                    &nbsp;|&nbsp;
                    <strong>Students with Outstanding:</strong> <?php echo number_format($adminStudentsWithBalance); ?>
                    &nbsp;|&nbsp;
                    <strong>Total Outstanding (UGX base):</strong>
                    <?php echo e(Helper::formatCurrency($adminOutstandingBalanceUgx, 'UGX', 0)); ?>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2" style="gap:8px;">
                    <div class="d-flex align-items-center" style="gap:8px;">
                        <input
                            type="text"
                            id="adminBalanceSearch"
                            class="form-control form-control-sm"
                            placeholder="Search student, ID, currency..."
                            style="min-width:240px; max-width:320px;"
                        >
                        <select id="adminBalancePageSize" class="form-control form-control-sm" style="width:auto;">
                            <option value="10" selected>10 / page</option>
                            <option value="25">25 / page</option>
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>
                        </select>
                    </div>
                    <small id="adminBalanceCountInfo" class="text-muted"></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm" id="adminBalanceTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Currency</th>
                                <th>Total Fees</th>
                                <th>Total Paid</th>
                                <th>Outstanding Balance</th>
                            </tr>
                        </thead>
                        <tbody id="adminBalanceTableBody">
                            <?php foreach ($adminStudentBalances as $balanceRow): ?>
                                <?php $displayCurrency = (string)($balanceRow['display_currency'] ?? 'UGX'); ?>
                                <tr>
                                    <td>
                                        <?php echo e(trim((string)$balanceRow['first_name'] . ' ' . (string)$balanceRow['last_name'])); ?><br>
                                        <small><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $balanceRow)); ?></small>
                                    </td>
                                    <td><?php echo e($displayCurrency); ?></td>
                                    <td><?php echo e(formatAmountFromUgxForDisplayCurrency((float)($balanceRow['total_fees'] ?? 0), $displayCurrency, $usdUgxRate)); ?></td>
                                    <td><?php echo e(formatAmountFromUgxForDisplayCurrency((float)($balanceRow['total_paid'] ?? 0), $displayCurrency, $usdUgxRate)); ?></td>
                                    <td><strong><?php echo e(formatAmountFromUgxForDisplayCurrency((float)($balanceRow['balance'] ?? 0), $displayCurrency, $usdUgxRate)); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end align-items-center mt-2" style="gap:8px;">
                    <button type="button" id="adminBalancePrev" class="btn btn-sm btn-outline-secondary">Previous</button>
                    <small id="adminBalancePageInfo" class="text-muted">Page 1 of 1</small>
                    <button type="button" id="adminBalanceNext" class="btn btn-sm btn-outline-secondary">Next</button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assigned Courses Section -->
        <div class="assigned-courses-section">
            <div class="section-header">
                <h3><i class="fas fa-graduation-cap"></i> Recent Courses</h3>
                <a href="courses/list.php" class="btn btn-sm btn-outline-primary">View All Courses</a>
            </div>
            <div class="assigned-courses-grid" data-rotate-courses="1" data-rotate-size="3" data-rotate-interval-ms="30000">
                <?php if (!empty($assignedCourses)): ?>
                    <?php foreach ($assignedCourses as $assignment): ?>
                        <div class="assignment-card">
                            <div class="assignment-header">
                                <div class="course-info">
                                    <h5><?php echo e($assignment['course_code']); ?></h5>
                                    <p><?php echo e($assignment['course_name']); ?></p>
                                    <small class="text-muted"><?php echo e($assignment['program_code'] . ' - ' . $assignment['program_name']); ?></small>
                                </div>
                                <div class="assignment-status">
                                    <span class="badge badge-<?php echo Helper::getStatusColor($assignment['status']); ?>">
                                        <?php echo e(ucfirst($assignment['status'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="assignment-details">
                                <div class="lecturer-info">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <strong><?php echo e($assignment['first_name'] . ' ' . $assignment['last_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo e($assignment['lecturer_id']); ?></small>
                                </div>
                                <div class="semester-info">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo e($assignment['year_name'] . ' - ' . $assignment['semester_name']); ?>
                                </div>
                                <div class="assignment-date">
                                    <i class="fas fa-clock"></i>
                                    <?php echo Helper::formatDate($assignment['assigned_date']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h4>No Course Assignments</h4>
                        <p>No courses have been assigned to lecturers yet.</p>
                        <a href="courses/assign.php" class="btn btn-primary">Assign First Course</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity & Sessions - Full Width, Collapsible -->
        <div class="row">
            <div class="col-md-7">
                <div class="recent-activity foldable-card">
                    <h3 class="foldable-header" data-target="activityBody">
                        <span><i class="fas fa-history"></i> Recent System Activity</span>
                        <i class="fas fa-chevron-up fold-arrow"></i>
                    </h3>
                    <div class="foldable-body" id="activityBody">
                        <div class="card-last-updated">
                            Last updated at <span id="activityLastUpdated"><?php echo date('H:i:s'); ?></span>
                        </div>
                        <div id="activityList">
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?php 
                                            switch($activity['action']) {
                                                case 'login': echo '<i class="fas fa-sign-in-alt"></i>'; break;
                                                case 'logout': echo '<i class="fas fa-sign-out-alt"></i>'; break;
                                                case 'create': echo '<i class="fas fa-plus"></i>'; break;
                                                case 'update': echo '<i class="fas fa-edit"></i>'; break;
                                                case 'delete': echo '<i class="fas fa-trash"></i>'; break;
                                                case 'approve': echo '<i class="fas fa-check"></i>'; break;
                                                case 'submit': echo '<i class="fas fa-paper-plane"></i>'; break;
                                                default: echo '<i class="fas fa-circle"></i>';
                                            }
                                            ?>
                                        </div>
                                        <div class="activity-details">
                                            <h5><?php echo e($activity['description']); ?></h5>
                                            <p>
                                                <i class="fas fa-clock"></i> 
                                                <span class="activity-time" title="<?php echo Helper::formatDateTime($activity['created_at'], 'M d, Y g:i:s A'); ?>">
                                                    <?php echo Helper::formatDateTime($activity['created_at'], 'g:i:s A'); ?>
                                                </span>
                                                <span class="activity-date"><?php echo Helper::formatDate($activity['created_at'], 'M d, Y'); ?></span>
                                                <span class="activity-module"><?php echo e($activity['module']); ?></span>
                                                <?php 
                                                    $activityActor = $activity['display_name'] ?? ($activity['username'] ?? null);
                                                    $activityRole = $activity['user_role'] ?? null;
                                                ?>
                                                <?php if (!empty($activityActor)): ?>
                                                    <span class="activity-user">
                                                        <i class="fas fa-user"></i> <?php echo e($activityActor); ?>
                                                        <?php if (!empty($activityRole)): ?>
                                                            <small class="text-muted">(<?php echo e(ucfirst((string)$activityRole)); ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h4>No Recent Activity</h4>
                                    <p>System activities will appear here as they occur.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-5">
                <!-- User Sessions Section -->
                <div class="user-sessions-card foldable-card">
                    <h3 class="foldable-header" data-target="sessionsBody">
                        <span><i class="fas fa-user-clock"></i> User Login Sessions</span>
                        <i class="fas fa-chevron-up fold-arrow"></i>
                    </h3>
                    <div class="foldable-body" id="sessionsBody">
                    <div class="card-last-updated">
                        Last updated at <span id="sessionsLastUpdated"><?php echo date('H:i:s'); ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="sessions-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Login Time</th>
                                    <th>Logout Time</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody id="sessionsTableBody">
                                <?php if (!empty($loginSessions)): ?>
                                    <?php foreach ($loginSessions as $session): ?>
                                        <tr>
                                            <td>
                                                <?php
                                                    $sessionActor = $session['display_name'] ?? ($session['username'] ?? 'Unknown');
                                                    $sessionRole = $session['user_role'] ?? null;
                                                ?>
                                                <span class="session-user">
                                                    <i class="fas fa-user"></i> <?php echo e($sessionActor); ?>
                                                    <?php if (!empty($sessionRole)): ?>
                                                        <small class="text-muted">(<?php echo e(ucfirst((string)$sessionRole)); ?>)</small>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="session-time login">
                                                    <i class="fas fa-sign-in-alt"></i>
                                                    <?php echo Helper::formatDateTime($session['login_time'], 'M d, g:i:s A'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($session['session_end_time'])): ?>
                                                    <?php if (($session['end_type'] ?? '') === 'superseded'): ?>
                                                        <span class="session-time auto-closed">
                                                            <i class="fas fa-exchange-alt"></i>
                                                            <?php echo Helper::formatDateTime($session['session_end_time'], 'M d, g:i:s A'); ?>
                                                            <small style="display:block; color:#856404;">Ended by next login</small>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="session-time logout">
                                                            <i class="fas fa-sign-out-alt"></i>
                                                            <?php echo Helper::formatDateTime($session['session_end_time'], 'M d, g:i:s A'); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="session-active">
                                                        <i class="fas fa-circle"></i> Active
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($session['session_duration_minutes'] !== null): ?>
                                                    <span class="session-duration">
                                                        <?php 
                                                        $mins = $session['session_duration_minutes'];
                                                        if ($mins < 60) {
                                                            echo $mins . ' min';
                                                        } else {
                                                            $hours = floor($mins / 60);
                                                            $remainMins = $mins % 60;
                                                            echo $hours . 'h ' . $remainMins . 'm';
                                                        }
                                                        ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="session-duration active">--</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No login sessions found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Info Row -->
        <div class="row mt-3">
            <div class="col-md-4">
                <div class="system-status">
                    <h3><i class="fas fa-server"></i> System Status</h3>
                    <div class="status-item">
                        <span><i class="fas fa-database"></i> Database</span>
                        <span class="status-indicator online"><i class="fas fa-check-circle"></i> Online</span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-calendar"></i> Academic Year</span>
                        <span class="status-indicator online academic-status-indicator">
                            <span class="primary" id="systemAcademicYearLabel"><?php echo e($currentAcademicYearName ?: 'Not Set'); ?></span>
                            <span class="secondary" id="systemAcademicStateLabel"><?php echo e($activeSemestersStatusText); ?></span>
                        </span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-clipboard-list"></i> Registrations</span>
                        <span class="status-indicator <?php echo $pendingRegistrations > 10 ? 'warning' : 'online'; ?>">
                            <?php echo $pendingRegistrations; ?> pending
                        </span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-chart-bar"></i> Results</span>
                        <span class="status-indicator <?php echo $pendingResults > 5 ? 'warning' : 'online'; ?>">
                            <?php echo $pendingResults; ?> pending
                        </span>
                    </div>
                    <div class="status-item">
                        <span><i class="fas fa-tachometer-alt"></i> System Load</span>
                        <span class="status-indicator online"><i class="fas fa-check-circle"></i> Normal</span>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="quick-links-card">
                    <h3><i class="fas fa-link"></i> Quick Links</h3>
                    <div class="quick-links-list">
                        <a href="system/health.php" class="quick-link-item">
                            <i class="fas fa-heartbeat"></i> System Health
                        </a>
                        <a href="students/list.php" class="quick-link-item">
                            <i class="fas fa-user-graduate"></i> View Students
                        </a>
                        <a href="lecturers/list.php" class="quick-link-item">
                            <i class="fas fa-chalkboard-teacher"></i> View Lecturers
                        </a>
                        <a href="courses/list.php" class="quick-link-item">
                            <i class="fas fa-book"></i> View Courses
                        </a>
                    </div>
                </div>
            </div>
                
            <div class="col-md-4">
                <!-- Current Academic Year -->
                <?php if ($currentSemester): ?>
                <div class="semester-card" tabindex="0" role="button" aria-label="Current intake - click to open intake selector">
                    <h3><i class="fas fa-calendar-alt"></i> Current Intake</h3>
                    <div class="activate-controls">
                        <form method="POST" style="display:flex;gap:8px;align-items:center;">
                            <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                            <select name="activate_semester_id" class="form-control form-control-sm" style="min-width:220px;">
                                <?php
                                    $ystmt = $conn->query("
                                        SELECT
                                            s.id AS semester_id,
                                            s.semester_number,
                                            s.start_date,
                                            s.end_date,
                                            s.status AS semester_status,
                                            s.academic_year_id,
                                            ay.year_name
                                        FROM semesters s
                                        INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                                        WHERE ay.start_date >= '2025-08-01'
                                          AND s.semester_number IN (1, 2)
                                        ORDER BY ay.start_date DESC, s.semester_number ASC, s.id DESC
                                    ");
                                    $semesterRows = $ystmt->fetchAll(PDO::FETCH_ASSOC);
                                    $selectedSemesterId = (int)($currentSemester['id'] ?? 0);
                                    foreach ($semesterRows as $y) {
                                        $activationSemesterId = (int)($y['semester_id'] ?? 0);
                                        if ($activationSemesterId <= 0) {
                                            continue;
                                        }

                                        $startLabel = !empty($y['start_date']) ? date('d F Y', strtotime((string)$y['start_date'])) : '-';
                                        $endLabel = !empty($y['end_date']) ? date('d F Y', strtotime((string)$y['end_date'])) : '-';
                                        $intakeTitle = ((int)($y['semester_number'] ?? 0) === 1) ? 'January' : 'August';
                                        $label = trim($y['year_name'] . ' ' . $intakeTitle . ' Semester 1 & Semester 2 (' . $startLabel . ' - ' . $endLabel . ')');
                                        $semesterStatus = strtolower((string)($y['semester_status'] ?? 'inactive')) === 'active' ? 'active' : 'inactive';
                                        $sel = ($selectedSemesterId > 0 && $selectedSemesterId === $activationSemesterId) ? 'selected' : '';

                                        echo "<option value=\"{$activationSemesterId}\" data-base-label=\"" . e($label) . "\" data-status=\"" . e($semesterStatus) . "\" data-academic-year-id=\"" . (int)$y['academic_year_id'] . "\" data-semester-number=\"" . (int)$y['semester_number'] . "\" {$sel}>" . e($label) . " - " . e(ucfirst($semesterStatus)) . "</option>";
                                    }
                                ?>
                            </select>
                            <button class="btn btn-sm btn-primary" type="submit">Activate</button>
                        </form>
                    </div>
                    <div class="semester-info">
                            <div class="semester-name"><?php echo e($currentAcademicYearName); ?></div>
                            <div class="semester-term"><?php echo e($currentSemesterTermLabel); ?></div>
                            <div class="semester-dates">
                                <span><i class="fas fa-calendar-plus"></i> <?php echo Helper::formatDate($currentContextStartDate); ?></span>
                                <span><i class="fas fa-calendar-check"></i> <?php echo Helper::formatDate($currentContextEndDate); ?></span>
                            </div>
                            <div class="semester-status">
                                <span class="badge badge-<?php echo Helper::getStatusColor($currentSemester['status'] ?? 'active'); ?>">
                                    <?php echo ucfirst($currentSemester['status'] ?? 'Active'); ?>
                                </span>
                            </div>


                        </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Saved / Archived Notifications (dashboard widget) -->
                <div class="saved-notifications-card">
                    <h3><i class="fas fa-bookmark"></i> Saved Notifications</h3>
                    <div class="saved-list">
                        <?php if (!empty($savedNotifications)): ?>
                            <?php foreach ($savedNotifications as $s): ?>
                                <div class="saved-item">
                                    <div class="saved-meta">
                                        <strong><?php echo e($s['title']); ?></strong>
                                        <span class="saved-time"><?php echo Helper::timeAgo($s['archived_at']); ?></span>
                                    </div>
                                    <div class="saved-body">
                                        <p><?php echo e(mb_substr(strip_tags($s['message']), 0, 120)); ?></p>
                                        <?php if (!empty($s['link'])): ?>
                                            <a href="<?php echo e($s['link']); ?>" class="btn btn-sm btn-link">Open</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state text-muted">No saved notifications</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right mt-2"><a href="notifications/archive.php" class="btn btn-sm btn-outline-primary">View all saved</a></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var card = document.querySelector('.semester-card');
    if (!card) return;
    var select = card.querySelector('select[name="activate_semester_id"]');
    var form = card.querySelector('form');
    var btn = form ? form.querySelector('button[type="submit"]') : null;

    function normalizeStatus(status) {
        var s = (status || '').toString().trim().toLowerCase();
        return s || 'inactive';
    }

    function statusLabel(status) {
        var s = normalizeStatus(status);
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    function optionBaseLabel(opt) {
        return opt.getAttribute('data-base-label') || opt.textContent.replace(/\s-\s(Active|Inactive|Completed|Pending|active|inactive|completed|pending)$/i, '').trim();
    }

    function optionStatus(opt) {
        var fromAttr = normalizeStatus(opt.getAttribute('data-status'));
        if (fromAttr) return fromAttr;
        var m = opt.textContent.match(/\s-\s(Active|Inactive|Completed|Pending)$/i);
        return m ? normalizeStatus(m[1]) : 'inactive';
    }

    function setOptionStatus(opt, status) {
        var normalized = normalizeStatus(status);
        opt.setAttribute('data-status', normalized);
        opt.textContent = optionBaseLabel(opt) + ' - ' + statusLabel(normalized);
    }

    function semesterIntakeLabel(semesterNumber) {
        var n = parseInt(semesterNumber, 10);
        if (n === 1) return 'January Intake';
        if (n === 2) return 'August Intake';
        return '';
    }

    function semesterTermLabel(sem) {
        if (!sem) return 'Current Semester';
        var intake = semesterIntakeLabel(sem.semester_number);
        return intake ? (intake + ' - Semester 1 & Semester 2') : 'Current Semester';
    }

    function activeStateLabel(sem) {
        if (!sem) return 'No active semester';
        return semesterIntakeLabel(sem.semester_number) + ' Active (Semester 1 & Semester 2)';
    }

    function semesterContextLabel(sem) {
        if (!sem) return 'Semester context';
        var year = (sem.year_name || '').toString().trim();
        var term = (semesterIntakeLabel(sem.semester_number) + ' Semester 1 & Semester 2').trim();
        return year ? (year + ' - ' + term) : term;
    }

    function refreshSemesterOptions(sel, activeId) {
        if (!sel) return;
        var active = parseInt(activeId, 10);
        Array.from(sel.options).forEach(function(opt) {
            var id = parseInt(opt.value, 10);
            var newStatus = (id === active) ? 'active' : 'inactive';
            setOptionStatus(opt, newStatus);
            opt.selected = id === active;
        });
    }

    function openSelector() {
        if (!select) return; try { select.focus(); select.click(); } catch(e){}
    }
    card.addEventListener('click', function(e){ if (e.target.closest('select')||e.target.closest('button')||e.target.closest('a')) return; openSelector(); });
    card.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' '||e.key==='Spacebar'){ e.preventDefault(); openSelector(); } });

    if (!form) return;
    form.addEventListener('submit', function(e){
        e.preventDefault(); if (!btn) return; btn.disabled = true;
        var fd = new FormData(form);
        fetch(window.location.pathname, { method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r=>r.json()).then(function(data){ btn.disabled=false; if (data && data.success){ var sem = data.semester||null; if (sem){ var nameEl=document.querySelector('.semester-name'); if (nameEl) nameEl.textContent = sem.year_name||nameEl.textContent; var termEl=document.querySelector('.semester-term'); if (termEl) termEl.textContent = semesterTermLabel(sem); var systemYearEl = document.getElementById('systemAcademicYearLabel'); if (systemYearEl) systemYearEl.textContent = sem.year_name || systemYearEl.textContent; var systemStateEl = document.getElementById('systemAcademicStateLabel'); if (systemStateEl) systemStateEl.textContent = activeStateLabel(sem); var spans=document.querySelectorAll('.semester-dates span'); function fmt(d){ try{ return new Date(d).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}); }catch(e){return d;} } if (spans[0]) spans[0].innerHTML = '<i class="fas fa-calendar-plus"></i> ' + fmt(sem.start_date); if (spans[1]) spans[1].innerHTML = '<i class="fas fa-calendar-check"></i> ' + fmt(sem.end_date); var badge=document.querySelector('.semester-status .badge'); if (badge){ var st=(sem.status||'').toLowerCase(); badge.textContent = (st.charAt(0).toUpperCase()+st.slice(1))||badge.textContent; badge.className = 'badge ' + (st==='active' ? 'badge-success' : 'badge-secondary'); } var sel = form.querySelector('select[name="activate_semester_id"]'); refreshSemesterOptions(sel, sem.id); }
            var existing = document.querySelector('.content-area .alert[data-auto-dismiss="false"]'); if (existing) existing.remove();

// Build distinct alert for activation/no-change
var a = document.createElement('div');
if (data && data.action === 'no_change') {
    a.className = 'alert alert-info';
    a.setAttribute('data-auto-dismiss', 'false');
    a.setAttribute('role','alert');
    a.innerHTML = '<strong>Info:</strong> ' + (data.message || 'No changes were made');
} else {
    a.className = 'alert alert-success';
    a.setAttribute('data-auto-dismiss', 'false');
    a.setAttribute('role','alert');

    var title = document.createElement('div');
    title.innerHTML = '<strong><i class="fas fa-check-circle"></i> Activated:</strong> ' + semesterContextLabel(data.semester);
    a.appendChild(title);

    if (data.previous) {
        var prev = document.createElement('div');
        prev.style.marginTop = '6px';
        prev.innerHTML = '<strong><i class="fas fa-times-circle"></i> Deactivated:</strong> ' + semesterContextLabel(data.previous);
        a.appendChild(prev);

        // add explicit Undo button to revert activation
        var undoWrap = document.createElement('div');
        undoWrap.style.marginTop = '8px';
        var undoBtn = document.createElement('button');
        undoBtn.className = 'btn btn-sm btn-outline-light undo-activation-btn';
        undoBtn.style.marginLeft = '6px';
        undoBtn.textContent = 'Undo';
        undoBtn.dataset.undoId = data.previous.id;
        undoWrap.appendChild(undoBtn);
        a.appendChild(undoWrap);

        // attach handler for Undo
        undoBtn.addEventListener('click', function(ev){
            var btn = this; btn.disabled = true;
            var undoId = btn.dataset.undoId;
            var tokenEl = document.querySelector('.semester-card form input[name="csrf_token"]');
            var csrf = tokenEl ? tokenEl.value : '';
            var fd2 = new FormData();
            fd2.append('undo_semester_id', undoId);
            fd2.append('csrf_token', csrf);

            fetch(window.location.pathname, { method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd2 })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res && res.success) {
                    var sem = res.semester || null;
                    // update semester card UI
                    if (sem) {
                        var nameEl = document.querySelector('.semester-name'); if (nameEl) nameEl.textContent = sem.year_name || nameEl.textContent;
                        var termEl = document.querySelector('.semester-term'); if (termEl) termEl.textContent = semesterTermLabel(sem);
                        var systemYearEl = document.getElementById('systemAcademicYearLabel'); if (systemYearEl) systemYearEl.textContent = sem.year_name || systemYearEl.textContent;
                        var systemStateEl = document.getElementById('systemAcademicStateLabel'); if (systemStateEl) systemStateEl.textContent = activeStateLabel(sem);
                        var spans = document.querySelectorAll('.semester-dates span');
                        function fmt(d){ try{ return new Date(d).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}); }catch(e){return d;} }
                        if (spans[0]) spans[0].innerHTML = '<i class="fas fa-calendar-plus"></i> ' + fmt(sem.start_date);
                        if (spans[1]) spans[1].innerHTML = '<i class="fas fa-calendar-check"></i> ' + fmt(sem.end_date);
                        var badge = document.querySelector('.semester-status .badge'); if (badge){ var st=(sem.status||'').toLowerCase(); badge.textContent = (st.charAt(0).toUpperCase()+st.slice(1))||badge.textContent; badge.className = 'badge ' + (st==='active' ? 'badge-success' : 'badge-secondary'); }
                        var sel = form.querySelector('select[name="activate_semester_id"]'); refreshSemesterOptions(sel, sem.id);
                    }

                    // replace alert content to show reverted state
                    a.className = 'alert alert-success';
                    a.innerHTML = '<strong><i class="fas fa-undo"></i> Reverted to:</strong> ' + semesterContextLabel(res.semester);
                } else {
                    var content = document.querySelector('.content-area');
                    var err = document.createElement('div'); err.className='alert alert-danger'; err.textContent = (res && res.error) ? res.error : 'Undo failed'; if (content) content.prepend(err);
                    btn.disabled = false;
                }
            })
            .catch(function(err){ btn.disabled = false; var content=document.querySelector('.content-area'); var errEl=document.createElement('div'); errEl.className='alert alert-danger'; errEl.textContent='Network error. Please try again.'; if (content) content.prepend(errEl); });
        });
    }

    // optional descriptive message
    var msg = document.createElement('div');
    msg.style.marginTop = '8px';
    msg.textContent = data.message || '';
    if (msg.textContent) a.appendChild(msg);
}

var content = document.querySelector('.content-area');
if (content) { content.prepend(a); a.scrollIntoView({behavior:'smooth', block:'center'}); a.setAttribute('tabindex','-1'); a.focus(); }
        } else { var msg=(data&&data.error)?data.error:'Activation failed'; var err=document.createElement('div'); err.className='alert alert-danger'; err.textContent = msg; var content=document.querySelector('.content-area'); if (content) content.prepend(err); } })
        .catch(function(err){ btn.disabled=false; var errEl=document.createElement('div'); errEl.className='alert alert-danger'; errEl.textContent='Network error. Please try again.'; var content=document.querySelector('.content-area'); if (content) content.prepend(errEl); });
    });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function initTableSearchPagination(config) {
        var tbody = document.getElementById(config.tbodyId);
        var searchInput = document.getElementById(config.searchId);
        var pageSizeSelect = document.getElementById(config.pageSizeId);
        var prevBtn = document.getElementById(config.prevId);
        var nextBtn = document.getElementById(config.nextId);
        var pageInfo = document.getElementById(config.pageInfoId);
        var countInfo = document.getElementById(config.countInfoId);
        if (!tbody || !searchInput || !pageSizeSelect || !prevBtn || !nextBtn || !pageInfo || !countInfo) {
            return;
        }

        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var currentPage = 1;

        function render() {
            var query = (searchInput.value || '').toLowerCase().trim();
            var pageSize = parseInt(pageSizeSelect.value, 10) || 10;

            var filteredRows = allRows.filter(function (row) {
                if (!query) return true;
                return (row.textContent || '').toLowerCase().indexOf(query) !== -1;
            });

            var totalRows = filteredRows.length;
            var totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            var startIdx = (currentPage - 1) * pageSize;
            var endIdx = startIdx + pageSize;

            allRows.forEach(function (row) { row.style.display = 'none'; });
            filteredRows.slice(startIdx, endIdx).forEach(function (row) { row.style.display = ''; });

            var from = totalRows === 0 ? 0 : (startIdx + 1);
            var to = totalRows === 0 ? 0 : Math.min(endIdx, totalRows);
            countInfo.textContent = totalRows === 0
                ? 'No matching students'
                : ('Showing ' + from + '-' + to + ' of ' + totalRows);

            pageInfo.textContent = totalRows === 0
                ? 'Page 0 of 0'
                : ('Page ' + currentPage + ' of ' + totalPages);
            prevBtn.disabled = currentPage <= 1 || totalRows === 0;
            nextBtn.disabled = currentPage >= totalPages || totalRows === 0;
        }

        searchInput.addEventListener('input', function () {
            currentPage = 1;
            render();
        });
        pageSizeSelect.addEventListener('change', function () {
            currentPage = 1;
            render();
        });
        prevBtn.addEventListener('click', function () {
            currentPage -= 1;
            render();
        });
        nextBtn.addEventListener('click', function () {
            currentPage += 1;
            render();
        });

        render();
    }

    initTableSearchPagination({
        tbodyId: 'adminBalanceTableBody',
        searchId: 'adminBalanceSearch',
        pageSizeId: 'adminBalancePageSize',
        prevId: 'adminBalancePrev',
        nextId: 'adminBalanceNext',
        pageInfoId: 'adminBalancePageInfo',
        countInfoId: 'adminBalanceCountInfo'
    });
});
</script>

<style>
/* Admin Dashboard Specific Styles */
.system-users-icon {
    color: #0ea5e9;
}

.outstanding-icon {
    color: #d97706;
}

/* Keep dashboard stats on one line on desktop without horizontal scroll */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
    align-items: stretch;
}

.stats-grid .stat-card {
    min-width: 0;
    padding: 10px 12px;
    min-height: 86px;
    height: auto;
    display: flex;
    align-items: center;
    gap: 10px;
}

.stats-grid .stat-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    font-size: 16px;
    margin: 0;
    min-height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
}

.stats-grid .stat-details {
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
}

.stats-grid .stat-details h3 {
    font-size: clamp(14px, 1.1vw, 22px);
    line-height: 1.05;
    margin: 0;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stats-grid .stat-details h3.outstanding-value {
    font-size: clamp(12px, 0.95vw, 18px);
}

.stats-grid .stat-details p {
    font-size: 11px;
    line-height: 1.2;
    margin: 0;
    font-weight: 700;
    color: #000;
}

html[data-theme='dark'] .stats-grid .stat-details p {
    color: #fff;
}

.stats-grid .stat-change {
    font-size: 10px;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
    min-height: 0;
    display: flex;
    align-items: center;
    gap: 4px;
    max-width: 100%;
}

.stats-grid .ticker-wrap {
    position: relative;
    display: inline-block;
    overflow: hidden;
    max-width: 100%;
    white-space: nowrap;
    vertical-align: middle;
}

.stats-grid .ticker-text {
    display: inline-block;
    padding-left: 100%;
    animation: statTickerLoop 12s linear infinite;
    will-change: transform;
}

.stats-grid .ticker-text.balance-alert-text {
    color: #dc3545;
}

html[data-theme='dark'] .stats-grid .ticker-text.balance-alert-text {
    color: #dc3545 !important;
}

.stats-grid .ticker-wrap:hover .ticker-text {
    animation-play-state: paused;
}

@keyframes statTickerLoop {
    0% { transform: translateX(0); }
    100% { transform: translateX(-100%); }
}

@media (prefers-reduced-motion: reduce) {
    .stats-grid .ticker-text {
        animation: none;
        padding-left: 0;
    }
}

.stats-grid .system-users-breakdown {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 3px;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    min-height: 0;
    background: transparent !important;
    padding: 0;
}

.stats-grid .system-users-breakdown .role-pill {
    display: inline-flex;
    align-items: center;
    padding: 1px 4px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #334155;
    font-weight: 600;
    line-height: 1.05;
    font-size: 10px;
}

html[data-theme='dark'] .stats-grid .system-users-breakdown .role-pill {
    background: #1f2937;
    color: #e2e8f0;
    border: 1px solid #334155;
}

html[data-theme='dark'] .stats-grid .system-users-breakdown i {
    color: #93c5fd;
}

/* Quick Actions: compact, aligned, responsive */
.quick-actions-section .action-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.quick-actions-section .action-card {
    min-width: 0;
    min-height: 88px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
}

.quick-actions-section .action-card .action-icon {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    margin: 0;
    border-radius: 9px;
    font-size: 15px;
}

.quick-actions-section .action-card .action-copy {
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
}

.quick-actions-section .action-card h4 {
    margin: 0;
    font-size: 14px;
    line-height: 1.2;
}

.quick-actions-section .action-card p {
    margin: 0;
    font-size: 11px;
    line-height: 1.3;
    color: #64748b;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

html[data-theme='dark'] .quick-actions-section .action-card h4 {
    color: #f8fafc !important;
}

html[data-theme='dark'] .quick-actions-section .action-card p {
    color: #cbd5e1 !important;
}

.quick-links-card,
.semester-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    margin-bottom: 16px;
}

.quick-links-card h3,
.semester-card h3 {
    font-size: 14px;
    font-weight: 600;
    color: #1a1a2e;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.quick-links-card h3 i,
.semester-card h3 i {
    color: #667eea;
}

.quick-links-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.quick-link-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
}

/* Saved notifications widget */
.saved-notifications-card {
    background: white;
    border-radius: 12px;
    padding: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    margin-bottom: 16px;
    min-height: 180px;
}
.saved-notifications-card h3 { font-size:14px; font-weight:600; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.saved-list { display:flex; flex-direction:column; gap:10px; max-height:260px; overflow:auto; }
.saved-item { padding:8px; border-radius:8px; border:1px solid #f2f2f2; background:#fff; display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
.saved-meta strong { display:block; font-size:13px; }
.saved-meta .saved-time { color:#888; font-size:12px; }
.saved-body p { margin:6px 0 0; font-size:13px; color:#444; }
.saved-body .btn-link { padding:0; font-size:12px; }

/* Quick-link appearance */
.quick-link-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 8px;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s;
    font-size: 13px;
    font-weight: 500;
}

/* Semester card layout: keep all controls/content inside card boundaries */
.semester-card {
    position: relative;
    overflow: hidden;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
}

.activate-controls {
    position: static;
    width: 100%;
    min-width: 0;
}

.activate-controls form {
    display: flex;
    align-items: stretch;
    gap: 8px;
    width: 100%;
    min-width: 0;
    margin: 0;
}

.semester-card select[name="activate_semester_id"] {
    flex: 1 1 auto;
    min-width: 0 !important;
    max-width: 100%;
    width: 100%;
}

.activate-controls button {
    flex: 0 0 auto;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .activate-controls form {
        flex-direction: column;
    }
    .activate-controls button {
        width: 100%;
    }
}

.quick-link-item:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    transform: translateX(5px);
    text-decoration: none;
}

.quick-link-item i {
    width: 20px;
    text-align: center;
}

.semester-info {
    display: flex;
    flex-direction: column;
    gap: 10px;
    text-align: left;
    width: 100%;
    min-width: 0;
}

.semester-name {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a2e;
    line-height: 1.2;
    margin: 0;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.semester-term {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    line-height: 1.35;
    margin: 0;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.semester-dates {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    font-size: 12px;
    color: #6c757d;
    margin: 0;
    min-width: 0;
}

.semester-dates span {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    max-width: 100%;
    padding: 7px 8px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.semester-dates span i {
    flex: 0 0 auto;
}

.semester-status {
    display: flex;
    align-items: center;
    justify-content: flex-start;
}

.semester-status .badge {
    padding: 6px 14px;
    font-size: 12px;
    border-radius: 999px;
    max-width: 100%;
}

html[data-theme='dark'] .semester-dates span {
    background: rgba(148, 163, 184, 0.14);
    border-color: rgba(148, 163, 184, 0.28);
    color: #dbeafe;
}

.badge-active, .badge-success {
    background: #28a745;
    color: white;
}

/* Activity Items Styling */
.activity-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e4102f, #c60f28);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 12px;
}

.activity-details {
    min-width: 0;
    flex: 1 1 auto;
}

.activity-details h5 {
    margin: 0 0 3px;
    font-size: 13px;
    font-weight: 600;
    color: #1a1a2e;
    line-height: 1.35;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.activity-details p {
    margin: 0;
    font-size: 11px;
    color: #6c757d;
    display: flex;
    align-items: center;
    column-gap: 10px;
    row-gap: 6px;
    flex-wrap: wrap;
    min-width: 0;
}

.activity-module {
    background: #e9ecef;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.activity-user {
    color: #e4102f;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.3;
}

.activity-user small {
    font-size: 10px;
    line-height: 1.2;
}

.activity-time {
    font-weight: 600;
    color: #1a1a2e;
    font-family: 'Consolas', 'Monaco', monospace;
}

.activity-date {
    background: #f8f9fa;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    color: #6c757d;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 30px 16px;
    color: #6c757d;
}

.empty-state i {
    font-size: 36px;
    margin-bottom: 10px;
    color: #dee2e6;
}

.empty-state h4 {
    margin: 0 0 6px;
    color: #495057;
    font-size: 14px;
}

.empty-state p {
    margin: 0;
    font-size: 12px;
}

/* User Sessions Card */
.user-sessions-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    min-height: 300px;
}

.user-sessions-card h3 {
    margin: 0 0 0 0;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sessions-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.sessions-table th,
.sessions-table td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
    vertical-align: top;
    line-height: 1.35;
}

.sessions-table th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
}

.sessions-table th:nth-child(1),
.sessions-table td:nth-child(1) { width: 30%; }

.sessions-table th:nth-child(2),
.sessions-table td:nth-child(2) { width: 24%; }

.sessions-table th:nth-child(3),
.sessions-table td:nth-child(3) { width: 24%; }

.sessions-table th:nth-child(4),
.sessions-table td:nth-child(4) { width: 22%; }

.session-user {
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 8px;
    color: #1a1a2e;
    font-weight: 500;
    min-width: 0;
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.3;
}

.session-user i {
    margin-top: 2px;
    flex-shrink: 0;
}

.session-user small {
    font-size: 10px;
    line-height: 1.2;
}

.session-time {
    display: inline-flex;
    align-items: flex-start;
    flex-wrap: wrap;
    white-space: normal;
    gap: 6px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 12px;
}

.session-time i {
    margin-top: 2px;
}

.session-time.login {
    color: #28a745;
}

.session-time.logout {
    color: #dc3545;
}

.session-time.auto-closed {
    color: #856404;
}

.card-last-updated {
    text-align: right;
    font-size: 11px;
    color: #6c757d;
    margin-bottom: 10px;
    font-weight: 500;
}

.session-active {
    display: inline-flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 5px;
    color: #28a745;
    font-weight: 600;
    font-size: 12px;
}

.session-active i {
    font-size: 8px;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

.session-duration {
    background: #e9ecef;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    color: #495057;
}

.session-duration.active {
    background: transparent;
    color: #adb5bd;
}

/* System Status */
.system-status {
    background: white;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

.system-status h3 {
    margin: 0 0 14px;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.status-item:last-child {
    border-bottom: none;
}

.status-item span:first-child {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #495057;
    font-size: 12px;
}

.status-indicator {
    font-size: 11px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
}

.status-indicator.online {
    color: #28a745;
}

.status-indicator.warning {
    color: #ffc107;
}

.status-indicator.offline {
    color: #dc3545;
}

.academic-status-indicator {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    text-align: right;
    line-height: 1.25;
    max-width: 62%;
}

.academic-status-indicator .primary {
    font-size: 11px;
    font-weight: 700;
    color: inherit;
    white-space: nowrap;
}

.academic-status-indicator .secondary {
    font-size: 10px;
    font-weight: 600;
    color: inherit;
    white-space: normal;
}

/* Recent Activity Card */
.recent-activity {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    min-height: 300px;
}

.recent-activity h3 {
    margin: 0 0 0 0;
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Responsive fixes */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .stats-grid .stat-card {
        min-height: 80px;
        padding: 9px 10px;
    }

    .stats-grid .stat-details h3 {
        font-size: 20px;
    }

    .quick-actions-section .action-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 992px) {
    .col-md-8, .col-md-4 {
        flex: 0 0 100%;
        max-width: 100%;
    }

    .col-md-7, .col-md-5 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .stats-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .stats-grid .stat-card {
        min-height: 74px;
        padding: 8px 10px;
        gap: 8px;
    }

    .stats-grid .stat-icon {
        width: 32px;
        height: 32px;
        flex-basis: 32px;
    }

    .quick-actions-section .action-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 10px;
    }

    .quick-actions-section .action-card {
        min-height: 78px;
        padding: 9px 10px;
        gap: 8px;
    }
    
    .action-cards {
        grid-template-columns: 1fr;
    }

    .semester-card {
        padding: 12px;
    }

    .semester-dates {
        grid-template-columns: 1fr;
    }

    .status-item {
        align-items: flex-start;
        gap: 10px;
    }

    .academic-status-indicator {
        align-items: flex-start;
        text-align: left;
        max-width: 100%;
    }
}

/* Foldable Card Styles */
.foldable-card {
    min-height: 60px;
}

.foldable-header {
    cursor: pointer;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0 !important;
    padding-bottom: 12px;
    border-bottom: 2px solid #f0f0f0;
    transition: margin 0.3s;
}

.foldable-header span {
    display: flex;
    align-items: center;
    gap: 8px;
}

.foldable-header:hover {
    color: #667eea;
}

.fold-arrow {
    font-size: 12px;
    color: #999;
    transition: transform 0.3s ease;
}

.foldable-header.collapsed .fold-arrow {
    transform: rotate(180deg);
}

.foldable-header.collapsed {
    border-bottom-color: transparent;
    margin-bottom: 0 !important;
    padding-bottom: 0;
}

.foldable-body {
    overflow: hidden;
    max-height: 2000px;
    transition: max-height 0.4s ease, opacity 0.3s ease, padding 0.3s ease;
    opacity: 1;
    padding-top: 10px;
}

/* Add scrolling for activity section */
.recent-activity .foldable-body {
    max-height: 400px;
    overflow-y: auto;
    overflow-x: hidden;
}

.recent-activity .foldable-body::-webkit-scrollbar {
    width: 6px;
}

.recent-activity .foldable-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.recent-activity .foldable-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.recent-activity .foldable-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Add scrolling for sessions section */
.user-sessions-card .foldable-body {
    max-height: 350px;
    overflow-y: auto;
    overflow-x: hidden;
}

.user-sessions-card .table-responsive {
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
}

.user-sessions-card .foldable-body::-webkit-scrollbar {
    width: 6px;
}

.user-sessions-card .foldable-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.user-sessions-card .foldable-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.user-sessions-card .foldable-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.foldable-body.folded {
    max-height: 0;
    opacity: 0;
    padding-top: 0;
}

/* Assigned Courses Section */
.assigned-courses-section {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.section-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #1a1a2e;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-header h3 i {
    color: #667eea;
}

.assigned-courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
}

.assignment-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 16px;
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    position: relative;
}

.assignment-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-color: #667eea;
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.course-info h5 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 600;
    color: #1a1a2e;
}

.course-info p {
    margin: 0 0 4px 0;
    font-size: 13px;
    color: #495057;
    font-weight: 500;
}

.course-info small {
    color: #6c757d;
    font-size: 11px;
}

.assignment-status {
    flex-shrink: 0;
}

.assignment-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.lecturer-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #495057;
}

.lecturer-info i {
    color: #28a745;
    width: 14px;
}

.lecturer-info strong {
    color: #1a1a2e;
}

.assignment-details .semester-info,
.assignment-date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #6c757d;
}

.assignment-details .semester-info i,
.assignment-date i {
    width: 12px;
    color: #667eea;
}

/* Responsive adjustments for assigned courses */
@media (max-width: 768px) {
    .assigned-courses-grid {
        grid-template-columns: 1fr;
    }

    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .assignment-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .sessions-table th,
    .sessions-table td {
        padding: 8px 6px;
        font-size: 11px;
    }

    .sessions-table th:nth-child(1),
    .sessions-table td:nth-child(1) { width: 32%; }

    .sessions-table th:nth-child(2),
    .sessions-table td:nth-child(2) { width: 26%; }

    .sessions-table th:nth-child(3),
    .sessions-table td:nth-child(3) { width: 26%; }

    .sessions-table th:nth-child(4),
    .sessions-table td:nth-child(4) { width: 16%; }
}

@media (max-width: 767.98px) {
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
    }

    .topbar {
        min-height: 48px !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center !important;
        column-gap: 6px !important;
        padding: 6px 8px !important;
        overflow: visible !important;
    }

    .topbar-left {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        gap: 6px !important;
        overflow: hidden !important;
        width: auto !important;
        max-width: none !important;
    }

    .topbar-left h4 {
        max-width: 54vw !important;
        font-size: 0.9rem !important;
        line-height: 1.2 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .topbar-right {
        flex: 0 0 auto !important;
        width: auto !important;
        max-width: none !important;
        margin-left: auto !important;
        gap: 4px !important;
        flex-wrap: nowrap !important;
        justify-self: end !important;
        overflow: visible !important;
    }

    .sidebar.active {
        left: 0 !important;
        transform: translateX(0) !important;
        visibility: visible !important;
        display: flex !important;
    }

    .topbar-time {
        display: none !important;
    }

    .user-dropdown-toggle {
        padding: 2px 4px !important;
    }

    .user-dropdown-toggle > div:not(.user-avatar),
    .user-dropdown-toggle .dropdown-arrow {
        display: none !important;
    }

    .user-avatar {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        margin: 0 !important;
    }

    .content-area {
        padding: 10px !important;
    }

    .stats-grid {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 10px !important;
    }

    .stats-grid .stat-card {
        min-height: 70px !important;
        padding: 10px !important;
        gap: 8px !important;
    }

    .stats-grid .stat-icon {
        width: 32px !important;
        height: 32px !important;
        flex: 0 0 32px !important;
        font-size: 1rem !important;
    }

    .stats-grid .stat-details h3 {
        font-size: 1.05rem !important;
    }

    .stats-grid .stat-details p,
    .stats-grid .stat-change {
        font-size: 0.72rem !important;
    }

    .quick-actions-section .action-grid,
    .assigned-courses-grid {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 10px !important;
    }

    .quick-actions-section .action-card,
    .assigned-courses-section,
    .recent-activity,
    .system-status {
        width: 100% !important;
        max-width: 100% !important;
        padding: 12px !important;
        border-radius: 10px !important;
        overflow: hidden;
    }

    .section-header {
        flex-direction: row !important;
        align-items: flex-start !important;
        flex-wrap: wrap !important;
        gap: 8px !important;
        margin-bottom: 12px !important;
        padding-bottom: 10px !important;
    }

    .section-header h3,
    .quick-actions-section h3,
    .recent-activity h3 {
        font-size: 1rem !important;
        line-height: 1.2 !important;
    }

    #adminBalanceSearch {
        min-width: 0 !important;
        width: 100% !important;
    }

    #adminBalancePageSize {
        width: 100% !important;
    }

    .table-responsive {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        border-radius: 10px;
    }

    .table-responsive table {
        min-width: 620px;
        font-size: 0.78rem !important;
    }
}

@media (max-width: 430px) {
    .topbar-left h4 {
        max-width: 58vw !important;
        font-size: 0.84rem !important;
    }

    .topbar-right {
        max-width: none !important;
    }
}
</style>

<script>
// Rotate Recent Courses based on available screen size:
// - Large screens: show 3 cards
// - Medium screens: show 2 cards
// - Mobile: show 1 card
(function() {
    var grid = document.querySelector('.assigned-courses-grid[data-rotate-courses="1"]');
    if (!grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.assignment-card'));
    if (!cards.length) return;

    var intervalMs = parseInt(grid.getAttribute('data-rotate-interval-ms') || '30000', 10);
    if (!intervalMs || intervalMs < 1000) intervalMs = 30000;

    var pages = [];
    var currentPage = 0;
    var rotationTimer = null;

    function showPage(pageIndex) {
        for (var x = 0; x < cards.length; x++) {
            cards[x].style.display = 'none';
        }

        var page = pages[pageIndex] || [];
        for (var y = 0; y < page.length; y++) {
            page[y].style.display = '';
        }
    }

    function getPageSize() {
        if (window.innerWidth <= 768) return 1;
        if (window.innerWidth >= 1200) return 3;
        return 2;
    }

    function buildPages() {
        var pageSize = getPageSize();
        pages = [];
        for (var i = 0; i < cards.length; i += pageSize) {
            pages.push(cards.slice(i, i + pageSize));
        }
        if (currentPage >= pages.length) {
            currentPage = 0;
        }
    }

    function restartRotation() {
        if (rotationTimer) {
            clearInterval(rotationTimer);
            rotationTimer = null;
        }
        if (pages.length <= 1) {
            return;
        }
        rotationTimer = setInterval(function() {
            currentPage = (currentPage + 1) % pages.length;
            showPage(currentPage);
        }, intervalMs);
    }

    function refreshCourseRotationLayout() {
        buildPages();
        showPage(currentPage);
        restartRotation();
    }

    refreshCourseRotationLayout();

    var resizeTimer = null;
    window.addEventListener('resize', function() {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refreshCourseRotationLayout, 140);
    });

    var sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            setTimeout(refreshCourseRotationLayout, 120);
        });
    }

    window.addEventListener('beforeunload', function() {
        if (rotationTimer) clearInterval(rotationTimer);
    });
})();

// Foldable card toggle
document.querySelectorAll('.foldable-header').forEach(function(header) {
    var targetId = header.getAttribute('data-target');
    var body = document.getElementById(targetId);
    // Restore state from localStorage (default: collapsed)
    var key = 'fold_' + targetId;
    var state = localStorage.getItem(key);
    if (state !== 'expanded') {
        header.classList.add('collapsed');
        body.classList.add('folded');
    }
    header.addEventListener('click', function() {
        header.classList.toggle('collapsed');
        body.classList.toggle('folded');
        localStorage.setItem(key, header.classList.contains('collapsed') ? 'collapsed' : 'expanded');
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
<?php
/**
 * Complete Admin Notification System - All-in-One File
 * 
 * USAGE:
 * 1. Include this file in your admin header: require_once 'admin-notifications-complete.php';
 * 2. Display the bell: echo renderAdminNotificationBell();
 * 3. Create notifications: createAdminNotification() or notifyAllAdmins()
 * 
 * FEATURES:
 * - Automatic database table creation
 * - Bell icon with badge
 * - Mark as read functionality
 * - API endpoints built-in
 * - Helper functions included
 */

// ============================================================================
// CONFIGURATION
// ============================================================================
if (!defined('BASE_URL')) {
    define('BASE_URL', '/smns');
}

// ============================================================================
// HANDLE API REQUESTS (Must be at the top before any output)
// ============================================================================
if (isset($_GET['notification_action'])) {
    handleNotificationAPI();
    exit;
}

// ============================================================================
// DATABASE SETUP & NOTIFICATION FETCHING
// ============================================================================
function setupNotificationsTable() {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        if (function_exists('ensureNotificationsTable')) {
            ensureNotificationsTable($conn);
        } else {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    user_type VARCHAR(20) NULL DEFAULT NULL,
                    type VARCHAR(50) NOT NULL DEFAULT 'info',
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    link VARCHAR(500) NULL,
                    read_status VARCHAR(20) NOT NULL DEFAULT 'unread',
                    is_read TINYINT(1) NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    read_at TIMESTAMP NULL,
                    INDEX idx_user (user_id),
                    INDEX idx_read_status (read_status),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error setting up notifications table: " . $e->getMessage());
        return false;
    }
}

function fetchAdminNotifications($adminId = null) {
    try {
        // Setup table if it doesn't exist
        setupNotificationsTable();
        
        if ($adminId === null) {
            $adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
        }
        
        if ($adminId <= 0) {
            return [];
        }
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            AND COALESCE(read_status, '') <> 'read'
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute(['user_id' => $adminId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// NOTIFICATION CREATION FUNCTIONS
// ============================================================================

/**
 * Create notification for specific admin
 */
function createAdminNotification($adminId, $type, $title, $message, $link = null) {
    try {
        setupNotificationsTable();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at, read_status) 
            VALUES (:user_id, :type, :title, :message, :link, NOW(), 'unread')
        ");
        
        return $stmt->execute([
            'user_id' => $adminId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link
        ]);
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all active admins
 */
function notifyAllAdmins($type, $title, $message, $link = null) {
    try {
        setupNotificationsTable();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Get all active admin IDs
        $stmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($admins)) {
            return false;
        }
        
        $insertStmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at, read_status) 
            VALUES (:user_id, :type, :title, :message, :link, NOW(), 'unread')
        ");
        
        foreach ($admins as $adminId) {
            $insertStmt->execute([
                'user_id' => $adminId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error notifying all admins: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// API HANDLER
// ============================================================================
function handleNotificationAPI() {
    header('Content-Type: application/json');
    
    // Check authentication
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $action = $_GET['notification_action'] ?? '';
    $adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        switch ($action) {
            case 'mark_read':
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                $notifId = intval($data['notification_id'] ?? 0);
                
                if ($notifId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE notifications 
                        SET read_status = 'read', read_at = NOW(), is_read = 1 
                        WHERE id = :id AND user_id = :user_id
                    ");
                    $stmt->execute(['id' => $notifId, 'user_id' => $adminId]);
                    
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                }
                break;
                
            case 'mark_all_read':
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET read_status = 'read', read_at = NOW(), is_read = 1 
                    WHERE user_id = :user_id AND COALESCE(read_status, '') <> 'read'
                ");
                $stmt->execute(['user_id' => $adminId]);
                
                echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("Notification API error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
}

// ============================================================================
// RENDER NOTIFICATION BELL
// ============================================================================
function renderAdminNotificationBell() {
    $notifications = fetchAdminNotifications();
    $count = count($notifications);
    $currentFile = basename($_SERVER['PHP_SELF']);
    
    ob_start();
    ?>
    
    <!-- Admin Notification Bell -->
    <div class="notification-wrapper">
        <button class="notification-bell" id="adminNotificationBell" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php if ($count > 0): ?>
                <span class="notification-badge"><?php echo $count; ?></span>
            <?php endif; ?>
        </button>
        
        <div class="notification-dropdown" id="adminNotificationDropdown">
            <div class="notification-header">
                <h6>Notifications</h6>
                <?php if ($count > 0): ?>
                    <a href="#" id="adminMarkAllRead">Mark all read</a>
                <?php endif; ?>
            </div>
            <div class="notification-list">
                <?php if ($count > 0): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>" 
                           class="notification-item unread" 
                           data-id="<?php echo $notif['id']; ?>"
                           onclick="markNotificationRead(<?php echo $notif['id']; ?>)">
                            <div class="notif-icon notif-<?php echo htmlspecialchars($notif['type']); ?>">
                                <?php
                                $iconMap = [
                                    'info' => 'info-circle',
                                    'success' => 'check-circle',
                                    'warning' => 'exclamation-triangle',
                                    'error' => 'times-circle',
                                    'student' => 'user-graduate',
                                    'finance' => 'dollar-sign',
                                    'system' => 'cog'
                                ];
                                $icon = $iconMap[$notif['type']] ?? 'bell';
                                ?>
                                <i class="fas fa-<?php echo $icon; ?>"></i>
                            </div>
                            <div class="notif-content">
                                <p class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                <p class="notif-text"><?php echo htmlspecialchars($notif['message']); ?></p>
                                <span class="notif-time"><?php echo Helper::timeAgo($notif['created_at']); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notification-empty">
                        <i class="fas fa-bell-slash"></i>
                        <p>No new notifications</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
    .notification-wrapper {
        position: relative;
        display: inline-block;
        margin-left: 15px;
    }
    .notification-bell {
        background: transparent;
        border: none;
        color: #fff;
        font-size: 20px;
        cursor: pointer;
        position: relative;
        padding: 8px 12px;
        transition: all 0.3s;
    }
    .notification-bell:hover {
        transform: scale(1.1);
        color: #ffc107;
    }
    .notification-badge {
        position: absolute;
        top: 2px;
        right: 2px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        padding: 2px 6px;
        font-size: 10px;
        font-weight: bold;
        min-width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }
    .notification-dropdown {
        position: absolute;
        top: 45px;
        right: 0;
        width: 360px;
        max-height: 500px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        display: none;
        z-index: 1000;
        overflow: hidden;
    }
    .notification-dropdown.show {
        display: block;
        animation: slideDown 0.3s ease;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .notification-header {
        padding: 15px;
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .notification-header h6 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }
    .notification-header a {
        font-size: 13px;
        color: #007bff;
        text-decoration: none;
    }
    .notification-header a:hover {
        text-decoration: underline;
    }
    .notification-list {
        max-height: 400px;
        overflow-y: auto;
    }
    .notification-item {
        display: flex;
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        text-decoration: none;
        color: inherit;
        transition: background 0.2s;
    }
    .notification-item:hover {
        background: #f8f9fa;
    }
    .notification-item.unread {
        background: #e7f3ff;
    }
    .notification-item.unread:hover {
        background: #d1e7ff;
    }
    .notif-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        margin-right: 12px;
        flex-shrink: 0;
    }
    .notif-icon.notif-info { background: #e7f3ff; color: #007bff; }
    .notif-icon.notif-success { background: #d4edda; color: #28a745; }
    .notif-icon.notif-warning { background: #fff3cd; color: #ffc107; }
    .notif-icon.notif-error { background: #f8d7da; color: #dc3545; }
    .notif-icon.notif-student { background: #e7f3ff; color: #17a2b8; }
    .notif-icon.notif-finance { background: #d4edda; color: #28a745; }
    .notif-icon.notif-system { background: #e2e3e5; color: #6c757d; }
    .notif-content {
        flex: 1;
    }
    .notif-title {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 600;
        color: #333;
    }
    .notif-text {
        margin: 0 0 4px 0;
        font-size: 13px;
        color: #666;
        line-height: 1.4;
    }
    .notif-time {
        font-size: 11px;
        color: #999;
    }
    .notification-empty {
        text-align: center;
        padding: 40px 20px;
        color: #999;
    }
    .notification-empty i {
        font-size: 48px;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    .notification-empty p {
        margin: 0;
        font-size: 14px;
    }
    </style>

    <script>
    (function() {
        const bell = document.getElementById('adminNotificationBell');
        const dropdown = document.getElementById('adminNotificationDropdown');
        const markAllBtn = document.getElementById('adminMarkAllRead');
        
        if (!bell || !dropdown) return;
        
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== bell) {
                dropdown.classList.remove('show');
            }
        });
        
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>?notification_action=mark_all_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                        const badge = bell.querySelector('.notification-badge');
                        if (badge) badge.remove();
                        markAllBtn.style.display = 'none';
                    }
                });
            });
        }
    })();

    function markNotificationRead(notifId) {
        fetch('<?php echo $_SERVER['PHP_SELF']; ?>?notification_action=mark_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ notification_id: notifId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    const count = parseInt(badge.textContent) - 1;
                    if (count <= 0) badge.remove();
                    else badge.textContent = count;
                }
            }
        });
    }

    // Auto-refresh recent activities and login sessions
    let activityRefreshInterval;
    let sessionsRefreshInterval;

    function getCurrentTimeLabel() {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');
        return hh + ':' + mm + ':' + ss;
    }

    function setLastUpdated(labelId) {
        const el = document.getElementById(labelId);
        if (el) {
            el.textContent = getCurrentTimeLabel();
        }
    }

    function refreshRecentActivities() {
        const activityList = document.getElementById('activityList');
        if (!activityList) return;

        // Show loading indicator
        const originalContent = activityList.innerHTML;
        activityList.innerHTML = '<div class="text-center" style="padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Refreshing...</div>';

        fetch('<?php echo BASE_URL; ?>/api/activities.php?limit=10')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.activities && data.activities.length > 0) {
                    let html = '';
                    data.activities.forEach(activity => {
                        let icon = '';
                        switch(activity.action) {
                            case 'login': icon = '<i class="fas fa-sign-in-alt"></i>'; break;
                            case 'logout': icon = '<i class="fas fa-sign-out-alt"></i>'; break;
                            case 'create': icon = '<i class="fas fa-plus"></i>'; break;
                            case 'update': icon = '<i class="fas fa-edit"></i>'; break;
                            case 'delete': icon = '<i class="fas fa-trash"></i>'; break;
                            case 'approve': icon = '<i class="fas fa-check"></i>'; break;
                            case 'submit': icon = '<i class="fas fa-paper-plane"></i>'; break;
                            default: icon = '<i class="fas fa-circle"></i>';
                        }

                        const description = activity.description || 'Unknown activity';
                        const module = activity.module || 'system';
                        const time = activity.formatted_time || '';
                        const date = activity.formatted_date || '';
                        const actorName = activity.display_name || activity.username || '';
                        const actorRole = activity.user_role || '';
                        const roleLabel = actorRole ? (actorRole.charAt(0).toUpperCase() + actorRole.slice(1)) : '';
                        const safeActorName = actorName.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        const safeRoleLabel = roleLabel.replace(/</g, '&lt;').replace(/>/g, '&gt;');

                        html += `
                            <div class="activity-item">
                                <div class="activity-icon">
                                    ${icon}
                                </div>
                                <div class="activity-details">
                                    <h5>${description.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</h5>
                                    <p>
                                        <i class="fas fa-clock"></i>
                                        <span class="activity-time" title="${date} ${time}">
                                            ${time}
                                        </span>
                                        <span class="activity-date">${date}</span>
                                        <span class="activity-module">${module}</span>
                                        ${safeActorName ? `<span class="activity-user"><i class="fas fa-user"></i> ${safeActorName}${safeRoleLabel ? ` <small class="text-muted">(${safeRoleLabel})</small>` : ''}</span>` : ''}
                                    </p>
                                </div>
                            </div>
                        `;
                    });
                    activityList.innerHTML = html;
                    setLastUpdated('activityLastUpdated');
                } else {
                    // Restore original content if no activities or error
                    activityList.innerHTML = originalContent;
                    setLastUpdated('activityLastUpdated');
                }
            })
            .catch(error => {
                console.log('Error refreshing activities:', error);
                // Restore original content on error
                activityList.innerHTML = originalContent;
            });
    }

    function refreshLoginSessions() {
        const sessionsBody = document.getElementById('sessionsTableBody');
        if (!sessionsBody) return;

        fetch('<?php echo BASE_URL; ?>/api/login-sessions.php?limit=<?php echo (int)$dashboardLoginSessionLimit; ?>')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    return;
                }

                const sessions = Array.isArray(data.sessions) ? data.sessions : [];
                if (sessions.length === 0) {
                    sessionsBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No login sessions found</td></tr>';
                    return;
                }

                let html = '';
                sessions.forEach(row => {
                    const actorName = (row.display_name || row.username || 'Unknown').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const actorRole = (row.user_role || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const actorRoleLabel = actorRole ? (actorRole.charAt(0).toUpperCase() + actorRole.slice(1)) : '';
                    const loginTime = (row.formatted_login_time || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const endTime = (row.formatted_end_time || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const endType = row.end_type || 'active';
                    const mins = row.session_duration_minutes;

                    let endCell = '<span class="session-active"><i class="fas fa-circle"></i> Active</span>';
                    if (endTime) {
                        if (endType === 'superseded') {
                            endCell = '<span class="session-time auto-closed"><i class="fas fa-exchange-alt"></i> ' + endTime + '<small style="display:block; color:#856404;">Ended by next login</small></span>';
                        } else {
                            endCell = '<span class="session-time logout"><i class="fas fa-sign-out-alt"></i> ' + endTime + '</span>';
                        }
                    }

                    let durationCell = '<span class="session-duration active">--</span>';
                    if (mins !== null && mins !== undefined && !isNaN(mins)) {
                        const total = parseInt(mins, 10);
                        if (total < 60) {
                            durationCell = '<span class="session-duration">' + total + ' min</span>';
                        } else {
                            const hours = Math.floor(total / 60);
                            const remain = total % 60;
                            durationCell = '<span class="session-duration">' + hours + 'h ' + remain + 'm</span>';
                        }
                    }

                    html += '<tr>' +
                        '<td><span class="session-user"><i class="fas fa-user"></i> ' + actorName + (actorRoleLabel ? ' <small class="text-muted">(' + actorRoleLabel + ')</small>' : '') + '</span></td>' +
                        '<td><span class="session-time login"><i class="fas fa-sign-in-alt"></i> ' + loginTime + '</span></td>' +
                        '<td>' + endCell + '</td>' +
                        '<td>' + durationCell + '</td>' +
                    '</tr>';
                });

                sessionsBody.innerHTML = html;
                setLastUpdated('sessionsLastUpdated');
            })
            .catch(error => {
                console.log('Error refreshing login sessions:', error);
            });
    }

    // Auto-refresh every 30 seconds
    activityRefreshInterval = setInterval(refreshRecentActivities, 30000);
    sessionsRefreshInterval = setInterval(refreshLoginSessions, 30000);

    // Initial refresh after 5 seconds
    setTimeout(refreshRecentActivities, 5000);
    setTimeout(refreshLoginSessions, 5000);

    // Clear interval when page unloads
    window.addEventListener('beforeunload', function() {
        if (activityRefreshInterval) {
            clearInterval(activityRefreshInterval);
        }
        if (sessionsRefreshInterval) {
            clearInterval(sessionsRefreshInterval);
        }
    });
    </script>
    
    <?php
    return ob_get_clean();
}

// ============================================================================
// USAGE EXAMPLES (Remove these in production)
// ============================================================================

/*
// EXAMPLE 1: Display the bell in your admin header
echo renderAdminNotificationBell();

// EXAMPLE 2: Create notification for specific admin
createAdminNotification(
    1, // admin ID
    'success', // type: info, success, warning, error, student, finance, system
    'New Student Registered',
    'John Doe has completed registration',
    '/views/admin/students/view.php?id=123'
);

// EXAMPLE 3: Notify all admins
notifyAllAdmins(
    'warning',
    'Low Balance Alert',
    'Student Jane Smith has a balance of UGX 50,000',
    '/views/admin/finance/students.php'
);

// EXAMPLE 4: In your student registration file
$conn->commit();
notifyAllAdmins(
    'student',
    'New Student Added',
    "$first_name $last_name (ID: $studentCode) has been added",
    BASE_URL . "/views/admin/students/view.php?id=$newStudentId"
);

// EXAMPLE 5: On payment received
$conn->commit();
notifyAllAdmins(
    'finance',
    'Payment Received',
    "$studentName paid UGX " . number_format($amount),
    BASE_URL . "/views/admin/finance/transaction.php?id=$transactionId"
);
*/

?>
