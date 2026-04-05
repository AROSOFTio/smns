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
 * Build the canonical student registration prefix for a given year.
 */
function buildStudentRegistrationPrefix($year = null) {
    $resolvedYear = (int)($year ?: date('Y'));
    if ($resolvedYear < 1900) {
        $resolvedYear = (int)date('Y');
    }
    return $resolvedYear . '-STU-';
}

/**
 * Generate a unique student registration number in the format YYYY-STU-XXX.
 */
function generateStudentRegistrationNumber(PDO $conn, $year = null) {
    $prefix = buildStudentRegistrationPrefix($year);
    $stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id LIKE :prefix ORDER BY student_id DESC LIMIT 1");
    $stmt->execute(['prefix' => $prefix . '%']);
    $lastStudentId = (string)($stmt->fetchColumn() ?: '');
    $next = 1;

    if ($lastStudentId !== '' && preg_match('/(\d+)$/', $lastStudentId, $matches)) {
        $next = ((int)$matches[1]) + 1;
    }

    for ($i = 0; $i < 1000; $i++) {
        $candidate = $prefix . str_pad((string)($next + $i), 3, '0', STR_PAD_LEFT);
        $checkStmt = $conn->prepare("SELECT id FROM students WHERE student_id = :student_id LIMIT 1");
        $checkStmt->execute(['student_id' => $candidate]);
        if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
            return $candidate;
        }
    }

    throw new Exception('Unable to allocate a unique student registration number.');
}

/**
 * Resolve the student's effective programme from the most reliable academic source.
 * Preference order:
 * 1. Dominant programme found in the student's registered course history.
 * 2. Explicit programme on the student profile row.
 * 3. Caller-provided fallback values.
 */
