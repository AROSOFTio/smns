<?php
/**
 * Admin - Saved Notifications
 */
require_once dirname(__DIR__, 3) . '/config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /smns/views/auth/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$adminId = $currentUser['id'];

$db = new Database();
$conn = $db->getConnection();

// Fetch archived notifications for the current admin
$stmt = $conn->prepare("SELECT * FROM notification_archive WHERE user_id = :user_id ORDER BY archived_at DESC");
$stmt->execute(['user_id' => $adminId]);
$savedNotifications = $stmt->fetchAll();

$pageTitle = 'Saved Notifications - ' . APP_NAME;
include dirname(__DIR__, 3) . '/includes/header.php';
?>

<?php include dirname(__DIR__, 3) . '/includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <h3><i class="fas fa-bookmark"></i> Saved Notifications</h3>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>All Saved Notifications (<?php echo count($savedNotifications); ?>)</span>
            </div>
            <div class="card-body">
                <?php if (empty($savedNotifications)): ?>
                    <p class="text-center">You have no saved notifications.</p>
                    <p class="text-center text-muted"><small>Notifications you view from the bell icon are automatically saved here.</small></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($savedNotifications as $archive): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center" data-archive-id="<?php echo $archive['id']; ?>">
                                <div>
                                    <small class="text-muted">Saved <?php echo Helper::timeAgo($archive['archived_at']); ?></small>
                                    <h5><?php echo e($archive['title']); ?></h5>
                                    <p class="mb-1"><?php echo e($archive['message']); ?></p>
                                    <?php if (!empty($archive['link']) && strpos($archive['link'], 'action:') !== 0): 
                                        $url = (strpos($archive['link'], 'http') === 0) ? $archive['link'] : BASE_URL . $archive['link'];
                                    ?>
                                        <a href="<?php echo e($url); ?>" class="btn btn-sm btn-info">View Original Item</a>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-auto">
                                    <button class="btn btn-sm btn-outline-danger delete-archive-btn" title="Delete Permanently">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const API_URL = '/smns/api/notifications.php';
    const API_MODULE = 'admin';

    function apiUrl(action, extraQuery) {
        const qs = new URLSearchParams();
        qs.set('action', action);
        qs.set('module', API_MODULE);
        if (extraQuery && typeof extraQuery === 'object') {
            Object.keys(extraQuery).forEach(key => {
                const value = extraQuery[key];
                if (value !== undefined && value !== null && value !== '') {
                    qs.set(key, String(value));
                }
            });
        }
        return `${API_URL}?${qs.toString()}`;
    }

    // Delete archived notification
    document.querySelectorAll('.delete-archive-btn').forEach(button => {
        button.addEventListener('click', function() {
            if (!confirm('Are you sure you want to permanently delete this saved notification?')) {
                return;
            }
            const listItem = this.closest('li');
            const archiveId = listItem.dataset.archiveId;
            
            fetch(apiUrl('delete_archive', { id: archiveId }), { method: 'POST', credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        listItem.remove();
                    } else {
                        alert('Failed to delete notification. ' + (data.error || ''));
                    }
                });
        });
    });
});
</script>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
