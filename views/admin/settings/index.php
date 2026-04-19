<?php
/**
 * Admin Settings (Tabbed)
 */
require_once '../../../config.php';



$session = new Session('admin');
$auth = new Auth('admin');

// Verify admin access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();

$db = new Database();
$conn = $db->getConnection();
$settingsPageUrl = rtrim((string)BASE_URL, '/') . '/views/admin/settings/index.php';

// Active tab
$tab = $_GET['tab'] ?? 'general';

// Helpers
function saveSetting($conn, $key, $value) {
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute(['key' => $key, 'value' => $value]);
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid CSRF token');
        header('Location: ' . $settingsPageUrl . '?tab=' . urlencode($tab));
        exit;
    }

    if (isset($_POST['save_general'])) {
        $fields = ['institution_name','institution_email','institution_phone','institution_address','academic_year_format','student_id_prefix','admission_prefix'];
        foreach ($fields as $f) {
            $val = Security::sanitize($_POST[$f] ?? '');

            // Normalize institution phone to include +256 when missing
            if ($f === 'institution_phone') {
                $phone = preg_replace('/[^\d+]/', '', $val); // keep digits and plus sign
                if ($phone === '') {
                    $val = '';
                } else if (strpos($phone, '+') !== 0) {
                    // no country code provided — prepend +256 and trim leading zeros
                    $phone = ltrim($phone, '0');
                    $val = '+256' . $phone;
                } else {
                    // keep user-provided country code
                    $val = $phone;
                }
            }

            saveSetting($conn, $f, $val);
        }
        setFlash('success', 'General settings updated');
        header('Location: ' . $settingsPageUrl . '?tab=general');
        exit;
    }

    if (isset($_POST['save_system'])) {
        $fields = ['timezone','date_format','session_timeout'];
        foreach ($fields as $f) {
            $val = Security::sanitize($_POST[$f] ?? '');
            saveSetting($conn, $f, $val);
        }

        // Backup & log retention settings
        $backupRetention = (int)($_POST['backup_retention_days'] ?? 30);
        $maxBackupFiles = (int)($_POST['backup_retention_max_files'] ?? 30);
        $logRetention = (int)($_POST['log_retention_days'] ?? 90);
        saveSetting($conn, 'backup_retention_days', $backupRetention);
        saveSetting($conn, 'backup_retention_max_files', $maxBackupFiles);
        saveSetting($conn, 'log_retention_days', $logRetention);

        // Scheduled backup settings
        $enabled = isset($_POST['scheduled_backup_enabled']) && $_POST['scheduled_backup_enabled'] == '1' ? '1' : '0';
        $freq = in_array($_POST['scheduled_backup_frequency'] ?? '', ['daily','weekly']) ? $_POST['scheduled_backup_frequency'] : 'daily';
        $timeOfDay = preg_match('/^\d{2}:\d{2}$/', $_POST['scheduled_backup_time'] ?? '') ? $_POST['scheduled_backup_time'] : '02:00';
        $dow = isset($_POST['scheduled_backup_day']) ? (int)$_POST['scheduled_backup_day'] : 0;
        $recips = Security::sanitize($_POST['scheduled_backup_recipients'] ?? '');
        saveSetting($conn, 'scheduled_backup_enabled', $enabled);
        saveSetting($conn, 'scheduled_backup_frequency', $freq);
        saveSetting($conn, 'scheduled_backup_time', $timeOfDay);
        saveSetting($conn, 'scheduled_backup_day', $dow);
        saveSetting($conn, 'scheduled_backup_recipients', $recips);

        setFlash('success', 'System settings updated');
        header('Location: ' . $settingsPageUrl . '?tab=system');
        exit;
    }

    if (isset($_POST['save_email'])) {
        $transport = strtolower(trim((string)($_POST['email_transport'] ?? '')));
        if (!in_array($transport, ['nodemailer', 'php_mail'], true)) {
            $transport = defined('EMAIL_TRANSPORT') ? strtolower((string)EMAIL_TRANSPORT) : 'nodemailer';
        }
        if (!in_array($transport, ['nodemailer', 'php_mail'], true)) {
            $transport = 'nodemailer';
        }

        $fallback = (isset($_POST['email_fallback_php_mail']) && $_POST['email_fallback_php_mail'] == '1') ? '1' : '0';
        $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $smtpPort = 587;
        }
        $smtpUsername = trim((string)($_POST['smtp_username'] ?? ''));
        $smtpSecure = (isset($_POST['smtp_secure']) && $_POST['smtp_secure'] == '1') ? '1' : '0';
        $smtpFromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
        $smtpFromName = Security::sanitize($_POST['smtp_from_name'] ?? '');
        if ($smtpFromEmail !== '' && !filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'SMTP From Email is not a valid email address.');
            header('Location: ' . $settingsPageUrl . '?tab=email');
            exit;
        }

        saveSetting($conn, 'email_transport', $transport);
        saveSetting($conn, 'email_fallback_php_mail', $fallback);
        saveSetting($conn, 'smtp_host', $smtpHost);
        saveSetting($conn, 'smtp_port', (string)$smtpPort);
        saveSetting($conn, 'smtp_username', $smtpUsername);
        saveSetting($conn, 'smtp_secure', $smtpSecure);
        saveSetting($conn, 'smtp_from_email', $smtpFromEmail);
        saveSetting($conn, 'smtp_from_name', $smtpFromName);

        $clearSmtpPassword = isset($_POST['clear_smtp_password']) && $_POST['clear_smtp_password'] == '1';
        $smtpPasswordInput = trim((string)($_POST['smtp_password'] ?? ''));
        if ($clearSmtpPassword) {
            saveSetting($conn, 'smtp_password', '');
        } elseif ($smtpPasswordInput !== '') {
            saveSetting($conn, 'smtp_password', $smtpPasswordInput);
        }

        setFlash('success', 'Email settings updated. Use Email Test to confirm delivery.');
        header('Location: ' . $settingsPageUrl . '?tab=email');
        exit;
    }

    if (isset($_POST['save_security'])) {
        $fields = ['max_login_attempts','account_lockout_duration','min_credit_hours','max_credit_hours','pass_mark'];
        foreach ($fields as $f) {
            $val = Security::sanitize($_POST[$f] ?? '');
            saveSetting($conn, $f, $val);
        }
        // checkbox: auto_approve_registrations
        $auto = isset($_POST['auto_approve_registrations']) && ($_POST['auto_approve_registrations'] == '1') ? '1' : '0';
        saveSetting($conn, 'auto_approve_registrations', $auto);

        // MFA controls
        $mfaEnabled = (isset($_POST['mfa_enabled']) && $_POST['mfa_enabled'] == '1') ? '1' : '0';
        saveSetting($conn, 'mfa_enabled', $mfaEnabled);
        $allowedRoles = ['admin', 'finance', 'lecturer', 'student'];
        $postedRoles = $_POST['mfa_enforced_roles'] ?? [];
        if (!is_array($postedRoles)) {
            $postedRoles = [];
        }
        $postedRoles = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $postedRoles)))));
        $postedRoles = array_values(array_intersect($postedRoles, $allowedRoles));
        saveSetting($conn, 'mfa_enforced_roles', implode(',', $postedRoles));
        $mfaCodeLength = max(4, min(8, (int)($_POST['mfa_code_length'] ?? (defined('MFA_CODE_LENGTH') ? MFA_CODE_LENGTH : 6))));
        $mfaTtl = max(60, min(1800, (int)($_POST['mfa_challenge_ttl_seconds'] ?? (defined('MFA_CHALLENGE_TTL_SECONDS') ? MFA_CHALLENGE_TTL_SECONDS : 300))));
        $mfaMaxAttempts = max(1, min(10, (int)($_POST['mfa_max_attempts'] ?? (defined('MFA_MAX_ATTEMPTS') ? MFA_MAX_ATTEMPTS : 5))));
        saveSetting($conn, 'mfa_code_length', (string)$mfaCodeLength);
        saveSetting($conn, 'mfa_challenge_ttl_seconds', (string)$mfaTtl);
        saveSetting($conn, 'mfa_max_attempts', (string)$mfaMaxAttempts);

        // Privacy consent controls
        $privacyRequired = (isset($_POST['privacy_consent_required']) && $_POST['privacy_consent_required'] == '1') ? '1' : '0';
        saveSetting($conn, 'privacy_consent_required', $privacyRequired);
        $privacyVersionRaw = trim((string)($_POST['privacy_notice_version'] ?? ''));
        $privacyVersion = preg_replace('/[^A-Za-z0-9._\-]/', '', $privacyVersionRaw);
        if ($privacyVersion === '') {
            $privacyVersion = defined('PRIVACY_NOTICE_VERSION') ? PRIVACY_NOTICE_VERSION : date('Y-m-d');
        }
        saveSetting($conn, 'privacy_notice_version', $privacyVersion);
        $privacyKeyRaw = trim((string)($_POST['privacy_consent_key'] ?? ''));
        $privacyKey = preg_replace('/[^A-Za-z0-9._\-]/', '', $privacyKeyRaw);
        if ($privacyKey === '') {
            $privacyKey = defined('PRIVACY_CONSENT_KEY') ? PRIVACY_CONSENT_KEY : 'privacy_notice';
        }
        saveSetting($conn, 'privacy_consent_key', $privacyKey);

        // Backup encryption controls
        $backupEncryptionEnabled = (isset($_POST['backup_encryption_enabled']) && $_POST['backup_encryption_enabled'] == '1') ? '1' : '0';
        saveSetting($conn, 'backup_encryption_enabled', $backupEncryptionEnabled);
        $clearBackupKey = isset($_POST['clear_backup_encryption_key']) && $_POST['clear_backup_encryption_key'] == '1';
        $backupKeyInput = trim((string)($_POST['backup_encryption_key'] ?? ''));
        if ($clearBackupKey) {
            saveSetting($conn, 'backup_encryption_key', '');
        } elseif ($backupKeyInput !== '') {
            if (strlen($backupKeyInput) < 16) {
                setFlash('error', 'Backup encryption key must be at least 16 characters.');
                header('Location: ' . $settingsPageUrl . '?tab=security');
                exit;
            }
            saveSetting($conn, 'backup_encryption_key', $backupKeyInput);
        }

        setFlash('success', 'Security settings updated');
        header('Location: ' . $settingsPageUrl . '?tab=security');
        exit;
    }
}

