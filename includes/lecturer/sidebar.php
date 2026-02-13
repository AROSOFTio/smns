<?php
/**
 * Lecturer Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);

// Get current lecturer profile for sidebar
$session = new Session('lecturer');
$auth = new Auth('lecturer');
$currentUser = $auth->getCurrentUser();
$lecturerProfile = $currentUser['profile'];
?>
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
