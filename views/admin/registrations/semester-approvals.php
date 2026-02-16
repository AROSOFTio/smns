    // --- Notify all admins of pending approvals with action links ---
    try {
        $adminRows = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $notified = 0;
        foreach ($adminRows as $admin) {
            // Check if a similar notification already exists and is unread
            $exists = $conn->prepare("SELECT id FROM notifications WHERE user_id = :uid AND type = 'warning' AND title = :title AND read_status = 'unread' LIMIT 1");
            $title = 'Pending Semester Approvals';
            $exists->execute(['uid' => $admin['id'], 'title' => $title]);
            if (!$exists->fetch()) {
                $msg = 'There are ' . count($pending) . ' pending semester registrations requiring approval.';
                // Actionable link: use a special action:approve_pending_registrations for the bell
                $actionLink = 'action:approve_pending_registrations';
                $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at, read_status) VALUES (:uid, :title, :msg, 'warning', :link, NOW(), 'unread')");
                $ins->execute([
                    'uid' => $admin['id'],
                    'title' => $title,
                    'msg' => $msg,
                    'link' => $actionLink
                ]);
                $notified++;
            }
        }
        echo "   → Actionable notifications sent to admins: {$notified}\n";
    } catch (Exception $e) {
        echo "   → Failed to notify admins: " . $e->getMessage() . "\n";
    }
<?php
/**
 * Diagnostic Script - Check Course Assignment Configuration
 * Run this to verify your course assignments are properly configured
 */
$baseDir = realpath(__DIR__ . '/../../../');
require_once $baseDir . '/config.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== COURSE ASSIGNMENT DIAGNOSTIC ===\n\n";

// 1. Check if course_assignments table exists
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'course_assignments'");
    if ($stmt->rowCount() > 0) {
        echo "   ✓ course_assignments table exists\n";
        $cols = $conn->query("SHOW COLUMNS FROM course_assignments")->fetchAll(PDO::FETCH_COLUMN);
        echo "   Columns: " . implode(', ', $cols) . "\n";
    } else {
        echo "   ✗ course_assignments table does NOT exist\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 2. Check students table columns
try {
    $stmt = $conn->query("SHOW COLUMNS FROM students");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $requiredCols = ['year_of_study', 'level_year', 'program_id'];
    foreach ($requiredCols as $col) {
        if (in_array($col, $columns)) {
            echo "   ✓ {$col} exists\n";
        } else {
            echo "   ✗ {$col} MISSING\n";
        }
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 3. Sample data check
$progCount = $conn->query("SELECT COUNT(*) FROM programs")->fetchColumn();
echo "   Programs: {$progCount}\n";
$courseCount = $conn->query("SELECT COUNT(*) FROM courses WHERE status = 'active'")->fetchColumn();
echo "   Active Courses: {$courseCount}\n";
$semCount = $conn->query("SELECT COUNT(*) FROM semesters")->fetchColumn();
echo "   Semesters: {$semCount}\n";

// 4. Check course assignments (if table exists)
try {
    $assignCount = $conn->query("SELECT COUNT(*) FROM course_assignments")->fetchColumn();
    echo "   Course Assignments: {$assignCount}\n";
    if ($assignCount > 0) {
        $sampleStmt = $conn->query("
            SELECT ca.*, p.program_name, c.course_code, c.course_name, s.semester_number
            FROM course_assignments ca
            LEFT JOIN programs p ON ca.program_id = p.id
            LEFT JOIN courses c ON ca.course_id = c.id
            LEFT JOIN semesters s ON ca.semester_id = s.id
            LIMIT 5
        ");
        $samples = $sampleStmt->fetchAll();
        foreach ($samples as $sample) {
            echo "   - {$sample['program_name']} | Year {$sample['year_of_study']} | Sem {$sample['semester_number']} | {$sample['course_code']} - {$sample['course_name']}\n";
        }
    } else {
        echo "   ⚠ NO COURSE ASSIGNMENTS FOUND!\n";
    }
} catch (Exception $e) {
    echo "   ✗ Cannot check assignments: " . $e->getMessage() . "\n";
}

// 5. Checking pending semester registrations
$pendingStmt = $conn->query("
    SELECT sr.*, s.first_name, s.last_name, s.program_id, 
           COALESCE(s.year_of_study, s.level_year) as year,
           sem.semester_number
    FROM semester_registrations sr
    JOIN students s ON sr.student_id = s.id
    JOIN semesters sem ON sr.semester_id = sem.id
    WHERE sr.status = 'pending'
    LIMIT 3
");
$pending = $pendingStmt->fetchAll();
if (count($pending) > 0) {
    echo "   Found " . count($pending) . " pending registration(s):\n";
    foreach ($pending as $p) {
        echo "   - {$p['first_name']} {$p['last_name']} | Program ID: {$p['program_id']} | Year: {$p['year']} | Semester: {$p['semester_number']}\n";
        try {
            $checkStmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM course_assignments ca
                WHERE ca.program_id = :prog
                  AND ca.semester_id = :sem
                  AND ca.year_of_study = :year
                  AND ca.status = 'active'
            ");
            $checkStmt->execute([
                'prog' => $p['program_id'],
                'sem' => $p['semester_id'],
                'year' => $p['year']
            ]);
            $courseCount = $checkStmt->fetchColumn();
            echo "     → Would auto-register {$courseCount} course(s)\n";
        } catch (Exception $e) {
            echo "     → Cannot determine courses\n";
        }
    }

    // --- Notify all admins of pending approvals ---
    try {
        $adminRows = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $notified = 0;
        foreach ($adminRows as $admin) {
            // Check if a similar notification already exists and is unread
            $exists = $conn->prepare("SELECT id FROM notifications WHERE user_id = :uid AND type = 'warning' AND title = :title AND read_status = 'unread' LIMIT 1");
            $title = 'Pending Semester Approvals';
            $exists->execute(['uid' => $admin['id'], 'title' => $title]);
            if (!$exists->fetch()) {
                $msg = 'There are ' . count($pending) . ' pending semester registrations requiring approval.';
                $link = BASE_URL . '/views/admin/registrations/pending.php';
                $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at, read_status) VALUES (:uid, :title, :msg, 'warning', :link, NOW(), 'unread')");
                $ins->execute([
                    'uid' => $admin['id'],
                    'title' => $title,
                    'msg' => $msg,
                    'link' => $link
                ]);
                $notified++;
            }
        }
        echo "   → Notifications sent to admins: {$notified}\n";
    } catch (Exception $e) {
        echo "   → Failed to notify admins: " . $e->getMessage() . "\n";
    }
} else {
    echo "   No pending registrations\n";
}

echo "\n=== END DIAGNOSTIC ===\n";
echo "\nNext Steps:\n";
echo "1. If course_assignments table is missing, create it using the provided SQL migration.\n";
echo "2. If year_of_study column is missing from students, add it using the provided SQL migration.\n";
echo "3. Assign courses to programs/semesters/years in the admin panel.\n";
echo "4. Run the SQL migration for any missing structure.\n";