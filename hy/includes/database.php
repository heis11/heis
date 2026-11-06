<?php
// One MySQL connection shared by the public site (api.php) and the admin dashboard.
require_once dirname(__DIR__) . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        error_log('HEISVERSE database error: ' . $e->getMessage());
        throw new RuntimeException('Cannot connect to the database "' . DB_NAME . '". Start MySQL in XAMPP, import database.sql in phpMyAdmin, and check the DB_ settings in config.php.', 503);
    }
    return $pdo;
}
