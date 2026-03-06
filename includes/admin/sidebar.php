<?php
/**
 * Admin Sidebar Navigation
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
$currentReportType = $_GET['report'] ?? '';

// Use existing session/auth/currentUser if already set by the page
if (!isset($session) || !is_object($session)) {
    $session = new Session('admin');
}
if (!isset($auth) || !is_object($auth)) {
    $auth = new Auth('admin');
}
if (!isset($currentUser) || !is_array($currentUser)) {
    $currentUser = $auth->getCurrentUser();
}
?>

<style>
    .sidebar {
        width: var(--sidebar-width, 230px);
        height: 100vh;
        height: 100dvh;
        max-height: 100dvh;
        background: #f8f9fa;
        position: fixed;
        left: 0;
        top: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: none;
        z-index: 1000;
        border-right: 0;
        transition: left 0.3s ease, width 0.3s ease;
    }
    .sidebar-header {
        padding: 20px 15px 15px;
        text-align: center;
        background: #ffffff;
        border-bottom: 2px solid #e5e7eb;
        position: sticky;
        top: 0;
        z-index: 2;
        flex: 0 0 auto;
    }
    .sidebar-header h3 { color: #000; margin: 0 0 3px 0; font-size: 20px; font-weight: 700; }
    .sidebar-header p { color: #64748b; margin: 0; font-size: 11px; }
    .sidebar-menu {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 0 104px 0;
        scrollbar-gutter: stable;
    }
    .sidebar-menu ul { list-style: none; padding: 0; margin: 0; }
    .sidebar-menu li { margin: 0; }
    .sidebar-menu > ul > li > a {
        display: flex; align-items: center; padding: 10px 15px;
        color: #475569; text-decoration: none; transition: all 0.2s ease;
    }
    .sidebar-menu > ul > li > a:hover { background: #e0f2fe; color: #0369a1; }
    .sidebar-menu > ul > li > a.active { background: #dbeafe; color: #0369a1; font-weight: 600; border-left: 3px solid #3b82f6; }
    .sidebar-menu a i { width: 18px; margin-right: 10px; text-align: center; font-size: 15px; }
    .sidebar-menu a span { flex: 1; font-size: 13px; }
    .sidebar-menu .submenu { display: none; list-style: none; padding: 0; margin: 0; background: #f1f5f9; }
    .sidebar-menu li.open > .submenu { display: block; }
    .sidebar-menu .submenu a { padding: 8px 15px 8px 48px; font-size: 12px; color: #334155; display: block; text-decoration: none; }
    .sidebar-menu .submenu a:hover { background: #e0f2fe; }
    .sidebar-menu .submenu a.active { font-weight: 700; color: #0284c7; }
    .menu-section { color: #94a3b8; padding: 12px 15px 6px; font-size: 10px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.8px; border-top: 1px solid #e5e7eb; margin-top: 8px; }
    .menu-section:first-child { margin-top: 0; border-top: none; }
    .logout-item { margin-top: 15px; border-top: 2px solid #e5e7eb; padding-top: 8px; }
    .logout-link { color: #dc2626 !important; }
    .logout-link:hover { background: #fee2e2 !important; color: #991b1b !important; }

    @media (max-width: 767.98px) {
        .sidebar {
            left: calc(-1 * var(--sidebar-width, 230px));
        }
        .sidebar.active {
            left: 0;
        }
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
    }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo APP_SHORT_NAME; ?></h3>
        <p>Administration</p>
    </div>

    <div class="sidebar-menu">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/dashboard.php" class="<?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
            </li>

            <li class="menu-section">User Management</li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/students/list.php" class="<?php echo $currentDir == 'students' ? 'active' : ''; ?>">
                    <i class="fas fa-user-graduate"></i><span>Students</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/students/list.php?status=graduated" class="<?php echo ($currentDir == 'students' && ($currentPage == 'graduation-awards.php' || (($_GET['status'] ?? '') === 'graduated'))) ? 'active' : ''; ?>">
                    <i class="fas fa-certificate"></i><span>Graduation & Awards</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/student_requests.php?view=transcript" class="<?php echo ($currentPage == 'student_requests.php' && (($_GET['view'] ?? '') === 'transcript')) ? 'active' : ''; ?>">
                    <i class="fas fa-file-signature"></i><span>Transcript</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/students/issued-transcripts.php" class="<?php echo $currentPage == 'issued-transcripts.php' ? 'active' : ''; ?>">
                    <i class="fas fa-shield-alt"></i><span>Issued Transcripts</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/list.php" class="<?php echo ($currentDir == 'lecturers' && $currentPage !== 'schedule.php') ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i><span>Lecturers</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/lecturers/schedule.php" class="<?php echo $currentPage == 'schedule.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i><span>Lecturer Schedule</span>
                </a>
            </li>

            <li class="menu-section">Academic Management</li>
            <li class="<?php echo in_array($currentDir, ['registrations', 'courses']) ? 'open' : ''; ?>">
                <a href="#" class="has-submenu"><i class="fas fa-university"></i><span>Academics</span></a>
                <ul class="submenu">
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/registrations/pending.php" class="<?php echo $currentPage === 'pending.php' ? 'active' : ''; ?>">Pending Registrations</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/courses/list.php" class="<?php echo $currentDir === 'courses' ? 'active' : ''; ?>">Courses</a></li>
                </ul>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/academic-calendar.php" class="<?php echo $currentPage == 'academic-calendar.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Academic Calendar</span>
                </a>
            </li>
            <li class="<?php echo $currentDir === 'results' ? 'open' : ''; ?>">
                <a href="#" class="has-submenu"><i class="fas fa-poll"></i><span>Results Management</span></a>
                <ul class="submenu">
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/results/submitted.php" class="<?php echo $currentPage === 'submitted.php' ? 'active' : ''; ?>">Enter/Approve Marks</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/results/provisional.php" class="<?php echo $currentPage === 'provisional.php' ? 'active' : ''; ?>">Publish Results</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/results/audit.php" class="<?php echo $currentPage === 'audit.php' ? 'active' : ''; ?>">Marks Audit Trail</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/results/student-results.php" class="<?php echo in_array($currentPage, ['student-results.php', 'view-slip.php']) ? 'active' : ''; ?>">Student Results</a></li>
                </ul>
            </li>

            <li class="menu-section">Operations</li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/student_requests.php" class="<?php echo $currentPage == 'student_requests.php' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i><span>Student Requests</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/communications.php" class="<?php echo $currentPage == 'communications.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn"></i><span>Communications</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/finance/fee-structures.php" class="<?php echo ($currentDir === 'finance' && $currentPage === 'fee-structures.php') ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i><span>Fee Structure Approvals</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/activity-recovery.php" class="<?php echo $currentPage == 'activity-recovery.php' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i><span>Activity Recovery</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/change-tracker.php" class="<?php echo $currentPage == 'change-tracker.php' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i><span>Change Tracker</span>
                </a>
            </li>
            <li class="<?php echo $currentDir == 'reports' ? 'open' : ''; ?>">
                <a href="#" class="has-submenu <?php echo $currentDir == 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i><span>Reports</span>
                </a>
                <ul class="submenu">
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/reports/index.php?report=executive" class="<?php echo ($currentDir == 'reports' && ($currentReportType === 'executive' || $currentPage === 'index.php')) ? 'active' : ''; ?>">Executive Summary</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/reports/index.php?report=enrollment" class="<?php echo ($currentDir == 'reports' && $currentReportType === 'enrollment') ? 'active' : ''; ?>">Enrollment Trends</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/reports/index.php?report=financial" class="<?php echo ($currentDir == 'reports' && $currentReportType === 'financial') ? 'active' : ''; ?>">Financial Summary</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/reports/index.php?report=staff" class="<?php echo ($currentDir == 'reports' && $currentReportType === 'staff') ? 'active' : ''; ?>">Staff Workload</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/reports/index.php?report=system" class="<?php echo ($currentDir == 'reports' && $currentReportType === 'system') ? 'active' : ''; ?>">System Overview</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/views/admin/reports/schedules.php" class="<?php echo ($currentDir == 'reports' && $currentPage === 'schedules.php') ? 'active' : ''; ?>">Scheduled Reports</a></li>
                </ul>
            </li>

            <li class="menu-section">System & Settings</li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/email-test.php" class="<?php echo $currentPage == 'email-test.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i><span>Email Test</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/system/health.php" class="<?php echo $currentPage == 'health.php' ? 'active' : ''; ?>">
                    <i class="fas fa-heartbeat"></i><span>System Health</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/views/admin/settings/index.php" class="<?php echo $currentDir == 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i><span>Settings</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/unlock_user.php" class="<?php echo $currentPage == 'unlock_user.php' ? 'active' : ''; ?>">
                    <i class="fas fa-unlock"></i><span>Unlock User Account</span>
                </a>
            </li>

            <li class="logout-item">
                <a href="<?php echo BASE_URL; ?>/views/admin/logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sidebar-menu .has-submenu').forEach(function(menuItem) {
        menuItem.addEventListener('click', function(e) {
            e.preventDefault();
            var parentLi = this.parentElement;
            document.querySelectorAll('.sidebar-menu li.open').forEach(function(li) {
                if (li !== parentLi) li.classList.remove('open');
            });
            parentLi.classList.toggle('open');
        });
    });
});
</script>
