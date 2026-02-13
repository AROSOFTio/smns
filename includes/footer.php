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
    </style>
</body>
</html>
