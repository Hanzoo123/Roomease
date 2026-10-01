<?php
/**
 * Everyday helpers: escaping output, redirects, URLs, flash messages, dates and money.
 */

/** Escape output for safe HTML display. */

function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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
        // Worked out from SCRIPT_NAME, the page being served, rather than from
        // where this file sits: the app root is the folder above auth/,
        // landlord/, boarder/, admin/ or legal/, or the page's own folder
        // otherwise. A new folder of pages has to be named here and in
        // app_cookie_path(), which scopes the session cookie the same way.
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
 * The last word on an exception nothing else caught. includes/init.php
 * registers it, so every page has it.
 *
 * Without it, a failure no page expected (a database hiccup, or two sign-ups
 * racing for one email) ends in a blank page. The details go to the PHP error
 * log and never to the visitor. A page that answers fetch() with JSON gets
 * JSON back, so its script can show the message. A transaction left open is
 * rolled back by MySQL when the script ends.
 */

function handle_uncaught_exception(Throwable $e)
{
    error_log('RoomEase: uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());

    $message = 'Something went wrong on our side, and nothing was saved from that last step. Please try again.';

    // Part of the page is already on its way, so the most that can be done
    // is to say so beneath it.
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

    // The 404 page's layout and styles (.notfound in style.css), so an error
    // looks like part of the site rather than a broken one.
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

/**
 * Peso with the centavos dropped when the amount is a whole number.
 *
 * Rents are whole pesos in practice, so a column of them all ending in ".00"
 * is two characters of noise on every row of the listing board. peso() keeps
 * the exact figure for anywhere a centavo could matter; this is for display.
 */

function peso_round($amount)
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $value = (float) $amount;
    $decimals = (abs($value - round($value)) < 0.005) ? 0 : 2;
    return '₱' . number_format($value, $decimals);
}

/* ---------------------------------------------------------------------------
 * Small display helpers for the public pages
 * ------------------------------------------------------------------------ */

/**
 * True when an uploaded photo is really on disk. A database row can outlive
 * its file, and a missing picture drawn as a broken image looks worse than
 * the placeholder that stands in for no picture at all.
 */

function photo_on_disk($path)
{
    $path = ltrim((string) $path, '/');
    return $path !== '' && is_file(dirname(__DIR__, 2) . '/' . $path);
}

/**
 * An address without the ", Baybay City, Leyte" every listing shares, for the
 * cards: on a phone the tail used to push the barangay itself out of view.
 */

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
 * The database's clock, as 'Y-m-d H:i:s', read once per request.
 *
 * PHP here runs on UTC (date.timezone in php.ini) while MySQL runs on the
 * machine's local time, eight hours ahead in the Philippines. A timestamp read
 * from the database is therefore only ever compared with this, never with
 * time(): mixing the two made an action taken a minute ago read "just now" for
 * eight hours, and put late-evening sign-ups on the wrong day.
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

/**
 * An absolute URL to a page of this site, which is what an email link needs,
 * and what a link-preview tag or the sitemap needs as well: a relative
 * address means nothing to a mail client, to Facebook or to a crawler.
 */

function absolute_url($path)
{
    $scheme = is_https_request() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url($path);
}

/**
 * The full address of the page being served, query string and all, which is
 * what og:url has to carry: a link preview names the page it was made from.
 */

function current_url()
{
    $scheme = is_https_request() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
}
