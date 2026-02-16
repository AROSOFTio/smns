<?php
/**
 * Unlock User Account Script
 *
 * This script resets the failed login attempts and unlocks the specified user accounts.
 * For security, please delete this file immediately after use.
 */

require_once '../config.php';

// --- Configuration ---
$users_to_unlock = [
    'admin',
    'kevin'
];
// -------------------

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "<h3>Account Unlock Script</h3>";
    echo "Attempting to unlock accounts: " . implode(', ', $users_to_unlock) . "<br><hr>";

    // Prepare the update statement
    $stmt = $conn->prepare(
        "UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL WHERE username = :username"
    );

    foreach ($users_to_unlock as $username) {
        $stmt->bindParam(':username', $username);

        if ($stmt->execute()) {
            $count = $stmt->rowCount();
            if ($count > 0) {
                echo "✅ Account for user '<strong>" . htmlspecialchars($username) . "</strong>' has been successfully unlocked.<br>";
            } else {
                echo "⚠️ No user found with username '<strong>" . htmlspecialchars($username) . "</strong>'. No changes made.<br>";
            }
        } else {
            echo "❌ Failed to execute update for user '<strong>" . htmlspecialchars($username) . "</strong>'.<br>";
        }
    }

    echo "<hr><h4>Script finished.</h4>";
    echo "<p style='color:red; font-weight:bold;'>IMPORTANT: Please delete this file (unlock_accounts.php) from the 'scripts' directory immediately for security reasons.</p>";

} catch (Exception $e) {
    die("<h3>Error</h3><p>An error occurred: " . $e->getMessage() . "</p>");
}
