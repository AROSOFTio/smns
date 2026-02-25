<?php
if (!isset($resultsWorkflowActive)) {
    $resultsWorkflowActive = '';
}
$workflowItems = [
    [
        'key' => 'enter_approval',
        'label' => 'Enter / Approval',
        'icon' => 'fas fa-edit',
        'href' => 'submitted.php'
    ],
    [
        'key' => 'publish_result',
        'label' => 'Publish Result',
        'icon' => 'fas fa-bullhorn',
        'href' => 'provisional.php'
    ],
    [
        'key' => 'marks_audit',
        'label' => 'Marks Audit Trail',
        'icon' => 'fas fa-history',
        'href' => 'audit.php?tab=audit'
    ],
    [
        'key' => 'student_result',
        'label' => 'Student Result',
        'icon' => 'fas fa-user-graduate',
        'href' => 'student-results.php'
    ]
];
?>
<style>
.results-workflow-nav {
    display: grid;
    grid-template-columns: repeat(4, minmax(180px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
.results-workflow-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    text-decoration: none;
    color: #1e293b;
    background: #fff;
    min-height: 52px;
}
.results-workflow-link i {
    width: 18px;
    color: #64748b;
}
.results-workflow-link.active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 700;
}
.results-workflow-link.active i {
    color: #1d4ed8;
}
.results-helper-note {
    background: #f8fafc;
    border: 1px solid #dbe2ea;
    color: #334155;
    border-radius: 10px;
    padding: 10px 12px;
}
html[data-theme='dark'] .results-course-table {
    color: #e2e8f0;
}
html[data-theme='dark'] .results-course-table thead.thead-light th {
    background: #1f2937;
    border-color: #334155;
    color: #e5e7eb;
}
html[data-theme='dark'] .results-course-table td,
html[data-theme='dark'] .results-course-table th {
    border-color: #334155;
}
html[data-theme='dark'] .results-course-table .table-primary,
html[data-theme='dark'] .results-course-table .table-primary > td,
html[data-theme='dark'] .results-course-table .table-primary > th {
    background: #1e3a8a;
    color: #dbeafe;
}
html[data-theme='dark'] .results-course-table .table-primary .text-muted,
html[data-theme='dark'] .results-course-table .text-muted {
    color: #93c5fd !important;
}
html[data-theme='dark'] .results-workflow-link {
    background: #0f172a;
    border-color: #334155;
    color: #cbd5e1;
}
html[data-theme='dark'] .results-workflow-link i {
    color: #94a3b8;
}
html[data-theme='dark'] .results-workflow-link.active {
    background: #1e3a8a;
    border-color: #3b82f6;
    color: #dbeafe;
}
html[data-theme='dark'] .results-workflow-link.active i {
    color: #dbeafe;
}
html[data-theme='dark'] .results-stat-card,
html[data-theme='dark'] .results-student-card,
html[data-theme='dark'] .results-slip-card {
    background: #0f172a;
    border-color: #334155;
}
html[data-theme='dark'] .results-stat-label,
html[data-theme='dark'] .results-student-label,
html[data-theme='dark'] .results-slip-label {
    color: #94a3b8;
}
html[data-theme='dark'] .results-stat-value,
html[data-theme='dark'] .results-student-value,
html[data-theme='dark'] .results-slip-value {
    color: #f8fafc;
}
html[data-theme='dark'] .results-helper-note {
    background: #1e293b;
    border-color: #334155;
    color: #e2e8f0;
}
@media (max-width: 992px) {
    .results-workflow-nav {
        grid-template-columns: repeat(2, minmax(160px, 1fr));
    }
}
@media (max-width: 576px) {
    .results-workflow-nav {
        grid-template-columns: 1fr;
    }
}
</style>
<div class="results-workflow-nav no-print">
    <?php foreach ($workflowItems as $item): ?>
        <?php $isActive = ($resultsWorkflowActive === $item['key']); ?>
        <a class="results-workflow-link <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e($item['href']); ?>">
            <i class="<?php echo e($item['icon']); ?>"></i>
            <span><?php echo e($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</div>
