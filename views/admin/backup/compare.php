<?php
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/views/auth/login.php');
    exit;
}
$currentUser = $auth->getCurrentUser();

$file = $_GET['file'] ?? '';
$basename = basename($file);
$backupDir = BASE_PATH . DIRECTORY_SEPARATOR . 'database backup';
$fullPath = realpath($backupDir . DIRECTORY_SEPARATOR . $basename);
if (!$fullPath || strpos($fullPath, realpath($backupDir)) !== 0 || !file_exists($fullPath)) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    echo 'File not found';
    exit;
}

// Parse backup file: collect CREATE TABLE stmts and INSERT row counts (and small-data samples)
$sql = file_get_contents($fullPath);
$backupSchemas = [];
$backupCounts = [];
$backupSmallData = []; // table => array(rows)

// Regex to extract CREATE TABLE statements
if (preg_match_all('/CREATE TABLE\s+`([^`]+)`[\s\S]*?;\s*/i', $sql, $cMatches)) {
    foreach ($cMatches[0] as $i => $stmt) {
        $tbl = $cMatches[1][$i];
        $backupSchemas[$tbl] = $stmt;
    }
}

// Extract INSERT INTO statements and count rows per table
if (preg_match_all('/INSERT INTO\s+`([^`]+)`\s+.*?VALUES\s*(\(.+?\));/is', $sql, $iMatches, PREG_SET_ORDER)) {
    // The above matches only the first VALUES group; use broader approach
}
// Better: find all INSERT INTO `table` ... ; blocks
if (preg_match_all('/INSERT INTO\s+`([^`]+)`[\s\S]*?;\s*/i', $sql, $insMatches, PREG_SET_ORDER)) {
    foreach ($insMatches as $m) {
        $tbl = $m[1];
        $stmt = $m[0];
        // find the VALUES(...) portion
        if (preg_match('/VALUES\s*(\(.+\))\s*;\s*$/is', $stmt, $vMatch)) {
            $valuesText = $vMatch[1];
        } else {
            // fallback: strip until VALUES
            $parts = preg_split('/VALUES/i', $stmt, 2);
            $valuesText = isset($parts[1]) ? $parts[1] : '';
        }

        // crude count of rows: count top-level '),(' occurrences safely by ignoring those inside quotes
        $rowsInStmt = 0;
        // Trim leading/trailing parentheses groups
        $trimmed = trim($valuesText);
        // remove trailing semicolon if present
        $trimmed = rtrim($trimmed, ";\n \t");
        // naive: count '),(' occurrences outside quotes
        $inQuote = false; $esc = false; $depth = 0; $buf = ''; $rowCountThisStmt = 0;
        $chars = str_split($trimmed);
        $current = '';
        foreach ($chars as $ch) {
            $current .= $ch;
            if ($ch === "\\" && !$esc) { $esc = true; continue; }
            if ($ch === "'" && !$esc) { $inQuote = !$inQuote; }
            $esc = false;
        }
        // simple approach: count "),(" outside single quotes
        $rowCountThisStmt = 0;
        $s = $trimmed;
        $len = strlen($s);
        $iPos = 0; $level = 0; $inQ = false; $esc = false; $start = 0; $rowsArr = [];
        for ($iPos = 0; $iPos < $len; $iPos++) {
            $ch = $s[$iPos];
            if ($ch === "\\" && !$esc) { $esc = true; continue; }
            if ($ch === "'" && !$esc) { $inQ = !$inQ; }
            if (!$inQ) {
                if ($ch === '(') { if ($level === 0) $start = $iPos; $level++; }
                if ($ch === ')') { $level--; if ($level === 0) { $rowsArr[] = substr($s, $start, $iPos - $start + 1); } }
            }
            $esc = false;
        }
        $rowCountThisStmt = count($rowsArr);

        if (!isset($backupCounts[$tbl])) $backupCounts[$tbl] = 0;
        $backupCounts[$tbl] += $rowCountThisStmt;

        // for small tables capture parsed rows (attempt)
        if ($backupCounts[$tbl] <= 500) {
            foreach ($rowsArr as $rowText) {
                // remove outer ( )
                $inner = trim(substr($rowText, 1, -1));
                // split fields on commas not inside quotes
                $fields = []; $field = ''; $inQ = false; $esc = false;
                $chars2 = str_split($inner);
                foreach ($chars2 as $ch2) {
                    if ($ch2 === "\\" && !$esc) { $esc = true; $field .= $ch2; continue; }
                    if ($ch2 === "'" && !$esc) { $inQ = !$inQ; $field .= $ch2; $esc = false; continue; }
                    if ($ch2 === ',' && !$inQ) { $fields[] = trim($field); $field = ''; } else { $field .= $ch2; }
                    $esc = false;
                }
                if (trim($field) !== '') $fields[] = trim($field);
                // normalize values
                $vals = array_map(function($v){
                    $v = trim($v);
                    if (strtoupper($v) === 'NULL') return null;
                    if (strlen($v) > 0 && $v[0] === "'") {
                        $inner = substr($v,1,-1);
                        $inner = str_replace(['\\\\','\\\''], ['\\','\''], $inner);
                        return $inner;
                    }
                    return $v;
                }, $fields);
                $backupSmallData[$tbl][] = $vals;
            }
        }
    }
}