// Read current settings (use getSetting helper)
$settings = [];
$keys = [
    'institution_name','institution_email','institution_phone','institution_address',
    'academic_year_format','student_id_prefix','admission_prefix','timezone','date_format','session_timeout',
    'max_login_attempts','account_lockout_duration','min_credit_hours','max_credit_hours','pass_mark', 'auto_approve_registrations',
    'mfa_enabled','mfa_enforced_roles','mfa_code_length','mfa_challenge_ttl_seconds','mfa_max_attempts',
    'privacy_consent_required','privacy_notice_version','privacy_consent_key',
    'backup_encryption_enabled','backup_encryption_key',
    'email_transport','email_fallback_php_mail','smtp_host','smtp_port','smtp_username','smtp_password','smtp_secure','smtp_from_email','smtp_from_name',
    // backup/log retention & scheduler
    'backup_retention_days','backup_retention_max_files','log_retention_days',
    'scheduled_backup_enabled','scheduled_backup_frequency','scheduled_backup_time','scheduled_backup_day','scheduled_backup_recipients'
];
foreach ($keys as $k) {
    $settings[$k] = getSetting($k, '');
}

$mfaSelectedRoles = array_filter(array_map('trim', explode(',', strtolower((string)($settings['mfa_enforced_roles'] ?: (defined('MFA_ENFORCED_ROLES') ? MFA_ENFORCED_ROLES : 'admin,finance'))))));
$mfaEnabledSetting = strtolower(trim((string)($settings['mfa_enabled'] !== '' ? $settings['mfa_enabled'] : ((defined('MFA_ENABLED') && MFA_ENABLED) ? '1' : '0'))));
$privacyRequiredSetting = strtolower(trim((string)($settings['privacy_consent_required'] !== '' ? $settings['privacy_consent_required'] : ((defined('PRIVACY_CONSENT_REQUIRED') && PRIVACY_CONSENT_REQUIRED) ? '1' : '0'))));
$backupEncryptionEnabledSetting = strtolower(trim((string)($settings['backup_encryption_enabled'] !== '' ? $settings['backup_encryption_enabled'] : ((defined('BACKUP_ENCRYPTION_ENABLED') && BACKUP_ENCRYPTION_ENABLED) ? '1' : '0'))));
$backupKeyStatus = BackupSecurity::getEncryptionKeyStatus();
$emailTransportSetting = strtolower(trim((string)($settings['email_transport'] !== '' ? $settings['email_transport'] : (defined('EMAIL_TRANSPORT') ? EMAIL_TRANSPORT : 'nodemailer'))));
if (!in_array($emailTransportSetting, ['nodemailer', 'php_mail'], true)) {
    $emailTransportSetting = 'nodemailer';
}
$emailFallbackSetting = strtolower(trim((string)($settings['email_fallback_php_mail'] !== '' ? $settings['email_fallback_php_mail'] : ((defined('EMAIL_FALLBACK_PHP_MAIL') && EMAIL_FALLBACK_PHP_MAIL) ? '1' : '0'))));
$smtpSecureSetting = strtolower(trim((string)($settings['smtp_secure'] !== '' ? $settings['smtp_secure'] : ((defined('SMTP_SECURE') && SMTP_SECURE) ? '1' : '0'))));
$smtpPasswordConfigured = trim((string)($settings['smtp_password'] ?? '')) !== '';

