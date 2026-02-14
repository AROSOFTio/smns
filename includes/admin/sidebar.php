<?php
/**
 * Admin Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
$activeSettingsTab = $_GET['tab'] ?? ''; // used to highlight specific settings tabs

// Get current admin profile for sidebar
$session = new Session('admin');
$auth = new Auth('admin');
$currentUser = $auth->getCurrentUser();
$adminProfile = $currentUser['profile'];

// Get initials (disabled in sidebar)
$initials = '';
$fullName = trim(($adminProfile['first_name'] ?? '') . ' ' . ($adminProfile['last_name'] ?? ''));
?>

<style>
/* Modern White-Grey Sidebar */
.sidebar {
    width: 260px;
    height: 100vh;
    background: #f8f9fa;
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 2px 0 10px rgba(0,0,0,0.08);
    z-index: 1000;
    transition: all 0.3s ease;
    border-right: 1px solid #e5e7eb;
}

.sidebar::-webkit-scrollbar {
    width: 5px;
}

.sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.sidebar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Sidebar Header */
.sidebar-header {
    padding: 20px 15px 15px;
    text-align: center;
    background: #ffffff;
    border-bottom: 2px solid #e5e7eb;
}

.sidebar-header h3 {
    color: #1e293b;
    margin: 0 0 3px 0;
    font-size: 20px;
    font-weight: 700;
}

.sidebar-header p {
    color: #64748b;
    margin: 0;
    font-size: 11px;
    font-weight: 500;
}



/* Sidebar Menu */
.sidebar-menu {
    padding: 12px 0 80px 0;
}

.sidebar-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    margin: 0;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 10px 15px;
    color: #475569;
    text-decoration: none;
    transition: all 0.2s ease;
    position: relative;
}

.sidebar-menu a:hover {
    background: #e0f2fe;
    color: #0369a1;
    padding-left: 18px;
}

.sidebar-menu a.active {
    background: #dbeafe;
    color: #0369a1;
    font-weight: 600;
    border-left: 3px solid #3b82f6;
}

.sidebar-menu a i {
    width: 18px;
    margin-right: 10px;
    text-align: center;
    font-size: 15px;
}

.sidebar-menu a span {
    flex: 1;
    font-size: 13px;
}

/* Settings submenu — high-contrast text; grey on hover/touch */
.submenu-menu li a {
    color: #0f172a; /* near-black for high contrast */
    font-weight: 700;
    background: transparent;
    transition: color 0.15s ease, background 0.15s ease;
}
.submenu-menu li a i {
    color: #0f172a;
}
.submenu-menu li a:hover,
.submenu-menu li a:focus,
.submenu-menu li a:active {
    color: #6b7280; /* gray on touch/hover */
    background: #f3f4f6; /* subtle grey background */
}
.submenu-menu li a.active {
    color: #0f172a; /* keep high contrast when active */
    background: #e6f2ff; /* subtle active background */
    font-weight: 800;
}

/* System section & items (always visible, blue -> grey on hover) */
.system-section {
    color: #1d4ed8;
    font-weight: 800;
}
.system-item {
    color: #1d4ed8;
    font-weight: 600;
    transition: color 0.15s ease, background 0.15s ease;
}
.system-item i {
    color: #1d4ed8;
}
.system-item:hover,
.system-item:focus,
.system-item:active {
    color: #6b7280;
    background: #f3f4f6;
}
.system-item.active {
    color: #1e40af;
    background: #e6f2ff;
}

/* Menu Sections */
.menu-section {
    color: #94a3b8;
    padding: 12px 15px 6px;
    font-size: 10px;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.8px;
    border-top: 1px solid #e5e7eb;
    margin-top: 8px;
}

.menu-section:first-child {
    margin-top: 0;
    border-top: none;
}

/* Logout Item */
.logout-item {
    margin-top: 15px;
    border-top: 2px solid #e5e7eb;
    padding-top: 8px;
}

.logout-link {
    color: #dc2626 !important;
}

.logout-link:hover {
    background: #fee2e2 !important;
    color: #991b1b !important;
}

