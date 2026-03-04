<?php
/**
 * Finance Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentSection = trim((string)($_GET['section'] ?? ''));

if ($currentPage !== 'dashboard.php') {
    $currentSection = '';
}

$isDashboardHome = $currentPage === 'dashboard.php' && $currentSection === '';
?>
<div class="sidebar finance-sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Finance Portal</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php" class="<?php echo $isDashboardHome ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/fee-structures.php" class="<?php echo $currentPage === 'fee-structures.php' ? 'active' : ''; ?>" title="Fee Structures">
                    <i class="fas fa-sitemap"></i> <span>Fee Structures</span>
                </a>
            </li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/student-profile.php" class="<?php echo $currentPage === 'student-profile.php' ? 'active' : ''; ?>" title="Student Financial Profile">
                    <i class="fas fa-user-graduate"></i> <span>Student Profiles</span>
                </a>
            </li>
             
            <li class="menu-section">Operations</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=record-payment#record-payment-section" class="<?php echo $currentSection === 'record-payment' ? 'active' : ''; ?>" title="Record Payment">
                    <i class="fas fa-plus-circle"></i> <span>Record Payment</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=invoice-create#invoice-create-section" class="<?php echo $currentSection === 'invoice-create' ? 'active' : ''; ?>" title="Generate Invoice">
                    <i class="fas fa-file-invoice-dollar"></i> <span>Generate Invoice</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=payments#payments-section" class="<?php echo $currentSection === 'payments' ? 'active' : ''; ?>" title="Payments">
                    <i class="fas fa-money-check-alt"></i> <span>Payments</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=invoices#invoices-section" class="<?php echo $currentSection === 'invoices' ? 'active' : ''; ?>" title="Invoices">
                    <i class="fas fa-file-invoice"></i> <span>Invoices</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=balances#balances-section" class="<?php echo $currentSection === 'balances' ? 'active' : ''; ?>" title="Student Balances">
                    <i class="fas fa-balance-scale"></i> <span>Student Balances</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=reports#reports-section" class="<?php echo $currentSection === 'reports' ? 'active' : ''; ?>" title="Collections Report">
                    <i class="fas fa-chart-line"></i> <span>Collections Report</span>
                </a>
            </li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php?section=saved-notifications#finance-saved-notifications-section" class="<?php echo $currentSection === 'saved-notifications' ? 'active' : ''; ?>" title="Saved Notifications">
                    <i class="fas fa-bell"></i> <span>Saved Notifications</span>
                </a>
            </li>

            <li class="menu-section">Account</li>

            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/change-password.php" class="<?php echo $currentPage == 'change-password.php' ? 'active' : ''; ?>" title="Change Password">
                    <i class="fas fa-key"></i> <span>Change Password</span>
                </a>
            </li>
            
            <li class="logout-item">
                <a href="<?php echo BASE_URL; ?>/views/finance/logout.php" class="logout-link" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</div>