$pageTitle = 'Settings - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h4>Settings</h4>
        </div>
    </div>

    <div class="content-area container-fluid p-4">
        <?php if ($msg = getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($msg); ?></div>
        <?php endif; ?>
        <?php if ($msg = getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($msg); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='general'?'active':''; ?>" href="<?php echo e($settingsPageUrl); ?>?tab=general">General</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='system'?'active':''; ?>" href="<?php echo e($settingsPageUrl); ?>?tab=system">System</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='security'?'active':''; ?>" href="<?php echo e($settingsPageUrl); ?>?tab=security">Security</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='email'?'active':''; ?>" href="<?php echo e($settingsPageUrl); ?>?tab=email">Email</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='notifications'?'active':''; ?>" href="<?php echo e($settingsPageUrl); ?>?tab=notifications">Notifications</a></li>
                </ul>

                <div class="tab-content mt-4">
                    <!-- General -->
                    <div class="tab-pane <?php echo $tab=='general'?'active show':''; ?>" id="general">
                        <form method="POST" action="<?php echo e($settingsPageUrl); ?>?tab=general">
                            <?php echo csrfField(); ?>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Institution Name</label>
                                    <input type="text" name="institution_name" class="form-control" value="<?php echo e($settings['institution_name']); ?>">
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Institution Email</label>
                                    <input type="email" name="institution_email" class="form-control" value="<?php echo e($settings['institution_email']); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Phone</label>
                                    <input type="text" name="institution_phone" class="form-control" placeholder="+256..." value="<?php echo e($settings['institution_phone']); ?>">
                                    <small class="form-text text-muted">Phone numbers missing a country code will automatically be saved with <code>+256</code>.</small>
                                </div>
                                <div class="form-group col-md-8">
                                    <label>Address</label>
                                    <input type="text" name="institution_address" class="form-control" value="<?php echo e($settings['institution_address']); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Academic Year Format</label>
                                    <input type="text" name="academic_year_format" class="form-control" value="<?php echo e($settings['academic_year_format']); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Student ID Prefix</label>
                                    <input type="text" name="student_id_prefix" class="form-control" value="<?php echo e($settings['student_id_prefix']); ?>">
                                    <small class="form-text text-muted">Prefix used when generating student IDs (example below).</small>
                                    <div class="mt-2"><strong>Example:</strong> <?php echo date('Y') . '-' . strtoupper(trim($settings['student_id_prefix'] ?: 'STD')) . '-001'; ?></div>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Admission Prefix</label>
                                    <input type="text" name="admission_prefix" class="form-control" placeholder="ADM-XXX" value="<?php echo e($settings['admission_prefix'] ?: 'ADM-'); ?>">
                                    <small class="form-text text-muted">Prefix used when generating admission numbers (example: <code>ADM-</code> → <code>ADM-<?php echo date('Y'); ?>-0001</code>).</small>
                                </div>
                                <div class="form-group col-md-4 align-self-end">
                                    <button type="submit" name="save_general" class="btn btn-primary">Save General</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- System -->
                    <div class="tab-pane <?php echo $tab=='system'?'active show':''; ?>" id="system">
                        <form method="POST" action="<?php echo e($settingsPageUrl); ?>?tab=system">
                            <?php echo csrfField(); ?>
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Timezone</label>
                                    <input type="text" name="timezone" class="form-control" value="<?php echo e($settings['timezone'] ?: 'UTC'); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Date Format</label>
                                    <input type="text" name="date_format" class="form-control" value="<?php echo e($settings['date_format'] ?: 'Y-m-d'); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Session timeout (seconds)</label>
                                    <input type="number" name="session_timeout" class="form-control" value="<?php echo e($settings['session_timeout'] ?: 3600); ?>">
                                </div>
                            </div>

                            <hr>

                            <h6>Backup & Log Retention</h6>
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <label>Backup retention (days)</label>
                                    <input type="number" name="backup_retention_days" class="form-control" value="<?php echo e($settings['backup_retention_days'] ?: (defined('BACKUP_RETENTION_DAYS') ? BACKUP_RETENTION_DAYS : 30)); ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>Max backup files</label>
                                    <input type="number" name="backup_retention_max_files" class="form-control" value="<?php echo e($settings['backup_retention_max_files'] ?: (defined('BACKUP_RETENTION_MAX_FILES') ? BACKUP_RETENTION_MAX_FILES : 30)); ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>Log retention (days)</label>
                                    <input type="number" name="log_retention_days" class="form-control" value="<?php echo e($settings['log_retention_days'] ?: (defined('LOG_RETENTION_DAYS') ? LOG_RETENTION_DAYS : 90)); ?>">
                                </div>
                            </div>

                            <hr>

                            <h6>Scheduled Backups</h6>
                            <div class="form-row">
                                <div class="form-group col-md-2">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="schedEnabled" name="scheduled_backup_enabled" value="1" <?php echo ($settings['scheduled_backup_enabled'] ?? '') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="schedEnabled">Enabled</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-3">
                                    <label>Frequency</label>
                                    <select name="scheduled_backup_frequency" class="form-control">
                                        <option value="daily" <?php echo ($settings['scheduled_backup_frequency'] ?? '') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo ($settings['scheduled_backup_frequency'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label>Time (HH:MM)</label>
                                    <input type="time" name="scheduled_backup_time" class="form-control" value="<?php echo e($settings['scheduled_backup_time'] ?: '02:00'); ?>">
                                </div>
                                <div class="form-group col-md-2">
                                    <label>Day (weekly)</label>
                                    <select name="scheduled_backup_day" class="form-control">
                                        <?php for ($d=0;$d<7;$d++): $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; ?>
                                            <option value="<?php echo $d; ?>" <?php echo (int)$settings['scheduled_backup_day'] === $d ? 'selected' : ''; ?>><?php echo $days[$d]; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-12 mt-2">
                                    <label>Recipients (comma-separated emails)</label>
                                    <input type="text" name="scheduled_backup_recipients" class="form-control" value="<?php echo e($settings['scheduled_backup_recipients'] ?? getSetting('institution_email')); ?>">
                                    <small class="form-text text-muted">Enter admin emails to receive notifications when scheduled backups run or fail.</small>
                                </div>
                            </div>

                            <div class="form-row mt-3">
                                <div class="form-group col-md-12">
                                    <button type="submit" name="save_system" class="btn btn-primary">Save System</button>
                                    <form method="post" action="../system/health.php" style="display:inline-block;margin-left:10px;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="run_scheduled_backup">
                                        <button type="submit" class="btn btn-outline-secondary">Run scheduled backup now</button>
                                    </form>
                                    <div class="mt-3 text-muted">Cron example (add to server crontab): <code>0 2 * * * /usr/bin/php <?php echo BASE_PATH; ?>/scripts/backup_cron.php</code></div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Security -->
                    <div class="tab-pane <?php echo $tab=='security'?'active show':''; ?>" id="security">
                        <form method="POST" action="<?php echo e($settingsPageUrl); ?>?tab=security">
                            <?php echo csrfField(); ?>
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Max login attempts</label>
                                    <input type="number" name="max_login_attempts" class="form-control" value="<?php echo e($settings['max_login_attempts'] ?: 5); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Account lockout (minutes)</label>
                                    <input type="number" name="account_lockout_duration" class="form-control" value="<?php echo e($settings['account_lockout_duration'] ?: 30); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Pass mark (%)</label>
                                    <input type="number" name="pass_mark" class="form-control" value="<?php echo e($settings['pass_mark'] ?: 50); ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <label>Min CU</label>
                                    <input type="number" name="min_credit_hours" class="form-control" value="<?php echo e($settings['min_credit_hours'] ?: 12); ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>Max CU</label>
                                    <input type="number" name="max_credit_hours" class="form-control" value="<?php echo e($settings['max_credit_hours'] ?: 21); ?>">
                                </div>
                                <div class="form-group col-md-6">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="autoApprove" name="auto_approve_registrations" value="1" <?php echo ($settings['auto_approve_registrations'] ?? '') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="autoApprove">Auto-approve course registrations on student submit</label>
                                        <small class="form-text text-muted">When enabled, course registrations submitted by students will be automatically approved.</small>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h6>MFA Configuration</h6>
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="mfaEnabled" name="mfa_enabled" value="1" <?php echo in_array($mfaEnabledSetting, ['1','true','yes','on'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="mfaEnabled">Enable MFA</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-3">
                                    <label>OTP Length</label>
                                    <input type="number" min="4" max="8" name="mfa_code_length" class="form-control" value="<?php echo e($settings['mfa_code_length'] ?: (defined('MFA_CODE_LENGTH') ? MFA_CODE_LENGTH : 6)); ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>OTP TTL (seconds)</label>
                                    <input type="number" min="60" max="1800" name="mfa_challenge_ttl_seconds" class="form-control" value="<?php echo e($settings['mfa_challenge_ttl_seconds'] ?: (defined('MFA_CHALLENGE_TTL_SECONDS') ? MFA_CHALLENGE_TTL_SECONDS : 300)); ?>">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>Max OTP Attempts</label>
                                    <input type="number" min="1" max="10" name="mfa_max_attempts" class="form-control" value="<?php echo e($settings['mfa_max_attempts'] ?: (defined('MFA_MAX_ATTEMPTS') ? MFA_MAX_ATTEMPTS : 5)); ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-12">
                                    <label>Roles Enforced for MFA</label>
                                    <div class="d-flex flex-wrap" style="gap:18px;">
                                        <?php foreach (['admin' => 'Admin', 'finance' => 'Finance', 'lecturer' => 'Lecturer', 'student' => 'Student'] as $roleKey => $roleLabel): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="mfaRole_<?php echo e($roleKey); ?>" name="mfa_enforced_roles[]" value="<?php echo e($roleKey); ?>" <?php echo in_array($roleKey, $mfaSelectedRoles, true) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="mfaRole_<?php echo e($roleKey); ?>"><?php echo e($roleLabel); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h6>Privacy Consent</h6>
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="privacyConsentRequired" name="privacy_consent_required" value="1" <?php echo in_array($privacyRequiredSetting, ['1','true','yes','on'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="privacyConsentRequired">Require Consent at Login</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-5">
                                    <label>Consent Version</label>
                                    <input type="text" name="privacy_notice_version" class="form-control" value="<?php echo e($settings['privacy_notice_version'] ?: (defined('PRIVACY_NOTICE_VERSION') ? PRIVACY_NOTICE_VERSION : date('Y-m-d'))); ?>">
                                    <small class="form-text text-muted">Change this version when the privacy notice changes to force re-consent.</small>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Consent Key</label>
                                    <input type="text" name="privacy_consent_key" class="form-control" value="<?php echo e($settings['privacy_consent_key'] ?: (defined('PRIVACY_CONSENT_KEY') ? PRIVACY_CONSENT_KEY : 'privacy_notice')); ?>">
                                </div>
                            </div>

                            <hr>

                            <h6>Backup Encryption Key</h6>
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="backupEncryptionEnabled" name="backup_encryption_enabled" value="1" <?php echo in_array($backupEncryptionEnabledSetting, ['1','true','yes','on'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="backupEncryptionEnabled">Enable Backup Encryption</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Key Source Status</label>
                                    <div>
                                        <?php if ($backupKeyStatus['source'] === 'environment'): ?>
                                            <span class="badge badge-success">Configured from Environment</span>
                                        <?php elseif ($backupKeyStatus['source'] === 'database'): ?>
                                            <span class="badge badge-primary">Configured in Database</span>
                                        <?php elseif ($backupKeyStatus['source'] === 'fallback'): ?>
                                            <span class="badge badge-warning">Using Fallback Derived Key</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">No Key Available</span>
                                        <?php endif; ?>
                                        <?php if (!$backupKeyStatus['enabled']): ?>
                                            <span class="badge badge-secondary ml-2">Encryption Disabled</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-group col-md-5">
                                    <label>Set / Rotate Database Key</label>
                                    <input type="password" name="backup_encryption_key" class="form-control" autocomplete="new-password" placeholder="Enter at least 16 characters">
                                    <small class="form-text text-muted">Leave blank to keep current key. Environment key (if set) always takes precedence.</small>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="clearBackupKey" name="clear_backup_encryption_key" value="1">
                                        <label class="form-check-label" for="clearBackupKey">Clear database-stored key</label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-12">
                                    <button type="submit" name="save_security" class="btn btn-primary">Save Security</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Email -->
                    <div class="tab-pane <?php echo $tab=='email'?'active show':''; ?>" id="email">
                        <form method="POST" action="<?php echo e($settingsPageUrl); ?>?tab=email">
                            <?php echo csrfField(); ?>
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Email Transport</label>
                                    <select name="email_transport" class="form-control">
                                        <option value="nodemailer" <?php echo $emailTransportSetting === 'nodemailer' ? 'selected' : ''; ?>>Nodemailer (SMTP)</option>
                                        <option value="php_mail" <?php echo $emailTransportSetting === 'php_mail' ? 'selected' : ''; ?>>PHP mail()</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Fallback</label>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="emailFallbackPhpMail" name="email_fallback_php_mail" value="1" <?php echo in_array($emailFallbackSetting, ['1','true','yes','on'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="emailFallbackPhpMail">Allow fallback to PHP mail()</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>SMTP Port</label>
                                    <input type="number" min="1" max="65535" name="smtp_port" class="form-control" value="<?php echo e($settings['smtp_port'] ?: (defined('SMTP_PORT') ? SMTP_PORT : 587)); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>SMTP Host</label>
                                    <input type="text" name="smtp_host" class="form-control" value="<?php echo e($settings['smtp_host'] ?: (defined('SMTP_HOST') ? SMTP_HOST : '')); ?>" placeholder="smtp.gmail.com">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>SMTP Username</label>
                                    <input type="text" name="smtp_username" class="form-control" value="<?php echo e($settings['smtp_username'] ?: (defined('SMTP_USERNAME') ? SMTP_USERNAME : '')); ?>" placeholder="your-email@example.com">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Connection Security</label>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="smtpSecure" name="smtp_secure" value="1" <?php echo in_array($smtpSecureSetting, ['1','true','yes','on'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="smtpSecure">Use SSL/TLS (typically port 465)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>SMTP Password / App Password</label>
                                    <input type="password" name="smtp_password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current">
                                    <small class="form-text text-muted">Password status: <?php echo $smtpPasswordConfigured ? 'Configured in database' : 'Not configured in database'; ?></small>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="clearSmtpPassword" name="clear_smtp_password" value="1">
                                        <label class="form-check-label" for="clearSmtpPassword">Clear stored SMTP password</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>From Email</label>
                                    <input type="email" name="smtp_from_email" class="form-control" value="<?php echo e($settings['smtp_from_email'] ?: (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '')); ?>" placeholder="noreply@example.com">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>From Name</label>
                                    <input type="text" name="smtp_from_name" class="form-control" value="<?php echo e($settings['smtp_from_name'] ?: (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : APP_NAME)); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-12">
                                    <button type="submit" name="save_email" class="btn btn-primary">Save Email Settings</button>
                                    <a href="<?php echo BASE_URL; ?>/views/admin/email-test.php" class="btn btn-outline-secondary ml-2">Open Email Test</a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Notifications (placeholder) -->
                    <div class="tab-pane <?php echo $tab=='notifications'?'active show':''; ?>" id="notifications">
                        <div class="alert alert-light">Notification templates and notification routing can be managed in the <code>email_templates</code> and <code>notifications</code> areas. (Placeholder)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
