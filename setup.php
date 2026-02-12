<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMNS - Database Setup</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #f5f5f5; padding: 40px 0; }
        .setup-container { max-width: 800px; margin: 0 auto; }
        .card { margin-bottom: 20px; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3>Seminary Results Management System - Setup</h3>
            </div>
            <div class="card-body">
                <?php
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
                
                echo "<h4>Step 1: Configuration Check</h4>";
                
                // Check if config file exists
                if (file_exists('config.php')) {
                    echo "<p class='success'>✓ config.php found</p>";
                    require_once 'config.php';
                    
                    echo "<p><strong>Database Settings:</strong></p>";
                    echo "<pre>";
                    echo "Host: " . DB_HOST . "\n";
                    echo "Database: " . DB_NAME . "\n";
                    echo "User: " . DB_USER . "\n";
                    echo "</pre>";
                } else {
                    echo "<p class='error'>✗ config.php not found</p>";
                    die();
                }
                
                echo "<hr><h4>Step 2: Database Connection Test</h4>";
                
                try {
                    // Try to connect without selecting database first
                    $pdo = new PDO(
                        "mysql:host=" . DB_HOST,
                        DB_USER,
                        DB_PASS,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    echo "<p class='success'>✓ MySQL connection successful</p>";
                    
                    // Check if database exists
                    $databases = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'")->fetchAll();
                    
                    if (count($databases) > 0) {
                        echo "<p class='success'>✓ Database '" . DB_NAME . "' exists</p>";
                        
                        // Connect to the database
                        $pdo = new PDO(
                            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
                            DB_USER,
                            DB_PASS,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );
                        
                        // Check if users table exists
                        $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
                        
                        if (count($tables) > 0) {
                            echo "<p class='success'>✓ Users table exists</p>";
                            
                            // Count users
                            $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                            echo "<p class='success'>✓ Users in database: " . $userCount . "</p>";
                            
                            if ($userCount > 0) {
                                echo "<hr><h4>Step 3: Test Users</h4>";
                                echo "<table class='table table-sm'>";
                                echo "<thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>";
                                echo "<tbody>";
                                
                                $users = $pdo->query("SELECT username, email, role, status FROM users LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($users as $user) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                                    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
                                    echo "<td><span class='badge badge-info'>" . htmlspecialchars($user['role']) . "</span></td>";
                                    echo "<td><span class='badge badge-success'>" . htmlspecialchars($user['status']) . "</span></td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table>";
                                
                                echo "<div class='alert alert-info'>";
                                echo "<strong>Default Test Credentials:</strong><br>";
                                echo "• Admin: <code>admin</code> / <code>password</code><br>";
                                echo "• Student: <code>std001</code> / <code>password</code><br>";
                                echo "• Lecturer: <code>prof.johnson</code> / <code>password</code><br>";
                                echo "• Finance: <code>finance1</code> / <code>password</code>";
                                echo "</div>";
                                
                                echo "<div class='alert alert-success'>";
                                echo "<h5>✓ Setup Complete!</h5>";
                                echo "<p>Your system is ready. You can now:</p>";
                                echo "<a href='views/auth/login.php' class='btn btn-primary'>Go to Login Page</a> ";
                                echo "<a href='index.php' class='btn btn-secondary'>Go to Home</a>";
                                echo "</div>";
                            } else {
                                echo "<div class='alert alert-warning'>";
                                echo "<h5>Database is empty - Need to run seed data</h5>";
                                echo "<p>Run the following SQL files in phpMyAdmin or MySQL:</p>";
                                echo "<ol>";
                                echo "<li><code>Seed/schema.sql</code> - Creates tables</li>";
                                echo "<li><code>Seed/seed.sql</code> - Inserts test data</li>";
                                echo "</ol>";
                                echo "</div>";
                            }
                        } else {
                            echo "<p class='error'>✗ Users table does not exist</p>";
                            echo "<div class='alert alert-warning'>";
                            echo "<h5>Need to create tables</h5>";
                            echo "<p>Import <code>Seed/schema.sql</code> using phpMyAdmin or MySQL command line</p>";
                            echo "</div>";
                        }
                    } else {
                        echo "<p class='error'>✗ Database '" . DB_NAME . "' does not exist</p>";
                        echo "<div class='alert alert-warning'>";
                        echo "<h5>Creating database...</h5>";
                        
                        try {
                            $pdo->exec("CREATE DATABASE `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                            echo "<p class='success'>✓ Database created successfully</p>";
                            echo "<p>Now import <code>Seed/schema.sql</code> and <code>Seed/seed.sql</code></p>";
                        } catch (Exception $e) {
                            echo "<p class='error'>✗ Failed to create database: " . $e->getMessage() . "</p>";
                        }
                        echo "</div>";
                    }
                    
                } catch(PDOException $e) {
                    echo "<p class='error'>✗ Connection failed: " . $e->getMessage() . "</p>";
                    echo "<div class='alert alert-danger'>";
                    echo "<h5>Database Connection Failed</h5>";
                    echo "<p>Please check:</p>";
                    echo "<ul>";
                    echo "<li>XAMPP MySQL service is running</li>";
                    echo "<li>Database credentials in config.php are correct</li>";
                    echo "<li>MySQL port 3306 is not blocked</li>";
                    echo "</ul>";
                    echo "</div>";
                }
                
                echo "<hr><h4>Step 4: PHP Extensions Check</h4>";
                echo "<p class='success'>✓ PDO: " . (extension_loaded('pdo') ? 'Enabled' : 'Disabled') . "</p>";
                echo "<p class='success'>✓ PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled') . "</p>";
                echo "<p class='success'>✓ Session: " . (extension_loaded('session') ? 'Enabled' : 'Disabled') . "</p>";
                echo "<p class='success'>✓ OpenSSL: " . (extension_loaded('openssl') ? 'Enabled' : 'Disabled') . "</p>";
                
                ?>
            </div>
        </div>
    </div>
</body>
</html>
