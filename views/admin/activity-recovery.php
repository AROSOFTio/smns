<?php
/**
 * Activity Recovery / Export
 * Unified timeline from activity_logs, admin_communications, and recipient delivery rows.
 */
require_once '../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();

try {
    if (class_exists('AdminCommunicationService')) {
        $svc = new AdminCommunicationService($conn, new Logger());
        $svc->ensureTables();
    }
} catch (Exception $e) {
    // Continue; recovery page should still work with available tables.
}

function arNormalizeDate($value, $fallback)
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    $dt = date_create($raw);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function arTableExists(PDO $conn, $tableName)
{
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute(['table_name' => (string)$tableName]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function arShortText($text, $limit = 180)
{
    $value = trim((string)$text);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, $limit) . '...';
    }
    if (strlen($value) <= $limit) {
        return $value;
    }
    return substr($value, 0, $limit) . '...';
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$from = arNormalizeDate($_GET['from'] ?? '', $defaultFrom);
$to = arNormalizeDate($_GET['to'] ?? '', $today);
if ($from > $to) {
    $swap = $from;
    $from = $to;
    $to = $swap;
}

$sourceFilter = strtolower(trim((string)($_GET['source'] ?? 'all')));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 300);
if ($limit < 50) {
    $limit = 50;
}
if ($limit > 2000) {
    $limit = 2000;
}
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$allowedSource = ['all', 'activity', 'communications', 'recipients'];
if (!in_array($sourceFilter, $allowedSource, true)) {
    $sourceFilter = 'all';
}

