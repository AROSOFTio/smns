<?php
/**
 * Finance Dashboard
 */
require_once '../../config.php';

// Initialize session and auth with finance module context
$session = new Session('finance');
$auth = new Auth('finance');

// Verify finance access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    header('Location: ' . BASE_URL . '/views/finance/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$financeProfile = $currentUser['profile'];

// Get statistics
$db = new Database();
$conn = $db->getConnection();

// Current semester
$currentSemester = Helper::getCurrentSemester();

// Total collections this semester
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments 
                        WHERE semester_id = :semester_id");
$stmt->execute(['semester_id' => $currentSemester['id'] ?? 0]);
$totalCollections = $stmt->fetch()['total'];

// Outstanding balances
$stmt = $conn->prepare("SELECT COALESCE(SUM(balance), 0) as total FROM student_balances 
                        WHERE semester_id = :semester_id AND balance > 0");
$stmt->execute(['semester_id' => $currentSemester['id'] ?? 0]);
$outstandingBalance = $stmt->fetch()['total'];

// Payments today
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments 
                        WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$paymentsToday = $stmt->fetch()['total'];

// Recent payments
$stmt = $conn->prepare("SELECT p.*, s.student_id, s.first_name, s.last_name
                        FROM payments p
                        INNER JOIN students s ON p.student_id = s.id
                        ORDER BY p.created_at DESC LIMIT 10");
$stmt->execute();
$recentPayments = $stmt->fetchAll();

// Additional stats for dashboard
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM invoices 
                        WHERE semester_id = :semester_id AND status != 'paid'");
$stmt->execute(['semester_id' => $currentSemester['id'] ?? 0]);
$totalInvoices = $stmt->fetch()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_balances 
                        WHERE semester_id = :semester_id AND balance > 0");
$stmt->execute(['semester_id' => $currentSemester['id'] ?? 0]);
$studentsWithBalance = $stmt->fetch()['total'];

// Notifications
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :user_id AND read_status = 'unread' ORDER BY created_at DESC LIMIT 5");
$stmt->execute(['user_id' => $currentUser['id']]);
$unreadNotifications = $stmt->fetchAll();

$pageTitle = 'Finance Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/finance/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Dashboard</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
            <?php include '../../includes/notification_bell.php'; ?>
            <div class="user-info">
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" id="userDropdown">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($financeProfile['first_name'], 0, 1) . substr($financeProfile['last_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <strong><?php echo e($financeProfile['first_name']); ?> <?php echo e($financeProfile['last_name']); ?></strong>
                            <br><small>Finance Staff</small>
                        </div>
                        <i class="dropdown-arrow">▼</i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <a href="profile.php" class="dropdown-item">
                            <i>👤</i> My Profile
                        </a>
                        <a href="reports/collections.php" class="dropdown-item">
                            <i>📈</i> Reports
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo BASE_URL; ?>/views/finance/logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success">
                <?php echo e($session->getFlash('success')); ?>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Section -->
        <div class="welcome-section mb-3">
            <h5>Welcome, <?php echo e($financeProfile['first_name']); ?> — Finance Dashboard</h5>
            <p class="text-muted mb-0" style="font-size:13px;">
                <strong>Semester:</strong> <?php echo e($currentSemester['semester_name'] ?? 'N/A'); ?> &nbsp;|&nbsp; <?php echo date('l, M d, Y'); ?>
            </p>
        </div>
        
        <!-- Finance Stats Cards -->
        <div class="stats-grid finance-stats">
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-details">
                    <h3><?php echo Helper::formatCurrency($paymentsToday); ?></h3>
                    <p>Today's Collections</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-details">
                    <h3><?php echo Helper::formatCurrency($totalCollections); ?></h3>
                    <p>Semester Collections</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">⏰</div>
                <div class="stat-details">
                    <h3><?php echo Helper::formatCurrency($outstandingBalance); ?></h3>
                    <p>Outstanding Balances</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">📄</div>
                <div class="stat-details">
                    <h3><?php echo number_format($totalInvoices); ?></h3>
                    <p>Active Invoices</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-details">
                    <h3><?php echo number_format($studentsWithBalance); ?></h3>
                    <p>Students w/ Balance</p>
                </div>
            </div>
        </div>

        <!-- Collection Progress -->
        <?php $collectionRate = ($totalCollections + $outstandingBalance) > 0 ? (($totalCollections / ($totalCollections + $outstandingBalance)) * 100) : 0; ?>
        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small class="font-weight-bold">Collection Rate</small>
                    <small class="text-muted"><?php echo number_format($collectionRate, 1); ?>%</small>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $collectionRate; ?>%"></div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-3">
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/finance/payments/record.php" class="btn btn-primary btn-sm btn-block">💳 Record Payment</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/finance/invoices/generate.php" class="btn btn-success btn-sm btn-block">⚡ Generate Invoice</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/finance/balances/student-balances.php" class="btn btn-info btn-sm btn-block">⚖️ View Balances</a>
            </div>
            <div class="col-6 col-md-3 mb-2">
                <a href="<?php echo BASE_URL; ?>/views/finance/reports/collections.php" class="btn btn-warning btn-sm btn-block">📑 Reports</a>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <div class="card">
            <div class="card-header">
                Recent Payments
            </div>
            <div class="card-body">
                <?php if (count($recentPayments) > 0): ?>
                    <table class="table table-hover table-sm" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Payment ID</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentPayments as $payment): ?>
                                <tr>
                                    <td><?php echo e($payment['payment_id']); ?></td>
                                    <td><?php echo e($payment['first_name'] . ' ' . $payment['last_name']); ?><br><small><?php echo e($payment['student_id']); ?></small></td>
                                    <td><strong><?php echo Helper::formatCurrency($payment['amount']); ?></strong></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                    <td><?php echo Helper::formatDate($payment['payment_date']); ?></td>
                                    <td>
                                        <?php if ($payment['receipt_number']): ?>
                                            <a href="<?php echo BASE_URL; ?>/views/finance/payments/receipt.php?id=<?php echo $payment['id']; ?>" class="btn btn-sm btn-info" target="_blank">🧾 View</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="<?php echo BASE_URL; ?>/views/finance/payments/list.php" class="btn btn-sm btn-primary">View All Payments</a>
                <?php else: ?>
                    <p class="text-center">No payments recorded yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