/* Compact-fit helper (reduces paddings/fonts so everything fits) */
.sidebar.compact-fit .sidebar-header { padding: 8px 12px; }
.sidebar.compact-fit .sidebar-header h3 { font-size: 16px; }
.sidebar.compact-fit .sidebar-header p { font-size: 10px; }
.sidebar.compact-fit .sidebar-menu { padding: 4px 0 40px 0; }
.sidebar.compact-fit .sidebar-menu a { padding: 6px 12px; font-size: 13px; }
.sidebar.compact-fit .sidebar-menu a i { font-size: 14px; margin-right: 8px; }
.sidebar.compact-fit .menu-section { padding: 8px 12px 4px; font-size: 9px; }
.sidebar.compact-fit .logout-item { padding-top: 6px; }

/* Responsive */
@media (max-width: 768px) {
    /* Keep sidebar fully visible on small screens per user request */
    .sidebar {
        width: 280px; /* show full labels */
    }

    .sidebar-header h3,
    .sidebar-header p,
    .menu-section,
    .sidebar-menu a span {
        display: block; /* always show labels */
    }

    .sidebar-profile {
        padding: 10px 12px;
    }

    .sidebar-profile-avatar {
        width: 48px;
        height: 48px;
        margin: 0 auto;
    }

    .sidebar-profile-initials {
        width: 48px;
        height: 48px;
        font-size: 16px;
    }

    .sidebar-menu a {
        justify-content: flex-start;
        padding: 10px 14px;
    }

    .sidebar-menu a i {
        margin-right: 10px;
        font-size: 16px;
    }

    /* Disable hover-expand behavior on mobile */
    .sidebar:hover {
        width: 280px;
    }

    .sidebar:hover .sidebar-profile,
    .sidebar:hover .sidebar-profile-avatar,
    .sidebar:hover .sidebar-profile-initials,
    .sidebar:hover .sidebar-menu a {
        /* same as non-hover; no change */
    }
}
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p>Administration</p>
    </div>
    

    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> 
                    <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-section">User Management</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/students/list.php" class="<?php echo $currentDir == 'students' ? 'active' : ''; ?>">
                    <i class="fas fa-user-graduate"></i> 
                    <span>Students</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/list.php" class="<?php echo $currentDir == 'lecturers' ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i> 
                    <span>Lecturers</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/approvals.php" class="<?php echo $currentPage == 'approvals.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i> 
                    <span>Approvals</span>
                </a>
            </li>
            
            <li class="menu-section">Academic</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/courses/list.php" class="<?php echo $currentDir == 'courses' ? 'active' : ''; ?>">
                    <i class="fas fa-book"></i> 
                    <span>Courses</span>
                </a>
            </li>
            
            <li class="menu-section">Operations</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/registrations/pending.php" class="<?php echo $currentDir == 'registrations' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i> 
                    <span>Registrations</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/results/submitted.php" class="<?php echo $currentDir == 'results' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> 
                    <span>Results</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/reports/index.php" class="<?php echo $currentDir == 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> 
                    <span>Reports</span>
                </a>
            </li>
            
            <li class="menu-section system-section">System</li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/email-test.php" class="<?php echo ($currentPage == 'email-test.php' ? 'active' : '') ?> system-item">
                    <i class="fas fa-envelope"></i> 
                    <span>Email Test</span>
                </a>
            </li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/system/health.php" class="<?php echo ($currentPage == 'health.php' ? 'active' : '') ?> system-item">
                    <i class="fas fa-heartbeat"></i>
                    <span>System Health</span>
                </a>
            </li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/settings/index.php" class="<?php echo ($currentDir == 'settings' ? 'active' : '') ?> system-item">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </li>
            
            <li class="logout-item">
                <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i> 
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</div>

<script>
// Ensure sidebar content fits the viewport — apply compact styles when necessary
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    function ensureFits() {
        // reset then measure
        sidebar.classList.remove('compact-fit');
        // if the sidebar height exceeds viewport, apply compact-fit
        if (sidebar.scrollHeight > window.innerHeight) {
            sidebar.classList.add('compact-fit');
        }
    }

    // initial check
    ensureFits();
    // re-check on resize
    window.addEventListener('resize', ensureFits);

    // re-check when dropdowns toggle (menu height may change)
    document.querySelectorAll('.sidebar-menu a').forEach(function(el) {
        el.addEventListener('click', function() {
            // small delay for DOM changes
            setTimeout(ensureFits, 150);
        });
    });
});
</script>