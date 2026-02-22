<?php
/**
 * One-time lecturer identity replacement.
 *
 * Replaces lecturer names using a fixed 17-person master list while preserving
 * lecturer IDs, linked assignments, and other non-name fields.
 *
 * Usage:
 *   php scripts/replace_lecturers_from_master_list.php            # dry run
 *   php scripts/replace_lecturers_from_master_list.php --apply    # apply changes
 *   php scripts/replace_lecturers_from_master_list.php --apply --update-emails
 */

require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$updateEmails = in_array('--update-emails', $argv, true);

$masterNames = [
    'Dr. Jonathan E. Whitfield',
    'Dr. Miriam A. Osei-Bonsu',
    'Dr. Samuel R. Adeyemi',
    'Rev. Dr. Grace N. Pemberton',
    'Dr. Emmanuel K. Asante',
    'Dr. Priscilla J. Nakamura',
    'Dr. Thomas B. Owusu',
    'Dr. Rachel E. Hoffmann',
    'Dr. Augustine F. Mensah',
    'Dr. Lydia M. Christodoulou',
    'Rev. Dr. Daniel O. Amponsah',
    'Dr. Esther A. Quansah',
    'Rev. Dr. Joshua P. Nkrumah',
    'Dr. Abigail T. Acheampong',
    'Dr. Nathaniel C. Boateng',
    'Dr. Sophia R. Andersen',
    'Dr. Kofi J. Mensah-Bonsu',
];

function parseLecturerIdentity(string $raw): array
{
    $raw = trim(preg_replace('/^\d+\s*/', '', $raw));
    $title = '';
    if (preg_match('/^(Rev\.\s*Dr\.|Dr\.)\s+/i', $raw, $m)) {
        $title = preg_replace('/\s+/', ' ', trim($m[1]));
        $raw = trim(substr($raw, strlen($m[0])));
    }

    $parts = preg_split('/\s+/', $raw) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static function ($p) {
        return $p !== '';
    }));

    if (count($parts) < 2) {
        return [
            'title' => $title,
            'first_name' => $raw !== '' ? $raw : 'Lecturer',
            'middle_name' => null,
            'last_name' => 'Updated',
        ];
    }

    $lastName = array_pop($parts);
    $firstName = array_shift($parts);
    $middleName = !empty($parts) ? implode(' ', $parts) : null;

    return [
        'title' => $title,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
    ];
}

