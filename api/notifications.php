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
