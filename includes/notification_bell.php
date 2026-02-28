<!-- Notification Bell -->
<?php
$currentBellUserId = 0;
if (!empty($currentUser['id'])) {
    $currentBellUserId = (int)$currentUser['id'];
} else {
    $sessionUserKeys = ['admin_user_id', 'student_user_id', 'lecturer_user_id', 'finance_user_id', 'user_id'];
    foreach ($sessionUserKeys as $key) {
        if (!empty($_SESSION[$key])) {
            $currentBellUserId = (int)$_SESSION[$key];
            break;
        }
    }
}

$initialUnreadCount = 0;
if ($currentBellUserId > 0 && function_exists('getUnreadNotificationCountForUser')) {
    try {
        $initialUnreadCount = (int)getUnreadNotificationCountForUser($currentBellUserId);
    } catch (Exception $e) {
        $initialUnreadCount = 0;
    }
}

if ($initialUnreadCount <= 0 && !empty($unreadNotifications) && is_array($unreadNotifications)) {
    $initialUnreadCount = count($unreadNotifications);
}
?>
<div class="notification-wrapper">
    <button class="notification-bell" id="notificationBell" title="Notifications" data-notification-managed="1">
        <i class="fas fa-bell"></i>
    </button>
    <span
        class="notification-indicator<?php echo $initialUnreadCount > 0 ? ' active' : ''; ?>"
        id="notificationIndicator"
        aria-hidden="true"
    ></span>
    <?php
    // Show change-password quick dropdown for logged-in users in the active module.
    // Role detection must prefer current module context to avoid cross-module endpoint mixups.
    $role = '';
    if (!empty($currentUser['role']) && in_array($currentUser['role'], ['admin', 'student', 'lecturer', 'finance'], true)) {
        $role = (string)$currentUser['role'];
    } else {
        $sessionRoleMap = [
            'SMNS_ADMIN_SESSION' => 'admin',
            'SMNS_STUDENT_SESSION' => 'student',
            'SMNS_LECTURER_SESSION' => 'lecturer',
            'SMNS_FINANCE_SESSION' => 'finance',
        ];
        $activeRole = $sessionRoleMap[session_name()] ?? '';
        if ($activeRole !== '' && !empty($_SESSION[$activeRole . '_logged_in']) && $_SESSION[$activeRole . '_logged_in'] === true) {
            $role = $activeRole;
        }
    }
    if ($role === '') {
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($requestUri, '/views/finance/') !== false) {
            $role = 'finance';
        } elseif (strpos($requestUri, '/views/admin/') !== false) {
            $role = 'admin';
        } elseif (strpos($requestUri, '/views/student/') !== false) {
            $role = 'student';
        } elseif (strpos($requestUri, '/views/lecturer/') !== false) {
            $role = 'lecturer';
        }
    }
    $baseUrlPath = rtrim((string)parse_url(BASE_URL, PHP_URL_PATH), '/');
    $notificationsApiPath = ($baseUrlPath !== '' ? $baseUrlPath : '') . '/api/notifications.php';
    $notificationsStreamPath = ($baseUrlPath !== '' ? $baseUrlPath : '') . '/api/notifications_stream.php';
    if ($role !== ''):
        $csrf = Security::generateCSRFToken();
        if ($role === 'admin') {
            $changePwdEndpoint = BASE_URL . '/views/admin/change-password.php';
        } elseif ($role === 'student') {
            $changePwdEndpoint = BASE_URL . '/views/student/change-password.php';
        } elseif ($role === 'lecturer') {
            $changePwdEndpoint = BASE_URL . '/views/lecturer/change-password.php';
        } elseif ($role === 'finance') {
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
                <form id="<?php echo $formId; ?>" autocomplete="off" data-lpignore="true">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="ajax" value="1">
                    <div class="form-group mb-2">
                        <label class="mb-1" style="font-size:13px;">Current password</label>
                        <input type="password" name="current_password" class="form-control form-control-sm" required autocomplete="off" data-lpignore="true">
                    </div>
                    <div class="form-group mb-2">
                        <label class="mb-1" style="font-size:13px;">New password</label>
                        <input type="password" name="new_password" class="form-control form-control-sm" required autocomplete="off" data-lpignore="true">
                    </div>
                    <div class="form-group mb-2">
                        <label class="mb-1" style="font-size:13px;">Confirm new password</label>
                        <input type="password" name="confirm_password" class="form-control form-control-sm" required autocomplete="off" data-lpignore="true">
                    </div>
                    <div id="<?php echo $msgId; ?>" style="font-size:13px;margin-bottom:6px;padding:6px;border-radius:3px;"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="(function(p){p.style.display='none'; p.querySelectorAll('input[type=password]').forEach(function(i){i.disabled=true;});})(document.getElementById('<?php echo $panelId; ?>'));">Cancel</button>
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
                var passwordInputs = form ? form.querySelectorAll('input[type="password"]') : [];

                function setPasswordInputsDisabled(disabled) {
                    if (!passwordInputs || !passwordInputs.length) return;
                    passwordInputs.forEach(function(input) {
                        input.disabled = !!disabled;
                    });
                }

                function isDarkMode() {
                    return document.documentElement.getAttribute('data-theme') === 'dark';
                }

                function applyToggleBaseStyle() {
                    if (isDarkMode()) {
                        toggle.style.setProperty('background', 'rgba(51,65,85,0.72)', 'important');
                        toggle.style.setProperty('border', '1px solid rgba(100,116,139,0.6)', 'important');
                        toggle.style.setProperty('color', '#e2e8f0', 'important');
                        toggle.style.setProperty('box-shadow', '0 2px 6px rgba(2,6,23,0.45)', 'important');
                    } else {
                        toggle.style.setProperty('background', 'rgba(220,220,220,0.4)', 'important');
                        toggle.style.setProperty('border', '1px solid rgba(200,200,200,0.5)', 'important');
                        toggle.style.setProperty('color', '#fff', 'important');
                        toggle.style.setProperty('box-shadow', '0 2px 4px rgba(0,0,0,0.1)', 'important');
                    }
                    toggle.style.setProperty('transform', 'scale(1)', 'important');
                }

                // Add hover effect
                toggle.addEventListener('mouseenter', function(){
                    if (isDarkMode()) {
                        toggle.style.setProperty('background', 'rgba(71,85,105,0.88)', 'important');
                        toggle.style.setProperty('box-shadow', '0 4px 10px rgba(2,6,23,0.55)', 'important');
                    } else {
                        toggle.style.setProperty('background', 'rgba(235,235,235,0.5)', 'important');
                        toggle.style.setProperty('box-shadow', '0 4px 8px rgba(0,0,0,0.15)', 'important');
                    }
                    toggle.style.setProperty('transform', 'scale(1.08)', 'important');
                });
                toggle.addEventListener('mouseleave', function(){
                    applyToggleBaseStyle();
                });

                applyToggleBaseStyle();
                new MutationObserver(function() {
                    applyToggleBaseStyle();
                }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

                function closePanel(e){
                    if (!panel.contains(e.target) && e.target !== toggle) {
                        panel.style.display = 'none';
                        setPasswordInputsDisabled(true);
                        document.removeEventListener('click', closePanel);
                    }
                }

                toggle.addEventListener('click', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    var isVisible = panel.style.display === 'block';
                    panel.style.display = isVisible ? 'none' : 'block';
                    setPasswordInputsDisabled(isVisible);
                    
                    if (!isVisible) {
                        setPasswordInputsDisabled(false);
                        // Clear previous messages
                        msg.textContent = '';
                        msg.style.backgroundColor = '';
                        msg.style.color = '';
                        // Add click listener after a small delay
                        setTimeout(function(){ 
                            document.addEventListener('click', closePanel); 
                        }, 100);
                    } else {
                        setPasswordInputsDisabled(true);
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
                    
                    // Submit via AJAX to the correct role-specific endpoint
                    fetch('<?php echo $changePwdEndpoint; ?>', {
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
                            // show brief success then close
                            msg.style.backgroundColor = '#e6ffed';
                            msg.style.color = '#087f23';
                            msg.textContent = data.message || 'Password changed';
                            setTimeout(function(){ form.reset(); panel.style.display = 'none'; setPasswordInputsDisabled(true); msg.textContent = ''; }, 900);
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

                // Keep hidden password fields disabled unless user intentionally opens the panel.
                setPasswordInputsDisabled(true);
            })();
        </script>
    <?php endif; ?>
    
    <script>
    (function() {
        function initNotificationBell() {
            const bell = document.getElementById('notificationBell');
            const dropdown = document.getElementById('notificationDropdown');
            if (!bell || !dropdown) {
                return false;
            }
            if (bell.getAttribute('data-notification-init') === '1') {
                return true;
            }
            bell.setAttribute('data-notification-init', '1');

            const markAllBtn = document.getElementById('markAllRead');
            const indicator = document.getElementById('notificationIndicator');
            let previousUnreadCount = <?php echo (int)$initialUnreadCount; ?>;
            const notificationModule = <?php echo json_encode($role !== '' ? $role : null); ?>;
            const resolvedNotificationModule = notificationModule || (function() {
                const p = String(window.location.pathname || '');
                if (p.indexOf('/views/finance/') !== -1) return 'finance';
                if (p.indexOf('/views/admin/') !== -1) return 'admin';
                if (p.indexOf('/views/student/') !== -1) return 'student';
                if (p.indexOf('/views/lecturer/') !== -1) return 'lecturer';
                return null;
            })();

        function notificationApiUrl(action, extraParams) {
            const params = new URLSearchParams();
            params.set('action', action);
            if (resolvedNotificationModule) {
                params.set('module', resolvedNotificationModule);
            }
            if (extraParams && typeof extraParams === 'object') {
                Object.keys(extraParams).forEach(function(key) {
                    if (extraParams[key] !== undefined && extraParams[key] !== null && extraParams[key] !== '') {
                        params.set(key, String(extraParams[key]));
                    }
                });
            }
            return '<?php echo e($notificationsApiPath); ?>?' + params.toString();
        }

        function notificationStreamUrl() {
            const params = new URLSearchParams();
            if (resolvedNotificationModule) {
                params.set('module', resolvedNotificationModule);
            }
            params.set('_ts', String(Date.now()));
            return '<?php echo e($notificationsStreamPath); ?>?' + params.toString();
        }
        
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
            // Force-refresh whenever the bell is opened so the list is always current.
            if (dropdown.classList.contains('show')) {
                refreshNotifications(true);
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        function markAllRead() {
            return fetch(notificationApiUrl('mark_all_read'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    return false;
                }
                dropdown.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
                const markAllLink = dropdown.querySelector('#markAllRead');
                if (markAllLink) {
                    markAllLink.style.display = 'none';
                }
                return true;
            });
        }

        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                markAllRead()
                    .then(function(success) {
                        if (success) {
                            refreshNotifications(true);
                        }
                    })
                    .catch(function(error) {
                        console.log('Error marking all read:', error);
                    });
            });
        }
        
        // Auto-refresh notifications
        let notificationRefreshInterval;
        let notificationEventStream = null;
        let refreshInFlight = false;
        let refreshQueued = false;

        function startPollingFallback() {
            if (notificationRefreshInterval) {
                return;
            }
            notificationRefreshInterval = setInterval(refreshNotifications, 30000);
        }

        function stopPollingFallback() {
            if (!notificationRefreshInterval) {
                return;
            }
            clearInterval(notificationRefreshInterval);
            notificationRefreshInterval = null;
        }

        function initNotificationPushStream() {
            if (typeof window.EventSource !== 'function') {
                startPollingFallback();
                return;
            }

            try {
                notificationEventStream = new EventSource(notificationStreamUrl());
            } catch (err) {
                startPollingFallback();
                return;
            }

            notificationEventStream.addEventListener('open', function() {
                // Stream is alive, so we no longer need timer polling.
                stopPollingFallback();
            });

            notificationEventStream.addEventListener('unread_count', function(evt) {
                let payload = null;
                try {
                    payload = JSON.parse(String(evt.data || '{}'));
                } catch (err) {
                    payload = null;
                }

                if (!payload || typeof payload.count === 'undefined' || payload.count === null) {
                    return;
                }

                const pushedCount = Number(payload.count || 0);
                if (pushedCount !== previousUnreadCount || dropdown.classList.contains('show')) {
                    refreshNotifications(true);
                }
            });

            notificationEventStream.onerror = function() {
                // If stream is fully closed, resume polling as a safety fallback.
                if (notificationEventStream && notificationEventStream.readyState === 2) {
                    startPollingFallback();
                }
            };
        }
        
        function refreshNotifications(forceQueue) {
            if (refreshInFlight) {
                if (forceQueue) {
                    refreshQueued = true;
                }
                return;
            }
            refreshInFlight = true;
            fetch(notificationApiUrl('fetch', { limit: 10, _ts: Date.now() }), { credentials: 'same-origin', cache: 'no-store' })
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
                })
                .finally(() => {
                    refreshInFlight = false;
                    if (refreshQueued) {
                        refreshQueued = false;
                        refreshNotifications(false);
                    }
                });
        }

        function escapeHtml(value) {
            return String(value === undefined || value === null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getSafeNotificationLink(rawLink) {
            const link = String(rawLink === undefined || rawLink === null ? '' : rawLink).trim();
            if (!link || link === '#') {
                return '#';
            }
            if (/^(javascript:|data:|vbscript:)/i.test(link)) {
                return '#';
            }
            let parsed;
            try {
                parsed = new URL(link, window.location.href);
            } catch (err) {
                return '#';
            }
            // Never allow notification click-through to a logout endpoint.
            if (/\/views\/(admin|student|lecturer|finance)\/logout\.php/i.test(parsed.pathname || '')) {
                return '#';
            }
            // Keep navigation same-origin/session-safe by using relative URL.
            return (parsed.pathname || '') + (parsed.search || '') + (parsed.hash || '');
        }

        function resolveActionLabel(actionCode) {
            if (!actionCode) return 'Run';
            const map = {
                'run_backup': 'Run backup',
                'purge_backups': 'Purge backups',
                'purge_logs': 'Purge logs',
                'clear_cache': 'Clear cache',
                'approve_pending_registrations': 'Approve registrations'
            };
            if (map[actionCode]) return map[actionCode];
            if (actionCode.indexOf('resend_email:') === 0) return 'Resend email';
            if (actionCode.indexOf('resend_report:') === 0) return 'Resend report';
            if (actionCode.indexOf('run_cron:') === 0) return 'Run task';
            return 'Run';
        }
        
        function updateNotificationBell(count, notifications) {
            if (!Array.isArray(notifications)) {
                notifications = [];
            }
            const unreadCount = Number(count || 0);
            if (indicator) {
                indicator.classList.toggle('active', unreadCount > 0);
                const hasNewUnread = unreadCount > previousUnreadCount;
                if (hasNewUnread) {
                    indicator.classList.add('pulse');
                } else if (unreadCount <= 0) {
                    indicator.classList.remove('pulse');
                }
            }
            if (bell) {
                bell.setAttribute(
                    'title',
                    unreadCount > 0
                        ? ('Notifications (' + unreadCount + ' new)')
                        : 'Notifications'
                );
            }
            previousUnreadCount = unreadCount;
            
            if (unreadCount > 0) {
                // Update dropdown content
                const list = dropdown.querySelector('.notification-list');
                if (list && notifications.length > 0) {
                    let html = '';
                    notifications.forEach(notif => {
                        const iconMap = { 'info': 'info-circle', 'success': 'check-circle', 'warning': 'exclamation-triangle', 'error': 'times-circle' };
                        const rawNotifType = String(notif.type || '').toLowerCase();
                        const notifType = Object.prototype.hasOwnProperty.call(iconMap, rawNotifType) ? rawNotifType : 'info';
                        const icon = iconMap[notifType] || 'info-circle';
                        const notifId = parseInt(notif.id, 10) || 0;
                        const isArchived = !!notif.is_archived;
                        const safeTitle = escapeHtml(notif.title);
                        const safeMessage = escapeHtml(notif.message);
                        const safeTimeAgo = escapeHtml(notif.time_ago);
                        const safeLink = getSafeNotificationLink(notif.link);

                        if (notif.action) {
                            const aLabel = resolveActionLabel(notif.action);
                            html += `
                                <div class="notification-item unread d-flex" data-id="${notifId}" data-action="${escapeHtml(notif.action)}">
                                    <div class="notif-icon notif-${notifType}">
                                        <i class="fas fa-${icon}"></i>
                                    </div>
                                    <div class="notif-content">
                                        <p class="notif-title">${safeTitle}</p>
                                        <p class="notif-text">${safeMessage}</p>
                                        <span class="notif-time">${safeTimeAgo}</span>
                                    </div>
                                    <div style="margin-left:8px;align-self:center;display:flex;flex-direction:column;gap:6px;">
                                        <button class="btn btn-sm btn-outline-primary notif-action-btn" data-action="${escapeHtml(notif.action)}">${escapeHtml(aLabel)}</button>
                                        <button class="btn btn-sm ${isArchived ? 'btn-success' : 'btn-outline-secondary'} notif-archive-btn" data-id="${notifId}" ${isArchived ? 'disabled' : ''}>${isArchived ? 'Saved' : 'Save'}</button>
                                    </div>
                                </div>
                            `;
                        } else {
                            html += `
                                <div class="notification-item unread d-flex" data-id="${notifId}">
                                    <a href="${safeLink}" class="notif-link" style="flex:1;text-decoration:none;color:inherit;display:flex;">
                                        <div class="notif-icon notif-${notifType}">
                                            <i class="fas fa-${icon}"></i>
                                        </div>
                                        <div class="notif-content">
                                            <p class="notif-title">${safeTitle}</p>
                                            <p class="notif-text">${safeMessage}</p>
                                            <span class="notif-time">${safeTimeAgo}</span>
                                        </div>
                                    </a>
                                    <div style="margin-left:8px;align-self:center;">
                                        <button class="btn btn-sm ${isArchived ? 'btn-success' : 'btn-outline-secondary'} notif-archive-btn" data-id="${notifId}" ${isArchived ? 'disabled' : ''}>${isArchived ? 'Saved' : 'Save'}</button>
                                    </div>
                                </div>
                            `;
                        }
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
                            markAllRead()
                                .then(function(success) {
                                    if (success) {
                                        refreshNotifications(true);
                                    }
                                })
                                .catch(function(error) {
                                    console.log('Error marking all read:', error);
                                });
                        });
                    } else {
                        markAllLink.style.display = 'inline';
                    }
                }
            } else {
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
        
        // Prefer push updates; polling remains fallback-only.
        initNotificationPushStream();
         
        // Initial refresh shortly after page load to correct any stale badge count.
        setTimeout(refreshNotifications, 1500);
        
        // Clear interval when page unloads
        window.addEventListener('beforeunload', function() {
            if (notificationRefreshInterval) {
                clearInterval(notificationRefreshInterval);
            }
            if (notificationEventStream) {
                notificationEventStream.close();
                notificationEventStream = null;
            }
        });
        
        // Function to mark individual notification as read
        window.markNotificationRead = function(notifId) {
            if (!notifId) {
                return Promise.resolve(false);
            }

            return fetch(notificationApiUrl('mark_read', { id: notifId }), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                keepalive: true
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Mark the item as read in the dropdown
                    const item = dropdown.querySelector(`[data-id="${notifId}"]`);
                    if (item) {
                        item.classList.remove('unread');

                        // UI: reflect that the notification was auto-saved (archive)
                        const saveBtn = item.querySelector('.notif-archive-btn');
                        if (saveBtn) {
                            saveBtn.disabled = true;
                            saveBtn.classList.remove('btn-outline-secondary');
                            saveBtn.classList.add('btn-success');
                            saveBtn.textContent = 'Saved';
                        }
                    }
                    return true;
                }
                return false;
            })
            .catch(function() {
                return false;
            })
            .finally(function() {
                refreshNotifications(true);
            });
        };
        
        // Handle notification-item clicks (mark read)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.notif-action-btn');
            if (btn) {
                // action button clicked
                e.preventDefault();
                const op = btn.getAttribute('data-action');
                if (!op) return;
                btn.disabled = true;
                btn.textContent = 'Running...';

                fetch(notificationApiUrl('execute'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'op=' + encodeURIComponent(op) + '&module=' + encodeURIComponent(resolvedNotificationModule || '')
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        btn.classList.remove('btn-outline-primary');
                        btn.classList.add('btn-success');
                        btn.textContent = 'Done';
                        // optionally mark the notification read
                        const parent = btn.closest('.notification-item');
                        if (parent && parent.getAttribute('data-id')) {
                            markNotificationRead(parent.getAttribute('data-id'));
                        }
                    } else {
                        btn.classList.remove('btn-outline-primary');
                        btn.classList.add('btn-danger');
                        btn.textContent = 'Failed';
                        setTimeout(function(){
                            btn.disabled = false;
                            btn.classList.remove('btn-danger');
                            btn.classList.add('btn-outline-primary');
                            btn.textContent = resolveActionLabel(op);
                        }, 3000);
                    }
                })
                .catch(err => {
                    console.error('Action error', err);
                    btn.disabled = false; btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-danger'); btn.textContent = 'Error';
                });

                return;
            }

            // Archive (Save) button
            const abtn = e.target.closest('.notif-archive-btn');
            if (abtn) {
                e.preventDefault();
                const nid = abtn.getAttribute('data-id');
                if (!nid) return;
                abtn.disabled = true;
                abtn.textContent = 'Saving...';

                fetch(notificationApiUrl('archive', { id: nid }), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + encodeURIComponent(nid) + '&module=' + encodeURIComponent(resolvedNotificationModule || '')
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        abtn.classList.remove('btn-outline-secondary');
                        abtn.classList.add('btn-success');
                        abtn.textContent = 'Saved';
                        refreshNotifications(true);
                    } else {
                        abtn.disabled = false;
                        abtn.classList.remove('btn-outline-secondary');
                        abtn.classList.add('btn-danger');
                        abtn.textContent = 'Error';
                        setTimeout(function(){ abtn.classList.remove('btn-danger'); abtn.classList.add('btn-outline-secondary'); abtn.textContent = 'Save'; }, 2500);
                    }
                })
                .catch(err => {
                    console.error('Archive error', err);
                    abtn.disabled = false; abtn.classList.remove('btn-outline-secondary'); abtn.classList.add('btn-danger'); abtn.textContent = 'Error';
                    setTimeout(function(){ abtn.classList.remove('btn-danger'); abtn.classList.add('btn-outline-secondary'); abtn.textContent = 'Save'; }, 2500);
                });

                return;
            }

            const item = e.target.closest('.notification-item');
            if (item && item.classList.contains('unread')) {
                const link = e.target.closest('a[href]');
                const notifId = item.getAttribute('data-id');
                if (notifId) {
                    const href = link ? String(link.getAttribute('href') || '').trim() : '';
                    const unsafeOrLogoutLink = (!href || href === '#' || /^(javascript:|data:|vbscript:)/i.test(href) || /\/views\/(admin|student|lecturer|finance)\/logout\.php/i.test(href));
                    if (link && !unsafeOrLogoutLink) {
                        e.preventDefault();
                        const targetUrl = href;
                        markNotificationRead(notifId)
                            .catch(function(){})
                            .finally(function() {
                                window.location.href = targetUrl;
                            });
                    } else {
                        markNotificationRead(notifId);
                    }
                }
            }
        });
            return true;
        }

        if (!initNotificationBell()) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initNotificationBell, { once: true });
            } else {
                window.setTimeout(initNotificationBell, 0);
            }
        }
    })();
    </script>
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <h6>Notifications</h6>
            <?php if ($initialUnreadCount > 0): ?>
                <a href="#" id="markAllRead">Mark all read</a>
            <?php endif; ?>
        </div>
        <div class="notification-list">
            <?php if (!empty($unreadNotifications)): ?>
                <?php foreach ($unreadNotifications as $notif): ?>
                    <?php if (!empty($notif['link']) && strpos($notif['link'], 'action:') === 0):
                            $actionCode = substr($notif['link'], strlen('action:'));
                            $actionLabel = 'Run';
                            if ($actionCode === 'run_backup') {
                                $actionLabel = 'Run backup';
                            } elseif ($actionCode === 'purge_backups') {
                                $actionLabel = 'Purge backups';
                            } elseif ($actionCode === 'purge_logs') {
                                $actionLabel = 'Purge logs';
                            } elseif ($actionCode === 'clear_cache') {
                                $actionLabel = 'Clear cache';
                            } elseif (strpos($actionCode, 'resend_email:') === 0) {
                                $actionLabel = 'Resend email';
                            } elseif (strpos($actionCode, 'resend_report:') === 0) {
                                $actionLabel = 'Resend report';
                            } elseif (strpos($actionCode, 'run_cron:') === 0) {
                                $actionLabel = 'Run task';
                            }
                        ?>
                        <div class="notification-item unread d-flex" data-id="<?php echo $notif['id']; ?>">
                            <div class="notif-icon notif-<?php echo e($notif['type']); ?>">
                                <?php $icon = ($notif['type'] == 'success') ? 'check-circle' : (($notif['type']=='error') ? 'times-circle' : 'info-circle'); ?>
                                <i class="fas fa-<?php echo $icon; ?>"></i>
                            </div>
                            <div class="notif-content" style="flex:1;">
                                <p class="notif-title"><?php echo e($notif['title']); ?></p>
                                <p class="notif-text"><?php echo e($notif['message']); ?></p>
                                <span class="notif-time"><?php echo Helper::timeAgo($notif['created_at']); ?></span>
                            </div>
                            <div style="margin-left:8px;align-self:center;display:flex;flex-direction:column;gap:6px;">
                                <button class="btn btn-sm btn-outline-primary notif-action-btn" data-action="<?php echo e($actionCode); ?>"><?php echo e($actionLabel); ?></button>
                                <button class="btn btn-sm <?php echo !empty($notif['my_archive_id']) ? 'btn-success' : 'btn-outline-secondary'; ?> notif-archive-btn" data-id="<?php echo $notif['id']; ?>" <?php echo !empty($notif['my_archive_id']) ? 'disabled' : ''; ?>>
                                    <?php echo !empty($notif['my_archive_id']) ? 'Saved' : 'Save'; ?>
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php
                            $rawLink = trim((string)($notif['link'] ?? ''));
                            $safeLink = '#';
                            if (
                                $rawLink !== '' &&
                                stripos($rawLink, 'javascript:') !== 0 &&
                                stripos($rawLink, 'data:') !== 0 &&
                                stripos($rawLink, 'vbscript:') !== 0 &&
                                !preg_match('#/views/(admin|student|lecturer|finance)/logout\.php#i', $rawLink)
                            ) {
                                $parsedLink = @parse_url($rawLink);
                                if (is_array($parsedLink) && !empty($parsedLink['path'])) {
                                    $safeLink = (string)$parsedLink['path'];
                                    if (isset($parsedLink['query']) && $parsedLink['query'] !== '') {
                                        $safeLink .= '?' . $parsedLink['query'];
                                    }
                                    if (isset($parsedLink['fragment']) && $parsedLink['fragment'] !== '') {
                                        $safeLink .= '#' . $parsedLink['fragment'];
                                    }
                                } else {
                                    $safeLink = $rawLink;
                                }
                            }
                        ?>
                        <div class="notification-item unread d-flex" data-id="<?php echo $notif['id']; ?>">
                            <a href="<?php echo e($safeLink); ?>" class="notif-link" style="flex:1;text-decoration:none;color:inherit;display:flex;">
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
                            <div style="margin-left:8px;align-self:center;">
                                <button class="btn btn-sm <?php echo !empty($notif['my_archive_id']) ? 'btn-success' : 'btn-outline-secondary'; ?> notif-archive-btn" data-id="<?php echo $notif['id']; ?>" <?php echo !empty($notif['my_archive_id']) ? 'disabled' : ''; ?>>
                                    <?php echo !empty($notif['my_archive_id']) ? 'Saved' : 'Save'; ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
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