function getStudentEffectiveProgram(PDO $conn, int $studentId, array $fallback = []): array
{
    $result = [
        'program_id' => (int)($fallback['program_id'] ?? 0),
        'program_code' => (string)($fallback['program_code'] ?? ''),
        'program_name' => (string)($fallback['program_name'] ?? ''),
        'source' => 'fallback',
    ];

    if ($studentId <= 0) {
        return $result;
    }

    try {
        $historyStmt = $conn->prepare("
            SELECT
                c.program_id,
                p.program_code,
                p.program_name,
                COUNT(*) AS course_count
            FROM course_registrations cr
            INNER JOIN courses c ON c.id = cr.course_id
            LEFT JOIN programs p ON p.id = c.program_id
            WHERE cr.student_id = :student_id
              AND COALESCE(LOWER(cr.status), 'approved') <> 'dropped'
              AND c.program_id IS NOT NULL
              AND c.program_id > 0
            GROUP BY c.program_id, p.program_code, p.program_name
            ORDER BY course_count DESC, c.program_id ASC
            LIMIT 1
        ");
        $historyStmt->execute(['student_id' => $studentId]);
        $historyProgram = $historyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($historyProgram && !empty($historyProgram['program_id'])) {
            return [
                'program_id' => (int)$historyProgram['program_id'],
                'program_code' => (string)($historyProgram['program_code'] ?? ''),
                'program_name' => (string)($historyProgram['program_name'] ?? ''),
                'source' => 'course_history',
            ];
        }
    } catch (Exception $e) {
        // Fall through to profile programme.
    }

    try {
        $profileStmt = $conn->prepare("
            SELECT
                s.program_id,
                p.program_code,
                p.program_name
            FROM students s
            LEFT JOIN programs p ON p.id = s.program_id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $profileStmt->execute(['student_id' => $studentId]);
        $profileProgram = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($profileProgram && !empty($profileProgram['program_id'])) {
            return [
                'program_id' => (int)$profileProgram['program_id'],
                'program_code' => (string)($profileProgram['program_code'] ?? ''),
                'program_name' => (string)($profileProgram['program_name'] ?? ''),
                'source' => 'student_profile',
            ];
        }
    } catch (Exception $e) {
        // Use fallback.
    }

    return $result;
}

/**
 * Normalize country/nationality tokens for currency routing.
 */
function normalizeGeoForCurrency($value) {
    $v = strtolower(trim((string)$value));
    return preg_replace('/[^a-z]/', '', $v);
}

/**
 * Resolve display currency for a student.
 * Rule: nationality has priority, then country, fallback UGX.
 */
function getStudentDisplayCurrencyCode($country = '', $nationality = '') {
    $ugandaTokens = ['uganda', 'ugandan', 'ug'];
    $countryNorm = normalizeGeoForCurrency($country);
    $nationalityNorm = normalizeGeoForCurrency($nationality);

    if ($nationalityNorm !== '') {
        return in_array($nationalityNorm, $ugandaTokens, true) ? 'UGX' : 'USD';
    }
    if ($countryNorm !== '') {
        return in_array($countryNorm, $ugandaTokens, true) ? 'UGX' : 'USD';
    }

    return 'UGX';
}

/**
 * Convert UGX base amount to the selected display currency.
 */
function convertAmountFromUgxForDisplayCurrency($amountUgx, $displayCurrency = 'UGX', $usdUgxRate = 0.0) {
    $currency = strtoupper(trim((string)$displayCurrency));
    $amount = (float)$amountUgx;

    if ($currency === 'USD') {
        $rate = (float)$usdUgxRate;
        if ($rate <= 0) {
            $rate = (float)Helper::getUsdUgxRate();
        }
        if ($rate <= 0) {
            $rate = 3700.0;
        }
        return $amount / $rate;
    }

    return $amount;
}

/**
 * Format UGX base amount in the selected display currency.
 */
function formatAmountFromUgxForDisplayCurrency($amountUgx, $displayCurrency = 'UGX', $usdUgxRate = 0.0) {
    $currency = strtoupper(trim((string)$displayCurrency));
    $amount = convertAmountFromUgxForDisplayCurrency($amountUgx, $currency, (float)$usdUgxRate);
    $decimals = $currency === 'USD' ? 2 : 0;
    return Helper::formatCurrency($amount, $currency, $decimals);
}

/**
 * Build an SQL-safe payment verification predicate.
 * Returns "1=1" when verification column is unavailable (legacy schema).
 */
function getVerifiedPaymentsPredicate($conn, $tableAlias = '') {
    if (!($conn instanceof PDO)) {
        return '1=1';
    }

    static $hasVerificationColumn = null;
    if ($hasVerificationColumn === null) {
        try {
            $colStmt = $conn->query("SHOW COLUMNS FROM payments LIKE 'verification_status'");
            $hasVerificationColumn = (bool)($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            $hasVerificationColumn = false;
        }
    }

    if (!$hasVerificationColumn) {
        return '1=1';
    }

    $prefix = trim((string)$tableAlias);
    if ($prefix !== '' && substr($prefix, -1) !== '.') {
        $prefix .= '.';
    }
    return "COALESCE(" . $prefix . "verification_status, 'verified') = 'verified'";
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
 * For finance users, compute unread student-message coverage not yet represented
 * by regular unread notifications (prevents bell undercount).
 */
function getFinanceMessageNotificationBridge(PDO $conn, $userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return ['gap' => 0, 'unread_total' => 0, 'latest_at' => null];
    }

    try {
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
        $roleStmt->execute(['uid' => $userId]);
        $role = strtolower(trim((string)$roleStmt->fetchColumn()));
        if ($role !== 'finance') {
            return ['gap' => 0, 'unread_total' => 0, 'latest_at' => null];
        }

        $tableStmt = $conn->query("SHOW TABLES LIKE 'finance_messages'");
        if (!($tableStmt && $tableStmt->fetch(PDO::FETCH_NUM))) {
            return ['gap' => 0, 'unread_total' => 0, 'latest_at' => null];
        }

        $fmStmt = $conn->query("
            SELECT
                COUNT(*) AS unread_total,
                MAX(created_at) AS latest_at
            FROM finance_messages
            WHERE sender_role = 'student'
              AND is_read = 0
        ");
        $fmRow = $fmStmt ? ($fmStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $unreadTotal = (int)($fmRow['unread_total'] ?? 0);
        $latestAt = $fmRow['latest_at'] ?? null;
        if ($unreadTotal <= 0) {
            return ['gap' => 0, 'unread_total' => 0, 'latest_at' => $latestAt];
        }

        // If per-message notifications were already created, avoid double-counting.
        $existing = 0;
        try {
            $existingStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE user_id = :uid
                  AND COALESCE(read_status, '') <> 'read'
                  AND (
                        title = 'New Student Message'
                        OR link LIKE :messages_link
                  )
            ");
            $existingStmt->execute([
                'uid' => $userId,
                'messages_link' => '%/views/finance/dashboard.php?section=messages%'
            ]);
            $existing = (int)$existingStmt->fetchColumn();
        } catch (Exception $e) {
            $existing = 0;
        }

        return [
            'gap' => max(0, $unreadTotal - $existing),
            'unread_total' => $unreadTotal,
            'latest_at' => $latestAt
        ];
    } catch (Exception $e) {
        return ['gap' => 0, 'unread_total' => 0, 'latest_at' => null];
    }
}

/**
 * Ensure core notifications table schema is compatible with unread logic.
 */
function ensureNotificationsTable(PDO $conn) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // Create base table if missing (include legacy columns for compatibility).
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

    $columns = [];
    try {
        $colStmt = $conn->query("SHOW COLUMNS FROM notifications");
        if ($colStmt) {
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[strtolower((string)$col['Field'])] = true;
            }
        }
    } catch (Exception $e) {
        $columns = [];
    }

    if (!isset($columns['read_status'])) {
        $conn->exec("ALTER TABLE notifications ADD COLUMN read_status VARCHAR(20) NOT NULL DEFAULT 'unread'");
        $columns['read_status'] = true;
    }
    if (!isset($columns['read_at'])) {
        $conn->exec("ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL");
        $columns['read_at'] = true;
    }
    if (!isset($columns['created_at'])) {
        $conn->exec("ALTER TABLE notifications ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $columns['created_at'] = true;
    }
    if (!isset($columns['user_type'])) {
        $conn->exec("ALTER TABLE notifications ADD COLUMN user_type VARCHAR(20) NULL DEFAULT NULL");
        $columns['user_type'] = true;
    }
    if (!isset($columns['is_read'])) {
        $conn->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NULL DEFAULT 0");
        $columns['is_read'] = true;
    }

    // Backfill read_status from legacy is_read flags when present.
    if (isset($columns['is_read']) && isset($columns['read_status'])) {
        $conn->exec("UPDATE notifications SET read_status = 'read' WHERE is_read = 1 AND (read_status IS NULL OR read_status = '' OR read_status = 'unread')");
    }
}

/**
 * Fetch unread notifications for a given user (handles personal + broadcast + per-user read state)
 */
function fetchUnreadNotificationsForUser($userId, $limit = 50) {
    $db = new Database();
    $conn = $db->getConnection();
    ensureNotificationsTable($conn);

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
    // Exclude archived notifications so saved items leave the unread bell queue.
    // Apply limit AFTER filtering for unread to ensure we get accurate count
    $sql = "SELECT DISTINCT n.*, nr.read_at AS my_read_at, IF(na.id IS NULL, 0, 1) AS my_archive_id
            FROM notifications n
            LEFT JOIN notifications_read nr ON nr.notification_id = n.id AND nr.user_id = :uid_read
            LEFT JOIN notification_archive na ON na.notification_id = n.id AND na.user_id = :uid_archive
            WHERE ((n.user_id = :uid) OR (n.user_id IS NULL) OR (n.user_id = 0))
            AND na.id IS NULL
            ORDER BY n.created_at DESC, n.id DESC";

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

    // Finance bell should also reflect unread student->finance chat items.
    $bridge = getFinanceMessageNotificationBridge($conn, (int)$userId);
    if (!empty($bridge['gap']) && (int)$bridge['gap'] > 0) {
        $bridgeCount = (int)$bridge['unread_total'];
        $bridgeMessage = 'You have ' . number_format($bridgeCount) . ' unread student message' . ($bridgeCount === 1 ? '' : 's') . ' in Finance Messages.';
        $bridgeRow = [
            'id' => 0,
            'user_id' => (int)$userId,
            'title' => 'Student Messages Pending',
            'message' => $bridgeMessage,
            'type' => 'info',
            'read_status' => 'unread',
            'link' => BASE_URL . '/views/finance/dashboard.php?section=messages#finance-messages-section',
            'created_at' => !empty($bridge['latest_at']) ? (string)$bridge['latest_at'] : date('Y-m-d H:i:s'),
            'my_read_at' => null,
            // Mark as already "saved" to disable archive button for this synthetic row.
            'my_archive_id' => 1
        ];
        array_unshift($unread, $bridgeRow);
        if (count($unread) > $limit) {
            $unread = array_slice($unread, 0, $limit);
        }
    }

    return $unread;
}

/**
 * Get exact unread notification count for a user.
 */
function getUnreadNotificationCountForUser($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return 0;
    }

    try {
        $db = new Database();
        $conn = $db->getConnection();
        ensureNotificationsTable($conn);

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
        $baseCount = (int)$stmt->fetchColumn();
        $bridge = getFinanceMessageNotificationBridge($conn, $userId);
        return $baseCount + (int)($bridge['gap'] ?? 0);
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
            $studentCtx = getStudentCurrentSemesterContext($conn, $studentId);
            $resolvedSemesterId = (int)($studentCtx['id'] ?? 0);
        }
        if ($resolvedSemesterId <= 0) {
            $activeSemester = Helper::getCurrentSemester();
            $resolvedSemesterId = (int)($activeSemester['id'] ?? 0);
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
 * Student-first context for continuous intake:
 * 1) Latest approved semester enrollment for this student
 * 2) Latest semester where student has course registrations
 * 3) Semester 1 of active academic year (for students with no history)
 * 4) Most recent Semester 1 fallback
 * 5) Institution active semester final fallback
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
        // 1) Latest approved semester enrollment for this student
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

        // 2) Latest semester where student has course registrations
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

        // 3) For students with no history, default to Semester 1 in the active academic year
        $activeYear = Helper::getCurrentAcademicYear();
        $activeYearId = (int)($activeYear['id'] ?? 0);
        if ($activeYearId > 0) {
            $semStmt = $conn->prepare("
                SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
                FROM semesters s
                INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                WHERE s.academic_year_id = :academic_year_id
                ORDER BY
                    CASE WHEN s.semester_number = 1 THEN 0 ELSE 1 END,
                    s.semester_number ASC,
                    s.start_date ASC,
                    s.id ASC
                LIMIT 1
            ");
            $semStmt->execute(['academic_year_id' => $activeYearId]);
            $row = $semStmt->fetch(PDO::FETCH_ASSOC);
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

        // 4) Prefer most recent Semester 1 to avoid global Semester 2 default bias.
        $semOneFallbackStmt = $conn->query("
            SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
            FROM semesters s
            INNER JOIN academic_years ay ON ay.id = s.academic_year_id
            WHERE s.semester_number = 1
            ORDER BY ay.start_date DESC, s.start_date ASC, s.id DESC
            LIMIT 1
        ");
        $row = $semOneFallbackStmt ? $semOneFallbackStmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'semester_name' => (string)($row['semester_name'] ?? '-'),
                'semester_number' => (int)($row['semester_number'] ?? 0),
                'academic_year_id' => (int)($row['academic_year_id'] ?? 0),
                'academic_year' => (string)($row['academic_year'] ?? '-'),
            ];
        }

        // 5) Institution active semester final fallback
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
    } catch (Exception $e) {
        // Fall through to default.
    }

    return $fallback;
}

