<?php
/**
 * Admin - Notification Archive (Saved notifications)
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();

// Handle deletion (remove saved/archived item)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token');
        header('Location: archive.php'); exit;
    }
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM notification_archive WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $currentUser['id']]);
        $session->setFlash('success', 'Saved notification removed');
        header('Location: archive.php'); exit;
    }
}

// Pagination params
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$totalStmt = $conn->prepare('SELECT COUNT(*) FROM notification_archive WHERE user_id = :uid');
$totalStmt->execute(['uid' => $currentUser['id']]);
$total = (int)$totalStmt->fetchColumn();

$stmt = $conn->prepare('SELECT * FROM notification_archive WHERE user_id = :uid ORDER BY archived_at DESC LIMIT :limit OFFSET :offset');
$stmt->bindValue(':uid', $currentUser['id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Saved Notifications - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Saved Notifications</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../../includes/notification_bell.php'; ?>
            <div class="user-info"></div>
        </div>
    </div>

    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <p class="text-muted">Saved notifications are personal to your account. Use this page to review or remove saved items.</p>
                <?php if (empty($rows)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bookmark"></i>
                        <h4>No saved notifications</h4>
                        <p>Use the notification bell to save important messages for later.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Message</th>
                                    <th>Link</th>
                                    <th>Saved</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo e($r['title']); ?></td>
                                        <td style="max-width:480px;white-space:normal;"><?php echo e(mb_substr(strip_tags($r['message']),0,200)); ?></td>
                                        <td><?php if (!empty($r['link'])): ?><a href="<?php echo e($r['link']); ?>" target="_blank">Open</a><?php else: ?>—<?php endif; ?></td>
                                        <td><?php echo e(date('Y-m-d H:i', strtotime($r['archived_at']))); ?></td>
                                        <td style="width:110px;">
                                            <form method="post" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
                                                <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php // simple pager ?>
                    <?php $totalPages = max(1, ceil($total / $perPage)); ?>
                    <nav aria-label="Saved notifications pages">
                        <ul class="pagination pagination-sm">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a></li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

                <div class="mt-3">
                    <a href="../dashboard.php" class="btn btn-sm btn-outline-secondary">Back to dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
