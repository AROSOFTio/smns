<?php
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();
$issuanceService = new TranscriptIssuanceService($conn);
$issuanceService->ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'revoke_issuance') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: issued-transcripts.php');
        exit;
    }

    $issuanceId = (int)($_POST['issuance_id'] ?? 0);
    $reason = trim((string)($_POST['revoke_reason'] ?? ''));
    try {
        $revoked = $issuanceService->revokeIssuance($issuanceId, (int)($currentUser['id'] ?? 0), $reason);
        if ($revoked) {
            try {
                (new Logger())->log((int)($currentUser['id'] ?? 0), 'transcript_issuance_revoked', 'students', 'Revoked transcript issuance #' . $issuanceId);
            } catch (Exception $e) {
            }
            $session->setFlash('success', 'Issued transcript revoked.');
        } else {
            $session->setFlash('error', 'Issued transcript could not be revoked.');
        }
    } catch (Exception $e) {
        $session->setFlash('error', 'Failed to revoke issued transcript.');
    }
    header('Location: issued-transcripts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'mark_dispatched') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: issued-transcripts.php');
        exit;
    }

    $issuanceId = (int)($_POST['issuance_id'] ?? 0);
    $dispatchMethod = trim((string)($_POST['dispatch_method'] ?? ''));
    $dispatchReference = trim((string)($_POST['dispatch_reference'] ?? ''));
    $dispatchNotes = trim((string)($_POST['dispatch_notes'] ?? ''));

    try {
        $updated = $issuanceService->markDispatched($issuanceId, (int)($currentUser['id'] ?? 0), $dispatchMethod, $dispatchReference, $dispatchNotes);
        if ($updated) {
            $session->setFlash('success', 'Transcript marked as dispatched.');
        } else {
            $session->setFlash('error', 'Transcript could not be marked as dispatched.');
        }
    } catch (Exception $e) {
        $session->setFlash('error', 'Failed to update transcript dispatch status.');
    }

    header('Location: issued-transcripts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'mark_distributed') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: issued-transcripts.php');
        exit;
    }

    $issuanceId = (int)($_POST['issuance_id'] ?? 0);
    $distributionNotes = trim((string)($_POST['distribution_notes'] ?? ''));

    try {
        $updated = $issuanceService->markDistributed($issuanceId, (int)($currentUser['id'] ?? 0), $distributionNotes);
        if ($updated) {
            $session->setFlash('success', 'Transcript marked as distributed.');
        } else {
            $session->setFlash('error', 'Transcript could not be marked as distributed.');
        }
    } catch (Exception $e) {
        $session->setFlash('error', 'Failed to update transcript distribution status.');
    }

    header('Location: issued-transcripts.php');
    exit;
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'active')));
if (!in_array($statusFilter, ['active', 'revoked', 'all'], true)) {
    $statusFilter = 'active';
}
$distributionFilter = strtolower(trim((string)($_GET['distribution'] ?? 'all')));
if (!in_array($distributionFilter, ['all', 'to_be_distributed', 'dispatched', 'distributed'], true)) {
    $distributionFilter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

$summary = [
    'total' => 0,
    'active_total' => 0,
    'revoked_total' => 0,
    'student_count' => 0,
    'to_be_distributed_total' => 0,
    'dispatched_total' => 0,
    'distributed_total' => 0,
];
$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_total,
        SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked_total,
        SUM(CASE WHEN distribution_status = 'to_be_distributed' THEN 1 ELSE 0 END) AS to_be_distributed_total,
        SUM(CASE WHEN distribution_status = 'dispatched' THEN 1 ELSE 0 END) AS dispatched_total,
        SUM(CASE WHEN distribution_status = 'distributed' THEN 1 ELSE 0 END) AS distributed_total,
        COUNT(DISTINCT student_id) AS student_count
    FROM transcript_issuances
");
if ($summaryStmt) {
    $summary = array_merge($summary, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

$sql = "
    SELECT
        ti.*,
        s.student_id AS student_identifier,
        s.first_name,
        s.last_name,
        p.program_code,
        p.program_name
    FROM transcript_issuances ti
    INNER JOIN students s ON s.id = ti.student_id
    LEFT JOIN programs p ON p.id = s.program_id
    WHERE 1 = 1
";
$params = [];
if ($statusFilter !== 'all') {
    $sql .= " AND ti.status = :status";
    $params['status'] = $statusFilter;
}
if ($distributionFilter !== 'all') {
    $sql .= " AND ti.distribution_status = :distribution_status";
    $params['distribution_status'] = $distributionFilter;
}
if ($search !== '') {
    $sql .= " AND (
        s.student_id LIKE :search
        OR s.first_name LIKE :search
        OR s.last_name LIKE :search
        OR CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) LIKE :search
        OR CONCAT(COALESCE(s.last_name, ''), ' ', COALESCE(s.first_name, '')) LIKE :search
        OR COALESCE(p.program_code, '') LIKE :search
        OR COALESCE(p.program_name, '') LIKE :search
        OR ti.verification_code LIKE :search
        OR ti.verification_token LIKE :search
        OR ti.dispatch_reference LIKE :search
        OR ti.dispatch_method LIKE :search
        OR COALESCE(ti.distribution_status, '') LIKE :search
        OR COALESCE(ti.status, '') LIKE :search
    )";
    $params['search'] = '%' . $search . '%';
}
$sql .= " ORDER BY ti.id DESC LIMIT 200";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Issued Transcripts - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>

<style>
.it-shell { padding: 20px; }
.it-topbar { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:18px; }
.it-topbar h4 { margin:0; }
.it-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:18px; }
.it-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:16px; box-shadow:0 10px 24px rgba(15,23,42,.05); }
.it-label { color:#64748b; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
.it-value { font-size:28px; font-weight:700; color:#0f172a; margin-top:8px; }
.it-panel { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:18px; box-shadow:0 10px 24px rgba(15,23,42,.05); }
.it-filter { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
.it-table { width:100%; border-collapse:collapse; font-size:13px; }
.it-table th, .it-table td { border-bottom:1px solid #e5e7eb; padding:10px 8px; vertical-align:top; text-align:left; }
.it-badge { display:inline-block; padding:4px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.it-badge.active { background:#dcfce7; color:#166534; }
.it-badge.revoked { background:#fee2e2; color:#991b1b; }
.it-badge.to_be_distributed { background:#fef3c7; color:#92400e; }
.it-badge.dispatched { background:#dbeafe; color:#1d4ed8; }
.it-badge.distributed { background:#dcfce7; color:#166534; }
.it-link { font-size:12px; word-break:break-all; }
.it-form { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.it-form input { min-width:180px; }
.it-stack { display:flex; flex-direction:column; gap:8px; }
.it-subtle { color:#64748b; font-size:12px; }
.it-actions-cell { min-width:280px; }
html[data-theme='dark'] .it-topbar h4 { color:#e2e8f0; }
html[data-theme='dark'] .it-topbar .text-muted,
html[data-theme='dark'] .it-panel .text-muted,
html[data-theme='dark'] .it-table .text-muted { color:#93c5fd !important; }
html[data-theme='dark'] .it-card,
html[data-theme='dark'] .it-panel { background:#0f172a; border-color:#243047; box-shadow:0 10px 24px rgba(2, 6, 23, 0.45); }
html[data-theme='dark'] .it-label { color:#93c5fd; }
html[data-theme='dark'] .it-value { color:#e2e8f0; }
html[data-theme='dark'] .it-table th,
html[data-theme='dark'] .it-table td { border-color:#243047; color:#dbeafe; }
html[data-theme='dark'] .it-table th { background:#111a2b; }
html[data-theme='dark'] .it-table tbody tr:hover { background:rgba(30, 58, 138, 0.12); }
html[data-theme='dark'] .it-link a { color:#93c5fd; }
html[data-theme='dark'] .it-form input,
html[data-theme='dark'] .it-filter input,
html[data-theme='dark'] .it-filter select { background:#111a2b; border-color:#334155; color:#e2e8f0; }
html[data-theme='dark'] .it-form input::placeholder,
html[data-theme='dark'] .it-filter input::placeholder { color:#93c5fd; }
</style>

<div class="main-content" id="mainContent">
    <div class="it-shell">
        <div class="it-topbar">
            <div>
                <h4>Issued Transcripts</h4>
                <div class="text-muted small">Manage immutable transcript issuance records, verification tokens, and public verification links.</div>
            </div>
        </div>

        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

            <div class="it-grid">
                <div class="it-card"><div class="it-label">Total Issuances</div><div class="it-value"><?php echo number_format((int)($summary['total'] ?? 0)); ?></div></div>
                <div class="it-card"><div class="it-label">Active</div><div class="it-value"><?php echo number_format((int)($summary['active_total'] ?? 0)); ?></div></div>
                <div class="it-card"><div class="it-label">Revoked</div><div class="it-value"><?php echo number_format((int)($summary['revoked_total'] ?? 0)); ?></div></div>
                <div class="it-card"><div class="it-label">Students Covered</div><div class="it-value"><?php echo number_format((int)($summary['student_count'] ?? 0)); ?></div></div>
                <div class="it-card"><div class="it-label">To Be Distributed</div><div class="it-value"><?php echo number_format((int)($summary['to_be_distributed_total'] ?? 0)); ?></div></div>
                <div class="it-card"><div class="it-label">Dispatched</div><div class="it-value"><?php echo number_format((int)($summary['dispatched_total'] ?? 0)); ?></div></div>
                <div class="it-card"><div class="it-label">Distributed</div><div class="it-value"><?php echo number_format((int)($summary['distributed_total'] ?? 0)); ?></div></div>
            </div>

        <div class="it-panel">
            <form method="get" class="it-filter">
                <select name="status" class="form-control form-control-sm" style="max-width:160px;">
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="revoked" <?php echo $statusFilter === 'revoked' ? 'selected' : ''; ?>>Revoked</option>
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
                <select name="distribution" class="form-control form-control-sm" style="max-width:190px;">
                    <option value="all" <?php echo $distributionFilter === 'all' ? 'selected' : ''; ?>>All Distribution States</option>
                    <option value="to_be_distributed" <?php echo $distributionFilter === 'to_be_distributed' ? 'selected' : ''; ?>>To Be Distributed</option>
                    <option value="dispatched" <?php echo $distributionFilter === 'dispatched' ? 'selected' : ''; ?>>Dispatched</option>
                    <option value="distributed" <?php echo $distributionFilter === 'distributed' ? 'selected' : ''; ?>>Distributed</option>
                </select>
                <input type="text" name="q" class="form-control form-control-sm" style="max-width:280px;" placeholder="Search student, code, token" value="<?php echo e($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            </form>

            <div class="table-responsive">
                <table class="it-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Issued</th>
                            <th>Record Status</th>
                            <th>Distribution</th>
                            <th>Verification</th>
                            <th>Format</th>
                            <th>Source</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-muted">No issued transcript records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''))); ?></strong><br>
                                        <span class="text-muted"><?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $row)); ?></span><br>
                                        <span class="text-muted"><?php echo e(trim((string)($row['program_code'] ?? '') . ' - ' . (string)($row['program_name'] ?? ''))); ?></span>
                                    </td>
                                    <td>
                                        <?php echo e(Helper::formatDateTime((string)($row['issued_at'] ?? ''), 'M d, Y g:i A')); ?>
                                        <?php if (!empty($row['revoked_at'])): ?>
                                            <br><span class="text-muted">Revoked: <?php echo e(Helper::formatDateTime((string)$row['revoked_at'], 'M d, Y g:i A')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="it-badge <?php echo e((string)($row['status'] ?? 'active')); ?>"><?php echo e(strtoupper((string)($row['status'] ?? 'active'))); ?></span></td>
                                    <td>
                                        <span class="it-badge <?php echo e((string)($row['distribution_status'] ?? 'to_be_distributed')); ?>">
                                            <?php echo e(strtoupper(str_replace('_', ' ', (string)($row['distribution_status'] ?? 'to_be_distributed')))); ?>
                                        </span>
                                        <?php if (!empty($row['dispatched_at'])): ?>
                                            <div class="it-subtle">Dispatched: <?php echo e(Helper::formatDateTime((string)$row['dispatched_at'], 'M d, Y g:i A')); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['distributed_at'])): ?>
                                            <div class="it-subtle">Distributed: <?php echo e(Helper::formatDateTime((string)$row['distributed_at'], 'M d, Y g:i A')); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['dispatch_method']) || !empty($row['dispatch_reference'])): ?>
                                            <div class="it-subtle">
                                                <?php echo e((string)($row['dispatch_method'] ?? '')); ?>
                                                <?php if (!empty($row['dispatch_reference'])): ?>
                                                    <?php echo e(((string)($row['dispatch_method'] ?? '') !== '' ? ' | ' : '') . 'Ref: ' . (string)$row['dispatch_reference']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><strong>Code:</strong> <?php echo e((string)($row['verification_code'] ?? '')); ?></div>
                                        <div><strong>Token:</strong> <?php echo e((string)($row['verification_token'] ?? '')); ?></div>
                                        <div class="it-link"><a href="<?php echo e($issuanceService->buildVerificationUrl((string)($row['verification_token'] ?? ''))); ?>" target="_blank"><?php echo e($issuanceService->buildVerificationUrl((string)($row['verification_token'] ?? ''))); ?></a></div>
                                        <div class="text-muted small"><?php echo e((string)($row['transcript_hash'] ?? '')); ?></div>
                                    </td>
                                    <td><?php echo e(strtoupper((string)($row['export_format'] ?? ''))); ?></td>
                                    <td><?php echo e((string)($row['source_channel'] ?? '')); ?></td>
                                    <td class="it-actions-cell">
                                        <?php if ((string)($row['status'] ?? '') === 'active'): ?>
                                            <div class="it-stack">
                                                <?php if ((string)($row['distribution_status'] ?? 'to_be_distributed') === 'to_be_distributed'): ?>
                                                    <form method="post" class="it-form">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="mark_dispatched">
                                                        <input type="hidden" name="issuance_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                        <input type="text" name="dispatch_method" class="form-control form-control-sm" placeholder="Dispatch method">
                                                        <input type="text" name="dispatch_reference" class="form-control form-control-sm" placeholder="Reference / courier no.">
                                                        <input type="text" name="dispatch_notes" class="form-control form-control-sm" placeholder="Notes (optional)">
                                                        <button type="submit" class="btn btn-info btn-sm">Mark Dispatched</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (in_array((string)($row['distribution_status'] ?? ''), ['to_be_distributed', 'dispatched'], true)): ?>
                                                    <form method="post" class="it-form">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="mark_distributed">
                                                        <input type="hidden" name="issuance_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                        <input type="text" name="distribution_notes" class="form-control form-control-sm" placeholder="Distribution notes (optional)">
                                                        <button type="submit" class="btn btn-success btn-sm">Mark Distributed</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" class="it-form" onsubmit="return confirm('Revoke this issued transcript?');">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="revoke_issuance">
                                                    <input type="hidden" name="issuance_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                    <input type="text" name="revoke_reason" class="form-control form-control-sm" placeholder="Revoke reason (optional)">
                                                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No action</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
