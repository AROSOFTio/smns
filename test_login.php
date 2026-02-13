<?php
require_once __DIR__ . '/config.php';

// Quick test for student login
$username = $argv[1] ?? 'std001';
$password = $argv[2] ?? 'password';

echo "Testing login for: $username\n";

$auth = new Auth('student');
$user = $auth->usernameExists($username);

echo "usernameExists result:\n";
var_dump($user);

echo "\nAttempting login with provided password:\n";
$result = $auth->login($username, $password);
var_dump($result);

if ($result['success']) {
    echo "\nLogin succeeded for role: " . ($result['role'] ?? 'unknown') . "\n";
} else {
    echo "\nLogin failed: " . ($result['message'] ?? 'No message') . "\n";
}
