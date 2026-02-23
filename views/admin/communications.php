<?php
/**
 * Admin Communications
 * Bulk communication to students via portal notifications and email.
 */
require_once '../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (
    !isset($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true ||
    ($_SESSION['admin_role'] ?? '') !== 'admin'
) {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();
$logger = new Logger();
$communicationService = new AdminCommunicationService($conn, $logger);
$communicationService->ensureTables();

$communicationTypes = [
    'institution_announcement' => 'Institution Announcement',
    'exam_timetable' => 'Examination Timetable',
    'public_holiday' => 'Public Holiday Notice',
    'campus_event' => 'Campus Event',
    'academic_announcement' => 'Academic Announcement',
    'general' => 'General Notice'
];

$audienceScopes = [
    'all_students' => 'All Active Students',
    'program' => 'Specific Program',
    'level_year' => 'Specific Year Level',
    'semester' => 'Students Approved In Selected Semester'
];

$form = [
    'title' => '',
    'message' => '',
    'communication_type' => 'institution_announcement',
    'audience_scope' => 'all_students',
    'program_id' => '',
    'level_year' => '',
    'semester_id' => '',
    'send_portal' => '1',
    'send_email' => ''
];
$submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'send_communication') {
    $form['title'] = trim((string)($_POST['title'] ?? ''));
    $form['message'] = trim((string)($_POST['message'] ?? ''));
    $form['communication_type'] = (string)($_POST['communication_type'] ?? 'general');
    $form['audience_scope'] = (string)($_POST['audience_scope'] ?? 'all_students');
    $form['program_id'] = (string)($_POST['program_id'] ?? '');
    $form['level_year'] = (string)($_POST['level_year'] ?? '');
    $form['semester_id'] = (string)($_POST['semester_id'] ?? '');
    $form['send_portal'] = isset($_POST['send_portal']) ? '1' : '';
    $form['send_email'] = isset($_POST['send_email']) ? '1' : '';

    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $submitError = 'Invalid CSRF token.';
    } else {
        try {
            $result = $communicationService->sendToStudents(
                (int)($currentUser['id'] ?? 0),
                $form['title'],
                $form['message'],
                [
                    'communication_type' => $form['communication_type'],
                    'source' => 'manual',
                    'audience_scope' => $form['audience_scope'],
                    'program_id' => $form['program_id'] !== '' ? (int)$form['program_id'] : null,
                    'level_year' => $form['level_year'] !== '' ? (int)$form['level_year'] : null,
                    'semester_id' => $form['semester_id'] !== '' ? (int)$form['semester_id'] : null,
                    'send_portal' => !empty($form['send_portal']),
                    'send_email' => !empty($form['send_email']),
                    'link' => BASE_URL . '/views/student/notifications.php',
                    'notification_type' => 'info'
                ]
            );

            $session->setFlash(
                'success',
                'Communication sent. Recipients: ' . (int)$result['total_recipients']
                    . ', Portal Delivered: ' . (int)$result['portal_success_count']
                    . ', Email Sent: ' . (int)$result['email_success_count']
                    . ', Email Failed: ' . (int)$result['email_fail_count']
                    . ', Status: ' . strtoupper((string)$result['status'])
            );
            header('Location: communications.php?view_comm=' . (int)$result['communication_id']);
            exit;
        } catch (Exception $e) {
            $submitError = $e->getMessage();
        }
    }
}

