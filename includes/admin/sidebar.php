<?php
/**
 * Admin Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>

<style>
/* Modern Smart Sidebar */
.sidebar {
    width: 280px;
    height: 100vh;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 4px 0 20px rgba(0,0,0,0.12), 0 0 0 1px rgba(255,255,255,0.05);
    z-index: 1000;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-right: 1px solid #e2e8f0;
    backdrop-filter: blur(10px);
}

.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(0,0,0,0.05);
    border-radius: 10px;
}

.sidebar::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #cbd5e1 0%, #94a3b8 100%);
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.3);
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
}

/* Sidebar Header */
.sidebar-header {
    padding: 25px 20px 20px;
    text-align: center;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-bottom: 2px solid #e2e8f0;
    position: relative;
}

.sidebar-header::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 20px;
    right: 20px;
    height: 1px;
    background: linear-gradient(90deg, transparent 0%, #3b82f6 50%, transparent 100%);
}

.sidebar-header h3 {
    color: #1e293b;
    margin: 0 0 5px 0;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.5px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.sidebar-header p {
    color: #64748b;
    margin: 0;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Sidebar Menu */
.sidebar-menu {
    padding: 25px 0 80px 0;
}

.sidebar-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    margin: 0;
    position: relative;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: #475569;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    border-radius: 0 25px 25px 0;
    margin: 2px 0;
    font-weight: 500;
}

.sidebar-menu a:hover {
    background: linear-gradient(90deg, #e0f2fe 0%, #bae6fd 100%);
    color: #0369a1;
    padding-left: 25px;
    transform: translateX(3px);
    box-shadow: 0 4px 12px rgba(3, 105, 161, 0.15);
}

.sidebar-menu a.active {
    background: linear-gradient(90deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1d4ed8;
    font-weight: 700;
    border-left: 4px solid #3b82f6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
}
}

.sidebar-menu a i {
    width: 20px;
    margin-right: 12px;
    text-align: center;
    font-size: 18px;
    opacity: 0.8;
    transition: all 0.3s ease;
}

.sidebar-menu a:hover i {
    opacity: 1;
    transform: scale(1.1);
}

.sidebar-menu a span {
    flex: 1;
    font-size: 15px;
    font-weight: 500;
}

/* Menu Sections */
.menu-section {
    color: #64748b;
    padding: 15px 20px 8px;
    font-size: 12px;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 1.2px;
    border-top: 1px solid #e2e8f0;
    margin-top: 15px;
    position: relative;
    background: linear-gradient(90deg, rgba(255,255,255,0.8) 0%, rgba(248,250,252,0.8) 100%);
}

.menu-section:first-child {
    margin-top: 0;
    border-top: none;
}

.menu-section::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 12px;
    background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%);
    border-radius: 2px;
}

/* Dropdown Menu Styles */
.dropdown {
    position: relative;
}

.dropdown-toggle {
    cursor: pointer;
    position: relative;
}

.dropdown-toggle::after {
    content: '\f107';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    right: 20px;
    font-size: 14px;
    color: #64748b;
    transition: all 0.3s ease;
    opacity: 0.7;
}

.dropdown.open .dropdown-toggle::after {
    transform: rotate(180deg);
    opacity: 1;
    color: #3b82f6;
}

.dropdown-menu {
    display: none;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 0 0 12px 12px;
    margin: 0;
    padding: 5px 0;
    list-style: none;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid #e2e8f0;
    border-top: none;
}

.dropdown.open .dropdown-menu {
    display: block;
}

.dropdown-menu li a {
    padding: 10px 20px 10px 45px;
    font-size: 14px;
    background: transparent;
    border-left: none;
    margin: 1px 5px;
    border-radius: 0 20px 20px 0;
    font-weight: 500;
}

