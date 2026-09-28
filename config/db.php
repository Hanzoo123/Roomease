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
// Without `composer install` the site still runs on the defaults below, and
// safeLoad() (unlike load()) doesn't throw when there is no .env file.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}
unset($autoload);

$pdo = (static function (): PDO {
    $host     = env_value('ROOMEASE_DB_HOST', 'localhost');
    $dbname   = env_value('ROOMEASE_DB_NAME', 'roomease');
    $username = env_value('ROOMEASE_DB_USER', 'root');
    $password = env_value('ROOMEASE_DB_PASS', '');

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        // MySQL 8's own default mode, set here because WAMP ships with an
        // empty one. Without it a value too long for its column is silently
        // cut off and an out-of-range number silently capped; with it, as on
        // most hosting, the save fails and the mistake is seen. Setting it on
        // the connection makes every copy of RoomEase behave the same.
        $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,"
            . "NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    } catch (PDOException $e) {
        // The driver message names the host, database and user, so it goes to
        // the error log rather than to whoever happened to load the page.
        error_log('RoomEase: database connection failed - ' . $e->getMessage());
        http_response_code(503);
        die('The site is temporarily unavailable. Please try again shortly.');
    }
})();
