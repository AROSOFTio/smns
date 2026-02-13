<!-- Notification Bell -->
<div class="notification-wrapper">
    <button class="notification-bell" id="notificationBell" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if (!empty($unreadNotifications)): ?>
            <span class="notification-badge"><?php echo count($unreadNotifications); ?></span>
        <?php endif; ?>
    </button>
    <?php
    // Show change-password quick dropdown for logged-in students, admins, lecturers, or finance next to the bell
    $isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    $isStudent = isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true;
    $isLecturer = isset($_SESSION['lecturer_logged_in']) && $_SESSION['lecturer_logged_in'] === true;
    $isFinance = isset($_SESSION['finance_logged_in']) && $_SESSION['finance_logged_in'] === true;
    if ($isAdmin || $isStudent || $isLecturer || $isFinance):
        $csrf = Security::generateCSRFToken();
        $role = $isAdmin ? 'admin' : ($isStudent ? 'student' : ($isLecturer ? 'lecturer' : ($isFinance ? 'finance' : '')));
        if ($isAdmin) {
            $changePwdEndpoint = BASE_URL . '/views/admin/change-password.php';
        } elseif ($isStudent) {
            $changePwdEndpoint = BASE_URL . '/views/student/change-password.php';
        } elseif ($isLecturer) {
            $changePwdEndpoint = BASE_URL . '/views/lecturer/change-password.php';
        } elseif ($isFinance) {
            $changePwdEndpoint = BASE_URL . '/views/finance/change-password.php';
        } else {
            $changePwdEndpoint = '';
        }
        $toggleId = 'changePasswordToggle_' . $role;
        $panelId = 'changePasswordPanel_' . $role;
        $formId = 'headerChangePasswordForm_' . $role;
        $msgId = 'headerChangePwdMsg_' . $role;
        ?>
        <div class="change-password-wrapper" style="display:inline-block;position:relative;margin-left:12px;">
            <button id="<?php echo $toggleId; ?>" class="change-password-btn" title="Change Password" aria-label="Change Password" style="background:rgba(220,220,220,0.4) !important;color:#fff !important;border:1px solid rgba(200,200,200,0.5) !important;width:36px !important;height:36px !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;border-radius:50% !important;font-size:14px !important;padding:0 !important;transition:all 0.3s !important;box-shadow:0 2px 4px rgba(0,0,0,0.1) !important;">
                <i class="fas fa-key" style="color:#fff !important;font-size:14px !important;"></i>
            </button>
            <div id="<?php echo $panelId; ?>" class="change-password-panel" style="display:none;position:absolute;right:0;top:40px;z-index:1200;width:320px;background:#fff;color:#333;border:1px solid #ddd;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.08);overflow:auto;max-height:360px;padding:12px;">
                <h6 class="mb-3" style="font-size:14px;font-weight:600;">Change Password</h6>
                <form id="<?php echo $formId; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="ajax" value="1">
                    <div class="form-group mb-2">
                        <label class="mb-1" style="font-size:13px;">Current password</label>
                        <input type="password" name="current_password" class="form-control form-control-sm" required autocomplete="current-password">
                    </div>
                    <div class="form-group mb-2">
                        <label class="mb-1" style="font-size:13px;">New password</label>
                        <input type="password" name="new_password" class="form-control form-control-sm" required autocomplete="new-password">
                    </div>
                    <div class="form-group mb-2">
                        <label class="mb-1" style="font-size:13px;">Confirm new password</label>
                        <input type="password" name="confirm_password" class="form-control form-control-sm" required autocomplete="new-password">
                    </div>
                    <div id="<?php echo $msgId; ?>" style="font-size:13px;margin-bottom:6px;padding:6px;border-radius:3px;"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('<?php echo $panelId; ?>').style.display='none'">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
            (function(){
                var toggle = document.getElementById('<?php echo $toggleId; ?>');
                var panel = document.getElementById('<?php echo $panelId; ?>');
                var form = document.getElementById('<?php echo $formId; ?>');
                var msg = document.getElementById('<?php echo $msgId; ?>');

                // Add hover effect
                toggle.addEventListener('mouseenter', function(){
                    toggle.style.setProperty('background', 'rgba(235,235,235,0.5)', 'important');
                    toggle.style.setProperty('transform', 'scale(1.1)', 'important');
                    toggle.style.setProperty('box-shadow', '0 4px 8px rgba(0,0,0,0.15)', 'important');
                });
                toggle.addEventListener('mouseleave', function(){
                    toggle.style.setProperty('background', 'rgba(220,220,220,0.4)', 'important');
                    toggle.style.setProperty('transform', 'scale(1)', 'important');
                    toggle.style.setProperty('box-shadow', '0 2px 4px rgba(0,0,0,0.1)', 'important');
                });

                function closePanel(e){
                    if (!panel.contains(e.target) && e.target !== toggle) {
                        panel.style.display = 'none';
                        document.removeEventListener('click', closePanel);
                    }
                }

                toggle.addEventListener('click', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    var isVisible = panel.style.display === 'block';
                    panel.style.display = isVisible ? 'none' : 'block';
                    
                    if (!isVisible) {
                        // Clear previous messages
                        msg.textContent = '';
                        msg.style.backgroundColor = '';
                        msg.style.color = '';
                        // Add click listener after a small delay
                        setTimeout(function(){ 
                            document.addEventListener('click', closePanel); 
                        }, 100);
                    } else {
                        document.removeEventListener('click', closePanel);
                    }
                });

                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    
                    // Clear previous messages
                    msg.textContent = '';
                    msg.style.backgroundColor = '';
                    msg.style.color = '';
                    
                    // Get form data
                    var formData = new FormData(form);
                    
                    // Validate passwords match
                    var newPassword = formData.get('new_password');
                    var confirmPassword = formData.get('confirm_password');
                    
                    if (newPassword !== confirmPassword) {
                        msg.style.backgroundColor = '#fee';
                        msg.style.color = '#c00';
                        msg.textContent = 'New passwords do not match';
                        return;
                    }
                    
                    if (newPassword.length < 6) {
                        msg.style.backgroundColor = '#fee';
                        msg.style.color = '#c00';
                        msg.textContent = 'Password must be at least 6 characters';
                        return;
                    }
                    
                    // Show loading
                    msg.style.backgroundColor = '#e7f3ff';
                    msg.style.color = '#0066cc';
                    msg.textContent = 'Changing password...';
                    
                    // Submit via AJAX
                    fetch('<?php echo "' + $changePwdEndpoint + '"; ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(function(response){
                        return response.json().catch(function(){
                            throw new Error('Invalid response from server');
                        });
                    })
                    .then(function(data){
                        if (data.success) {
                            form.reset();
                            panel.style.display = 'none';
                        } else {
                            msg.style.backgroundColor = '#fee';
                            msg.style.color = '#c00';
                            msg.textContent = data.error || data.message || 'Failed to change password';
                        }
                    })
                    .catch(function(error){
                        console.error('Change password error:', error);
                        msg.style.backgroundColor = '#fee';
                        msg.style.color = '#c00';
                        msg.textContent = 'Network error. Please try again.';
                    });
                });
            })();
        </script>
    <?php endif; ?>
    
    <script>
    (function() {
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');
        const markAllBtn = document.getElementById('markAllRead');
        
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
                
                fetch('<?php echo BASE_URL; ?>/api/notifications.php?action=mark_all_read', {
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
        
        // Auto-refresh notifications
        let notificationRefreshInterval;
        
        function refreshNotifications() {
            fetch('<?php echo BASE_URL; ?>/api/notifications.php?action=fetch&limit=10')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        updateNotificationBell(data.count, data.notifications);
                    }
                })
                .catch(error => {
                    console.log('Error refreshing notifications:', error);
                });
        }
        
        function updateNotificationBell(count, notifications) {
            const badge = bell.querySelector('.notification-badge');
            
            if (count > 0) {
                if (!badge) {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'notification-badge';
                    newBadge.textContent = count;
                    bell.appendChild(newBadge);
                } else {
                    badge.textContent = count;
                }
                
                // Update dropdown content
                const list = dropdown.querySelector('.notification-list');
                if (list && notifications.length > 0) {
                    let html = '';
                    notifications.forEach(notif => {
                        const iconMap = {
                            'info': 'info-circle',
                            'success': 'check-circle',
                            'warning': 'exclamation-triangle',
                            'error': 'times-circle'
                        };
                        const icon = iconMap[notif.type] || 'info-circle';
                        
                        html += `
                            <a href="${notif.link || '#'}" class="notification-item unread" data-id="${notif.id}">
                                <div class="notif-icon notif-${notif.type}">
                                    <i class="fas fa-${icon}"></i>
                                </div>
                                <div class="notif-content">
                                    <p class="notif-title">${notif.title.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>
                                    <p class="notif-text">${notif.message.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>
                                    <span class="notif-time">${notif.time_ago}</span>
                                </div>
                            </a>
                        `;
                    });
                    list.innerHTML = html;
                    
                    // Show mark all read link
                    const header = dropdown.querySelector('.notification-header');
                    const markAllLink = header.querySelector('#markAllRead');
                    if (!markAllLink) {
                        const newLink = document.createElement('a');
                        newLink.href = '#';
                        newLink.id = 'markAllRead';
                        newLink.textContent = 'Mark all read';
                        header.appendChild(newLink);
                        
                        // Re-attach event listener
                        newLink.addEventListener('click', function(e) {
                            e.preventDefault();
                            fetch('<?php echo BASE_URL; ?>/api/notifications.php?action=mark_all_read', {
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
                                    newLink.style.display = 'none';
                                }
                            });
                        });
                    } else {
                        markAllLink.style.display = 'inline';
                    }
                }
            } else {
                if (badge) badge.remove();
                
                // Update dropdown to show empty state
                const list = dropdown.querySelector('.notification-list');
                if (list) {
                    list.innerHTML = `
                        <div class="notification-empty">
                            <i class="fas fa-bell-slash"></i>
                            <p>No new notifications</p>
                        </div>
                    `;
                }
                
                // Hide mark all read link
                const markAllLink = dropdown.querySelector('#markAllRead');
                if (markAllLink) markAllLink.style.display = 'none';
            }
        }
        
        // Auto-refresh every 30 seconds
        notificationRefreshInterval = setInterval(refreshNotifications, 30000);
        
        // Initial refresh after 10 seconds (to avoid immediate load)
        setTimeout(refreshNotifications, 10000);
        
        // Clear interval when page unloads
        window.addEventListener('beforeunload', function() {
            if (notificationRefreshInterval) {
                clearInterval(notificationRefreshInterval);
            }
        });
        
        // Function to mark individual notification as read
        window.markNotificationRead = function(notifId) {
            fetch('<?php echo BASE_URL; ?>/api/notifications.php?action=mark_read&id=' + notifId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const badge = bell.querySelector('.notification-badge');
                    if (badge) {
                        const count = parseInt(badge.textContent) - 1;
                        if (count <= 0) badge.remove();
                        else badge.textContent = count;
                    }
                    
                    // Mark the item as read in the dropdown
                    const item = dropdown.querySelector(`[data-id="${notifId}"]`);
                    if (item) {
                        item.classList.remove('unread');
                    }
                }
            });
        };
        
        // Add click listeners to notification items
        document.addEventListener('click', function(e) {
            const item = e.target.closest('.notification-item');
            if (item && item.classList.contains('unread')) {
                const notifId = item.getAttribute('data-id');
                if (notifId) {
                    markNotificationRead(notifId);
                }
            }
        });
    })();
    </script>
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