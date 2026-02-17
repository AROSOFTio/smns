<?php
/**
 * Student Notification Center
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/config.php';

$session = new Session('student');
$auth = new Auth('student');

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: /smns/views/auth/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentId = $_SESSION['student_id'] ?? $currentUser['id'] ?? 0;

$db = new Database();
$conn = $db->getConnection();

// Fetch all notifications (inbox)
$stmt = $conn->prepare("
    SELECT n.*, nr.read_at
    FROM notifications n
    LEFT JOIN notifications_read nr ON n.id = nr.notification_id AND nr.user_id = :user_id_join
    WHERE n.user_id = :user_id_where OR n.user_id IS NULL OR n.user_id = 0
    ORDER BY n.created_at DESC
");
$stmt->execute([
    'user_id_join' => $currentUser['id'],
    'user_id_where' => $currentUser['id']
]);
$inboxNotifications = $stmt->fetchAll();

// Fetch archived notifications
$stmt = $conn->prepare("SELECT * FROM notification_archive WHERE user_id = :user_id ORDER BY archived_at DESC");
$stmt->execute(['user_id' => $currentUser['id']]);
$archivedNotifications = $stmt->fetchAll();


$pageTitle = 'Notification Center - ' . APP_NAME;
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 2) . '/includes/student/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <h4>Notification Center</h4>
    </div>

    <div class="content-area">
        <?php 
        $successMessage = $session->getFlash('success');
        if ($successMessage): 
        ?>
            <div class="alert alert-success"><?php echo e($successMessage); ?></div>
        <?php endif; ?>
        
        <?php 
        $errorMessage = $session->getFlash('error');
        if ($errorMessage): 
        ?>
            <div class="alert alert-danger"><?php echo e($errorMessage); ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs" id="notificationTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="inbox-tab" data-toggle="tab" href="#inbox" role="tab" aria-controls="inbox" aria-selected="true">
                    Inbox (<?php echo count($inboxNotifications); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="archived-tab" data-toggle="tab" href="#archived" role="tab" aria-controls="archived" aria-selected="false">
                    Archived (<?php echo count($archivedNotifications); ?>)
                </a>
            </li>
        </ul>

        <div class="tab-content" id="notificationTabsContent">
            <!-- Inbox Tab -->
            <div class="tab-pane fade show active" id="inbox" role="tabpanel" aria-labelledby="inbox-tab">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>All Notifications</span>
                        <button id="mark-all-read-btn" class="btn btn-sm btn-primary">Mark All as Read</button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($inboxNotifications)): ?>
                            <p class="text-center">Your inbox is empty.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($inboxNotifications as $notif): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center <?php echo is_null($notif['read_at']) ? 'font-weight-bold' : ''; ?>" data-notification-id="<?php echo $notif['id']; ?>">
                                        <div>
                                            <small class="text-muted"><?php echo Helper::timeAgo($notif['created_at']); ?></small>
                                            <h5><?php echo e($notif['title']); ?></h5>
                                            <p class="mb-1"><?php echo e($notif['message']); ?></p>
                                            <?php if (!empty($notif['link']) && strpos($notif['link'], 'action:') !== 0): 
                                                $url = (strpos($notif['link'], 'http') === 0) ? $notif['link'] : BASE_URL . $notif['link'];
                                            ?>
                                                <a href="<?php echo e($url); ?>" class="btn btn-sm btn-info">View Details</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ml-auto">
                                            <button class="btn btn-sm btn-outline-secondary archive-btn" title="Archive">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Archived Tab -->
            <div class="tab-pane fade" id="archived" role="tabpanel" aria-labelledby="archived-tab">
                <div class="card">
                    <div class="card-header">
                        <span>Archived Notifications</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($archivedNotifications)): ?>
                            <p class="text-center">You have no archived notifications.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($archivedNotifications as $archive): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center" data-archive-id="<?php echo $archive['id']; ?>">
                                        <div>
                                            <small class="text-muted">Archived <?php echo Helper::timeAgo($archive['archived_at']); ?></small>
                                            <h5><?php echo e($archive['title']); ?></h5>
                                            <p><?php echo e($archive['message']); ?></p>
                                        </div>
                                        <button class="btn btn-sm btn-danger delete-archive-btn">Delete</button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const API_URL = '/smns/api/notifications.php';

    // Mark all as read
    document.getElementById('mark-all-read-btn').addEventListener('click', function() {
        fetch(`${API_URL}?action=mark_all_read`, { method: 'POST' })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI without a full reload
                    document.querySelectorAll('.list-group-item').forEach(item => {
                        item.classList.remove('font-weight-bold');
                    });

                    // Find the notification bell count in the header and hide it
                    const bellBadge = document.querySelector('#notificationBell .notification-badge');
                    if (bellBadge) {
                        bellBadge.textContent = '0';
                        bellBadge.style.display = 'none';
                    }
                    
                    // No full page reload, just update UI dynamically
                    // location.reload(); 
                } else {
                    alert('Failed to mark notifications as read.');
                }
            });
    });

    // Archive notification
    document.querySelectorAll('.archive-btn').forEach(button => {
        button.addEventListener('click', function() {
            const listItem = this.closest('li');
            const notificationId = listItem.dataset.notificationId;
            fetch(`${API_URL}?action=archive&id=${notificationId}`, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        listItem.remove();
                        // Optionally, refresh the archived tab or add the item there dynamically
                        location.reload(); // Simple reload to see changes in both tabs
                    } else {
                        alert('Failed to archive notification.');
                    }
                });
        });
    });

    // Delete archived notification
    document.querySelectorAll('.delete-archive-btn').forEach(button => {
        button.addEventListener('click', function() {
            const listItem = this.closest('li');
            const archiveId = listItem.dataset.archiveId;
            fetch(`${API_URL}?action=delete_archive&id=${archiveId}`, { method: 'POST' })
                .then(() => listItem.remove());
        });
    });
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
