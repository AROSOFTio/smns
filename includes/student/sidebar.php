<?php
/**
 * Student Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo BASE_URL; ?>/assets/images/logo-small.png" alt="Logo" style="max-width: 60px;" onerror="this.style.display='none'">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Student Portal</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i>📊</i> Dashboard
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/profile.php">
                    <i>👤</i> My Profile
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php">
                    <i>📝</i> Course Registration
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/my-courses.php">
                    <i>📚</i> My Courses
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/results.php">
                    <i>📊</i> My Results
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/transcript.php">
                    <i>📄</i> Transcript
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/fees.php">
                    <i>💰</i> Fees & Invoices
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/payments.php">
                    <i>💳</i> Payment History
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/notifications.php">
                    <i>🔔</i> Notifications
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
