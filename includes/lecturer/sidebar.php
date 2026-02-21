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
