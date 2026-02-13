<?php

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/smns'); // Change this to your base URL
}

// ============================================================================
// HANDLE API REQUESTS (Must be at the top before any output)
// ============================================================================
if (isset($_GET['notification_action'])) {
    handleNotificationAPI();
    exit;
}

// ============================================================================
// DATABASE SETUP & NOTIFICATION FETCHING
// ============================================================================
function setupNotificationsTable() {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $conn->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_type VARCHAR(20) NOT NULL DEFAULT 'admin',
                type VARCHAR(50) NOT NULL DEFAULT 'info',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                link VARCHAR(500) NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMP NULL,
                INDEX idx_user (user_id, user_type),
                INDEX idx_read (is_read),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        return true;
    } catch (Exception $e) {
        error_log("Error setting up notifications table: " . $e->getMessage());
        return false;
    }
}

function fetchAdminNotifications($adminId = null) {
    try {
        // Setup table if it doesn't exist
        setupNotificationsTable();
        
        if ($adminId === null) {
            $adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
        }
        
        if ($adminId <= 0) {
            return [];
        }
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            AND user_type = 'admin' 
            AND is_read = 0 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute(['user_id' => $adminId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// NOTIFICATION CREATION FUNCTIONS
// ============================================================================

/**
 * Create notification for specific admin
 */
function createAdminNotification($adminId, $type, $title, $message, $link = null) {
    try {
        setupNotificationsTable();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, user_type, type, title, message, link, created_at) 
            VALUES (:user_id, 'admin', :type, :title, :message, :link, NOW())
        ");
        
        return $stmt->execute([
            'user_id' => $adminId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link
        ]);
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all active admins
 */
function notifyAllAdmins($type, $title, $message, $link = null) {
    try {
        setupNotificationsTable();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Get all active admin IDs
        $stmt = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($admins)) {
            return false;
        }
        
        $insertStmt = $conn->prepare("
            INSERT INTO notifications (user_id, user_type, type, title, message, link, created_at) 
            VALUES (:user_id, 'admin', :type, :title, :message, :link, NOW())
        ");
        
        foreach ($admins as $adminId) {
            $insertStmt->execute([
                'user_id' => $adminId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error notifying all admins: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// API HANDLER
// ============================================================================
function handleNotificationAPI() {
    header('Content-Type: application/json');
    
    // Check authentication
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $action = $_GET['notification_action'] ?? '';
    $adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        switch ($action) {
            case 'mark_read':
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                $notifId = intval($data['notification_id'] ?? 0);
                
                if ($notifId > 0) {
                    $stmt = $conn->prepare("
                        UPDATE notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE id = :id AND user_id = :user_id AND user_type = 'admin'
                    ");
                    $stmt->execute(['id' => $notifId, 'user_id' => $adminId]);
                    
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                }
                break;
                
            case 'mark_all_read':
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE user_id = :user_id AND user_type = 'admin' AND is_read = 0
                ");
                $stmt->execute(['user_id' => $adminId]);
                
                echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("Notification API error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
}

// ============================================================================
// RENDER NOTIFICATION BELL
// ============================================================================
function renderAdminNotificationBell() {
    $notifications = fetchAdminNotifications();
    $count = count($notifications);
    $currentFile = basename($_SERVER['PHP_SELF']);
    
    ob_start();
    ?>
    
    <!-- Admin Notification Bell -->
    <div class="notification-wrapper">
        <button class="notification-bell" id="adminNotificationBell" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php if ($count > 0): ?>
                <span class="notification-badge"><?php echo $count; ?></span>
            <?php endif; ?>
        </button>
        
        <div class="notification-dropdown" id="adminNotificationDropdown">
            <div class="notification-header">
                <h6>Notifications</h6>
                <?php if ($count > 0): ?>
                    <a href="#" id="adminMarkAllRead">Mark all read</a>
                <?php endif; ?>
            </div>
            <div class="notification-list">
                <?php if ($count > 0): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>" 
                           class="notification-item unread" 
                           data-id="<?php echo $notif['id']; ?>"
                           onclick="markNotificationRead(<?php echo $notif['id']; ?>)">
                            <div class="notif-icon notif-<?php echo htmlspecialchars($notif['type']); ?>">
                                <?php
                                $iconMap = [
                                    'info' => 'info-circle',
                                    'success' => 'check-circle',
                                    'warning' => 'exclamation-triangle',
                                    'error' => 'times-circle',
                                    'student' => 'user-graduate',
                                    'finance' => 'dollar-sign',
                                    'system' => 'cog'
                                ];
                                $icon = $iconMap[$notif['type']] ?? 'bell';
                                ?>
                                <i class="fas fa-<?php echo $icon; ?>"></i>
                            </div>
                            <div class="notif-content">
                                <p class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                <p class="notif-text"><?php echo htmlspecialchars($notif['message']); ?></p>
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

    <style>
    .notification-wrapper {
        position: relative;
        display: inline-block;
        margin-left: 15px;
    }
    .notification-bell {
        background: transparent;
        border: none;
        color: #fff;
        font-size: 20px;
        cursor: pointer;
        position: relative;
        padding: 8px 12px;
        transition: all 0.3s;
    }
    .notification-bell:hover {
        transform: scale(1.1);
        color: #ffc107;
    }
    .notification-badge {
        position: absolute;
        top: 2px;
        right: 2px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        padding: 2px 6px;
        font-size: 10px;
        font-weight: bold;
        min-width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }
    .notification-dropdown {
        position: absolute;
        top: 45px;
        right: 0;
        width: 360px;
        max-height: 500px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        display: none;
        z-index: 1000;
        overflow: hidden;
    }
    .notification-dropdown.show {
        display: block;
        animation: slideDown 0.3s ease;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .notification-header {
        padding: 15px;
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .notification-header h6 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }
    .notification-header a {
        font-size: 13px;
        color: #007bff;
        text-decoration: none;
    }
    .notification-header a:hover {
        text-decoration: underline;
    }
    .notification-list {
        max-height: 400px;
        overflow-y: auto;
    }
    .notification-item {
        display: flex;
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        text-decoration: none;
        color: inherit;
        transition: background 0.2s;
    }
    .notification-item:hover {
        background: #f8f9fa;
    }
    .notification-item.unread {
        background: #e7f3ff;
    }
    .notification-item.unread:hover {
        background: #d1e7ff;
    }
    .notif-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        margin-right: 12px;
        flex-shrink: 0;
    }
    .notif-icon.notif-info { background: #e7f3ff; color: #007bff; }
    .notif-icon.notif-success { background: #d4edda; color: #28a745; }
    .notif-icon.notif-warning { background: #fff3cd; color: #ffc107; }
    .notif-icon.notif-error { background: #f8d7da; color: #dc3545; }
    .notif-icon.notif-student { background: #e7f3ff; color: #17a2b8; }
    .notif-icon.notif-finance { background: #d4edda; color: #28a745; }
    .notif-icon.notif-system { background: #e2e3e5; color: #6c757d; }
    .notif-content {
        flex: 1;
    }
    .notif-title {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 600;
        color: #333;
    }
    .notif-text {
        margin: 0 0 4px 0;
        font-size: 13px;
        color: #666;
        line-height: 1.4;
    }
    .notif-time {
        font-size: 11px;
        color: #999;
    }
    .notification-empty {
        text-align: center;
        padding: 40px 20px;
        color: #999;
    }
    .notification-empty i {
        font-size: 48px;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    .notification-empty p {
        margin: 0;
        font-size: 14px;
    }
    </style>

    <script>
    (function() {
        const bell = document.getElementById('adminNotificationBell');
        const dropdown = document.getElementById('adminNotificationDropdown');
        const markAllBtn = document.getElementById('adminMarkAllRead');
        
        if (!bell || !dropdown) return;
        
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== bell) {
                dropdown.classList.remove('show');
            }
        });
        
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>?notification_action=mark_all_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                        const badge = bell.querySelector('.notification-badge');
                        if (badge) badge.remove();
                        markAllBtn.style.display = 'none';
                    }
                });
            });
        }
    })();

    function markNotificationRead(notifId) {
        fetch('<?php echo $_SERVER['PHP_SELF']; ?>?notification_action=mark_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ notification_id: notifId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    const count = parseInt(badge.textContent) - 1;
                    if (count <= 0) badge.remove();
                    else badge.textContent = count;
                }
            }
        });
    }
    </script>
    
    <?php
    return ob_get_clean();
}

// ============================================================================
// USAGE EXAMPLES (Remove these in production)
// ============================================================================

/*
// EXAMPLE 1: Display the bell in your admin header
echo renderAdminNotificationBell();

// EXAMPLE 2: Create notification for specific admin
createAdminNotification(
    1, // admin ID
    'success', // type: info, success, warning, error, student, finance, system
    'New Student Registered',
    'John Doe has completed registration',
    '/views/admin/students/view.php?id=123'
);

// EXAMPLE 3: Notify all admins
notifyAllAdmins(
    'warning',
    'Low Balance Alert',
    'Student Jane Smith has a balance of UGX 50,000',
    '/views/admin/finance/students.php'
);

// EXAMPLE 4: In your student registration file
$conn->commit();
notifyAllAdmins(
    'student',
    'New Student Added',
    "$first_name $last_name (ID: $studentCode) has been added",
    BASE_URL . "/views/admin/students/view.php?id=$newStudentId"
);

// EXAMPLE 5: On payment received
$conn->commit();
notifyAllAdmins(
    'finance',
    'Payment Received',
    "$studentName paid UGX " . number_format($amount),
    BASE_URL . "/views/admin/finance/transaction.php?id=$transactionId"
);
*/

?>