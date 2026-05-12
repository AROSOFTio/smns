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
<style>
    .finance-sidebar-mobile-toggle {
        display: none;
    }

    @media (max-width: 992px) {
        body.smns-finance-mobile-shell .mobile-menu-btn {
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }

        .finance-sidebar {
            --mobile-sidebar-width: min(78vw, 260px);
            width: var(--mobile-sidebar-width) !important;
            max-width: var(--mobile-sidebar-width) !important;
            height: 100vh !important;
            height: 100dvh !important;
            max-height: 100dvh !important;
            left: calc(-1 * var(--mobile-sidebar-width)) !important;
            margin-left: 0 !important;
            top: 0 !important;
            z-index: 1400 !important;
            visibility: visible !important;
            display: flex !important;
            transform: none !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            box-shadow: 18px 0 40px rgba(15, 23, 42, 0.22) !important;
        }

        .finance-sidebar.active {
            left: 0 !important;
            margin-left: 0 !important;
            transform: translateX(0) !important;
            visibility: visible !important;
            display: flex !important;
        }

        .finance-sidebar-mobile-toggle {
            position: fixed;
            top: 9px;
            left: 10px;
            z-index: 1600;
            width: 34px;
            height: 34px;
            min-width: 34px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(15, 23, 42, 0.14);
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.18);
            padding: 0;
            font-size: 15px;
            line-height: 1;
            transition: left 0.25s ease, background 0.2s ease, color 0.2s ease;
        }

        body.smns-sidebar-open .finance-sidebar-mobile-toggle {
            left: calc(var(--mobile-sidebar-width, min(78vw, 260px)) + 8px);
            background: #0f172a;
            color: #ffffff;
        }

        .finance-sidebar ~ .main-content,
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            overflow-x: hidden !important;
        }

        .finance-sidebar ~ .main-content .topbar,
        .main-content .topbar {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto !important;
            align-items: center !important;
            gap: 10px !important;
            min-height: 52px !important;
            padding: 8px 10px 8px 52px !important;
            overflow: visible !important;
            background: #ffffff !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08) !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06) !important;
        }

        .finance-sidebar ~ .main-content .topbar-left,
        .main-content .topbar-left {
            display: flex !important;
            align-items: center !important;
            gap: 7px !important;
            min-width: 0 !important;
            overflow: hidden !important;
        }

        .finance-sidebar ~ .main-content .topbar-left h1,
        .finance-sidebar ~ .main-content .topbar-left h2,
        .finance-sidebar ~ .main-content .topbar-left h3,
        .finance-sidebar ~ .main-content .topbar-left h4,
        .main-content .topbar-left h1,
        .main-content .topbar-left h2,
        .main-content .topbar-left h3,
        .main-content .topbar-left h4 {
            min-width: 0 !important;
            max-width: 100% !important;
            margin: 0 !important;
            font-size: 0.96rem !important;
            font-weight: 700 !important;
            line-height: 1.2 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .finance-sidebar ~ .main-content .topbar-right,
        .main-content .topbar-right {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 7px !important;
            min-width: 0 !important;
            width: auto !important;
            max-width: none !important;
            margin-left: 0 !important;
            overflow: visible !important;
            flex-wrap: nowrap !important;
        }

        .finance-sidebar ~ .main-content .topbar-time,
        .main-content .topbar-time {
            display: none !important;
        }

        .finance-sidebar ~ .main-content .sidebar-toggle,
        .finance-sidebar ~ .main-content #sidebarToggle,
        .main-content .sidebar-toggle,
        .main-content #sidebarToggle {
            display: none !important;
        }

        .finance-sidebar ~ .main-content .notification-bell,
        .main-content .notification-bell {
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            border-radius: 50% !important;
        }

        .finance-sidebar ~ .main-content .user-dropdown-toggle,
        .main-content .user-dropdown-toggle {
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            padding: 0 !important;
            border-radius: 50% !important;
            justify-content: center !important;
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: none !important;
        }

        .finance-sidebar ~ .main-content .user-dropdown-toggle > div:not(.user-avatar):not(.user-avatar-sm),
        .finance-sidebar ~ .main-content .user-dropdown-toggle strong,
        .finance-sidebar ~ .main-content .user-dropdown-toggle small,
        .finance-sidebar ~ .main-content .user-dropdown-toggle .dropdown-arrow,
        .main-content .user-dropdown-toggle > div:not(.user-avatar):not(.user-avatar-sm),
        .main-content .user-dropdown-toggle strong,
        .main-content .user-dropdown-toggle small,
        .main-content .user-dropdown-toggle .dropdown-arrow {
            display: none !important;
        }

        .finance-sidebar ~ .main-content .user-avatar,
        .finance-sidebar ~ .main-content .user-avatar-sm,
        .main-content .user-avatar,
        .main-content .user-avatar-sm {
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            margin: 0 !important;
            font-size: 0.8rem !important;
        }

        .finance-sidebar ~ .main-content .content-area,
        .main-content .content-area {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            padding: 10px !important;
            overflow-x: hidden !important;
        }

        .finance-sidebar ~ .main-content .table-responsive,
        .main-content .table-responsive {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            overflow-y: visible !important;
            -webkit-overflow-scrolling: touch;
        }

        .finance-sidebar ~ .main-content .table-responsive table,
        .finance-sidebar ~ .main-content .table-responsive .table,
        .main-content .table-responsive table,
        .main-content .table-responsive .table {
            width: max-content !important;
            max-width: none !important;
            min-width: 680px !important;
            table-layout: auto !important;
        }

        .finance-dashboard .stats-grid,
        .finance-dashboard .action-grid {
            grid-template-columns: minmax(0, 1fr) !important;
        }

        .finance-dashboard .finance-msg-toolbar,
        .finance-dashboard .finance-msg-layout {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) !important;
            gap: 8px !important;
        }

        .finance-dashboard .finance-msg-toolbar > *,
        .finance-dashboard .finance-msg-layout > * {
            max-width: 100% !important;
            min-width: 0 !important;
        }
    }
