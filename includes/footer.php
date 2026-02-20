<?php
/**
 * Common Footer
 */
?>
    </div><!-- .wrapper -->
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/navigation.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach($additionalJS as $js): ?>
            <script src="<?php echo BASE_URL . '/assets/js/' . $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Session Inactivity Timeout Checker -->
    <script>
    (function() {
        var TIMEOUT_MS = 10 * 60 * 1000; // 10 minutes
        var WARNING_MS = 60 * 1000;       // warn 1 minute before
        var lastActivity = Date.now();
        var warned = false;
        var warningModal = null;

        // Detect current module from URL path
        function getModuleLoginUrl() {
            var path = window.location.pathname;
            var modules = ['admin', 'student', 'lecturer', 'finance'];
            for (var i = 0; i < modules.length; i++) {
                if (path.indexOf('/views/' + modules[i] + '/') !== -1) {
                    return '<?php echo BASE_URL; ?>/views/' + modules[i] + '/login.php?error=session_expired';
                }
            }
            return '<?php echo BASE_URL; ?>/views/auth/login.php?error=session_expired';
        }

        // Reset timer on any user interaction
        function resetTimer() {
            lastActivity = Date.now();
            warned = false;
            hideWarning();
        }

        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function(evt) {
            document.addEventListener(evt, resetTimer, { passive: true });
        });

        // Warning modal
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
                // Ping server to refresh session
                fetch(window.location.href, { method: 'HEAD', cache: 'no-store' }).catch(function(){});
            });
        }

        function hideWarning() {
            if (warningModal) {
                warningModal.remove();
                warningModal = null;
            }
        }

        // Check every 5 seconds
        setInterval(function() {
            var elapsed = Date.now() - lastActivity;
            var remaining = TIMEOUT_MS - elapsed;

            if (remaining <= 0) {
                // Expired — redirect to login
                window.location.href = getModuleLoginUrl();
                return;
            }

            if (remaining <= WARNING_MS && !warned) {
                warned = true;
                showWarning();
            }

            // Update countdown
            var cd = document.getElementById('stoCountdown');
            if (cd && remaining > 0) {
                cd.textContent = Math.ceil(remaining / 1000);
            }
        }, 5000);
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

    /* Global Theme Toggle */
    #themeToggleBtn {
        position: fixed;
        right: 22px;
        bottom: 22px;
        width: 48px;
        height: 48px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        color: #222;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        z-index: 10050;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
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
        --app-surface-2: #f8fafc;
        --app-border: #e5e7eb;
    }
    html[data-theme='dark'] {
        --app-bg: #0b1220;
        --app-text: #e5e7eb;
        --app-muted: #9ca3af;
        --app-surface: #111827;
        --app-surface-2: #1f2937;
        --app-border: #334155;
    }
    html[data-theme='dark'] body {
        background: var(--app-bg) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .sidebar,
    html[data-theme='dark'] .student-sidebar,
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
    html[data-theme='dark'] .dropdown-menu,
    html[data-theme='dark'] #profileDropMenu {
        background: var(--app-surface) !important;
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
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
    html[data-theme='dark'] .badge {
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
        color: var(--app-text) !important;
        border-color: var(--app-border) !important;
    }
    html[data-theme='dark'] .alert-light {
        background: var(--app-surface-2) !important;
        color: var(--app-text) !important;
    }
    html[data-theme='dark'] .alert-secondary {
        background: #1f2937 !important;
        color: #e5e7eb !important;
    }
    html[data-theme='dark'] .text-white,
    html[data-theme='dark'] .text-white * {
        color: #ffffff !important;
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
        if (!btn) return;

        function applyTheme(mode) {
            if (mode === 'dark') {
                root.setAttribute('data-theme', 'dark');
                btn.innerHTML = '<i class="fas fa-sun" aria-hidden="true"></i>';
                btn.setAttribute('title', 'Switch to Light Mode');
                btn.setAttribute('aria-label', 'Switch to Light Mode');
            } else {
                root.removeAttribute('data-theme');
                btn.innerHTML = '<i class="fas fa-moon" aria-hidden="true"></i>';
                btn.setAttribute('title', 'Switch to Dark Mode');
                btn.setAttribute('aria-label', 'Switch to Dark Mode');
            }
        }

        var saved = localStorage.getItem(STORAGE_KEY);
        var initial = (saved === 'dark' || saved === 'light') ? saved : 'light';
        applyTheme(initial);

        btn.addEventListener('click', function() {
            var current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem(STORAGE_KEY, next);
            applyTheme(next);
        });
    })();
    </script>
</body>
</html>
