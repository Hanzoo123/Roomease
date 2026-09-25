<?php
/**
 * One way to read a setting, wherever it was set.
 *
 * Settings reach the application by two different routes, and neither one
 * sees the other. config/db.php loads the project's .env through phpdotenv,
 * which puts the values in $_ENV and leaves the process environment alone, so
 * getenv() never returns them. A real environment variable, set by the web
 * server or by whoever started PHP, arrives the other way round: getenv() has
 * it and $_ENV may not.
 *
 * Reading both here is what lets the same setting live in .env on a
 * development machine and in the environment on a deployed copy, without
 * every caller having to know the difference. Callers that got this wrong
 * failed silently, which is the reason this is now in one place.
 */

/** The value set for $key in .env or in the environment, or $default. */
function env_value($key, $default = '')
{
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $value = getenv($key);

    return $value !== false ? $value : $default;
}
