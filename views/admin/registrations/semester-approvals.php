<?php
/**
 * Backward-compatible entrypoint redirected to pending approvals.
 */
require_once '../../../config.php';

$session = new Session('admin');
$auth = new Auth('admin');
if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

header('Location: ' . BASE_URL . '/views/admin/registrations/pending.php');
exit;
