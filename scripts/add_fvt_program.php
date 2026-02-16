<?php
// scripts/add_fvt_program.php
require_once __DIR__ . '/../config.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $programName = 'FIAT VOLUNTAS TUA';
    $programCode = 'FVT';
    $department = 'Theology'; // A sensible default

    // Check if the program already exists
    $checkStmt = $conn->prepare("SELECT id FROM programs WHERE program_code = :program_code OR program_name = :program_name");
    $checkStmt->execute(['program_code' => $programCode, 'program_name' => $programName]);

    if ($checkStmt->fetch()) {
        $message = "Program '{$programName}' ({$programCode}) already exists.";
    } else {
        // Insert the new program
        $insertStmt = $conn->prepare("
            INSERT INTO programs (program_code, program_name, department, duration_years, total_credits_required, status) 
            VALUES (:program_code, :program_name, :department, 3, 120, 'active')
        ");
        $insertStmt->execute([
            'program_code' => $programCode,
            'program_name' => $programName,
            'department' => $department
        ]);
        $message = "Successfully added program '{$programName}' ({$programCode}) to the database.";
    }

    if (php_sapi_name() === 'cli') {
        echo $message . "\n";
    } else {
        echo "<h1>Success</h1><p>{$message}</p><p><a href='../views/admin/courses/add.php'>Go back to Add Course page</a></p>";
    }
    exit;

} catch (Exception $e) {
    $errorMessage = "Error: " . $e->getMessage();
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain', true, 500);
    }
    echo $errorMessage;
    exit;
}
