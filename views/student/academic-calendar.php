<?php
require_once dirname(__DIR__, 2) . '/config.php';

$session = new Session('student');
$auth = new Auth('student');

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || ($_SESSION['student_role'] ?? '') !== 'student') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=student');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$studentId = (int)($studentProfile['id'] ?? 0);
$userId = (int)($currentUser['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
$studentDisplayId = resolveDisplayedStudentRegistrationNumber($conn, $studentProfile);

$currentSemester = ['academic_year' => '-', 'semester_name' => '-', 'id' => 0, 'academic_year_id' => 0];
$activeSemester = getStudentCurrentSemesterContext($conn, $studentId);
if (!empty($activeSemester)) {
    $currentSemester['semester_name'] = $activeSemester['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($activeSemester['id'] ?? 0);
    $currentSemester['academic_year'] = $activeSemester['academic_year'] ?? '-';
    $currentSemester['academic_year_id'] = (int)($activeSemester['academic_year_id'] ?? 0);
}
$currentRolloutStageLabel = getRolloutStageLabel(
    (string)($currentSemester['academic_year'] ?? ''),
    (int)($activeSemester['semester_number'] ?? 0),
    (string)($currentSemester['semester_name'] ?? '')
);

$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : (int)($currentSemester['academic_year_id'] ?? 0);
$selectedAcademicYearName = '-';
$selectedAcademicYearStart = null;
$selectedAcademicYearEnd = null;
$allAcademicYears = [];
try {
    $window = getAcademicCalendarDisplayWindowBounds();
    $yStmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE start_date >= :start_date AND start_date <= :end_date ORDER BY start_date DESC, id DESC");
    $yStmt->execute($window);
    $allAcademicYears = $yStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
if ($selectedAcademicYearId <= 0 && !empty($allAcademicYears)) {
    $selectedAcademicYearId = (int)$allAcademicYears[0]['id'];
}
if ($selectedAcademicYearId > 0) {
    $ayStmt = $conn->prepare("SELECT year_name, start_date, end_date FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => $selectedAcademicYearId]);
    $ayRow = $ayStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($ayRow) {
        $selectedAcademicYearName = (string)($ayRow['year_name'] ?? '-');
        $selectedAcademicYearStart = !empty($ayRow['start_date']) ? (string)$ayRow['start_date'] : null;
        $selectedAcademicYearEnd = !empty($ayRow['end_date']) ? (string)$ayRow['end_date'] : null;
    }
}

$semesters = [1 => null, 2 => null];
try {
    $semStmt = $conn->prepare("SELECT * FROM semesters WHERE academic_year_id = :ay AND semester_number IN (1,2) ORDER BY semester_number ASC");
    $semStmt->execute(['ay' => $selectedAcademicYearId]);
    foreach (($semStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $sn = (int)($r['semester_number'] ?? 0);
        if ($sn === 1 || $sn === 2) $semesters[$sn] = $r;
    }
} catch (Exception $e) {}

$today = date('Y-m-d');
$statusFor = function ($startDate, $endDate) use ($today) {
    if (empty($startDate) || empty($endDate)) return ['label' => 'Close', 'class' => 'closed', 'icon' => 'fas fa-minus-circle'];
    if ($today >= $startDate && $today <= $endDate) return ['label' => 'Open', 'class' => 'open', 'icon' => 'fas fa-sync-alt'];
    return ['label' => 'Close', 'class' => 'closed', 'icon' => 'fas fa-minus-circle'];
};
$rowsForSemester = function ($sem) use ($statusFor) {
    $rows = [];
    if (empty($sem)) return $rows;
    $rows[] = ['event' => 'ENROLLMENT', 'description' => 'ENROLLMENT', 'start_date' => $sem['start_date'] ?? '', 'end_date' => $sem['end_date'] ?? '', 'status' => $statusFor($sem['start_date'] ?? null, $sem['end_date'] ?? null)];
    if (!empty($sem['registration_start_date']) && !empty($sem['registration_end_date'])) {
        $rows[] = ['event' => 'REGISTRATION', 'description' => 'REGISTRATION', 'start_date' => $sem['registration_start_date'], 'end_date' => $sem['registration_end_date'], 'status' => $statusFor($sem['registration_start_date'], $sem['registration_end_date'])];
    }
    return $rows;
};
$semesterOneRows = $rowsForSemester($semesters[1]);
$semesterTwoRows = $rowsForSemester($semesters[2]);
$semesterOneCurrent = !empty($semesters[1]['start_date']) && !empty($semesters[1]['end_date']) && $today >= $semesters[1]['start_date'] && $today <= $semesters[1]['end_date'];
$semesterTwoCurrent = !empty($semesters[2]['start_date']) && !empty($semesters[2]['end_date']) && $today >= $semesters[2]['start_date'] && $today <= $semesters[2]['end_date'];

$otherEvents = [];
try {
    $annStmt = $conn->prepare("
        SELECT title, start_date, end_date
        FROM announcements
        WHERE status = 'active'
          AND start_date IS NOT NULL
          AND end_date IS NOT NULL
          AND target_audience IN ('all','students')
          AND (
                :ay_start_null IS NULL OR :ay_end_null IS NULL
                OR (start_date <= :ay_end_cmp AND end_date >= :ay_start_cmp)
              )
        ORDER BY start_date ASC
        LIMIT 20
    ");
    $annStmt->execute([
        'ay_start_null' => $selectedAcademicYearStart,
        'ay_end_null' => $selectedAcademicYearEnd,
        'ay_start_cmp' => $selectedAcademicYearStart,
        'ay_end_cmp' => $selectedAcademicYearEnd
    ]);
    foreach (($annStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $a) {
        $t = strtoupper((string)($a['title'] ?? 'EVENT'));
        $ev = 'EVENT';
        if (stripos($t, 'ENROLL') !== false) $ev = 'ENROLLMENT';
        elseif (stripos($t, 'REGIST') !== false) $ev = 'REGISTRATION';
        $otherEvents[] = ['event' => $ev, 'description' => $ev, 'start_date' => $a['start_date'] ?? '', 'end_date' => $a['end_date'] ?? '', 'status' => $statusFor($a['start_date'] ?? null, $a['end_date'] ?? null)];
    }
} catch (Exception $e) {}

$approvedFeesAmount = 0.0;
$outstandingBalance = 0.0;
$balanceOnAccount = 0.0;
if ($studentId > 0 && $currentSemester['id'] > 0) {
    $financialSnapshot = getStudentFinancialSnapshot(
        $conn,
        $studentId,
        (int)$currentSemester['id'],
        (int)($studentProfile['program_id'] ?? 0),
        (int)($currentSemester['academic_year_id'] ?? 0),
        (int)($studentProfile['level_year'] ?? ($studentProfile['year_of_study'] ?? 1))
    );
    $approvedFeesAmount = (float)($financialSnapshot['approved_total_fees'] ?? 0);
    $outstandingBalance = (float)($financialSnapshot['balance_due'] ?? 0);
    $balanceOnAccount = (float)($financialSnapshot['balance_on_account'] ?? $outstandingBalance);
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
$studentCountryForCurrency = trim((string)($studentProfile['country'] ?? ''));
$studentNationalityForCurrency = trim((string)($studentProfile['nationality'] ?? ''));
if ($studentId > 0) {
    try {
        $pStmt = $conn->prepare("SELECT p.program_name, s.country, s.nationality FROM students s LEFT JOIN programs p ON s.program_id = p.id WHERE s.id = :student_id LIMIT 1");
        $pStmt->execute(['student_id' => $studentId]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($pRow['program_name'])) {
            $registeredProgramName = (string)$pRow['program_name'];
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
        if (!empty($pRow['country'])) {
            $studentCountryForCurrency = trim((string)$pRow['country']);
        }
        if (!empty($pRow['nationality'])) {
            $studentNationalityForCurrency = trim((string)$pRow['nationality']);
        }
    } catch (Exception $e) {}
}

$normalizeGeo = function ($value) {
    $v = strtolower(trim((string)$value));
    $v = preg_replace('/[^a-z]/', '', $v);
    return $v;
};
$countryNorm = $normalizeGeo($studentCountryForCurrency);
$nationalityNorm = $normalizeGeo($studentNationalityForCurrency);
$ugandaTokens = ['uganda', 'ugandan', 'ug'];
$isUgandanStudent = false;
if ($nationalityNorm !== '') {
    // Nationality takes priority for fee display currency.
    $isUgandanStudent = in_array($nationalityNorm, $ugandaTokens, true);
} elseif ($countryNorm !== '') {
    $isUgandanStudent = in_array($countryNorm, $ugandaTokens, true);
} else {
    $isUgandanStudent = true;
}
$isInternationalStudent = !$isUgandanStudent;
$studentDisplayCurrency = $isInternationalStudent ? 'USD' : 'UGX';
$usdUgxRate = (float)Helper::getUsdUgxRate();
if ($usdUgxRate <= 0) {
    $usdUgxRate = 3700.0;
}
$formatCurrencyForDisplay = function ($amountUgx) use ($isInternationalStudent, $usdUgxRate, $studentDisplayCurrency) {
    $amount = (float)$amountUgx;
    if ($isInternationalStudent) {
        $amount = $amount / $usdUgxRate;
    }
    return Helper::formatCurrency($amount, $studentDisplayCurrency, $isInternationalStudent ? 2 : 0);
};

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
$linkAcademicCalendar = 'academic-calendar.php';

$mailUnreadCount = $userId > 0 ? getUnreadNotificationCountForUser((int)$userId) : 0;

$fmtDate = function ($d) {
    if (empty($d) || $d === '0000-00-00') return '-';
    $ts = strtotime($d);
    return $ts ? date('jS M Y', $ts) : '-';
};

$pageTitle = 'Academic Calendar - ' . APP_NAME;
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
body{background:#f2f4f7}.student-sidebar{width:230px;background:linear-gradient(180deg,#fff 0%,#f8fafc 100%);min-height:100vh;height:100vh;overflow-y:auto;overflow-x:hidden;border-right:1px solid #e5e7eb;position:fixed;left:0;top:0;z-index:100;box-shadow:2px 0 12px rgba(15,23,42,.04);transition:transform .25s ease}.student-sidebar ul{list-style:none;padding:10px 8px;margin:0}.student-sidebar>ul{padding-bottom:20px}.student-sidebar li{padding:9px 12px;margin-bottom:4px;border:1px solid transparent;border-radius:8px;font-size:.82rem;letter-spacing:.02em;color:#334155;cursor:pointer;transition:all .2s ease}.student-sidebar li a{color:inherit;text-decoration:none;display:block}.student-sidebar li.active{background:#eaf2ff;border-color:#bfdbfe;color:#1d4ed8;font-weight:700}.student-sidebar li:hover{background:#f1f5f9;color:#0f172a}.student-sidebar.sidebar-collapsed{transform:translateX(-100%)}.sidebar-user-card {
    margin: 0.4rem 0.45rem 0.15rem;
    background: linear-gradient(180deg, #31465d 0%, #243547 100%);
    border-radius: 10px;
    color: #fff;
    text-align: center;
    padding: 0.4rem 0.45rem 0.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.28rem;
}.sidebar-user-card img { width: 126px; height: 126px; border-radius: 16px; object-fit: cover; border: 2px solid rgba(255,255,255,0.82); box-shadow: 0 8px 18px rgba(0,0,0,0.2); }.sidebar-user-name { font-size: 0.8rem; line-height: 1.12; margin: 0; }.sidebar-user-no { font-size: 1rem; font-weight: 700; line-height: 1.08; margin: 0; }

.sidebar-portal-title { font-size: 0.6rem; letter-spacing: 0.07em; text-transform: uppercase; color: #d7e3f3; margin-bottom: 0.12rem; font-weight: 700; }
.main-content{margin-left:230px;width:calc(100vw - 230px);max-width:calc(100vw - 230px);min-height:100vh;transition:margin-left .25s ease,width .25s ease}.main-content.full-width{margin-left:0;width:100vw;max-width:100vw}.student-topbar{display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid #e5e7eb;padding:.5rem 1.2rem;position:sticky;top:0;z-index:10;--key-btn-bg:#fff;--key-btn-border:#e5e7eb;--key-btn-color:#0f172a}.student-profile-pic { width: 64px; height: 64px; border-radius: 14px; object-fit: cover; border: 2px solid #e5e7eb; }.chip-row{padding:.45rem 1.2rem .2rem;display:flex;align-items:center;gap:.35rem;white-space:nowrap}.chip{border-radius:6px;padding:4px 8px;font-weight:600;font-size:.78rem;line-height:1;white-space:nowrap}.chip.gray{background:#f1f5f9;color:#222}.chip.blue{background:#1f7aa8;color:#fff}.chip.red{background:#fee2e2;color:#991b1b}html[data-theme='dark'] .student-topbar{--key-btn-bg:rgba(15,23,42,0.9);--key-btn-border:rgba(148,163,184,0.5);--key-btn-color:#f8fafc)}html[data-theme='dark'] #keyDropMenu{background:#0f172a;color:#e2e8f0;border-color:#334155;box-shadow:0 2px 12px rgba(2,6,23,0.65)}html[data-theme='dark'] #keyDropMenu label{color:#e2e8f0}html[data-theme='dark'] #keyDropMenu input.form-control{background:#0b1220;color:#e2e8f0;border-color:#334155}html[data-theme='dark'] #keyDropMenu input.form-control::placeholder{color:#94a3b8}
.cal-wrap{padding:.9rem 1.2rem 1.3rem}.cal-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px}.cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.cal-title{margin:0;font-size:2rem;font-weight:400;color:#20262d;text-align:center;width:100%}.cal-year-pick{width:220px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:.88rem}.cal-block{margin-bottom:18px;border:1px solid #d1d5db}.cal-block-head{background:#2f4052;color:#fff;font-size:1.7rem;font-weight:700;padding:7px 10px;display:flex;align-items:center;justify-content:space-between}.cal-current{background:#ecfdf3;color:#359c0f;border:1px solid #84cc16;border-radius:8px;padding:4px 11px;font-size:1.05rem;line-height:1;font-weight:700}.cal-table{width:100%;border-collapse:collapse}.cal-table th,.cal-table td{border-top:1px solid #d1d5db;padding:10px 12px;font-size:1rem;color:#2a3138}.cal-table th{font-weight:700;text-align:left}.status-pill{display:inline-flex;align-items:center;gap:7px;border-radius:8px;padding:5px 12px;font-size:1rem;font-weight:500}.status-pill.open{color:#359c0f;background:#ecfdf3;border:1px solid #a3d98c}.status-pill.closed{color:#dc5b2c;background:#fff1ea;border:1px solid #f3ad8d}
@media(max-width:1200px){.cal-title{font-size:1.4rem;text-align:left}.cal-table{display:block;overflow-x:auto}.cal-table th,.cal-table td{white-space:nowrap;font-size:.9rem}.cal-block-head{font-size:1.3rem}}
</style>

<div class="student-sidebar">
  <div class="sidebar-user-card">
        <div class="sidebar-portal-title">SMNS-STUDENT PORTAL</div>
    <?php if (!empty($studentProfile['photo'])): ?><img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile"><?php else: ?><img src="/assets/img/student_sample.jpg" alt="Profile"><?php endif; ?>
    <div class="sidebar-user-name"><?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?></div>
    <div class="sidebar-user-no"><?php echo e($studentDisplayId); ?></div>
  </div>
  <ul>
    <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
    <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
    <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
    <li><a href="<?php echo e($linkProgramme); ?>">MY COURSES & RESULTS</a></li>
    <li><a href="services.php?tab=apply">SERVICES</a></li>
    <ul class="services-submenu">
      <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
      <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
      <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
    </ul>
    <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
    <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
    <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
    <li class="active"><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
  </ul>
</div>

<div class="main-content">
  <div class="student-topbar">
    <div style="display:flex;align-items:center;gap:.7rem;">
      <button id="menuBtn" style="background:none;border:none;font-size:1.1rem;cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
      <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#f1f5f9;color:#222;border:1px solid #e5e7eb;border-radius:5px;padding:5px 10px;font-size:.92rem;font-weight:600;">VIEW BIO DATA</button>
      <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9;color:#222;border:1px solid #e5e7eb;border-radius:5px;padding:5px 10px;font-size:.92rem;font-weight:600;">VIEW RESULTS</button>
      <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9;color:#222;border:1px solid #e5e7eb;border-radius:5px;padding:5px 10px;font-size:.92rem;font-weight:600;">VIEW INVOICES</button>
      <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9;color:#222;border:1px solid #e5e7eb;border-radius:5px;padding:5px 10px;font-size:.92rem;font-weight:600;">VIEW FEES STRUCTURE</button>
      <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9;color:#222;border:1px solid #e5e7eb;border-radius:5px;padding:5px 10px;font-size:.92rem;font-weight:600;">Generate PRN</button>
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;position:relative;">
      <?php if (!empty($studentProfile['photo'])): ?><img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic"><?php else: ?><img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic"><?php endif; ?>
      <span style="font-size:.98rem;color:#222;font-weight:600;white-space:nowrap;"><?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?></span>
      <a href="<?php echo e($linkMailbox); ?>" title="My Mailbox" style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid #dbe3ef;border-radius:50%;color:#1f7aa8;text-decoration:none;background:#fff;"><i class="far fa-envelope"></i><?php if ($mailUnreadCount > 0): ?><span style="position:absolute;top:-6px;right:-6px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;line-height:16px;text-align:center;"><?php echo $mailUnreadCount > 99 ? '99+' : $mailUnreadCount; ?></span><?php endif; ?></a>
      <div class="profile-dropdown" style="position:relative;"><button id="profileDropBtn" style="background:none;border:none;font-size:0.98rem;cursor:pointer;padding:0 6px;"><i class="fas fa-chevron-down"></i></button><div id="profileDropMenu" style="display:none;position:absolute;top:120%;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.08);min-width:140px;z-index:100;"><a href="dashboard.php" style="display:block;padding:8px 14px;color:#1f2937;text-decoration:none;font-weight:600;font-size:0.92rem;border-bottom:1px solid #f1f5f9;">Profile</a><a href="services.php?tab=apply" style="display:block;padding:8px 14px;color:#1f2937;text-decoration:none;font-weight:600;font-size:0.92rem;border-bottom:1px solid #f1f5f9;">Services</a><a href="logout.php" style="display:block;padding:8px 14px;color:#dc2626;text-decoration:none;font-weight:600;font-size:0.92rem;">Logout</a></div></div>
      <div class="profile-dropdown" style="position:relative;"><button id="keyDropBtn" style="background:var(--key-btn-bg,#fff);border:1px solid var(--key-btn-border,#e5e7eb);border-radius:50%;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:var(--key-btn-color,#0f172a);"><i class="fas fa-key"></i></button><div id="keyDropMenu" style="display:none;position:absolute;top:120%;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.12);min-width:260px;padding:12px;z-index:100;"><div style="font-weight:700;font-size:0.92rem;margin-bottom:8px;color:#0f172a;"><i class="fas fa-key" style="margin-right:6px;"></i> Change Password</div><form id="keyChangePasswordForm" method="POST" action="change-password.php"><?php echo csrfField(); ?><div class="form-group" style="margin-bottom:8px;"><label style="font-size:0.82rem;margin-bottom:4px;">Current Password</label><input type="password" name="current_password" class="form-control" required></div><div class="form-group" style="margin-bottom:8px;"><label style="font-size:0.82rem;margin-bottom:4px;">New Password</label><input type="password" name="new_password" class="form-control" required></div><div class="form-group" style="margin-bottom:10px;"><label style="font-size:0.82rem;margin-bottom:4px;">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div><div id="keyChangePasswordMsg" style="display:none;font-size:0.82rem;margin-bottom:8px;"></div><button type="submit" class="btn btn-sm btn-primary btn-block">Update Password</button></form></div></div>
    </div>
  </div>

  <div class="chip-row"><span style="font-size:1rem;color:#1f7aa8;">PROGRAMME:</span><span style="font-size:1rem;"><?php echo e($registeredProgramName); ?></span><span class="chip" style="background:#16a34a;color:#fff;">ACTIVE</span><span style="margin-left:auto;font-size:1rem;color:#1f7aa8;">ACADEMIC STATUS:</span><span class="chip red" style="<?php echo e($academicStatusStyle); ?>"><?php echo e($academicStatus); ?></span></div>
  <div class="chip-row"><span class="chip gray">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span><span class="chip gray">CURRENT CALENDAR. <span style="color:#2563eb;"><?php echo e($currentRolloutStageLabel); ?></span></span><span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?></span><span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?></span><span class="chip gray">APPROVED FEES AMOUNT: <?php echo $formatCurrencyForDisplay((float)$approvedFeesAmount); ?></span><span class="chip blue">BALANCE ON ACCOUNT: <?php echo $formatCurrencyForDisplay((float)$balanceOnAccount); ?></span></div>

  <div class="cal-wrap"><div class="cal-card">
    <form method="GET" class="cal-head"><div style="min-width:220px;"><select class="cal-year-pick" name="academic_year_id" onchange="this.form.submit()"><?php foreach ($allAcademicYears as $y): ?><option value="<?php echo (int)$y['id']; ?>" <?php echo (int)$selectedAcademicYearId === (int)$y['id'] ? 'selected' : ''; ?>><?php echo e(getRolloutStageLabel((string)$y['year_name'], 1)); ?></option><?php endforeach; ?></select></div><h2 class="cal-title">ACADEMIC YEAR - <?php echo e(getRolloutStageLabel((string)$selectedAcademicYearName, 1)); ?></h2><div style="min-width:220px;"></div></form>

    <div class="cal-block"><div class="cal-block-head"><span>SEMESTER I</span><?php if ($semesterOneCurrent): ?><span class="cal-current">Current</span><?php endif; ?></div><table class="cal-table"><thead><tr><th>EVENT</th><th>DESCRIPTION</th><th>START DATE</th><th>END DATE</th><th>STATUS</th></tr></thead><tbody><?php if (empty($semesterOneRows)): ?><tr><td colspan="5">No semester I events found.</td></tr><?php else: foreach ($semesterOneRows as $row): ?><tr><td><?php echo e($row['event']); ?></td><td><?php echo e($row['description']); ?></td><td><?php echo e($fmtDate($row['start_date'])); ?></td><td><?php echo e($fmtDate($row['end_date'])); ?></td><td><span class="status-pill <?php echo e($row['status']['class']); ?>"><i class="<?php echo e($row['status']['icon']); ?>"></i> <?php echo e($row['status']['label']); ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div>

    <div class="cal-block"><div class="cal-block-head"><span>SEMESTER II</span><?php if ($semesterTwoCurrent): ?><span class="cal-current">Current</span><?php endif; ?></div><table class="cal-table"><thead><tr><th>EVENT</th><th>DESCRIPTION</th><th>START DATE</th><th>END DATE</th><th>STATUS</th></tr></thead><tbody><?php if (empty($semesterTwoRows)): ?><tr><td colspan="5">No semester II events found.</td></tr><?php else: foreach ($semesterTwoRows as $row): ?><tr><td><?php echo e($row['event']); ?></td><td><?php echo e($row['description']); ?></td><td><?php echo e($fmtDate($row['start_date'])); ?></td><td><?php echo e($fmtDate($row['end_date'])); ?></td><td><span class="status-pill <?php echo e($row['status']['class']); ?>"><i class="<?php echo e($row['status']['icon']); ?>"></i> <?php echo e($row['status']['label']); ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div>

    <div class="cal-block" style="margin-bottom:0;"><div class="cal-block-head"><span>OTHER EVENTS</span></div><table class="cal-table"><thead><tr><th>EVENT</th><th>DESCRIPTION</th><th>START DATE</th><th>END DATE</th><th>STATUS</th></tr></thead><tbody><?php if (empty($otherEvents)): ?><tr><td colspan="5">No additional events found for this academic year.</td></tr><?php else: foreach ($otherEvents as $row): ?><tr><td><?php echo e($row['event']); ?></td><td><?php echo e($row['description']); ?></td><td><?php echo e($fmtDate($row['start_date'])); ?></td><td><?php echo e($fmtDate($row['end_date'])); ?></td><td><span class="status-pill <?php echo e($row['status']['class']); ?>"><i class="<?php echo e($row['status']['icon']); ?>"></i> <?php echo e($row['status']['label']); ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div>

  </div></div>
</div>

<script>
document.getElementById('menuBtn').addEventListener('click', function(){var s=document.querySelector('.student-sidebar');var m=document.querySelector('.main-content');s.classList.toggle('sidebar-collapsed');if(m)m.classList.toggle('full-width');});
document.getElementById('profileDropBtn').addEventListener('click', function(e){e.stopPropagation();var menu=document.getElementById('profileDropMenu');menu.style.display=menu.style.display==='block'?'none':'block';});
document.getElementById('keyDropBtn').addEventListener('click', function(e){e.stopPropagation();var menu=document.getElementById('keyDropMenu');menu.style.display=menu.style.display==='block'?'none':'block';});
var keyForm=document.getElementById('keyChangePasswordForm');if(keyForm){keyForm.addEventListener('submit',function(e){e.preventDefault();var msg=document.getElementById('keyChangePasswordMsg');var submitBtn=keyForm.querySelector('button[type=\"submit\"]');if(submitBtn)submitBtn.disabled=true;if(msg){msg.style.display='none';msg.textContent='';}fetch('change-password.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(keyForm)}).then(function(res){return res.json();}).then(function(data){if(msg){msg.style.display='block';if(data&&data.success){msg.style.color='#166534';msg.textContent=data.message||'Password updated.';keyForm.reset();}else{msg.style.color='#b91c1c';msg.textContent=(data&&(data.error||data.message))?(data.error||data.message):'Unable to update password.';}}}).catch(function(){if(msg){msg.style.display='block';msg.style.color='#b91c1c';msg.textContent='Unable to update password.';}}).finally(function(){if(submitBtn)submitBtn.disabled=false;});});}
document.addEventListener('click', function(){var menu=document.getElementById('profileDropMenu');if(menu)menu.style.display='none';var keyMenu=document.getElementById('keyDropMenu');if(keyMenu)keyMenu.style.display='none';});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>



