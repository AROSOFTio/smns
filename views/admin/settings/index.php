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

// Active tab
$tab = $_GET['tab'] ?? 'general';

// Helpers
function saveSetting($conn, $key, $value) {
    $stmt = $conn->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
    $stmt->execute(['value' => $value, 'key' => $key]);
    if ($stmt->rowCount() === 0) {
        $ins = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)");
        $ins->execute(['key' => $key, 'value' => $value]);
    }
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid CSRF token');
        header('Location: index.php?tab=' . urlencode($tab));
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
        header('Location: index.php?tab=general');
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
        header('Location: index.php?tab=system');
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

        setFlash('success', 'Security settings updated');
        header('Location: index.php?tab=security');
        exit;
    }
}

// Read current settings (use getSetting helper)
$settings = [];
$keys = [
    'institution_name','institution_email','institution_phone','institution_address',
    'academic_year_format','student_id_prefix','admission_prefix','timezone','date_format','session_timeout',
    'max_login_attempts','account_lockout_duration','min_credit_hours','max_credit_hours','pass_mark', 'auto_approve_registrations',
    // backup/log retention & scheduler
    'backup_retention_days','backup_retention_max_files','log_retention_days',
    'scheduled_backup_enabled','scheduled_backup_frequency','scheduled_backup_time','scheduled_backup_day','scheduled_backup_recipients'
];
foreach ($keys as $k) {
    $settings[$k] = getSetting($k, '');
}

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
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='general'?'active':''; ?>" href="?tab=general">General</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='system'?'active':''; ?>" href="?tab=system">System</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='security'?'active':''; ?>" href="?tab=security">Security</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='email'?'active':''; ?>" href="?tab=email">Email</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $tab=='notifications'?'active':''; ?>" href="?tab=notifications">Notifications</a></li>
                </ul>

                <div class="tab-content mt-4">
                    <!-- General -->
                    <div class="tab-pane <?php echo $tab=='general'?'active show':''; ?>" id="general">
                        <form method="POST" action="?tab=general">
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
                                    <small class="form-text text-muted">Prefix used when generating admission numbers (example: <code>ADM-</code> → <code>ADM-2026-0001</code>).</small>
                                </div>
                                <div class="form-group col-md-4 align-self-end">
                                    <button type="submit" name="save_general" class="btn btn-primary">Save General</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- System -->
                    <div class="tab-pane <?php echo $tab=='system'?'active show':''; ?>" id="system">
                        <form method="POST" action="?tab=system">
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
                        <form method="POST" action="?tab=security">
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
                                <div class="form-group col-md-6 align-self-end">
                                    <button type="submit" name="save_security" class="btn btn-primary">Save Security</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Email (placeholder) -->
                    <div class="tab-pane <?php echo $tab=='email'?'active show':''; ?>" id="email">
                        <div class="alert alert-info">SMTP settings are defined in <code>config.php</code>. Use the <a href="<?php echo BASE_URL; ?>/views/admin/email-test.php">Email Test</a> page to verify configuration.</div>
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