<?php
/**
 * Database connection.
 *
 * Credentials come from the environment when it supplies them, so a deployed
 * copy never has to keep its real password in a file that ships with the code.
 * The values below are the stock WAMP/XAMPP defaults and are only a fallback
 * for local development.
 *
 * The connection settings are built inside a closure so that $host, $dbname,
 * $username and $password stay local to it. They used to be plain globals, and
 * because this file is required at the top of almost every page, any script
 * that had its own variable by one of those names had it silently overwritten
 * the moment it required this file. Only $pdo escapes into the global scope.
 */

$pdo = (static function (): PDO {
    $host     = getenv('ROOMEASE_DB_HOST') ?: 'localhost';
    $dbname   = getenv('ROOMEASE_DB_NAME') ?: 'roomease';
    $username = getenv('ROOMEASE_DB_USER') ?: 'root';
    $password = getenv('ROOMEASE_DB_PASS') !== false ? getenv('ROOMEASE_DB_PASS') : '';

    try {
        return new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (PDOException $e) {
        // The driver message names the host, database and user, so it goes to
        // the error log rather than to whoever happened to load the page.
        error_log('RoomEase: database connection failed - ' . $e->getMessage());
        http_response_code(503);
        die('The site is temporarily unavailable. Please try again shortly.');
    }
})();
