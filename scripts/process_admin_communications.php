<?php
/**
 * CLI worker: process queued admin communications.
 * Usage:
 *   php scripts/process_admin_communications.php --max-jobs=5
 *   php scripts/process_admin_communications.php --job-id=12 --max-jobs=1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/../config.php';

$jobId = 0;
$maxJobs = 5;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        $value = trim((string)$arg);
        if (strpos($value, '--job-id=') === 0) {
            $jobId = (int)substr($value, 9);
            continue;
        }
        if (strpos($value, '--max-jobs=') === 0) {
            $maxJobs = (int)substr($value, 11);
            continue;
        }
    }
}

if ($maxJobs <= 0) {
    $maxJobs = 1;
}
if ($maxJobs > 100) {
    $maxJobs = 100;
}

try {
    $service = new AdminCommunicationService();
    $result = $service->processQueuedJobs($maxJobs, $jobId);
    echo '[' . date('Y-m-d H:i:s') . '] processed=' . (int)($result['processed'] ?? 0)
        . ', completed=' . (int)($result['completed'] ?? 0)
        . ', failed=' . (int)($result['failed'] ?? 0) . PHP_EOL;
    exit;
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] worker error: ' . (string)$e->getMessage() . PHP_EOL;
    exit(1);
}
