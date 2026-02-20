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
    $tempSession = new Session();
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
    // Try to detect from any active session
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
 * Auto-assign courses to a student for a given semester.
 * Prefers explicit `course_assignments` for the semester, then falls back to courses by program/level.
 * Safe to call multiple times; avoids creating duplicates.
 */
function auto_assign_courses(PDO $conn, int $studentId, int $semesterId, $adminId = null, $programId = null, $levelYear = null) {
    try {
        // Insert from course_assignments for the exact semester (includes academic year via semester)
        $insAssign = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at, updated_at)
            SELECT :student_id, c.id, :semester_id, CURDATE(), 'approved', :approved_by, NOW(), NOW(), NOW()
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.id
            WHERE ca.semester_id = :semester_id AND ca.status = 'active' AND c.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id)");
        $insAssign->execute(['student_id' => $studentId, 'semester_id' => $semesterId, 'approved_by' => $adminId]);

        // Fallback: insert from courses table filtered by program/level and semester offered
        $insFallback = "INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at, updated_at)
            SELECT :student_id, c.id, :semester_id, CURDATE(), 'approved', :approved_by, NOW(), NOW(), NOW()
            FROM courses c
            WHERE c.status = 'active' AND (c.semester_offered = :sem_num OR c.semester_offered = 3)
            AND NOT EXISTS (SELECT 1 FROM course_registrations cr WHERE cr.student_id = :student_id AND cr.course_id = c.id AND cr.semester_id = :semester_id)";

        // Resolve semester number for sem_num parameter
        $semNum = null;
        $sstmt = $conn->prepare('SELECT semester_number FROM semesters WHERE id = :id LIMIT 1');
        $sstmt->execute(['id' => $semesterId]);
        $semNum = $sstmt->fetchColumn();

        if ($programId && $levelYear) {
            $insFallback = str_replace("WHERE c.status = 'active'", "WHERE c.program_id = :program_id AND c.level_year = :level_year AND c.status = 'active'", $insFallback);
        }

        $params = ['student_id' => $studentId, 'semester_id' => $semesterId, 'approved_by' => $adminId, 'sem_num' => $semNum];
        if ($programId && $levelYear) { $params['program_id'] = $programId; $params['level_year'] = $levelYear; }

        $insStmt = $conn->prepare($insFallback);
        $insStmt->execute($params);

        return true;
    } catch (Exception $e) {
        error_log('auto_assign_courses error: ' . $e->getMessage());
        return false;
    }
}

