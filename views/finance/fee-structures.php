<?php
require_once '../../config.php';

$session = new Session('finance');
$auth = new Auth('finance');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'finance') {
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=session_expired&role=finance');
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
$editLineId = (int)($_GET['edit_line_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid request token.');
        header('Location: ' . $pageUrl);
        exit;
    }
    try {
        if ($financeStaffId <= 0) throw new Exception('Finance profile missing.');
        $action = trim((string)($_POST['action'] ?? ''));
        $loadEditableVersion = function ($versionId) use ($conn, $financeStaffId) {
            $v = $conn->prepare("SELECT id, status, locked, program_id FROM fee_structure_versions WHERE id=:id AND created_by=:uid LIMIT 1");
            $v->execute(['id' => (int)$versionId, 'uid' => $financeStaffId]);
            $version = $v->fetch(PDO::FETCH_ASSOC);
            if (!$version) {
                throw new Exception('Version not found.');
            }
            if ((string)$version['status'] !== 'draft' || (int)$version['locked'] === 1) {
                throw new Exception('Only draft versions are editable.');
            }
            return $version;
        };

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

        if ($action === 'add_line' || $action === 'update_line') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $lineId = (int)($_POST['line_id'] ?? 0);
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
            if ($action === 'update_line' && $lineId <= 0) throw new Exception('Select a line to update.');
            if ($name === '' || $type === '' || $amount <= 0) throw new Exception('Fee line fields are invalid.');
            if ($fine < 0 || $discount < 0 || $discount > $amount) throw new Exception('Fine/discount values are invalid.');
            if ($level <= 0) throw new Exception('Year is required for every fee item.');
            if ($semester <= 0) throw new Exception('Semester is required for every fee item.');

            $version = $loadEditableVersion($versionId);

            if (!empty($version['program_id'])) {
                $durStmt = $conn->prepare("SELECT duration_years FROM programs WHERE id = :program_id LIMIT 1");
                $durStmt->execute(['program_id' => (int)$version['program_id']]);
                $duration = (int)$durStmt->fetchColumn();
                if ($duration > 0 && $level > $duration) {
                    throw new Exception('Year exceeds program duration (' . $duration . ').');
                }
            }

            $dueSql = null;
            if ($dueDate !== '') {
                $ts = strtotime($dueDate);
                if ($ts === false) throw new Exception('Invalid deadline.');
                $dueSql = date('Y-m-d', $ts);
            }

            if ($action === 'update_line') {
                $lineCheck = $conn->prepare("SELECT id FROM fees_structure WHERE id=:line_id AND version_id=:version_id LIMIT 1");
                $lineCheck->execute([
                    'line_id' => $lineId,
                    'version_id' => $versionId
                ]);
                if (!$lineCheck->fetch()) {
                    throw new Exception('Fee line not found.');
                }

                $upd = $conn->prepare("
                    UPDATE fees_structure
                    SET fee_name = :name,
                        fee_type = :type,
                        amount = :amount,
                        program_id = :program_id,
                        level_year = :level_year,
                        semester_id = :semester_id,
                        mandatory = :mandatory,
                        due_date = :due_date,
                        fine_amount = :fine,
                        discount_amount = :discount,
                        description = :description,
                        updated_at = NOW()
                    WHERE id = :line_id
                      AND version_id = :version_id
                ");
                $upd->execute([
                    'name' => $name,
                    'type' => $type,
                    'amount' => $amount,
                    'program_id' => !empty($version['program_id']) ? (int)$version['program_id'] : null,
                    'level_year' => $level,
                    'semester_id' => $semester,
                    'mandatory' => $mandatory,
                    'due_date' => $dueSql,
                    'fine' => $fine,
                    'discount' => $discount,
                    'description' => $description !== '' ? $description : null,
                    'line_id' => $lineId,
                    'version_id' => $versionId
                ]);

                $session->setFlash('success', 'Fee line updated.');
            } else {
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
                    'level_year' => $level,
                    'semester_id' => $semester,
                    'mandatory' => $mandatory,
                    'due_date' => $dueSql,
                    'fine' => $fine,
                    'discount' => $discount,
                    'created_by_finance_id' => $financeStaffId,
                    'version_id' => $versionId,
                    'description' => $description !== '' ? $description : null
                ]);

                $session->setFlash('success', 'Fee line added.');
            }
            header('Location: ' . $pageUrl . '?version_id=' . $versionId);
            exit;
        }

        if ($action === 'delete_line') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $lineId = (int)($_POST['line_id'] ?? 0);
            if ($versionId <= 0 || $lineId <= 0) throw new Exception('Invalid line selection.');
            $loadEditableVersion($versionId);
            $del = $conn->prepare("DELETE FROM fees_structure WHERE id=:line_id AND version_id=:version_id LIMIT 1");
            $del->execute([
                'line_id' => $lineId,
                'version_id' => $versionId
            ]);
            if ($del->rowCount() <= 0) throw new Exception('Fee line not found.');
            $session->setFlash('success', 'Fee line deleted.');
            header('Location: ' . $pageUrl . '?version_id=' . $versionId);
            exit;
        }

        if ($action === 'delete_version') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            if ($versionId <= 0) throw new Exception('Invalid version.');
            $loadEditableVersion($versionId);
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM fees_structure WHERE version_id = :id")->execute(['id' => $versionId]);
            $delVersion = $conn->prepare("DELETE FROM fee_structure_versions WHERE id = :id AND created_by = :uid LIMIT 1");
            $delVersion->execute([
                'id' => $versionId,
                'uid' => $financeStaffId
            ]);
            if ($delVersion->rowCount() <= 0) {
                throw new Exception('Version could not be deleted.');
            }
            $conn->commit();
            $session->setFlash('success', 'Draft version deleted.');
            header('Location: ' . $pageUrl);
            exit;
        }

        if ($action === 'submit_version') {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $v = $conn->prepare("SELECT id, version_name, academic_year_id, program_id, status, locked, notes FROM fee_structure_versions WHERE id=:id AND created_by=:uid LIMIT 1");
            $v->execute(['id' => $versionId, 'uid' => $financeStaffId]);
            $version = $v->fetch(PDO::FETCH_ASSOC);
            if (!$version) throw new Exception('Version not found.');
            if ((string)$version['status'] !== 'draft' || (int)$version['locked'] === 1) throw new Exception('Version is not submit-ready.');
            $c = $conn->prepare("SELECT COUNT(*) FROM fees_structure WHERE version_id = :id");
            $c->execute(['id' => $versionId]);
            if ((int)$c->fetchColumn() <= 0) throw new Exception('Add at least one fee line first.');
            $missingMapStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM fees_structure
                WHERE version_id = :id
                  AND (level_year IS NULL OR level_year <= 0 OR semester_id IS NULL OR semester_id <= 0)
            ");
            $missingMapStmt->execute(['id' => $versionId]);
            if ((int)$missingMapStmt->fetchColumn() > 0) {
                throw new Exception('Every fee line must be assigned to a valid year and semester before submit.');
            }

            // Keep finance draft intact and submit a cloned copy for admin workflow.
            $conn->beginTransaction();

            $submittedSuffix = ' (Submitted ' . date('Y-m-d H:i') . ')';
            $baseName = trim((string)$version['version_name']);
            $maxBaseLength = 150 - strlen($submittedSuffix);
            if ($maxBaseLength < 1) {
                $maxBaseLength = 1;
            }
            $submittedVersionName = substr($baseName, 0, $maxBaseLength) . $submittedSuffix;
            $submittedNotes = trim((string)($version['notes'] ?? ''));
            if ($submittedNotes !== '') {
                $submittedNotes .= PHP_EOL;
            }
            $submittedNotes .= 'Snapshot submitted from draft #' . (int)$version['id'] . ' on ' . date('Y-m-d H:i:s') . '.';

            $insertVersion = $conn->prepare("
                INSERT INTO fee_structure_versions
                    (version_name, academic_year_id, program_id, status, locked, created_by, notes, created_at, updated_at)
                VALUES
                    (:version_name, :academic_year_id, :program_id, 'pending_approval', 0, :created_by, :notes, NOW(), NOW())
            ");
            $insertVersion->execute([
                'version_name' => $submittedVersionName,
                'academic_year_id' => !empty($version['academic_year_id']) ? (int)$version['academic_year_id'] : null,
                'program_id' => !empty($version['program_id']) ? (int)$version['program_id'] : null,
                'created_by' => $financeStaffId,
                'notes' => $submittedNotes
            ]);
            $submittedVersionId = (int)$conn->lastInsertId();

            $cloneLines = $conn->prepare("
                INSERT INTO fees_structure
                    (fee_name, fee_type, amount, program_id, level_year, semester_id, mandatory, due_date,
                     fine_amount, discount_amount, created_by_finance_id, version_id, description, status, created_at, updated_at)
                SELECT
                    fs.fee_name, fs.fee_type, fs.amount, fs.program_id, fs.level_year, fs.semester_id, fs.mandatory, fs.due_date,
                    COALESCE(fs.fine_amount, 0), COALESCE(fs.discount_amount, 0), COALESCE(fs.created_by_finance_id, :created_by_finance_id),
                    :new_version_id, fs.description, 'inactive', NOW(), NOW()
                FROM fees_structure fs
                WHERE fs.version_id = :source_version_id
            ");
            $cloneLines->execute([
                'created_by_finance_id' => $financeStaffId,
                'new_version_id' => $submittedVersionId,
                'source_version_id' => $versionId
            ]);
            if ($cloneLines->rowCount() <= 0) {
                throw new Exception('Failed to clone fee lines for submission.');
            }

            $conn->commit();
            $session->setFlash('success', 'Submitted a copy for admin approval (Version #' . $submittedVersionId . '). Your draft remains saved for further edits.');
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
$selectedLineGroups = [];
$lineBeingEdited = null;
if ($selectedVersionId > 0) {
    $sel = $conn->prepare("SELECT * FROM fee_structure_versions WHERE id=:id AND created_by=:uid LIMIT 1");
    $sel->execute(['id' => $selectedVersionId, 'uid' => $financeStaffId]);
    $selectedVersion = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedVersion) {
        $l = $conn->prepare("SELECT fs.*, s.semester_name, s.semester_number FROM fees_structure fs LEFT JOIN semesters s ON s.id = fs.semester_id WHERE fs.version_id=:id ORDER BY COALESCE(fs.level_year, 999) ASC, COALESCE(s.semester_number, 999) ASC, fs.fee_name ASC, fs.id ASC");
        $l->execute(['id' => $selectedVersionId]);
        $selectedLines = $l->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($selectedLines as $line) {
            $year = (int)($line['level_year'] ?? 0);
            $semNum = (int)($line['semester_number'] ?? 0);
            $semNameRaw = (string)($line['semester_name'] ?? '');
            if ($semNum !== 1 && $semNum !== 2) {
                $semName = strtolower($semNameRaw);
                $semNum = (strpos($semName, '2') !== false || strpos($semName, 'ii') !== false) ? 2 : 1;
            }

            $yearLabel = $year > 0 ? ('Year ' . $year) : 'Unassigned Year';
            $semLabel = $semNum === 1 ? 'Semester I' : ($semNum === 2 ? 'Semester II' : (!empty($semNameRaw) ? $semNameRaw : 'Unassigned Semester'));
            $groupKey = sprintf('%03d|%02d', $year > 0 ? $year : 999, $semNum > 0 ? $semNum : 99);
            if (!isset($selectedLineGroups[$groupKey])) {
                $selectedLineGroups[$groupKey] = [
                    'year' => $year,
                    'semester_number' => $semNum,
                    'year_label' => $yearLabel,
                    'semester_label' => $semLabel,
                    'rows' => [],
                    'total' => 0.0
                ];
            }
            $base = (float)($line['amount'] ?? 0);
            $discount = (float)($line['discount_amount'] ?? 0);
            $fine = (float)($line['fine_amount'] ?? 0);
            $selectedLineGroups[$groupKey]['rows'][] = $line;
            $selectedLineGroups[$groupKey]['total'] += max(0, $base - $discount + $fine);
        }
        if (!empty($selectedLineGroups)) {
            ksort($selectedLineGroups, SORT_STRING);
        }
        if ($editLineId > 0 && (string)$selectedVersion['status'] === 'draft' && (int)$selectedVersion['locked'] === 0) {
            $editStmt = $conn->prepare("SELECT * FROM fees_structure WHERE id=:line_id AND version_id=:version_id LIMIT 1");
            $editStmt->execute([
                'line_id' => $editLineId,
                'version_id' => $selectedVersionId
            ]);
            $lineBeingEdited = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
}

$unreadNotifications = fetchUnreadNotificationsForUser($currentUserId, 10);
$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');
$pageTitle = 'Fee Structures - Finance - ' . APP_NAME;
include '../../includes/header.php';
?>
<style>
.fee-group-card {
    border: 1px solid #e5e7eb;
}
.fee-group-card > .card-header {
    background: #f8fafc;
}
.fee-group-total td {
    background: #f8fafc;
    font-weight: 600;
}
html[data-theme='dark'] .fee-group-card {
    background: var(--app-surface) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .fee-group-card > .card-header {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-bottom: 1px solid var(--app-border) !important;
}
html[data-theme='dark'] .fee-group-total td {
    background: #1f2937 !important;
    color: #f8fafc !important;
    border-color: var(--app-border) !important;
}
</style>
<?php include '../../includes/finance/sidebar.php'; ?>
<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left"><button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button><h4>Fee Structures</h4></div>
        <div class="topbar-right"><?php include '../../includes/notification_bell.php'; ?></div>
    </div>
    <div class="content-area">
        <?php if (!empty($flashSuccess)): ?><div class="alert alert-success"><?php echo e($flashSuccess); ?></div><?php endif; ?>
        <?php if (!empty($flashError)): ?><div class="alert alert-danger"><?php echo e($flashError); ?></div><?php endif; ?>
        <div class="alert alert-info"><strong>Created by:</strong> Finance Office. Build draft versions, then submit a copy to Admin for approval/publish. Your original draft remains saved.</div>
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
                <div class="card mb-3"><div class="card-header"><?php echo $lineBeingEdited ? 'Edit Fee Line' : 'Add Fee Line'; ?></div><div class="card-body">
                    <div class="alert alert-light border mb-3"><strong>Rule:</strong> every item must be mapped to a specific <strong>Year</strong> and <strong>Semester</strong>.</div>
                    <form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$selectedVersion['id'] . ($lineBeingEdited ? ('&edit_line_id=' . (int)$lineBeingEdited['id']) : '')); ?>"><?php echo csrfField(); ?><input type="hidden" name="action" value="<?php echo $lineBeingEdited ? 'update_line' : 'add_line'; ?>"><input type="hidden" name="version_id" value="<?php echo (int)$selectedVersion['id']; ?>"><?php if ($lineBeingEdited): ?><input type="hidden" name="line_id" value="<?php echo (int)$lineBeingEdited['id']; ?>"><?php endif; ?>
                        <div class="form-group"><label>Fee Name</label><input class="form-control" name="fee_name" value="<?php echo e((string)($lineBeingEdited['fee_name'] ?? '')); ?>" required></div>
                        <div class="form-group"><label>Fee Type</label><input class="form-control" name="fee_type" value="<?php echo e((string)($lineBeingEdited['fee_type'] ?? '')); ?>" required></div>
                        <div class="row"><div class="col-md-6"><div class="form-group"><label>Amount (UGX)</label><input type="number" class="form-control" min="0.01" step="0.01" name="amount" value="<?php echo e((string)($lineBeingEdited['amount'] ?? '')); ?>" required></div></div><div class="col-md-6"><div class="form-group"><label>Deadline</label><input type="date" class="form-control" name="due_date" value="<?php echo e((string)($lineBeingEdited['due_date'] ?? '')); ?>"></div></div></div>
                        <div class="row"><div class="col-md-4"><div class="form-group"><label>Fine</label><input type="number" class="form-control" min="0" step="0.01" name="fine_amount" value="<?php echo e((string)($lineBeingEdited['fine_amount'] ?? '0')); ?>"></div></div><div class="col-md-4"><div class="form-group"><label>Discount</label><input type="number" class="form-control" min="0" step="0.01" name="discount_amount" value="<?php echo e((string)($lineBeingEdited['discount_amount'] ?? '0')); ?>"></div></div><div class="col-md-4"><div class="form-group"><label>Mandatory</label><select class="form-control" name="mandatory"><option value="yes" <?php echo ((string)($lineBeingEdited['mandatory'] ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Yes</option><option value="no" <?php echo ((string)($lineBeingEdited['mandatory'] ?? 'yes') === 'no') ? 'selected' : ''; ?>>No</option></select></div></div></div>
                        <div class="row"><div class="col-md-6"><div class="form-group"><label>Year</label><input type="number" class="form-control" min="1" max="9" name="level_year" value="<?php echo e((string)($lineBeingEdited['level_year'] ?? '')); ?>" required></div></div><div class="col-md-6"><div class="form-group"><label>Semester</label><select class="form-control" name="semester_id" required><option value="">Select semester</option><?php foreach ($semesters as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)($lineBeingEdited['semester_id'] ?? 0) === (int)$s['id']) ? 'selected' : ''; ?>><?php echo e((string)$s['semester_name']); ?></option><?php endforeach; ?></select></div></div></div>
                        <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="2"><?php echo e((string)($lineBeingEdited['description'] ?? '')); ?></textarea></div>
                        <button class="btn btn-success btn-block" type="submit"><?php echo $lineBeingEdited ? 'Update Line' : 'Add Line'; ?></button>
                        <?php if ($lineBeingEdited): ?><a class="btn btn-link btn-block mt-2" href="<?php echo e($pageUrl . '?version_id=' . (int)$selectedVersion['id']); ?>">Cancel edit</a><?php endif; ?>
                    </form>
                </div></div>
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                <div class="card mb-3"><div class="card-header">My Versions</div><div class="card-body p-0">
                    <?php if (empty($versions)): ?><div class="p-3 text-muted">No versions yet.</div><?php else: ?>
                    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Version</th><th>Status</th><th>Items</th><th>Total</th><th>Actions</th></tr></thead><tbody>
                        <?php foreach ($versions as $v): ?><tr>
                            <td><a href="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>"><?php echo e((string)$v['version_name']); ?></a><div class="small text-muted"><?php echo !empty($v['program_name']) ? e((string)$v['program_name']) : 'All Programs'; ?></div></td>
                            <td><?php echo e(strtoupper(str_replace('_', ' ', (string)$v['status']))); ?></td>
                            <td><?php echo (int)$v['item_count']; ?></td>
                            <td><?php echo number_format((float)$v['total_amount']); ?></td>
                            <td><?php if ((string)$v['status'] === 'draft' && (int)$v['locked'] === 0): ?><form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$v['id']); ?>" class="mb-1"><?php echo csrfField(); ?><input type="hidden" name="action" value="submit_version"><input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>"><button class="btn btn-sm btn-outline-primary" type="submit">Submit</button></form><form method="POST" action="<?php echo e($pageUrl); ?>" onsubmit="return confirm('Delete this draft version and all its lines?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_version"><input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete Draft</button></form><?php endif; ?></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </div></div>
                <?php if ($selectedVersion): ?><div class="card"><div class="card-header">Version Lines: <?php echo e((string)$selectedVersion['version_name']); ?></div><div class="card-body">
                    <?php if (empty($selectedLineGroups)): ?><div class="text-muted">No lines yet.</div><?php else: ?>
                        <?php foreach ($selectedLineGroups as $group): ?>
                            <div class="card mb-3 fee-group-card">
                                <div class="card-header py-2"><strong><?php echo e($group['year_label']); ?> - <?php echo e($group['semester_label']); ?></strong></div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead><tr><th>#</th><th>Item</th><th>Type</th><th>Amount</th><th>Discount</th><th>Fine</th><th>To Pay</th><th>Deadline</th><?php if ((string)$selectedVersion['status'] === 'draft' && (int)$selectedVersion['locked'] === 0): ?><th>Actions</th><?php endif; ?></tr></thead>
                                            <tbody>
                                            <?php $sn = 1; foreach ($group['rows'] as $line): $net = max(0, (float)$line['amount'] - (float)$line['discount_amount'] + (float)$line['fine_amount']); ?><tr><td><?php echo $sn++; ?></td><td><?php echo e((string)$line['fee_name']); ?></td><td><?php echo e((string)$line['fee_type']); ?></td><td><?php echo number_format((float)$line['amount']); ?></td><td><?php echo number_format((float)$line['discount_amount']); ?></td><td><?php echo number_format((float)$line['fine_amount']); ?></td><td><strong><?php echo number_format($net); ?></strong></td><td><?php echo e((string)($line['due_date'] ?: '-')); ?></td><?php if ((string)$selectedVersion['status'] === 'draft' && (int)$selectedVersion['locked'] === 0): ?><td><a class="btn btn-sm btn-outline-primary mr-1" href="<?php echo e($pageUrl . '?version_id=' . (int)$selectedVersion['id'] . '&edit_line_id=' . (int)$line['id']); ?>">Edit</a><form method="POST" action="<?php echo e($pageUrl . '?version_id=' . (int)$selectedVersion['id']); ?>" style="display:inline;" onsubmit="return confirm('Delete this draft fee line?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_line"><input type="hidden" name="version_id" value="<?php echo (int)$selectedVersion['id']; ?>"><input type="hidden" name="line_id" value="<?php echo (int)$line['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form></td><?php endif; ?></tr><?php endforeach; ?>
                                            <tr class="fee-group-total"><td colspan="<?php echo ((string)$selectedVersion['status'] === 'draft' && (int)$selectedVersion['locked'] === 0) ? '6' : '5'; ?>"><strong>Total</strong></td><td colspan="<?php echo ((string)$selectedVersion['status'] === 'draft' && (int)$selectedVersion['locked'] === 0) ? '3' : '3'; ?>"><strong><?php echo number_format((float)$group['total']); ?> UGX</strong></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
