<?php
/**
 * Installation Script for Mira Edge Technologies
 * Run this once, then DELETE IT!
 */

// Prevent direct access without confirmation
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    die('
        <!DOCTYPE html>
        <html>
        <head>
            <title>Mira Edge - Installation</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 20px; margin: 20px auto; max-width: 600px; }
                .btn { display: inline-block; padding: 10px 20px; margin: 10px; text-decoration: none; }
                .btn-danger { background: #dc3545; color: white; }
                .btn-primary { background: #007bff; color: white; }
            </style>
        </head>
        <body>
            <h1>Mira Edge Technologies - Installation</h1>
            <div class="warning">
                <h2>⚠️ WARNING!</h2>
                <p>This will:</p>
                <ul>
                    <li>Create/overwrite the database</li>
                    <li>Insert initial data</li>
                    <li>Create default admin user</li>
                </ul>
                <p><strong>Make sure you have backed up any existing data!</strong></p>
            </div>
            <p>
                <a href="install.php?confirm=yes" class="btn btn-danger">Proceed with Installation</a>
                <a href="/" class="btn btn-primary">Cancel</a>
            </p>
        </body>
        </html>
    ');
}

// Start installation
echo "<!DOCTYPE html><html><head><title>Installing...</title></head><body>";
echo "<h1>Installing Mira Edge Technologies...</h1>";
echo "<pre>";

try {
    // Connect to MySQL (without selecting database)
    $pdo = new PDO("mysql:host=localhost", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Drop database if exists (for fresh install)
    $pdo->exec("DROP DATABASE IF EXISTS mira_edge_technologies");
    echo "✓ Dropped existing database\n";
    
    // Create database
    $pdo->exec("CREATE DATABASE mira_edge_technologies CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE mira_edge_technologies");
    echo "✓ Created database\n";
    
    // Read and execute SQL file
    $sql_file = 'database-schema.sql';
    if (!file_exists($sql_file)) {
        die("Error: SQL file ($sql_file) not found!");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split SQL into individual statements
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                echo "✓ Executed SQL statement\n";
            } catch (PDOException $e) {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✅ Installation completed successfully!\n";
    echo "\nDefault Admin Credentials:\n";
    echo "Username: superadmin\n";
    echo "Email: admin@miraedgetech.com\n";
    echo "Password: Admin@MiraEdge2024\n";
    echo "\n⚠️ IMPORTANT:\n";
    echo "1. Change the default password immediately\n";
    echo "2. Delete this install.php file\n";
    echo "3. Update database credentials in /includes/config/database.php\n";
    
} catch (PDOException $e) {
    echo "❌ Installation failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo '<p><a href="/login.php">Go to Login Page</a></p>';
echo "</body></html>";
?>