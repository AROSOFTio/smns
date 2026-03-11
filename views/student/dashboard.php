
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

/**
 * Ensure student profile-completion fields exist.
 * This is safe to run repeatedly.
 */
function ensureStudentProfileCompletionColumns(PDO $conn) {
    $columns = [
        'religion' => 'VARCHAR(100) NULL',
        'district' => 'VARCHAR(100) NULL',
        'parish' => 'VARCHAR(100) NULL AFTER district',
        'nationality' => 'VARCHAR(100) NULL',
        'national_id' => 'VARCHAR(100) NULL',
        'passport' => 'VARCHAR(100) NULL',
        'guardian_name' => 'VARCHAR(200) NULL',
        'guardian_relation' => 'VARCHAR(100) NULL',
        'guardian_email' => 'VARCHAR(150) NULL',
        'guardian_phone' => 'VARCHAR(30) NULL',
        'profile_locked' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'profile_completed_at' => 'DATETIME NULL'
    ];

    foreach ($columns as $column => $definition) {
        try {
            $exists = $conn->query("SHOW COLUMNS FROM students LIKE '{$column}'")->fetch();
            if (!$exists) {
                $conn->exec("ALTER TABLE students ADD COLUMN {$column} {$definition}");
            }
        } catch (Exception $e) {
            // Non-fatal: continue with available columns.
        }
    }
}

ensureStudentProfileCompletionColumns($conn);