</style>

<button
    type="button"
    class="finance-sidebar-mobile-toggle"
    id="financeSidebarMobileToggle"
    aria-label="Toggle finance sidebar"
    aria-controls="sidebar"
    aria-expanded="false"
>
    <span aria-hidden="true">&#9776;</span>
</button>

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

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.classList.add('smns-finance-mobile-shell');
    document.querySelectorAll('.mobile-menu-btn').forEach(function(button) {
        button.remove();
    });

    var sidebar = document.getElementById('sidebar');
    var topbar = document.querySelector('.main-content .topbar');
    var mobileToggle = document.getElementById('financeSidebarMobileToggle');
    var overlay = document.querySelector('.sidebar-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    function isMobileSidebar() {
        return window.innerWidth <= 992;
    }

    function syncFinanceSidebarToggle() {
        if (!sidebar || !mobileToggle) return;
        var isOpen = sidebar.classList.contains('active') && isMobileSidebar();
        mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        mobileToggle.innerHTML = isOpen
            ? '<span aria-hidden="true">&times;</span>'
            : '<span aria-hidden="true">&#9776;</span>';
    }

    function closeFinanceSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('active');
        sidebar.classList.remove('collapsed');
        overlay.classList.remove('active');
        document.body.classList.remove('smns-sidebar-open');
        document.body.style.overflow = '';
        syncFinanceSidebarToggle();
    }

    function openFinanceSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('active');
        sidebar.classList.remove('collapsed');
        overlay.classList.add('active');
        document.body.classList.add('smns-sidebar-open');
        document.body.style.overflow = 'hidden';
        syncFinanceSidebarToggle();
    }

    if (mobileToggle && sidebar && mobileToggle.dataset.smnsFinanceSidebarBound !== '1') {
        mobileToggle.dataset.smnsFinanceSidebarBound = '1';
        mobileToggle.addEventListener('click', function(e) {
            if (!isMobileSidebar()) return;
            e.preventDefault();
            e.stopPropagation();
            if (sidebar.classList.contains('active')) {
                closeFinanceSidebar();
            } else {
                openFinanceSidebar();
            }
        });
    }

    overlay.addEventListener('click', closeFinanceSidebar);
    overlay.addEventListener('touchstart', closeFinanceSidebar, { passive: true });

    document.querySelectorAll('.finance-sidebar a[href]').forEach(function(link) {
        link.addEventListener('click', function() {
            if (isMobileSidebar()) closeFinanceSidebar();
        });
    });

    window.addEventListener('resize', function() {
        if (!isMobileSidebar()) closeFinanceSidebar();
        syncFinanceSidebarToggle();
    });

    if (window.MutationObserver && sidebar) {
        new MutationObserver(syncFinanceSidebarToggle).observe(sidebar, {
            attributes: true,
            attributeFilter: ['class']
        });
    }

    syncFinanceSidebarToggle();

    if (!sidebar || !topbar || document.getElementById('sidebarToggle')) return;

    var left = topbar.querySelector('.topbar-left');
    if (!left) {
        left = document.createElement('div');
        left.className = 'topbar-left';
        while (topbar.firstChild) {
            left.appendChild(topbar.firstChild);
        }
        topbar.appendChild(left);
    }

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'sidebar-toggle';
    toggle.id = 'sidebarToggle';
    toggle.title = 'Toggle Sidebar';
    toggle.setAttribute('aria-label', 'Toggle Sidebar');
    toggle.innerHTML = '<i class="fas fa-bars"></i>';
    left.insertBefore(toggle, left.firstChild);
});
</script>
