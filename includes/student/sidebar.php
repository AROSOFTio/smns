<?php
/**
 * Student Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);

// Use existing session/auth/currentUser if already set by the page
if (!isset($session) || !is_object($session)) {
    $session = new Session('student');
}
if (!isset($auth) || !is_object($auth)) {
    $auth = new Auth('student');
}
if (!isset($currentUser) || !is_array($currentUser)) {
    $currentUser = $auth->getCurrentUser();
}
$studentProfile = $currentUser['profile'] ?? [];
?>
<div class="sidebar student-sidebar" id="sidebar">
    <div class="sidebar-header">
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
            
            <li class="menu-section">Academic</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/course-registration.php" class="<?php echo $currentPage == 'course-registration.php' ? 'active' : ''; ?>" title="Semester Registration">
                    <i class="fas fa-clipboard-list"></i> <span>Semester Registration</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/provisional-results.php" class="<?php echo $currentPage == 'provisional-results.php' ? 'active' : ''; ?>" title="My Provisional Results">
                    <i class="fas fa-chart-line"></i> <span>My Provisional Results</span>
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
                <a href="<?php echo BASE_URL; ?>/views/student/notifications.php" class="<?php echo $currentPage == 'notifications.php' ? 'active' : ''; ?>" title="Notification Center">
                    <i class="fas fa-bell"></i> <span>Notification Center</span>
                </a>
            </li>

            <li class="menu-section">Account</li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/student/change-password.php" class="<?php echo $currentPage == 'change-password.php' ? 'active' : ''; ?>" title="Change Password">
                    <i class="fas fa-key"></i> <span>Change Password</span>
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

<script>
// Auto-scroll student sidebar so the active link is visible (improves visibility on short viewports)
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    function scrollActive() {
        var active = sidebar.querySelector('.sidebar-menu a.active');
        if (active && active.scrollIntoView) {
            try { active.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(e) {}
        }
    }
    // initial, and on interactions
    setTimeout(scrollActive, 120);
    window.addEventListener('resize', scrollActive);
    document.querySelectorAll('.sidebar-menu a').forEach(function(el){
        el.addEventListener('click', function(){ setTimeout(scrollActive, 150); });
    });
});
</script>
