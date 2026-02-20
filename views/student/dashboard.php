
<?php
require_once '../../config.php';
$session = new Session('student');
$auth    = new Auth('student');
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}
$currentUser    = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];
$currentUserId = (int)($currentUser['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
$studentDbId = (int)($studentProfile['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_contacts' && $studentDbId > 0) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    $phone = trim(Security::sanitize($_POST['phone'] ?? ''));
    $email = trim($_POST['email'] ?? '');
    $address = trim(Security::sanitize($_POST['address'] ?? ''));
    $city = trim(Security::sanitize($_POST['city'] ?? ''));
    $country = trim(Security::sanitize($_POST['country'] ?? ''));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $session->setFlash('error', 'Please enter a valid email address.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    try {
        $updStmt = $conn->prepare("
            UPDATE students
            SET phone = :phone,
                email = :email,
                address = :address,
                city = :city,
                country = :country,
                updated_at = NOW()
            WHERE id = :student_id
        ");
        $updStmt->execute([
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
            'city' => $city !== '' ? $city : null,
            'country' => $country !== '' ? $country : null,
            'student_id' => $studentDbId
        ]);

        // Keep user email in sync when changed from contacts.
        if (!empty($studentProfile['user_id']) && $email !== '') {
            $uStmt = $conn->prepare("UPDATE users SET email = :email, updated_at = NOW() WHERE id = :user_id");
            $uStmt->execute([
                'email' => $email,
                'user_id' => (int)$studentProfile['user_id']
            ]);
        }

        $session->setFlash('success', 'Contacts updated successfully.');
    } catch (Exception $e) {
        $session->setFlash('error', 'Failed to update contacts.');
    }

    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password' && $currentUserId > 0) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $session->setFlash('error', 'All password fields are required.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }
    if ($newPassword !== $confirmPassword) {
        $session->setFlash('error', 'New password and confirmation do not match.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }
    if (strlen($newPassword) < 8) {
        $session->setFlash('error', 'Password must be at least 8 characters.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    try {
        $pStmt = $conn->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $pStmt->execute(['id' => $currentUserId]);
        $row = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !Security::verifyPassword($currentPassword, $row['password_hash'])) {
            $session->setFlash('error', 'Current password is incorrect.');
            header('Location: ' . BASE_URL . '/views/student/dashboard.php');
            exit;
        }

        $hash = Security::hashPassword($newPassword);
        $uStmt = $conn->prepare('UPDATE users SET password_hash = :hash, require_password_change = 0, updated_at = NOW() WHERE id = :id');
        $uStmt->execute(['hash' => $hash, 'id' => $currentUserId]);

        // Optional history insert; do not fail password change if history table is unavailable.
        try {
            $hStmt = $conn->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (:user_id, :password_hash)');
            $hStmt->execute(['user_id' => $currentUserId, 'password_hash' => $hash]);
        } catch (Exception $e) {
        }

        $session->setFlash('success', 'Password changed successfully.');
    } catch (Exception $e) {
        $session->setFlash('error', 'Failed to change password.');
    }

    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;
}

$studentRow = [];
if ($studentDbId > 0) {
    try {
        $sStmt = $conn->prepare("
            SELECT s.*, p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $sStmt->execute(['student_id' => $studentDbId]);
        $studentRow = $sStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $studentRow = [];
    }
}

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];

$activeSemester = Helper::getCurrentSemester();
if (!empty($activeSemester)) {
    $currentSemester['semester_name'] = $activeSemester['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($activeSemester['id'] ?? 0);

    if (!empty($activeSemester['academic_year_id'])) {
        $ayStmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = :id LIMIT 1");
        $ayStmt->execute(['id' => (int)$activeSemester['academic_year_id']]);
        $yearName = $ayStmt->fetchColumn();
        if ($yearName) {
            $currentSemester['academic_year'] = $yearName;
        }
    }
}

$outstandingBalance = (float)($studentProfile['account_balance'] ?? 0);
if (!empty($studentProfile['id']) && $currentSemester['id'] > 0) {
    try {
        $balStmt = $conn->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM student_balances
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $balStmt->execute([
            'student_id' => (int)$studentProfile['id'],
            'semester_id' => (int)$currentSemester['id']
        ]);
        $outstandingBalance = (float)$balStmt->fetchColumn();
    } catch (Exception $e) {
        $outstandingBalance = (float)($studentProfile['account_balance'] ?? 0);
    }
}

$academicStatus = 'Normal Progress';
if (!empty($studentProfile['id'])) {
    try {
        $standingStmt = $conn->prepare("
            SELECT sg.academic_standing
            FROM student_gpas sg
            WHERE sg.student_id = :student_id
            ORDER BY
                CASE WHEN :semester_id > 0 AND sg.semester_id = :semester_id THEN 0 ELSE 1 END,
                sg.semester_id DESC,
                sg.id DESC
            LIMIT 1
        ");
        $standingStmt->execute([
            'student_id' => (int)$studentProfile['id'],
            'semester_id' => (int)$currentSemester['id']
        ]);
        $standing = trim((string)$standingStmt->fetchColumn());

        if ($standing !== '') {
            $standingLower = strtolower($standing);
            if ($standingLower === 'good standing') {
                $academicStatus = 'Normal Progress';
            } elseif ($standingLower === 'suspension') {
                $academicStatus = 'Suspended';
            } else {
                $academicStatus = $standing;
            }
        } elseif (!empty($studentProfile['academic_status'])) {
            $rawAcademic = trim((string)$studentProfile['academic_status']);
            $rawLower = strtolower($rawAcademic);
            $academicStatus = ($rawLower === 'active' || $rawLower === 'good standing')
                ? 'Normal Progress'
                : $rawAcademic;
        }
    } catch (Exception $e) {
        if (!empty($studentProfile['academic_status'])) {
            $rawAcademic = trim((string)$studentProfile['academic_status']);
            $rawLower = strtolower($rawAcademic);
            $academicStatus = ($rawLower === 'active' || $rawLower === 'good standing')
                ? 'Normal Progress'
                : $rawAcademic;
        }
    }
}

// Always resolve programme from admin-assigned student record.
$registeredProgramName = '-';
if (!empty($studentProfile['id'])) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => (int)$studentProfile['id']]);
        $programName = $progStmt->fetchColumn();
        if (!empty($programName)) {
            $registeredProgramName = $programName;
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
        $registeredProgramName = !empty($studentProfile['program_name']) ? $studentProfile['program_name'] : '-';
    }
}

// Navigation links with safe fallbacks for pages that may not exist yet.
$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

$pageTitle = 'Student Portal - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
body { background: #f8fafc; }
.student-sidebar {
    width: 230px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    min-height: 100vh;
    height: 100vh;
    overflow-y: auto;
    overflow-x: hidden;
    border-right: 1px solid #e5e7eb;
    position: fixed;
    left: 0;
    top: 0;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(15, 23, 42, 0.04);
    transition: transform 0.25s ease;
}
.student-sidebar ul {
    list-style: none;
    padding: 10px 8px;
    margin: 0;
}
.student-sidebar > ul { padding-bottom: 20px; }
.student-sidebar li {
    padding: 9px 12px;
    margin-bottom: 4px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 0.82rem;
    letter-spacing: 0.02em;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s ease;
}
.student-sidebar li a {
    color: inherit;
    text-decoration: none;
    display: block;
}
.student-sidebar li.active {
    background: #eaf2ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
    font-weight: 700;
}
.student-sidebar li:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.student-sidebar .sidebar-section { font-size: 0.9rem; color: #888; padding: 10px 28px 4px 28px; text-transform: uppercase; letter-spacing: 0.04em; }
.main-content {
    margin-left: 230px;
    width: calc(100vw - 230px);
    max-width: calc(100vw - 230px);
    min-height: 100vh;
    background: #f8fafc;
    transition: margin-left 0.25s ease, width 0.25s ease;
}
.student-sidebar.sidebar-collapsed {
    transform: translateX(-100%);
}
.sidebar-user-card {
    margin: 0.45rem 0.45rem 0.2rem;
    background: #2b3c4f;
    border-radius: 8px;
    color: #fff;
    text-align: center;
    padding: 0.6rem 0.55rem 0.6rem;
}
.sidebar-user-card img {
    width: 62px;
    height: 72px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.35);
    margin-bottom: 0.3rem;
}
.sidebar-user-name { font-size: 0.82rem; line-height: 1.2; }
.sidebar-user-no { font-size: 0.9rem; font-weight: 700; }

.sidebar-portal-title { font-size: 0.66rem; letter-spacing: 0.08em; text-transform: uppercase; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 700; }
.main-content.full-width {
    margin-left: 0;
    width: 100vw;
    max-width: 100vw;
}
.student-topbar {
    display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0.7rem 2.5rem 0.7rem 2.5rem; position: sticky; top: 0; z-index: 10;
}
.student-profile-pic { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb; }
.bio-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 2rem 2.5rem; margin-top: 2rem; }
.bio-header { display: flex; align-items: center; gap: 1.5rem; margin-bottom: 1.5rem; }
.bio-header .status-badge { font-size: 0.95rem; padding: 4px 14px; border-radius: 12px; margin-left: 0.7rem; }
.status-active { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.status-notreg { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.bio-details-table { width: 100%; font-size: 0.82rem; margin-top: 1rem; }
.bio-details-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
.bio-details-table tr:last-child td { border-bottom: none; }
.bio-action-group { margin-left: auto; display: flex; gap: 0.55rem; align-items: center; }
.bio-btn {
    padding: 6px 12px;
    border-radius: 7px;
    border: 1px solid transparent;
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    transition: all 0.18s ease;
}
.bio-btn.print {
    background: #1f7aa8;
    color: #fff;
    border-color: #1f7aa8;
}
.bio-btn.print:hover {
    background: #176488;
    border-color: #176488;
}
.bio-btn.reload {
    background: #fff;
    color: #ef4444;
    border-color: #fca5a5;
}
.bio-btn.reload:hover {
    background: #fff1f2;
    color: #dc2626;
    border-color: #f87171;
}
.bio-edit-link { color: #2563eb; font-size: 0.82rem; float: right; cursor: pointer; }
.bio-section-tabs { margin-top: 2rem; display: flex; gap: 1.5rem; border-bottom: 2px solid #e5e7eb; }
.bio-section-tabs .tab { padding: 10px 0; font-size: 0.82rem; color: #222; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; background: transparent; border-top: none; border-left: none; border-right: none; font-weight: 600; }
.bio-section-tabs .tab.active { color: #2563eb; border-bottom: 3px solid #2563eb; font-weight: 600; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
#bioTabPanels { min-height: 260px; }
.bio-card,
.bio-card label,
.bio-card .form-control-sm,
.bio-card .btn,
.bio-card .btn-sm {
    font-size: 0.82rem !important;
}
@media print {
    .student-sidebar,
    .student-topbar,
    .bio-section-tabs,
    .bio-action-group,
    #editContactsBtn,
    #editContactsForm {
        display: none !important;
    }
    .main-content {
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .bio-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}
</style>

<div class="student-sidebar">
    <div class="sidebar-user-card">
        <div class="sidebar-portal-title">SMNS-STUDENT PORTAL</div>
        <?php if (!empty($studentProfile['photo'])): ?>
            <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile">
        <?php else: ?>
            <img src="/assets/img/student_sample.jpg" alt="Profile">
        <?php endif; ?>
        <div class="sidebar-user-name">
            <?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?>
        </div>
        <div class="sidebar-user-no">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
    </div>
    <ul>
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY PROGRAMME</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li class="active"><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar" style="padding:0.5rem 1.2rem; font-size:0.92rem; display:flex; align-items:center; justify-content:space-between;">
        <div style="display:flex; align-items:center; gap:0.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#2563eb; color:#fff; border:none; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic" style="width:48px;height:48px;">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic" style="width:48px;height:48px;">
            <?php endif; ?>
            <span style="font-size:0.98rem; color:#222; font-weight:600; white-space:nowrap;"> <?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?> </span>
            <a href="<?php echo e($linkMailbox); ?>" title="My Mailbox" style="position:relative; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px solid #dbe3ef; border-radius:50%; color:#1f7aa8; text-decoration:none; background:#fff;">
                <i class="far fa-envelope"></i>
                <?php if ($mailUnreadCount > 0): ?>
                    <span style="position:absolute; top:-6px; right:-6px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:#ef4444; color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center;"><?php echo $mailUnreadCount > 99 ? '99+' : $mailUnreadCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="profile-dropdown" style="position:relative;">
                <button id="profileDropBtn" style="background:none; border:none; font-size:0.98rem; cursor:pointer; padding:0 6px;">
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div id="profileDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.08); min-width:140px; z-index:100;">
                    <a href="dashboard.php" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Profile</a>
                    <a href="services.php?tab=apply" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Services</a>
                    <a href="logout.php" style="display:block; padding:8px 14px; color:#dc2626; text-decoration:none; font-weight:600; font-size:0.92rem;">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div style="padding:0.7rem 1.2rem 0.2rem 1.2rem; font-size:0.98rem; font-weight:600; display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap;">
        <span>PROGRAMME: <?php echo e($registeredProgramName); ?></span>
        <span class="status-badge status-active" style="font-size:0.85rem; padding:3px 10px;"><?php echo !empty($studentProfile['status']) ? strtoupper(e($studentProfile['status'])) : 'ACTIVE'; ?></span>
        <span style="margin-left:auto; font-size:1.05rem; color:#222;">ACADEMIC STATUS: <span style="background:#fee2e2; color:#991b1b; border-radius:6px; padding:4px 12px; font-weight:600;">
            <?php echo !empty($academicStatus) ? e($academicStatus) : '-'; ?>
        </span></span>
    </div>
<script>
// Sidebar toggle
document.getElementById('menuBtn').addEventListener('click', function() {
    var sidebar = document.querySelector('.student-sidebar');
    var main = document.querySelector('.main-content');
    sidebar.classList.toggle('sidebar-collapsed');
    if (main) main.classList.toggle('full-width');
});
// Profile dropdown
document.getElementById('profileDropBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    var menu = document.getElementById('profileDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function() {
    var menu = document.getElementById('profileDropMenu');
    if (menu) menu.style.display = 'none';
});
</script>

    <div style="padding:2.5rem 3rem 1rem 3rem;">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div style="display:flex; align-items:center; gap:0.35rem; margin-bottom:1.2rem; flex-wrap:nowrap; white-space:nowrap;">
            <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">CURRENT YR. <span style="color:#2563eb;"><?php echo !empty($currentSemester['academic_year']) ? e($currentSemester['academic_year']) : '-'; ?></span></span>
            <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">CURRENT SEM. <span style="color:#2563eb;"><?php echo !empty($currentSemester['semester_name']) ? e($currentSemester['semester_name']) : '-'; ?></span></span>
            <span style="background:#fee2e2; color:#991b1b; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">
                <?php echo (isset($studentProfile['enrollment_status']) && strtolower($studentProfile['enrollment_status']) === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?>
            </span>
            <span style="background:#fee2e2; color:#991b1b; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">
                <?php echo (isset($studentProfile['registration_status']) && strtolower($studentProfile['registration_status']) === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?>
            </span>
            <span style="background:#f1f5f9; color:#991b1b; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">TOTAL FEES BAL DUE: <?php echo isset($outstandingBalance) ? number_format($outstandingBalance) : '0'; ?>/=</span>
            <span style="background:#2563eb; color:#fff; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">BALANCE ON ACCOUNT: <?php echo isset($studentProfile['account_balance']) ? number_format($studentProfile['account_balance']) : '0'; ?>/=</span>
        </div>

        <div class="bio-card">
            <div class="bio-header">
                <?php if (!empty($studentProfile['photo'])): ?>
                    <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic">
                <?php else: ?>
                    <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic">
                <?php endif; ?>
                <div>
                    <div style="font-size:1.2rem; font-weight:700; color:#2563eb;">
                        <?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?>
                    </div>
                    <div style="font-size:1.05rem; color:#222;">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
                    <span class="status-badge status-notreg" style="margin-top:0.5rem;">
                        <?php echo ($studentProfile['registration_status'] ?? '') === 'registered' ? 'REGISTERED' : 'NOT REGISTERED'; ?>
                    </span>
                </div>
                <div class="bio-action-group">
                    <button type="button" id="printBioBtn" class="bio-btn print">Print Bio Data</button>
                    <button type="button" id="reloadBioBtn" class="bio-btn reload">Reload</button>
                </div>
            </div>
            <div style="margin-bottom:1.2rem;">
                <div class="bio-section-tabs" id="bioTabs">
                    <button type="button" class="tab active" data-tab="personal">PERSONAL DETAILS</button>
                    <button type="button" class="tab" data-tab="academic">ACADEMIC DETAILS</button>
                    <button type="button" class="tab" data-tab="guardian">GUARDIAN DETAILS</button>
                    <button type="button" class="tab" data-tab="nextkin">NEXT OF KIN</button>
                    <button type="button" class="tab" data-tab="password">CHANGE PASSWORD</button>
                </div>
            </div>
            <div style="margin-top:1.5rem;" id="bioTabPanels">
                <div class="tab-panel active" data-panel="personal">
                    <table class="bio-details-table">
                        <tr><td><b>SURNAME</b></td><td>:<?php echo e($studentRow['last_name'] ?? ($studentProfile['last_name'] ?? '-')); ?></td><td><b>RELIGION</b></td><td>:<?php echo e($studentRow['religion'] ?? ($studentProfile['religion'] ?? '-')); ?></td></tr>
                        <tr><td><b>OTHER NAMES</b></td><td>:<?php echo e(trim(($studentRow['first_name'] ?? ($studentProfile['first_name'] ?? '')) . ' ' . ($studentRow['middle_name'] ?? ($studentProfile['middle_name'] ?? ''))) ?: '-'); ?></td><td><b>DISTRICT</b></td><td>:<?php echo e($studentRow['district'] ?? ($studentRow['city'] ?? ($studentProfile['district'] ?? '-'))); ?></td></tr>
                        <tr><td><b>EMAIL</b></td><td>:<?php echo e($studentRow['email'] ?? ($studentProfile['email'] ?? '-')); ?></td><td><b>NATIONALITY</b></td><td>:<?php echo e($studentRow['country'] ?? ($studentProfile['country'] ?? '-')); ?></td></tr>
                        <tr><td><b>TEL. PHONE</b></td><td>:<?php echo e($studentRow['phone'] ?? ($studentProfile['phone'] ?? '-')); ?></td><td><b>NATIONAL ID NO.</b></td><td>:<?php echo e($studentRow['national_id'] ?? ($studentProfile['national_id'] ?? '-')); ?></td></tr>
                        <tr><td><b>SEX</b></td><td>:<?php echo e($studentRow['gender'] ?? ($studentProfile['gender'] ?? '-')); ?></td><td><b>PASSPORT</b></td><td>:<?php echo e($studentRow['passport'] ?? ($studentProfile['passport'] ?? '-')); ?></td></tr>
                        <tr><td><b>DATE OF BIRTH</b></td><td>:<?php echo !empty($studentRow['date_of_birth'] ?? $studentProfile['date_of_birth']) ? date('d/m/Y', strtotime($studentRow['date_of_birth'] ?? $studentProfile['date_of_birth'])) : '-'; ?></td><td></td><td></td></tr>
                    </table>
                    <div class="bio-edit-link" id="editContactsBtn">Edit Contacts</div>
                    <form method="POST" id="editContactsForm" style="display:none; margin-top:1rem;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="update_contacts">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control form-control-sm" value="<?php echo e($studentRow['phone'] ?? ($studentProfile['phone'] ?? '')); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control form-control-sm" value="<?php echo e($studentRow['email'] ?? ($studentProfile['email'] ?? '')); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>City</label>
                                <input type="text" name="city" class="form-control form-control-sm" value="<?php echo e($studentRow['city'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Address</label>
                                <input type="text" name="address" class="form-control form-control-sm" value="<?php echo e($studentRow['address'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Country</label>
                                <input type="text" name="country" class="form-control form-control-sm" value="<?php echo e($studentRow['country'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="tab-panel" data-panel="academic">
                    <table class="bio-details-table">
                        <tr><td><b>PROGRAMME</b></td><td>:<?php echo e($registeredProgramName); ?></td><td><b>YEAR OF STUDY</b></td><td>:<?php echo e($studentRow['level_year'] ?? ($studentProfile['level_year'] ?? '-')); ?></td></tr>
                        <tr><td><b>ENTRY YEAR</b></td><td>:<?php echo e($studentRow['entry_year'] ?? ($studentProfile['entry_year'] ?? '-')); ?></td><td><b>ENTRY MODE</b></td><td>:<?php echo e($studentRow['entry_mode'] ?? ($studentProfile['entry_mode'] ?? '-')); ?></td></tr>
                        <tr><td><b>ENROLLMENT TYPE</b></td><td>:<?php echo e($studentRow['enrollment_type'] ?? ($studentProfile['enrollment_type'] ?? '-')); ?></td><td><b>ACADEMIC STATUS</b></td><td>:<?php echo e($academicStatus); ?></td></tr>
                    </table>
                </div>

                <div class="tab-panel" data-panel="guardian">
                    <table class="bio-details-table">
                        <tr><td><b>GUARDIAN NAME</b></td><td>:<?php echo e($studentRow['guardian_name'] ?? '-'); ?></td><td><b>RELATION</b></td><td>:<?php echo e($studentRow['guardian_relation'] ?? '-'); ?></td></tr>
                        <tr><td><b>GUARDIAN PHONE</b></td><td>:<?php echo e($studentRow['guardian_phone'] ?? '-'); ?></td><td><b>GUARDIAN EMAIL</b></td><td>:<?php echo e($studentRow['guardian_email'] ?? '-'); ?></td></tr>
                    </table>
                </div>

                <div class="tab-panel" data-panel="nextkin">
                    <table class="bio-details-table">
                        <tr><td><b>NAME</b></td><td>:<?php echo e($studentRow['emergency_contact_name'] ?? '-'); ?></td><td><b>RELATIONSHIP</b></td><td>:<?php echo e($studentRow['emergency_contact_relationship'] ?? '-'); ?></td></tr>
                        <tr><td><b>PHONE</b></td><td>:<?php echo e($studentRow['emergency_contact_phone'] ?? '-'); ?></td><td></td><td></td></tr>
                    </table>
                </div>

                <div class="tab-panel" data-panel="password">
                    <form method="POST" style="max-width:460px;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label style="font-size:0.85rem; font-weight:600;">Current Password</label>
                            <input type="password" name="current_password" class="form-control form-control-sm" required>
                        </div>
                        <div class="form-group">
                            <label style="font-size:0.85rem; font-weight:600;">New Password</label>
                            <input type="password" name="new_password" class="form-control form-control-sm" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label style="font-size:0.85rem; font-weight:600;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control form-control-sm" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('#bioTabs .tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var target = tab.getAttribute('data-tab');
        document.querySelectorAll('#bioTabs .tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('#bioTabPanels .tab-panel').forEach(function(p) { p.classList.remove('active'); });
        tab.classList.add('active');
        var panel = document.querySelector('#bioTabPanels .tab-panel[data-panel="' + target + '"]');
        if (panel) panel.classList.add('active');
    });
});

var editBtn = document.getElementById('editContactsBtn');
if (editBtn) {
    editBtn.addEventListener('click', function() {
        var form = document.getElementById('editContactsForm');
        if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
    });
}

var printBtn = document.getElementById('printBioBtn');
if (printBtn) {
    printBtn.addEventListener('click', function() {
        window.print();
    });
}

var reloadBtn = document.getElementById('reloadBioBtn');
if (reloadBtn) {
    reloadBtn.addEventListener('click', function() {
        window.location.reload();
    });
}
</script>

<?php include '../../includes/footer.php'; ?>

