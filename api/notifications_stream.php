<?php
require_once '../config.php';

$modules = ['admin', 'student', 'lecturer', 'finance'];
$requestedModule = strtolower(trim((string)($_GET['module'] ?? $_REQUEST['module'] ?? '')));
$userId = 0;
$activeModule = '';

$tryModuleSession = function ($mod) use (&$userId, &$activeModule) {
    $cookieName = 'SMNS_' . strtoupper($mod) . '_SESSION';
    if (empty($_COOKIE[$cookieName])) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($cookieName);
    session_start();

    $loggedInKey = $mod . '_logged_in';
    $userIdKey = $mod . '_user_id';
    if (!empty($_SESSION[$loggedInKey]) && $_SESSION[$loggedInKey] === true && !empty($_SESSION[$userIdKey])) {
        $userId = (int)$_SESSION[$userIdKey];
        $activeModule = $mod;
        return $userId > 0;
    }

    return false;
};

if (in_array($requestedModule, $modules, true)) {
    $tryModuleSession($requestedModule);
} else {
    foreach ($modules as $mod) {
        if ($tryModuleSession($mod)) {
            break;
        }
    }
}

if ($userId <= 0) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

ignore_user_abort(true);
@set_time_limit(0);

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$db = new Database();
$conn = $db->getConnection();

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

$fetchUnreadCount = function () use ($conn, $userId) {
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

    $bridge = function_exists('getFinanceMessageNotificationBridge')
        ? getFinanceMessageNotificationBridge($conn, $userId)
        : ['gap' => 0];

    return $baseCount + (int)($bridge['gap'] ?? 0);
};

$financeTableExists = null;
$fetchFinanceUnread = function () use ($conn, &$financeTableExists) {
    if ($financeTableExists === null) {
        $t = $conn->query("SHOW TABLES LIKE 'finance_messages'");
        $financeTableExists = (bool)($t && $t->fetch(PDO::FETCH_NUM));
    }
    if (!$financeTableExists) {
        return 0;
    }

    $stmt = $conn->query("
        SELECT COUNT(*)
        FROM finance_messages
        WHERE sender_role = 'student'
          AND is_read = 0
    ");
    return (int)($stmt ? $stmt->fetchColumn() : 0);
};

$emit = function ($event, array $payload) {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    @ob_flush();
    flush();
};

echo "retry: 3000\n\n";
@ob_flush();
flush();

$streamLifetimeSeconds = 55;
$tickSeconds = 2;
$startedAt = time();
$lastPingAt = 0;
$lastCount = null;
$lastFinanceUnread = null;

while (!connection_aborted() && (time() - $startedAt) < $streamLifetimeSeconds) {
    try {
        $count = (int)$fetchUnreadCount();
        $financeUnread = null;
        if ($activeModule === 'finance') {
            $financeUnread = (int)$fetchFinanceUnread();
        }

        $isInitial = ($lastCount === null);
        $countChanged = ($count !== $lastCount);
        $financeChanged = ($activeModule === 'finance' && $financeUnread !== $lastFinanceUnread);

        if ($isInitial || $countChanged || $financeChanged) {
            $lastCount = $count;
            $lastFinanceUnread = $financeUnread;

            $payload = [
                'count' => $count,
                'module' => $activeModule,
                'ts' => time()
            ];
            if ($financeUnread !== null) {
                $payload['finance_unread_messages'] = $financeUnread;
            }
            $emit('unread_count', $payload);
            $lastPingAt = time();
        } elseif ((time() - $lastPingAt) >= 15) {
            $emit('ping', ['ts' => time()]);
            $lastPingAt = time();
        }
    } catch (Exception $e) {
        $emit('stream_error', ['message' => 'Notification stream error', 'ts' => time()]);
    }

    sleep($tickSeconds);
}
