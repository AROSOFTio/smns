<?php
require_once __DIR__ . '/../config.php';

$defaultPassword = 'Password@' . date('Y');
$passwordHash = Security::hashPassword($defaultPassword);

$db = new Database();
$conn = $db->getConnection();

try {
    $hasRequirePasswordChange = false;
    $colStmt = $conn->query("SHOW COLUMNS FROM users LIKE 'require_password_change'");
    if ($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $hasRequirePasswordChange = true;
    }

    if ($hasRequirePasswordChange) {
        $stmt = $conn->prepare("
            UPDATE users u
            INNER JOIN students s ON s.user_id = u.id
            SET u.password_hash = :password_hash,
                u.require_password_change = 0
            WHERE u.role = 'student'
        ");
    } else {
        $stmt = $conn->prepare("
            UPDATE users u
            INNER JOIN students s ON s.user_id = u.id
            SET u.password_hash = :password_hash
            WHERE u.role = 'student'
        ");
    }

    $stmt->execute(['password_hash' => $passwordHash]);
    echo 'Reset password for ' . $stmt->rowCount() . ' student account(s) to ' . $defaultPassword . '.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
