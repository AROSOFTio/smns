<?php
/**
 * Password Reset Script
 *
 * This script resets the passwords for specified users.
 * For security, please delete this file immediately after use.
 */

require_once '../config.php';

// --- Configuration ---
$new_password = 'Password@2026'; // The new password for the users.
$users_to_update = [
    'admin',
    'kevin'
];
// -------------------

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    echo "<h3>Password Reset Script</h3>";
    echo "Attempting to reset passwords for: " . implode(', ', $users_to_update) . "<br>";
    echo "New password will be set to: <strong>" . htmlspecialchars($new_password) . "</strong><br><hr>";

    // Prepare the update statement
    $stmt = $conn->prepare("UPDATE users SET password_hash = :password WHERE username = :username");

    foreach ($users_to_update as $username) {
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':username', $username);

        if ($stmt->execute()) {
            $count = $stmt->rowCount();
            if ($count > 0) {
                echo "✅ Password for user '<strong>" . htmlspecialchars($username) . "</strong>' has been successfully reset.<br>";
            } else {
                echo "⚠️ No user found with username '<strong>" . htmlspecialchars($username) . "</strong>'. No changes made.<br>";
            }
        } else {
            echo "❌ Failed to execute update for user '<strong>" . htmlspecialchars($username) . "</strong>'.<br>";
        }
    }

    echo "<hr><h4>Script finished.</h4>";
    echo "<p style='color:red; font-weight:bold;'>IMPORTANT: Please delete this file (reset_passwords.php) from the 'scripts' directory immediately for security reasons.</p>";

} catch (Exception $e) {
    die("<h3>Error</h3><p>An error occurred: " . $e->getMessage() . "</p>");
}
