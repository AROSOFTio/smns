<?php
require_once '../../../config.php';

// require admin session
$session = new Session('admin');
$auth = new Auth('admin');
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/views/auth/login.php');
    exit;
}

$file = $_GET['file'] ?? '';
$basename = basename($file);
$backupDir = BASE_PATH . DIRECTORY_SEPARATOR . 'database backup';
$fullPath = realpath($backupDir . DIRECTORY_SEPARATOR . $basename);

if (!$fullPath || strpos($fullPath, realpath($backupDir)) !== 0 || !file_exists($fullPath)) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    echo 'File not found';
    exit;
}

// serve file securely
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;