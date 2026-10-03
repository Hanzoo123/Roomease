<?php
/**
 * Database connection ($pdo). Credentials come from .env; the defaults below
 * are WAMP's, for local use. Built inside a function so only $pdo becomes global.
 */

require_once __DIR__ . '/../includes/core/env.php';

// Load .env if Composer is installed. Without it, the defaults below are used.
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
        // Strict mode: too-long or out-of-range values cause an error instead
        // of being silently cut. WAMP leaves this off by default.
        $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,"
            . "NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    } catch (PDOException $e) {
    http_response_code(503);
    // Temporarily show the raw error to find out why it's failing:
    die('Database Error: ' . $e->getMessage()); 
}

})();
