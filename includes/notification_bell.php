<!-- Notification Bell -->
<div class="notification-wrapper">
    <button class="notification-bell" id="notificationBell" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if (!empty($unreadNotifications)): ?>
            <span class="notification-badge"><?php echo count($unreadNotifications); ?></span>
        <?php endif; ?>
    </button>
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <h6>Notifications</h6>
            <?php if (!empty($unreadNotifications)): ?>
                <a href="#" id="markAllRead">Mark all read</a>
            <?php endif; ?>
        </div>
        <div class="notification-list">
            <?php if (!empty($unreadNotifications)): ?>
                <?php foreach ($unreadNotifications as $notif): ?>
                    <a href="<?php echo e($notif['link'] ?? '#'); ?>" class="notification-item unread" data-id="<?php echo $notif['id']; ?>">
                        <div class="notif-icon notif-<?php echo e($notif['type']); ?>">
                            <?php
                            $iconMap = [
                                'info' => 'info-circle',
                                'success' => 'check-circle',
                                'warning' => 'exclamation-triangle',
                                'error' => 'times-circle'
                            ];
                            $icon = $iconMap[$notif['type']] ?? 'info-circle';
                            ?>
                            <i class="fas fa-<?php echo $icon; ?>"></i>
                        </div>
                        <div class="notif-content">
                            <p class="notif-title"><?php echo e($notif['title']); ?></p>
                            <p class="notif-text"><?php echo e($notif['message']); ?></p>
                            <span class="notif-time"><?php echo Helper::timeAgo($notif['created_at']); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    <p>No new notifications</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
