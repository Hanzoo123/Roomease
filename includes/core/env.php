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

/**
 * Read the project's .env into $_ENV without phpdotenv, for a copy where
 * `composer install` was never run. vendor/ is not in git, so a fresh clone
 * has no phpdotenv, and its .env used to be ignored without a word: settings
 * that worked on one computer silently fell back to the defaults on the next.
 *
 * Only plain KEY=VALUE lines are understood, which is all .env.example uses.
 * Like phpdotenv's immutable loader, a value already in the environment wins.
 */
function load_env_file($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strncmp($key, 'export ', 7) === 0) {
            $key = trim(substr($key, 7));
        }
        if ($key === '' || isset($_ENV[$key]) || getenv($key) !== false) {
            continue;
        }
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && strlen($value) > 1 && substr($value, -1) === $quote) {
            $value = substr($value, 1, -1);
        } else {
            // An unquoted value ends at a comment, as in phpdotenv.
            $value = rtrim(preg_replace('/\s+#.*$/', '', $value));
        }
        $_ENV[$key] = $value;
    }
}
