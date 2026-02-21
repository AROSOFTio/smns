<?php
require_once dirname(__DIR__, 2) . '/config.php';

$session = new Session('student');
$auth = new Auth('student');

if (
    !isset($_SESSION['student_logged_in']) ||
    $_SESSION['student_logged_in'] !== true ||
    ($_SESSION['student_role'] ?? '') !== 'student'
) {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$studentId = (int)($studentProfile['id'] ?? 0);
$userId = (int)($currentUser['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();

// Ensure mailbox helper tables exist before querying.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS notifications_read (
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at DATETIME NOT NULL,
        PRIMARY KEY(notification_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS notification_archive (
        id INT PRIMARY KEY AUTO_INCREMENT,
        notification_id INT NULL,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        link VARCHAR(255) NULL,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

$box = $_GET['box'] ?? 'inbox'; // inbox|read|archived
if (!in_array($box, ['inbox', 'read', 'archived'], true)) {
    $box = 'inbox';
}

$inboxNotifications = [];
$archivedNotifications = [];
$readNotifications = [];

try {
    $stmt = $conn->prepare("
        SELECT n.*, nr.read_at
        FROM notifications n
        LEFT JOIN notifications_read nr ON n.id = nr.notification_id AND nr.user_id = :uid_join
        LEFT JOIN notification_archive na ON n.id = na.notification_id AND na.user_id = :uid_archive
        WHERE (n.user_id = :uid_where OR n.user_id IS NULL OR n.user_id = 0)
          AND na.id IS NULL
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([
        'uid_join' => $userId,
        'uid_archive' => $userId,
        'uid_where' => $userId
    ]);
    $inboxNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $inboxNotifications = [];
}

try {
    $stmt = $conn->prepare("
        SELECT id, notification_id, title, message, link, archived_at
        FROM notification_archive
        WHERE user_id = :uid
        ORDER BY archived_at DESC
    ");
    $stmt->execute(['uid' => $userId]);
    $archivedNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $archivedNotifications = [];
}

foreach ($inboxNotifications as $n) {
    $isPersonal = (isset($n['user_id']) && (int)$n['user_id'] === $userId);
    $isRead = !empty($n['read_at']) || ($isPersonal && strtolower((string)($n['read_status'] ?? '')) === 'read');
    if ($isRead) {
        $readNotifications[] = $n;
    }
}

$displayNotifications = $inboxNotifications;
$emptyText = 'No emails in Inbox';
if ($box === 'read') {
    $displayNotifications = $readNotifications;
    $emptyText = 'No read emails';
} elseif ($box === 'archived') {
    $displayNotifications = $archivedNotifications;
    $emptyText = 'No archived emails';
}

$inboxCount = count($inboxNotifications);
$readCount = count($readNotifications);
$archivedCount = count($archivedNotifications);
$unreadCount = max(0, $inboxCount - $readCount);

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];
$studentSemesterContext = getStudentCurrentSemesterContext($conn, $studentId);
if (!empty($studentSemesterContext['id'])) {
    $currentSemester['semester_name'] = $studentSemesterContext['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($studentSemesterContext['id'] ?? 0);
    $currentSemester['academic_year'] = $studentSemesterContext['academic_year'] ?? '-';
}

$outstandingBalance = (float)($studentProfile['account_balance'] ?? 0);
if ($studentId > 0 && $currentSemester['id'] > 0) {
    try {
        $balStmt = $conn->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM student_balances
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $balStmt->execute([
            'student_id' => $studentId,
            'semester_id' => (int)$currentSemester['id']
        ]);
        $outstandingBalance = (float)$balStmt->fetchColumn();
    } catch (Exception $e) {
    }
}

$academicStatusMeta = getStudentAcademicStatusMeta(
    $conn,
    (int)$studentId,
    (int)($currentSemester['id'] ?? 0),
    (string)($studentProfile['academic_status'] ?? '')
);
$academicStatus = (string)($academicStatusMeta['label'] ?? 'Status Pending');
$academicStatusStyle = (string)($academicStatusMeta['style'] ?? getAcademicStatusChipStyle('neutral'));

$registeredProgramName = '-';
if ($studentId > 0) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => $studentId]);
        $programName = $progStmt->fetchColumn();
        if (!empty($programName)) {
            $registeredProgramName = $programName;
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
    }
}

$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';

$pageTitle = 'My Mailbox - ' . APP_NAME;
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
body { background:#f2f4f7; }
.student-sidebar { width:230px; background:linear-gradient(180deg,#fff 0%,#f8fafc 100%); min-height:100vh; height:100vh; overflow-y:auto; overflow-x:hidden; border-right:1px solid #e5e7eb; position:fixed; left:0; top:0; z-index:100; box-shadow:2px 0 12px rgba(15,23,42,.04); transition:transform .25s ease; }
.student-sidebar ul { list-style:none; padding:10px 8px; margin:0; }
.student-sidebar > ul { padding-bottom:20px; }
.student-sidebar li { padding:9px 12px; margin-bottom:4px; border:1px solid transparent; border-radius:8px; font-size:.82rem; letter-spacing:.02em; color:#334155; cursor:pointer; transition:all .2s ease; }
.student-sidebar li a { color:inherit; text-decoration:none; display:block; }
.student-sidebar li.active { background:#eaf2ff; border-color:#bfdbfe; color:#1d4ed8; font-weight:700; }
.student-sidebar li:hover { background:#f1f5f9; color:#0f172a; }
.student-sidebar.sidebar-collapsed { transform:translateX(-100%); }
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
.main-content { margin-left:230px; width:calc(100vw - 230px); max-width:calc(100vw - 230px); min-height:100vh; transition:margin-left .25s ease, width .25s ease; }
.main-content.full-width { margin-left:0; width:100vw; max-width:100vw; }
.student-topbar { display:flex; align-items:center; justify-content:space-between; background:#fff; border-bottom:1px solid #e5e7eb; padding:.5rem 1.2rem; position:sticky; top:0; z-index:10; }
.student-profile-pic { width:48px; height:48px; border-radius:50%; object-fit:cover; border:2px solid #e5e7eb; }
.chip-row { padding:.45rem 1.2rem .2rem; display:flex; align-items:center; gap:.35rem; white-space:nowrap; }
.chip { border-radius:6px; padding:4px 8px; font-weight:600; font-size:.78rem; line-height:1; white-space:nowrap; }
.chip.gray { background:#f1f5f9; color:#222; }
.chip.blue { background:#1f7aa8; color:#fff; }
.chip.red { background:#fee2e2; color:#991b1b; }

.mail-wrap { padding:.9rem 1.2rem 1.3rem; }
.mail-shell { background:#fff; border:1px solid #e5e7eb; border-radius:10px; display:grid; grid-template-columns:320px 1fr; min-height:560px; }
.mail-left { border-right:1px solid #e5e7eb; padding:14px; }
.mail-title { margin:0 0 12px; color:#1f7aa8; font-size:1.9rem; font-weight:400; }
.compose-btn { width:100%; border:1px solid #1f7aa8; background:#1f7aa8; color:#fff; border-radius:10px; padding:10px; font-size:1.9rem; font-size:.95rem; font-weight:600; margin-bottom:14px; cursor:pointer; }
.mail-folder { display:flex; align-items:center; justify-content:space-between; text-decoration:none; color:#1f2937; border-radius:10px; padding:12px 14px; margin-bottom:7px; border:1px solid transparent; font-size:1.65rem; font-size:1rem; }
.mail-folder.active { background:#dce8ee; color:#0e7490; }
.mail-folder:hover { background:#f1f5f9; }
.mail-right { padding:0; display:flex; flex-direction:column; }
.mail-toolbar { border-bottom:1px solid #e5e7eb; padding:14px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.mail-search { width:100%; max-width:400px; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:.92rem; }
.mail-actions { display:flex; gap:8px; }
.mail-btn { border:1px solid #d1d5db; background:#fff; color:#334155; border-radius:8px; padding:7px 12px; font-weight:700; font-size:.8rem; cursor:pointer; }
.mail-btn.primary { border-color:#1f7aa8; background:#1f7aa8; color:#fff; }
.mail-list { padding:12px; overflow:auto; }
.mail-item { border:1px solid #e5e7eb; border-radius:8px; padding:11px 12px; margin-bottom:9px; background:#fff; }
.mail-item.unread { border-left:4px solid #1f7aa8; background:#f8fbff; }
.mail-item-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.mail-item-title { margin:0; font-size:.95rem; font-weight:700; color:#0f172a; }
.mail-item-time { color:#64748b; font-size:.78rem; white-space:nowrap; }
.mail-item-msg { margin:6px 0 8px; font-size:.85rem; color:#334155; }
.mail-item-actions { display:flex; gap:7px; }
.mail-action { border:1px solid #d1d5db; background:#fff; color:#334155; border-radius:7px; padding:5px 9px; font-size:.75rem; font-weight:700; cursor:pointer; }
.mail-action.warn { color:#b91c1c; border-color:#fecaca; background:#fff1f2; }
.mail-empty { display:flex; align-items:center; justify-content:center; min-height:340px; color:#64748b; font-size:1.1rem; }
.mail-stat { color:#0f172a; font-weight:700; font-size:.85rem; }

/* Dark mode overrides for mailbox */
html[data-theme='dark'] .mail-shell {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-left {
    background: var(--app-surface-1) !important;
    border-right-color: var(--app-border) !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-title {
    color: #93c5fd !important;
}
html[data-theme='dark'] .mail-folder {
    color: #e5e7eb !important;
    border-color: transparent !important;
}
html[data-theme='dark'] .mail-folder:hover {
    background: #1f2937 !important;
}
html[data-theme='dark'] .mail-folder.active {
    background: #0f2a39 !important;
    color: #7dd3fc !important;
    border-color: #164e63 !important;
}
html[data-theme='dark'] .mail-stat {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .mail-folder.active .mail-stat {
    color: #bae6fd !important;
}
html[data-theme='dark'] .mail-right {
    background: var(--app-surface-1) !important;
}
html[data-theme='dark'] .mail-toolbar {
    background: var(--app-surface-2) !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-search {
    background: var(--app-surface-1) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-search::placeholder {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .mail-btn {
    background: var(--app-surface-1) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-btn.primary {
    background: #1f7aa8 !important;
    color: #fff !important;
    border-color: #1f7aa8 !important;
}
html[data-theme='dark'] .mail-list {
    background: var(--app-surface-1) !important;
}
html[data-theme='dark'] .mail-item {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-item.unread {
    background: #112030 !important;
    border-left-color: #38bdf8 !important;
}
html[data-theme='dark'] .mail-item-title {
    color: #f8fafc !important;
}
html[data-theme='dark'] .mail-item-time {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .mail-item-msg {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .mail-action {
    background: #0f172a !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mail-action.warn {
    background: #3a1820 !important;
    color: #fecaca !important;
    border-color: #7f1d1d !important;
}
html[data-theme='dark'] .mail-empty {
    color: #94a3b8 !important;
}

@media (max-width: 1200px) {
    .mail-shell { grid-template-columns:1fr; }
    .mail-left { border-right:none; border-bottom:1px solid #e5e7eb; }
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
        <div class="sidebar-user-name"><?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?></div>
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
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li class="active"><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:.92rem; font-weight:600;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic">
            <?php endif; ?>
            <span style="font-size:.98rem; color:#222; font-weight:600; white-space:nowrap;"><?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?></span>
            <a href="<?php echo e($linkMailbox); ?>" title="My Mailbox" style="position:relative; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px solid #dbe3ef; border-radius:50%; color:#1f7aa8; text-decoration:none; background:#fff;">
                <i class="far fa-envelope"></i>
                <?php if ($unreadCount > 0): ?>
                    <span style="position:absolute; top:-6px; right:-6px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:#ef4444; color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center;"><?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
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

    <div class="chip-row">
        <span style="font-size:1rem; color:#1f7aa8;">PROGRAMME:</span>
        <span style="font-size:1rem;"><?php echo e($registeredProgramName); ?></span>
        <span class="chip" style="background:#16a34a; color:#fff;">ACTIVE</span>
        <span style="margin-left:auto; font-size:1rem; color:#1f7aa8;">ACADEMIC STATUS:</span>
        <span class="chip red" style="<?php echo e($academicStatusStyle); ?>"><?php echo e($academicStatus); ?></span>
    </div>
    <div class="chip-row">
        <span class="chip gray">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span>
        <span class="chip gray">CURRENT SEM. <span style="color:#2563eb;"><?php echo e($currentSemester['semester_name']); ?></span></span>
        <span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?></span>
        <span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
        <span class="chip gray">TOTAL FEES BAL DUE: <?php echo number_format($outstandingBalance); ?>/=</span>
        <span class="chip blue">BALANCE ON ACCOUNT: <?php echo number_format((float)($studentProfile['account_balance'] ?? 0)); ?>/=</span>
    </div>

    <div class="mail-wrap">
        <div class="mail-shell">
            <div class="mail-left">
                <h3 class="mail-title">Mailbox</h3>
                <button type="button" class="compose-btn" onclick="alert('Compose flow can be connected next.');"><i class="fas fa-pen mr-2"></i> Compose Mail</button>

                <a class="mail-folder <?php echo $box === 'inbox' ? 'active' : ''; ?>" href="notifications.php?box=inbox">
                    <span><i class="far fa-envelope-open mr-2"></i> Inbox</span>
                    <span class="mail-stat"><?php echo $inboxCount; ?></span>
                </a>
                <a class="mail-folder <?php echo $box === 'read' ? 'active' : ''; ?>" href="notifications.php?box=read">
                    <span><i class="far fa-folder-open mr-2"></i> Read</span>
                    <span class="mail-stat"><?php echo $readCount; ?></span>
                </a>
                <a class="mail-folder <?php echo $box === 'archived' ? 'active' : ''; ?>" href="notifications.php?box=archived">
                    <span><i class="fas fa-archive mr-2"></i> Archived</span>
                    <span class="mail-stat"><?php echo $archivedCount; ?></span>
                </a>
                <div class="mail-folder">
                    <span><i class="far fa-paper-plane mr-2"></i> Sent</span>
                    <span class="mail-stat">0</span>
                </div>
                <div class="mail-folder">
                    <span><i class="far fa-file-alt mr-2"></i> Drafts</span>
                    <span class="mail-stat">0</span>
                </div>
                <div class="mail-folder">
                    <span><i class="far fa-star mr-2"></i> Starred</span>
                    <span class="mail-stat">0</span>
                </div>
                <div class="mail-folder">
                    <span><i class="far fa-trash-alt mr-2"></i> Junk</span>
                    <span class="mail-stat">0</span>
                </div>
            </div>

            <div class="mail-right">
                <div class="mail-toolbar">
                    <input id="mailSearch" class="mail-search" type="text" placeholder="Search mail">
                    <div class="mail-actions">
                        <?php if ($box !== 'archived'): ?>
                            <button id="markAllReadBtn" class="mail-btn primary" type="button">Mark All Read</button>
                        <?php endif; ?>
                        <button class="mail-btn" type="button" onclick="window.location.reload();">Reload</button>
                    </div>
                </div>

                <div class="mail-list" id="mailList">
                    <?php if (empty($displayNotifications)): ?>
                        <div class="mail-empty"><?php echo e($emptyText); ?></div>
                    <?php else: ?>
                        <?php if ($box === 'archived'): ?>
                            <?php foreach ($displayNotifications as $mail): ?>
                                <div class="mail-item" data-text="<?php echo e(strtolower((string)($mail['title'] ?? '') . ' ' . (string)($mail['message'] ?? ''))); ?>">
                                    <div class="mail-item-head">
                                        <h5 class="mail-item-title"><?php echo e($mail['title'] ?? '-'); ?></h5>
                                        <div class="mail-item-time"><?php echo !empty($mail['archived_at']) ? e(Helper::timeAgo($mail['archived_at'])) : '-'; ?></div>
                                    </div>
                                    <div class="mail-item-msg"><?php echo e($mail['message'] ?? '-'); ?></div>
                                    <div class="mail-item-actions">
                                        <button class="mail-action warn delete-archive-btn" data-id="<?php echo (int)$mail['id']; ?>" type="button">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($displayNotifications as $mail): ?>
                                <?php
                                    $isPersonal = (isset($mail['user_id']) && (int)$mail['user_id'] === $userId);
                                    $isRead = !empty($mail['read_at']) || ($isPersonal && strtolower((string)($mail['read_status'] ?? '')) === 'read');
                                ?>
                                <div class="mail-item <?php echo $isRead ? '' : 'unread'; ?>" data-text="<?php echo e(strtolower((string)($mail['title'] ?? '') . ' ' . (string)($mail['message'] ?? ''))); ?>">
                                    <div class="mail-item-head">
                                        <h5 class="mail-item-title"><?php echo e($mail['title'] ?? '-'); ?></h5>
                                        <div class="mail-item-time"><?php echo !empty($mail['created_at']) ? e(Helper::timeAgo($mail['created_at'])) : '-'; ?></div>
                                    </div>
                                    <div class="mail-item-msg"><?php echo e($mail['message'] ?? '-'); ?></div>
                                    <div class="mail-item-actions">
                                        <?php if (!$isRead): ?>
                                            <button class="mail-action mark-read-btn" data-id="<?php echo (int)$mail['id']; ?>" type="button">Mark Read</button>
                                        <?php endif; ?>
                                        <button class="mail-action warn archive-btn" data-id="<?php echo (int)$mail['id']; ?>" type="button">Archive</button>
                                        <?php if (!empty($mail['link']) && strpos((string)$mail['link'], 'action:') !== 0): ?>
                                            <?php $url = (strpos((string)$mail['link'], 'http') === 0) ? $mail['link'] : BASE_URL . $mail['link']; ?>
                                            <a class="mail-action" style="text-decoration:none;" href="<?php echo e($url); ?>">Open</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('menuBtn').addEventListener('click', function() {
    var sidebar = document.querySelector('.student-sidebar');
    var main = document.querySelector('.main-content');
    sidebar.classList.toggle('sidebar-collapsed');
    if (main) main.classList.toggle('full-width');
});

document.getElementById('profileDropBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    var menu = document.getElementById('profileDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function() {
    var menu = document.getElementById('profileDropMenu');
    if (menu) menu.style.display = 'none';
});

var search = document.getElementById('mailSearch');
if (search) {
    search.addEventListener('input', function() {
        var q = (search.value || '').toLowerCase().trim();
        document.querySelectorAll('#mailList .mail-item').forEach(function(item) {
            var txt = (item.getAttribute('data-text') || '').toLowerCase();
            item.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
        });
    });
}

var API_URL = '/smns/api/notifications.php';

var markAllReadBtn = document.getElementById('markAllReadBtn');
if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', function() {
        fetch(API_URL + '?action=mark_all_read', { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    window.location.reload();
                } else {
                    alert('Failed to mark all as read.');
                }
            })
            .catch(function() { alert('Network error while marking all as read.'); });
    });
}

document.querySelectorAll('.mark-read-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = btn.getAttribute('data-id');
        fetch(API_URL + '?action=mark_read&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    window.location.reload();
                } else {
                    alert('Failed to mark as read.');
                }
            })
            .catch(function() { alert('Network error while marking as read.'); });
    });
});

document.querySelectorAll('.archive-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = btn.getAttribute('data-id');
        fetch(API_URL + '?action=archive&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    window.location.reload();
                } else {
                    alert('Failed to archive message.');
                }
            })
            .catch(function() { alert('Network error while archiving.'); });
    });
});

document.querySelectorAll('.delete-archive-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = btn.getAttribute('data-id');
        fetch(API_URL + '?action=delete_archive&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    window.location.reload();
                } else {
                    alert('Failed to delete archived message.');
                }
            })
            .catch(function() { alert('Network error while deleting archived message.'); });
    });
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>


