<?php
/**
 * Notifications API
 * Handles mark as read operations
 */
require_once '../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (any module)
$userId = null;
$modules = ['admin', 'student', 'lecturer', 'finance'];
foreach ($modules as $mod) {
    if (!empty($_SESSION[$mod . '_logged_in']) && $_SESSION[$mod . '_logged_in'] === true) {
        $userId = $_SESSION[$mod . '_user_id'] ?? null;
        break;
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$db = new Database();
$conn = $db->getConnection();

switch ($action) {
    case 'fetch':
        // Fetch notifications for the current user
        $isAdmin = !empty($_SESSION['admin_logged_in']);
        $limit = intval($_GET['limit'] ?? 10);
        
        if ($isAdmin) {
            // Admin sees all system notifications
            $stmt = $conn->prepare("SELECT * FROM notifications WHERE read_status = 'unread' ORDER BY created_at DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            // Others see only their notifications
            $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :user_id AND read_status = 'unread' ORDER BY created_at DESC LIMIT :limit");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format notifications for JSON response
        $formattedNotifications = [];
        foreach ($notifications as $notif) {
            $formattedNotifications[] = [
                'id' => $notif['id'],
                'type' => $notif['type'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'link' => $notif['link'],
                'created_at' => $notif['created_at'],
                'time_ago' => Helper::timeAgo($notif['created_at'])
            ];
        }
        
        echo json_encode([
            'success' => true,
            'count' => count($formattedNotifications),
            'notifications' => $formattedNotifications
        ]);
        break;

    case 'mark_all_read':
        // Admin marks all, others mark only their own
        $isAdmin = !empty($_SESSION['admin_logged_in']);
        if ($isAdmin) {
            $stmt = $conn->prepare("UPDATE notifications SET read_status = 'read', read_at = NOW() WHERE read_status = 'unread'");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET read_status = 'read', read_at = NOW() WHERE user_id = :user_id AND read_status = 'unread'");
            $stmt->execute(['user_id' => $userId]);
        }
        echo json_encode(['success' => true]);
        break;

    case 'mark_read':
        $id = intval($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET read_status = 'read', read_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