/**
 * Resolve the student's default enrollment target for mixed cohorts.
 * Priority:
 * 1) Latest pending semester enrollment request (student is already in-progress for that semester)
 * 2) Next semester after student's latest context:
 *    - Semester 1 -> Semester 2 (same academic year)
 *    - Semester 2 -> Semester 1 (next academic year)
 * 3) Fall back to current context resolver
 */
function getStudentEnrollmentTargetContext($conn, $studentId) {
    $base = getStudentCurrentSemesterContext($conn, $studentId);
    if (!($conn instanceof PDO)) {
        return $base;
    }

    $studentId = (int)$studentId;
    if ($studentId <= 0) {
        return $base;
    }

    try {
        // 1) Keep student on latest pending request when present.
        $pendingStmt = $conn->prepare("
            SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
            FROM semester_registrations sr
            INNER JOIN semesters s ON s.id = sr.semester_id
            INNER JOIN academic_years ay ON ay.id = s.academic_year_id
            WHERE sr.student_id = :student_id
              AND sr.status = 'pending'
            ORDER BY COALESCE(sr.updated_at, sr.request_date, sr.created_at) DESC, sr.id DESC
            LIMIT 1
        ");
        $pendingStmt->execute(['student_id' => $studentId]);
        $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        if ($pending) {
            return [
                'id' => (int)($pending['id'] ?? 0),
                'semester_name' => (string)($pending['semester_name'] ?? '-'),
                'semester_number' => (int)($pending['semester_number'] ?? 0),
                'academic_year_id' => (int)($pending['academic_year_id'] ?? 0),
                'academic_year' => (string)($pending['academic_year'] ?? '-'),
            ];
        }

        $currentSemesterId = (int)($base['id'] ?? 0);
        $currentYearId = (int)($base['academic_year_id'] ?? 0);
        $currentSemNo = (int)($base['semester_number'] ?? 0);
        if ($currentSemesterId <= 0 || $currentYearId <= 0 || ($currentSemNo !== 1 && $currentSemNo !== 2)) {
            return $base;
        }

        // Institutional progression guard:
        // only advance away from current context when the student has an approved
        // semester registration in the current semester.
        $approvedCurrentStmt = $conn->prepare("
            SELECT id
            FROM semester_registrations
            WHERE student_id = :student_id
              AND semester_id = :semester_id
              AND status = 'approved'
            ORDER BY id DESC
            LIMIT 1
        ");
        $approvedCurrentStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $currentSemesterId
        ]);
        $canProgressFromCurrent = ((int)$approvedCurrentStmt->fetchColumn() > 0);

        // 2a) Semester 1 -> Semester 2 (same academic year)
        if ($currentSemNo === 1 && $canProgressFromCurrent) {
            $sameYearStmt = $conn->prepare("
                SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
                FROM semesters s
                INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                WHERE s.academic_year_id = :academic_year_id
                  AND s.semester_number = 2
                LIMIT 1
            ");
            $sameYearStmt->execute(['academic_year_id' => $currentYearId]);
            $next = $sameYearStmt->fetch(PDO::FETCH_ASSOC);
            if ($next) {
                return [
                    'id' => (int)($next['id'] ?? 0),
                    'semester_name' => (string)($next['semester_name'] ?? '-'),
                    'semester_number' => (int)($next['semester_number'] ?? 0),
                    'academic_year_id' => (int)($next['academic_year_id'] ?? 0),
                    'academic_year' => (string)($next['academic_year'] ?? '-'),
                ];
            }
        }

        // 2b) Semester 2 -> Semester 1 (next academic year)
        if ($currentSemNo === 2 && $canProgressFromCurrent) {
            $nextYearStmt = $conn->prepare("
                SELECT ay2.id
                FROM academic_years ay1
                INNER JOIN academic_years ay2 ON ay2.start_date > ay1.start_date
                WHERE ay1.id = :academic_year_id
                ORDER BY ay2.start_date ASC, ay2.id ASC
                LIMIT 1
            ");
            $nextYearStmt->execute(['academic_year_id' => $currentYearId]);
            $nextYearId = (int)$nextYearStmt->fetchColumn();
            if ($nextYearId > 0) {
                $nextSemStmt = $conn->prepare("
                    SELECT s.id, s.semester_name, s.semester_number, s.academic_year_id, ay.year_name AS academic_year
                    FROM semesters s
                    INNER JOIN academic_years ay ON ay.id = s.academic_year_id
                    WHERE s.academic_year_id = :academic_year_id
                      AND s.semester_number = 1
                    LIMIT 1
                ");
                $nextSemStmt->execute(['academic_year_id' => $nextYearId]);
                $next = $nextSemStmt->fetch(PDO::FETCH_ASSOC);
                if ($next) {
                    return [
                        'id' => (int)($next['id'] ?? 0),
                        'semester_name' => (string)($next['semester_name'] ?? '-'),
                        'semester_number' => (int)($next['semester_number'] ?? 0),
                        'academic_year_id' => (int)($next['academic_year_id'] ?? 0),
                        'academic_year' => (string)($next['academic_year'] ?? '-'),
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Fall through to base context.
    }

    return $base;
}

/**
 * Resolve current finance snapshot for a student in a semester.
 * Source priority:
 * 1) Approved/published fee structure (program + academic year + level + semester)
 * 2) student_balances fallback when approved fee lines are unavailable
 */
function getStudentFinancialSnapshot($conn, $studentId, $semesterId = 0, $programId = 0, $academicYearId = 0, $fallbackLevelYear = 1, $forceRefresh = false) {
    static $memo = [];

    $studentId = (int)$studentId;
    $semesterId = (int)$semesterId;
    $programId = (int)$programId;
    $academicYearId = (int)$academicYearId;
    $fallbackLevelYear = max(1, (int)$fallbackLevelYear);

    $memoKey = implode(':', [$studentId, $semesterId, $programId, $academicYearId, $fallbackLevelYear]);
    $forceRefresh = (bool)$forceRefresh;
    if (!$forceRefresh && isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    $snapshot = [
        'student_id' => $studentId,
        'semester_id' => $semesterId,
        'academic_year_id' => $academicYearId,
        'program_id' => $programId,
        'level_year' => $fallbackLevelYear,
        'approved_total_fees' => 0.0,
        'total_fees' => 0.0,
        'total_paid' => 0.0,
        'balance_due' => 0.0,
        'balance_on_account' => 0.0,
        'account_credit' => 0.0,
        'source' => 'none',
        'fee_version_id' => 0
    ];

    if (!($conn instanceof PDO) || $studentId <= 0) {
        return $memo[$memoKey] = $snapshot;
    }

    if ($semesterId <= 0) {
        $ctx = getStudentCurrentSemesterContext($conn, $studentId);
        $semesterId = (int)($ctx['id'] ?? 0);
        if ($academicYearId <= 0) {
            $academicYearId = (int)($ctx['academic_year_id'] ?? 0);
        }
        $snapshot['semester_id'] = $semesterId;
        $snapshot['academic_year_id'] = $academicYearId;
    }

    $studentLevelYear = $fallbackLevelYear;
    try {
        $studentMetaStmt = $conn->prepare("
            SELECT program_id, COALESCE(level_year, year_of_study, 1) AS resolved_level_year
            FROM students
            WHERE id = :student_id
            LIMIT 1
        ");
        $studentMetaStmt->execute(['student_id' => $studentId]);
        $studentMeta = $studentMetaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($programId <= 0) {
            $programId = (int)($studentMeta['program_id'] ?? 0);
        }
        $studentLevelYear = max(1, (int)($studentMeta['resolved_level_year'] ?? $studentLevelYear));
    } catch (Exception $e) {
        // Keep supplied values.
    }

    if ($semesterId > 0 && $academicYearId <= 0) {
        try {
            $ayStmt = $conn->prepare("SELECT academic_year_id FROM semesters WHERE id = :semester_id LIMIT 1");
            $ayStmt->execute(['semester_id' => $semesterId]);
            $academicYearId = (int)$ayStmt->fetchColumn();
        } catch (Exception $e) {
            $academicYearId = 0;
        }
    }

    $levelYear = $studentLevelYear;
    if ($semesterId > 0) {
        try {
            $levelStmt = $conn->prepare("
                SELECT year_of_study
                FROM semester_registrations
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
                  AND status = 'approved'
                ORDER BY id DESC
                LIMIT 1
            ");
            $levelStmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId
            ]);
            $registeredLevel = (int)$levelStmt->fetchColumn();
            if ($registeredLevel > 0) {
                $levelYear = $registeredLevel;
            }
        } catch (Exception $e) {
            // Keep fallback level.
        }
    }

    $snapshot['program_id'] = $programId;
    $snapshot['academic_year_id'] = $academicYearId;
    $snapshot['level_year'] = $levelYear;

    $totalPaid = 0.0;
    if ($semesterId > 0) {
        try {
            $verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn);
            $paidStmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM payments
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
                  AND {$verifiedPaymentsPredicate}
            ");
            $paidStmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId
            ]);
            $totalPaid = (float)$paidStmt->fetchColumn();
        } catch (Exception $e) {
            $totalPaid = 0.0;
        }
    }

    $approvedFeesTotal = 0.0;
    $feeVersionId = 0;
    if ($semesterId > 0 && $levelYear > 0) {
        try {
            if (class_exists('FeeStructureGovernance')) {
                try {
                    FeeStructureGovernance::ensureSchema($conn);
                } catch (Exception $e) {
                    // Continue without hard failing.
                }
            }

            if (class_exists('FeeStructureGovernance') && method_exists('FeeStructureGovernance', 'findPreferredPublishedVersion')) {
                $version = FeeStructureGovernance::findPreferredPublishedVersion($conn, $programId, $academicYearId);
                $feeVersionId = (int)($version['id'] ?? 0);
            }

            if ($feeVersionId > 0) {
                $feesStmt = $conn->prepare("
                    SELECT COALESCE(SUM(GREATEST(0, COALESCE(fs.amount, 0) - COALESCE(fs.discount_amount, 0) + COALESCE(fs.fine_amount, 0))), 0)
                    FROM fees_structure fs
                    WHERE fs.version_id = :version_id
                      AND fs.status = 'active'
                      AND fs.level_year = :level_year
                      AND fs.semester_id = :semester_id
                ");
                $feesStmt->execute([
                    'version_id' => $feeVersionId,
                    'level_year' => $levelYear,
                    'semester_id' => $semesterId
                ]);
                $approvedFeesTotal = (float)$feesStmt->fetchColumn();
            } else {
                $feesStmt = $conn->prepare("
                    SELECT COALESCE(SUM(GREATEST(0, COALESCE(fs.amount, 0) - COALESCE(fs.discount_amount, 0) + COALESCE(fs.fine_amount, 0))), 0)
                    FROM fees_structure fs
                    WHERE fs.status = 'active'
                      AND fs.level_year = :level_year
                      AND fs.semester_id = :semester_id
                      AND (fs.program_id = :program_id OR fs.program_id IS NULL)
                ");
                $feesStmt->execute([
                    'level_year' => $levelYear,
                    'semester_id' => $semesterId,
                    'program_id' => $programId
                ]);
                $approvedFeesTotal = (float)$feesStmt->fetchColumn();
            }
        } catch (Exception $e) {
            $approvedFeesTotal = 0.0;
            $feeVersionId = 0;
        }
    }

    $totalFees = $approvedFeesTotal;
    $balanceDue = 0.0;
    $source = 'none';

    if ($approvedFeesTotal > 0) {
        $balanceDue = max($approvedFeesTotal - $totalPaid, 0);
        $source = 'fee_structure';
    } else if ($semesterId > 0) {
        try {
            $balStmt = $conn->prepare("
                SELECT
                    COALESCE(SUM(total_fees), 0) AS total_fees,
                    COALESCE(SUM(total_paid), 0) AS total_paid,
                    COALESCE(SUM(balance), 0) AS balance
                FROM student_balances
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
            ");
            $balStmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId
            ]);
            $balRow = $balStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $fallbackTotalFees = (float)($balRow['total_fees'] ?? 0);
            $fallbackTotalPaid = (float)($balRow['total_paid'] ?? 0);
            $fallbackBalance = (float)($balRow['balance'] ?? 0);

            if ($fallbackTotalFees > 0) {
                $totalFees = $fallbackTotalFees;
            }
            if ($totalPaid <= 0 && $fallbackTotalPaid > 0) {
                $totalPaid = $fallbackTotalPaid;
            }

            if ($totalFees > 0) {
                $balanceDue = max($totalFees - $totalPaid, 0);
            } else {
                $balanceDue = max($fallbackBalance, 0);
            }

            if ($totalFees > 0 || $totalPaid > 0 || $balanceDue > 0) {
                $source = 'student_balances';
            }
        } catch (Exception $e) {
            // Keep defaults.
        }
    }

    if ($source === 'none' && $semesterId > 0) {
        try {
            $invoiceStmt = $conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0) AS total_fees
                FROM invoices
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
            ");
            $invoiceStmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId
            ]);
            $invoiceTotalFees = (float)$invoiceStmt->fetchColumn();

            if ($invoiceTotalFees > 0) {
                $totalFees = $invoiceTotalFees;
                $balanceDue = max($totalFees - $totalPaid, 0);
                $source = 'invoices';
            }
        } catch (Exception $e) {
            // Keep defaults.
        }
    }

    $snapshot['fee_version_id'] = $feeVersionId;
    $resolvedFees = $approvedFeesTotal > 0 ? $approvedFeesTotal : $totalFees;
    $accountCredit = max($totalPaid - $resolvedFees, 0.0);
    $snapshot['approved_total_fees'] = $resolvedFees;
    $snapshot['total_fees'] = $totalFees;
    $snapshot['total_paid'] = $totalPaid;
    $snapshot['balance_due'] = $balanceDue;
    // Keep legacy key while exposing true overpayment credit.
    $snapshot['balance_on_account'] = $accountCredit > 0 ? $accountCredit : $balanceDue;
    $snapshot['account_credit'] = $accountCredit;
    $snapshot['source'] = $source;

    return $memo[$memoKey] = $snapshot;
}

/**
 * Count outstanding retakes using the latest progression-relevant attempt per course.
 * A newer submitted/approved/published replacement attempt suppresses the older
 * published fail from still counting as outstanding.
 */
function getStudentOutstandingRetakeCount(PDO $conn, int $studentId): int
{
    if ($studentId <= 0) {
        return 0;
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM results r
            INNER JOIN semesters s ON s.id = r.semester_id
            INNER JOIN academic_years ay ON ay.id = s.academic_year_id
            WHERE r.student_id = :student_id
              AND r.status = 'published'
              AND NOT EXISTS (
                    SELECT 1
                    FROM results r2
                    INNER JOIN semesters s2 ON s2.id = r2.semester_id
                    INNER JOIN academic_years ay2 ON ay2.id = s2.academic_year_id
                    WHERE r2.student_id = r.student_id
                      AND r2.course_id = r.course_id
                      AND r2.status IN ('submitted', 'approved', 'published')
                      AND (
                            ay2.start_date > ay.start_date
                            OR (ay2.start_date = ay.start_date AND s2.semester_number > s.semester_number)
                            OR (ay2.start_date = ay.start_date AND s2.semester_number = s.semester_number AND r2.id > r.id)
                      )
              )
              AND (
                    (r.grade_points IS NOT NULL AND r.grade_points < 2.0)
                    OR UPPER(COALESCE(r.grade, '')) IN ('E', 'F')
              )
        ");
        $stmt->execute(['student_id' => $studentId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Resolve all-time outstanding bills for a student.
 */
function getStudentOutstandingBillsTotal(PDO $conn, int $studentId): float
{
    if ($studentId <= 0) {
        return 0.0;
    }

    try {
        $balStmt = $conn->prepare("
            SELECT COALESCE(SUM(GREATEST(COALESCE(balance, 0), 0)), 0)
            FROM student_balances
            WHERE student_id = :student_id
        ");
        $balStmt->execute(['student_id' => $studentId]);
        $balanceOutstanding = (float)$balStmt->fetchColumn();
        if ($balanceOutstanding > 0) {
            return $balanceOutstanding;
        }
    } catch (Exception $e) {
        // Fall back to invoices/payments path.
    }

    try {
        $invoiceStmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM invoices
            WHERE student_id = :student_id
        ");
        $invoiceStmt->execute(['student_id' => $studentId]);
        $invoiceTotal = (float)$invoiceStmt->fetchColumn();

        $verifiedPaymentsPredicate = getVerifiedPaymentsPredicate($conn);
        $paidStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE student_id = :student_id
              AND {$verifiedPaymentsPredicate}
        ");
        $paidStmt->execute(['student_id' => $studentId]);
        $paidTotal = (float)$paidStmt->fetchColumn();

        return max($invoiceTotal - $paidTotal, 0.0);
    } catch (Exception $e) {
        return 0.0;
    }
}

/**
 * Evaluate whether a student's transcript can be released.
 */
function getStudentTranscriptEligibility(PDO $conn, int $studentId): array
{
    $result = [
        'eligible' => false,
        'completed_studies' => false,
        'has_no_retakes' => true,
        'retake_count' => 0,
        'bills_cleared' => true,
        'outstanding_bills' => 0.0,
        'discipline_ok' => true,
        'discipline_status' => '',
        'blocking_reasons' => []
    ];

    if ($studentId <= 0) {
        $result['blocking_reasons'][] = 'Invalid student record.';
        return $result;
    }

    $student = [];
    try {
        $studentStmt = $conn->prepare("SELECT * FROM students WHERE id = :student_id LIMIT 1");
        $studentStmt->execute(['student_id' => $studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $student = [];
    }

    $academicStatus = strtolower(trim((string)($student['academic_status'] ?? '')));
    $studentStatus = strtolower(trim((string)($student['status'] ?? '')));
    $hasGraduationRecord = !empty($student['graduation_date']) || !empty($student['graduation_award_title']);
    $completedStudies = $hasGraduationRecord
        || in_array($academicStatus, ['graduated', 'completed', 'complete'], true)
        || in_array($studentStatus, ['graduated', 'completed', 'complete'], true);
    $result['completed_studies'] = $completedStudies;

    $retakeCount = getStudentOutstandingRetakeCount($conn, $studentId);
    $result['retake_count'] = $retakeCount;
    $result['has_no_retakes'] = ($retakeCount === 0);

    $outstandingBills = getStudentOutstandingBillsTotal($conn, $studentId);
    $result['outstanding_bills'] = $outstandingBills;
    $result['bills_cleared'] = ($outstandingBills <= 0.009);

    $disciplineStatus = trim((string)($student['discipline_status'] ?? ''));
    $disciplineStatusLower = strtolower($disciplineStatus);
    $disciplineGoodStatuses = ['good standing', 'good', 'cleared', 'clear'];
    $disciplineBadStatuses = ['probation', 'suspended', 'expelled', 'dismissed', 'disciplinary'];
    $disciplineOk = ($disciplineStatusLower === '' || in_array($disciplineStatusLower, $disciplineGoodStatuses, true));
    if (!$disciplineOk) {
        foreach ($disciplineBadStatuses as $badStatus) {
            if (strpos($disciplineStatusLower, $badStatus) !== false) {
                $disciplineOk = false;
                break;
            }
        }
    }
    $result['discipline_status'] = $disciplineStatus;
    $result['discipline_ok'] = $disciplineOk;

    if (!$result['completed_studies']) {
        $result['blocking_reasons'][] = 'Student has not completed studies.';
    }
    if (!$result['has_no_retakes']) {
        $result['blocking_reasons'][] = 'Outstanding retakes: ' . (int)$result['retake_count'] . '.';
    }
    if (!$result['bills_cleared']) {
        $result['blocking_reasons'][] = 'Outstanding institutional bills: UGX ' . number_format((float)$result['outstanding_bills']);
    }
    if (!$result['discipline_ok']) {
        $result['blocking_reasons'][] = 'Discipline status is not in good standing.';
    }

    $result['eligible'] = (
        $result['completed_studies']
        && $result['has_no_retakes']
        && $result['bills_cleared']
        && $result['discipline_ok']
    );

    return $result;
}

/**
 * Build monitor rows for students enrolled (approved semester registration)
 * in the selected semester.
 * Amounts are returned in UGX base, with row-level display currency metadata.
 */
function getActiveStudentBalanceMonitor(PDO $conn, int $semesterId): array {
    $result = [
        'rows' => [],
        'student_count' => 0,
        'students_with_balance' => 0,
        'outstanding_total_ugx' => 0.0,
        'expected_total_fees_ugx' => 0.0,
        'collected_toward_fees_ugx' => 0.0,
        'overpayment_total_ugx' => 0.0
    ];

    if ($semesterId <= 0) {
        return $result;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                DISTINCT s.id,
                s.student_id,
                s.first_name,
                s.last_name,
                s.program_id,
                COALESCE(sr.year_of_study, s.level_year, s.year_of_study, 1) AS level_year,
                s.country,
                s.nationality
            FROM students s
            INNER JOIN semester_registrations sr
                ON sr.student_id = s.id
               AND sr.semester_id = :semester_id
               AND sr.status = 'approved'
            WHERE s.status = 'active'
            ORDER BY s.last_name ASC, s.first_name ASC
        ");
        $stmt->execute(['semester_id' => $semesterId]);
        $students = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Exception $e) {
        return $result;
    }

    $result['student_count'] = count($students);
    $rows = [];
    foreach ($students as $student) {
        $studentId = (int)($student['id'] ?? 0);
        if ($studentId <= 0) {
            continue;
        }

        $snapshot = getStudentFinancialSnapshot(
            $conn,
            $studentId,
            $semesterId,
            (int)($student['program_id'] ?? 0),
            0,
            (int)($student['level_year'] ?? 1)
        );

        $totalFees = (float)($snapshot['approved_total_fees'] ?? $snapshot['total_fees'] ?? 0.0);
        $totalPaid = (float)($snapshot['total_paid'] ?? 0.0);
        $balanceDue = (float)($snapshot['balance_due'] ?? max($totalFees - $totalPaid, 0.0));
        if ($balanceDue < 0) {
            $balanceDue = 0.0;
        }
        $collectedTowardFees = min($totalPaid, $totalFees);
        if ($collectedTowardFees < 0) {
            $collectedTowardFees = 0.0;
        }
        $overpayment = max($totalPaid - $totalFees, 0.0);

        $displayCurrency = getStudentDisplayCurrencyCode(
            (string)($student['country'] ?? ''),
            (string)($student['nationality'] ?? '')
        );

        $rows[] = [
            'student_db_id' => $studentId,
            'student_id' => (string)($student['student_id'] ?? ''),
            'first_name' => (string)($student['first_name'] ?? ''),
            'last_name' => (string)($student['last_name'] ?? ''),
            'country' => (string)($student['country'] ?? ''),
            'nationality' => (string)($student['nationality'] ?? ''),
            'display_currency' => $displayCurrency,
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $balanceDue
        ];

        $result['outstanding_total_ugx'] += $balanceDue;
        $result['expected_total_fees_ugx'] += $totalFees;
        $result['collected_toward_fees_ugx'] += $collectedTowardFees;
        $result['overpayment_total_ugx'] += $overpayment;
        if ($balanceDue > 0) {
            $result['students_with_balance']++;
        }
    }

    usort($rows, function ($a, $b) {
        $balCmp = ((float)($b['balance'] ?? 0.0)) <=> ((float)($a['balance'] ?? 0.0));
        if ($balCmp !== 0) {
            return $balCmp;
        }
        $nameA = strtolower(trim((string)($a['last_name'] ?? '') . ' ' . (string)($a['first_name'] ?? '')));
        $nameB = strtolower(trim((string)($b['last_name'] ?? '') . ' ' . (string)($b['first_name'] ?? '')));
        return strcmp($nameA, $nameB);
    });

    $result['rows'] = $rows;
    return $result;
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
        $approvedRegistrationYear = 0;
        $approvedYearStmt = $conn->prepare("
            SELECT year_of_study
            FROM semester_registrations
            WHERE student_id = :student_id
              AND semester_id = :semester_id
              AND status = 'approved'
            ORDER BY id DESC
            LIMIT 1
        ");
        $approvedYearStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId
        ]);
        $approvedRegistrationYear = (int)$approvedYearStmt->fetchColumn();
        if ($approvedRegistrationYear > 0 && $levelYear > 0 && $approvedRegistrationYear !== $levelYear) {
            error_log('auto_assign_courses guard: year_of_study mismatch for student ' . $studentId
                . ', semester ' . $semesterId . ', approved=' . $approvedRegistrationYear
                . ', requested=' . $levelYear);
            return false;
        }
        if ($levelYear <= 0) {
            $levelYear = $approvedRegistrationYear;
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

/**
 * Audit & Traceability infrastructure bootstrap.
 * Creates core audit tables/indexes and immutable triggers when missing.
 */
function ensureAuditTraceabilityInfrastructure(PDO $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $tableExists = static function (PDO $conn, string $tableName): bool {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
            ");
            $stmt->execute(['table_name' => $tableName]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    $indexExists = static function (PDO $conn, string $tableName, string $indexName): bool {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                  AND index_name = :index_name
            ");
            $stmt->execute([
                'table_name' => $tableName,
                'index_name' => $indexName
            ]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    $columnExists = static function (PDO $conn, string $tableName, string $columnName): bool {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                  AND column_name = :column_name
            ");
            $stmt->execute([
                'table_name' => $tableName,
                'column_name' => $columnName
            ]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    $triggerExists = static function (PDO $conn, string $triggerName): bool {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.triggers
                WHERE trigger_schema = DATABASE()
                  AND trigger_name = :trigger_name
            ");
            $stmt->execute(['trigger_name' => $triggerName]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    try {
        if (!$tableExists($conn, 'results_audit')) {
            $conn->exec("CREATE TABLE IF NOT EXISTS `results_audit` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `result_id` INT(11) NOT NULL,
                `student_id` INT(11) NOT NULL,
                `course_id` INT(11) NOT NULL,
                `changed_by_user_id` INT(11) NOT NULL,
                `change_type` ENUM('publish','edit') NOT NULL,
                `old_marks` LONGTEXT DEFAULT NULL,
                `new_marks` LONGTEXT NOT NULL,
                `reason` TEXT DEFAULT NULL,
                `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_results_audit_result` (`result_id`),
                KEY `idx_results_audit_student` (`student_id`),
                KEY `idx_results_audit_course` (`course_id`),
                KEY `idx_results_audit_actor` (`changed_by_user_id`),
                KEY `idx_results_audit_changed_at` (`changed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            if (!$indexExists($conn, 'results_audit', 'idx_results_audit_actor')) {
                $conn->exec("ALTER TABLE results_audit ADD INDEX idx_results_audit_actor (changed_by_user_id)");
            }
            if (!$indexExists($conn, 'results_audit', 'idx_results_audit_changed_at')) {
                $conn->exec("ALTER TABLE results_audit ADD INDEX idx_results_audit_changed_at (changed_at)");
            }
        }
    } catch (Exception $e) {
        error_log('Audit infra warning (results_audit): ' . $e->getMessage());
    }

    try {
        if ($tableExists($conn, 'student_profile_audit')) {
            if (!$indexExists($conn, 'student_profile_audit', 'idx_spa_changed_at')) {
                $conn->exec("ALTER TABLE student_profile_audit ADD INDEX idx_spa_changed_at (changed_at)");
            }
            if (!$indexExists($conn, 'student_profile_audit', 'idx_spa_user')) {
                $conn->exec("ALTER TABLE student_profile_audit ADD INDEX idx_spa_user (changed_by_user_id)");
            }
        }
    } catch (Exception $e) {
        error_log('Audit infra warning (student_profile_audit): ' . $e->getMessage());
    }

    try {
        if ($tableExists($conn, 'students')) {
            if (!$columnExists($conn, 'students', 'graduation_date')) {
                $conn->exec("ALTER TABLE students ADD COLUMN graduation_date DATE NULL AFTER status");
            }
            if (!$columnExists($conn, 'students', 'graduation_semester_id')) {
                $conn->exec("ALTER TABLE students ADD COLUMN graduation_semester_id INT NULL AFTER graduation_date");
            }
            if (!$columnExists($conn, 'students', 'graduation_award_title')) {
                $conn->exec("ALTER TABLE students ADD COLUMN graduation_award_title VARCHAR(200) NULL AFTER graduation_semester_id");
            }
            if (!$columnExists($conn, 'students', 'graduation_classification')) {
                $conn->exec("ALTER TABLE students ADD COLUMN graduation_classification VARCHAR(100) NULL AFTER graduation_award_title");
            }

            if (!$indexExists($conn, 'students', 'idx_graduation_date')) {
                $conn->exec("ALTER TABLE students ADD INDEX idx_graduation_date (graduation_date)");
            }
            if (!$indexExists($conn, 'students', 'idx_graduation_semester')) {
                $conn->exec("ALTER TABLE students ADD INDEX idx_graduation_semester (graduation_semester_id)");
            }
            if (!$indexExists($conn, 'students', 'idx_graduation_class')) {
                $conn->exec("ALTER TABLE students ADD INDEX idx_graduation_class (graduation_classification)");
            }
        }
    } catch (Exception $e) {
        error_log('Audit infra warning (students graduation metadata): ' . $e->getMessage());
    }

    try {
        if (!$tableExists($conn, 'student_graduation_awards')) {
            $conn->exec("CREATE TABLE IF NOT EXISTS student_graduation_awards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                award_type ENUM('degree','diploma','certificate','classification','honours','other') NOT NULL DEFAULT 'degree',
                award_title VARCHAR(200) NOT NULL,
                classification VARCHAR(100) NULL,
                cgpa_at_award DECIMAL(3,2) NULL,
                award_date DATE NOT NULL,
                approved_by_user_id INT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_sga_student (student_id),
                KEY idx_sga_award_date (award_date),
                KEY idx_sga_type (award_type),
                KEY idx_sga_approved_by (approved_by_user_id),
                CONSTRAINT fk_sga_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                CONSTRAINT fk_sga_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            if (!$indexExists($conn, 'student_graduation_awards', 'idx_sga_student')) {
                $conn->exec("ALTER TABLE student_graduation_awards ADD INDEX idx_sga_student (student_id)");
            }
            if (!$indexExists($conn, 'student_graduation_awards', 'idx_sga_award_date')) {
                $conn->exec("ALTER TABLE student_graduation_awards ADD INDEX idx_sga_award_date (award_date)");
            }
            if (!$indexExists($conn, 'student_graduation_awards', 'idx_sga_type')) {
                $conn->exec("ALTER TABLE student_graduation_awards ADD INDEX idx_sga_type (award_type)");
            }
            if (!$indexExists($conn, 'student_graduation_awards', 'idx_sga_approved_by')) {
                $conn->exec("ALTER TABLE student_graduation_awards ADD INDEX idx_sga_approved_by (approved_by_user_id)");
            }
        }
    } catch (Exception $e) {
        error_log('Audit infra warning (student_graduation_awards): ' . $e->getMessage());
    }

    $createImmutableTrigger = static function (PDO $conn, string $triggerName, string $tableName, string $eventName, string $message) use ($tableExists, $triggerExists): void {
        try {
            if (!$tableExists($conn, $tableName) || $triggerExists($conn, $triggerName)) {
                return;
            }
            $sql = "CREATE TRIGGER {$triggerName}
                    BEFORE {$eventName} ON {$tableName}
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = " . $conn->quote($message);
            $conn->exec($sql);
        } catch (Exception $e) {
            error_log('Audit trigger warning (' . $triggerName . '): ' . $e->getMessage());
        }
    };

    $createImmutableTrigger($conn, 'trg_results_audit_lock_update', 'results_audit', 'UPDATE', 'results_audit is immutable');
    $createImmutableTrigger($conn, 'trg_results_audit_lock_delete', 'results_audit', 'DELETE', 'results_audit cannot be deleted');
    $createImmutableTrigger($conn, 'trg_student_profile_audit_lock_update', 'student_profile_audit', 'UPDATE', 'student_profile_audit is immutable');
    $createImmutableTrigger($conn, 'trg_student_profile_audit_lock_delete', 'student_profile_audit', 'DELETE', 'student_profile_audit cannot be deleted');
    $createImmutableTrigger($conn, 'trg_activity_logs_lock_update', 'activity_logs', 'UPDATE', 'activity_logs updates are not allowed');
}

