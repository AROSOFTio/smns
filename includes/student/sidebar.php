<?php
/**
 * Student Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar student-sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Student Portal</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-section">Personal</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/profile.php" class="<?php echo $currentPage == 'profile.php' ? 'active' : ''; ?>" title="My Profile">
                    <i class="fas fa-user"></i> <span>My Profile</span>
                </a>
            </li>
            
            <li class="menu-section">Academic</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="<?php echo $currentPage == 'course-registration.php' ? 'active' : ''; ?>" title="Course Registration">
                    <i class="fas fa-clipboard-list"></i> <span>Course Registration</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/my-courses.php" class="<?php echo $currentPage == 'my-courses.php' ? 'active' : ''; ?>" title="My Courses">
                    <i class="fas fa-book-open"></i> <span>My Courses</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/results.php" class="<?php echo $currentPage == 'results.php' ? 'active' : ''; ?>" title="My Results">
                    <i class="fas fa-chart-line"></i> <span>My Results</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/transcript.php" class="<?php echo $currentPage == 'transcript.php' ? 'active' : ''; ?>" title="Transcript">
                    <i class="fas fa-file-alt"></i> <span>Transcript</span>
                </a>
            </li>
            
            <li class="menu-section">Financial</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/fees.php" class="<?php echo $currentPage == 'fees.php' ? 'active' : ''; ?>" title="Fees & Invoices">
                    <i class="fas fa-file-invoice-dollar"></i> <span>Fees & Invoices</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/payments.php" class="<?php echo $currentPage == 'payments.php' ? 'active' : ''; ?>" title="Payment History">
                    <i class="fas fa-credit-card"></i> <span>Payment History</span>
                </a>
            </li>
            
            <li class="menu-section">Updates</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/notifications.php" class="<?php echo $currentPage == 'notifications.php' ? 'active' : ''; ?>" title="Notifications">
                    <i class="fas fa-bell"></i> <span>Notifications</span>
                </a>
            </li>
            
            <li class="logout-item">
                <a href="<?php echo BASE_URL; ?>/views/student/logout.php" class="logout-link" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</div>
