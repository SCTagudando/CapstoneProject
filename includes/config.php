<?php
define('DB_HOST', '');
define('DB_USER', '');      
define('DB_PASS', '');          
define('DB_NAME', '');

define('SITE_NAME', '');
define('SITE_URL', '');
define('PAYMONGO_SECRET_KEY',     '');
define('PAYMONGO_PUBLIC_KEY',     '');
define('PAYMONGO_WEBHOOK_SECRET', '');

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

function generateBookingCode() {
    return 'SFR-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}

function clean($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

session_start();
?>