$allowedStatus = ['all', 'logged', 'processing', 'completed', 'partial', 'failed', 'sent', 'not_sent'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$fromTs = $from . ' 00:00:00';
$toTs = $to . ' 23:59:59';
$perSourceLimit = min(3000, max(200, $limit));

$timeline = [];
$warnings = [];

$hasActivityLogs = arTableExists($conn, 'activity_logs');
$hasCommunications = arTableExists($conn, 'admin_communications');
$hasRecipients = arTableExists($conn, 'admin_communication_recipients');

if (!$hasActivityLogs) {
    $warnings[] = 'Table activity_logs not found. Activity logs are unavailable.';
}
if (!$hasCommunications) {
    $warnings[] = 'Table admin_communications not found. Communication summary records are unavailable.';
}
if (!$hasRecipients) {
    $warnings[] = 'Table admin_communication_recipients not found. Recipient delivery records are unavailable.';
}

if ($hasActivityLogs) {
    try {
        $sql = "SELECT
                    al.id,
                    al.created_at,
                    al.action,
                    al.module,
                    al.description,
                    al.ip_address,
                    u.username,
                    u.role AS user_role,
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', l.first_name, l.last_name)), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                        u.username,
                        'System'
                    ) AS actor_name
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN admins a ON u.id = a.user_id
                LEFT JOIN lecturers l ON u.id = l.user_id
                LEFT JOIN finance_staff f ON u.id = f.user_id
                LEFT JOIN students s ON u.id = s.user_id
                WHERE al.created_at BETWEEN :from_ts AND :to_ts
                ORDER BY al.created_at DESC
                LIMIT :lim";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
        $stmt->bindValue(':to_ts', $toTs, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $perSourceLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $timeline[] = [
                'time' => (string)($row['created_at'] ?? ''),
                'source' => 'activity',
                'source_label' => 'Activity Log',
                'status' => 'logged',
                'event' => strtoupper((string)($row['action'] ?? 'activity')),
                'actor' => (string)($row['actor_name'] ?? ($row['username'] ?? 'System')),
                'target' => (string)($row['module'] ?? 'system'),
                'summary' => trim((string)($row['description'] ?? '')),
                'reference' => 'activity_logs#' . (int)($row['id'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        $warnings[] = 'Failed to read activity_logs: ' . $e->getMessage();
    }
}

if ($hasCommunications) {
    try {
        $sql = "SELECT
                    c.id,
                    c.created_at,
                    c.communication_type,
                    c.source,
                    c.title,
                    c.message,
                    c.audience_scope,
                    c.total_recipients,
                    c.portal_success_count,
                    c.email_success_count,
                    c.email_fail_count,
                    c.status,
                    u.username,
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                        u.username,
                        'System'
                    ) AS actor_name
                FROM admin_communications c
                LEFT JOIN users u ON c.created_by_user_id = u.id
                LEFT JOIN admins a ON u.id = a.user_id
                WHERE c.created_at BETWEEN :from_ts AND :to_ts
                ORDER BY c.created_at DESC
                LIMIT :lim";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
        $stmt->bindValue(':to_ts', $toTs, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $perSourceLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $title = trim((string)($row['title'] ?? ''));
            $summary = $title !== '' ? $title : 'Communication sent';
            $summary .= ' | recipients=' . (int)($row['total_recipients'] ?? 0);
            $summary .= ', portal=' . (int)($row['portal_success_count'] ?? 0);
            $summary .= ', email_ok=' . (int)($row['email_success_count'] ?? 0);
            $summary .= ', email_fail=' . (int)($row['email_fail_count'] ?? 0);

            $timeline[] = [
                'time' => (string)($row['created_at'] ?? ''),
                'source' => 'communications',
                'source_label' => 'Communication',
                'status' => strtolower((string)($row['status'] ?? 'completed')),
                'event' => ucwords(str_replace('_', ' ', (string)($row['communication_type'] ?? 'general'))),
                'actor' => (string)($row['actor_name'] ?? ($row['username'] ?? 'System')),
                'target' => 'Audience: ' . (string)($row['audience_scope'] ?? 'all_students'),
                'summary' => $summary,
                'reference' => 'admin_communications#' . (int)($row['id'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        $warnings[] = 'Failed to read admin_communications: ' . $e->getMessage();
    }
}

if ($hasCommunications && $hasRecipients) {
    try {
        $sql = "SELECT
                    r.id,
                    r.communication_id,
                    r.created_at,
                    r.recipient_email,
                    r.portal_notified,
                    r.email_sent,
                    r.email_error,
                    c.title AS communication_title,
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                        u.username,
                        r.recipient_email,
                        CONCAT('User #', r.user_id)
                    ) AS recipient_name,
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                        cu.username,
                        'System'
                    ) AS sender_name
                FROM admin_communication_recipients r
                INNER JOIN admin_communications c ON c.id = r.communication_id
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN students s ON r.student_id = s.id
                LEFT JOIN users cu ON c.created_by_user_id = cu.id
                LEFT JOIN admins a ON cu.id = a.user_id
                WHERE r.created_at BETWEEN :from_ts AND :to_ts
                ORDER BY r.created_at DESC
                LIMIT :lim";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
        $stmt->bindValue(':to_ts', $toTs, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $perSourceLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $portalSent = (int)($row['portal_notified'] ?? 0) === 1;
            $emailSent = (int)($row['email_sent'] ?? 0) === 1;
            $emailError = trim((string)($row['email_error'] ?? ''));

            $status = 'not_sent';
            if ($portalSent && $emailSent) {
                $status = 'sent';
            } elseif ($portalSent || $emailSent) {
                $status = 'partial';
            } elseif ($emailError !== '') {
                $status = 'failed';
            }

            $summary = 'Comm #' . (int)($row['communication_id'] ?? 0) . ': ' . trim((string)($row['communication_title'] ?? 'Communication'));
            $summary .= ' | portal=' . ($portalSent ? 'sent' : 'not_sent');
            $summary .= ', email=' . ($emailSent ? 'sent' : 'not_sent');
            if ($emailError !== '') {
                $summary .= ', error=' . arShortText($emailError, 220);
            }

            $target = trim((string)($row['recipient_name'] ?? 'Recipient'));
            $recipientEmail = trim((string)($row['recipient_email'] ?? ''));
            if ($recipientEmail !== '' && stripos($target, $recipientEmail) === false) {
                $target .= ' <' . $recipientEmail . '>';
            }

            $timeline[] = [
                'time' => (string)($row['created_at'] ?? ''),
                'source' => 'recipients',
                'source_label' => 'Recipient Delivery',
                'status' => $status,
                'event' => 'Recipient Delivery',
                'actor' => (string)($row['sender_name'] ?? 'System'),
                'target' => $target,
                'summary' => $summary,
                'reference' => 'admin_communication_recipients#' . (int)($row['id'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        $warnings[] = 'Failed to read admin_communication_recipients: ' . $e->getMessage();
    }
}

usort($timeline, function ($a, $b) {
    $ta = (string)($a['time'] ?? '');
    $tb = (string)($b['time'] ?? '');
    return strcmp($tb, $ta);
});

$filtered = [];
foreach ($timeline as $row) {
    if ($sourceFilter !== 'all' && (string)$row['source'] !== $sourceFilter) {
        continue;
    }
    if ($statusFilter !== 'all' && (string)$row['status'] !== $statusFilter) {
        continue;
    }
    if ($q !== '') {
        $haystack = strtolower(
            (string)$row['event'] . ' ' .
            (string)$row['actor'] . ' ' .
            (string)$row['target'] . ' ' .
            (string)$row['summary'] . ' ' .
            (string)$row['reference']
        );
        if (strpos($haystack, strtolower($q)) === false) {
            continue;
        }
    }
    $filtered[] = $row;
}

$totalBeforeLimit = count($filtered);
if ($totalBeforeLimit > $limit) {
    $filtered = array_slice($filtered, 0, $limit);
}

$sourceCounts = ['activity' => 0, 'communications' => 0, 'recipients' => 0];
foreach ($filtered as $row) {
    $src = (string)($row['source'] ?? '');
    if (isset($sourceCounts[$src])) {
        $sourceCounts[$src]++;
    }
}

if ($export === 'csv' || $export === 'excel') {
    $isExcel = ($export === 'excel');
    $filename = 'activity_recovery_' . $from . '_to_' . $to . ($isExcel ? '.xls' : '.csv');
    if ($isExcel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time', 'Source', 'Status', 'Event', 'Actor', 'Target', 'Summary', 'Reference']);
    foreach ($filtered as $row) {
        fputcsv($out, [
            (string)($row['time'] ?? ''),
            (string)($row['source_label'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['event'] ?? ''),
            (string)($row['actor'] ?? ''),
            (string)($row['target'] ?? ''),
            (string)($row['summary'] ?? ''),
            (string)($row['reference'] ?? '')
        ]);
    }
    fclose($out);
    exit;
}

$csvExportQuery = $_GET;
$csvExportQuery['export'] = 'csv';
$csvExportHref = '?' . http_build_query($csvExportQuery);

$excelExportQuery = $_GET;
$excelExportQuery['export'] = 'excel';
$excelExportHref = '?' . http_build_query($excelExportQuery);

$pageTitle = 'Activity Recovery & Export - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/admin/sidebar.php'; ?>

<style>
.activity-recovery-page .content-area { max-width: 100%; overflow-x: hidden; }
.activity-recovery-page .table-wrap { overflow-x: auto; }
.activity-recovery-page table { width: 100%; min-width: 980px; }
.activity-recovery-page .mono { font-family: Consolas, Monaco, monospace; font-size: 12px; }
.activity-recovery-page .badge-status { text-transform: uppercase; font-size: 10px; letter-spacing: 0.4px; }
</style>

<div class="main-content activity-recovery-page">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-history mr-1"></i> Activity Recovery & Export</h4>
        </div>
        <div class="topbar-right">
            <a href="<?php echo e($csvExportHref); ?>" class="btn btn-sm btn-outline-primary mr-2">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a href="<?php echo e($excelExportHref); ?>" class="btn btn-sm btn-outline-success">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
        </div>
    </div>

    <div class="content-area container-fluid p-4">
        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning">
                <?php foreach ($warnings as $warn): ?>
                    <div><?php echo e($warn); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header"><strong>Filters</strong></div>
            <div class="card-body">
                <form method="get" class="form-row">
                    <div class="form-group col-md-2">
                        <label>From</label>
                        <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label>To</label>
                        <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label>Source</label>
                        <select name="source" class="form-control">
                            <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="activity" <?php echo $sourceFilter === 'activity' ? 'selected' : ''; ?>>Activity Logs</option>
                            <option value="communications" <?php echo $sourceFilter === 'communications' ? 'selected' : ''; ?>>Communications</option>
                            <option value="recipients" <?php echo $sourceFilter === 'recipients' ? 'selected' : ''; ?>>Recipient Delivery</option>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <?php foreach ($allowedStatus as $st): ?>
                                <option value="<?php echo e($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>>
                                    <?php echo e($st === 'all' ? 'All' : strtoupper($st)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label>Limit</label>
                        <input type="number" min="50" max="2000" name="limit" class="form-control" value="<?php echo (int)$limit; ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label>Search</label>
                        <input type="text" name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="actor, target, text">
                    </div>
                    <div class="form-group col-12 mb-0">
                        <button type="submit" class="btn btn-primary mr-2"><i class="fas fa-filter"></i> Apply</button>
                        <a href="activity-recovery.php" class="btn btn-outline-secondary"><i class="fas fa-undo"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap" style="gap:14px;">
                    <span><strong>Total shown:</strong> <?php echo number_format((int)count($filtered)); ?> / <?php echo number_format((int)$totalBeforeLimit); ?></span>
                    <span><strong>Activity:</strong> <?php echo number_format((int)$sourceCounts['activity']); ?></span>
                    <span><strong>Communications:</strong> <?php echo number_format((int)$sourceCounts['communications']); ?></span>
                    <span><strong>Recipients:</strong> <?php echo number_format((int)$sourceCounts['recipients']); ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Recovered Timeline</strong></div>
            <div class="card-body p-0">
                <div class="table-wrap">
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:170px;">Time</th>
                                <th style="width:140px;">Source</th>
                                <th style="width:110px;">Status</th>
                                <th style="width:160px;">Event</th>
                                <th style="width:170px;">Actor</th>
                                <th style="width:220px;">Target</th>
                                <th>Summary</th>
                                <th style="width:170px;">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted p-4">No timeline entries match your current filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered as $row): ?>
                                    <?php
                                        $status = (string)($row['status'] ?? 'logged');
                                        $badge = 'secondary';
                                        if (in_array($status, ['sent', 'completed'], true)) {
                                            $badge = 'success';
                                        } elseif ($status === 'processing') {
                                            $badge = 'info';
                                        } elseif ($status === 'partial') {
                                            $badge = 'warning';
                                        } elseif ($status === 'failed') {
                                            $badge = 'danger';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo e(Helper::formatDateTime((string)($row['time'] ?? ''), 'M d, Y g:i:s A')); ?></td>
                                        <td><?php echo e($row['source_label'] ?? '-'); ?></td>
                                        <td><span class="badge badge-<?php echo e($badge); ?> badge-status"><?php echo e($status); ?></span></td>
                                        <td><?php echo e($row['event'] ?? '-'); ?></td>
                                        <td><?php echo e($row['actor'] ?? '-'); ?></td>
                                        <td><?php echo e(arShortText((string)($row['target'] ?? '-'), 120)); ?></td>
                                        <td><?php echo e(arShortText((string)($row['summary'] ?? '-'), 260)); ?></td>
                                        <td class="mono"><?php echo e($row['reference'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
