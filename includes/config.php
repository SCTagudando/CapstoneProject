<?php
// includes/config.php
// S-Five Inland Resort - Database Configuration

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Change to your MySQL username
define('DB_PASS', '');           // Change to your MySQL password
define('DB_NAME', 'sfive_resort');

define('SITE_NAME', 'S-Five Inland Resort');
define('SITE_URL', 'http://localhost/sfive'); // Change to your domain

// ============================================
// PAYMONGO API KEYS
// Get these from: https://dashboard.paymongo.com
// ============================================
define('PAYMONGO_SECRET_KEY',     'sk_test_REPLACE_WITH_YOUR_SECRET_KEY');
define('PAYMONGO_PUBLIC_KEY',     'pk_test_REPLACE_WITH_YOUR_PUBLIC_KEY');
define('PAYMONGO_WEBHOOK_SECRET', 'whsk_REPLACE_WITH_YOUR_WEBHOOK_SECRET');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:2rem;color:red;">
                <h2>Database Connection Error</h2>
                <p>Could not connect to MySQL. Please check your config.php settings.</p>
                <code>' . htmlspecialchars($e->getMessage()) . '</code>
            </div>');
        }
    }
    return $pdo;
}

// Generate unique booking code
function generateBookingCode() {
    return 'SFR-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}

// Sanitize input
function clean($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

// Start session
session_start();
?>
