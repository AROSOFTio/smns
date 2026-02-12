<?php
/**
 * System Health Check
 * Verifies all system components are working properly
 */
require_once '../../../config.php';

// Simple session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize with admin module context
$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access (using module-specific session keys)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$checks = [];

// 1. Database Connection Test
try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->query("SELECT 1");
    $checks['database'] = ['status' => 'pass', 'message' => 'Database connection successful'];
} catch (Exception $e) {
    $checks['database'] = ['status' => 'fail', 'message' => 'Database connection failed: ' . $e->getMessage()];
}

// 2. User Authentication Test
try {
    $testAuth = new Auth();
    $checks['auth'] = ['status' => 'pass', 'message' => 'Authentication system loaded'];
} catch (Exception $e) {
    $checks['auth'] = ['status' => 'fail', 'message' => 'Auth system error: ' . $e->getMessage()];
}

// 3. Session Management Test
try {
    $testSession = new Session();
    $checks['session'] = ['status' => 'pass', 'message' => 'Session management working'];
} catch (Exception $e) {
    $checks['session'] = ['status' => 'fail', 'message' => 'Session error: ' . $e->getMessage()];
}

// 4. Security System Test
try {
    $token = Security::generateCSRFToken();
    $checks['security'] = ['status' => 'pass', 'message' => 'Security system operational (CSRF: ' . substr($token, 0, 8) . '...)'];
} catch (Exception $e) {
    $checks['security'] = ['status' => 'fail', 'message' => 'Security system error: ' . $e->getMessage()];
}

// 5. Helper Functions Test
try {
    $currentSem = Helper::getCurrentSemester();
    $checks['helper'] = ['status' => 'pass', 'message' => 'Helper functions working'];
} catch (Exception $e) {
    $checks['helper'] = ['status' => 'fail', 'message' => 'Helper error: ' . $e->getMessage()];
}

// 6. Logger Test (with actual write test)
try {
    $logger = new Logger();
    // Attempt to get recent activities to verify the table exists and is readable
    $activities = $logger->getRecentActivities(1);
    
    // Try to write a test log entry
    $testUserId = $_SESSION['admin_user_id'] ?? null;
    if ($testUserId) {
        $logResult = $logger->log($testUserId, 'health_check', 'system', 'System health check performed');
        if ($logResult) {
            $checks['logger'] = ['status' => 'pass', 'message' => 'Logging system operational - read/write OK'];
        } else {
            $checks['logger'] = ['status' => 'warning', 'message' => 'Logger loaded but write failed'];
        }
    } else {
        $checks['logger'] = ['status' => 'warning', 'message' => 'Logger loaded but no user ID for write test'];
    }
} catch (Exception $e) {
    $checks['logger'] = ['status' => 'fail', 'message' => 'Logger error: ' . $e->getMessage()];
}

// 7. Core Classes Test
$coreClasses = ['Database', 'Auth', 'Session', 'Security', 'Helper', 'Logger', 'Validator'];
$missingClasses = [];
foreach ($coreClasses as $class) {
    if (!class_exists($class)) {
        $missingClasses[] = $class;
    }
}

if (empty($missingClasses)) {
    $checks['classes'] = ['status' => 'pass', 'message' => 'All core classes loaded'];
} else {
    $checks['classes'] = ['status' => 'fail', 'message' => 'Missing classes: ' . implode(', ', $missingClasses)];
}

// 8. File Permissions Test
$criticalDirs = [
    'logs' => '../../logs',
    'uploads' => '../../uploads', 
    'cache' => '../../cache'
];

$permissionIssues = [];
foreach ($criticalDirs as $name => $dir) {
    if (!is_dir($dir)) {
        $permissionIssues[] = "$name directory missing";
    } elseif (!is_writable($dir)) {
        $permissionIssues[] = "$name directory not writable";
    }
}

if (empty($permissionIssues)) {
    $checks['permissions'] = ['status' => 'pass', 'message' => 'Directory permissions OK'];
} else {
    $checks['permissions'] = ['status' => 'warning', 'message' => 'Issues: ' . implode(', ', $permissionIssues)];
}

// 9. System Constants Test
$requiredConstants = ['BASE_PATH', 'BASE_URL', 'APP_NAME', 'DB_HOST', 'DB_NAME'];
$missingConstants = [];
foreach ($requiredConstants as $const) {
    if (!defined($const)) {
        $missingConstants[] = $const;
    }
}

if (empty($missingConstants)) {
    $checks['constants'] = ['status' => 'pass', 'message' => 'All required constants defined'];
} else {
    $checks['constants'] = ['status' => 'fail', 'message' => 'Missing constants: ' . implode(', ', $missingConstants)];
}

