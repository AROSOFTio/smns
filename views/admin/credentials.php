<?php
/**
 * Display User Credentials - Admin
 * Shows login credentials for newly created users
 */
require_once __DIR__ . '/../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash_error'] = 'Session expired while opening credentials.';
    }
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=session_expired&role=admin');
    exit;
}

// Get credentials from session
$credentials = $_SESSION['new_user_credentials'] ?? null;
$userType = $_SESSION['new_user_type'] ?? 'user';
$credentialsFromCache = false;

// Persist a short-lived backup so page refresh does not force-redirect away.
if (is_array($credentials) && !empty($credentials)) {
    $_SESSION['last_generated_credentials'] = $credentials;
    $_SESSION['last_generated_user_type'] = $userType;
    $_SESSION['last_generated_credentials_expires_at'] = time() + 900; // 15 minutes
} else {
    $backup = $_SESSION['last_generated_credentials'] ?? null;
    $backupType = $_SESSION['last_generated_user_type'] ?? 'user';
    $backupExpiresAt = (int)($_SESSION['last_generated_credentials_expires_at'] ?? 0);
    if (is_array($backup) && !empty($backup) && $backupExpiresAt >= time()) {
        $credentials = $backup;
        $userType = $backupType;
        $credentialsFromCache = true;
    }
}

$isPending = strpos($userType, '_pending') !== false;
$actualUserType = str_replace('_pending', '', $userType);
$successFlash = $session->getFlash('success');

$hasCredentials = is_array($credentials) && !empty($credentials);

$pageTitle = 'User Credentials - ' . APP_NAME;
$disableAutoLogout = true;
include __DIR__ . '/../../includes/header.php';
?>

<style>
/* AGGRESSIVE HORIZONTAL SCROLL PREVENTION */
* {
    box-sizing: border-box !important;
}

html {
    overflow-x: hidden !important;
    width: 100% !important;
}

body {
    overflow-x: hidden !important;
    width: 100% !important;
    margin: 0 !important;
}

.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.content-area {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.container, .container-fluid {
    overflow-x: hidden !important;
    max-width: 100% !important;
}

/* Credentials specific styles */
.credentials-container {
    max-width: 800px;
    margin: 0 auto;
}

.credentials-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    padding: 40px;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    margin-bottom: 30px;
}

.credentials-header {
    text-align: center;
    margin-bottom: 30px;
}

.credentials-header h2 {
    margin: 0 0 10px 0;
    font-size: 28px;
    font-weight: 700;
}

.credentials-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 16px;
}

.credentials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.credential-item {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 20px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
}

.credential-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
    opacity: 0.8;
}

.credential-value {
    font-size: 18px;
    font-weight: 700;
    word-break: break-all;
    background: rgba(255,255,255,0.2);
    padding: 10px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
}

.login-url {
    text-align: center;
    margin: 30px 0;
}

.login-url a {
    display: inline-block;
    background: white;
    color: #667eea;
    padding: 15px 30px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    font-size: 16px;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.login-url a:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    color: #5a6fd8;
}

.instructions-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.instructions-card h3 {
    color: #333;
    margin-bottom: 20px;
    font-size: 20px;
}

.instruction-list {
    list-style: none;
    padding: 0;
}

.instruction-list li {
    padding: 10px 0;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: flex-start;
    gap: 15px;
}

.instruction-list li:last-child {
    border-bottom: none;
}

.instruction-list .step-number {
    background: #667eea;
    color: white;
    width: 25px;
    height: 25px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}

.instruction-list .step-text {
    color: #555;
    line-height: 1.5;
}

.actions {
    text-align: center;
    margin-top: 30px;
}

.btn-print {
    background: #28a745;
    color: white;
    border: none;
    padding: 12px 25px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    margin-right: 10px;
    transition: background 0.3s;
}

.btn-print:hover {
    background: #218838;
}

.btn-dashboard {
    background: #6c757d;
    color: white;
    border: none;
    padding: 12px 25px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: background 0.3s;
}

