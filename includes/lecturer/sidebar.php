<?php
/**
 * Lecturer Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo BASE_URL; ?>/assets/images/logo-small.png" alt="Logo" style="max-width: 60px;" onerror="this.style.display='none'">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Lecturer Portal</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i>📊</i> Dashboard
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/profile.php">
                    <i>👤</i> My Profile
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/my-courses.php">
                    <i>📚</i> My Courses
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php">
                    <i>✏️</i> Enter Results
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/view-results.php">
                    <i>👁️</i> View Results
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/reports.php">
                    <i>📑</i> Reports
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