$studentProfileLocked = 0;
$studentExistingPhoto = '';
if ($studentDbId > 0) {
    try {
        $metaStmt = $conn->prepare("SELECT COALESCE(profile_locked, 0) AS profile_locked, photo FROM students WHERE id = :student_id LIMIT 1");
        $metaStmt->execute(['student_id' => $studentDbId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentProfileLocked = (int)($meta['profile_locked'] ?? 0);
        $studentExistingPhoto = (string)($meta['photo'] ?? '');
    } catch (Exception $e) {
        $studentProfileLocked = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_profile_once' && $studentDbId > 0) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    // Re-check lock state directly from DB to prevent bypass.
    try {
        $lockStmt = $conn->prepare("SELECT COALESCE(profile_locked, 0) AS profile_locked, photo FROM students WHERE id = :student_id LIMIT 1");
        $lockStmt->execute(['student_id' => $studentDbId]);
        $lockData = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $lockedNow = (int)($lockData['profile_locked'] ?? 0);
        $studentExistingPhoto = (string)($lockData['photo'] ?? '');
    } catch (Exception $e) {
        $lockedNow = $studentProfileLocked;
    }

    if ($lockedNow === 1) {
        $session->setFlash('error', 'Your profile is already completed and locked.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    $phone = trim(Security::sanitize($_POST['phone'] ?? ''));
    $religion = trim(Security::sanitize($_POST['religion'] ?? ''));
    $district = trim(Security::sanitize($_POST['district'] ?? ''));
    $parish = trim(Security::sanitize($_POST['parish'] ?? ''));
    $nationality = trim(Security::sanitize($_POST['nationality'] ?? ''));
    $nationalId = trim(Security::sanitize($_POST['national_id'] ?? ''));
    $passport = trim(Security::sanitize($_POST['passport'] ?? ''));
    $guardianName = trim(Security::sanitize($_POST['guardian_name'] ?? ''));
    $guardianRelation = trim(Security::sanitize($_POST['guardian_relation'] ?? ''));
    $guardianEmail = trim($_POST['guardian_email'] ?? '');
    $guardianPhone = trim(Security::sanitize($_POST['guardian_phone'] ?? ''));
    $nextKinName = trim(Security::sanitize($_POST['next_of_kin_name'] ?? ''));
    $nextKinPhone = trim(Security::sanitize($_POST['next_of_kin_phone'] ?? ''));
    $nextKinRelationship = trim(Security::sanitize($_POST['next_of_kin_relationship'] ?? ''));

    $validationErrors = [];
    if ($phone === '') $validationErrors[] = 'Telephone is required.';
    if ($religion === '') $validationErrors[] = 'Religion is required.';
    if ($district === '') $validationErrors[] = 'District is required.';
    if ($parish === '') $validationErrors[] = 'Parish is required.';
    if ($nationality === '') $validationErrors[] = 'Nationality is required.';
    if ($nationalId === '') $validationErrors[] = 'National ID number is required.';
    if ($guardianName === '') $validationErrors[] = 'Guardian name is required.';
    if ($guardianRelation === '') $validationErrors[] = 'Guardian relation is required.';
    if ($guardianPhone === '') $validationErrors[] = 'Guardian phone is required.';
    if ($guardianEmail !== '' && !filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) $validationErrors[] = 'Guardian email must be valid if provided.';
    if ($nextKinName === '') $validationErrors[] = 'Next of kin name is required.';
    if ($nextKinPhone === '') $validationErrors[] = 'Next of kin phone is required.';
    if ($nextKinRelationship === '') $validationErrors[] = 'Next of kin relationship is required.';

    $photoPath = $studentExistingPhoto !== '' ? $studentExistingPhoto : null;
    $photoFile = $_FILES['profile_photo'] ?? null;
    $hasUpload = !empty($photoFile['name']) && (int)($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$hasUpload && empty($photoPath)) {
        $validationErrors[] = 'Student profile photo is required.';
    }

    if ($hasUpload) {
        if (($photoFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $validationErrors[] = 'Failed to upload profile photo.';
        } else {
            $ext = strtolower(pathinfo($photoFile['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];
            if (!in_array($ext, $allowed, true)) {
                $validationErrors[] = 'Profile photo must be JPG or PNG.';
            }
            if (!empty($photoFile['size']) && (int)$photoFile['size'] > MAX_FILE_SIZE) {
                $validationErrors[] = 'Profile photo exceeds maximum upload size.';
            }
        }
    }

    if (!empty($validationErrors)) {
        $session->setFlash('error', implode(' ', $validationErrors));
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    if ($hasUpload) {
        $uploadDir = UPLOAD_PATH . '/students/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $ext = strtolower(pathinfo($photoFile['name'], PATHINFO_EXTENSION));
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($studentProfile['student_id'] ?? $studentDbId));
        $fileName = 'student_' . ($ref !== '' ? $ref : $studentDbId) . '_' . time() . '.' . $ext;
        $target = $uploadDir . $fileName;

        if (!@move_uploaded_file($photoFile['tmp_name'], $target)) {
            $session->setFlash('error', 'Failed to save uploaded profile photo.');
            header('Location: ' . BASE_URL . '/views/student/dashboard.php');
            exit;
        }
        $photoPath = 'uploads/students/' . $fileName;
    }

    try {
        $conn->beginTransaction();
        $updStmt = $conn->prepare("
            UPDATE students
            SET phone = :phone,
                religion = :religion,
                district = :district,
                parish = :parish,
                nationality = :nationality,
                national_id = :national_id,
                passport = :passport,
                guardian_name = :guardian_name,
                guardian_relation = :guardian_relation,
                guardian_email = :guardian_email,
                guardian_phone = :guardian_phone,
                emergency_contact_name = :next_kin_name,
                emergency_contact_phone = :next_kin_phone,
                emergency_contact_relationship = :next_kin_relationship,
                photo = :photo,
                profile_locked = 1,
                profile_completed_at = NOW(),
                updated_at = NOW()
            WHERE id = :student_id
        ");
        $updStmt->execute([
            'phone' => $phone,
            'religion' => $religion,
            'district' => $district,
            'parish' => $parish,
            'nationality' => $nationality,
            'national_id' => $nationalId,
            'passport' => $passport !== '' ? $passport : null,
            'guardian_name' => $guardianName,
            'guardian_relation' => $guardianRelation,
            'guardian_email' => $guardianEmail !== '' ? $guardianEmail : null,
            'guardian_phone' => $guardianPhone,
            'next_kin_name' => $nextKinName,
            'next_kin_phone' => $nextKinPhone,
            'next_kin_relationship' => $nextKinRelationship,
            'photo' => $photoPath,
            'student_id' => $studentDbId
        ]);

        $refreshStmt = $conn->prepare("SELECT * FROM students WHERE id = :student_id LIMIT 1");
        $refreshStmt->execute(['student_id' => $studentDbId]);
        $freshProfile = $refreshStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($freshProfile) {
            $_SESSION['student_profile'] = $freshProfile;
        }

        $conn->commit();
        $session->setFlash('success', 'Profile completed successfully. Your profile is now locked.');
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $session->setFlash('error', 'Failed to save profile information.');
    }

    header('Location: ' . BASE_URL . '/views/student/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_contacts' && $studentDbId > 0) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . BASE_URL . '/views/student/dashboard.php');
        exit;
    }

    if ($studentProfileLocked === 1) {
        $session->setFlash('error', 'Profile updates are locked.');
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
    $policyErrors = [];
    if (!Security::validatePasswordPolicy($newPassword, $policyErrors)) {
        $session->setFlash('error', implode(' ', $policyErrors));
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
        if (Security::isPasswordReused($conn, (int)$currentUserId, $newPassword)) {
            $session->setFlash('error', 'You cannot reuse a recent password.');
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

if (!empty($studentRow)) {
    $studentProfile = array_merge($studentProfile, $studentRow);
    $studentProfileLocked = (int)($studentRow['profile_locked'] ?? $studentProfileLocked);
}

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];

$studentSemesterContext = getStudentCurrentSemesterContext($conn, (int)($studentProfile['id'] ?? 0));
if (!empty($studentSemesterContext['id'])) {
    $currentSemester['semester_name'] = $studentSemesterContext['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($studentSemesterContext['id'] ?? 0);
    $currentSemester['academic_year'] = $studentSemesterContext['academic_year'] ?? '-';
}

$approvedFeesAmount = 0.0;
$outstandingBalance = 0.0;
$balanceOnAccount = 0.0;
if ($studentDbId > 0 && $currentSemester['id'] > 0) {
    $financialSnapshot = getStudentFinancialSnapshot(
        $conn,
        $studentDbId,
        (int)$currentSemester['id'],
        (int)($studentProfile['program_id'] ?? 0),
        (int)($studentSemesterContext['academic_year_id'] ?? 0),
        (int)($studentProfile['level_year'] ?? ($studentProfile['year_of_study'] ?? 1))
    );
    $approvedFeesAmount = (float)($financialSnapshot['approved_total_fees'] ?? 0);
    $outstandingBalance = (float)($financialSnapshot['balance_due'] ?? 0);
    $balanceOnAccount = (float)($financialSnapshot['balance_on_account'] ?? $outstandingBalance);
}

$academicStatusMeta = getStudentAcademicStatusMeta(
    $conn,
    (int)($studentProfile['id'] ?? 0),
    (int)($currentSemester['id'] ?? 0),
    (string)($studentProfile['academic_status'] ?? '')
);
$academicStatus = (string)($academicStatusMeta['label'] ?? 'Status Pending');
$academicStatusStyle = (string)($academicStatusMeta['style'] ?? getAcademicStatusChipStyle('neutral'));

// Resolve study progress as "Year X Sem Y" using approved semester registrations.
$resolvedStudyYear = (int)($studentRow['level_year'] ?? ($studentProfile['level_year'] ?? ($studentProfile['year_of_study'] ?? 0)));
$resolvedStudySemesterNumber = (int)($currentSemester['semester_number'] ?? 0);
if ($studentDbId > 0) {
    try {
        $studyProgressStmt = $conn->prepare("
            SELECT
                COALESCE(sr.year_of_study, s.level_year, s.year_of_study, 0) AS study_year,
                COALESCE(sem.semester_number, 0) AS semester_number
            FROM semester_registrations sr
            INNER JOIN students s ON s.id = sr.student_id
            INNER JOIN semesters sem ON sem.id = sr.semester_id
            WHERE sr.student_id = :student_id
              AND sr.status = 'approved'
            ORDER BY
                CASE WHEN sr.semester_id = :current_semester_id THEN 0 ELSE 1 END ASC,
                COALESCE(sr.updated_at, sr.approval_date, sr.request_date, sr.created_at) DESC,
                sr.id DESC
            LIMIT 1
        ");
        $studyProgressStmt->execute([
            'student_id' => $studentDbId,
            'current_semester_id' => (int)($currentSemester['id'] ?? 0)
        ]);
        $studyProgressRow = $studyProgressStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $approvedStudyYear = (int)($studyProgressRow['study_year'] ?? 0);
        $approvedSemesterNumber = (int)($studyProgressRow['semester_number'] ?? 0);
        if ($approvedStudyYear > 0) {
            $resolvedStudyYear = $approvedStudyYear;
        }
        if ($approvedSemesterNumber > 0) {
            $resolvedStudySemesterNumber = $approvedSemesterNumber;
        }
    } catch (Exception $e) {
        // Keep fallback values when registrations are unavailable.
    }
}
if ($resolvedStudyYear <= 0) {
    $resolvedStudyYear = 1;
}
$studyProgressLabel = 'Year ' . (int)$resolvedStudyYear;
if ($resolvedStudySemesterNumber > 0) {
    $studyProgressLabel .= ' Sem ' . (int)$resolvedStudySemesterNumber;
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

$studentCountryForCurrency = trim((string)($studentProfile['country'] ?? ''));
$studentNationalityForCurrency = trim((string)($studentProfile['nationality'] ?? ''));
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
    html[data-theme='dark'] .student-topbar {
        --key-btn-bg: rgba(15, 23, 42, 0.9);
        --key-btn-border: rgba(148, 163, 184, 0.5);
        --key-btn-color: #f8fafc;
    }
    html[data-theme='dark'] #keyDropMenu {
        background: #0f172a !important;
        color: #e2e8f0 !important;
        border-color: #334155 !important;
        box-shadow: 0 2px 12px rgba(2, 6, 23, 0.65) !important;
    }
    html[data-theme='dark'] #keyDropMenu label {
        color: #e2e8f0 !important;
    }
    html[data-theme='dark'] #keyDropMenu input.form-control {
        background: #0b1220 !important;
        color: #e2e8f0 !important;
        border-color: #334155 !important;
    }
    html[data-theme='dark'] #keyDropMenu input.form-control::placeholder {
        color: #94a3b8 !important;
    }
    html[data-theme='dark'] #keyDropMenu input:-webkit-autofill,
    html[data-theme='dark'] #keyDropMenu input:-webkit-autofill:hover,
    html[data-theme='dark'] #keyDropMenu input:-webkit-autofill:focus,
    html[data-theme='dark'] #keyDropMenu input:-webkit-autofill:active {
        -webkit-text-fill-color: #e2e8f0 !important;
        caret-color: #e2e8f0 !important;
        box-shadow: 0 0 0 1000px #0b1220 inset !important;
        border-color: #334155 !important;
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

.profile-lock-overlay {
    position: fixed;
    inset: 0;
    z-index: 2000;
    background: rgba(15, 23, 42, 0.72);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.profile-lock-modal {
    width: min(980px, 100%);
    max-height: 95vh;
    overflow: auto;
    background: #fff;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 20px 45px rgba(2, 6, 23, 0.35);
    padding: 1rem 1.1rem 1.15rem;
}
.profile-lock-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 0.35rem;
}
.profile-lock-subtitle {
    font-size: 0.84rem;
    color: #334155;
    margin-bottom: 0.75rem;
}
.profile-lock-section {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 0.7rem;
    margin-bottom: 0.7rem;
}
.profile-lock-section h6 {
    margin: 0 0 0.55rem;
    font-size: 0.83rem;
    font-weight: 700;
    color: #1e293b;
}
html[data-theme='dark'] .profile-lock-overlay {
    background: rgba(2, 6, 23, 0.84);
}
html[data-theme='dark'] .profile-lock-modal {
    background: #111827;
    border-color: #334155;
    box-shadow: 0 24px 48px rgba(2, 6, 23, 0.72);
}
html[data-theme='dark'] .profile-lock-title {
    color: #f8fafc;
}
html[data-theme='dark'] .profile-lock-subtitle {
    color: #cbd5e1;
}
html[data-theme='dark'] .profile-lock-section {
    background: #0f172a;
    border-color: #334155;
}
html[data-theme='dark'] .profile-lock-section h6 {
    color: #e2e8f0;
}
html[data-theme='dark'] .profile-lock-modal label {
    color: #e5e7eb !important;
}
html[data-theme='dark'] .profile-lock-modal .text-danger {
    color: #fca5a5 !important;
}
html[data-theme='dark'] .profile-lock-modal .form-control,
html[data-theme='dark'] .profile-lock-modal .form-control-sm {
    background: #1f2937;
    color: #e5e7eb;
    border-color: #334155;
}
html[data-theme='dark'] .profile-lock-modal .form-control::placeholder,
html[data-theme='dark'] .profile-lock-modal .form-control-sm::placeholder {
    color: #94a3b8;
}
html[data-theme='dark'] .profile-lock-modal .form-control:focus,
html[data-theme='dark'] .profile-lock-modal .form-control-sm:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 0.2rem rgba(96, 165, 250, 0.22);
}
html[data-theme='dark'] .profile-lock-modal .form-control-file {
    color: #cbd5e1;
}
html[data-theme='dark'] .profile-lock-modal .form-control-file::file-selector-button {
    color: #e5e7eb;
    background: #1f2937;
    border: 1px solid #475569;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    margin-right: 0.5rem;
}
html[data-theme='dark'] .profile-lock-modal .form-control-file::-webkit-file-upload-button {
    color: #e5e7eb;
    background: #1f2937;
    border: 1px solid #475569;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    margin-right: 0.5rem;
}
html[data-theme='dark'] .profile-lock-modal small,
html[data-theme='dark'] .profile-lock-modal .text-muted {
    color: #94a3b8 !important;
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
        <li><a href="<?php echo e($linkProgramme); ?>">MY COURSES & RESULTS</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li class="active"><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar" style="padding:0.5rem 1.2rem; font-size:0.92rem; display:flex; align-items:center; justify-content:space-between; --key-btn-bg:#fff; --key-btn-border:#e5e7eb; --key-btn-color:#0f172a;">
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
                    <a href="change-password.php" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;"><i class="fas fa-key"></i> Change Password</a>
                    <a href="logout.php" style="display:block; padding:8px 14px; color:#dc2626; text-decoration:none; font-weight:600; font-size:0.92rem;">Logout</a>
                </div>
            </div>
            <div class="profile-dropdown" style="position:relative;">
                <button id="keyDropBtn" style="background:var(--key-btn-bg, #fff); border:1px solid var(--key-btn-border, #e5e7eb); border-radius:50%; width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:var(--key-btn-color, #0f172a);">
                    <i class="fas fa-key"></i>
                </button>
                <div id="keyDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.12); min-width:260px; padding:12px; z-index:100;">
                    <div style="font-weight:700; font-size:0.92rem; margin-bottom:8px; color:#0f172a;">
                        <i class="fas fa-key" style="margin-right:6px;"></i> Change Password
                    </div>
                    <form id="keyChangePasswordForm" method="POST" action="change-password.php">
                        <?php echo csrfField(); ?>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:0.82rem; margin-bottom:4px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div id="keyChangePasswordMsg" style="display:none; font-size:0.82rem; margin-bottom:8px;"></div>
                        <button type="submit" class="btn btn-sm btn-primary btn-block">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div style="padding:0.7rem 1.2rem 0.2rem 1.2rem; font-size:0.98rem; font-weight:600; display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap;">
        <span>PROGRAMME: <?php echo e($registeredProgramName); ?></span>
        <span class="status-badge status-active" style="font-size:0.85rem; padding:3px 10px;"><?php echo !empty($studentProfile['status']) ? strtoupper(e($studentProfile['status'])) : 'ACTIVE'; ?></span>
        <span style="margin-left:auto; font-size:1.05rem; color:#222;">ACADEMIC STATUS: <span style="<?php echo e($academicStatusStyle); ?> border-radius:6px; padding:4px 12px; font-weight:600;">
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
document.getElementById('keyDropBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    var menu = document.getElementById('keyDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});
var keyForm = document.getElementById('keyChangePasswordForm');
if (keyForm) {
    keyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = document.getElementById('keyChangePasswordMsg');
        var submitBtn = keyForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        if (msg) {
            msg.style.display = 'none';
            msg.textContent = '';
        }
        fetch('change-password.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(keyForm)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (msg) {
                msg.style.display = 'block';
                if (data && data.success) {
                    msg.style.color = '#166534';
                    msg.textContent = data.message || 'Password updated.';
                    keyForm.reset();
                } else {
                    msg.style.color = '#b91c1c';
                    msg.textContent = (data && (data.error || data.message)) ? (data.error || data.message) : 'Unable to update password.';
                }
            }
        })
        .catch(function() {
            if (msg) {
                msg.style.display = 'block';
                msg.style.color = '#b91c1c';
                msg.textContent = 'Unable to update password.';
            }
        })
        .finally(function() {
            if (submitBtn) submitBtn.disabled = false;
        });
    });
}
document.addEventListener('click', function() {
    var menu = document.getElementById('profileDropMenu');
    if (menu) menu.style.display = 'none';
    var keyMenu = document.getElementById('keyDropMenu');
    if (keyMenu) keyMenu.style.display = 'none';
});
</script>

    <?php if ($studentProfileLocked !== 1): ?>
        <div id="profileCompletionOverlay" class="profile-lock-overlay">
            <div class="profile-lock-modal">
                <div class="profile-lock-title">Complete Your Profile (One-Time Submission)</div>
                <div class="profile-lock-subtitle">
                    Fill all required details below. After submission, profile editing will be locked.
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="complete_profile_once">

                    <div class="profile-lock-section">
                        <h6>Personal Details</h6>
                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Tel. Phone <span class="text-danger">*</span></label>
                                <input type="text" name="phone" class="form-control form-control-sm" required value="<?php echo e($studentRow['phone'] ?? ($studentProfile['phone'] ?? '')); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>Religion <span class="text-danger">*</span></label>
                                <input type="text" name="religion" class="form-control form-control-sm" required value="<?php echo e($studentRow['religion'] ?? ($studentProfile['religion'] ?? '')); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>District <span class="text-danger">*</span></label>
                                <input type="text" name="district" class="form-control form-control-sm" required value="<?php echo e($studentRow['district'] ?? ($studentRow['city'] ?? ($studentProfile['district'] ?? ''))); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>Parish <span class="text-danger">*</span></label>
                                <input type="text" name="parish" class="form-control form-control-sm" required value="<?php echo e($studentRow['parish'] ?? ($studentProfile['parish'] ?? '')); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Nationality <span class="text-danger">*</span></label>
                                <input type="text" name="nationality" class="form-control form-control-sm" required value="<?php echo e($studentRow['nationality'] ?? ($studentRow['country'] ?? ($studentProfile['nationality'] ?? ''))); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>National ID Number <span class="text-danger">*</span></label>
                                <input type="text" name="national_id" class="form-control form-control-sm" required value="<?php echo e($studentRow['national_id'] ?? ($studentProfile['national_id'] ?? '')); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Passport</label>
                                <input type="text" name="passport" class="form-control form-control-sm" value="<?php echo e($studentRow['passport'] ?? ($studentProfile['passport'] ?? '')); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Profile Photo (JPG/PNG) <?php echo empty($studentRow['photo'] ?? $studentProfile['photo'] ?? '') ? '<span class="text-danger">*</span>' : ''; ?></label>
                                <input type="file" name="profile_photo" class="form-control-file" accept=".jpg,.jpeg,.png">
                                <?php if (!empty($studentRow['photo'] ?? $studentProfile['photo'] ?? '')): ?>
                                    <small class="text-muted">Current photo exists. Upload only if you want to replace it before lock.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="profile-lock-section">
                        <h6>Guardian Details</h6>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Guardian Name <span class="text-danger">*</span></label>
                                <input type="text" name="guardian_name" class="form-control form-control-sm" required value="<?php echo e($studentRow['guardian_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-6">
                                <label>Relation <span class="text-danger">*</span></label>
                                <input type="text" name="guardian_relation" class="form-control form-control-sm" required value="<?php echo e($studentRow['guardian_relation'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Guardian Email (Optional)</label>
                                <input type="email" name="guardian_email" class="form-control form-control-sm" value="<?php echo e($studentRow['guardian_email'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-6">
                                <label>Guardian Phone <span class="text-danger">*</span></label>
                                <input type="text" name="guardian_phone" class="form-control form-control-sm" required value="<?php echo e($studentRow['guardian_phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="profile-lock-section">
                        <h6>Next of Kin</h6>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Name <span class="text-danger">*</span></label>
                                <input type="text" name="next_of_kin_name" class="form-control form-control-sm" required value="<?php echo e($studentRow['emergency_contact_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Phone <span class="text-danger">*</span></label>
                                <input type="text" name="next_of_kin_phone" class="form-control form-control-sm" required value="<?php echo e($studentRow['emergency_contact_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Relationship <span class="text-danger">*</span></label>
                                <input type="text" name="next_of_kin_relationship" class="form-control form-control-sm" required value="<?php echo e($studentRow['emergency_contact_relationship'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary btn-sm">Save and Lock Profile</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div style="padding:2.5rem 3rem 1rem 3rem;">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div style="display:flex; align-items:center; gap:0.35rem; margin-bottom:1.2rem; flex-wrap:wrap; white-space:normal;">
            <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">CURRENT YR. <span style="color:#2563eb;"><?php echo !empty($currentSemester['academic_year']) ? e($currentSemester['academic_year']) : '-'; ?></span></span>
            <span style="background:#f1f5f9; color:#222; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">CURRENT SEM. <span style="color:#2563eb;"><?php echo !empty($currentSemester['semester_name']) ? e($currentSemester['semester_name']) : '-'; ?></span></span>
            <span style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'background:#dcfce7; color:#166534; border:1px solid #86efac; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;' : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;'; ?>">
                <?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?>
            </span>
            <span style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'background:#dcfce7; color:#166534; border:1px solid #86efac; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;' : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;'; ?>">
                <?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?>
            </span>
            <div style="display:flex; align-items:center; gap:0.35rem; white-space:nowrap; flex:0 0 auto; margin-left:auto;">
                <span style="background:#f1f5f9; color:#991b1b; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">APPROVED FEES AMOUNT: <?php echo $formatCurrencyForDisplay((float)$approvedFeesAmount); ?></span>
                <span style="background:#2563eb; color:#fff; border-radius:6px; padding:4px 8px; font-weight:600; font-size:0.78rem; line-height:1; white-space:nowrap; flex:0 0 auto;">BALANCE ON ACCOUNT: <?php echo $formatCurrencyForDisplay((float)$balanceOnAccount); ?></span>
            </div>
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
                    <span class="status-badge <?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'status-active' : 'status-notreg'; ?>" style="margin-top:0.5rem;">
                        <?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?>
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
                        <tr><td><b>OTHER NAMES</b></td><td>:<?php echo e(trim(($studentRow['first_name'] ?? ($studentProfile['first_name'] ?? '')) . ' ' . ($studentRow['middle_name'] ?? ($studentProfile['middle_name'] ?? ''))) ?: '-'); ?></td><td><b>PARISH</b></td><td>:<?php echo e($studentRow['parish'] ?? ($studentProfile['parish'] ?? '-')); ?></td></tr>
                        <tr><td><b>EMAIL</b></td><td>:<?php echo e($studentRow['email'] ?? ($studentProfile['email'] ?? '-')); ?></td><td><b>DISTRICT</b></td><td>:<?php echo e($studentRow['district'] ?? ($studentRow['city'] ?? ($studentProfile['district'] ?? '-'))); ?></td></tr>
                        <tr><td><b>TEL. PHONE</b></td><td>:<?php echo e($studentRow['phone'] ?? ($studentProfile['phone'] ?? '-')); ?></td><td><b>NATIONALITY</b></td><td>:<?php echo e($studentRow['nationality'] ?? ($studentRow['country'] ?? ($studentProfile['nationality'] ?? '-'))); ?></td></tr>
                        <tr><td><b>SEX</b></td><td>:<?php echo e($studentRow['gender'] ?? ($studentProfile['gender'] ?? '-')); ?></td><td><b>NATIONAL ID NO.</b></td><td>:<?php echo e($studentRow['national_id'] ?? ($studentProfile['national_id'] ?? '-')); ?></td></tr>
                        <tr><td><b>DATE OF BIRTH</b></td><td>:<?php echo !empty($studentRow['date_of_birth'] ?? $studentProfile['date_of_birth']) ? date('d/m/Y', strtotime($studentRow['date_of_birth'] ?? $studentProfile['date_of_birth'])) : '-'; ?></td><td><b>PASSPORT</b></td><td>:<?php echo e($studentRow['passport'] ?? ($studentProfile['passport'] ?? '-')); ?></td></tr>
                    </table>
                    <?php if ($studentProfileLocked === 1): ?>
                        <div class="alert alert-info mt-3 mb-0">Profile is locked after first submission. Contact administration for corrections.</div>
                    <?php endif; ?>
                </div>

                <div class="tab-panel" data-panel="academic">
                    <table class="bio-details-table">
                        <tr><td><b>PROGRAMME</b></td><td>:<?php echo e($registeredProgramName); ?></td><td><b>YEAR OF STUDY</b></td><td>:<?php echo e($studyProgressLabel); ?></td></tr>
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


