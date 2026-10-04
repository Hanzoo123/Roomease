<?php
/**
 * Everyday helpers: escaping output, redirects, URLs, flash messages, dates and money.
 */

/** Escape output for safe HTML display. */

function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Hidden inputs that send $fields (name => value) with a form, such as the
 * browse filters. A list, such as the ticked amenities, goes as name[].
 */

function hidden_fields(array $fields)
{
    $html = '';
    foreach ($fields as $name => $value) {
        foreach ((array) $value as $item) {
            $html .= '<input type="hidden" name="' . h($name) . (is_array($value) ? '[]' : '') . '" value="' . h($item) . '">';
        }
    }
    return $html;
}

/** Redirect to a given path relative to the app root and stop execution. */

function redirect($path)
{
    header('Location: ' . base_url($path));
    exit;
}

/** Build a URL relative to the app's base path, so it works in any subfolder. */

function base_url($path = '')
{
    static $base = null;
    if ($base === null) {
        // The app root is the folder above auth/, landlord/, etc. Add new
        // page folders here and in app_cookie_path().
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $appRoot = preg_replace('#/(auth|landlord|boarder|admin|legal)/[^/]*$#', '', $script);
        if ($appRoot === $script) {
            $appRoot = rtrim(dirname($script), '/');
        }
        $base = $appRoot;
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Handles any error no page caught: logs the details, and shows the visitor a
 * friendly "something went wrong" page (or JSON) instead of a blank one.
 */

function handle_uncaught_exception(Throwable $e)
{
    error_log('RoomEase: uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());

    $message = 'Something went wrong on our side, and nothing was saved from that last step. Please try again.';

    // Part of the page was already sent: add a message below it.
    if (headers_sent()) {
        echo '<p role="alert" style="margin:16px;padding:12px 16px;border:1px solid #c0392b;color:#1F2A28;">'
            . h($message) . '</p>';
        return;
    }

    http_response_code(500);
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'reload' => true, 'message' => $message]);
        return;
    }

    // Same look as the 404 page (.notfound in style.css).
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex"><title>Something went wrong · RoomEase</title>'
        . '<link rel="stylesheet" href="' . h(base_url('assets/css/style.css')) . '"></head><body>'
        . '<main class="notfound"><div class="notfound-inner">'
        . '<p class="notfound-code">Error</p>'
        . '<h1 class="notfound-title">Something went wrong</h1>'
        . '<p class="notfound-lede">' . h($message) . '</p>'
        . '<div class="notfound-actions">'
        . '<a class="btn btn-accent" href="' . h(base_url('boarder/browse.php')) . '">Browse rooms</a>'
        . '<a class="notfound-link" href="' . h(base_url('index.php')) . '">Go to the home page &rarr;</a>'
        . '</div></div></main></body></html>';
}

/** Flash message helpers (one-time messages shown after redirect). */

function flash_set($message, $type = 'success')
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_get()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/** Format a peso amount for display. */

function peso($amount)
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return '₱' . number_format((float) $amount, 2);
}

/** Like peso(), but drops ".00" from whole amounts (₱2,500 not ₱2,500.00). */

function peso_round($amount)
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $value = (float) $amount;
    $decimals = (abs($value - round($value)) < 0.005) ? 0 : 2;
    return '₱' . number_format($value, $decimals);
}

/** True when the photo file exists, so a missing file isn't shown as a broken image. */

function photo_on_disk($path)
{
    $path = ltrim((string) $path, '/');
    return $path !== '' && is_file(dirname(__DIR__, 2) . '/' . $path);
}

/** The address without ", Baybay City, Leyte", which every listing shares. For cards. */

function short_address($address)
{
    $short = preg_replace('/,\s*Baybay(\s+City)?(,\s*Leyte)?(,\s*Philippines)?\s*$/i', '', trim((string) $address));
    return $short !== '' ? $short : trim((string) $address);
}

/** "?, ?, ?" for an IN (...) list of $count values. */

function sql_placeholders($count)
{
    return implode(', ', array_fill(0, max(1, (int) $count), '?'));
}

/**
 * The database's current time ('Y-m-d H:i:s'). Compare database timestamps
 * with this, not time(): PHP runs on UTC but MySQL on Philippine time.
 */

function db_now()
{
    global $pdo;
    static $now = null;
    if ($now === null) {
        try {
            $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        } catch (PDOException $e) {
            $now = date('Y-m-d H:i:s');
        }
    }
    return $now;
}

/** "3 days ago", "just now": how long ago a database timestamp was. */

function time_ago($datetime)
{
    $seconds = max(0, strtotime(db_now()) - strtotime((string) $datetime));
    if ($seconds < 60) {
        return 'just now';
    }
    foreach ([['day', 86400], ['hour', 3600], ['minute', 60]] as [$unit, $size]) {
        if ($seconds >= $size) {
            $n = (int) floor($seconds / $size);
            return $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

/** A full URL (with http:// and host), for emails and link previews. */

function absolute_url($path)
{
    $scheme = is_https_request() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url($path);
}

/** The full URL of the current page, for link previews (og:url). */

function current_url()
{
    $scheme = is_https_request() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
}
