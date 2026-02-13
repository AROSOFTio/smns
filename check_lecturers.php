<?php
require 'config.php';

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $result = $pdo->query('DESCRIBE lecturers');
    echo 'Current lecturers table columns:' . PHP_EOL;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo '- ' . $row['Field'] . ' (' . $row['Type'] . ')' . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>