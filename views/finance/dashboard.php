<?php
/**
 * Finance Dashboard
 */
require_once '../../config.php';

Security::requireRole('finance');
$auth = new Auth();
$currentUser = $auth->getCurrentUser();
$financeProfile = $currentUser['profile'];
$session = new Session();

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

$pageTitle = 'Finance Dashboard - ' . APP_NAME;
include '../../includes/header.php';
?>

<?php include '../../includes/finance/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Dashboard</h4>
        </div>
        <div class="topbar-right">
            <div class="topbar-time">
                <div id="current-date-time">
                    <div class="time-display"><?php echo date('h:i:s A'); ?></div>
                    <div class="date-display"><?php echo date('l, F j, Y'); ?></div>
                </div>
            </div>
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
                        <a href="<?php echo BASE_URL; ?>/views/auth/logout.php" class="dropdown-item logout-item">
                            <i>🚪</i> Logout
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
        
        <!-- Welcome Message -->
        <div class="card">
            <div class="card-body">
                <h3>Welcome, <?php echo e($financeProfile['first_name']); ?>!</h3>
                <p><strong>Department:</strong> Finance</p>
                <p><strong>Current Semester:</strong> <?php echo e($currentSemester['semester_name'] ?? 'N/A'); ?></p>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-card success">
                    <p>Total Collections</p>
                    <h3><?php echo Helper::formatCurrency($totalCollections); ?></h3>
                    <small>This semester</small>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stats-card danger">
                    <p>Outstanding Balance</p>
                    <h3><?php echo Helper::formatCurrency($outstandingBalance); ?></h3>
                    <small>Unpaid fees</small>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stats-card primary">
                    <p>Today's Collections</p>
                    <h3><?php echo Helper::formatCurrency($paymentsToday); ?></h3>
                    <small><?php echo date('M d, Y'); ?></small>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Quick Actions -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Quick Actions
                    </div>
                    <div class="card-body">
                        <a href="<?php echo BASE_URL; ?>/views/finance/payments/record.php" class="btn btn-primary mb-2" style="width:100%">💳 Record New Payment</a>
                        <a href="<?php echo BASE_URL; ?>/views/finance/invoices/generate.php" class="btn btn-success mb-2" style="width:100%">⚡ Generate Invoice</a>
                        <a href="<?php echo BASE_URL; ?>/views/finance/balances/student-balances.php" class="btn btn-info mb-2" style="width:100%">⚖️ View Balances</a>
                        <a href="<?php echo BASE_URL; ?>/views/finance/reports/collections.php" class="btn btn-warning mb-2" style="width:100%">📑 Generate Report</a>
                    </div>
                </div>
            </div>
            
            <!-- Financial Summary -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Financial Summary
                    </div>
                    <div class="card-body">
                        <?php
                        $collectionRate = $totalCollections > 0 ? (($totalCollections / ($totalCollections + $outstandingBalance)) * 100) : 0;
                        ?>
                        <p><strong>Collection Rate:</strong> <?php echo number_format($collectionRate, 1); ?>%</p>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $collectionRate; ?>%" aria-valuenow="<?php echo $collectionRate; ?>" aria-valuemin="0" aria-valuemax="100">
                                <?php echo number_format($collectionRate, 1); ?>%
                            </div>
                        </div>
                        <p><strong>Total Expected:</strong> <?php echo Helper::formatCurrency($totalCollections + $outstandingBalance); ?></p>
                        <p><strong>Collected:</strong> <span class="text-success"><?php echo Helper::formatCurrency($totalCollections); ?></span></p>
                        <p><strong>Outstanding:</strong> <span class="text-danger"><?php echo Helper::formatCurrency($outstandingBalance); ?></span></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <div class="card">
            <div class="card-header">
                Recent Payments
            </div>
            <div class="card-body">
                <?php if (count($recentPayments) > 0): ?>
                    <table class="table table-hover">
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
