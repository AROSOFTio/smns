<?php
/**
 * Replace courses catalog with a fixed theological curriculum.
 *
 * Usage:
 *   php scripts/replace_course_catalog.php           # dry run
 *   php scripts/replace_course_catalog.php --apply   # apply (rename-only: code/name)
 *   php scripts/replace_course_catalog.php --apply --full-replace   # allow insert/delete and metadata rewrite
 *   php scripts/replace_course_catalog.php --apply --program-id=1
 */

require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$fullReplace = in_array('--full-replace', $argv, true);
$renameOnly = !$fullReplace;
$programIdArg = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--program-id=') === 0) {
        $programIdArg = (int)substr($arg, strlen('--program-id='));
    }
}

$targetCourses = [
    // Year 1, Semester 1
    ['code' => 'BIB1101', 'name' => 'Introduction to the Bible', 'year' => 1, 'semester' => 1],
    ['code' => 'THE1102', 'name' => 'Foundations of Christian Theology', 'year' => 1, 'semester' => 1],
    ['code' => 'CHH1103', 'name' => 'Church History: Early Church Period', 'year' => 1, 'semester' => 1],
    ['code' => 'GRK1104', 'name' => 'Biblical Greek I', 'year' => 1, 'semester' => 1],
    ['code' => 'HEB1105', 'name' => 'Biblical Hebrew I', 'year' => 1, 'semester' => 1],
    ['code' => 'MIN1106', 'name' => 'Introduction to Christian Ministry', 'year' => 1, 'semester' => 1],
    ['code' => 'ETH1107', 'name' => 'Christian Ethics and Moral Reasoning', 'year' => 1, 'semester' => 1],
    ['code' => 'SPI1108', 'name' => 'Spiritual Formation and Discipleship', 'year' => 1, 'semester' => 1],

    // Year 1, Semester 2
    ['code' => 'BIB1201', 'name' => 'Old Testament Survey', 'year' => 1, 'semester' => 2],
    ['code' => 'THE1202', 'name' => 'Doctrine of God and Creation', 'year' => 1, 'semester' => 2],
    ['code' => 'GRK1203', 'name' => 'Biblical Greek II', 'year' => 1, 'semester' => 2],
    ['code' => 'HEB1204', 'name' => 'Biblical Hebrew II', 'year' => 1, 'semester' => 2],
    ['code' => 'CHH1205', 'name' => 'Church History: Medieval Period', 'year' => 1, 'semester' => 2],
    ['code' => 'HOM1206', 'name' => 'Introduction to Homiletics', 'year' => 1, 'semester' => 2],
    ['code' => 'PST1207', 'name' => 'Introduction to Pastoral Care', 'year' => 1, 'semester' => 2],

    // Year 2, Semester 1
    ['code' => 'BIB2101', 'name' => 'New Testament Survey', 'year' => 2, 'semester' => 1],
    ['code' => 'THE2102', 'name' => 'Christology and Soteriology', 'year' => 2, 'semester' => 1],
    ['code' => 'GRK2103', 'name' => 'Greek Exegesis I', 'year' => 2, 'semester' => 1],
    ['code' => 'HEB2104', 'name' => 'Hebrew Exegesis I', 'year' => 2, 'semester' => 1],
    ['code' => 'CHH2105', 'name' => 'Church History: Reformation Period', 'year' => 2, 'semester' => 1],
    ['code' => 'HOM2106', 'name' => 'Preaching and Biblical Exposition', 'year' => 2, 'semester' => 1],
    ['code' => 'MIN2107', 'name' => 'Evangelism and Church Outreach', 'year' => 2, 'semester' => 1],
    ['code' => 'SPI2108', 'name' => 'Prayer and Contemplative Spirituality', 'year' => 2, 'semester' => 1],

    // Year 2, Semester 2
    ['code' => 'BIB2201', 'name' => 'Pentateuch and Torah Studies', 'year' => 2, 'semester' => 2],
    ['code' => 'THE2202', 'name' => 'Pneumatology and Ecclesiology', 'year' => 2, 'semester' => 2],
    ['code' => 'GRK2203', 'name' => 'Greek Exegesis II', 'year' => 2, 'semester' => 2],
    ['code' => 'HEB2204', 'name' => 'Hebrew Exegesis II', 'year' => 2, 'semester' => 2],
    ['code' => 'ETH2205', 'name' => 'Social Ethics and Justice in Scripture', 'year' => 2, 'semester' => 2],
    ['code' => 'PST2206', 'name' => 'Pastoral Counseling Foundations', 'year' => 2, 'semester' => 2],
    ['code' => 'MIS2207', 'name' => 'Introduction to Christian Missions', 'year' => 2, 'semester' => 2],

    // Year 3, Semester 1
    ['code' => 'BIB3101', 'name' => 'Pauline Epistles and Theology', 'year' => 3, 'semester' => 1],
    ['code' => 'THE3102', 'name' => 'Eschatology and Biblical Prophecy', 'year' => 3, 'semester' => 1],
    ['code' => 'MIN3103', 'name' => 'Church Leadership and Administration', 'year' => 3, 'semester' => 1],
    ['code' => 'HOM3104', 'name' => 'Advanced Preaching and Communication', 'year' => 3, 'semester' => 1],
    ['code' => 'PST3105', 'name' => 'Marriage, Family and Pastoral Ministry', 'year' => 3, 'semester' => 1],
    ['code' => 'MIS3106', 'name' => 'Cross-Cultural Ministry and Missiology', 'year' => 3, 'semester' => 1],
    ['code' => 'ETH3107', 'name' => 'Bioethics and Contemporary Moral Issues', 'year' => 3, 'semester' => 1],
    ['code' => 'SPI3108', 'name' => 'Spiritual Direction and Formation', 'year' => 3, 'semester' => 1],

    // Year 3, Semester 2
    ['code' => 'BIB3201', 'name' => 'General Epistles and Revelation', 'year' => 3, 'semester' => 2],
    ['code' => 'THE3202', 'name' => 'Systematic Theology Integration', 'year' => 3, 'semester' => 2],
    ['code' => 'MIN3203', 'name' => 'Worship and Liturgical Studies', 'year' => 3, 'semester' => 2],
    ['code' => 'PST3204', 'name' => 'Clinical Pastoral Education', 'year' => 3, 'semester' => 2],
    ['code' => 'MIS3205', 'name' => 'World Religions and Christian Apologetics', 'year' => 3, 'semester' => 2],
    ['code' => 'CHH3206', 'name' => 'African Church History and Theology', 'year' => 3, 'semester' => 2],
    ['code' => 'ETH3207', 'name' => 'Christian Leadership and Integrity', 'year' => 3, 'semester' => 2],

    // Year 4, Semester 1
    ['code' => 'THE4101', 'name' => 'Advanced Systematic Theology', 'year' => 4, 'semester' => 1],
    ['code' => 'MIN4102', 'name' => 'Church Planting and Growth Strategy', 'year' => 4, 'semester' => 1],
    ['code' => 'PST4103', 'name' => 'Advanced Pastoral Counseling', 'year' => 4, 'semester' => 1],
    ['code' => 'MIS4104', 'name' => 'Global Mission Strategy and Leadership', 'year' => 4, 'semester' => 1],
    ['code' => 'RES4105', 'name' => 'Theological Research Methods', 'year' => 4, 'semester' => 1],
    ['code' => 'BIB4106', 'name' => 'Biblical Hermeneutics and Application', 'year' => 4, 'semester' => 1],

    // Year 4, Semester 2
    ['code' => 'THE4201', 'name' => 'Theology and Culture in the Modern World', 'year' => 4, 'semester' => 2],
    ['code' => 'MIN4202', 'name' => 'Ministry Practicum and Field Education', 'year' => 4, 'semester' => 2],
    ['code' => 'PST4203', 'name' => 'Grief, Trauma and Pastoral Response', 'year' => 4, 'semester' => 2],
    ['code' => 'HOM4204', 'name' => 'Capstone Preaching Project', 'year' => 4, 'semester' => 2],
    ['code' => 'MIS4205', 'name' => 'Urban Ministry and Community Development', 'year' => 4, 'semester' => 2],
    ['code' => 'ETH4206', 'name' => 'Church, State and Public Theology', 'year' => 4, 'semester' => 2],
    ['code' => 'CHH4207', 'name' => 'Contemporary Christianity and Global Trends', 'year' => 4, 'semester' => 2],
    ['code' => 'THE4208', 'name' => 'Senior Thesis and Theological Defense', 'year' => 4, 'semester' => 2],
];

