<?php
/**
 * Global Utility Functions
 * Common functions used throughout the application
 */

/**
 * Get base URL
 */
function baseUrl($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Get asset URL
 */
function asset($path = '') {
    return BASE_URL . '/assets/' . ltrim($path, '/');
}

/**
 * Include view
 */
function view($path, $data = []) {
    extract($data);
    $viewPath = BASE_PATH . '/views/' . $path . '.php';
    if (file_exists($viewPath)) {
        include $viewPath;
    } else {
        throw new Exception("View not found: $path");
    }
}

/**
 * Redirect helper
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Get flash message
 * Uses existing session if available, otherwise creates public session
 */
function getFlash($key) {
    global $session;
    if (isset($session) && is_object($session)) {
        return $session->getFlash($key);
    }
    static $tempSession = null;
    if (!($tempSession instanceof Session)) {
        $tempSession = new Session();
    }
    return $tempSession->getFlash($key);
}

/**
 * Set flash message
 * Uses existing session if available, otherwise creates public session
 */
function setFlash($key, $message) {
    global $session;
    if (isset($session) && is_object($session)) {
        $session->setFlash($key, $message);
    } else {
        $tempSession = new Session();
        $tempSession->setFlash($key, $message);
    }
}

/**
 * Escape HTML
 */
function e($string) {
    if ($string === null) {
        return '';
    }
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Debug dump
 */
function dd($var) {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    die;
}

/**
 * Check if user is logged in
 * Uses existing auth if available
 */
function isLoggedIn() {
    global $auth;
    if (isset($auth) && is_object($auth)) {
        return $auth->isLoggedIn();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $roleMap = [
        'SMNS_ADMIN_SESSION' => 'admin',
        'SMNS_STUDENT_SESSION' => 'student',
        'SMNS_LECTURER_SESSION' => 'lecturer',
        'SMNS_FINANCE_SESSION' => 'finance',
    ];
    $role = $roleMap[session_name()] ?? null;
    if ($role) {
        return !empty($_SESSION[$role . '_logged_in']) && !empty($_SESSION[$role . '_role']) && $_SESSION[$role . '_role'] === $role;
    }

    // Legacy fallback
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Get current user
 * Uses existing auth if available
 */
function currentUser() {
    global $auth;
    if (isset($auth) && is_object($auth)) {
        return $auth->getCurrentUser();
    }
    return null;
}

/**
 * Check user role
 * Uses existing auth if available
 */
function hasRole($role) {
    global $auth;
    if (isset($auth) && is_object($auth)) {
        return $auth->hasRole($role);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $key = $role . '_role';
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key] === $role;
        }
    }

    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * OLD input value (for form repopulation)
 */
function old($key, $default = '') {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/**
 * CSRF token field
 */
function csrfField() {
    $token = Security::generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Get setting value
 */
function getSetting($key, $default = null) {
    static $settings = null;
    
    if ($settings === null) {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings[$key] ?? $default;
}

/**
 * Resolve PHP executable for background process calls.
 * On Windows, prefer php-win.exe to avoid opening a console window.
 */
function resolvePhpExecBinary($preferWindowless = true) {
    $candidates = [];
    $normalizeCandidate = static function ($path) {
        $p = trim((string)$path);
        if ($p === '') {
            return '';
        }
        // Normalize malformed Windows drive prefix like "R:xxxamp\php\php.exe" -> "R:\xxxamp\php\php.exe"
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:[^\\\\\\/]/', $p)) {
            $p = substr($p, 0, 2) . DIRECTORY_SEPARATOR . substr($p, 2);
        }
        // Normalize slashes to current platform separator.
        $p = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $p);
        return $p;
    };

    // Optional hard override from config/environment.
    if (defined('PHP_EXEC_BINARY') && PHP_EXEC_BINARY) {
        $candidates[] = $normalizeCandidate((string)PHP_EXEC_BINARY);
    }
    $envPhp = getenv('PHP_EXEC_BINARY');
    if (is_string($envPhp) && $envPhp !== '') {
        $candidates[] = $normalizeCandidate($envPhp);
    }

    // Prefer local server layout relative to BASE_PATH first.
    if (defined('BASE_PATH')) {
        $baseRoot = dirname(dirname((string)BASE_PATH));
        if ($preferWindowless && DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = $normalizeCandidate($baseRoot . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php-win.exe');
        }
        $candidates[] = $normalizeCandidate($baseRoot . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe');
    }

    // Runtime binary detected by PHP (fallback, because it can be stale in some environments).
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $candidates[] = $normalizeCandidate((string)PHP_BINARY);
    }

    // Generic PATH fallback.
    $candidates[] = $normalizeCandidate('php');

    $normalized = [];
    foreach ($candidates as $cand) {
        $cand = $normalizeCandidate($cand);
        if ($cand === '') {
            continue;
        }
        if (isset($normalized[$cand])) {
            continue;
        }
        $normalized[$cand] = true;
    }

    foreach (array_keys($normalized) as $binary) {
        // If explicitly asking for windowless on Windows, prefer php-win in same folder.
        if ($preferWindowless && DIRECTORY_SEPARATOR === '\\') {
            $binaryName = strtolower((string)basename($binary));
            if ($binaryName === 'php-win.exe') {
                if ($binary === 'php-win.exe' || is_file($binary)) {
                    return $binary;
                }
            }

            if ($binary !== 'php' && $binary !== 'php.exe') {
                $phpWinCandidate = dirname($binary) . DIRECTORY_SEPARATOR . 'php-win.exe';
                if (is_file($phpWinCandidate)) {
                    return $phpWinCandidate;
                }
            }
        }

        // Use direct binary if it exists or if it's a PATH command.
        if ($binary === 'php' || $binary === 'php.exe') {
            return $binary;
        }
        if (is_file($binary)) {
            return $binary;
        }
    }

    // Last-resort fallback.
    return $preferWindowless && DIRECTORY_SEPARATOR === '\\' ? 'php-win.exe' : 'php';
}

/**
 * Fetch unread notifications for a given user (handles personal + broadcast + per-user read state)
 */
function fetchUnreadNotificationsForUser($userId, $limit = 50) {
    $db = new Database();
    $conn = $db->getConnection();

    // Ensure helper tables exist (notifications_read and notification_archive)
    $conn->exec("CREATE TABLE IF NOT EXISTS notifications_read (
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at DATETIME NOT NULL,
        PRIMARY KEY(notification_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
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

    // Fetch notifications visible to this user: personal (user_id = $userId) or broadcasts (user_id IS NULL or 0)
    // Exclude notifications that have been archived by this user
    // Apply limit AFTER filtering for unread to ensure we get accurate count
    $sql = "SELECT n.*, nr.read_at AS my_read_at
            FROM notifications n
            LEFT JOIN notifications_read nr ON nr.notification_id = n.id AND nr.user_id = :uid_read
            LEFT JOIN notification_archive na ON na.notification_id = n.id AND na.user_id = :uid_archive
            WHERE ((n.user_id = :uid) OR (n.user_id IS NULL) OR (n.user_id = 0))
            AND na.id IS NULL
            ORDER BY n.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':uid', (int)$userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid_read', (int)$userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid_archive', (int)$userId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread = [];
    foreach ($rows as $r) {
        $isBroadcast = is_null($r['user_id']) || $r['user_id'] == 0;
        if ($isBroadcast) {
            // unread if no entry in notifications_read for this user
            if (empty($r['my_read_at'])) {
                $unread[] = $r;
                if (count($unread) >= $limit) break; // Apply limit after filtering
            }
        } else {
            // personal notification honours read_status
            if (($r['read_status'] ?? '') !== 'read') {
                $unread[] = $r;
                if (count($unread) >= $limit) break; // Apply limit after filtering
            }
        }
    }

    return $unread;
}

/**
 * Get exact unread notification count for a user.
 * Uses NOT EXISTS filters to avoid inflated counts from duplicate join rows.
 */
function getUnreadNotificationCountForUser($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return 0;
    }

    try {
        $db = new Database();
        $conn = $db->getConnection();

        $sql = "
            SELECT COUNT(*)
            FROM notifications n
            WHERE (n.user_id = :uid OR n.user_id IS NULL OR n.user_id = 0)
              AND NOT EXISTS (
                    SELECT 1
                    FROM notification_archive na
                    WHERE na.notification_id = n.id
                      AND na.user_id = :uid_archive
              )
              AND (
                    (
                        n.user_id = :uid_personal
                        AND COALESCE(n.read_status, '') <> 'read'
                    )
                    OR
                    (
                        (n.user_id IS NULL OR n.user_id = 0)
                        AND NOT EXISTS (
                            SELECT 1
                            FROM notifications_read nr
                            WHERE nr.notification_id = n.id
                              AND nr.user_id = :uid_read
                        )
                    )
                  )
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            'uid' => $userId,
            'uid_archive' => $userId,
            'uid_personal' => $userId,
            'uid_read' => $userId
        ]);

        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Resolve live student enrollment/registration status from DB for a target semester.
 * Falls back to latest known approved records when semester is not provided.
 */
function getStudentLifecycleStatus($conn, $studentId, $semesterId = 0) {
    static $memo = [];

    $studentId = (int)$studentId;
    $semesterId = (int)$semesterId;
    $memoKey = $studentId . ':' . $semesterId;
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    $default = [
        'enrollment_status' => 'not_enrolled',
        'registration_status' => 'not_registered',
        'has_enrollment' => false,
        'has_registration' => false,
    ];
    if ($studentId <= 0 || !($conn instanceof PDO)) {
        return $memo[$memoKey] = $default;
    }

    try {
        $resolvedSemesterId = $semesterId;
        if ($resolvedSemesterId <= 0) {
            $sstmt = $conn->prepare("SELECT id FROM semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
            $sstmt->execute();
            $resolvedSemesterId = (int)$sstmt->fetchColumn();
        }

        $hasEnrollment = false;
        if ($resolvedSemesterId > 0) {
            $estmt = $conn->prepare("SELECT COUNT(*) FROM semester_registrations WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'approved'");
            $estmt->execute(['student_id' => $studentId, 'semester_id' => $resolvedSemesterId]);
            $hasEnrollment = ((int)$estmt->fetchColumn()) > 0;
        }
        if (!$hasEnrollment) {
            $estmt = $conn->prepare("SELECT COUNT(*) FROM semester_registrations WHERE student_id = :student_id AND status = 'approved'");
            $estmt->execute(['student_id' => $studentId]);
            $hasEnrollment = ((int)$estmt->fetchColumn()) > 0;
        }

        $hasRegistration = false;
        if ($resolvedSemesterId > 0) {
            $rstmt = $conn->prepare("
                SELECT COUNT(*)
                FROM course_registrations
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
                  AND status IN ('approved', 'registered', 'pending', 'submitted')
            ");
            $rstmt->execute(['student_id' => $studentId, 'semester_id' => $resolvedSemesterId]);
            $hasRegistration = ((int)$rstmt->fetchColumn()) > 0;
        }
        if (!$hasRegistration) {
            $rstmt = $conn->prepare("
                SELECT COUNT(*)
                FROM course_registrations
                WHERE student_id = :student_id
                  AND status IN ('approved', 'registered', 'pending', 'submitted')
            ");
            $rstmt->execute(['student_id' => $studentId]);
            $hasRegistration = ((int)$rstmt->fetchColumn()) > 0;
        }

        return $memo[$memoKey] = [
            'enrollment_status' => $hasEnrollment ? 'enrolled' : 'not_enrolled',
            'registration_status' => $hasRegistration ? 'registered' : 'not_registered',
            'has_enrollment' => $hasEnrollment,
            'has_registration' => $hasRegistration,
        ];
    } catch (Exception $e) {
        return $memo[$memoKey] = $default;
    }
}

/**
 * Return a consistent chip style for academic status labels.
 */
function getAcademicStatusChipStyle($tone = 'neutral') {
    $tone = strtolower(trim((string)$tone));
    switch ($tone) {
        case 'success':
            return 'background:#dcfce7;color:#166534;border:1px solid #86efac;';
        case 'warning':
            return 'background:#ffedd5;color:#9a3412;border:1px solid #fdba74;';
        case 'danger':
            return 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;';
        case 'info':
            return 'background:#eff6ff;color:#1d4ed8;border:1px solid #93c5fd;';
        default:
            return 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
    }
}

/**
 * Resolve student academic status label + visual tone.
 * Uses latest student_gpas (preferred), then student.academic_status fallback,
 * and finally enrollment/registration lifecycle signals.
 */
function getStudentAcademicStatusMeta($conn, $studentId, $semesterId = 0, $fallbackAcademicStatus = '') {
    static $memo = [];

    $studentId = (int)$studentId;
    $semesterId = (int)$semesterId;
    $fallbackAcademicStatus = trim((string)$fallbackAcademicStatus);
    $memoKey = $studentId . ':' . $semesterId . ':' . strtolower($fallbackAcademicStatus);
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    $buildMeta = function ($label, $tone, $source = 'computed', $sgpa = null) {
        $label = trim((string)$label);
        if ($label === '') {
            $label = 'Status Pending';
        }
        return [
            'label' => $label,
            'tone' => $tone,
            'style' => getAcademicStatusChipStyle($tone),
            'source' => $source,
            'sgpa' => $sgpa,
        ];
    };

    $normalize = function ($rawLabel, $sgpa = null) use ($buildMeta) {
        $raw = trim((string)$rawLabel);
        $rawLower = strtolower($raw);

        // Promotion rule: SGPA below 2.00 should indicate repeat status.
        if ($sgpa !== null && $sgpa < 2.0) {
            if (in_array($rawLower, ['suspension', 'suspended', 'dead semester', 'dismissed', 'discontinued', 'withdrawn'], true)) {
                $label = ($raw === '') ? 'Suspended' : ucwords($rawLower);
                return $buildMeta($label, 'danger', 'gpa');
            }
            return $buildMeta('Repeat Semester (SGPA ' . number_format($sgpa, 2) . ')', 'warning', 'gpa', $sgpa);
        }

        if ($rawLower === '' || in_array($rawLower, ['good standing', 'active', 'normal progress'], true)) {
            return $buildMeta('Normal Progress', 'success', 'standing', $sgpa);
        }

        if (in_array($rawLower, ['probation', 'on probation'], true)) {
            return $buildMeta('On Probation', 'warning', 'standing', $sgpa);
        }

        if (in_array($rawLower, ['repeat', 'repeat semester'], true)) {
            return $buildMeta('Repeat Semester', 'warning', 'standing', $sgpa);
        }

        if (in_array($rawLower, ['suspension', 'suspended', 'dead semester', 'dismissed', 'discontinued', 'withdrawn'], true)) {
            $label = ($rawLower === 'dead semester') ? 'Dead Semester' : ucwords($rawLower);
            return $buildMeta($label, 'danger', 'standing', $sgpa);
        }

        if (in_array($rawLower, ['deferred', 'on leave'], true)) {
            return $buildMeta(ucwords($rawLower), 'info', 'standing', $sgpa);
        }

        if (in_array($rawLower, ['graduated', 'completed', 'complete'], true)) {
            $label = ($rawLower === 'complete') ? 'Completed' : ucwords($rawLower);
            return $buildMeta($label, 'success', 'standing', $sgpa);
        }

        return $buildMeta(ucwords($rawLower), 'warning', 'standing', $sgpa);
    };

    if ($studentId <= 0 || !($conn instanceof PDO)) {
        return $memo[$memoKey] = $buildMeta('Status Pending', 'neutral', 'none');
    }

    try {
        $stmt = $conn->prepare("
            SELECT sg.academic_standing, sg.semester_gpa
            FROM student_gpas sg
            WHERE sg.student_id = :student_id
            ORDER BY
                CASE WHEN :semester_id > 0 AND sg.semester_id = :semester_id THEN 0 ELSE 1 END,
                sg.semester_id DESC,
                sg.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!empty($row)) {
            $standing = trim((string)($row['academic_standing'] ?? ''));
            $sgpa = (isset($row['semester_gpa']) && $row['semester_gpa'] !== '' && is_numeric($row['semester_gpa']))
                ? (float)$row['semester_gpa']
                : null;
            return $memo[$memoKey] = $normalize($standing, $sgpa);
        }
    } catch (Exception $e) {
        // Continue to fallbacks.
    }

    if ($fallbackAcademicStatus !== '') {
        return $memo[$memoKey] = $normalize($fallbackAcademicStatus, null);
    }

    $lifecycle = getStudentLifecycleStatus($conn, $studentId, $semesterId);
    if (($lifecycle['has_enrollment'] ?? false) === false) {
        return $memo[$memoKey] = $buildMeta('Enrollment Pending', 'info', 'lifecycle');
    }
    if (($lifecycle['has_registration'] ?? false) === false) {
        return $memo[$memoKey] = $buildMeta('Registration Pending', 'info', 'lifecycle');
    }

    return $memo[$memoKey] = $buildMeta('Normal Progress', 'success', 'default');
}

/**
 * Resolve the semester context to display for a student.
 * Prefers active calendar semester so "CURRENT" chips stay in sync with admin semester activation.
 * Falls back to latest approved semester registration, then latest course registration.
 */
function getStudentCurrentSemesterContext($conn, $studentId) {
    $studentId = (int)$studentId;
    $fallback = [
        'id' => 0,
        'semester_name' => '-',
        'semester_number' => 0,
        'academic_year_id' => 0,
        'academic_year' => '-',
    ];

    if (!($conn instanceof PDO)) {
        return $fallback;
    }

    try {
        // 1) Active semester (system calendar source of truth for "current" context)
        $active = Helper::getCurrentSemester();
        if (!empty($active)) {
            $yearName = '-';
            if (!empty($active['academic_year_id'])) {
                $ayStmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = :id LIMIT 1");
                $ayStmt->execute(['id' => (int)$active['academic_year_id']]);
                $yearName = (string)($ayStmt->fetchColumn() ?: '-');
            }
            return [
                'id' => (int)($active['id'] ?? 0),
                'semester_name' => (string)($active['semester_name'] ?? '-'),
                'semester_number' => (int)($active['semester_number'] ?? 0),
                'academic_year_id' => (int)($active['academic_year_id'] ?? 0),
                'academic_year' => $yearName,
            ];
        }

        // 2) Latest approved semester enrollment for this student
        if ($studentId > 0) {
            $stmt = $conn->prepare("
                SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
                FROM semester_registrations sr
                INNER JOIN semesters s ON s.id = sr.semester_id
                INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                WHERE sr.student_id = :student_id AND sr.status = 'approved'
                ORDER BY COALESCE(sr.updated_at, sr.request_date, sr.created_at) DESC, sr.id DESC
                LIMIT 1
            ");
            $stmt->execute(['student_id' => $studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'semester_name' => (string)($row['semester_name'] ?? '-'),
                    'semester_number' => (int)($row['semester_number'] ?? 0),
                    'academic_year_id' => (int)($row['academic_year_id'] ?? 0),
                    'academic_year' => (string)($row['academic_year'] ?? '-'),
                ];
            }
        }

        // 3) Latest semester where student has course registrations
        if ($studentId > 0) {
            $stmt = $conn->prepare("
                SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
                FROM course_registrations cr
                INNER JOIN semesters s ON s.id = cr.semester_id
                INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                WHERE cr.student_id = :student_id
                ORDER BY COALESCE(cr.updated_at, cr.registration_date, cr.created_at) DESC, cr.id DESC
                LIMIT 1
            ");
            $stmt->execute(['student_id' => $studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'semester_name' => (string)($row['semester_name'] ?? '-'),
                    'semester_number' => (int)($row['semester_number'] ?? 0),
                    'academic_year_id' => (int)($row['academic_year_id'] ?? 0),
                    'academic_year' => (string)($row['academic_year'] ?? '-'),
                ];
            }
        }

        // 4) No active semester and no student history
    } catch (Exception $e) {
        // Fall through to default.
    }

    return $fallback;
}

/**
 * Auto-assign courses to a student for a given semester.
 * Prefers explicit `course_assignments` for the semester, then falls back to courses by program/level.
 * Safe to call multiple times; avoids creating duplicates.
 */
function auto_assign_courses(PDO $conn, int $studentId, int $semesterId, $adminId = null, $programId = null, $levelYear = null) {
    try {
        $studentId = (int)$studentId;
        $semesterId = (int)$semesterId;
        $programId = (int)$programId;
        $levelYear = (int)$levelYear;
        if ($studentId <= 0 || $semesterId <= 0) {
            return false;
        }

        // Resolve missing routing metadata to avoid cross-year/course mixing.
        if ($programId <= 0) {
            $pstmt = $conn->prepare("SELECT program_id FROM students WHERE id = :id LIMIT 1");
            $pstmt->execute(['id' => $studentId]);
            $programId = (int)$pstmt->fetchColumn();
        }
        if ($levelYear <= 0) {
            $ystmt = $conn->prepare("
                SELECT year_of_study
                FROM semester_registrations
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
                  AND status = 'approved'
                ORDER BY id DESC
                LIMIT 1
            ");
            $ystmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId
            ]);
            $levelYear = (int)$ystmt->fetchColumn();
        }
        if ($levelYear <= 0) {
            $ystmt = $conn->prepare("SELECT COALESCE(level_year, year_of_study, 1) FROM students WHERE id = :id LIMIT 1");
            $ystmt->execute(['id' => $studentId]);
            $levelYear = max(1, (int)$ystmt->fetchColumn());
        }

        // Resolve available columns to support mixed schema versions safely.
        $availableCols = [];
        $colStmt = $conn->query("SHOW COLUMNS FROM course_registrations");
        while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $availableCols[] = strtolower((string)($col['Field'] ?? ''));
        }
        $has = function ($name) use ($availableCols) {
            return in_array(strtolower($name), $availableCols, true);
        };

        $insertCols = ['student_id', 'course_id', 'semester_id'];
        $selectCols = [':student_id', 'c.id', ':semester_id'];
        if ($has('registration_date')) { $insertCols[] = 'registration_date'; $selectCols[] = 'CURDATE()'; }
        if ($has('status')) { $insertCols[] = 'status'; $selectCols[] = "'approved'"; }
        if ($has('approved_by')) { $insertCols[] = 'approved_by'; $selectCols[] = ':approved_by'; }
        if ($has('approved_date')) { $insertCols[] = 'approved_date'; $selectCols[] = 'NOW()'; }
        if ($has('created_at')) { $insertCols[] = 'created_at'; $selectCols[] = 'NOW()'; }
        if ($has('updated_at')) { $insertCols[] = 'updated_at'; $selectCols[] = 'NOW()'; }

        $insertColSql = implode(', ', $insertCols);
        $selectColSql = implode(', ', $selectCols);
        $baseParams = ['student_id' => $studentId, 'semester_id' => $semesterId, 'approved_by' => $adminId];

        // Resolve semester number once so every path stays semester-accurate.
        $semNum = null;
        $sstmt = $conn->prepare('SELECT semester_number FROM semesters WHERE id = :id LIMIT 1');
        $sstmt->execute(['id' => $semesterId]);
        $semNum = (int)$sstmt->fetchColumn();

        // Insert from course_assignments (if table exists) for the exact semester.
        // Apply filters through courses table so we do not mix year/program/semester.
        $hasCourseAssignments = false;
        try {
            $tableCheck = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'course_assignments'");
            $tableCheck->execute();
            $hasCourseAssignments = ((int)$tableCheck->fetchColumn()) > 0;
        } catch (Exception $e) {
            $hasCourseAssignments = false;
        }

        if ($hasCourseAssignments && $semNum > 0) {
            $assignWhere = "ca.semester_id = :semester_id
                AND ca.status = 'active'
                AND c.status = 'active'
                AND (c.semester_offered = :sem_num OR c.semester_offered = 3)";
            $assignParams = $baseParams + ['sem_num' => $semNum];
            $assignWhere .= " AND c.level_year = :level_year";
            $assignParams['level_year'] = (int)$levelYear;
            if ((int)$programId > 0) {
                $assignWhere .= " AND (c.program_id = :program_id OR c.program_id IS NULL OR c.program_id = 0)";
                $assignParams['program_id'] = (int)$programId;
            }
            $assignSql = "INSERT INTO course_registrations ($insertColSql)
                SELECT $selectColSql
                FROM course_assignments ca
                JOIN courses c ON ca.course_id = c.id
                WHERE $assignWhere
                AND NOT EXISTS (
                    SELECT 1 FROM course_registrations cr
                    WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id
                )";
            $insAssign = $conn->prepare($assignSql);
            $insAssign->execute($assignParams);
        }

        if ($semNum <= 0) {
            return true;
        }

        // Fallback chain from strict to broad while keeping semester isolation.
        $fallbackWhereClauses = [];
        if ($programId > 0) {
            $fallbackWhereClauses[] = "c.status = 'active' AND c.program_id = :program_id AND c.level_year = :level_year AND (c.semester_offered = :sem_num OR c.semester_offered = 3)";
        }
        $fallbackWhereClauses[] = "c.status = 'active' AND c.level_year = :level_year AND (c.semester_offered = :sem_num OR c.semester_offered = 3)";

        foreach ($fallbackWhereClauses as $whereSql) {
            $insFallback = "INSERT INTO course_registrations ($insertColSql)
                SELECT $selectColSql
                FROM courses c
                WHERE $whereSql
                AND NOT EXISTS (
                    SELECT 1 FROM course_registrations cr
                    WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id
                )";
            $params = ['student_id' => $studentId, 'semester_id' => $semesterId, 'approved_by' => $adminId, 'sem_num' => $semNum];
            if (strpos($whereSql, ':program_id') !== false) {
                $params['program_id'] = $programId;
            }
            $params['level_year'] = $levelYear;
            $insStmt = $conn->prepare($insFallback);
            $insStmt->execute($params);
        }

        return true;
    } catch (Exception $e) {
        error_log('auto_assign_courses error: ' . $e->getMessage());
        return false;
    }
}

