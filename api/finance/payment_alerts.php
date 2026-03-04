<?php
require_once '../../config.php';

header('Content-Type: application/json');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$auth = new Auth('finance');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Finance account is missing.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = [];
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$limit = (int)($_GET['limit'] ?? ($_POST['limit'] ?? ($jsonBody['limit'] ?? 8)));
if ($limit < 1) {
    $limit = 8;
}
if ($limit > 30) {
    $limit = 30;
}
$action = strtolower(trim((string)($_GET['action'] ?? ($_POST['action'] ?? ($jsonBody['action'] ?? 'fetch')))));

try {
    $db = new Database();
    $conn = $db->getConnection();

    $whereSql = "
        user_id = :user_id
        AND COALESCE(read_status, 'unread') <> 'read'
        AND (
            title IN ('PRN Payment Posted', 'Bank Proof Submitted')
            OR message LIKE :msg_prn
            OR link LIKE :link_pay
            OR link LIKE :link_bank
        )
    ";

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_all_read') {
        $csrfToken = (string)($_POST['csrf_token'] ?? ($jsonBody['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
        if (!Security::verifyCSRFToken($csrfToken)) {
            http_response_code(419);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }

        $markStmt = $conn->prepare("
            UPDATE notifications
            SET read_status = 'read',
                read_at = NOW()
            WHERE {$whereSql}
        ");
        $markStmt->execute([
            'user_id' => $userId,
            'msg_prn' => '%PRN %',
            'link_pay' => '%/views/finance/dashboard.php?section=payments%',
            'link_bank' => '%#bank-verification-section%'
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Payment alerts marked as read.',
            'marked_count' => (int)$markStmt->rowCount()
        ]);
        exit;
    }

    $countStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE {$whereSql}
    ");
    $countStmt->execute([
        'user_id' => $userId,
        'msg_prn' => '%PRN %',
        'link_pay' => '%/views/finance/dashboard.php?section=payments%',
        'link_bank' => '%#bank-verification-section%'
    ]);
    $unreadCount = (int)$countStmt->fetchColumn();

    $listStmt = $conn->prepare("
        SELECT
            id,
            title,
            message,
            type,
            link,
            created_at
        FROM notifications
        WHERE {$whereSql}
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");
    $listStmt->execute([
        'user_id' => $userId,
        'msg_prn' => '%PRN %',
        'link_pay' => '%/views/finance/dashboard.php?section=payments%',
        'link_bank' => '%#bank-verification-section%'
    ]);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $alerts = [];
    foreach ($rows as $row) {
        $linkRaw = trim((string)($row['link'] ?? ''));
        $linkSafe = '#';
        if (
            $linkRaw !== '' &&
            stripos($linkRaw, 'javascript:') !== 0 &&
            stripos($linkRaw, 'data:') !== 0 &&
            stripos($linkRaw, 'vbscript:') !== 0 &&
            !preg_match('#/views/(admin|student|lecturer|finance)/logout\.php#i', $linkRaw)
        ) {
            $parsed = @parse_url($linkRaw);
            if (is_array($parsed) && !empty($parsed['path'])) {
                $linkSafe = (string)$parsed['path'];
                if (isset($parsed['query']) && $parsed['query'] !== '') {
                    $linkSafe .= '?' . $parsed['query'];
                }
                $fragment = isset($parsed['fragment']) ? (string)$parsed['fragment'] : '';
                if ($fragment === '' && stripos((string)$parsed['path'], '/views/finance/dashboard.php') !== false) {
                    $query = (string)($parsed['query'] ?? '');
                    if (stripos($query, 'section=payments') !== false) {
                        $fragment = 'payments-section';
                    } elseif (stripos($query, 'section=bank-verification') !== false) {
                        $fragment = 'bank-verification-section';
                    }
                }
                if ($fragment !== '') {
                    $linkSafe .= '#' . $fragment;
                }
            } else {
                $linkSafe = $linkRaw;
            }
        }

        $createdAt = (string)($row['created_at'] ?? '');
        $alerts[] = [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'type' => (string)($row['type'] ?? 'info'),
            'link' => $linkSafe,
            'created_at' => $createdAt,
            'created_at_label' => $createdAt !== '' ? date('d M Y, h:i A', strtotime($createdAt)) : '-',
            'time_ago' => $createdAt !== '' ? Helper::timeAgo($createdAt) : '-'
        ];
    }

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'alerts' => $alerts
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch payment alerts.',
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null
    ]);
}
