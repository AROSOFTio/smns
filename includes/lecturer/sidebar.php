<?php
/**
 * Lecturer Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);

// Use existing session/auth/currentUser if already set by the page
if (!isset($session) || !is_object($session)) {
    $session = new Session('lecturer');
}
if (!isset($auth) || !is_object($auth)) {
    $auth = new Auth('lecturer');
}
if (!isset($currentUser) || !is_array($currentUser)) {
    $currentUser = $auth->getCurrentUser();
}
$lecturerProfile = $currentUser['profile'] ?? [];
?>
<style>
    .lecturer-sidebar-mobile-toggle {
        display: none;
    }

    @media (max-width: 992px) {
        body.smns-lecturer-mobile-shell .mobile-menu-btn {
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }

        .lecturer-sidebar {
            --mobile-sidebar-width: min(78vw, 260px);
            width: var(--mobile-sidebar-width) !important;
            max-width: var(--mobile-sidebar-width) !important;
            height: 100vh !important;
            height: 100dvh !important;
            max-height: 100dvh !important;
            left: calc(-1 * var(--mobile-sidebar-width)) !important;
            margin-left: 0 !important;
            top: 0 !important;
            z-index: 1400 !important;
            visibility: visible !important;
            display: flex !important;
            transform: none !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            box-shadow: 18px 0 40px rgba(15, 23, 42, 0.22) !important;
        }

        .lecturer-sidebar.active {
            left: 0 !important;
            margin-left: 0 !important;
            transform: translateX(0) !important;
            visibility: visible !important;
            display: flex !important;
        }

        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        .lecturer-sidebar-mobile-toggle {
            position: fixed;
            top: 9px;
            left: 10px;
            z-index: 1600;
            width: 34px;
            height: 34px;
            min-width: 34px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(15, 23, 42, 0.14);
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.18);
            padding: 0;
            font-size: 15px;
            line-height: 1;
            transition: left 0.25s ease, background 0.2s ease, color 0.2s ease;
        }

        body.smns-sidebar-open .lecturer-sidebar-mobile-toggle {
            left: calc(var(--mobile-sidebar-width, min(72vw, 260px)) + 8px);
            background: #0f172a;
            color: #ffffff;
        }
    }
</style>

<button
    type="button"
    class="lecturer-sidebar-mobile-toggle"
    id="lecturerSidebarMobileToggle"
    aria-label="Toggle lecturer sidebar"
    aria-controls="sidebar"
    aria-expanded="false"
>
    <span aria-hidden="true">&#9776;</span>
</button>

<div class="sidebar lecturer-sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Faculty Portal</small></p>

        <!-- Lecturer Profile Section -->
        <div class="sidebar-profile">
            <div class="sidebar-profile-avatar">
                <?php if (!empty($lecturerProfile['photo'])): ?>
                    <img src="<?php echo BASE_URL . '/' . $lecturerProfile['photo']; ?>" alt="Profile Photo">
                <?php else: ?>
                    <div class="sidebar-profile-initials">
                        <?php echo strtoupper(substr($lecturerProfile['first_name'], 0, 1) . substr($lecturerProfile['last_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="sidebar-profile-info">
                <div class="sidebar-profile-name"><?php echo e($lecturerProfile['first_name'] . ' ' . $lecturerProfile['last_name']); ?></div>
                <div class="sidebar-profile-id"><?php echo e($lecturerProfile['lecturer_id']); ?></div>
            </div>
        </div>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-section">Personal</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/profile.php" class="<?php echo $currentPage == 'profile.php' ? 'active' : ''; ?>" title="My Profile">
                    <i class="fas fa-user"></i> <span>My Profile</span>
                </a>
            </li>
            
            <li class="menu-section">Teaching</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/my-courses.php" class="<?php echo $currentPage == 'my-courses.php' ? 'active' : ''; ?>" title="My Courses">
                    <i class="fas fa-book"></i> <span>My Courses</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/class-list.php" class="<?php echo $currentPage == 'class-list.php' ? 'active' : ''; ?>" title="Class List">
                    <i class="fas fa-users"></i> <span>Class List</span>
                </a>
            </li>
            
            <li class="menu-section">Results</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php" class="<?php echo $currentPage == 'enter-results.php' ? 'active' : ''; ?>" title="Enter Results">
                    <i class="fas fa-edit"></i> <span>Enter Results</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/draft-results.php" class="<?php echo $currentPage == 'draft-results.php' ? 'active' : ''; ?>" title="Draft Results">
                    <i class="fas fa-save"></i> <span>Draft Results</span>
                </a>
            </li>
            
            <li class="menu-section">Reports</li>
             
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/reports.php" class="<?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>" title="Teaching Reports">
                    <i class="fas fa-chart-pie"></i> <span>Teaching Reports</span>
                </a>
            </li>
            
            <li class="logout-item">
                <a href="<?php echo BASE_URL; ?>/views/lecturer/logout.php" class="logout-link" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.classList.add('smns-lecturer-mobile-shell');
    document.querySelectorAll('.mobile-menu-btn').forEach(function(button) {
        button.remove();
    });

    var sidebar = document.getElementById('sidebar');
    var topbar = document.querySelector('.main-content .topbar');
    var mobileToggle = document.getElementById('lecturerSidebarMobileToggle');
    var overlay = document.querySelector('.sidebar-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    function isMobileSidebar() {
        return window.innerWidth <= 992;
    }

    function syncLecturerSidebarToggle() {
        if (!sidebar || !mobileToggle) return;
        var isOpen = sidebar.classList.contains('active') && isMobileSidebar();
        mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        mobileToggle.innerHTML = isOpen
            ? '<span aria-hidden="true">&times;</span>'
            : '<span aria-hidden="true">&#9776;</span>';
    }

    function closeLecturerSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('active');
        sidebar.classList.remove('collapsed');
        overlay.classList.remove('active');
        document.body.classList.remove('smns-sidebar-open');
        document.body.style.overflow = '';
        syncLecturerSidebarToggle();
    }

    function openLecturerSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('active');
        sidebar.classList.remove('collapsed');
        overlay.classList.add('active');
        document.body.classList.add('smns-sidebar-open');
        document.body.style.overflow = 'hidden';
        syncLecturerSidebarToggle();
    }

    if (mobileToggle && sidebar && mobileToggle.dataset.smnsLecturerSidebarBound !== '1') {
        mobileToggle.dataset.smnsLecturerSidebarBound = '1';
        mobileToggle.addEventListener('click', function(e) {
            if (!isMobileSidebar()) return;
            e.preventDefault();
            e.stopPropagation();
            if (sidebar.classList.contains('active')) {
                closeLecturerSidebar();
            } else {
                openLecturerSidebar();
            }
        });
    }

    overlay.addEventListener('click', closeLecturerSidebar);
    overlay.addEventListener('touchstart', closeLecturerSidebar, { passive: true });

    document.querySelectorAll('.lecturer-sidebar a[href]').forEach(function(link) {
        link.addEventListener('click', function() {
            if (isMobileSidebar()) closeLecturerSidebar();
        });
    });

    window.addEventListener('resize', function() {
        if (!isMobileSidebar()) closeLecturerSidebar();
        syncLecturerSidebarToggle();
    });

    if (window.MutationObserver && sidebar) {
        new MutationObserver(syncLecturerSidebarToggle).observe(sidebar, {
            attributes: true,
            attributeFilter: ['class']
        });
    }

    syncLecturerSidebarToggle();

    if (!sidebar || !topbar || document.getElementById('sidebarToggle')) return;

    var left = topbar.querySelector('.topbar-left');
    if (!left) {
        left = document.createElement('div');
        left.className = 'topbar-left';
        while (topbar.firstChild) {
            left.appendChild(topbar.firstChild);
        }
        topbar.appendChild(left);
    }

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'sidebar-toggle';
    toggle.id = 'sidebarToggle';
    toggle.title = 'Toggle Sidebar';
    toggle.setAttribute('aria-label', 'Toggle Sidebar');
    toggle.innerHTML = '<i class="fas fa-bars"></i>';
    left.insertBefore(toggle, left.firstChild);
});
</script>
