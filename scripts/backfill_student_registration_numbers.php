<?php
require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();

$pattern = '/^\d{4}-STU-\d{3}$/';
$studentsStmt = $conn->query("
    SELECT id, student_id, entry_year, created_at
    FROM students
    ORDER BY
        CASE
            WHEN entry_year IS NOT NULL AND entry_year >= 1900 THEN entry_year
            ELSE YEAR(COALESCE(created_at, NOW()))
        END ASC,
        id ASC
");
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$usedCodes = [];
foreach ($students as $student) {
    $currentCode = trim((string)($student['student_id'] ?? ''));
    if ($currentCode !== '' && preg_match($pattern, $currentCode) === 1) {
        $usedCodes[$currentCode] = true;
    }
}

$allocateCode = static function (int $year) use (&$usedCodes): string {
    $prefix = buildStudentRegistrationPrefix($year);
    for ($seq = 1; $seq <= 999; $seq++) {
        $candidate = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        if (!isset($usedCodes[$candidate])) {
            $usedCodes[$candidate] = true;
            return $candidate;
        }
    }

    throw new RuntimeException('No available registration numbers remain for year ' . $year . '.');
};

$updated = [];
$conn->beginTransaction();

try {
    $updateStmt = $conn->prepare("UPDATE students SET student_id = :student_id WHERE id = :id");

    foreach ($students as $student) {
        $currentCode = trim((string)($student['student_id'] ?? ''));
        if ($currentCode !== '' && preg_match($pattern, $currentCode) === 1) {
            continue;
        }

        $year = (int)($student['entry_year'] ?? 0);
        if ($year < 1900) {
            $createdAt = (string)($student['created_at'] ?? '');
            $year = $createdAt !== '' ? (int)date('Y', strtotime($createdAt)) : 0;
        }
        if ($year < 1900) {
            $year = (int)date('Y');
        }

        $newCode = $allocateCode($year);
        $updateStmt->execute([
            'student_id' => $newCode,
            'id' => (int)$student['id'],
        ]);
        $updated[] = [
            'id' => (int)$student['id'],
            'old' => $currentCode,
            'new' => $newCode,
        ];
    }

    $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Updated ' . count($updated) . ' student registration number(s).' . PHP_EOL;
foreach ($updated as $row) {
    echo '#' . $row['id'] . ': ' . ($row['old'] !== '' ? $row['old'] : '[empty]') . ' -> ' . $row['new'] . PHP_EOL;
}
