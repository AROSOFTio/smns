<?php
/**
 * Finance Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo BASE_URL; ?>/assets/images/logo-small.png" alt="Logo" style="max-width: 60px;" onerror="this.style.display='none'">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p><small>Finance Portal</small></p>
    </div>
    
    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i>📊</i> Dashboard
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/profile.php">
                    <i>👤</i> My Profile
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/fees/structure.php">
                    <i>💲</i> Fee Structure
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/invoices/list.php">
                    <i>🧾</i> Invoices
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/payments/record.php">
                    <i>💳</i> Record Payment
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/payments/list.php">
                    <i>💰</i> Payment History
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/balances/student-balances.php">
                    <i>⚖️</i> Student Balances
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/views/finance/reports/collections.php">
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
