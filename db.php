<?php
// db.php — Database connection (include once per request)

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // change to your MySQL username
define('DB_PASS', '');           // change to your MySQL password
define('DB_NAME', 'lms_db');
define('DB_PORT', 3306);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Show a clean error instead of exposing credentials
    http_response_code(500);
    die('<div style="font-family:sans-serif;padding:40px;color:#991b1b;">
           <strong>Database connection failed.</strong><br>
           Please check your credentials in <code>includes/db.php</code>.<br><br>
           <small>' . htmlspecialchars($e->getMessage()) . '</small>
         </div>');
}
