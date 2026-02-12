<?php
/**
 * Admin Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-user-shield"></i>
        </div>
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Administration</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-section">User Management</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/students/list.php" class="<?php echo $currentDir == 'students' ? 'active' : ''; ?>" title="Students">
                    <i class="fas fa-user-graduate"></i> <span>Students</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/list.php" class="<?php echo $currentDir == 'lecturers' ? 'active' : ''; ?>" title="Lecturers">
                    <i class="fas fa-chalkboard-teacher"></i> <span>Lecturers</span>
                </a>
            </li>
            
            <li class="menu-section">Academic</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/courses/list.php" class="<?php echo $currentDir == 'courses' ? 'active' : ''; ?>" title="Courses">
                    <i class="fas fa-book"></i> <span>Courses</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/academic/programs.php" class="<?php echo $currentDir == 'academic' ? 'active' : ''; ?>" title="Programs">
                    <i class="fas fa-graduation-cap"></i> <span>Programs</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/academic/semesters.php" title="Semesters">
                    <i class="fas fa-calendar-alt"></i> <span>Semesters</span>
                </a>
            </li>
            
            <li class="menu-section">Operations</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/registrations/pending.php" class="<?php echo $currentDir == 'registrations' ? 'active' : ''; ?>" title="Registrations">
                    <i class="fas fa-clipboard-list"></i> <span>Registrations</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/results/submitted.php" class="<?php echo $currentDir == 'results' ? 'active' : ''; ?>" title="Results">
                    <i class="fas fa-chart-bar"></i> <span>Results</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/reports/students.php" class="<?php echo $currentDir == 'reports' ? 'active' : ''; ?>" title="Reports">
                    <i class="fas fa-file-alt"></i> <span>Reports</span>
                </a>
            </li>
            
            <li class="menu-section">System</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/announcements/list.php" class="<?php echo $currentDir == 'announcements' ? 'active' : ''; ?>" title="Announcements">
                    <i class="fas fa-bullhorn"></i> <span>Announcements</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/settings/general.php" class="<?php echo $currentDir == 'settings' ? 'active' : ''; ?>" title="Settings">
                    <i class="fas fa-cog"></i> <span>Settings</span>
                </a>
            </li>
            
            <li class="logout-item">
                <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="logout-link" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</div>
