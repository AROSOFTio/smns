<?php
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=session_expired');
    exit;
}

$currentUser = $auth->getCurrentUser();
$currentUserId = (int)($currentUser['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
FeeStructureGovernance::ensureSchema($conn);

$pageUrl = BASE_URL . '/views/admin/finance/fee-structures.php';
$selectedVersionId = (int)($_GET['version_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . $pageUrl);
        exit;
    }

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) throw new Exception('Invalid version.');

        $vStmt = $conn->prepare("SELECT * FROM fee_structure_versions WHERE id = :id LIMIT 1");
        $vStmt->execute(['id' => $versionId]);
        $version = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) throw new Exception('Version not found.');
        if ((int)($version['locked'] ?? 0) === 1) throw new Exception('Version is already locked.');

        if ($action === 'approve_version') {
            $ay = (int)($_POST['academic_year_id'] ?? 0);
            $program = (int)($_POST['program_id'] ?? 0);
            $stmt = $conn->prepare("
                UPDATE fee_structure_versions
                SET status = 'approved',
                    academic_year_id = :ay,
                    program_id = :program,
                    approved_by = :approved_by,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'ay' => $ay > 0 ? $ay : null,
                'program' => $program > 0 ? $program : null,
                'approved_by' => $currentUserId,
                'id' => $versionId
            ]);
            $session->setFlash('success', 'Version approved by University Administration.');
            header('Location: ' . $pageUrl . '?version_id=' . $versionId);
            exit;
        }

        if ($action === 'publish_version') {
            if ((string)($version['status'] ?? '') !== 'approved') {
                throw new Exception('Only approved versions can be published.');
            }
            $conn->beginTransaction();

            // Archive currently published version in same scope (program + academic year).
            $archive = $conn->prepare("
                UPDATE fee_structure_versions
                SET status = 'archived', updated_at = NOW()
                WHERE id <> :id
                  AND status = 'published'
                  AND (program_id <=> :program_id)
                  AND (academic_year_id <=> :academic_year_id)
            ");
            $archive->execute([
                'id' => $versionId,
                'program_id' => $version['program_id'] ?? null,
                'academic_year_id' => $version['academic_year_id'] ?? null
            ]);

            $publish = $conn->prepare("
                UPDATE fee_structure_versions
                SET status = 'published',
                    locked = 1,
                    published_by = :published_by,
                    published_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $publish->execute([
                'published_by' => $currentUserId,
                'id' => $versionId
            ]);

            $deactivateLines = $conn->prepare("
                UPDATE fees_structure fs
                INNER JOIN fee_structure_versions v ON v.id = fs.version_id
                SET fs.status = 'inactive'
                WHERE v.id <> :id
                  AND (v.program_id <=> :program_id)
                  AND (v.academic_year_id <=> :academic_year_id)
            ");
            $deactivateLines->execute([
                'id' => $versionId,
                'program_id' => $version['program_id'] ?? null,
                'academic_year_id' => $version['academic_year_id'] ?? null
            ]);

            $activateLines = $conn->prepare("
                UPDATE fees_structure
                SET status = 'active',
                    locked_at = NOW(),
                    updated_at = NOW()
                WHERE version_id = :id
            ");
            $activateLines->execute(['id' => $versionId]);

            $conn->commit();
            $session->setFlash('success', 'Version published and locked.');
            header('Location: ' . $pageUrl . '?version_id=' . $versionId);
            exit;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $session->setFlash('error', $e->getMessage());
        header('Location: ' . $pageUrl . ($selectedVersionId > 0 ? ('?version_id=' . $selectedVersionId) : ''));
        exit;
    }
}

$programs = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$years = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vStmt = $conn->query("
    SELECT
        v.*,
        ay.year_name AS academic_year_name,
        p.program_name,
        COUNT(fs.id) AS item_count,
        COALESCE(SUM(fs.amount),0) AS total_amount,
        COALESCE(CONCAT(fin.first_name, ' ', fin.last_name), 'Finance Office') AS created_by_name,
        COALESCE(CONCAT(adm.first_name, ' ', adm.last_name), 'University Administration') AS approved_by_name
    FROM fee_structure_versions v
    LEFT JOIN academic_years ay ON ay.id = v.academic_year_id
    LEFT JOIN programs p ON p.id = v.program_id
    LEFT JOIN fees_structure fs ON fs.version_id = v.id
    LEFT JOIN finance_staff fin ON fin.id = v.created_by
    LEFT JOIN users au ON au.id = v.approved_by
    LEFT JOIN admins adm ON adm.user_id = au.id
    GROUP BY v.id, ay.year_name, p.program_name, fin.first_name, fin.last_name, adm.first_name, adm.last_name
    ORDER BY COALESCE(v.published_at, v.approved_at, v.updated_at, v.created_at) DESC, v.id DESC
");
$versions = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($selectedVersionId <= 0 && !empty($versions)) $selectedVersionId = (int)$versions[0]['id'];
$selectedVersion = null;
$selectedLines = [];
if ($selectedVersionId > 0) {
    $sel = $conn->prepare("SELECT * FROM fee_structure_versions WHERE id=:id LIMIT 1");
    $sel->execute(['id' => $selectedVersionId]);
    $selectedVersion = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedVersion) {
        $l = $conn->prepare("SELECT fs.*, s.semester_name FROM fees_structure fs LEFT JOIN semesters s ON s.id = fs.semester_id WHERE fs.version_id=:id ORDER BY fs.id DESC");
        $l->execute(['id' => $selectedVersionId]);
        $selectedLines = $l->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$unreadNotifications = fetchUnreadNotificationsForUser($currentUserId, 10);
$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');
$pageTitle = 'Fee Structure Approvals - ' . APP_NAME;
include '../../../includes/header.php';
?>
<?php include '../../../includes/admin/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div><h4 class="mb-0">Fee Structure Approvals</h4></div>
        <div><?php include '../../../includes/notification_bell.php'; ?></div>
    </div>
    <div class="content-area">
        <?php if (!empty($flashSuccess)): ?><div class="alert alert-success"><?php echo e($flashSuccess); ?></div><?php endif; ?>
        <?php if (!empty($flashError)): ?><div class="alert alert-danger"><?php echo e($flashError); ?></div><?php endif; ?>

        <div class="alert alert-info"><strong>Approved by:</strong> University Administration. Publish locks the version and activates it for student read-only use.</div>

        <div class="card mb-3">
            <div class="card-header">All Fee Structure Versions</div>
            <div class="card-body p-0">
                <?php if (empty($versions)): ?>
                    <div class="p-3 text-muted">No versions submitted yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Version</th>
                                    <th>Scope</th>
                                    <th>Status</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Workflow</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($versions as $v): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>"><?php echo e((string)$v['version_name']); ?></a>
                                        <div class="small text-muted">Created by: <?php echo e((string)$v['created_by_name']); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo !empty($v['academic_year_name']) ? e((string)$v['academic_year_name']) : 'All Years'; ?></div>
                                        <div class="small text-muted"><?php echo !empty($v['program_name']) ? e((string)$v['program_name']) : 'All Programs'; ?></div>
                                    </td>
                                    <td><?php echo e(strtoupper(str_replace('_', ' ', (string)$v['status']))); ?></td>
                                    <td><?php echo (int)$v['item_count']; ?></td>
                                    <td><?php echo number_format((float)$v['total_amount']); ?></td>
                                    <td>
                                        <?php if ((int)($v['locked'] ?? 0) === 0): ?>
                                            <form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>" class="mb-1">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="approve_version">
                                                <input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>">
                                                <div class="d-flex" style="gap:6px;">
                                                    <select class="form-control form-control-sm" name="academic_year_id">
                                                        <option value="">Academic Year</option>
                                                        <?php foreach ($years as $y): ?>
                                                            <option value="<?php echo (int)$y['id']; ?>" <?php echo ((int)($v['academic_year_id'] ?? 0) === (int)$y['id']) ? 'selected' : ''; ?>><?php echo e((string)$y['year_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select class="form-control form-control-sm" name="program_id">
                                                        <option value="">All Programs</option>
                                                        <?php foreach ($programs as $p): ?>
                                                            <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)($v['program_id'] ?? 0) === (int)$p['id']) ? 'selected' : ''; ?>><?php echo e((string)$p['program_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Approve</button>
                                                </div>
                                            </form>
                                            <form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="publish_version">
                                                <input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success" <?php echo ((string)($v['status'] ?? '') !== 'approved') ? 'disabled' : ''; ?>>Publish & Lock</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge badge-success">Locked</span>
                                            <div class="small text-muted">Approved by: University Administration</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selectedVersion): ?>
            <div class="card">
                <div class="card-header">Version Lines: <?php echo e((string)$selectedVersion['version_name']); ?></div>
                <div class="card-body p-0">
                    <?php if (empty($selectedLines)): ?>
                        <div class="p-3 text-muted">No fee lines found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Item</th><th>Type</th><th>Amount</th><th>Discount</th><th>Fine</th><th>To Pay</th><th>Deadline</th></tr></thead>
                                <tbody>
                                <?php foreach ($selectedLines as $line): $net = max(0, (float)$line['amount'] - (float)$line['discount_amount'] + (float)$line['fine_amount']); ?>
                                    <tr>
                                        <td><?php echo e((string)$line['fee_name']); ?></td>
                                        <td><?php echo e((string)$line['fee_type']); ?></td>
                                        <td><?php echo number_format((float)$line['amount']); ?></td>
                                        <td><?php echo number_format((float)$line['discount_amount']); ?></td>
                                        <td><?php echo number_format((float)$line['fine_amount']); ?></td>
                                        <td><strong><?php echo number_format($net); ?></strong></td>
                                        <td><?php echo e((string)($line['due_date'] ?: '-')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include '../../../includes/footer.php'; ?>

