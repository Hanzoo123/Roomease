<?php
/**
 * Reads settings from .env ($_ENV) or from real environment variables
 * (getenv()). Each one misses the other, so always use env_value().
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
