<?php
/**
 * Admin Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo BASE_URL; ?>/assets/images/logo-small.png" alt="Logo" style="max-width: 60px;" onerror="this.style.display='none'">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Admin Panel</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i>📊</i> Dashboard
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/students/list.php">
                    <i>👨‍🎓</i> Students
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/list.php">
                    <i>👨‍🏫</i> Lecturers
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/courses/list.php">
                    <i>📚</i> Courses
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/academic/programs.php">
                    <i>🎓</i> Programs
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/academic/semesters.php">
                    <i>📅</i> Semesters
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/registrations/pending.php">
                    <i>📝</i> Registrations
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/results/submitted.php">
                    <i>📊</i> Results
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/reports/students.php">
                    <i>📑</i> Reports
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/announcements/list.php">
                    <i>📢</i> Announcements
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/settings/general.php">
                    <i>⚙️</i> Settings
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/auth/logout.php">
                    <i>🚪</i> Logout
                </a>
            </li>
        </ul>
    </div>
</div>
