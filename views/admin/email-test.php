<?php
/**
 * Email Configuration Test - Admin
 * Test email sending functionality
 */
require_once '../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$testResult = null;
$testEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $testEmail = Security::sanitize($_POST['test_email'] ?? '');

    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // Test email sending
        $subject = APP_NAME . ' - Email Configuration Test';
        $message = "Hello!\n\nThis is a test email from " . APP_NAME . ".\n\nIf you received this email, your email configuration is working correctly.\n\nTest sent at: " . date('Y-m-d H:i:s') . "\n\nRegards,\n" . APP_NAME . " System";

        try {
            $mailSent = Helper::sendEmail($testEmail, $subject, $message);
            if ($mailSent) {
                $testResult = [
                    'success' => true,
                    'message' => 'Test email sent successfully! Check your inbox (and spam folder).'
                ];
            } else {
                $testResult = [
                    'success' => false,
                    'message' => 'Email sending failed. Check your SMTP configuration.'
                ];
            }
        } catch (Exception $e) {
            $testResult = [
                'success' => false,
                'message' => 'Error sending email: ' . $e->getMessage()
            ];
        }
    }
}

$pageTitle = 'Email Configuration Test - ' . APP_NAME;
include '../../includes/header.php';
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

/* Email test specific styles */
.email-config-container {
    max-width: 800px;
    margin: 0 auto;
}

.config-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.config-header {
    text-align: center;
    margin-bottom: 30px;
}

.config-header h2 {
    color: #333;
    margin-bottom: 10px;
}

.config-header p {
    color: #666;
    font-size: 16px;
}

