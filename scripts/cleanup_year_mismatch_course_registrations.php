<?php
/**
 * One-time cleanup for year-of-study mismatched course registrations.
 *
 * Definition:
 * - A row is mismatched when course.level_year != latest approved
 *   semester_registrations.year_of_study for the same student+semester.
 *
 * Safety:
 * - Preserves rows that already have results.
 * - Archives then deletes only mismatched rows without results.
 *
 * Usage:
 *   php scripts/cleanup_year_mismatch_course_registrations.php         # dry run
 *   php scripts/cleanup_year_mismatch_course_registrations.php --apply # execute
 */

require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$batchId = 'year-mismatch-cleanup-' . date('Ymd-His');

try {
    $db = new Database();
    $conn = $db->getConnection();

    $sql = "
        SELECT
            cr.id,
            cr.student_id,
            cr.course_id,
            cr.semester_id,
            cr.status,
            c.course_code,
            c.course_name,
            c.level_year AS course_level_year,
            y.year_of_study AS registered_year_of_study,
            COALESCE(r.result_count, 0) AS result_count
        FROM course_registrations cr
        INNER JOIN courses c ON c.id = cr.course_id
        INNER JOIN (
            SELECT sr1.student_id, sr1.semester_id, sr1.year_of_study
            FROM semester_registrations sr1
            INNER JOIN (
                SELECT student_id, semester_id, MAX(id) AS max_id
                FROM semester_registrations
                WHERE status = 'approved'
                GROUP BY student_id, semester_id
            ) latest ON latest.max_id = sr1.id
        ) y ON y.student_id = cr.student_id AND y.semester_id = cr.semester_id
        LEFT JOIN (
            SELECT student_id, course_id, semester_id, COUNT(*) AS result_count
            FROM results
            GROUP BY student_id, course_id, semester_id
        ) r ON r.student_id = cr.student_id
          AND r.course_id = cr.course_id
          AND r.semester_id = cr.semester_id
        WHERE cr.status = 'approved'
          AND c.level_year <> y.year_of_study
        ORDER BY cr.id ASC
    ";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $toPreserve = [];
    $toDelete = [];
    foreach ($rows as $row) {
        if ((int)$row['result_count'] > 0) {
            $toPreserve[] = $row;
        } else {
            $toDelete[] = $row;
        }
    }

    echo "Mode: " . ($apply ? "APPLY" : "DRY RUN") . "\n";
    echo "Batch: {$batchId}\n";
    echo "Total year mismatches: " . count($rows) . "\n";
    echo "Preserved (has results): " . count($toPreserve) . "\n";
    echo "To archive+delete (no results): " . count($toDelete) . "\n";

    if (!empty($toPreserve)) {
        echo "\nSample preserved rows (first 10):\n";
        foreach (array_slice($toPreserve, 0, 10) as $r) {
            echo "  id={$r['id']} student={$r['student_id']} course={$r['course_code']} courseY={$r['course_level_year']} regY={$r['registered_year_of_study']} results={$r['result_count']}\n";
        }
    }
    if (!empty($toDelete)) {
        echo "\nSample delete rows (first 10):\n";
        foreach (array_slice($toDelete, 0, 10) as $r) {
            echo "  id={$r['id']} student={$r['student_id']} course={$r['course_code']} courseY={$r['course_level_year']} regY={$r['registered_year_of_study']}\n";
        }
    }

    if (!$apply) {
        echo "\nDry run complete. Re-run with --apply to execute.\n";
        exit(0);
    }

    if (empty($toDelete)) {
        echo "\nNo deletable rows found. Nothing to do.\n";
        exit(0);
    }

    $deleteIds = array_map(static function ($r) {
        return (int)$r['id'];
    }, $toDelete);

    // Prepare archive table outside transaction (DDL may auto-commit).
    $conn->exec("CREATE TABLE IF NOT EXISTS course_registrations_cleanup_archive LIKE course_registrations");

    $archiveCols = [];
    $archColStmt = $conn->query("SHOW COLUMNS FROM course_registrations_cleanup_archive");
    while ($c = $archColStmt->fetch(PDO::FETCH_ASSOC)) {
        $archiveCols[strtolower((string)$c['Field'])] = true;
    }
    if (!isset($archiveCols['cleanup_batch'])) {
        $conn->exec("ALTER TABLE course_registrations_cleanup_archive ADD COLUMN cleanup_batch VARCHAR(64) NULL");
    }
    if (!isset($archiveCols['cleanup_reason'])) {
        $conn->exec("ALTER TABLE course_registrations_cleanup_archive ADD COLUMN cleanup_reason VARCHAR(255) NULL");
    }
    if (!isset($archiveCols['archived_at'])) {
        $conn->exec("ALTER TABLE course_registrations_cleanup_archive ADD COLUMN archived_at DATETIME NULL");
    }
    if (!isset($archiveCols['original_registration_id'])) {
        $conn->exec("ALTER TABLE course_registrations_cleanup_archive ADD COLUMN original_registration_id INT NULL");
    }

    $baseCols = [];
    $colStmt = $conn->query("SHOW COLUMNS FROM course_registrations");
    while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $baseCols[] = '`' . str_replace('`', '``', (string)$col['Field']) . '`';
    }
    if (empty($baseCols)) {
        throw new RuntimeException('Could not resolve course_registrations columns.');
    }

    $conn->beginTransaction();

    $ph = implode(',', array_fill(0, count($deleteIds), '?'));
    $baseColSql = implode(', ', $baseCols);
    $archiveSql = "
        INSERT INTO course_registrations_cleanup_archive (
            {$baseColSql}, cleanup_batch, cleanup_reason, archived_at, original_registration_id
        )
        SELECT
            {$baseColSql}, ?, ?, NOW(), id
        FROM course_registrations
        WHERE id IN ({$ph})
    ";
    $archiveStmt = $conn->prepare($archiveSql);
    $archiveParams = array_merge([$batchId, 'year_mismatch_without_results'], $deleteIds);
    $archiveStmt->execute($archiveParams);

    $deleteStmt = $conn->prepare("DELETE FROM course_registrations WHERE id IN ({$ph})");
    $deleteStmt->execute($deleteIds);
    $deletedCount = $deleteStmt->rowCount();

    if ($conn->inTransaction()) {
        $conn->commit();
    }

    echo "\nCleanup completed.\n";
    echo "Archived rows: " . count($deleteIds) . "\n";
    echo "Deleted rows: {$deletedCount}\n";
    echo "Archive table: course_registrations_cleanup_archive\n";
    echo "Batch id: {$batchId}\n";
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

