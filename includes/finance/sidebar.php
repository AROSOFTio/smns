<?php
/**
 * Finance Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<div class="sidebar finance-sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Finance Portal</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-section">Personal</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/profile.php" class="<?php echo $currentPage == 'profile.php' ? 'active' : ''; ?>" title="My Profile">
                    <i class="fas fa-user"></i> <span>My Profile</span>
                </a>
            </li>
            
            <li class="menu-section">Billing</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/fees/structure.php" class="<?php echo $currentDir == 'fees' ? 'active' : ''; ?>" title="Fee Structure">
                    <i class="fas fa-money-bill-wave"></i> <span>Fee Structure</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/invoices/list.php" class="<?php echo $currentDir == 'invoices' ? 'active' : ''; ?>" title="Invoices">
                    <i class="fas fa-file-invoice"></i> <span>Invoices</span>
                </a>
            </li>
            
            <li class="menu-section">Payments</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/payments/record.php" class="<?php echo $currentPage == 'record.php' ? 'active' : ''; ?>" title="Record Payment">
                    <i class="fas fa-plus-circle"></i> <span>Record Payment</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/payments/list.php" class="<?php echo $currentPage == 'list.php' && $currentDir == 'payments' ? 'active' : ''; ?>" title="Payment History">
                    <i class="fas fa-history"></i> <span>Payment History</span>
                </a>
            </li>
            
            <li class="menu-section">Accounts</li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/balances/student-balances.php" class="<?php echo $currentDir == 'balances' ? 'active' : ''; ?>" title="Student Balances">
                    <i class="fas fa-balance-scale"></i> <span>Student Balances</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/reports/collections.php" class="<?php echo $currentDir == 'reports' ? 'active' : ''; ?>" title="Reports">
                    <i class="fas fa-chart-bar"></i> <span>Reports</span>
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