.current-config {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.config-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.config-item:last-child {
    border-bottom: none;
}

.config-label {
    font-weight: 600;
    color: #333;
}

.config-value {
    font-family: 'Courier New', monospace;
    background: white;
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid #ddd;
    color: #666;
}

.test-form {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input[type="email"] {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 16px;
    transition: border-color 0.3s;
}

.form-group input[type="email"]:focus {
    outline: none;
    border-color: #007bff;
}

.btn-test {
    background: #007bff;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
}

.btn-test:hover {
    background: #0056b3;
}

.btn-test:disabled {
    background: #6c757d;
    cursor: not-allowed;
}

.result-card {
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.result-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.result-error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.instructions-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
}

.instructions-card h3 {
    color: #333;
    margin-bottom: 15px;
}

.instruction-step {
    display: flex;
    margin-bottom: 15px;
    align-items: flex-start;
}

.step-number {
    background: #007bff;
    color: white;
    width: 25px;
    height: 25px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    margin-right: 15px;
    flex-shrink: 0;
}

.step-content {
    color: #555;
    line-height: 1.5;
}

.code-snippet {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 10px;
    font-family: 'Courier New', monospace;
    font-size: 14px;
    margin: 10px 0;
    overflow-x: auto;
}

.warning-box {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 15px;
    margin: 15px 0;
}

.warning-box h4 {
    color: #856404;
    margin: 0 0 10px 0;
}

.warning-box p {
    color: #856404;
    margin: 0;
}

/* Dark mode contrast fixes for this page */
html[data-theme='dark'] .config-card {
    background: #111827;
    border: 1px solid #334155;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
}

html[data-theme='dark'] .config-header h2 {
    color: #f9fafb !important;
}

html[data-theme='dark'] .config-header p {
    color: #cbd5e1 !important;
}

html[data-theme='dark'] .current-config,
html[data-theme='dark'] .test-form,
html[data-theme='dark'] .instructions-card {
    background: #1f2937;
    border: 1px solid #334155;
}

html[data-theme='dark'] .config-item {
    border-bottom-color: #334155;
}

html[data-theme='dark'] .config-label,
html[data-theme='dark'] .test-form h3,
html[data-theme='dark'] .test-form p,
html[data-theme='dark'] .instructions-card h3,
html[data-theme='dark'] .step-content {
    color: #e5e7eb !important;
}

html[data-theme='dark'] .config-value {
    background: #0f172a;
    border-color: #475569;
    color: #e5e7eb !important;
}

html[data-theme='dark'] .form-group label {
    color: #e5e7eb !important;
}

html[data-theme='dark'] .form-group input[type="email"] {
    background: #0f172a;
    color: #e5e7eb;
    border-color: #475569;
}

html[data-theme='dark'] .form-group input[type="email"]::placeholder {
    color: #94a3b8;
}

html[data-theme='dark'] .code-snippet {
    background: #0f172a;
    border-color: #334155;
    color: #e5e7eb;
}

html[data-theme='dark'] .warning-box {
    background: #3f2f12;
    border-color: #7c5a1e;
}

html[data-theme='dark'] .warning-box h4,
html[data-theme='dark'] .warning-box p,
html[data-theme='dark'] .warning-box code,
html[data-theme='dark'] .warning-box i {
    color: #fef3c7 !important;
}
</style>

<?php include '../../includes/admin/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <h4>Email Configuration Test</h4>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area">
        <div class="email-config-container">
            <?php if ($testResult): ?>
                <div class="result-card <?php echo $testResult['success'] ? 'result-success' : 'result-error'; ?>">
                    <h4><i class="fas fa-<?php echo $testResult['success'] ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <?php echo $testResult['success'] ? 'Test Successful' : 'Test Failed'; ?>
                    </h4>
                    <p><?php echo e($testResult['message']); ?></p>
                </div>
            <?php endif; ?>

            <!-- Current Configuration -->
            <div class="config-card">
                <div class="config-header">
                    <h2><i class="fas fa-cogs"></i> Current Email Configuration</h2>
                    <p>Review your current SMTP settings</p>
                </div>

                <div class="current-config">
                    <div class="config-item">
                        <span class="config-label">SMTP Host:</span>
                        <span class="config-value"><?php echo e(SMTP_HOST); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">SMTP Port:</span>
                        <span class="config-value"><?php echo e(SMTP_PORT); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">From Email:</span>
                        <span class="config-value"><?php echo e(SMTP_FROM_EMAIL); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">From Name:</span>
                        <span class="config-value"><?php echo e(SMTP_FROM_NAME); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">Username:</span>
                        <span class="config-value"><?php echo e(SMTP_USERNAME ?: 'Not set'); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">Password:</span>
                        <span class="config-value"><?php echo e(SMTP_PASSWORD ? '••••••••' : 'Not set'); ?></span>
                    </div>
                </div>

                <div class="warning-box">
                    <h4><i class="fas fa-info-circle"></i> Configuration Note</h4>
                    <p>This system now sends through <code>Nodemailer</code>. Configure SMTP in <code>config.php</code> and install dependencies in <code>scripts/mailer</code>.</p>
                </div>
            </div>

            <!-- Test Form -->
            <div class="config-card">
                <div class="test-form">
                    <h3><i class="fas fa-envelope"></i> Send Test Email</h3>
                    <p>Enter an email address to send a test message and verify your configuration.</p>

                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="test_email">Test Email Address:</label>
                            <input type="email" id="test_email" name="test_email"
                                   value="<?php echo e($testEmail); ?>" required
                                   placeholder="your-email@example.com">
                        </div>

                        <button type="submit" class="btn-test">
                            <i class="fas fa-paper-plane"></i> Send Test Email
                        </button>
                    </form>
                </div>
            </div>

            <!-- Setup Instructions -->
            <div class="instructions-card">
                <h3><i class="fas fa-list-check"></i> Email Setup Instructions</h3>

                <div class="instruction-step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <strong>Install Nodemailer dependency:</strong><br>
                        In the project root, run:<br>
                        <div class="code-snippet">cd scripts/mailer && npm install</div>
                    </div>
                </div>

                <div class="instruction-step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <strong>For Local Development (Recommended):</strong><br>
                        Install <a href="https://github.com/mailhog/MailHog" target="_blank">MailHog</a> and run it:<br>
                        <div class="code-snippet">mailhog</div>
                        The current configuration (localhost:1025) will work with MailHog.
                    </div>
                </div>

                <div class="instruction-step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <strong>For Gmail:</strong><br>
                        Update <code>config.php</code> with your Gmail settings:<br>
                        <div class="code-snippet">
define('SMTP_HOST', 'smtp.gmail.com');<br>
define('SMTP_PORT', 587);<br>
define('SMTP_USERNAME', 'your-gmail@gmail.com');<br>
define('SMTP_PASSWORD', 'your-app-password');<br>
define('SMTP_SECURE', false);<br>
define('SMTP_FROM_EMAIL', 'your-gmail@gmail.com');
                        </div>
                        <small>Note: Use an App Password, not your regular Gmail password.</small>
                    </div>
                </div>

                <div class="instruction-step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <strong>For Other Providers:</strong><br>
                        Update the SMTP settings in <code>config.php</code> according to your email provider's documentation (SendGrid, Mailgun, etc.).
                    </div>
                </div>

                <div class="instruction-step">
                    <div class="step-number">5</div>
                    <div class="step-content">
                        <strong>Test Configuration:</strong><br>
                        Use the form above to send a test email and verify everything works.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
