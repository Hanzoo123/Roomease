<?php
/**
 * Database connection.
 *
 * Credentials come from the project's .env file (read by vlucas/phpdotenv) or
 * from real environment variables, so a deployed copy never has to keep its
 * real password in a file that ships with the code. Either route is read by
 * env_value() in includes/core/env.php, which the mail and Google settings
 * use as well. The values below are the stock WAMP/XAMPP defaults and are
 * only a fallback for local development.
 *
 * The connection settings are built inside a closure so that $host, $dbname,
 * $username and $password stay local to it. They used to be plain globals, and
 * because this file is required at the top of almost every page, any script
 * that had its own variable by one of those names had it silently overwritten
 * the moment it required this file. Only $pdo escapes into the global scope.
 */

require_once __DIR__ . '/../includes/core/env.php';

// vendor/ and .env both sit in the project root, one level above config/.
// safeLoad() (unlike load()) doesn't throw when there is no .env file.
// Without `composer install` the .env is still read, by load_env_file().
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
} else {
    load_env_file(dirname(__DIR__) . '/.env');
}
unset($autoload);

$pdo = (static function (): PDO {
    $host     = env_value('ROOMEASE_DB_HOST', 'localhost');
    // Empty means the driver's default, 3306. WAMP runs MariaDB on 3307 when
    // MySQL holds 3306, so a database imported through phpMyAdmin's "MariaDB"
    // server needs ROOMEASE_DB_PORT=3307.
    $port     = env_value('ROOMEASE_DB_PORT', '');
    $dbname   = env_value('ROOMEASE_DB_NAME', 'roomease');
    $username = env_value('ROOMEASE_DB_USER', 'root');
    $password = env_value('ROOMEASE_DB_PASS', '');

    try {
        return new PDO(
            "mysql:host=$host;" . ($port !== '' ? "port=$port;" : '') . "dbname=$dbname;charset=utf8mb4",
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
