<?php
require 'config.php';

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare('UPDATE users SET status = "active" WHERE username = "karimkigenyi1" AND (status IS NULL OR status = "")');
    $result = $stmt->execute();
    echo 'User status updated successfully' . PHP_EOL;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>