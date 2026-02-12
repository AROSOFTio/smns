<?php
/**
 * Lecturer Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar lecturer-sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-chalkboard-teacher"></i>
        </div>
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Faculty Portal</small></p>
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
            
            <li class="menu-section">Results</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/enter-results.php" class="<?php echo $currentPage == 'enter-results.php' ? 'active' : ''; ?>" title="Enter Results">
                    <i class="fas fa-edit"></i> <span>Enter Results</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/view-results.php" class="<?php echo $currentPage == 'view-results.php' ? 'active' : ''; ?>" title="View Results">
                    <i class="fas fa-eye"></i> <span>View Results</span>
                </a>
            </li>
            
            <li class="menu-section">Reports</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/lecturer/reports.php" class="<?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>" title="Reports">
                    <i class="fas fa-chart-pie"></i> <span>Reports</span>
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
