<?php
/**
 * Database connection.
 *
 * Credentials come from the project's .env file (read by vlucas/phpdotenv) or
 * from real environment variables, so a deployed copy never has to keep its
 * real password in a file that ships with the code. The values below are the
 * stock WAMP/XAMPP defaults and are only a fallback for local development.
 *
 * The connection settings are built inside a closure so that $host, $dbname,
 * $username and $password stay local to it. They used to be plain globals, and
 * because this file is required at the top of almost every page, any script
 * that had its own variable by one of those names had it silently overwritten
 * the moment it required this file. Only $pdo escapes into the global scope.
 */

// vendor/ and .env both sit in the project root, one level above config/.
// Without `composer install` the site still runs on the defaults below, and
// safeLoad() (unlike load()) doesn't throw when there is no .env file.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}
unset($autoload);

$pdo = (static function (): PDO {
    $env = static function (string $key, string $default): string {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        $value = getenv($key);
        return $value !== false ? $value : $default;
    };

    $host     = $env('ROOMEASE_DB_HOST', 'localhost');
    $dbname   = $env('ROOMEASE_DB_NAME', 'roomease');
    $username = $env('ROOMEASE_DB_USER', 'root');
    $password = $env('ROOMEASE_DB_PASS', '');

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