.dropdown-menu li a:hover {
    background: linear-gradient(90deg, #e0f2fe 0%, #bae6fd 100%);
    padding-left: 50px;
    box-shadow: 0 2px 8px rgba(3, 105, 161, 0.1);
}

.dropdown-menu li a i {
    width: 16px;
    font-size: 14px;
    margin-right: 10px;
}

/* Submenu Styles */
.submenu {
    position: relative;
}

.submenu-toggle::after {
    content: '\f105';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    right: 20px;
    font-size: 14px;
    color: #64748b;
    transition: all 0.3s ease;
    opacity: 0.7;
}

.submenu.open .submenu-toggle::after {
    transform: rotate(90deg);
    opacity: 1;
    color: #3b82f6;
}

.submenu-menu {
    display: none;
    background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
    margin: 0;
    padding: 5px 0;
    list-style: none;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 12px 12px;
}

.submenu.open .submenu-menu {
    display: block;
}

.submenu-menu li a {
    padding: 8px 20px 8px 55px;
    font-size: 13px;
    background: transparent;
    border-left: none;
    margin: 1px 5px;
    border-radius: 0 18px 18px 0;
    font-weight: 500;
}

.submenu-menu li a:hover {
    background: linear-gradient(90deg, #e0f2fe 0%, #bae6fd 100%);
    padding-left: 60px;
    box-shadow: 0 2px 6px rgba(3, 105, 161, 0.1);
}

.submenu-menu li a i {
    width: 14px;
    font-size: 13px;
    margin-right: 8px;
}

/* Logout Item */
.logout-item {
    margin-top: 20px;
    border-top: 2px solid #e2e8f0;
    padding-top: 10px;
    position: relative;
}

.logout-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 20px;
    right: 20px;
    height: 1px;
    background: linear-gradient(90deg, transparent 0%, #ef4444 50%, transparent 100%);
}

.logout-link {
    color: #dc2626 !important;
    font-weight: 600 !important;
}

.logout-link:hover {
    background: linear-gradient(90deg, #fee2e2 0%, #fecaca 100%) !important;
    color: #991b1b !important;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15) !important;
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        width: 70px;
    }

    .sidebar-menu a span {
        display: none;
    }

    .dropdown-toggle::after,
    .submenu-toggle::after {
        display: none;
    }

    .sidebar-menu a {
        justify-content: center;
        padding: 14px;
    }

    .sidebar-menu a i {
        margin-right: 0;
        font-size: 20px;
    }

    .sidebar:hover {
        width: 280px;
    }

    .sidebar:hover .sidebar-header h3,
    .sidebar:hover .sidebar-header p,
    .sidebar:hover .menu-section,
    .sidebar:hover .sidebar-menu a span {
        display: block;
    }

    .sidebar:hover .sidebar-menu a {
        justify-content: flex-start;
        padding: 12px 20px;
    }

    .sidebar:hover .sidebar-menu a i {
        margin-right: 12px;
    }

    .sidebar:hover .dropdown-menu,
    .sidebar:hover .submenu-menu {
        display: block;
    }

    .sidebar:hover .dropdown-toggle::after,
    .sidebar:hover .submenu-toggle::after {
        display: inline-block;
    }
}

/* Additional Modern Effects */
.sidebar-menu a::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 2px;
    background: linear-gradient(90deg, #3b82f6 0%, #1d4ed8 100%);
    transition: width 0.3s ease;
    border-radius: 1px;
}

.sidebar-menu a:hover::before {
    width: 4px;
}

.dropdown-menu li a::before,
.submenu-menu li a::before {
    content: '';
    position: absolute;
    left: 25px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 2px;
    background: linear-gradient(90deg, #3b82f6 0%, #1d4ed8 100%);
    transition: width 0.3s ease;
    border-radius: 1px;
}

.dropdown-menu li a:hover::before,
.submenu-menu li a:hover::before {
    width: 6px;
}

/* Smooth animations for all elements */
* {
    box-sizing: border-box;
}

.sidebar * {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
        display: inline-block;
    }
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
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle <?php echo ($currentDir == 'lecturers' || $currentPage == 'approvals.php') ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i> 
                    <span>Lecturers</span>
                </a>
                <ul class="dropdown-menu">
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/list.php" class="<?php echo ($currentDir == 'lecturers' && $currentPage != 'approvals.php') ? 'active' : ''; ?>">
                            <i class="fas fa-list"></i> 
                            <span>Manage Lecturers</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/approvals.php" class="<?php echo $currentPage == 'approvals.php' ? 'active' : ''; ?>">
                            <i class="fas fa-user-check"></i> 
                            <span>Approvals</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <li class="menu-section">Academic Management</li>
            
            <li class="dropdown">
                <a href="#" class="dropdown-toggle <?php echo ($currentDir == 'courses' || strpos($_SERVER['REQUEST_URI'], 'semesters') !== false) ? 'active' : ''; ?>">
                    <i class="fas fa-book"></i> 
                    <span>Courses</span>
                </a>
                <ul class="dropdown-menu">
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/courses/list.php" class="<?php echo ($currentDir == 'courses' && strpos($_SERVER['REQUEST_URI'], 'semesters') === false) ? 'active' : ''; ?>">
                            <i class="fas fa-list"></i> 
                            <span>Manage Courses</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/academic/semesters.php" class="<?php echo strpos($_SERVER['REQUEST_URI'], 'semesters') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i> 
                            <span>Semesters</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <li class="menu-section">Academic Operations</li>
            
            <li class="submenu">
                <a href="#" class="submenu-toggle <?php echo ($currentDir == 'results' || $currentDir == 'registrations') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> 
                    <span>Results</span>
                </a>
                <ul class="submenu-menu">
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/results/submitted.php" class="<?php echo ($currentDir == 'results' && $currentPage != 'pending.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i> 
                            <span>View Results</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/registrations/pending.php" class="<?php echo $currentDir == 'registrations' ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list"></i> 
                            <span>Registration</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <li class="menu-section">Reports & System</li>
            
            <li class="submenu">
                <a href="#" class="submenu-toggle <?php echo ($currentDir == 'settings' || $currentDir == 'reports') ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> 
                    <span>Settings</span>
                </a>
                <ul class="submenu-menu">
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/settings/general.php" class="<?php echo ($currentDir == 'settings' && $currentPage != 'students.php') ? 'active' : ''; ?>">
                            <i class="fas fa-sliders-h"></i> 
                            <span>General Settings</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/views/admin/reports/students.php" class="<?php echo $currentDir == 'reports' ? 'active' : ''; ?>">
                            <i class="fas fa-file-alt"></i> 
                            <span>Reports</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/email-test.php" class="<?php echo $currentPage == 'email-test.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> 
                    <span>Email Test</span>
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
// Dropdown functionality
document.addEventListener('DOMContentLoaded', function() {
    // Dropdown toggle functionality
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const dropdown = this.closest('.dropdown');
            dropdown.classList.toggle('open');
        });
    });

    // Submenu toggle functionality
    const submenuToggles = document.querySelectorAll('.submenu-toggle');
    submenuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const submenu = this.closest('.submenu');
            submenu.classList.toggle('open');
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown') && !e.target.closest('.submenu')) {
            document.querySelectorAll('.dropdown.open, .submenu.open').forEach(open => {
                open.classList.remove('open');
            });
        }
    });
});
</script>