function buildEmail(array $identity, int $seq, array &$used, array $blocked): string
{
    $firstRaw = strtolower((string)($identity['first_name'] ?? 'lecturer'));
    $lastRaw = strtolower((string)($identity['last_name'] ?? 'user'));
    $first = preg_replace('/[^a-z0-9]+/', '.', $firstRaw);
    $last = preg_replace('/[^a-z0-9]+/', '.', $lastRaw);
    $first = trim($first, '.');
    $last = trim($last, '.');
    $base = trim($first . '.' . $last, '.');
    if ($base === '') {
        $base = 'lecturer' . $seq;
    }

    $candidate = $base;
    $n = 2;
    while (isset($used[$candidate]) || isset($blocked[$candidate . '@seminary.edu'])) {
        $candidate = $base . $n;
        $n++;
    }
    $used[$candidate] = true;
    return $candidate . '@seminary.edu';
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $lecturers = $conn->query("
        SELECT id, user_id, lecturer_id, title, first_name, middle_name, last_name, email
        FROM lecturers
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $existingCount = count($lecturers);
    $targetCount = count($masterNames);

    echo "Mode: " . ($apply ? 'APPLY' : 'DRY RUN') . "\n";
    echo "Email sync: " . ($updateEmails ? 'YES' : 'NO') . "\n";
    echo "Existing lecturers: {$existingCount}\n";
    echo "Target lecturers: {$targetCount}\n";

    if ($existingCount < $targetCount) {
        echo "Error: only {$existingCount} lecturers found, but {$targetCount} replacements were requested.\n";
        echo "Create additional lecturer accounts first, then rerun.\n";
        exit(1);
    }

    $mappedRows = [];
    $usedEmails = [];
    $mappedUserIds = array_map(static function ($r) {
        return (int)($r['user_id'] ?? 0);
    }, array_slice($lecturers, 0, $targetCount));
    $mappedUserIds = array_values(array_filter($mappedUserIds, static function ($id) {
        return $id > 0;
    }));

    $blockedEmails = [];
    if ($updateEmails) {
        if (!empty($mappedUserIds)) {
            $uph = implode(',', array_fill(0, count($mappedUserIds), '?'));
            $blockStmt = $conn->prepare("SELECT email FROM users WHERE id NOT IN ($uph)");
            $blockStmt->execute($mappedUserIds);
        } else {
            $blockStmt = $conn->query("SELECT email FROM users");
        }
        $blockRows = $blockStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($blockRows as $em) {
            $em = strtolower(trim((string)$em));
            if ($em !== '') {
                $blockedEmails[$em] = true;
            }
        }
    }

    foreach ($masterNames as $i => $name) {
        $row = $lecturers[$i];
        $identity = parseLecturerIdentity($name);
        $newEmail = $row['email'];
        if ($updateEmails) {
            $newEmail = buildEmail($identity, $i + 1, $usedEmails, $blockedEmails);
        }

        $mappedRows[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'lecturer_id' => $row['lecturer_id'],
            'old_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'new_name' => trim(($identity['first_name'] ?? '') . ' ' . ($identity['middle_name'] ?? '') . ' ' . ($identity['last_name'] ?? '')),
            'title' => $identity['title'] !== '' ? $identity['title'] : null,
            'first_name' => $identity['first_name'],
            'middle_name' => $identity['middle_name'],
            'last_name' => $identity['last_name'],
            'new_email' => $newEmail,
        ];
    }

    $extras = array_slice($lecturers, $targetCount);
    $extraIds = array_map(static function ($r) { return (int)$r['id']; }, $extras);

    echo "Will update: " . count($mappedRows) . "\n";
    echo "Will delete extras: " . count($extras) . "\n";
    if (!empty($mappedRows)) {
        echo "Preview (first 5):\n";
        foreach (array_slice($mappedRows, 0, 5) as $r) {
            echo "  {$r['lecturer_id']}: {$r['old_name']} -> {$r['new_name']}\n";
        }
    }

    if (!$apply) {
        echo "\nDry run complete. Re-run with --apply to save changes.\n";
        exit(0);
    }

    $conn->beginTransaction();

    $upLecturer = $conn->prepare("
        UPDATE lecturers
        SET title = :title,
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            email = :email,
            updated_at = NOW()
        WHERE id = :id
    ");

    $upUserEmail = $conn->prepare("
        UPDATE users
        SET email = :email,
            updated_at = NOW()
        WHERE id = :user_id
          AND role = 'lecturer'
    ");

    foreach ($mappedRows as $row) {
        $upLecturer->execute([
            'title' => $row['title'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'last_name' => $row['last_name'],
            'email' => $row['new_email'],
            'id' => $row['id'],
        ]);

        if ($updateEmails) {
            $upUserEmail->execute([
                'email' => $row['new_email'],
                'user_id' => $row['user_id'],
            ]);
        }
    }

    if (!empty($extraIds)) {
        $ph = implode(',', array_fill(0, count($extraIds), '?'));

        // Protect historical marks integrity: do not delete lecturers who entered results.
        $resStmt = $conn->prepare("SELECT entered_by, COUNT(*) AS cnt FROM results WHERE entered_by IN ($ph) GROUP BY entered_by");
        $resStmt->execute($extraIds);
        $blocked = $resStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($blocked)) {
            $blockedIds = array_map(static function ($b) { return (int)$b['entered_by']; }, $blocked);
            throw new RuntimeException(
                'Cannot delete extra lecturers with historical results: ' . implode(', ', $blockedIds)
            );
        }

        $userRows = [];
        $uStmt = $conn->prepare("SELECT user_id FROM lecturers WHERE id IN ($ph) AND user_id IS NOT NULL");
        $uStmt->execute($extraIds);
        $userRows = $uStmt->fetchAll(PDO::FETCH_COLUMN);

        $delLecturers = $conn->prepare("DELETE FROM lecturers WHERE id IN ($ph)");
        $delLecturers->execute($extraIds);

        if (!empty($userRows)) {
            $uph = implode(',', array_fill(0, count($userRows), '?'));
            $delUsers = $conn->prepare("DELETE FROM users WHERE id IN ($uph) AND role = 'lecturer'");
            $delUsers->execute(array_map('intval', $userRows));
        }
    }

    if ($conn->inTransaction()) {
        $conn->commit();
    }

    echo "\nLecturer replacement complete.\n";
    echo "Updated lecturers: " . count($mappedRows) . "\n";
    echo "Deleted extras: " . count($extras) . "\n";
    echo "Email sync applied: " . ($updateEmails ? 'YES' : 'NO') . "\n";
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
