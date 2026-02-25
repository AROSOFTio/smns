<?php
require_once '../../config.php';

$session = new Session('finance');
$auth = new Auth('finance');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    header('Location: ' . BASE_URL . '/views/finance/login.php?error=session_expired');
    exit;
}

$currentUser = $auth->getCurrentUser();
$financeProfile = $currentUser['profile'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$financeStaffId = (int)($financeProfile['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
FeeStructureGovernance::ensureSchema($conn);

if ($financeStaffId <= 0 && $currentUserId > 0) {
    $staffStmt = $conn->prepare("SELECT id FROM finance_staff WHERE user_id = :uid LIMIT 1");
    $staffStmt->execute(['uid' => $currentUserId]);
    $financeStaffId = (int)$staffStmt->fetchColumn();
}

$pageUrl = BASE_URL . '/views/finance/fee-structures.php';
$selectedVersionId = (int)($_GET['version_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . $pageUrl);
        exit;
    }
    try {
        if ($financeStaffId <= 0) throw new Exception('Finance profile missing.');
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_version') {
            $name = trim((string)($_POST['version_name'] ?? ''));
            if ($name === '') throw new Exception('Version name is required.');
            $ay = (int)($_POST['academic_year_id'] ?? 0);
            $program = (int)($_POST['program_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $ins = $conn->prepare("
                INSERT INTO fee_structure_versions
                    (version_name, academic_year_id, program_id, status, locked, created_by, notes, created_at, updated_at)
                VALUES
                    (:name, :ay, :program, 'draft', 0, :created_by, :notes, NOW(), NOW())
            ");
            $ins->execute([
                'name' => $name,
                'ay' => $ay > 0 ? $ay : null,
                'program' => $program > 0 ? $program : null,
                'created_by' => $financeStaffId,
                'notes' => $notes !== '' ? $notes : null
            ]);
            $newId = (int)$conn->lastInsertId();
            $session->setFlash('success', 'Version created (Created by: Finance Office).');
            header('Location: ' . $pageUrl . '?version_id=' . $newId);
            exit;
        }

        if ($action === 'add_line') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $name = trim((string)($_POST['fee_name'] ?? ''));
            $type = trim((string)($_POST['fee_type'] ?? ''));
            $amount = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
            $level = (int)($_POST['level_year'] ?? 0);
            $semester = (int)($_POST['semester_id'] ?? 0);
            $mandatory = (trim((string)($_POST['mandatory'] ?? 'yes')) === 'no') ? 'no' : 'yes';
            $dueDate = trim((string)($_POST['due_date'] ?? ''));
            $fine = (float)str_replace(',', '', (string)($_POST['fine_amount'] ?? '0'));
            $discount = (float)str_replace(',', '', (string)($_POST['discount_amount'] ?? '0'));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($versionId <= 0) throw new Exception('Select a version.');
            if ($name === '' || $type === '' || $amount <= 0) throw new Exception('Fee line fields are invalid.');
            if ($fine < 0 || $discount < 0 || $discount > $amount) throw new Exception('Fine/discount values are invalid.');

            $v = $conn->prepare("SELECT id, status, locked, program_id FROM fee_structure_versions WHERE id=:id AND created_by=:uid LIMIT 1");
            $v->execute(['id' => $versionId, 'uid' => $financeStaffId]);
            $version = $v->fetch(PDO::FETCH_ASSOC);
            if (!$version) throw new Exception('Version not found.');
            if ((string)$version['status'] !== 'draft' || (int)$version['locked'] === 1) throw new Exception('Only draft versions are editable.');

            $dueSql = null;
            if ($dueDate !== '') {
                $ts = strtotime($dueDate);
                if ($ts === false) throw new Exception('Invalid deadline.');
                $dueSql = date('Y-m-d', $ts);
            }

            $ins = $conn->prepare("
                INSERT INTO fees_structure
                    (fee_name, fee_type, amount, program_id, level_year, semester_id, mandatory, due_date,
                     fine_amount, discount_amount, created_by_finance_id, version_id, description, status, created_at, updated_at)
                VALUES
                    (:name, :type, :amount, :program_id, :level_year, :semester_id, :mandatory, :due_date,
                     :fine, :discount, :created_by_finance_id, :version_id, :description, 'inactive', NOW(), NOW())
            ");
            $ins->execute([
                'name' => $name,
                'type' => $type,
                'amount' => $amount,
                'program_id' => !empty($version['program_id']) ? (int)$version['program_id'] : null,
                'level_year' => $level > 0 ? $level : null,
                'semester_id' => $semester > 0 ? $semester : null,
                'mandatory' => $mandatory,
                'due_date' => $dueSql,
                'fine' => $fine,
                'discount' => $discount,
                'created_by_finance_id' => $financeStaffId,
                'version_id' => $versionId,
                'description' => $description !== '' ? $description : null
            ]);

            $session->setFlash('success', 'Fee line added.');
            header('Location: ' . $pageUrl . '?version_id=' . $versionId);
            exit;
        }

        if ($action === 'submit_version') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $v = $conn->prepare("SELECT id, status, locked FROM fee_structure_versions WHERE id=:id AND created_by=:uid LIMIT 1");
            $v->execute(['id' => $versionId, 'uid' => $financeStaffId]);
            $version = $v->fetch(PDO::FETCH_ASSOC);
            if (!$version) throw new Exception('Version not found.');
            if ((string)$version['status'] !== 'draft' || (int)$version['locked'] === 1) throw new Exception('Version is not submit-ready.');
            $c = $conn->prepare("SELECT COUNT(*) FROM fees_structure WHERE version_id = :id");
            $c->execute(['id' => $versionId]);
            if ((int)$c->fetchColumn() <= 0) throw new Exception('Add at least one fee line first.');
            $u = $conn->prepare("UPDATE fee_structure_versions SET status='pending_approval', updated_at=NOW() WHERE id=:id");
            $u->execute(['id' => $versionId]);
            $session->setFlash('success', 'Version submitted for admin approval.');
            header('Location: ' . $pageUrl . '?version_id=' . $versionId);
            exit;
        }
    } catch (Exception $e) {
        $session->setFlash('error', $e->getMessage());
        header('Location: ' . $pageUrl . ($selectedVersionId > 0 ? ('?version_id=' . $selectedVersionId) : ''));
        exit;
    }
}

$programs = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$years = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$semesters = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vStmt = $conn->prepare("
    SELECT v.*, ay.year_name AS academic_year_name, p.program_name, COUNT(fs.id) AS item_count, COALESCE(SUM(fs.amount),0) AS total_amount
    FROM fee_structure_versions v
    LEFT JOIN academic_years ay ON ay.id = v.academic_year_id
    LEFT JOIN programs p ON p.id = v.program_id
    LEFT JOIN fees_structure fs ON fs.version_id = v.id
    WHERE v.created_by = :uid
    GROUP BY v.id, ay.year_name, p.program_name
    ORDER BY v.created_at DESC, v.id DESC
");
$vStmt->execute(['uid' => $financeStaffId]);
$versions = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($selectedVersionId <= 0 && !empty($versions)) $selectedVersionId = (int)$versions[0]['id'];
$selectedVersion = null;
$selectedLines = [];
if ($selectedVersionId > 0) {
    $sel = $conn->prepare("SELECT * FROM fee_structure_versions WHERE id=:id AND created_by=:uid LIMIT 1");
    $sel->execute(['id' => $selectedVersionId, 'uid' => $financeStaffId]);
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
$pageTitle = 'Fee Structures - Finance - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/finance/sidebar.php'; ?>
<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button><h4>Fee Structures</h4></div>
        <div class="topbar-right"><?php include '../../includes/notification_bell.php'; ?></div>
    </div>
    <div class="content-area">
        <?php if (!empty($flashSuccess)): ?><div class="alert alert-success"><?php echo e($flashSuccess); ?></div><?php endif; ?>
        <?php if (!empty($flashError)): ?><div class="alert alert-danger"><?php echo e($flashError); ?></div><?php endif; ?>
        <div class="alert alert-info"><strong>Created by:</strong> Finance Office. Build draft versions, then submit to Admin for approval/publish.</div>
        <div class="row">
            <div class="col-md-5">
                <div class="card mb-3"><div class="card-header">Create Version</div><div class="card-body">
                    <form method="POST" action="<?php echo e($pageUrl); ?>"><?php echo csrfField(); ?><input type="hidden" name="action" value="create_version">
                        <div class="form-group"><label>Version Name</label><input class="form-control" name="version_name" placeholder="2025/2026 Fee Structure" required></div>
                        <div class="form-group"><label>Academic Year</label><select class="form-control" name="academic_year_id"><option value="">All / Not assigned</option><?php foreach ($years as $y): ?><option value="<?php echo (int)$y['id']; ?>"><?php echo e((string)$y['year_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Program</label><select class="form-control" name="program_id"><option value="">All Programs</option><?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo e((string)$p['program_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
                        <button class="btn btn-primary btn-block" type="submit">Create Draft</button>
                    </form>
                </div></div>
                <?php if ($selectedVersion && (string)$selectedVersion['status'] === 'draft' && (int)$selectedVersion['locked'] === 0): ?>
                <div class="card mb-3"><div class="card-header">Add Fee Line</div><div class="card-body">
                    <form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$selectedVersion['id']); ?>"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_line"><input type="hidden" name="version_id" value="<?php echo (int)$selectedVersion['id']; ?>">
                        <div class="form-group"><label>Fee Name</label><input class="form-control" name="fee_name" required></div>
                        <div class="form-group"><label>Fee Type</label><input class="form-control" name="fee_type" required></div>
                        <div class="row"><div class="col-md-6"><div class="form-group"><label>Amount (UGX)</label><input type="number" class="form-control" min="0.01" step="0.01" name="amount" required></div></div><div class="col-md-6"><div class="form-group"><label>Deadline</label><input type="date" class="form-control" name="due_date"></div></div></div>
                        <div class="row"><div class="col-md-4"><div class="form-group"><label>Fine</label><input type="number" class="form-control" min="0" step="0.01" name="fine_amount" value="0"></div></div><div class="col-md-4"><div class="form-group"><label>Discount</label><input type="number" class="form-control" min="0" step="0.01" name="discount_amount" value="0"></div></div><div class="col-md-4"><div class="form-group"><label>Mandatory</label><select class="form-control" name="mandatory"><option value="yes">Yes</option><option value="no">No</option></select></div></div></div>
                        <div class="row"><div class="col-md-6"><div class="form-group"><label>Year</label><input type="number" class="form-control" min="1" max="9" name="level_year"></div></div><div class="col-md-6"><div class="form-group"><label>Semester</label><select class="form-control" name="semester_id"><option value="">Not specific</option><?php foreach ($semesters as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo e((string)$s['semester_name']); ?></option><?php endforeach; ?></select></div></div></div>
                        <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                        <button class="btn btn-success btn-block" type="submit">Add Line</button>
                    </form>
                </div></div>
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                <div class="card mb-3"><div class="card-header">My Versions</div><div class="card-body p-0">
                    <?php if (empty($versions)): ?><div class="p-3 text-muted">No versions yet.</div><?php else: ?>
                    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Version</th><th>Status</th><th>Items</th><th>Total</th><th></th></tr></thead><tbody>
                        <?php foreach ($versions as $v): ?><tr>
                            <td><a href="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>"><?php echo e((string)$v['version_name']); ?></a><div class="small text-muted"><?php echo !empty($v['program_name']) ? e((string)$v['program_name']) : 'All Programs'; ?></div></td>
                            <td><?php echo e(strtoupper(str_replace('_', ' ', (string)$v['status']))); ?></td>
                            <td><?php echo (int)$v['item_count']; ?></td>
                            <td><?php echo number_format((float)$v['total_amount']); ?></td>
                            <td><?php if ((string)$v['status'] === 'draft' && (int)$v['locked'] === 0): ?><form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>"><?php echo csrfField(); ?><input type="hidden" name="action" value="submit_version"><input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>"><button class="btn btn-sm btn-outline-primary" type="submit">Submit</button></form><?php endif; ?></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </div></div>
                <?php if ($selectedVersion): ?><div class="card"><div class="card-header">Version Lines: <?php echo e((string)$selectedVersion['version_name']); ?></div><div class="card-body p-0">
                    <?php if (empty($selectedLines)): ?><div class="p-3 text-muted">No lines yet.</div><?php else: ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Item</th><th>Type</th><th>Amount</th><th>Discount</th><th>Fine</th><th>To Pay</th><th>Deadline</th></tr></thead><tbody>
                        <?php foreach ($selectedLines as $line): $net = max(0, (float)$line['amount'] - (float)$line['discount_amount'] + (float)$line['fine_amount']); ?><tr><td><?php echo e((string)$line['fee_name']); ?></td><td><?php echo e((string)$line['fee_type']); ?></td><td><?php echo number_format((float)$line['amount']); ?></td><td><?php echo number_format((float)$line['discount_amount']); ?></td><td><?php echo number_format((float)$line['fine_amount']); ?></td><td><strong><?php echo number_format($net); ?></strong></td><td><?php echo e((string)($line['due_date'] ?: '-')); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </div></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>

