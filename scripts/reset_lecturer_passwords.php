<?php
echo "Script starting...\n";
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Loading config...\n";
require_once __DIR__ . '/../config.php';

echo "Getting database connection...\n";
$db = new Database();
$conn = $db->getConnection();

echo "Database connected.\n";

$password = 'Password@' . date('Y');
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Hash generated: $hash\n";

$stmt = $conn->prepare("
    UPDATE users
    SET password_hash = ?,
        failed_login_attempts = 0,
        account_locked_until = NULL,
        status = 'active',
        require_password_change = 0
    WHERE role = 'lecturer'
");
echo "Statement prepared.\n";
$stmt->execute([$hash]);
echo "Statement executed.\n";

echo "Updated " . $stmt->rowCount() . " lecturer accounts.\n";
echo "Password: $password\n";
echo "Hash: $hash\n";

// Verify
$check = $conn->query("SELECT id, username, password_hash FROM users WHERE role = 'lecturer' LIMIT 1")->fetch();
echo "\nVerification - User: " . $check['username'] . "\n";
echo "Hash matches: " . (password_verify($password, $check['password_hash']) ? 'YES' : 'NO') . "\n";