$pageTitle = 'System Health Check - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>
                <a href="../dashboard.php" class="btn btn-link">← Back to Dashboard</a>
                System Health Check
            </h4>
        </div>
        <div class="topbar-right">
            <small class="text-muted">Last checked: <?php echo date('M j, Y g:i A'); ?></small>
        </div>
    </div>
    
    <div class="content-area">
        <div class="container-fluid">
            
            <!-- System Overview -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-heartbeat"></i> System Status Overview
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $passCount = count(array_filter($checks, function($check) { return $check['status'] === 'pass'; }));
                            $totalChecks = count($checks);
                            $healthPercentage = round(($passCount / $totalChecks) * 100);
                            ?>
                            
                            <div class="row">
                                <div class="col-md-3 text-center">
                                    <div class="health-score">
                                        <div class="score-circle <?php echo $healthPercentage >= 90 ? 'excellent' : ($healthPercentage >= 70 ? 'good' : 'poor'); ?>">
                                            <?php echo $healthPercentage; ?>%
                                        </div>
                                        <p>System Health</p>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <div class="health-summary">
                                        <div class="summary-item">
                                            <span class="badge badge-success"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'pass'; })); ?></span>
                                            <span>Passing Checks</span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="badge badge-warning"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'warning'; })); ?></span>
                                            <span>Warnings</span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="badge badge-danger"><?php echo count(array_filter($checks, function($c) { return $c['status'] === 'fail'; })); ?></span>
                                            <span>Failed Checks</span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($healthPercentage >= 90): ?>
                                        <div class="alert alert-success mt-3">
                                            <i class="fas fa-check-circle"></i>
                                            <strong>Excellent!</strong> Your system is running optimally.
                                        </div>
                                    <?php elseif ($healthPercentage >= 70): ?>
                                        <div class="alert alert-warning mt-3">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>Good</strong> System is functional but some improvements recommended.
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-danger mt-3">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <strong>Attention Required!</strong> Critical issues detected that need immediate attention.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Detailed Health Checks -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list-check"></i> Detailed System Checks
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="health-checks">
                                <?php foreach ($checks as $checkName => $check): ?>
                                    <div class="health-check-item">
                                        <div class="check-status">
                                            <?php if ($check['status'] === 'pass'): ?>
                                                <i class="fas fa-check-circle text-success"></i>
                                            <?php elseif ($check['status'] === 'warning'): ?>
                                                <i class="fas fa-exclamation-triangle text-warning"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times-circle text-danger"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="check-details">
                                            <h6><?php echo ucwords(str_replace('_', ' ', $checkName)); ?></h6>
                                            <p class="text-secondary"><?php echo e($check['message']); ?></p>
                                        </div>
                                        <div class="check-badge">
                                            <span class="badge badge-<?php echo $check['status'] === 'pass' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'danger'); ?>">
                                                <?php echo strtoupper($check['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-tools"></i> System Maintenance
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="btn-toolbar" role="toolbar">
                                <div class="btn-group mr-3" role="group">
                                    <button onclick="window.location.reload()" class="btn btn-primary">
                                        <i class="fas fa-redo"></i> Refresh Check
                                    </button>
                                    <a href="../logs/system.php" class="btn btn-secondary">
                                        <i class="fas fa-file-alt"></i> View Logs
                                    </a>
                                </div>
                                <div class="btn-group mr-3" role="group">
                                    <a href="../backup/database.php" class="btn btn-info">
                                        <i class="fas fa-download"></i> Backup Database
                                    </a>
                                    <a href="../maintenance/cleanup.php" class="btn btn-warning">
                                        <i class="fas fa-broom"></i> Clean Cache
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.health-score .score-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: bold;
    margin: 0 auto;
}
.score-circle.excellent { background: #d4edda; color: #155724; }
.score-circle.good { background: #fff3cd; color: #856404; }
.score-circle.poor { background: #f8d7da; color: #721c24; }

.health-summary {
    display: flex;
    gap: 20px;
    align-items: center;
}
.summary-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.health-check-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid #f1f3f4;
}
.health-check-item:last-child {
    border-bottom: none;
}
.check-status {
    width: 40px;
    text-align: center;
    font-size: 1.2rem;
}
.check-details {
    flex: 1;
    margin-left: 15px;
}
.check-details h6 {
    margin: 0;
    font-weight: 600;
}
.check-details p {
    margin: 0;
    font-size: 0.9rem;
}
.check-badge {
    margin-left: 15px;
}
</style>

<?php include '../../../includes/footer.php'; ?>