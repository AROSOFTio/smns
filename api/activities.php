<?php
/**
 * API endpoint for recent activities
 */
require_once '../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize session and auth with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Get recent activities
$logger = new Logger();
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$recentActivities = $logger->getRecentActivities($limit);

// Format activities for JSON response
$formattedActivities = [];
foreach ($recentActivities as $activity) {
    $formattedActivities[] = [
        'id' => $activity['id'],
        'action' => $activity['action'],
        'description' => $activity['description'],
        'module' => $activity['module'],
        'username' => $activity['username'] ?? null,
        'created_at' => $activity['created_at'],
        'formatted_time' => Helper::formatDateTime($activity['created_at'], 'g:i:s A'),
        'formatted_date' => Helper::formatDate($activity['created_at'], 'M d, Y')
    ];
}

echo json_encode([
    'success' => true,
    'activities' => $formattedActivities
]);
?>