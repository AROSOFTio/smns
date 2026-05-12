<?php
require_once __DIR__ . '/../../config.php';

$query = $_GET;
$query['role'] = 'student';

header('Location: ' . BASE_URL . '/views/auth/login.php?' . http_build_query($query));
exit;
