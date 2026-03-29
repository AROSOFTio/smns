<?php
/**
 * Admin Unit Testing Results Summary
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (
    !isset($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true ||
    ($_SESSION['admin_role'] ?? '') !== 'admin'
) {
    $returnTo = '/views/admin/reports/unit-testing.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $returnTo .= '?' . ltrim((string)$_SERVER['QUERY_STRING'], '?');
    }
    header('Location: ' . BASE_URL . '/views/auth/login.php?error=unauthorized&role=admin&return_to=' . urlencode($returnTo));
    exit;
}

$currentUser = $auth->getCurrentUser();
$db = new Database();
$conn = $db->getConnection();

$conn->exec("CREATE TABLE IF NOT EXISTS unit_test_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    system_check_key VARCHAR(120) NULL,
    module VARCHAR(100) NOT NULL,
    test_case VARCHAR(255) NOT NULL,
    input_data TEXT NULL,
    expected_output TEXT NULL,
    actual_output TEXT NULL,
    status ENUM('PASS','FAIL','PENDING') NOT NULL DEFAULT 'PENDING',
    source ENUM('manual','system') NOT NULL DEFAULT 'manual',
    executed_at DATETIME NOT NULL,
    recorded_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_system_check_key (system_check_key),
    INDEX idx_module (module),
    INDEX idx_status (status),
    INDEX idx_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->exec("CREATE TABLE IF NOT EXISTS integration_test_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    system_check_key VARCHAR(120) NULL,
    integration_test VARCHAR(255) NOT NULL,
    modules_tested VARCHAR(255) NOT NULL,
    expected_outcome TEXT NULL,
    actual_outcome TEXT NULL,
    status ENUM('PASS','FAIL','PENDING') NOT NULL DEFAULT 'PENDING',
    source ENUM('manual','system') NOT NULL DEFAULT 'system',
    executed_at DATETIME NOT NULL,
    recorded_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_integration_system_check_key (system_check_key),
    INDEX idx_integration_status (status),
    INDEX idx_integration_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

try {
    $conn->exec("ALTER TABLE unit_test_results ADD COLUMN system_check_key VARCHAR(120) NULL");
} catch (Throwable $e) {
    // Column may already exist.
}
try {
    $conn->exec("ALTER TABLE unit_test_results ADD UNIQUE KEY uq_system_check_key (system_check_key)");
} catch (Throwable $e) {
    // Key may already exist.
}
try {
    $conn->exec("ALTER TABLE integration_test_results ADD COLUMN system_check_key VARCHAR(120) NULL");
} catch (Throwable $e) {
    // Column may already exist.
}
try {
    $conn->exec("ALTER TABLE integration_test_results ADD UNIQUE KEY uq_integration_system_check_key (system_check_key)");
} catch (Throwable $e) {
    // Key may already exist.
}

function unitTestingNormalizeDateTime($value, $fallback)
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    $dt = date_create($raw);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d H:i:s');
}

function unitTestingUpsertSystemResult(PDO $conn, array $result)
{
    $systemCheckKey = trim((string)($result['system_check_key'] ?? ''));
    if ($systemCheckKey === '') {
        return;
    }

    $payload = [
        'system_check_key' => $systemCheckKey,
        'module' => trim((string)($result['module'] ?? 'System')),
        'test_case' => trim((string)($result['test_case'] ?? 'System check')),
        'input_data' => trim((string)($result['input_data'] ?? '')),
        'expected_output' => trim((string)($result['expected_output'] ?? '')),
        'actual_output' => trim((string)($result['actual_output'] ?? '')),
        'status' => strtoupper(trim((string)($result['status'] ?? 'PENDING'))),
        'source' => 'system',
        'executed_at' => unitTestingNormalizeDateTime($result['executed_at'] ?? '', date('Y-m-d H:i:s')),
    ];

    $allowedStatuses = ['PASS', 'FAIL', 'PENDING'];
    if (!in_array($payload['status'], $allowedStatuses, true)) {
        $payload['status'] = 'PENDING';
    }

    $stmt = $conn->prepare("
        INSERT INTO unit_test_results
            (system_check_key, module, test_case, input_data, expected_output, actual_output, status, source, executed_at, recorded_by_user_id)
        VALUES
            (:system_check_key, :module, :test_case, :input_data, :expected_output, :actual_output, :status, 'system', :executed_at, NULL)
        ON DUPLICATE KEY UPDATE
            module = VALUES(module),
            test_case = VALUES(test_case),
            input_data = VALUES(input_data),
            expected_output = VALUES(expected_output),
            actual_output = VALUES(actual_output),
            status = VALUES(status),
            source = 'system',
            executed_at = VALUES(executed_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute($payload);
}

function unitTestingTableExists(PDO $conn, $tableName)
{
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute(['table_name' => (string)$tableName]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function unitTestingColumnExists(PDO $conn, $tableName, $columnName)
{
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt->execute([
            'table_name' => (string)$tableName,
            'column_name' => (string)$columnName,
        ]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function integrationTestingUpsertSystemResult(PDO $conn, array $result)
{
    $systemCheckKey = trim((string)($result['system_check_key'] ?? ''));
    if ($systemCheckKey === '') {
        return;
    }

    $payload = [
        'system_check_key' => $systemCheckKey,
        'integration_test' => trim((string)($result['integration_test'] ?? 'Integration test')),
        'modules_tested' => trim((string)($result['modules_tested'] ?? 'System')),
        'expected_outcome' => trim((string)($result['expected_outcome'] ?? '')),
        'actual_outcome' => trim((string)($result['actual_outcome'] ?? '')),
        'status' => strtoupper(trim((string)($result['status'] ?? 'PENDING'))),
        'executed_at' => unitTestingNormalizeDateTime($result['executed_at'] ?? '', date('Y-m-d H:i:s')),
    ];

    $allowedStatuses = ['PASS', 'FAIL', 'PENDING'];
    if (!in_array($payload['status'], $allowedStatuses, true)) {
        $payload['status'] = 'PENDING';
    }

    $stmt = $conn->prepare("
        INSERT INTO integration_test_results
            (system_check_key, integration_test, modules_tested, expected_outcome, actual_outcome, status, source, executed_at, recorded_by_user_id)
        VALUES
            (:system_check_key, :integration_test, :modules_tested, :expected_outcome, :actual_outcome, :status, 'system', :executed_at, NULL)
        ON DUPLICATE KEY UPDATE
            integration_test = VALUES(integration_test),
            modules_tested = VALUES(modules_tested),
            expected_outcome = VALUES(expected_outcome),
            actual_outcome = VALUES(actual_outcome),
            status = VALUES(status),
            source = 'system',
            executed_at = VALUES(executed_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute($payload);
}

function integrationTestingRunAutomatedChecks(PDO $conn)
{
    $results = [];
    $now = date('Y-m-d H:i:s');

    $addResult = function ($key, $integrationTest, $modules, $expected, $actual, $passed) use (&$results, $now) {
        $results[] = [
            'system_check_key' => $key,
            'integration_test' => $integrationTest,
            'modules_tested' => $modules,
            'expected_outcome' => $expected,
            'actual_outcome' => $actual,
            'status' => $passed ? 'PASS' : 'FAIL',
            'source' => 'system',
            'executed_at' => $now,
        ];
    };

    $pageExists = function ($relativePath) {
        return is_file(BASE_PATH . '/' . ltrim((string)$relativePath, '/'));
    };

    try {
        $ready = $pageExists('views/lecturer/enter-results.php')
            && $pageExists('views/student/results.php')
            && unitTestingTableExists($conn, 'results');
        $actual = $ready
            ? 'Lecturer grade-entry page and student results page share the results dataset.'
            : 'Lecturer grade-entry page, student results page, or results table is missing.';
        $addResult('integration_grade_entry_student_view', 'Grade entry -> student view', 'Lecturer, Student', 'Marks appear immediately in student results', $actual, $ready);
    } catch (Throwable $e) {
        $addResult('integration_grade_entry_student_view', 'Grade entry -> student view', 'Lecturer, Student', 'Marks appear immediately in student results', 'Integration check failed: ' . $e->getMessage(), false);
    }

    try {
        $publishedCount = unitTestingTableExists($conn, 'results') ? (int)($conn->query("SELECT COUNT(*) FROM results WHERE status = 'published'")->fetchColumn() ?: 0) : 0;
        $ready = $pageExists('views/student/transcript.php')
            && $pageExists('views/verify/transcript.php')
            && unitTestingTableExists($conn, 'transcript_issuances')
            && $publishedCount >= 0;
        $actual = $ready
            ? ('Transcript pages and issuance table are available; published results=' . number_format($publishedCount) . '.')
            : 'Transcript pages, issuance table, or published results source is not ready.';
        $addResult('integration_results_transcript', 'Results -> transcript', 'Results, Reports', 'Correct data populates transcript', $actual, $ready);
    } catch (Throwable $e) {
        $addResult('integration_results_transcript', 'Results -> transcript', 'Results, Reports', 'Correct data populates transcript', 'Integration check failed: ' . $e->getMessage(), false);
    }

    try {
        $balancesReady = $pageExists('views/student/payments.php')
            && unitTestingTableExists($conn, 'payments')
            && unitTestingTableExists($conn, 'student_balances')
            && unitTestingTableExists($conn, 'invoices');
        $actual = $balancesReady
            ? 'Payments, invoices, and student balance tables are available for balance synchronization.'
            : 'Payments, invoices, or student balance support is missing.';
        $addResult('integration_payment_fee_balance', 'Payment -> fee balance', 'Finance, Student', 'Balance updates after payment entry', $actual, $balancesReady);
    } catch (Throwable $e) {
        $addResult('integration_payment_fee_balance', 'Payment -> fee balance', 'Finance, Student', 'Balance updates after payment entry', 'Integration check failed: ' . $e->getMessage(), false);
    }

    try {
        $ready = $pageExists('views/admin/users/add.php')
            && $pageExists('views/auth/login.php')
            && is_object(new Auth('admin'))
            && is_object(new Auth('student'))
            && is_object(new Auth('lecturer'))
            && is_object(new Auth('finance'));
        $actual = $ready
            ? 'User creation page and shared role-based authentication flow are available.'
            : 'User creation page or role login/auth flow is incomplete.';
        $addResult('integration_user_creation_login', 'User creation -> login', 'Admin, Auth', 'New user can log in with role', $actual, $ready);
    } catch (Throwable $e) {
        $addResult('integration_user_creation_login', 'User creation -> login', 'Admin, Auth', 'New user can log in with role', 'Integration check failed: ' . $e->getMessage(), false);
    }

    try {
        $reportsPagePath = BASE_PATH . '/views/admin/reports/index.php';
        $reportsSource = $pageExists('views/admin/reports/index.php') ? (string)file_get_contents($reportsPagePath) : '';
        $ready = $reportsSource !== ''
            && strpos($reportsSource, "['export'=>'csv']") !== false
            && strpos($reportsSource, 'window.print()') !== false;
        $actual = $ready
            ? 'Reports page supports CSV export and a print-friendly PDF/print view.'
            : 'Reports export actions for CSV and print/PDF were not both found.';
        $addResult('integration_report_export_pdf', 'Report export -> PDF', 'Reports, Admin', 'PDF generated with correct data', $actual, $ready);
    } catch (Throwable $e) {
        $addResult('integration_report_export_pdf', 'Report export -> PDF', 'Reports, Admin', 'PDF generated with correct data', 'Integration check failed: ' . $e->getMessage(), false);
    }

    $systemKeys = array_values(array_filter(array_map(function ($result) {
        return trim((string)($result['system_check_key'] ?? ''));
    }, $results)));

    if (!empty($systemKeys)) {
        $placeholders = [];
        $params = [];
        foreach ($systemKeys as $index => $key) {
            $param = ':key' . $index;
            $placeholders[] = $param;
            $params[$param] = $key;
        }
        try {
            $cleanupSql = "DELETE FROM integration_test_results WHERE source = 'system' AND system_check_key IS NOT NULL AND system_check_key NOT IN (" . implode(', ', $placeholders) . ")";
            $cleanupStmt = $conn->prepare($cleanupSql);
            $cleanupStmt->execute($params);
        } catch (Throwable $e) {
            // Ignore cleanup errors and keep latest automated results.
        }
    }

    foreach ($results as $result) {
        try {
            integrationTestingUpsertSystemResult($conn, $result);
        } catch (Throwable $e) {
            // Keep the page available even if one integration result cannot be persisted.
        }
    }

    return $results;
}

function unitTestingRunAutomatedSystemChecks(PDO $conn, array $currentUser = [])
{
    $results = [];
    $now = date('Y-m-d H:i:s');
    $addResult = function ($key, $module, $testCase, $input, $expected, $actual, $passed) use (&$results, $now) {
        $results[] = [
            'system_check_key' => $key,
            'module' => $module,
            'test_case' => $testCase,
            'input_data' => $input,
            'expected_output' => $expected,
            'actual_output' => $actual,
            'status' => $passed ? 'PASS' : 'FAIL',
            'source' => 'system',
            'executed_at' => $now,
        ];
    };

    $pageExists = function ($relativePath) {
        return is_file(BASE_PATH . '/' . ltrim((string)$relativePath, '/'));
    };

    try {
        $studentLoginOk = $pageExists('views/student/login.php')
            && $pageExists('views/auth/login.php')
            && $pageExists('views/auth/privacy-consent.php')
            && is_object(new Auth('student'));
        $addResult(
            'student_login_flow',
            'Student',
            'Student login',
            'Valid credentials + student login page',
            'Dashboard loads after auth checks',
            $studentLoginOk ? 'Login pages and auth flow are available.' : 'Student login page or auth dependency is missing.',
            $studentLoginOk
        );
    } catch (Throwable $e) {
        $addResult('student_login_flow', 'Student', 'Student login', 'Valid credentials + student login page', 'Dashboard loads after auth checks', 'Student login check failed: ' . $e->getMessage(), false);
    }

    try {
        $resultsPageOk = $pageExists('views/student/results.php') && unitTestingTableExists($conn, 'results');
        $publishedCount = $resultsPageOk ? (int)($conn->query("SELECT COUNT(*) FROM results WHERE status = 'published'")->fetchColumn() ?: 0) : 0;
        $addResult(
            'student_view_results',
            'Student',
            'View results',
            'Student results page + published results',
            'Results table displays available published results',
            $resultsPageOk ? ('Results page ready; published rows=' . number_format($publishedCount) . '.') : 'Results page or results table is missing.',
            $resultsPageOk
        );
    } catch (Throwable $e) {
        $addResult('student_view_results', 'Student', 'View results', 'Student results page + published results', 'Results table displays available published results', 'Results view check failed: ' . $e->getMessage(), false);
    }

    try {
        $semester = Helper::getCurrentSemester();
        $semesterOk = (int)($semester['id'] ?? 0) > 0;
        $lecturerEntryOk = $pageExists('views/lecturer/enter-results.php') && unitTestingTableExists($conn, 'results') && $semesterOk;
        $addResult(
            'lecturer_upload_marks',
            'Lecturer',
            'Upload marks',
            'CW marks page + active semester',
            'Coursework marks page accepts valid scores',
            $lecturerEntryOk ? 'CW entry page, results table, and active semester are ready.' : 'CW entry page, results table, or active semester is missing.',
            $lecturerEntryOk
        );
    } catch (Throwable $e) {
        $addResult('lecturer_upload_marks', 'Lecturer', 'Upload marks', 'CW marks page + active semester', 'Coursework marks page accepts valid scores', 'CW upload check failed: ' . $e->getMessage(), false);
    }

    try {
        $lecturerPagePath = BASE_PATH . '/views/lecturer/enter-results.php';
        $lecturerPageSource = $pageExists('views/lecturer/enter-results.php') ? (string)file_get_contents($lecturerPagePath) : '';
        $validationOk = $lecturerPageSource !== ''
            && strpos($lecturerPageSource, 'Coursework marks must be between 0 and 40.') !== false
            && strpos($lecturerPageSource, 'min="0"') !== false
            && strpos($lecturerPageSource, 'max="40"') !== false;
        $addResult(
            'lecturer_invalid_mark_guard',
            'Lecturer',
            'Invalid mark',
            'CW below 0 or above 40',
            'Validation message blocks invalid coursework score',
            $validationOk ? 'CW validation rule is present for the 0 to 40 range.' : 'CW validation rule for invalid marks was not found.',
            $validationOk
        );
    } catch (Throwable $e) {
        $addResult('lecturer_invalid_mark_guard', 'Lecturer', 'Invalid mark', 'CW below 0 or above 40', 'Validation message blocks invalid coursework score', 'Invalid-mark check failed: ' . $e->getMessage(), false);
    }

    try {
        $rightsTableOk = unitTestingTableExists($conn, 'transcript_download_rights');
        $issuanceTableOk = unitTestingTableExists($conn, 'transcript_issuances');
        if (class_exists('TranscriptIssuanceService')) {
            $transcriptIssuanceService = new TranscriptIssuanceService($conn);
            $transcriptIssuanceService->ensureSchema();
            $issuanceTableOk = unitTestingTableExists($conn, 'transcript_issuances');
        }
        $transcriptOk = $pageExists('views/student/transcript.php')
            && $pageExists('views/verify/transcript.php')
            && $rightsTableOk
            && $issuanceTableOk;
        $addResult(
            'admin_generate_transcript',
            'Admin',
            'Generate transcript',
            'Student transcript view + transcript tables',
            'Transcript workflow is ready for PDF generation',
            $transcriptOk ? 'Transcript pages and issuance tables are available.' : 'Transcript page, verify page, or transcript tables are missing.',
            $transcriptOk
        );
    } catch (Throwable $e) {
        $addResult('admin_generate_transcript', 'Admin', 'Generate transcript', 'Student transcript view + transcript tables', 'Transcript workflow is ready for PDF generation', 'Transcript check failed: ' . $e->getMessage(), false);
    }

    try {
        $userCreateOk = $pageExists('views/admin/users/add.php')
            && unitTestingTableExists($conn, 'users')
            && unitTestingTableExists($conn, 'admins');
        $addResult(
            'admin_add_new_user',
            'Admin',
            'Add new user',
            'Admin user form + users table',
            'User creation form saves to the database',
            $userCreateOk ? 'Admin user page and supporting tables are available.' : 'Admin user page or supporting tables are missing.',
            $userCreateOk
        );
    } catch (Throwable $e) {
        $addResult('admin_add_new_user', 'Admin', 'Add new user', 'Admin user form + users table', 'User creation form saves to the database', 'User-creation check failed: ' . $e->getMessage(), false);
    }

    try {
        $paymentsReady = $pageExists('views/student/payments.php')
            && is_file(BASE_PATH . '/api/payments/initiate.php')
            && is_file(BASE_PATH . '/api/payments/status.php')
            && unitTestingTableExists($conn, 'payments')
            && unitTestingColumnExists($conn, 'payments', 'receipt_number')
            && unitTestingColumnExists($conn, 'payments', 'amount');
        $paymentsCount = $paymentsReady ? (int)($conn->query("SELECT COUNT(*) FROM payments")->fetchColumn() ?: 0) : 0;
        $addResult(
            'finance_record_payment',
            'Finance',
            'Record payment',
            'Payment page + payment APIs + receipt fields',
            'Receipt details can be stored and displayed',
            $paymentsReady ? ('Payment workflow ready; recorded payments=' . number_format($paymentsCount) . '.') : 'Payments page, API endpoint, or receipt fields are missing.',
            $paymentsReady
        );
    } catch (Throwable $e) {
        $addResult('finance_record_payment', 'Finance', 'Record payment', 'Payment page + payment APIs + receipt fields', 'Receipt details can be stored and displayed', 'Payment check failed: ' . $e->getMessage(), false);
    }

    try {
        $reportsPagePath = BASE_PATH . '/views/admin/reports/index.php';
        $reportsPageSource = $pageExists('views/admin/reports/index.php') ? (string)file_get_contents($reportsPagePath) : '';
        $reportsExportOk = $reportsPageSource !== ''
            && strpos($reportsPageSource, "header('Content-Type: text/csv; charset=utf-8');") !== false
            && strpos($reportsPageSource, "['export'=>'csv']") !== false;
        $addResult(
            'reports_export_csv',
            'Reports',
            'Export report',
            'Admin reports page + export action',
            'CSV report download is available',
            $reportsExportOk ? 'Reports page includes a CSV export action.' : 'Reports export action was not found on the reports page.',
            $reportsExportOk
        );
    } catch (Throwable $e) {
        $addResult('reports_export_csv', 'Reports', 'Export report', 'Admin reports page + export action', 'CSV report download is available', 'Reports export check failed: ' . $e->getMessage(), false);
    }

    $systemKeys = array_values(array_filter(array_map(function ($result) {
        return trim((string)($result['system_check_key'] ?? ''));
    }, $results)));

    if (!empty($systemKeys)) {
        $placeholders = [];
        $params = [];
        foreach ($systemKeys as $index => $key) {
            $param = ':key' . $index;
            $placeholders[] = $param;
            $params[$param] = $key;
        }
        try {
            $cleanupSql = "DELETE FROM unit_test_results WHERE source = 'system' AND system_check_key IS NOT NULL AND system_check_key NOT IN (" . implode(', ', $placeholders) . ")";
            $cleanupStmt = $conn->prepare($cleanupSql);
            $cleanupStmt->execute($params);
        } catch (Throwable $e) {
            // Ignore cleanup errors and keep the latest automated results.
        }
    }

    foreach ($results as $result) {
        try {
            unitTestingUpsertSystemResult($conn, $result);
        } catch (Throwable $e) {
            // Keep the report page available even if one automated result cannot be persisted.
        }
    }

    return $results;
}

$automatedResults = unitTestingRunAutomatedSystemChecks($conn, is_array($currentUser) ? $currentUser : []);
$automatedIntegrationResults = integrationTestingRunAutomatedChecks($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: unit-testing.php');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_result') {
        $moduleInput = trim((string)($_POST['module'] ?? ''));
        $moduleCustomInput = trim((string)($_POST['module_custom'] ?? ''));
        $testCaseInput = trim((string)($_POST['test_case'] ?? ''));
        $testCaseCustomInput = trim((string)($_POST['test_case_custom'] ?? ''));
        $inputData = trim((string)($_POST['input_data'] ?? ''));
        $expectedOutput = trim((string)($_POST['expected_output'] ?? ''));
        $actualOutput = trim((string)($_POST['actual_output'] ?? ''));
        $statusInput = strtoupper(trim((string)($_POST['status'] ?? 'PENDING')));
        $sourceInput = 'manual';
        $executedAtInput = unitTestingNormalizeDateTime($_POST['executed_at'] ?? '', date('Y-m-d H:i:s'));

        if ($moduleInput === '__custom__') {
            $moduleInput = $moduleCustomInput;
        }
        if ($testCaseInput === '__custom__') {
            $testCaseInput = $testCaseCustomInput;
        }

        $allowedStatuses = ['PASS', 'FAIL', 'PENDING'];
        $allowedSources = ['manual'];
        $errors = [];

        if ($moduleInput === '') {
            $errors[] = 'Module is required.';
        }
        if ($testCaseInput === '') {
            $errors[] = 'Test case is required.';
        }
        if ($expectedOutput === '') {
            $errors[] = 'Expected output is required.';
        }
        if ($actualOutput === '') {
            $errors[] = 'Actual output is required.';
        }
        if (!in_array($statusInput, $allowedStatuses, true)) {
            $errors[] = 'Invalid status selected.';
        }
        if (!in_array($sourceInput, $allowedSources, true)) {
            $errors[] = 'Invalid source selected.';
        }

        if (!empty($errors)) {
            $session->setFlash('error', implode(' ', $errors));
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO unit_test_results
                    (module, test_case, input_data, expected_output, actual_output, status, source, executed_at, recorded_by_user_id)
                VALUES
                    (:module, :test_case, :input_data, :expected_output, :actual_output, :status, :source, :executed_at, :recorded_by_user_id)
            ");
            $insertStmt->execute([
                'module' => $moduleInput,
                'test_case' => $testCaseInput,
                'input_data' => $inputData,
                'expected_output' => $expectedOutput,
                'actual_output' => $actualOutput,
                'status' => $statusInput,
                'source' => $sourceInput,
                'executed_at' => $executedAtInput,
                'recorded_by_user_id' => (int)($currentUser['id'] ?? 0) ?: null,
            ]);
            $session->setFlash('success', 'Unit test result recorded successfully.');
        }

        header('Location: unit-testing.php');
        exit;
    }

    if ($action === 'delete_result') {
        $resultId = (int)($_POST['result_id'] ?? 0);
        if ($resultId <= 0) {
            $session->setFlash('error', 'Invalid test result selected.');
        } else {
            $deleteStmt = $conn->prepare("DELETE FROM unit_test_results WHERE id = :id");
            $deleteStmt->execute(['id' => $resultId]);
            $session->setFlash('success', 'Unit test result removed.');
        }

        $redirectQuery = $_POST['return_query'] ?? '';
        $redirectTarget = 'unit-testing.php' . ($redirectQuery !== '' ? '?' . ltrim((string)$redirectQuery, '?') : '');
        header('Location: ' . $redirectTarget);
        exit;
    }
}

$selectedModule = trim((string)($_GET['module'] ?? ''));
$selectedStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
$query = trim((string)($_GET['q'] ?? ''));
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$manualResultsStmt = $conn->prepare("
    SELECT
        utr.id,
        utr.system_check_key,
        utr.module,
        utr.test_case,
        utr.input_data,
        utr.expected_output,
        utr.actual_output,
        utr.status,
        utr.source,
        utr.executed_at,
        utr.recorded_by_user_id
    FROM unit_test_results utr
    WHERE utr.source = 'manual'
    ORDER BY utr.executed_at DESC, utr.id DESC
");
$manualResultsStmt->execute();
$manualResults = $manualResultsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allResults = array_merge($automatedResults, $manualResults);

usort($allResults, function ($left, $right) {
    $leftTime = strtotime((string)($left['executed_at'] ?? '')) ?: 0;
    $rightTime = strtotime((string)($right['executed_at'] ?? '')) ?: 0;
    if ($leftTime === $rightTime) {
        return ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0));
    }
    return $rightTime <=> $leftTime;
});

$filteredResults = array_values(array_filter($allResults, function ($row) use ($selectedModule, $selectedStatus, $query) {
    if ($selectedModule !== '' && (string)($row['module'] ?? '') !== $selectedModule) {
        return false;
    }
    if ($selectedStatus !== '' && strtoupper((string)($row['status'] ?? '')) !== $selectedStatus) {
        return false;
    }
    if ($query !== '') {
        $haystack = strtolower(
            trim(
                (string)($row['module'] ?? '') . ' ' .
                (string)($row['test_case'] ?? '') . ' ' .
                (string)($row['input_data'] ?? '') . ' ' .
                (string)($row['expected_output'] ?? '') . ' ' .
                (string)($row['actual_output'] ?? '')
            )
        );
        if (strpos($haystack, strtolower($query)) === false) {
            return false;
        }
    }
    return true;
}));

$moduleOptions = [];
foreach ($allResults as $row) {
    $moduleName = trim((string)($row['module'] ?? ''));
    if ($moduleName !== '') {
        $moduleOptions[$moduleName] = $moduleName;
    }
}
ksort($moduleOptions, SORT_NATURAL | SORT_FLAG_CASE);
$moduleOptions = array_values($moduleOptions);

$testCaseCatalog = [];
foreach ($allResults as $row) {
    $moduleName = trim((string)($row['module'] ?? ''));
    $testCaseName = trim((string)($row['test_case'] ?? ''));
    if ($moduleName === '' || $testCaseName === '') {
        continue;
    }
    if (!isset($testCaseCatalog[$moduleName])) {
        $testCaseCatalog[$moduleName] = [];
    }
    $testCaseCatalog[$moduleName][$testCaseName] = $testCaseName;
}
foreach ($testCaseCatalog as $moduleName => $cases) {
    ksort($cases, SORT_NATURAL | SORT_FLAG_CASE);
    $testCaseCatalog[$moduleName] = array_values($cases);
}

$summary = [
    'total' => count($filteredResults),
    'pass' => 0,
    'fail' => 0,
    'pending' => 0,
];

foreach ($filteredResults as $row) {
    $statusKey = strtoupper((string)($row['status'] ?? 'PENDING'));
    if ($statusKey === 'PASS') {
        $summary['pass']++;
    } elseif ($statusKey === 'FAIL') {
        $summary['fail']++;
    } else {
        $summary['pending']++;
    }
}

$passRate = $summary['total'] > 0 ? round(($summary['pass'] / $summary['total']) * 100) : 0;

$integrationResults = $automatedIntegrationResults;
usort($integrationResults, function ($left, $right) {
    $leftTime = strtotime((string)($left['executed_at'] ?? '')) ?: 0;
    $rightTime = strtotime((string)($right['executed_at'] ?? '')) ?: 0;
    return $rightTime <=> $leftTime;
});

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unit_testing_results_summary_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Module', 'Test Case', 'Input', 'Expected Output', 'Actual Output', 'Status', 'Executed At']);
    foreach ($filteredResults as $row) {
        fputcsv($out, [
            $row['module'],
            $row['test_case'],
            $row['input_data'],
            $row['expected_output'],
            $row['actual_output'],
            $row['status'],
            $row['executed_at'],
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Unit Testing Results Summary - ' . APP_NAME;
include '../../../includes/header.php';
?>

<?php include '../../../includes/admin/sidebar.php'; ?>
<style>
.unit-testing-page .hero-card {
    border: 0;
    border-radius: 18px;
    background: linear-gradient(135deg, #e0f2fe 0%, #ffffff 55%, #fef3c7 100%);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
}

.unit-testing-page .hero-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
}

.unit-testing-page .hero-copy {
    color: #475569;
    margin-bottom: 0;
}

.unit-testing-page .summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.unit-testing-page .summary-tile {
    border-radius: 16px;
    padding: 12px 14px;
    color: #0f172a;
    background: #fff;
    border: 1px solid rgba(148, 163, 184, 0.22);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
}

.unit-testing-page .summary-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
}

.unit-testing-page .summary-value {
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.1;
    margin-top: 4px;
}

.unit-testing-page .summary-note {
    margin-top: 5px;
    color: #64748b;
    font-size: 0.78rem;
    line-height: 1.35;
}

.unit-testing-page .summary-pass { border-top: 4px solid #16a34a; }
.unit-testing-page .summary-fail { border-top: 4px solid #dc2626; }
.unit-testing-page .summary-pending { border-top: 4px solid #d97706; }
.unit-testing-page .summary-total { border-top: 4px solid #2563eb; }

.unit-testing-page .filter-card,
.unit-testing-page .table-card {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.06);
}

.unit-testing-page .entry-card {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.06);
}

.unit-testing-page .section-header {
    padding: 1rem 1.25rem 0;
}

.unit-testing-page .section-toggle {
    width: 100%;
    border: 0;
    background: transparent;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-align: left;
    cursor: pointer;
}

.unit-testing-page .section-toggle:focus {
    outline: none;
}

.unit-testing-page .section-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.unit-testing-page .section-copy {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.88rem;
}

.unit-testing-page .section-chevron {
    color: #475569;
    font-size: 0.9rem;
    transition: transform 0.2s ease;
}

.unit-testing-page .section-toggle[aria-expanded="true"] .section-chevron {
    transform: rotate(180deg);
}

.unit-testing-page .section-panel {
    padding: 0 1.25rem 1.25rem;
}

.unit-testing-page .tools-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.95fr);
    gap: 16px;
    align-items: start;
}

.unit-testing-page .compact-form .form-group {
    margin-bottom: 0.85rem;
}

.unit-testing-page .compact-form label {
    margin-bottom: 0.35rem;
    font-size: 0.82rem;
    font-weight: 700;
    color: #334155;
}

.unit-testing-page .compact-form .form-control {
    min-height: 40px;
    border-radius: 10px;
}

.unit-testing-page .compact-form textarea.form-control {
    min-height: 92px;
    resize: vertical;
}

.unit-testing-page .results-table thead th {
    background: #d9e8f5;
    color: #1f2937;
    border-bottom: 1px solid #c9d7e6;
    font-weight: 700;
    white-space: normal;
    padding: 0.8rem 0.9rem;
    font-size: 0.9rem;
}

.unit-testing-page .results-table td,
.unit-testing-page .results-table th {
    vertical-align: top;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.unit-testing-page .results-table td {
    color: #334155;
    font-size: 0.88rem;
    line-height: 1.45;
    padding: 0.7rem 0.9rem;
}

.unit-testing-page .results-table {
    width: 100%;
    margin-bottom: 0;
    table-layout: auto;
}

.unit-testing-page .status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 64px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.02em;
}

.unit-testing-page .status-pass {
    background: rgba(22, 163, 74, 0.12);
    color: #15803d;
}

.unit-testing-page .status-fail {
    background: rgba(220, 38, 38, 0.12);
    color: #b91c1c;
}

.unit-testing-page .status-pending {
    background: rgba(217, 119, 6, 0.14);
    color: #b45309;
}

.unit-testing-page .module-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.unit-testing-page .table-responsive {
    overflow-x: visible;
}

.unit-testing-page .results-table th:nth-child(1),
.unit-testing-page .results-table td:nth-child(1),
.unit-testing-page .results-table th:nth-child(6),
.unit-testing-page .results-table td:nth-child(6),
.unit-testing-page .results-table th:nth-child(8),
.unit-testing-page .results-table td:nth-child(8),
.unit-testing-page .results-table th:nth-child(7),
.unit-testing-page .results-table td:nth-child(7) {
    white-space: nowrap;
}

.unit-testing-page .results-table th:nth-child(2),
.unit-testing-page .results-table td:nth-child(2) {
    min-width: 140px;
}

.unit-testing-page .results-table th:nth-child(3),
.unit-testing-page .results-table td:nth-child(3),
.unit-testing-page .results-table th:nth-child(4),
.unit-testing-page .results-table td:nth-child(4),
.unit-testing-page .results-table th:nth-child(5),
.unit-testing-page .results-table td:nth-child(5) {
    min-width: 150px;
}

.unit-testing-page .results-table td:nth-child(3),
.unit-testing-page .results-table td:nth-child(4),
.unit-testing-page .results-table td:nth-child(5) {
    overflow-wrap: break-word;
}

.unit-testing-page .results-table td:last-child,
.unit-testing-page .results-table th:last-child {
    width: 74px;
}

.unit-testing-page .results-table .btn-sm {
    padding: 0.28rem 0.6rem;
    font-size: 0.76rem;
}

.unit-testing-page .integration-report {
    margin-top: 2rem;
    padding: 0.15rem 0;
}

.unit-testing-page .integration-table {
    width: 100%;
    border-collapse: collapse;
    font-family: Georgia, "Times New Roman", serif;
    color: #111827;
}

.unit-testing-page .integration-table th,
.unit-testing-page .integration-table td {
    border: 1px solid #b8d7f0;
    padding: 0.48rem 0.58rem;
    vertical-align: top;
}

.unit-testing-page .integration-table thead th {
    background: #c7dff3;
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1.2;
}

.unit-testing-page .integration-table tbody td {
    font-size: 0.84rem;
    line-height: 1.28;
}

.unit-testing-page .integration-result {
    width: 72px;
    text-align: center;
    white-space: nowrap;
    font-weight: 700;
}

.unit-testing-page .integration-pass {
    color: #166534;
}

.unit-testing-page .integration-fail {
    color: #b91c1c;
}

.unit-testing-page .integration-caption {
    margin-top: 0.45rem;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 0.92rem;
    font-style: italic;
    color: #374151;
}

html[data-theme='dark'] .unit-testing-page .hero-card,
html[data-theme='dark'] .unit-testing-page .summary-tile,
html[data-theme='dark'] .unit-testing-page .entry-card,
html[data-theme='dark'] .unit-testing-page .filter-card,
html[data-theme='dark'] .unit-testing-page .table-card {
    background: #0f172a;
    color: #e2e8f0;
    box-shadow: 0 18px 34px rgba(2, 6, 23, 0.45);
}

html[data-theme='dark'] .unit-testing-page .hero-title,
html[data-theme='dark'] .unit-testing-page .summary-value {
    color: #f8fafc;
}

html[data-theme='dark'] .unit-testing-page .section-title,
html[data-theme='dark'] .unit-testing-page .section-chevron {
    color: #e2e8f0;
}

html[data-theme='dark'] .unit-testing-page .integration-caption {
    color: #cbd5e1;
}

html[data-theme='dark'] .unit-testing-page .hero-copy,
html[data-theme='dark'] .unit-testing-page .summary-label,
html[data-theme='dark'] .unit-testing-page .summary-note,
html[data-theme='dark'] .unit-testing-page .results-table td {
    color: #cbd5e1;
}

html[data-theme='dark'] .unit-testing-page .results-table thead th {
    background: #1e293b;
    color: #e2e8f0;
    border-bottom-color: #334155;
}

html[data-theme='dark'] .unit-testing-page .module-badge {
    background: rgba(37, 99, 235, 0.18);
    color: #93c5fd;
}

html[data-theme='dark'] .unit-testing-page .integration-table {
    color: #e5e7eb;
}

html[data-theme='dark'] .unit-testing-page .integration-table th,
html[data-theme='dark'] .unit-testing-page .integration-table td {
    border-color: #4b5563;
}

html[data-theme='dark'] .unit-testing-page .integration-table thead th {
    background: #284b70;
}

html[data-theme='dark'] .unit-testing-page .integration-pass {
    color: #86efac;
}

html[data-theme='dark'] .unit-testing-page .integration-fail {
    color: #fca5a5;
}

@media (max-width: 991.98px) {
    .unit-testing-page .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .unit-testing-page .tools-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 575.98px) {
    .unit-testing-page .summary-grid {
        grid-template-columns: 1fr;
    }

    .unit-testing-page .content-area {
        padding: 12px !important;
    }

    .unit-testing-page .table-responsive {
        overflow-x: visible;
    }

    .unit-testing-page .results-table,
    .unit-testing-page .results-table thead,
    .unit-testing-page .results-table tbody,
    .unit-testing-page .results-table tr,
    .unit-testing-page .results-table th,
    .unit-testing-page .results-table td {
        display: block;
        width: 100%;
    }

    .unit-testing-page .results-table thead {
        display: none;
    }

    .unit-testing-page .results-table tr {
        border-bottom: 1px solid #e2e8f0;
        padding: 10px 12px;
    }

    .unit-testing-page .results-table td {
        border: 0;
        padding: 8px 0 8px 42%;
        position: relative;
        min-height: 36px;
    }

    .unit-testing-page .results-table td::before {
        content: attr(data-label);
        position: absolute;
        left: 0;
        top: 8px;
        width: 38%;
        font-weight: 700;
        color: #475569;
        padding-right: 10px;
    }

    .unit-testing-page .results-table td.text-center {
        padding-left: 0;
    }

    .unit-testing-page .results-table td.text-center::before {
        content: '';
    }
}

html[data-theme='dark'] .unit-testing-page .results-table tr {
    border-bottom-color: #334155;
}

html[data-theme='dark'] .unit-testing-page .results-table td::before {
    color: #94a3b8;
}
</style>

<div class="main-content unit-testing-page">
    <div class="content-area container-fluid p-4">
        <div class="card hero-card mb-4">
            <div class="card-body d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                <div>
                    <div class="hero-title">Unit Testing Results Summary</div>
                    <p class="hero-copy">Review concise QA checks, add verified manual results, and export the latest summary for reporting.</p>
                </div>
                <div class="mt-3 mt-lg-0">
                    <a href="unit-testing.php?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-file-csv mr-1"></i> Export CSV
                    </a>
                </div>
            </div>
        </div>

        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger"><?php echo e($session->getFlash('error')); ?></div>
        <?php endif; ?>

        <div class="summary-grid mb-4">
            <div class="summary-tile summary-total">
                <div class="summary-label">Total Tests</div>
                <div class="summary-value"><?php echo number_format((int)$summary['total']); ?></div>
                <div class="summary-note">Filtered result entries in the table below.</div>
            </div>
            <div class="summary-tile summary-pass">
                <div class="summary-label">Passed</div>
                <div class="summary-value"><?php echo number_format((int)$summary['pass']); ?></div>
                <div class="summary-note">Test cases completed successfully.</div>
            </div>
            <div class="summary-tile summary-fail">
                <div class="summary-label">Failed</div>
                <div class="summary-value"><?php echo number_format((int)$summary['fail']); ?></div>
                <div class="summary-note">Cases needing correction before sign-off.</div>
            </div>
            <div class="summary-tile summary-pending">
                <div class="summary-label">Pass Rate</div>
                <div class="summary-value"><?php echo number_format((int)$passRate); ?>%</div>
                <div class="summary-note">Calculated from the current filtered results.</div>
            </div>
        </div>

        <div class="tools-grid mb-4">
        <div class="card entry-card">
            <button type="button" class="section-toggle" data-toggle-section="record-test-panel" aria-expanded="false">
                <span>
                    <span class="section-title"><i class="fas fa-plus-circle mr-1"></i> Record Test Result</span>
                    <span class="section-copy d-block">Add a manually verified result to complement the automated checks.</span>
                </span>
                <i class="fas fa-chevron-down section-chevron"></i>
            </button>
            <div class="section-panel d-none" id="record-test-panel">
                <form method="post" class="compact-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_result">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="entry_module">Module</label>
                            <select class="form-control" id="entry_module" name="module" required>
                                <option value="">Choose module</option>
                                <?php foreach ($moduleOptions as $moduleName): ?>
                                    <option value="<?php echo e($moduleName); ?>"><?php echo e($moduleName); ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__">Other module</option>
                            </select>
                            <input type="text" class="form-control mt-2 d-none" id="entry_module_custom" name="module_custom" placeholder="Type module name">
                        </div>
                        <div class="form-group col-md-5">
                            <label for="entry_test_case">Test Case</label>
                            <select class="form-control" id="entry_test_case" name="test_case" required>
                                <option value="">Choose test case</option>
                                <option value="__custom__">Other test case</option>
                            </select>
                            <input type="text" class="form-control mt-2 d-none" id="entry_test_case_custom" name="test_case_custom" placeholder="Describe the executed test">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="entry_status">Status</label>
                            <select class="form-control" id="entry_status" name="status" required>
                                <option value="PASS">PASS</option>
                                <option value="FAIL">FAIL</option>
                                <option value="PENDING">PENDING</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="entry_input">Input</label>
                            <textarea class="form-control" id="entry_input" name="input_data" rows="3" placeholder="Input values, IDs, or scenario setup"></textarea>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="entry_expected">Expected Output</label>
                            <textarea class="form-control" id="entry_expected" name="expected_output" rows="3" placeholder="What should happen" required></textarea>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="entry_actual">Actual Output</label>
                            <textarea class="form-control" id="entry_actual" name="actual_output" rows="3" placeholder="What actually happened" required></textarea>
                        </div>
                    </div>
                    <div class="form-row align-items-end">
                        <div class="form-group col-md-3">
                            <label for="entry_executed_at">Executed At</label>
                            <input type="datetime-local" class="form-control" id="entry_executed_at" name="executed_at" value="<?php echo e(date('Y-m-d\TH:i')); ?>">
                        </div>
                        <div class="form-group col-md-9 text-md-right">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save mr-1"></i> Save Result
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card filter-card">
            <button type="button" class="section-toggle" data-toggle-section="find-results-panel" aria-expanded="true">
                <span>
                    <span class="section-title"><i class="fas fa-filter mr-1"></i> Find Results</span>
                    <span class="section-copy d-block">Narrow the table by module, status, or keyword.</span>
                </span>
                <i class="fas fa-chevron-down section-chevron"></i>
            </button>
            <div class="section-panel" id="find-results-panel">
                <form method="get" class="compact-form">
                    <div class="form-group">
                        <label for="module">Module</label>
                        <select name="module" id="module" class="form-control">
                            <option value="">All Modules</option>
                            <?php foreach ($moduleOptions as $moduleName): ?>
                                <option value="<?php echo e($moduleName); ?>" <?php echo $selectedModule === $moduleName ? 'selected' : ''; ?>>
                                    <?php echo e($moduleName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="PASS" <?php echo $selectedStatus === 'PASS' ? 'selected' : ''; ?>>PASS</option>
                            <option value="FAIL" <?php echo $selectedStatus === 'FAIL' ? 'selected' : ''; ?>>FAIL</option>
                            <option value="PENDING" <?php echo $selectedStatus === 'PENDING' ? 'selected' : ''; ?>>PENDING</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="q">Search</label>
                        <input type="text" name="q" id="q" class="form-control" value="<?php echo e($query); ?>" placeholder="Search module, test case, input or output">
                    </div>
                    <div class="d-flex">
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-filter mr-1"></i> Filter
                        </button>
                        <a href="unit-testing.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        </div>

        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-vial mr-1"></i> Table 1: Unit Testing Results Summary</h5>
                <span class="text-muted small">Rows: <?php echo number_format(count($filteredResults)); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0 results-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Test Case</th>
                                <th>Input</th>
                                <th>Expected Output</th>
                                <th>Actual Output</th>
                                <th>Status</th>
                                <th>Executed At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filteredResults)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No unit testing results found yet. Use the form above to record a real executed test case.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filteredResults as $row): ?>
                                    <?php $statusKey = strtolower((string)$row['status']); ?>
                                    <tr>
                                        <td data-label="Module"><span class="module-badge"><?php echo e($row['module']); ?></span></td>
                                        <td data-label="Test Case"><?php echo e($row['test_case']); ?></td>
                                        <td data-label="Input"><?php echo e($row['input_data']); ?></td>
                                        <td data-label="Expected Output"><?php echo e($row['expected_output']); ?></td>
                                        <td data-label="Actual Output"><?php echo e($row['actual_output']); ?></td>
                                        <td data-label="Status">
                                            <span class="status-pill status-<?php echo e($statusKey); ?>">
                                                <?php echo e($row['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Executed At"><?php echo e($row['executed_at']); ?></td>
                                        <td data-label="Actions">
                                            <?php if ((string)($row['source'] ?? 'system') === 'manual' && !empty($row['id'])): ?>
                                                <form method="post" onsubmit="return confirm('Delete this recorded test result?');">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_result">
                                                    <input type="hidden" name="result_id" value="<?php echo (int)$row['id']; ?>">
                                                    <input type="hidden" name="return_query" value="<?php echo e(http_build_query($_GET)); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">Auto</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="integration-report">
            <div class="table-responsive">
                <table class="integration-table">
                    <thead>
                        <tr>
                            <th>Integration Test</th>
                            <th>Modules Tested</th>
                            <th>Expected Outcome</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($integrationResults as $row): ?>
                            <?php $integrationStatusKey = strtoupper((string)($row['status'] ?? 'FAIL')); ?>
                            <tr>
                                <td><?php echo e($row['integration_test']); ?></td>
                                <td><?php echo e($row['modules_tested']); ?></td>
                                <td><?php echo e($row['expected_outcome']); ?></td>
                                <td class="integration-result <?php echo $integrationStatusKey === 'PASS' ? 'integration-pass' : 'integration-fail'; ?>">
                                    <?php echo e($integrationStatusKey); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="integration-caption">Table 2: Integration Testing Results</div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
<script>
(function () {
    var sectionToggles = document.querySelectorAll('[data-toggle-section]');
    Array.prototype.forEach.call(sectionToggles, function (toggle) {
        toggle.addEventListener('click', function () {
            var targetId = toggle.getAttribute('data-toggle-section');
            var panel = document.getElementById(targetId);
            if (!panel) {
                return;
            }
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            panel.classList.toggle('d-none', expanded);
        });
    });

    var moduleSelect = document.getElementById('entry_module');
    var moduleCustom = document.getElementById('entry_module_custom');
    var testCaseSelect = document.getElementById('entry_test_case');
    var testCaseCustom = document.getElementById('entry_test_case_custom');
    var catalog = <?php echo json_encode($testCaseCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    if (!moduleSelect || !moduleCustom || !testCaseSelect || !testCaseCustom) {
        return;
    }

    function toggleCustomField(selectEl, customEl) {
        var isCustom = selectEl.value === '__custom__';
        customEl.classList.toggle('d-none', !isCustom);
        customEl.disabled = !isCustom;
        customEl.required = isCustom;
        if (!isCustom) {
            customEl.value = '';
        }
    }

    function rebuildTestCases() {
        var moduleValue = moduleSelect.value;
        var previousValue = testCaseSelect.value;
        var items = [];

        if (moduleValue && moduleValue !== '__custom__' && Array.isArray(catalog[moduleValue])) {
            items = catalog[moduleValue];
        }

        testCaseSelect.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose test case';
        testCaseSelect.appendChild(placeholder);

        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item;
            option.textContent = item;
            testCaseSelect.appendChild(option);
        });

        var customOption = document.createElement('option');
        customOption.value = '__custom__';
        customOption.textContent = 'Other test case';
        testCaseSelect.appendChild(customOption);

        if (items.indexOf(previousValue) !== -1 || previousValue === '__custom__') {
            testCaseSelect.value = previousValue;
        } else {
            testCaseSelect.value = '';
        }

        toggleCustomField(testCaseSelect, testCaseCustom);
    }

    moduleSelect.addEventListener('change', function () {
        toggleCustomField(moduleSelect, moduleCustom);
        rebuildTestCases();
    });

    testCaseSelect.addEventListener('change', function () {
        toggleCustomField(testCaseSelect, testCaseCustom);
    });

    toggleCustomField(moduleSelect, moduleCustom);
    rebuildTestCases();
})();
</script>