// Now inspect current DB
$db = new Database(); $conn = $db->getConnection();
$currentTables = $conn->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$allTables = array_values(array_unique(array_merge($currentTables, array_keys($backupSchemas), array_keys($backupCounts))));

// helper: get primary key columns for table
function getPrimaryKeyCols($conn, $table) {
    try {
        $stmt = $conn->prepare("SHOW KEYS FROM `" . $table . "` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();
        $cols = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $cols[] = $r['Column_name']; }
        return $cols;
    } catch (Exception $e) { return []; }
}

$results = [];
$smallThreshold = 500; // only compute content diff for small tables
foreach ($allTables as $t) {
    $row = ['table' => $t, 'schema_match' => null, 'backup_count' => 0, 'current_count' => 0, 'content' => 'n/a'];
    // backup schema
    $backupSchema = $backupSchemas[$t] ?? null;
    try {
        $curCreate = $conn->query("SHOW CREATE TABLE `" . $t . "`")->fetch(PDO::FETCH_ASSOC);
        $curSchema = $curCreate['Create Table'] ?? $curCreate[1] ?? null;
    } catch (Exception $e) {
        $curSchema = null;
    }
    $row['schema_match'] = ($backupSchema !== null && $curSchema !== null && trim(preg_replace('/\s+/', ' ', $backupSchema)) === trim(preg_replace('/\s+/', ' ', $curSchema)));

    $row['backup_count'] = (int)($backupCounts[$t] ?? 0);
    try { $stmt = $conn->query("SELECT COUNT(*) FROM `" . $t . "`"); $row['current_count'] = (int)$stmt->fetchColumn(); } catch (Exception $e) { $row['current_count'] = null; }

    // content comparison only if both small and backup data available
    if ($row['backup_count'] <= $smallThreshold && $row['current_count'] !== null && $row['current_count'] <= $smallThreshold && isset($backupSmallData[$t])) {
        // compute hash for backup rows
        $backupHash = md5(json_encode($backupSmallData[$t]));
        // fetch current rows ordered by PK if available
        $pkCols = getPrimaryKeyCols($conn, $t);
        $order = '';
        if (!empty($pkCols)) { $order = ' ORDER BY `' . implode('`,`', $pkCols) . '`'; }
        $curRows = $conn->query("SELECT * FROM `" . $t . "`" . $order)->fetchAll(PDO::FETCH_ASSOC);
        // normalize to simple arrays
        $normalized = [];
        foreach ($curRows as $cr) { $normalized[] = array_values($cr); }
        $curHash = md5(json_encode($normalized));
        $row['content'] = ($backupHash === $curHash) ? 'match' : 'mismatch';
    } else if ($row['backup_count'] === 0 && $row['current_count'] === 0) {
        $row['content'] = 'match';
    } else if ($row['backup_count'] === 0) {
        $row['content'] = 'new';
    } else if ($row['current_count'] === 0) {
        $row['content'] = 'missing';
    } else {
        $row['content'] = 'skipped';
    }

    $results[] = $row;
}

// Render minimal UI
$pageTitle = 'Compare Backup - ' . APP_NAME;
include '../../../includes/header.php';
include '../../../includes/admin/sidebar.php';
?>
<div class="main-content">
    <div class="topbar"><div class="topbar-left"><h4>Compare Backup: <?php echo e($basename); ?></h4></div>
    <div class="topbar-right"><a href="../system/health.php" class="btn btn-light">← Back</a></div></div>
    <div class="content-area container-fluid p-4">
        <div class="card mb-3">
            <div class="card-body">
                <p class="text-muted">Comparing the selected backup file with current live database. Schema and row-count differences are shown. Content comparison is attempted for small tables (≤ <?php echo $smallThreshold; ?> rows).</p>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Schema</th>
                            <th>Row count (backup → current)</th>
                            <th>Content</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><?php echo e($r['table']); ?></td>
                                <td class="text-center"><?php if ($r['schema_match'] === null): ?><span class="text-muted">N/A</span><?php elseif ($r['schema_match']): ?><span class="text-success">✔</span><?php else: ?><span class="text-danger">✖</span><?php endif; ?></td>
                                <td><?php echo e($r['backup_count']); ?> → <?php echo e($r['current_count'] === null ? 'N/A' : $r['current_count']); ?> <?php if ($r['backup_count'] === $r['current_count']): ?><span class="text-success"> ✔</span><?php else: ?><span class="text-danger"> ✖</span><?php endif; ?></td>
                                <td>
                                    <?php if ($r['content'] === 'match'): ?><span class="text-success">Match</span>
                                    <?php elseif ($r['content'] === 'mismatch'): ?><span class="text-danger">Mismatch</span>
                                    <?php elseif ($r['content'] === 'skipped'): ?><span class="text-warning">Skipped (too large)</span>
                                    <?php elseif ($r['content'] === 'new'): ?><span class="text-info">New table in DB</span>
                                    <?php elseif ($r['content'] === 'missing'): ?><span class="text-warning">Missing in DB</span>
                                    <?php else: ?><span class="text-muted">N/A</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mt-3"><strong>Legend:</strong> <span class="text-success">✔/green</span> = same, <span class="text-danger">✖/red</span> = different, <span class="text-warning">⚠/orange</span> = skipped/partial</div>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>