$programs = [];
try {
    $progStmt = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC");
    $programs = $progStmt ? ($progStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $programs = [];
}

$semesters = [];
try {
    $semStmt = $conn->query("SELECT s.id, s.semester_name, s.semester_number, ay.year_name
        FROM semesters s
        INNER JOIN academic_years ay ON s.academic_year_id = ay.id
        ORDER BY ay.start_date DESC, s.semester_number ASC, s.id DESC");
    $semesters = $semStmt ? ($semStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $semesters = [];
}

$programLookup = [];
foreach ($programs as $p) {
    $programLookup[(int)$p['id']] = (string)$p['program_name'];
}

$semesterLookup = [];
foreach ($semesters as $s) {
    $semesterLookup[(int)$s['id']] = (string)$s['year_name'] . ' - ' . (string)$s['semester_name'];
}

$communications = $communicationService->getCommunications(150);
$selectedCommunicationId = isset($_GET['view_comm']) ? (int)$_GET['view_comm'] : 0;
$selectedCommunication = null;
foreach ($communications as $item) {
    if ((int)($item['id'] ?? 0) === $selectedCommunicationId) {
        $selectedCommunication = $item;
        break;
    }
}
$recipientRows = $selectedCommunicationId > 0 ? $communicationService->getCommunicationRecipients($selectedCommunicationId, 1500) : [];

$scopeLabel = function ($row) use ($programLookup, $semesterLookup) {
    $scope = (string)($row['audience_scope'] ?? 'all_students');
    if ($scope === 'program') {
        $pid = (int)($row['audience_program_id'] ?? 0);
        return 'Program: ' . ($programLookup[$pid] ?? ('#' . $pid));
    }
    if ($scope === 'level_year') {
        return 'Year Level: ' . (int)($row['audience_level_year'] ?? 0);
    }
    if ($scope === 'semester') {
        $sid = (int)($row['audience_semester_id'] ?? 0);
        return 'Semester: ' . ($semesterLookup[$sid] ?? ('#' . $sid));
    }
    return 'All Active Students';
};

$typeLabel = function ($type) use ($communicationTypes) {
    $key = (string)$type;
    return $communicationTypes[$key] ?? ucwords(str_replace('_', ' ', $key));
};

$flashSuccess = $session->getFlash('success');
$flashError = $session->getFlash('error');

$pageTitle = 'Admin Communications - ' . APP_NAME;
include '../../includes/header.php';
?>
<?php include '../../includes/admin/sidebar.php'; ?>

<style>
.communications-page,
.communications-page .content-area {
    max-width: 100%;
    overflow-x: hidden;
}

.communications-page .card,
.communications-page .table-wrap {
    max-width: 100%;
}

.communications-page .table-wrap {
    overflow-x: auto;
}

.communications-page table {
    width: 100%;
}

.communications-page th,
.communications-page td {
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    vertical-align: top;
}

.communications-page .form-note {
    font-size: 0.85rem;
    color: #6b7280;
}

.communications-page .recipient-error {
    max-width: 380px;
}

@media (max-width: 768px) {
    .communications-page .content-area {
        padding: 12px !important;
    }
}
</style>

<div class="main-content communications-page">
    <div class="topbar">
        <div class="topbar-left">
            <h4><i class="fas fa-bullhorn mr-1"></i> Admin Communications</h4>
        </div>
        <div class="topbar-right">
            <a href="<?php echo BASE_URL; ?>/views/admin/academic-calendar.php?tab=announcements" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-calendar-alt"></i> Calendar Announcements
            </a>
        </div>
    </div>

    <div class="content-area container-fluid p-4">
        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if (!empty($flashError)): ?>
            <div class="alert alert-danger"><?php echo e($flashError); ?></div>
        <?php endif; ?>
        <?php if (!empty($submitError)): ?>
            <div class="alert alert-danger"><?php echo e($submitError); ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><strong>Compose Student Communication</strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e(Security::generateCSRFToken()); ?>">
                    <input type="hidden" name="action" value="send_communication">

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Title</label>
                            <input type="text" name="title" class="form-control" maxlength="255" required value="<?php echo e($form['title']); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Type</label>
                            <select name="communication_type" class="form-control" required>
                                <?php foreach ($communicationTypes as $typeKey => $typeName): ?>
                                    <option value="<?php echo e($typeKey); ?>" <?php echo $form['communication_type'] === $typeKey ? 'selected' : ''; ?>>
                                        <?php echo e($typeName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Audience Scope</label>
                            <select name="audience_scope" id="audienceScope" class="form-control" required>
                                <?php foreach ($audienceScopes as $scopeKey => $scopeName): ?>
                                    <option value="<?php echo e($scopeKey); ?>" <?php echo $form['audience_scope'] === $scopeKey ? 'selected' : ''; ?>>
                                        <?php echo e($scopeName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4 scope-field" id="scopeProgramWrap">
                            <label>Program</label>
                            <select name="program_id" id="scopeProgram" class="form-control">
                                <option value="">Select program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo (int)$program['id']; ?>" <?php echo (string)$form['program_id'] === (string)$program['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4 scope-field" id="scopeLevelWrap">
                            <label>Year Level</label>
                            <select name="level_year" id="scopeLevel" class="form-control">
                                <option value="">Select year level</option>
                                <?php for ($y = 1; $y <= 8; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo (string)$form['level_year'] === (string)$y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4 scope-field" id="scopeSemesterWrap">
                            <label>Semester</label>
                            <select name="semester_id" id="scopeSemester" class="form-control">
                                <option value="">Select semester</option>
                                <?php foreach ($semesters as $semester): ?>
                                    <option value="<?php echo (int)$semester['id']; ?>" <?php echo (string)$form['semester_id'] === (string)$semester['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($semester['year_name'] . ' - ' . $semester['semester_name'] . ' (No. ' . (int)$semester['semester_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" rows="5" required><?php echo e($form['message']); ?></textarea>
                    </div>

                    <div class="form-group mb-2">
                        <label class="d-block mb-2">Delivery Channels</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="sendPortal" name="send_portal" value="1" <?php echo !empty($form['send_portal']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="sendPortal">Student Portal Notifications</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="sendEmail" name="send_email" value="1" <?php echo !empty($form['send_email']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="sendEmail">Email</label>
                        </div>
                        <div class="form-note mt-1">At least one channel is required. Email sending depends on SMTP configuration.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Communication
                    </button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><strong>Recent Communications</strong></div>
            <div class="card-body p-0">
                <div class="table-wrap">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Sent At</th>
                                <th>Type</th>
                                <th>Title</th>
                                <th>Audience</th>
                                <th>Recipients</th>
                                <th>Portal</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Sender</th>
                                <th>Source</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($communications)): ?>
                                <tr><td colspan="11" class="text-center text-muted">No communications sent yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($communications as $row): ?>
                                    <tr>
                                        <td><?php echo e(Helper::formatDateTime($row['created_at'] ?? '', 'M d, Y g:i A')); ?></td>
                                        <td><?php echo e($typeLabel($row['communication_type'] ?? 'general')); ?></td>
                                        <td><?php echo e($row['title'] ?? ''); ?></td>
                                        <td><?php echo e($scopeLabel($row)); ?></td>
                                        <td><?php echo (int)($row['total_recipients'] ?? 0); ?></td>
                                        <td><?php echo (int)($row['portal_success_count'] ?? 0); ?></td>
                                        <td>
                                            <?php echo (int)($row['email_success_count'] ?? 0); ?>
                                            <?php if ((int)($row['email_fail_count'] ?? 0) > 0): ?>
                                                <small class="text-danger d-block">Fail: <?php echo (int)$row['email_fail_count']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo (($row['status'] ?? '') === 'completed') ? 'success' : ((($row['status'] ?? '') === 'partial') ? 'warning' : 'danger'); ?>">
                                                <?php echo e(strtoupper((string)($row['status'] ?? '-'))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo e($row['sender_name'] ?? ($row['username'] ?? 'System')); ?>
                                            <?php if (!empty($row['user_role'])): ?>
                                                <small class="text-muted d-block"><?php echo e(ucfirst((string)$row['user_role'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo e(ucwords(str_replace('_', ' ', (string)($row['source'] ?? 'manual')))); ?>
                                            <?php if ((int)($row['source_ref_id'] ?? 0) > 0): ?>
                                                <small class="text-muted d-block">#<?php echo (int)$row['source_ref_id']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="communications.php?view_comm=<?php echo (int)$row['id']; ?>#recipient-log" class="btn btn-sm btn-outline-primary">
                                                Recipients
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card" id="recipient-log">
            <div class="card-header">
                <strong>Recipient Delivery Log</strong>
                <?php if ($selectedCommunication): ?>
                    <small class="text-muted">Communication #<?php echo (int)$selectedCommunication['id']; ?> - <?php echo e($selectedCommunication['title'] ?? ''); ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!$selectedCommunication): ?>
                    <div class="p-3 text-muted">Select a communication above to view recipient-level delivery details.</div>
                <?php elseif (empty($recipientRows)): ?>
                    <div class="p-3 text-muted">No recipient delivery rows found for this communication.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Recipient</th>
                                    <th>Student No</th>
                                    <th>Email</th>
                                    <th>Portal</th>
                                    <th>Email Delivery</th>
                                    <th>Error Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipientRows as $recipient): ?>
                                    <tr>
                                        <td><?php echo e($recipient['recipient_name'] ?? ($recipient['username'] ?? '-')); ?></td>
                                        <td><?php echo e($recipient['registration_number'] ?? '-'); ?></td>
                                        <td><?php echo e($recipient['recipient_email'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo ((int)($recipient['portal_notified'] ?? 0) === 1) ? 'success' : 'secondary'; ?>">
                                                <?php echo ((int)($recipient['portal_notified'] ?? 0) === 1) ? 'SENT' : 'NOT SENT'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo ((int)($recipient['email_sent'] ?? 0) === 1) ? 'success' : 'secondary'; ?>">
                                                <?php echo ((int)($recipient['email_sent'] ?? 0) === 1) ? 'SENT' : 'NOT SENT'; ?>
                                            </span>
                                        </td>
                                        <td class="recipient-error">
                                            <?php echo e($recipient['email_error'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var audienceSelect = document.getElementById('audienceScope');
    var programWrap = document.getElementById('scopeProgramWrap');
    var levelWrap = document.getElementById('scopeLevelWrap');
    var semesterWrap = document.getElementById('scopeSemesterWrap');
    var programSelect = document.getElementById('scopeProgram');
    var levelSelect = document.getElementById('scopeLevel');
    var semesterSelect = document.getElementById('scopeSemester');

    function toggleAudienceFields() {
        var scope = audienceSelect ? audienceSelect.value : 'all_students';
        var isProgram = scope === 'program';
        var isLevel = scope === 'level_year';
        var isSemester = scope === 'semester';

        if (programWrap) programWrap.style.display = isProgram ? '' : 'none';
        if (levelWrap) levelWrap.style.display = isLevel ? '' : 'none';
        if (semesterWrap) semesterWrap.style.display = isSemester ? '' : 'none';

        if (programSelect) programSelect.required = isProgram;
        if (levelSelect) levelSelect.required = isLevel;
        if (semesterSelect) semesterSelect.required = isSemester;
    }

    if (audienceSelect) {
        audienceSelect.addEventListener('change', toggleAudienceFields);
    }
    toggleAudienceFields();
});
</script>

<?php include '../../includes/footer.php'; ?>