$codes = array_column($targetCourses, 'code');
if (count($codes) !== count(array_unique($codes))) {
    echo "Duplicate course codes detected in target catalog.\n";
    exit(1);
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $programId = 0;
    if ($programIdArg !== null && $programIdArg > 0) {
        $chk = $conn->prepare("SELECT id FROM programs WHERE id = :id LIMIT 1");
        $chk->execute(['id' => $programIdArg]);
        $programId = (int)($chk->fetchColumn() ?: 0);
        if ($programId === 0) {
            echo "Program id {$programIdArg} not found.\n";
            exit(1);
        }
    } else {
        // Prefer BTH program if it exists.
        $preferred = $conn->query("SELECT id FROM programs WHERE UPPER(program_code) = 'BTH' LIMIT 1")->fetchColumn();
        if ($preferred) {
            $programId = (int)$preferred;
        } else {
            $fallback = $conn->query("SELECT program_id FROM courses GROUP BY program_id ORDER BY COUNT(*) DESC, program_id ASC LIMIT 1")->fetchColumn();
            $programId = (int)($fallback ?: 1);
        }
    }

    $programRow = $conn->prepare("SELECT id, program_code, program_name FROM programs WHERE id = :id");
    $programRow->execute(['id' => $programId]);
    $program = $programRow->fetch(PDO::FETCH_ASSOC);
    if (!$program) {
        echo "Unable to resolve target program.\n";
        exit(1);
    }

    $existing = $conn->query("SELECT * FROM courses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $byCode = [];
    foreach ($existing as $row) {
        $code = strtoupper(trim((string)$row['course_code']));
        if ($code !== '' && !isset($byCode[$code])) {
            $byCode[$code] = $row;
        }
    }

    $usedIds = [];
    $updates = [];
    $inserts = [];

    // Reserve rows that already match a target course code.
    foreach ($targetCourses as $target) {
        $code = strtoupper($target['code']);
        if (isset($byCode[$code])) {
            $row = $byCode[$code];
            $usedIds[(int)$row['id']] = true;
            $updates[] = [
                'id' => (int)$row['id'],
                'code' => $target['code'],
                'name' => $target['name'],
                'year' => (int)$target['year'],
                'semester' => (int)$target['semester'],
                'credit_hours' => (int)($row['credit_hours'] ?: 3),
            ];
        }
    }

    // Reusable rows are non-target-code rows.
    $targetCodeSet = array_flip(array_map('strtoupper', array_column($targetCourses, 'code')));
    $reusableIds = [];
    foreach ($existing as $row) {
        $id = (int)$row['id'];
        $code = strtoupper(trim((string)$row['course_code']));
        if (isset($usedIds[$id])) {
            continue;
        }
        if (!isset($targetCodeSet[$code])) {
            $reusableIds[] = $row;
        }
    }

    $reusableIndex = 0;
    foreach ($targetCourses as $target) {
        $code = strtoupper($target['code']);
        if (isset($byCode[$code])) {
            continue;
        }

        if ($reusableIndex < count($reusableIds)) {
            $row = $reusableIds[$reusableIndex++];
            $id = (int)$row['id'];
            $usedIds[$id] = true;
            $updates[] = [
                'id' => $id,
                'code' => $target['code'],
                'name' => $target['name'],
                'year' => (int)$target['year'],
                'semester' => (int)$target['semester'],
                'credit_hours' => (int)($row['credit_hours'] ?: 3),
            ];
        } else {
            $inserts[] = [
                'code' => $target['code'],
                'name' => $target['name'],
                'year' => (int)$target['year'],
                'semester' => (int)$target['semester'],
                'credit_hours' => 3,
            ];
        }
    }

    $deleteIds = [];
    foreach ($existing as $row) {
        $id = (int)$row['id'];
        if (!isset($usedIds[$id])) {
            $deleteIds[] = $id;
        }
    }

    $activeCourseAssignments = 0;
    $activeRegistrations = 0;
    $activeResults = 0;
    if (!empty($deleteIds)) {
        $ph = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmtA = $conn->prepare("SELECT COUNT(*) FROM course_assignments WHERE course_id IN ($ph)");
        $stmtA->execute($deleteIds);
        $activeCourseAssignments = (int)$stmtA->fetchColumn();

        $stmtR = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE course_id IN ($ph)");
        $stmtR->execute($deleteIds);
        $activeRegistrations = (int)$stmtR->fetchColumn();

        $stmtRes = $conn->prepare("SELECT COUNT(*) FROM results WHERE course_id IN ($ph)");
        $stmtRes->execute($deleteIds);
        $activeResults = (int)$stmtRes->fetchColumn();
    }

    echo "Mode: " . ($apply ? "APPLY" : "DRY RUN") . "\n";
    echo "Strategy: " . ($renameOnly ? "RENAME_ONLY (code/name only, keep existing structure)" : "FULL_REPLACE (code/name + structural changes)") . "\n";
    echo "Target program: {$program['id']} ({$program['program_code']} - {$program['program_name']})\n";
    echo "Target courses: " . count($targetCourses) . "\n";
    echo "Existing courses: " . count($existing) . "\n";
    echo "Rows to update: " . count($updates) . "\n";
    echo "Rows to insert: " . ($renameOnly ? 0 : count($inserts)) . "\n";
    echo "Rows to delete: " . ($renameOnly ? 0 : count($deleteIds)) . "\n";
    if (!$renameOnly) {
        echo "Dependent rows tied to deletions (will cascade): assignments={$activeCourseAssignments}, registrations={$activeRegistrations}, results={$activeResults}\n";
    }
    if ($renameOnly && (!empty($inserts) || !empty($deleteIds))) {
        echo "Note: rename-only mode will skip inserts/deletes. Re-run with --full-replace if structural replacement is required.\n";
    }

    if (!$apply) {
        echo "Dry run completed. Re-run with --apply to execute.\n";
        exit(0);
    }

    $conn->beginTransaction();

    if ($renameOnly) {
        $updateStmt = $conn->prepare("
            UPDATE courses
            SET course_code = :code,
                course_name = :name,
                description = :description,
                updated_at = NOW()
            WHERE id = :id
        ");
    } else {
        $updateStmt = $conn->prepare("
            UPDATE courses
            SET course_code = :code,
                course_name = :name,
                credit_hours = :credit_hours,
                program_id = :program_id,
                level_year = :level_year,
                semester_offered = :semester_offered,
                description = :description,
                status = 'active',
                updated_at = NOW()
            WHERE id = :id
        ");
    }

    foreach ($updates as $u) {
        $params = [
            'id' => $u['id'],
            'code' => $u['code'],
            'name' => $u['name'],
            'description' => $u['name'],
        ];
        if (!$renameOnly) {
            $params['credit_hours'] = $u['credit_hours'];
            $params['program_id'] = $programId;
            $params['level_year'] = $u['year'];
            $params['semester_offered'] = $u['semester'];
        }
        $updateStmt->execute($params);
    }

    if (!$renameOnly) {
        $insertStmt = $conn->prepare("
            INSERT INTO courses
                (course_code, course_name, credit_hours, program_id, level_year, semester_offered, prerequisites, description, status, created_at, updated_at)
            VALUES
                (:code, :name, :credit_hours, :program_id, :level_year, :semester_offered, NULL, :description, 'active', NOW(), NOW())
        ");

        foreach ($inserts as $i) {
            $insertStmt->execute([
                'code' => $i['code'],
                'name' => $i['name'],
                'credit_hours' => $i['credit_hours'],
                'program_id' => $programId,
                'level_year' => $i['year'],
                'semester_offered' => $i['semester'],
                'description' => $i['name'],
            ]);
        }

        if (!empty($deleteIds)) {
            $ph = implode(',', array_fill(0, count($deleteIds), '?'));
            $deleteStmt = $conn->prepare("DELETE FROM courses WHERE id IN ($ph)");
            $deleteStmt->execute($deleteIds);
        }
    }

    $finalCount = (int)$conn->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    $conn->commit();

    echo "Catalog replacement completed.\n";
    echo "Final courses count: {$finalCount}\n";
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
