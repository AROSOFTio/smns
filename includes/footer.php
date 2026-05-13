<?php
/**
 * Common Footer
 */
?>
    </div><!-- .wrapper -->
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js?v=<?php echo urlencode((string)APP_VERSION); ?>" defer></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/navigation.js?v=<?php echo urlencode((string)APP_VERSION); ?>" defer></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?php echo urlencode((string)APP_VERSION); ?>" defer></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach($additionalJS as $js): ?>
            <script src="<?php echo BASE_URL . '/assets/js/' . $js . '?v=' . urlencode((string)APP_VERSION); ?>" defer></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
        $autoLogoutModule = '';
        $autoLogoutToken = '';
        $requestPath = $_SERVER['REQUEST_URI'] ?? '';
        $moduleFromPath = '';
        if (strpos($requestPath, '/views/admin/') !== false) {
            $moduleFromPath = 'admin';
        } elseif (strpos($requestPath, '/admin/') !== false) {
            // Legacy admin tools live under /admin/*.php (outside /views/admin).
            // Treat them as admin so the shared topbar (time + user dropdown/logout)
            // and auto-logout module tracking remain consistent.
            $moduleFromPath = 'admin';
        } elseif (strpos($requestPath, '/views/student/') !== false) {
            $moduleFromPath = 'student';
        } elseif (strpos($requestPath, '/views/lecturer/') !== false) {
            $moduleFromPath = 'lecturer';
        } elseif (strpos($requestPath, '/views/finance/') !== false) {
            $moduleFromPath = 'finance';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($moduleFromPath !== '' && !empty($_SESSION[$moduleFromPath . '_logged_in']) && (($_SESSION[$moduleFromPath . '_role'] ?? '') === $moduleFromPath)) {
                $autoLogoutModule = $moduleFromPath;
                $autoLogoutToken = (string)($_SESSION[$moduleFromPath . '_session_token'] ?? '');
            } else {
                foreach (['admin', 'student', 'lecturer', 'finance'] as $role) {
                    if (!empty($_SESSION[$role . '_logged_in']) && (($_SESSION[$role . '_role'] ?? '') === $role)) {
                        $autoLogoutModule = $role;
                        $autoLogoutToken = (string)($_SESSION[$role . '_session_token'] ?? '');
                        break;
                    }
                }
            }
        }

        $topbarModule = $moduleFromPath;
        $topbarCurrentUser = null;
        if ($topbarModule !== '' && class_exists('Auth') && session_status() === PHP_SESSION_ACTIVE) {
            try {
                $topbarAuth = new Auth($topbarModule);
                if ($topbarAuth->isLoggedIn()) {
                    $topbarCurrentUser = $topbarAuth->getCurrentUser();
                }
            } catch (Exception $e) {
                $topbarCurrentUser = null;
            }
        }

        $topbarTemplateData = [
            'module' => '',
            'display_name' => '',
            'subtitle' => '',
            'initials' => '',
            'menu_items' => []
        ];

        if (is_array($topbarCurrentUser) && !empty($topbarCurrentUser)) {
            $profile = (array)($topbarCurrentUser['profile'] ?? []);
            $firstName = trim((string)($profile['first_name'] ?? ''));
            $lastName = trim((string)($profile['last_name'] ?? ''));
            $displayName = trim($firstName . ' ' . $lastName);
            $emailAddress = trim((string)($profile['email'] ?? ($topbarCurrentUser['email'] ?? '')));
            $initials = strtoupper(substr($firstName !== '' ? $firstName : ((string)($topbarCurrentUser['username'] ?? 'U')), 0, 1) . substr($lastName, 0, 1));
            if ($initials === '') {
                $initials = 'U';
            }

            $topbarTemplateData['module'] = $topbarModule;
            $topbarTemplateData['display_name'] = $displayName !== '' ? $displayName : (string)($topbarCurrentUser['username'] ?? 'User');

            if ($topbarModule === 'admin') {
                $topbarTemplateData['subtitle'] = 'Admin';
                $topbarTemplateData['menu_items'] = [
                    ['href' => BASE_URL . '/views/admin/profile.php', 'icon' => 'fas fa-user', 'label' => 'My Profile'],
                    ['href' => BASE_URL . '/views/admin/settings/index.php', 'icon' => 'fas fa-cog', 'label' => 'Settings'],
                    ['divider' => true],
                    ['href' => BASE_URL . '/views/admin/logout.php', 'icon' => 'fas fa-sign-out-alt', 'label' => 'Logout', 'logout' => true],
                ];
            } elseif ($topbarModule === 'lecturer') {
                $topbarTemplateData['subtitle'] = trim((string)($profile['lecturer_id'] ?? 'Lecturer'));
                $topbarTemplateData['menu_items'] = [
                    ['href' => BASE_URL . '/views/lecturer/profile.php', 'icon' => 'fas fa-user', 'label' => 'My Profile'],
                    ['href' => BASE_URL . '/views/lecturer/my-courses.php', 'icon' => 'fas fa-book', 'label' => 'My Courses'],
                    ['href' => BASE_URL . '/views/lecturer/reports.php', 'icon' => 'fas fa-file-alt', 'label' => 'Reports'],
                    ['href' => BASE_URL . '/views/lecturer/change-password.php', 'icon' => 'fas fa-key', 'label' => 'Change Password'],
                    ['divider' => true],
                    ['href' => BASE_URL . '/views/lecturer/logout.php', 'icon' => 'fas fa-sign-out-alt', 'label' => 'Logout', 'logout' => true],
                ];
            } elseif ($topbarModule === 'finance') {
                $topbarTemplateData['subtitle'] = 'Finance Staff';
                $topbarTemplateData['menu_items'] = [
                    ['href' => BASE_URL . '/views/finance/change-password.php', 'icon' => 'fas fa-key', 'label' => 'Change Password'],
                    ['divider' => true],
                    ['href' => BASE_URL . '/views/finance/logout.php', 'icon' => 'fas fa-sign-out-alt', 'label' => 'Logout', 'logout' => true],
                ];
            } else {
                $topbarTemplateData['subtitle'] = $emailAddress !== '' ? $emailAddress : ucfirst($topbarModule);
            }

            $topbarTemplateData['initials'] = $initials;
        }
    ?>

        <!-- Session Inactivity Timeout Checker -->
    <script>
    (function() {
        var ACTIVE_MODULE = <?php echo json_encode($autoLogoutModule); ?>;
        var ACTIVE_TOKEN = <?php echo json_encode($autoLogoutToken); ?>;
        var DISABLE_AUTO_LOGOUT = <?php echo json_encode(!empty($disableAutoLogout)); ?>;
        if (DISABLE_AUTO_LOGOUT) {
            ACTIVE_MODULE = '';
            ACTIVE_TOKEN = '';
        }
        var AUTO_LOGOUT_ENABLED = !!ACTIVE_MODULE;
        var AUTO_LOGOUT_ENDPOINT = '<?php echo BASE_URL; ?>/api/auto-logout.php';
        var TIMEOUT_MS = 10 * 60 * 1000; // 10 minutes
        var WARNING_MS = 5 * 60 * 1000; // warn 5 minutes before
        var lastActivity = Date.now();
        var warned = false;
        var warningModal = null;
        var hasLoggedOut = false;
        var hasTimedOut = false;
        var internalNavigation = false;
        var TAB_KEY = ACTIVE_MODULE ? ('smns_open_tabs_' + ACTIVE_MODULE) : '';
        var tabId = '';
        var HEARTBEAT_MS = 15000;
        var STALE_TAB_MS = 45000;

        function getSafeTabMap(raw) {
            if (!raw) return {};
            try {
                var parsed = JSON.parse(raw);
                return (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
            } catch (err) {
                return {};
            }
        }

        function pruneTabMap(map) {
            var now = Date.now();
            var out = {};
            Object.keys(map || {}).forEach(function(id) {
                var ts = Number(map[id] || 0);
                if (ts > 0 && (now - ts) <= STALE_TAB_MS) {
                    out[id] = ts;
                }
            });
            return out;
        }

        function getOpenTabMap() {
            if (!TAB_KEY) return {};
            var raw = '';
            try {
                raw = localStorage.getItem(TAB_KEY);
            } catch (err) {
                return {};
            }
            var map = getSafeTabMap(raw);
            map = pruneTabMap(map);
            try {
                localStorage.setItem(TAB_KEY, JSON.stringify(map));
            } catch (err) {
                // Ignore storage write failures.
            }
            return map;
        }

        function setOpenTabMap(map) {
            if (!TAB_KEY) return;
            try {
                localStorage.setItem(TAB_KEY, JSON.stringify(pruneTabMap(map)));
            } catch (err) {
                // Ignore storage write failures.
            }
        }

        function getTabCount(map) {
            return Object.keys(map || {}).length;
        }

        function touchCurrentTab() {
            if (!AUTO_LOGOUT_ENABLED || !tabId) return;
            var map = getOpenTabMap();
            map[tabId] = Date.now();
            setOpenTabMap(map);
        }

        function registerTab() {
            if (!AUTO_LOGOUT_ENABLED) return;
            if (!tabId) {
                tabId = sessionStorage.getItem('smns_tab_id_' + ACTIVE_MODULE) || '';
            }
            if (!tabId) {
                tabId = ACTIVE_MODULE + '_' + Date.now() + '_' + Math.random().toString(36).slice(2);
                sessionStorage.setItem('smns_tab_id_' + ACTIVE_MODULE, tabId);
            }
            touchCurrentTab();
        }

        function unregisterTab() {
            if (!AUTO_LOGOUT_ENABLED || !tabId) return 0;
            var map = getOpenTabMap();
            delete map[tabId];
            setOpenTabMap(map);
            return getTabCount(map);
        }

        function postAutoLogout(reason) {
            if (!AUTO_LOGOUT_ENABLED || hasLoggedOut) return;
            hasLoggedOut = true;

            var body = new URLSearchParams();
            body.append('module', ACTIVE_MODULE);
            body.append('token', ACTIVE_TOKEN || '');
            body.append('reason', reason || 'close');
            body.append('source', 'footer');
            var payload = body.toString();

            if (navigator.sendBeacon) {
                try {
                    var blob = new Blob([payload], { type: 'application/x-www-form-urlencoded;charset=UTF-8' });
                    navigator.sendBeacon(AUTO_LOGOUT_ENDPOINT, blob);
                    return;
                } catch (err) {
                    // Fall through to fetch.
                }
            }

            fetch(AUTO_LOGOUT_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive: true,
                body: payload
            }).catch(function() {});
        }

        function postPresence(reason) {
            if (!AUTO_LOGOUT_ENABLED) return;
            var body = new URLSearchParams();
            body.append('module', ACTIVE_MODULE);
            body.append('token', ACTIVE_TOKEN || '');
            body.append('reason', reason || 'page_load');
            body.append('source', 'footer');
            fetch(AUTO_LOGOUT_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive: true,
                body: body.toString()
            }).catch(function() {});
        }

        function getModuleLoginUrl() {
            var qs = ACTIVE_MODULE ? ('&module=' + encodeURIComponent(ACTIVE_MODULE)) : '';
            return '<?php echo BASE_URL; ?>/views/auth/login.php?error=session_expired' + qs;
        }

        function resetTimer() {
            lastActivity = Date.now();
            warned = false;
            hideWarning();
        }

        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function(evt) {
            document.addEventListener(evt, resetTimer, { passive: true });
        });

        function showWarning() {
            if (warningModal) return;
            warningModal = document.createElement('div');
            warningModal.id = 'sessionTimeoutWarning';
            warningModal.innerHTML = '<div class="sto-overlay"></div>' +
                '<div class="sto-box">' +
                '<i class="fas fa-exclamation-triangle sto-icon"></i>' +
                '<h5>Session Expiring</h5>' +
                '<p>You will be logged out in <span id="stoCountdown">60</span> seconds due to inactivity.</p>' +
                '<button class="btn btn-primary btn-sm" id="stoStayBtn">Stay Logged In</button>' +
                '</div>';
            document.body.appendChild(warningModal);
            document.getElementById('stoStayBtn').addEventListener('click', function() {
                resetTimer();
                fetch(window.location.href, { method: 'HEAD', cache: 'no-store' }).catch(function(){});
            });
        }

        function hideWarning() {
            if (warningModal) {
                warningModal.remove();
                warningModal = null;
            }
        }

        registerTab();
        postPresence('page_load');
        if (AUTO_LOGOUT_ENABLED) {
            setInterval(function() {
                touchCurrentTab();
            }, HEARTBEAT_MS);
        }

        document.addEventListener('click', function(e) {
            var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!anchor) return;
            var href = anchor.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#') return;
            if (anchor.target && anchor.target.toLowerCase() === '_blank') return;
            if (anchor.hasAttribute('download')) return;
            if (/^(mailto:|tel:|javascript:)/i.test(href)) return;
            internalNavigation = true;
        }, true);

        document.addEventListener('submit', function() {
            internalNavigation = true;
        }, true);

        window.addEventListener('keydown', function(e) {
            var key = (e.key || '').toLowerCase();
            if (key === 'f5' || ((e.ctrlKey || e.metaKey) && key === 'r')) {
                internalNavigation = true;
            }
        }, true);

        window.addEventListener('pagehide', function(event) {
            // Keep tab map in sync, but do not auto-logout on pagehide.
            // pagehide also fires on refresh/navigation in many browsers.
            unregisterTab();
        });

        setInterval(function() {
            if (!AUTO_LOGOUT_ENABLED) {
                return;
            }

            var elapsed = Date.now() - lastActivity;
            var remaining = TIMEOUT_MS - elapsed;

            if (remaining <= 0) {
                if (!hasTimedOut) {
                    hasTimedOut = true;
                    unregisterTab();
                    postAutoLogout('inactivity_timeout');
                    setTimeout(function() {
                        window.location.href = getModuleLoginUrl();
                    }, 120);
                }
                return;
            }

            if (remaining <= WARNING_MS && !warned) {
                warned = true;
                showWarning();
            }

            var cd = document.getElementById('stoCountdown');
            if (cd && remaining > 0) {
                cd.textContent = Math.ceil(remaining / 1000);
            }
        }, 5000);
    })();
    </script>
    <script>
    (function() {
        var AUTO_DISMISS_MS = 10000;

        function shouldAutoDismiss(alertEl) {
            if (!alertEl) return false;
            if (alertEl.hasAttribute('data-persistent') || alertEl.classList.contains('alert-persistent')) {
                return false;
            }
            // Keep embedded/in-content status alerts visible.
            if (alertEl.closest('.card-body, .table-responsive, .modal-body, .mail-body, .history-body')) {
                return false;
            }
            return true;
        }

        function hideAlert(alertEl) {
            if (!alertEl || !alertEl.parentNode) return;
            alertEl.style.transition = 'opacity 0.25s ease';
            alertEl.style.opacity = '0';
            window.setTimeout(function() {
                if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.alert === 'function') {
                    try {
                        window.jQuery(alertEl).alert('close');
                        return;
                    } catch (e) {}
                }
                if (alertEl.parentNode) {
                    alertEl.parentNode.removeChild(alertEl);
                }
            }, 260);
        }

        document.addEventListener('DOMContentLoaded', function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alertEl) {
                if (!shouldAutoDismiss(alertEl)) return;
                window.setTimeout(function() {
                    hideAlert(alertEl);
                }, AUTO_DISMISS_MS);
            });
        });
    })();
    </script>

    <style>
    /* Session Timeout Warning */
    .sto-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5); z-index: 9998;
    }
    .sto-box {
        position: fixed; top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        background: #fff; border-radius: 12px;
        padding: 30px 36px; text-align: center;
        box-shadow: 0 10px 40px rgba(0,0,0,0.25);
        z-index: 9999; max-width: 360px; width: 90%;
    }
    .sto-icon {
        font-size: 36px; color: #f0ad4e; margin-bottom: 12px;
    }
    .sto-box h5 {
        font-size: 16px; font-weight: 700; margin-bottom: 8px; color: #1a1a2e;
    }
    .sto-box p {
        font-size: 13px; color: #555; margin-bottom: 16px;
    }
    .sto-box #stoCountdown {
        font-weight: 700; color: #dc3545;
    }
    html[data-theme='dark'] .sto-overlay {
        background: rgba(2, 6, 23, 0.72);
    }
    html[data-theme='dark'] .sto-box {
        background: #0f172a;
        border: 1px solid #334155;
        box-shadow: 0 14px 40px rgba(2, 6, 23, 0.7);
    }
    html[data-theme='dark'] .sto-icon {
        color: #fbbf24;
    }
    html[data-theme='dark'] .sto-box h5 {
        color: #f8fafc;
    }
    html[data-theme='dark'] .sto-box p {
        color: #cbd5e1;
    }
    html[data-theme='dark'] .sto-box #stoCountdown {
        color: #fca5a5;
    }
    html[data-theme='dark'] .sto-box #stoStayBtn {
        background: #2563eb;
        border-color: #1d4ed8;
        color: #ffffff;
    }
    html[data-theme='dark'] .sto-box #stoStayBtn:hover,
    html[data-theme='dark'] .sto-box #stoStayBtn:focus {
        background: #1d4ed8;
        border-color: #1e40af;
        color: #ffffff;
    }

    /* Global Theme Toggle */
    #themeToggleBtn {
        position: fixed;
        right: 16px;
        bottom: 16px;
        width: 40px;
        height: 40px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #ffffff;
        color: #222;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        z-index: 10050;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }
    #themeToggleBtn:hover {
        transform: translateY(-1px);
    }
    #themeToggleBtn:focus {
        outline: 2px solid #2563eb;
        outline-offset: 2px;
    }

    :root {
        --app-bg: #f4f6f9;
        --app-text: #1f2937;
        --app-muted: #6b7280;
        --app-surface: #ffffff;
        --app-surface-1: #ffffff;
        --app-surface-2: #f8fafc;
        --app-border: #e5e7eb;
    }
    html[data-theme='dark'] {
        --app-bg: #0b1220;
        --app-text: #e5e7eb;
        --app-muted: #9ca3af;
        --app-surface: #111827;
        --app-surface-1: #0f172a;
        --app-surface-2: #1f2937;
        --app-border: #334155;
    }
    html[data-theme='dark'] body {
        background: var(--app-bg) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .sidebar,
    html[data-theme='dark'] .student-sidebar,
    html[data-theme='dark'] .lecturer-sidebar,
    html[data-theme='dark'] .finance-sidebar,
    html[data-theme='dark'] .topbar,
    html[data-theme='dark'] .student-topbar,
    html[data-theme='dark'] .card,
    html[data-theme='dark'] .cardx,
    html[data-theme='dark'] .cal-card,
    html[data-theme='dark'] .enroll-shell,
    html[data-theme='dark'] .history-shell,
    html[data-theme='dark'] .mail-wrap,
    html[data-theme='dark'] .main-content {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .table,
    html[data-theme='dark'] .tbl,
    html[data-theme='dark'] .cal-table,
    html[data-theme='dark'] table {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] th,
    html[data-theme='dark'] td,
    html[data-theme='dark'] .table th,
    html[data-theme='dark'] .table td,
    html[data-theme='dark'] .tbl th,
    html[data-theme='dark'] .tbl td {
        border-color: var(--app-border) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] thead th,
    html[data-theme='dark'] table thead th,
    html[data-theme='dark'] .table thead th,
    html[data-theme='dark'] .table > thead > tr > th {
        background: var(--app-surface-2) !important;
        color: #f8fafc !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .student-sidebar li,
    html[data-theme='dark'] .sidebar-menu a,
    html[data-theme='dark'] .student-sidebar a,
    html[data-theme='dark'] .topbar a,
    html[data-theme='dark'] .student-topbar a,
    html[data-theme='dark'] label,
    html[data-theme='dark'] small,
    html[data-theme='dark'] p,
    html[data-theme='dark'] span,
    html[data-theme='dark'] h1,
    html[data-theme='dark'] h2,
    html[data-theme='dark'] h3,
    html[data-theme='dark'] h4,
    html[data-theme='dark'] h5,
    html[data-theme='dark'] h6 {
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .text-muted {
        color: var(--app-muted) !important;
    }
    html[data-theme='dark'] input,
    html[data-theme='dark'] select,
    html[data-theme='dark'] textarea,
    html[data-theme='dark'] .form-control {
        background: var(--app-surface-2) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .modal-content,
    html[data-theme='dark'] .dropdown-menu {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .user-dropdown-menu,
    html[data-theme='dark'] #userDropdownMenu,
    html[data-theme='dark'] #profileDropMenu {
        background: var(--app-surface-1) !important;
        background-color: var(--app-surface-1) !important;
        border: 1px solid var(--app-border) !important;
        box-shadow: 0 10px 24px rgba(2, 6, 23, 0.55) !important;
        opacity: 1 !important;
        -webkit-backdrop-filter: none !important;
        backdrop-filter: none !important;
    }
    html[data-theme='dark'] .user-profile-meta {
        background: var(--app-surface-1) !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .user-dropdown-menu .dropdown-item,
    html[data-theme='dark'] #profileDropMenu a {
        background: var(--app-surface-1) !important;
        color: var(--app-text) !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .user-dropdown-menu .dropdown-item:hover,
    html[data-theme='dark'] .user-dropdown-menu .dropdown-item:focus,
    html[data-theme='dark'] #profileDropMenu a:hover,
    html[data-theme='dark'] #profileDropMenu a:focus {
        background: var(--app-surface-2) !important;
        color: #ffffff !important;
    }
    html[data-theme='dark'] .user-profile-meta .user-fullname {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .user-profile-meta .user-email,
    html[data-theme='dark'] .user-profile-meta .user-email i {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .dropdown-divider {
        background: var(--app-border) !important;
    }
    html[data-theme='dark'] #profileDropMenu a {
        color: #e5e7eb !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] #profileDropMenu a[href*='logout'] {
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] #profileDropMenu a[href*='logout']:hover,
    html[data-theme='dark'] #profileDropMenu a[href*='logout']:focus {
        background: #3a1820 !important;
        color: #fecaca !important;
    }
    html[data-theme='dark'] .dropdown-item.logout-item {
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] .dropdown-item.logout-item:hover,
    html[data-theme='dark'] .dropdown-item.logout-item:focus {
        background: #3a1820 !important;
        color: #fecaca !important;
    }
    html[data-theme='dark'] .btn-light {
        background: var(--app-surface-2) !important;
        border-color: var(--app-border) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] #themeToggleBtn {
        background: #111827;
        color: #f9fafb;
        border-color: #334155;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
    }

    /* Contrast fixes for Bootstrap/light utility classes in dark mode */
    html[data-theme='dark'] a {
        color: #93c5fd;
    }
    html[data-theme='dark'] a:hover {
        color: #bfdbfe;
    }
    html[data-theme='dark'] .bg-light,
    html[data-theme='dark'] .card-header.bg-light,
    html[data-theme='dark'] .card.bg-light,
    html[data-theme='dark'] .thead-light th,
    html[data-theme='dark'] .table .thead-light th {
        background: var(--app-surface-2) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(148, 163, 184, 0.08) !important;
    }
    html[data-theme='dark'] .table-hover tbody tr:hover {
        background-color: rgba(148, 163, 184, 0.14) !important;
    }
    html[data-theme='dark'] .dropdown-item,
    html[data-theme='dark'] .dropdown-item-text {
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .dropdown-item:hover,
    html[data-theme='dark'] .dropdown-item:focus {
        background: var(--app-surface-2) !important;
        color: #ffffff !important;
    }
    html[data-theme='dark'] .sidebar .sidebar-header,
    html[data-theme='dark'] .student-sidebar .sidebar-header,
    html[data-theme='dark'] .lecturer-sidebar .sidebar-header,
    html[data-theme='dark'] .finance-sidebar .sidebar-header {
        background: #0f172a !important;
        border-bottom-color: #334155 !important;
    }
    html[data-theme='dark'] .sidebar .sidebar-header h3,
    html[data-theme='dark'] .sidebar .sidebar-header p,
    html[data-theme='dark'] .sidebar .sidebar-header small,
    html[data-theme='dark'] .student-sidebar .sidebar-header h3,
    html[data-theme='dark'] .student-sidebar .sidebar-header p,
    html[data-theme='dark'] .student-sidebar .sidebar-header small,
    html[data-theme='dark'] .lecturer-sidebar .sidebar-header h3,
    html[data-theme='dark'] .lecturer-sidebar .sidebar-header p,
    html[data-theme='dark'] .lecturer-sidebar .sidebar-header small,
    html[data-theme='dark'] .finance-sidebar .sidebar-header h3,
    html[data-theme='dark'] .finance-sidebar .sidebar-header p,
    html[data-theme='dark'] .finance-sidebar .sidebar-header small {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .lecturer-sidebar .sidebar-profile {
        background: linear-gradient(135deg, #0f172a, #1e293b) !important;
        border: 1px solid #334155 !important;
    }
    html[data-theme='dark'] .lecturer-sidebar .sidebar-profile-avatar {
        border-color: #334155 !important;
        box-shadow: 0 2px 8px rgba(2, 6, 23, 0.55) !important;
    }
    html[data-theme='dark'] .lecturer-sidebar .sidebar-profile-name {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .lecturer-sidebar .sidebar-profile-id {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .sidebar-menu a,
    html[data-theme='dark'] .sidebar-menu a span,
    html[data-theme='dark'] .sidebar-menu a i,
    html[data-theme='dark'] .student-sidebar li,
    html[data-theme='dark'] .student-sidebar li a {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .sidebar-menu a:hover,
    html[data-theme='dark'] .sidebar-menu a:focus,
    html[data-theme='dark'] .sidebar-menu a:active,
    html[data-theme='dark'] .sidebar-menu > ul > li > a:hover,
    html[data-theme='dark'] .sidebar-menu > ul > li > a:focus,
    html[data-theme='dark'] .sidebar-menu > ul > li > a:active,
    html[data-theme='dark'] .student-sidebar li:hover,
    html[data-theme='dark'] .student-sidebar li:active,
    html[data-theme='dark'] .student-sidebar li:hover a {
        background: #1e293b !important;
        color: #f8fafc !important;
        border-color: #3b82f6 !important;
    }
    html[data-theme='dark'] .sidebar-menu a.active,
    html[data-theme='dark'] .sidebar-menu > ul > li > a.active,
    html[data-theme='dark'] .sidebar-menu li.open > a,
    html[data-theme='dark'] .student-sidebar li.active,
    html[data-theme='dark'] .student-sidebar li.active a,
    html[data-theme='dark'] .student-sidebar li:active a {
        background: #1d4ed8 !important;
        color: #ffffff !important;
        border-left-color: #93c5fd !important;
    }
    html[data-theme='dark'] .sidebar-menu .submenu,
    html[data-theme='dark'] .sidebar-menu li.open > .submenu {
        background: #0b1220 !important;
        border-top: 1px solid #334155 !important;
        border-bottom: 1px solid #334155 !important;
    }
    html[data-theme='dark'] .sidebar-menu .submenu a {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .sidebar-menu .submenu a:hover,
    html[data-theme='dark'] .sidebar-menu .submenu a:focus,
    html[data-theme='dark'] .sidebar-menu .submenu a.active {
        background: #1e293b !important;
        color: #ffffff !important;
    }
    html[data-theme='dark'] .sidebar-menu li.menu-section,
    html[data-theme='dark'] .menu-section {
        color: #94a3b8 !important;
        border-top-color: #334155 !important;
    }
    html[data-theme='dark'] .sidebar-menu li.logout-item,
    html[data-theme='dark'] .logout-item {
        border-top-color: #334155 !important;
    }
    html[data-theme='dark'] .sidebar-menu a.logout-link {
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] .sidebar-menu a.logout-link:hover,
    html[data-theme='dark'] .logout-link:hover {
        background: #7f1d1d !important;
        color: #fecaca !important;
        border-left-color: #f87171 !important;
    }
    html[data-theme='dark'] .badge {
        color: #111827 !important;
    }
    html[data-theme='dark'] .badge-primary,
    html[data-theme='dark'] .badge-secondary,
    html[data-theme='dark'] .badge-success,
    html[data-theme='dark'] .badge-danger,
    html[data-theme='dark'] .badge-dark {
        color: #ffffff !important;
    }
    html[data-theme='dark'] .badge-light {
        background: #334155 !important;
        color: #f8fafc !important;
        border: 1px solid #475569;
    }
    html[data-theme='dark'] .text-dark,
    html[data-theme='dark'] .text-body,
    html[data-theme='dark'] .text-black-50 {
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .small,
    html[data-theme='dark'] .form-text,
    html[data-theme='dark'] small {
        color: var(--app-muted) !important;
    }
    html[data-theme='dark'] .alert {
        background: #111827 !important;
        color: #e5e7eb !important;
        border-color: #334155 !important;
        box-shadow: 0 6px 18px rgba(2, 6, 23, 0.35) !important;
    }
    html[data-theme='dark'] .alert *,
    html[data-theme='dark'] .alert p,
    html[data-theme='dark'] .alert span,
    html[data-theme='dark'] .alert small,
    html[data-theme='dark'] .alert strong,
    html[data-theme='dark'] .alert i {
        color: inherit !important;
    }
    html[data-theme='dark'] .alert a {
        color: #bfdbfe !important;
        text-decoration: underline;
    }
    html[data-theme='dark'] .alert hr {
        border-top-color: rgba(148, 163, 184, 0.35) !important;
    }
    html[data-theme='dark'] .alert-primary {
        background: #172554 !important;
        border-color: #1d4ed8 !important;
        color: #dbeafe !important;
    }
    html[data-theme='dark'] .alert-secondary {
        background: #1f2937 !important;
        border-color: #475569 !important;
        color: #e2e8f0 !important;
    }
    html[data-theme='dark'] .alert-success {
        background: #052e1f !important;
        border-color: #166534 !important;
        color: #bbf7d0 !important;
    }
    html[data-theme='dark'] .alert-danger {
        background: #3b0d13 !important;
        border-color: #7f1d1d !important;
        color: #fecaca !important;
    }
    html[data-theme='dark'] .alert-warning {
        background: #3f2f12 !important;
        border-color: #7c5a1e !important;
        color: #fef3c7 !important;
    }
    html[data-theme='dark'] .alert-info {
        background: #0b2f45 !important;
        border-color: #1f4f75 !important;
        color: #dbeafe !important;
    }
    html[data-theme='dark'] .alert-light {
        background: #1f2937 !important;
        border-color: #475569 !important;
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .alert-dark {
        background: #020617 !important;
        border-color: #1e293b !important;
        color: #e2e8f0 !important;
    }
    html[data-theme='dark'] .text-white,
    html[data-theme='dark'] .text-white * {
        color: #ffffff !important;
    }
    html[data-theme='dark'] .badge-warning,
    html[data-theme='dark'] .badge-info,
    html[data-theme='dark'] .btn-warning {
        color: #111827 !important;
    }
    html[data-theme='dark'] .warning-box,
    html[data-theme='dark'] .bg-warning {
        background: #3f2f12 !important;
        border-color: #7c5a1e !important;
        color: #fef3c7 !important;
    }
    html[data-theme='dark'] .warning-box *,
    html[data-theme='dark'] .bg-warning * {
        color: #fef3c7 !important;
    }
    html[data-theme='dark'] .code-snippet,
    html[data-theme='dark'] pre,
    html[data-theme='dark'] code {
        background: #0b1220 !important;
        border-color: #334155 !important;
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .code-snippet * {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .config-card,
    html[data-theme='dark'] .current-config,
    html[data-theme='dark'] .test-form,
    html[data-theme='dark'] .instructions-card,
    html[data-theme='dark'] .results-card,
    html[data-theme='dark'] .bio-card,
    html[data-theme='dark'] .mail-shell,
    html[data-theme='dark'] .request-box,
    html[data-theme='dark'] .tbl,
    html[data-theme='dark'] .acc-item,
    html[data-theme='dark'] .history-item,
    html[data-theme='dark'] .history-body,
    html[data-theme='dark'] .history-item summary,
    html[data-theme='dark'] .history-print,
    html[data-theme='dark'] .tx-shell,
    html[data-theme='dark'] .mail-btn,
    html[data-theme='dark'] .mail-item,
    html[data-theme='dark'] .mail-action,
    html[data-theme='dark'] .top-logout-link,
    html[data-theme='dark'] .saved-item,
    html[data-theme='dark'] .services-submenu li.active,
    html[data-theme='dark'] .payments-submenu li.active,
    html[data-theme='dark'] .enroll-submenu li.active,
    html[data-theme='dark'] .programme-submenu li.active,
    html[data-theme='dark'] .card-header:not([class*='bg-']) {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .mail-action.warn,
    html[data-theme='dark'] .top-logout-link {
        background: #3a1820 !important;
        border-color: #6b2531 !important;
        color: #fecdd3 !important;
    }
    html[data-theme='dark'] .chip.gray {
        background: #1f2937 !important;
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .chip.red,
    html[data-theme='dark'] .status-pill.closed {
        background: #3b2314 !important;
        border-color: #7c3e18 !important;
        color: #fdba74 !important;
    }
    html[data-theme='dark'] .cal-current,
    html[data-theme='dark'] .status-pill.open {
        background: #123021 !important;
        border-color: #1d5136 !important;
        color: #86efac !important;
    }
    html[data-theme='dark'] .config-header h1,
    html[data-theme='dark'] .config-header h2,
    html[data-theme='dark'] .config-header h3,
    html[data-theme='dark'] .config-header p,
    html[data-theme='dark'] .topbar h1,
    html[data-theme='dark'] .topbar h2,
    html[data-theme='dark'] .topbar h3,
    html[data-theme='dark'] .topbar h4,
    html[data-theme='dark'] .student-topbar h1,
    html[data-theme='dark'] .student-topbar h2,
    html[data-theme='dark'] .student-topbar h3,
    html[data-theme='dark'] .student-topbar h4 {
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .user-dropdown-toggle,
    html[data-theme='dark'] .user-dropdown-toggle strong,
    html[data-theme='dark'] .user-dropdown-toggle small,
    html[data-theme='dark'] .dropdown-arrow {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .sidebar-toggle,
    html[data-theme='dark'] #menuBtn {
        color: #f8fafc !important;
        background: #1e293b !important;
        border: 1px solid #334155 !important;
        border-radius: 8px !important;
    }
    html[data-theme='dark'] .sidebar-toggle i,
    html[data-theme='dark'] #menuBtn i,
    html[data-theme='dark'] .sidebar-toggle .fa-bars,
    html[data-theme='dark'] #menuBtn .fa-bars {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .sidebar-toggle:hover,
    html[data-theme='dark'] #menuBtn:hover {
        background: #273449 !important;
        border-color: #475569 !important;
    }
    html[data-theme='dark'] .sidebar-toggle:focus,
    html[data-theme='dark'] #menuBtn:focus {
        outline: 2px solid #60a5fa !important;
        outline-offset: 1px !important;
        box-shadow: none !important;
    }
    #profileDropBtn,
    #profileDropBtn .fa-chevron-down {
        color: #334155;
    }
    #profileDropBtn {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 30px !important;
        height: 30px !important;
        padding: 0 !important;
        line-height: 1 !important;
        border-radius: 50% !important;
        border: 1px solid transparent !important;
        background: transparent !important;
    }
    #profileDropBtn .fa-chevron-down {
        font-size: 0.82rem !important;
        line-height: 1 !important;
    }
    #profileDropBtn:hover {
        background: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
    }
    html[data-theme='dark'] #profileDropBtn,
    html[data-theme='dark'] #profileDropBtn .fa-chevron-down {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] #profileDropBtn {
        background: var(--app-surface-2) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] #profileDropBtn:hover {
        background: #273449 !important;
    }
    html[data-theme='dark'] #profileDropBtn:focus {
        outline: 2px solid #60a5fa;
        outline-offset: 1px;
    }
    html[data-theme='dark'] .topbar-time {
        border-right-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .topbar-time .time-display {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .topbar-time .date-display {
        background: var(--app-surface-2) !important;
        color: #cbd5e1 !important;
        border: 1px solid var(--app-border) !important;
        box-shadow: 0 8px 22px rgba(2, 6, 23, 0.6) !important;
    }
    html[data-theme='dark'] .topbar-time .date-display::before {
        border-bottom-color: var(--app-surface-2) !important;
    }
    html[data-theme='dark'] .status-badge.status-active {
        background: #14532d !important;
        color: #bbf7d0 !important;
        border-color: #22c55e !important;
    }
    html[data-theme='dark'] .status-badge.status-notreg {
        background: #7f1d1d !important;
        color: #fecaca !important;
        border-color: #ef4444 !important;
    }
    html[data-theme='dark'] .bio-section-tabs {
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .bio-section-tabs .tab {
        background: transparent !important;
        color: #cbd5e1 !important;
        border-bottom-color: transparent !important;
    }
    html[data-theme='dark'] .bio-section-tabs .tab:hover {
        color: #e2e8f0 !important;
    }
    html[data-theme='dark'] .bio-section-tabs .tab.active {
        color: #60a5fa !important;
        border-bottom-color: #60a5fa !important;
    }
    html[data-theme='dark'] .results-card {
        background: var(--app-surface-1) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .results-header {
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .results-header h4,
    html[data-theme='dark'] .student-meta,
    html[data-theme='dark'] .year-title,
    html[data-theme='dark'] .semester-title {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .semester-title {
        background: var(--app-surface-2) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .results-table {
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .results-table th {
        background: var(--app-surface-2) !important;
        color: #f8fafc !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .results-table td {
        color: #e5e7eb !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .results-table strong {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .summary-row td {
        background: #1f2937 !important;
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .cgpa-row td {
        background: #123021 !important;
        color: #bbf7d0 !important;
    }
    html[data-theme='dark'] .badge-published {
        background: #14532d !important;
        border-color: #22c55e !important;
        color: #bbf7d0 !important;
    }
    html[data-theme='dark'] .badge-pending {
        background: #7f1d1d !important;
        border-color: #ef4444 !important;
        color: #fecaca !important;
    }
    html[data-theme='dark'] td[style*='color:#666;'],
    html[data-theme='dark'] td[style*='color: #666;'] {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] h6[style*='color:#374151;'],
    html[data-theme='dark'] h6[style*='color: #374151;'] {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] [style*='background:#f9fafb;'],
    html[data-theme='dark'] [style*='background: #f9fafb;'] {
        background: var(--app-surface-2) !important;
        color: #e5e7eb !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .student-topbar [style*='background:#f1f5f9;'],
    html[data-theme='dark'] .student-topbar [style*='background: #f1f5f9;'] {
        background: var(--app-surface-2) !important;
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .student-topbar [style*='border:1px solid #e5e7eb;'],
    html[data-theme='dark'] .student-topbar [style*='border: 1px solid #e5e7eb;'] {
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] #profileDropMenu {
        background: var(--app-surface-1) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] #profileDropMenu a[style*='color:#1f2937;'],
    html[data-theme='dark'] #profileDropMenu a[style*='color: #1f2937;'] {
        color: #e5e7eb !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] [style*='background:#f1f5f9;'],
    html[data-theme='dark'] [style*='background: #f1f5f9;'] {
        background: var(--app-surface-2) !important;
        color: #e5e7eb !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] [style*='background:#dcfce7;'],
    html[data-theme='dark'] [style*='background: #dcfce7;'] {
        background: #14532d !important;
        color: #bbf7d0 !important;
        border-color: #22c55e !important;
    }
    html[data-theme='dark'] [style*='color:#222;'],
    html[data-theme='dark'] [style*='color: #222;'] {
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] [style*='color:#991b1b;'],
    html[data-theme='dark'] [style*='color: #991b1b;'] {
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] [style*='background:#fff;'],
    html[data-theme='dark'] [style*='background: #fff;'],
    html[data-theme='dark'] [style*='background:#ffffff;'],
    html[data-theme='dark'] [style*='background: #ffffff;'],
    html[data-theme='dark'] [style*='background-color:#fff;'],
    html[data-theme='dark'] [style*='background-color: #fff;'],
    html[data-theme='dark'] [style*='background-color:#ffffff;'],
    html[data-theme='dark'] [style*='background-color: #ffffff;'],
    html[data-theme='dark'] [style*='background:#f8f9fa;'],
    html[data-theme='dark'] [style*='background: #f8f9fa;'] {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] [style*='background:#fff;'] *,
    html[data-theme='dark'] [style*='background: #fff;'] *,
    html[data-theme='dark'] [style*='background:#ffffff;'] *,
    html[data-theme='dark'] [style*='background: #ffffff;'] *,
    html[data-theme='dark'] [style*='background-color:#fff;'] *,
    html[data-theme='dark'] [style*='background-color: #fff;'] *,
    html[data-theme='dark'] [style*='background-color:#ffffff;'] *,
    html[data-theme='dark'] [style*='background-color: #ffffff;'] *,
    html[data-theme='dark'] [style*='background:#f8f9fa;'] *,
    html[data-theme='dark'] [style*='background: #f8f9fa;'] * {
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] [style*='background:#fff3cd;'],
    html[data-theme='dark'] [style*='background: #fff3cd;'],
    html[data-theme='dark'] [style*='background:#fef3c7;'],
    html[data-theme='dark'] [style*='background: #fef3c7;'] {
        background: #3f2f12 !important;
        border-color: #7c5a1e !important;
        color: #fef3c7 !important;
    }
    html[data-theme='dark'] [style*='background:#fff1f2;'],
    html[data-theme='dark'] [style*='background: #fff1f2;'],
    html[data-theme='dark'] [style*='background:#fff7f7;'],
    html[data-theme='dark'] [style*='background: #fff7f7;'],
    html[data-theme='dark'] [style*='background:#fff5f5;'],
    html[data-theme='dark'] [style*='background: #fff5f5;'] {
        background: #3a1820 !important;
        border-color: #6b2531 !important;
        color: #fecdd3 !important;
    }
    html[data-theme='dark'] [style*='background:#ecfdf3;'],
    html[data-theme='dark'] [style*='background: #ecfdf3;'] {
        background: #123021 !important;
        border-color: #1d5136 !important;
        color: #d1fae5 !important;
    }
    html[data-theme='dark'] [style*='background:#fff3cd;'] *,
    html[data-theme='dark'] [style*='background: #fff3cd;'] *,
    html[data-theme='dark'] [style*='background:#fef3c7;'] *,
    html[data-theme='dark'] [style*='background: #fef3c7;'] * {
        color: #fef3c7 !important;
    }
    html[data-theme='dark'] [style*='background:#fff1f2;'] *,
    html[data-theme='dark'] [style*='background: #fff1f2;'] *,
    html[data-theme='dark'] [style*='background:#fff7f7;'] *,
    html[data-theme='dark'] [style*='background: #fff7f7;'] *,
    html[data-theme='dark'] [style*='background:#fff5f5;'] *,
    html[data-theme='dark'] [style*='background: #fff5f5;'] * {
        color: #fecdd3 !important;
    }
    html[data-theme='dark'] [style*='background:#ecfdf3;'] *,
    html[data-theme='dark'] [style*='background: #ecfdf3;'] * {
        color: #d1fae5 !important;
    }

    /* Admin dashboard: enforce full dark surfaces + readable light text */
    html[data-theme='dark'] .stat-card,
    html[data-theme='dark'] .action-card,
    html[data-theme='dark'] .assigned-courses-section,
    html[data-theme='dark'] .assignment-card,
    html[data-theme='dark'] .recent-activity,
    html[data-theme='dark'] .user-sessions-card,
    html[data-theme='dark'] .system-status,
    html[data-theme='dark'] .quick-links-card,
    html[data-theme='dark'] .semester-card,
    html[data-theme='dark'] .saved-notifications-card,
    html[data-theme='dark'] .saved-item,
    html[data-theme='dark'] .foldable-card {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.32) !important;
    }
    html[data-theme='dark'] .stat-icon i {
        color: #0f172a !important;
    }
    html[data-theme='dark'] .stat-icon.approval-icon {
        background: linear-gradient(135deg, #dcfce7, #bbf7d0) !important;
    }
    html[data-theme='dark'] .quick-actions-section h3,
    html[data-theme='dark'] .section-header h3,
    html[data-theme='dark'] .recent-activity h3,
    html[data-theme='dark'] .user-sessions-card h3,
    html[data-theme='dark'] .system-status h3,
    html[data-theme='dark'] .quick-links-card h3,
    html[data-theme='dark'] .semester-card h3,
    html[data-theme='dark'] .saved-notifications-card h3,
    html[data-theme='dark'] .stat-details h3,
    html[data-theme='dark'] .semester-name,
    html[data-theme='dark'] .course-info h5,
    html[data-theme='dark'] .activity-details h5,
    html[data-theme='dark'] .lecturer-info strong,
    html[data-theme='dark'] .session-user {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .quick-actions-section h3,
    html[data-theme='dark'] .section-header,
    html[data-theme='dark'] .foldable-header,
    html[data-theme='dark'] .status-item,
    html[data-theme='dark'] .sessions-table th,
    html[data-theme='dark'] .sessions-table td,
    html[data-theme='dark'] .activity-item {
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .action-card p,
    html[data-theme='dark'] .stat-details p,
    html[data-theme='dark'] .course-info p,
    html[data-theme='dark'] .course-info small,
    html[data-theme='dark'] .lecturer-info,
    html[data-theme='dark'] .semester-info,
    html[data-theme='dark'] .assignment-date,
    html[data-theme='dark'] .activity-details p,
    html[data-theme='dark'] .activity-date,
    html[data-theme='dark'] .activity-module,
    html[data-theme='dark'] .card-last-updated,
    html[data-theme='dark'] .saved-meta .saved-time,
    html[data-theme='dark'] .saved-body p,
    html[data-theme='dark'] .semester-dates,
    html[data-theme='dark'] .status-item span:first-child,
    html[data-theme='dark'] .fold-arrow {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .sessions-table th {
        background: var(--app-surface-2) !important;
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .sessions-table td {
        background: transparent !important;
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .session-duration {
        background: #1f2937 !important;
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .session-duration.active {
        background: transparent !important;
        color: #94a3b8 !important;
    }
    html[data-theme='dark'] .session-time.auto-closed,
    html[data-theme='dark'] .session-time.auto-closed small {
        color: #fbbf24 !important;
    }
    html[data-theme='dark'] .activity-module,
    html[data-theme='dark'] .activity-date {
        background: #1f2937 !important;
        border: 1px solid #334155 !important;
    }
    html[data-theme='dark'] .quick-link-item {
        background: #1f2937 !important;
        color: #e5e7eb !important;
        border: 1px solid #334155 !important;
    }
    html[data-theme='dark'] .quick-link-item:hover {
        background: #1d4ed8 !important;
        color: #ffffff !important;
        border-color: #93c5fd !important;
    }
    html[data-theme='dark'] .assignment-card:hover {
        border-color: #60a5fa !important;
        box-shadow: 0 6px 18px rgba(2, 6, 23, 0.6) !important;
    }
    html[data-theme='dark'] .recent-activity .activity-item {
        -webkit-tap-highlight-color: transparent;
    }
    html[data-theme='dark'] .recent-activity .activity-item:hover,
    html[data-theme='dark'] .recent-activity .activity-item:active,
    html[data-theme='dark'] .recent-activity .activity-item:focus-within {
        background: #1f2937 !important;
        border-radius: 8px !important;
    }
    html[data-theme='dark'] .status-indicator {
        border: 1px solid #334155 !important;
        border-radius: 999px !important;
        padding: 4px 10px !important;
    }
    html[data-theme='dark'] .status-indicator.online {
        background: #123021 !important;
        border-color: #1d5136 !important;
        color: #86efac !important;
    }
    html[data-theme='dark'] .status-indicator.warning {
        background: #3f2f12 !important;
        border-color: #7c5a1e !important;
        color: #fcd34d !important;
    }
    html[data-theme='dark'] .status-indicator.offline,
    html[data-theme='dark'] .status-indicator.error {
        background: #3a1820 !important;
        border-color: #6b2531 !important;
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] .notification-bell {
        color: #e2e8f0 !important;
        background: transparent !important;
    }
    html[data-theme='dark'] .notification-bell:hover {
        background: rgba(59, 130, 246, 0.18) !important;
        color: #bfdbfe !important;
    }
    html[data-theme='dark'] .notification-dropdown {
        background: var(--app-surface-1) !important;
        border: 1px solid var(--app-border) !important;
        box-shadow: 0 10px 28px rgba(2, 6, 23, 0.7) !important;
    }
    html[data-theme='dark'] .notification-dropdown::before {
        background: var(--app-surface-1) !important;
        box-shadow: -2px -2px 4px rgba(2, 6, 23, 0.35) !important;
    }
    html[data-theme='dark'] .notification-header {
        background: var(--app-surface-2) !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .notification-header h6 {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .notification-header a {
        color: #93c5fd !important;
    }
    html[data-theme='dark'] .notification-item {
        color: var(--app-text) !important;
        border-bottom-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .notification-item:hover {
        background: #1f2937 !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .notification-item.unread {
        background: #172554 !important;
        border-left-color: #3b82f6 !important;
    }
    html[data-theme='dark'] .notif-title {
        color: #f8fafc !important;
    }
    html[data-theme='dark'] .notif-text {
        color: #cbd5e1 !important;
    }
    html[data-theme='dark'] .notif-time {
        color: #94a3b8 !important;
    }
    html[data-theme='dark'] .notification-empty,
    html[data-theme='dark'] .notification-empty p {
        color: #94a3b8 !important;
    }
    html[data-theme='dark'] .notification-empty i {
        color: #64748b !important;
    }
    html[data-theme='dark'] .notif-icon.notif-info {
        background: #1e3a8a !important;
        color: #bfdbfe !important;
    }
    html[data-theme='dark'] .notif-icon.notif-success {
        background: #14532d !important;
        color: #86efac !important;
    }
    html[data-theme='dark'] .notif-icon.notif-warning {
        background: #78350f !important;
        color: #fcd34d !important;
    }
    html[data-theme='dark'] .notif-icon.notif-error {
        background: #7f1d1d !important;
        color: #fca5a5 !important;
    }
    html[data-theme='dark'] .change-password-panel {
        background: var(--app-surface-1) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
        box-shadow: 0 12px 24px rgba(2, 6, 23, 0.65) !important;
    }
    html[data-theme='dark'] .change-password-panel h6,
    html[data-theme='dark'] .change-password-panel label {
        color: #f8fafc !important;
    }
    </style>

    <button id="themeToggleBtn" type="button" title="Toggle Dark/Light Mode" aria-label="Toggle Dark/Light Mode">
        <i class="fas fa-moon" aria-hidden="true"></i>
    </button>
    <script>
    (function() {
        var STORAGE_KEY = 'smns_theme_mode';
        var root = document.documentElement;
        var btn = document.getElementById('themeToggleBtn');

        function applyTheme(mode) {
            if (mode === 'dark') {
                root.setAttribute('data-theme', 'dark');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-sun" aria-hidden="true"></i>';
                    btn.setAttribute('title', 'Switch to Light Mode');
                    btn.setAttribute('aria-label', 'Switch to Light Mode');
                }
            } else {
                root.removeAttribute('data-theme');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-moon" aria-hidden="true"></i>';
                    btn.setAttribute('title', 'Switch to Dark Mode');
                    btn.setAttribute('aria-label', 'Switch to Dark Mode');
                }
            }
        }

        var initial = 'light';
        try {
            var savedMode = localStorage.getItem(STORAGE_KEY);
            if (savedMode === 'dark' || savedMode === 'light') {
                initial = savedMode;
            }
        } catch (e) {}
        applyTheme(initial);

        if (btn) {
            btn.addEventListener('click', function() {
                var currentMode = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                var nextMode = currentMode === 'dark' ? 'light' : 'dark';
                try {
                    localStorage.setItem(STORAGE_KEY, nextMode);
                } catch (e) {}
                applyTheme(nextMode);
            });
        }
    })();
    </script>
    <script>
    (function() {
        var topbarData = <?php echo json_encode($topbarTemplateData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        if (!topbarData || !topbarData.module || ['admin', 'lecturer', 'finance'].indexOf(String(topbarData.module)) === -1) {
            return;
        }

        var overflowPlaceholders = new WeakMap();

        function isMobileTopbar() {
            return window.innerWidth <= 767.98;
        }

        function closeAllTopbarOverflows() {
            document.querySelectorAll('.smns-topbar-overflow.active').forEach(function(wrap) {
                wrap.classList.remove('active');
            });
        }

        function createTimeBlock() {
            var wrap = document.createElement('div');
            wrap.className = 'topbar-time smns-injected-topbar-time';

            var inner = document.createElement('div');
            inner.id = 'current-date-time';

            var time = document.createElement('div');
            time.className = 'time-display';
            time.textContent = '';

            var date = document.createElement('div');
            date.className = 'date-display';
            date.textContent = '';

            inner.appendChild(time);
            inner.appendChild(date);
            wrap.appendChild(inner);
            return wrap;
        }

        function createUserDropdown() {
            var userInfo = document.createElement('div');
            userInfo.className = 'user-info smns-injected-user-info';

            var dropdown = document.createElement('div');
            dropdown.className = 'user-dropdown';

            var button = document.createElement('button');
            button.className = 'user-dropdown-toggle';
            button.type = 'button';
            // Avoid duplicate dropdown bindings from navigation.js/main.js.
            // The injected dropdown manages its own toggle behavior.
            button.dataset.smnsDropdownBound = '1';

            var avatar = document.createElement('div');
            avatar.className = 'user-avatar';
            avatar.textContent = String(topbarData.initials || 'U');

            var meta = document.createElement('div');
            var strong = document.createElement('strong');
            strong.textContent = String(topbarData.display_name || 'User');
            var small = document.createElement('small');
            small.textContent = String(topbarData.subtitle || '');
            meta.appendChild(strong);
            meta.appendChild(document.createElement('br'));
            meta.appendChild(small);

            var arrow = document.createElement('i');
            arrow.className = 'dropdown-arrow';
            arrow.textContent = '▼';

            button.appendChild(avatar);
            button.appendChild(meta);
            button.appendChild(arrow);

            var menu = document.createElement('div');
            menu.className = 'user-dropdown-menu';

            (Array.isArray(topbarData.menu_items) ? topbarData.menu_items : []).forEach(function(item) {
                if (item && item.divider) {
                    var divider = document.createElement('div');
                    divider.className = 'dropdown-divider';
                    menu.appendChild(divider);
                    return;
                }

                if (!item || !item.href) {
                    return;
                }

                var link = document.createElement('a');
                link.className = 'dropdown-item' + (item.logout ? ' logout-item' : '');
                link.href = String(item.href);

                var icon = document.createElement('i');
                icon.className = String(item.icon || 'fas fa-circle');
                link.appendChild(icon);
                link.appendChild(document.createTextNode(' ' + String(item.label || 'Open')));
                menu.appendChild(link);
            });

            button.addEventListener('click', function(e) {
                e.stopPropagation();
                closeAllTopbarOverflows();
                var isActive = dropdown.classList.contains('active');
                document.querySelectorAll('.user-dropdown.active').forEach(function(openDropdown) {
                    openDropdown.classList.remove('active');
                    var openMenu = openDropdown.querySelector('.user-dropdown-menu');
                    if (openMenu) {
                        openMenu.classList.remove('show');
                    }
                });
                if (!isActive) {
                    dropdown.classList.add('active');
                    menu.classList.add('show');
                }
            });

            menu.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            dropdown.appendChild(button);
            dropdown.appendChild(menu);
            userInfo.appendChild(dropdown);
            return userInfo;
        }

        function ensureOverflowDropdown(standard) {
            var existing = standard.querySelector('.smns-topbar-overflow');
            if (existing) {
                return existing;
            }

            var wrap = document.createElement('div');
            wrap.className = 'smns-topbar-overflow';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'topbar-overflow-toggle';
            btn.setAttribute('aria-label', 'More actions');
            btn.innerHTML = '<i class="fas fa-ellipsis-v" aria-hidden="true"></i>';

            var menu = document.createElement('div');
            menu.className = 'topbar-overflow-menu';

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                // Close user dropdowns
                document.querySelectorAll('.user-dropdown.active').forEach(function(dropdown) {
                    dropdown.classList.remove('active');
                    var userMenu = dropdown.querySelector('.user-dropdown-menu');
                    if (userMenu) userMenu.classList.remove('show');
                });

                // Close notification dropdown if open
                var nd = document.getElementById('notificationDropdown');
                if (nd) nd.classList.remove('show');

                var isOpen = wrap.classList.contains('active');
                closeAllTopbarOverflows();
                if (!isOpen) {
                    wrap.classList.add('active');
                }
            });

            menu.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            wrap.appendChild(btn);
            wrap.appendChild(menu);

            // Insert before user dropdown if present; else append.
            var user = standard.querySelector('.user-dropdown') || standard.querySelector('.smns-injected-user-info');
            if (user && user.parentNode === standard) {
                standard.insertBefore(wrap, user);
            } else {
                standard.appendChild(wrap);
            }

            return wrap;
        }

        function syncTopbarOverflow(topbar) {
            if (String(topbarData.module) !== 'admin') {
                return;
            }

            var right = topbar.querySelector('.topbar-right');
            if (!(right instanceof HTMLElement)) {
                return;
            }

            var standard = right.querySelector('.smns-standard-topbar-actions');
            if (!(standard instanceof HTMLElement)) {
                return;
            }

            var overflowWrap = ensureOverflowDropdown(standard);
            var overflowMenu = overflowWrap.querySelector('.topbar-overflow-menu');
            var overflowBtn = overflowWrap.querySelector('.topbar-overflow-toggle');
            if (!(overflowMenu instanceof HTMLElement) || !(overflowBtn instanceof HTMLElement)) {
                return;
            }

            function restore() {
                while (overflowMenu.firstChild) {
                    var node = overflowMenu.firstChild;
                    overflowMenu.removeChild(node);
                    if (node && node.nodeType === 1 && overflowPlaceholders.has(node)) {
                        var ph = overflowPlaceholders.get(node);
                        if (ph && ph.parentNode) {
                            ph.parentNode.insertBefore(node, ph);
                            ph.parentNode.removeChild(ph);
                        } else {
                            right.insertBefore(node, standard);
                        }
                        overflowPlaceholders.delete(node);
                    } else {
                        right.insertBefore(node, standard);
                    }
                }
                overflowWrap.classList.remove('active');
                overflowWrap.style.display = 'none';
            }

            if (!isMobileTopbar()) {
                restore();
                return;
            }

            // Move any extra right-side items (excluding the standard actions container) into overflow menu.
            var extras = Array.prototype.slice.call(right.children).filter(function(el) {
                return el !== standard;
            });

            // Only move if there are extras.
            if (!extras.length) {
                restore();
                return;
            }

            extras.forEach(function(el) {
                if (!(el instanceof HTMLElement)) return;
                if (!overflowPlaceholders.has(el)) {
                    var placeholder = document.createComment('smns-overflow');
                    overflowPlaceholders.set(el, placeholder);
                    right.insertBefore(placeholder, el);
                }
                overflowMenu.appendChild(el);
            });

            overflowWrap.style.display = '';
        }

        function refreshInjectedDateTime() {
            var holder = document.querySelector('.smns-injected-topbar-time #current-date-time');
            if (!holder) {
                return;
            }

            var now = new Date();
            var hours = now.getHours();
            var minutes = now.getMinutes();
            var seconds = now.getSeconds();
            var ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;

            var timeEl = holder.querySelector('.time-display');
            var dateEl = holder.querySelector('.date-display');
            if (timeEl) {
                timeEl.textContent = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
            }
            if (dateEl) {
                dateEl.textContent = now.toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            }
        }

        function enhanceTopbar(topbar) {
            if (!(topbar instanceof HTMLElement) || topbar.classList.contains('smns-topbar-enhanced')) {
                return;
            }

            // Standardize the left side: ensure a sidebar toggle exists when a sidebar is present.
            // Many admin pages render a `.topbar-left` title but omit the toggle, which makes
            // the topbar feel inconsistent compared to the Dashboard.
            var left = topbar.querySelector('.topbar-left');
            var sidebarExists = !!(document.getElementById('sidebar') || document.querySelector('.sidebar'));
            if (left && sidebarExists && !document.getElementById('sidebarToggle') && !left.querySelector('.sidebar-toggle')) {
                var toggle = document.createElement('button');
                toggle.className = 'sidebar-toggle';
                toggle.id = 'sidebarToggle';
                toggle.type = 'button';
                toggle.title = 'Toggle Sidebar';
                toggle.innerHTML = '<i class="fas fa-bars"></i>';
                left.insertBefore(toggle, left.firstChild);
            }

            var right = topbar.querySelector('.topbar-right');
            if (!(right instanceof HTMLElement)) {
                right = document.createElement('div');
                right.className = 'topbar-right';
                topbar.appendChild(right);
            }

            var standard = right.querySelector('.smns-standard-topbar-actions');
            if (!(standard instanceof HTMLElement)) {
                standard = document.createElement('div');
                standard.className = 'smns-standard-topbar-actions';
                standard.style.display = 'flex';
                standard.style.alignItems = 'center';
                standard.style.gap = '16px';
                standard.style.flexWrap = 'wrap';
                right.appendChild(standard);
            }

            if (!right.querySelector('.topbar-time')) {
                standard.appendChild(createTimeBlock());
            }

            if (!right.querySelector('.user-dropdown')) {
                standard.appendChild(createUserDropdown());
            }

            // Admin mobile: move extra right-side items into a "More" dropdown.
            syncTopbarOverflow(topbar);

            topbar.classList.add('smns-topbar-enhanced');
        }

        document.querySelectorAll('.topbar').forEach(enhanceTopbar);
        document.querySelectorAll('.topbar').forEach(syncTopbarOverflow);
        refreshInjectedDateTime();
        window.setInterval(refreshInjectedDateTime, 1000);

        window.addEventListener('resize', function() {
            document.querySelectorAll('.topbar').forEach(syncTopbarOverflow);
        });

        document.addEventListener('click', function() {
            closeAllTopbarOverflows();
            document.querySelectorAll('.user-dropdown.active').forEach(function(dropdown) {
                dropdown.classList.remove('active');
                var menu = dropdown.querySelector('.user-dropdown-menu');
                if (menu) {
                    menu.classList.remove('show');
                }
            });
        });
    })();
    </script>
</body>
</html>
