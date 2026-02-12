<?php
/**
 * Logout Page
 */
require_once '../../config.php';

// Start a new session for flash message
$session = new Session();

// Set flash message before destroying the session
$session->setFlash('success', 'You have been successfully logged out.');

// Store flash message in a temporary variable
$flashMessage = $_SESSION['flash'] ?? [];

// Logout and destroy session
$auth = new Auth();
$auth->logout();

// Start new session and restore flash message
session_start();
if (!empty($flashMessage)) {
    $_SESSION['flash'] = $flashMessage;
}

// Redirect to login
header('Location: login.php');
exit;
