<?php
require_once '../../config.php';

$db = new Database();
$conn = $db->getConnection();
$issuanceService = new TranscriptIssuanceService($conn);
$issuanceService->ensureSchema();

$providedToken = strtolower(trim((string)($_GET['token'] ?? '')));
$providedStudentId = trim((string)($_GET['student_id'] ?? ''));
$providedCode = strtoupper(str_replace([' ', '-'], '', trim((string)($_GET['code'] ?? ''))));
$providedHash = strtolower(trim((string)($_GET['hash'] ?? '')));

$formSubmitted = !empty($_GET);

$student = null;
$status = 'pending';
$message = 'Enter a verification token or use Student ID plus verification code/hash to validate an official transcript.';
$generatedCode = '';
$generatedHash = '';
$issuedTranscript = null;
$verificationMode = 'pending';

if ($providedToken !== '') {
    $verificationMode = 'issuance';
    $issuedTranscript = $issuanceService->getIssuanceByToken($providedToken);
    if (!$issuedTranscript) {
        $status = 'invalid';
        $message = 'Verification failed. The supplied token does not match an issued transcript.';
    } else {
        $student = [
            'student_id' => (string)($issuedTranscript['student_identifier'] ?? ''),
            'first_name' => (string)($issuedTranscript['first_name'] ?? ''),
            'last_name' => (string)($issuedTranscript['last_name'] ?? ''),
            'program_code' => (string)($issuedTranscript['program_code'] ?? ''),
            'program_name' => (string)($issuedTranscript['program_name'] ?? ''),
        ];
        if (($issuedTranscript['status'] ?? '') !== 'active') {
            $status = 'invalid';
            $message = 'Verification failed. This issued transcript is no longer active.';
        } elseif (empty($issuedTranscript['ledger_valid'])) {
            $status = 'invalid';
            $message = 'Verification failed. The issuance ledger chain is not valid.';
        } else {
            $status = 'valid';
            $message = 'Transcript verification passed. This issued transcript matches an immutable issuance record.';
            $generatedCode = (string)($issuedTranscript['verification_code'] ?? '');
            $generatedHash = (string)($issuedTranscript['transcript_hash'] ?? '');
        }
    }
} elseif ($providedStudentId !== '') {
    $verificationMode = 'legacy';
    try {
        $studentStmt = $conn->prepare("
            SELECT s.id, s.student_id, s.first_name, s.last_name, s.graduation_award_title, s.graduation_date, p.program_name, p.program_code
            FROM students s
            LEFT JOIN programs p ON p.id = s.program_id
            WHERE s.student_id = :student_id OR s.id = :student_row_id
            LIMIT 1
        ");
        $studentStmt->execute([
            'student_id' => $providedStudentId,
            'student_row_id' => ctype_digit($providedStudentId) ? (int)$providedStudentId : -1
        ]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $student = null;
    }

    if (!$student) {
        $status = 'invalid';
        $message = 'Student not found for the supplied identifier.';
    } else {
        $studentRowId = (int)$student['id'];
        $publishedCourses = 0;
        $publishedCredits = 0.0;
        $publishedPoints = 0.0;
        $lastResultUpdate = '';
        try {
            $verifyStmt = $conn->prepare("
                SELECT
                    COUNT(*) AS published_courses,
                    COALESCE(SUM(COALESCE(c.credit_hours,0)), 0) AS published_credits,
                    COALESCE(SUM(COALESCE(r.grade_points,0) * COALESCE(c.credit_hours,0)), 0) AS published_points,
                    MAX(COALESCE(r.updated_at, r.created_at)) AS last_result_update
                FROM results r
                INNER JOIN courses c ON c.id = r.course_id
                WHERE r.student_id = :student_id
                  AND r.status = 'published'
            ");
            $verifyStmt->execute(['student_id' => $studentRowId]);
            $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $publishedCourses = (int)($verifyRow['published_courses'] ?? 0);
            $publishedCredits = (float)($verifyRow['published_credits'] ?? 0);
            $publishedPoints = (float)($verifyRow['published_points'] ?? 0);
            $lastResultUpdate = (string)($verifyRow['last_result_update'] ?? '');
        } catch (Exception $e) {
        }

        $finalCgpa = $publishedCredits > 0 ? round($publishedPoints / $publishedCredits, 2) : null;
        $verificationSeed = implode('|', [
            (string)getSetting('institution_name', INSTITUTION_NAME),
            (string)($student['student_id'] ?? ('student_' . $studentRowId)),
            number_format((float)$publishedCourses, 0, '.', ''),
            number_format((float)$publishedCredits, 2, '.', ''),
            number_format((float)$publishedPoints, 2, '.', ''),
            $finalCgpa !== null ? number_format((float)$finalCgpa, 2, '.', '') : 'NA',
            (string)($student['graduation_award_title'] ?? ''),
            (string)($student['graduation_date'] ?? ''),
            $lastResultUpdate
        ]);

        $generatedHash = hash('sha256', $verificationSeed);
        $generatedCode = strtoupper(substr($generatedHash, 0, 16));

        $isValid = false;
        if ($providedHash !== '') {
            $isValid = hash_equals($generatedHash, strtolower($providedHash));
        } elseif ($providedCode !== '') {
            $isValid = hash_equals($generatedCode, $providedCode);
        }

        if ($isValid) {
            $status = 'valid';
            $message = 'Transcript verification passed. This record hash matches system data.';
        } else {
            $status = 'invalid';
            $message = 'Verification failed. The supplied code/hash does not match current transcript data.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcript Verification - <?php echo e((string)getSetting('institution_name', INSTITUTION_NAME)); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fold-global.css?v=<?php echo urlencode((string)APP_VERSION); ?>">
</head>
<body style="background:#f3f4f6;">
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h4 class="mb-3">Transcript Verification</h4>
            <p class="text-muted mb-4">Preferred: verify using transcript token/QR link. Legacy verification by student ID plus code/hash is still supported.</p>

            <form method="get" class="mb-4">
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label>Verification Token</label>
                        <input type="text" name="token" value="<?php echo e($providedToken); ?>" class="form-control" placeholder="Paste the token from the issued transcript QR/link">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Student ID</label>
                        <input type="text" name="student_id" value="<?php echo e($providedStudentId); ?>" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Verification Code</label>
                        <input type="text" name="code" value="<?php echo e((string)($_GET['code'] ?? '')); ?>" class="form-control" placeholder="e.g. A1B2-C3D4-E5F6-G7H8">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Verification Hash</label>
                        <input type="text" name="hash" value="<?php echo e((string)($_GET['hash'] ?? '')); ?>" class="form-control" placeholder="sha256 hash (optional)">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Verify</button>
            </form>

            <?php if ($formSubmitted): ?>
            <div class="alert <?php echo $status === 'valid' ? 'alert-success' : ($status === 'invalid' ? 'alert-danger' : 'alert-info'); ?>">
                <?php echo e($message); ?>
            </div>

            <?php if ($student): ?>
                <div class="border rounded p-3 bg-light">
                    <div><strong>Name:</strong> <?php echo e(trim((string)$student['first_name'] . ' ' . (string)$student['last_name'])); ?></div>
                    <div><strong>Student ID:</strong> <?php echo e(resolveDisplayedStudentRegistrationNumberFromRow($conn, $student)); ?></div>
                    <div><strong>Program:</strong> <?php echo e(trim((string)($student['program_code'] ?? '') . ' - ' . (string)($student['program_name'] ?? ''))); ?></div>
                    <?php if ($status === 'valid'): ?>
                        <div><strong>Verified Code:</strong> <?php echo e(implode('-', str_split($generatedCode, 4))); ?></div>
                        <div><strong>Verified Hash:</strong> <code><?php echo e($generatedHash); ?></code></div>
                        <?php if ($verificationMode === 'issuance' && $issuedTranscript): ?>
                            <div><strong>Issued At:</strong> <?php echo e(Helper::formatDateTime((string)($issuedTranscript['issued_at'] ?? ''), 'M d, Y g:i A')); ?></div>
                            <div><strong>Export Format:</strong> <?php echo e(strtoupper((string)($issuedTranscript['export_format'] ?? ''))); ?></div>
                            <div><strong>Ledger:</strong> <?php echo !empty($issuedTranscript['ledger_valid']) ? 'VALID' : 'INVALID'; ?></div>
                            <div><strong>Verification URL:</strong> <a href="<?php echo e((string)($issuedTranscript['verification_url'] ?? '')); ?>"><?php echo e((string)($issuedTranscript['verification_url'] ?? '')); ?></a></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($status === 'valid' && $student): ?>
                <div id="transcriptDisplay" class="mt-4">
                    <?php
                    $studentId = (int)$student['id'];
                    include_once '../student/transcript_template.php';
                    ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?php echo BASE_URL; ?>/assets/js/fold-global.js?v=<?php echo urlencode((string)APP_VERSION); ?>"></script>
</body>
</html>