.btn-dashboard:hover {
    background: #5a6268;
    color: white;
}

@media print {
    .actions, .topbar, .sidebar {
        display: none !important;
    }

    .credentials-card {
        box-shadow: none;
        border: 2px solid #333;
    }

    .instruction-list .step-number {
        border: 1px solid #333;
        background: white !important;
        color: #333 !important;
    }
}
</style>

<?php include __DIR__ . '/../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>User Credentials</h4>
        </div>
        <div class="topbar-right">
            <button onclick="window.print()" class="btn btn-success">🖨️ Print Credentials</button>
            <?php include __DIR__ . '/../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="credentials-container">
            <?php if ($successFlash): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo e($successFlash); ?>
                </div>
            <?php endif; ?>

            <?php if (!$hasCredentials): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    No recent credentials are available in this session.
                </div>
                <div class="actions">
                    <a href="<?php echo BASE_URL; ?>/views/admin/users/add.php" class="btn-dashboard">
                        <i class="fas fa-user-plus"></i> Create Another User
                    </a>
                    <a href="dashboard.php" class="btn-dashboard ml-2">
                        <i class="fas fa-home"></i> Back to Dashboard
                    </a>
                </div>
            <?php else: ?>

            <!-- Success Message -->
            <div class="alert alert-<?php echo $isPending ? 'warning' : 'success'; ?>">
                <i class="fas fa-<?php echo $isPending ? 'clock' : 'check-circle'; ?>"></i>
                <strong><?php echo ucfirst($actualUserType); ?> <?php echo $isPending ? 'application submitted' : 'account created'; ?> successfully!</strong>
                <?php echo $isPending ? 'Application is pending administrative approval.' : 'Please save these login credentials securely.'; ?>
            </div>

            <?php if ($credentialsFromCache): ?>
                <div class="alert alert-info">
                    <i class="fas fa-sync-alt"></i>
                    Refreshed copy loaded from this session cache.
                </div>
            <?php endif; ?>

            <?php if ($actualUserType === 'finance' && !$isPending): ?>
                <?php if (!empty($credentials['mail_sent'])): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-envelope-open-text"></i>
                        Login credentials were sent to <strong><?php echo e((string)($credentials['email'] ?? '')); ?></strong>.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Credential email was not sent. Please share these credentials manually.
                        <?php if (!empty($credentials['mail_error'])): ?>
                            <div class="small mt-1 text-muted"><?php echo e((string)$credentials['mail_error']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Credentials Card -->
            <div class="credentials-card">
                <div class="credentials-header">
                    <h2><i class="fas fa-key"></i> Login Credentials</h2>
                    <p><?php echo ucfirst($userType); ?> Portal Access Information</p>
                </div>

                <div class="credentials-grid">
                    <?php if (isset($credentials['student_id'])): ?>
                        <div class="credential-item">
                            <div class="credential-label">Student ID</div>
                            <div class="credential-value"><?php echo e($credentials['student_id']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($credentials['lecturer_id'])): ?>
                        <div class="credential-item">
                            <div class="credential-label">Lecturer ID</div>
                            <div class="credential-value"><?php echo e($credentials['lecturer_id']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($credentials['account_id_label']) && !empty($credentials['account_id_value']) && (string)$credentials['account_id_value'] !== '-'): ?>
                        <div class="credential-item">
                            <div class="credential-label"><?php echo e((string)$credentials['account_id_label']); ?></div>
                            <div class="credential-value"><?php echo e((string)$credentials['account_id_value']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($credentials['user_name'])): ?>
                        <div class="credential-item">
                            <div class="credential-label">Full Name</div>
                            <div class="credential-value"><?php echo e($credentials['user_name']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isPending): ?>
                        <div class="credential-item">
                            <div class="credential-label">Username</div>
                            <div class="credential-value"><?php echo e($credentials['username']); ?></div>
                        </div>

                        <div class="credential-item">
                            <div class="credential-label">Temporary Password</div>
                            <div class="credential-value"><?php echo e($credentials['password']); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="credential-item">
                        <div class="credential-label">Email</div>
                        <div class="credential-value"><?php echo e($credentials['email']); ?></div>
                    </div>

                    <div class="credential-item">
                        <div class="credential-label">Status</div>
                        <div class="credential-value">
                            <span class="badge badge-<?php echo ($credentials['status'] ?? 'active') == 'pending' ? 'warning' : 'success'; ?>">
                                <?php echo ucfirst($credentials['status'] ?? 'active'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="credential-item">
                        <div class="credential-label">Role</div>
                        <div class="credential-value"><?php echo ucfirst($actualUserType); ?></div>
                    </div>
                </div>

                <?php if (!$isPending): ?>
                <div class="login-url">
                    <a href="<?php echo BASE_URL; ?>/views/<?php echo $actualUserType; ?>/login.php" target="_blank">
                        🚀 Access <?php echo ucfirst($actualUserType); ?> Portal
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Instructions Card -->
            <div class="instructions-card">
                <h3><i class="fas fa-list-check"></i> <?php echo $isPending ? 'Application Status' : 'First Login Instructions'; ?></h3>
                <ul class="instruction-list">
                    <?php if ($isPending): ?>
                        <li>
                            <div class="step-number">1</div>
                            <div class="step-text">
                                <strong>Application Submitted:</strong> Your lecturer application has been received and is being reviewed by administrators.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">2</div>
                            <div class="step-text">
                                <strong>Review Process:</strong> Our team will verify your qualifications and documentation.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">3</div>
                            <div class="step-text">
                                <strong>Approval Notification:</strong> You will receive an email with login credentials once approved.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">4</div>
                            <div class="step-text">
                                <strong>Next Steps:</strong> After approval, follow the login instructions that will be emailed to you.
                            </div>
                        </li>
                    <?php else: ?>
                        <li>
                            <div class="step-number">1</div>
                            <div class="step-text">
                                <strong>Click the "Access <?php echo ucfirst($actualUserType); ?> Portal" button above</strong> or visit the login URL directly.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">2</div>
                            <div class="step-text">
                                <strong>Enter your username and temporary password</strong> exactly as shown above.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">3</div>
                            <div class="step-text">
                                <strong>You will be required to change your password</strong> on first login for security.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">4</div>
                            <div class="step-text">
                                <strong>Choose a strong, memorable password</strong> that meets the security requirements.
                            </div>
                        </li>
                        <li>
                            <div class="step-number">5</div>
                            <div class="step-text">
                                <strong>Keep these credentials secure</strong> and do not share them with others.
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Actions -->
            <div class="actions">
                <button onclick="window.print()" class="btn-print">
                    <i class="fas fa-print"></i> Print Credentials
                </button>
                <a href="dashboard.php" class="btn-dashboard">
                    <i class="fas fa-home"></i> Back to Dashboard
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Clear one-time payload keys after loading, while keeping short-lived backup for refresh resilience.
if (isset($_SESSION['new_user_credentials'])) {
    unset($_SESSION['new_user_credentials']);
}
if (isset($_SESSION['new_user_type'])) {
    unset($_SESSION['new_user_type']);
}
?>

<script>
// Auto-print option
if (window.location.search.includes('print=1')) {
    window.print();
}

// Copy to clipboard functionality
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Show success message
        const notification = document.createElement('div');
        notification.className = 'alert alert-info';
        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 300px;';
        notification.innerHTML = '<i class="fas fa-copy"></i> Copied to clipboard!';
        document.body.appendChild(notification);
        setTimeout(() => document.body.removeChild(notification), 3000);
    });
}

// Add copy buttons to credential values
document.querySelectorAll('.credential-value').forEach(function(element) {
    element.style.cursor = 'pointer';
    element.title = 'Click to copy';
    element.addEventListener('click', function() {
        copyToClipboard(this.textContent);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
