<?php
/**
 * Transport- and session-level hardening for RoomEase.
 *
 * This file is required from the very top of includes/functions.php, BEFORE
 * session_start(), because cookie flags can only be chosen while there is no
 * session yet. Everything here applies itself: no page has to remember to call
 * it, so a new page cannot accidentally opt out of it.
 */

/** How long a session may sit idle before it is thrown away. */
const SESSION_IDLE_TIMEOUT = 1800;       // 30 minutes

/** How long a session may live at all, however active it is. */
const SESSION_ABSOLUTE_LIFETIME = 43200; // 12 hours

/** Failed logins allowed per email address before that account is paused. */
const LOGIN_MAX_PER_ACCOUNT = 5;

/** Failed logins allowed from one IP address before it is paused. */
const LOGIN_MAX_PER_IP = 20;

/** The window attempts are counted over, and the length of a lockout. */
const LOGIN_WINDOW_SECONDS = 900;        // 15 minutes

/** Password-reset requests allowed per email address per window. */
const RESET_MAX_PER_ACCOUNT = 3;

/** True when the current request arrived over HTTPS. */
function is_https_request()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Behind a reverse proxy the original scheme survives only in this header.
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * The URL path the app is served from, used to scope the session cookie so a
 * neighbouring app in the same webroot cannot read or overwrite it.
 */
function app_cookie_path()
{
    $script  = $_SERVER['SCRIPT_NAME'] ?? '/';
    $appRoot = preg_replace('#/(auth|landlord|boarder|admin)/[^/]*$#', '', $script);
    if ($appRoot === $script) {
        $appRoot = rtrim(dirname($script), '/');
    }
    return ($appRoot === '' ? '/' : $appRoot . '/');
}

/**
 * Cookie and session-id settings. Must run before session_start().
 *
 * use_strict_mode is the important one: without it PHP will adopt a session id
 * that a visitor invented, which is what makes session fixation possible in
 * the first place.
 */
function configure_session_security()
{
    if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_cookie_path(),
        'domain'   => '',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Response headers that limit what an injected string could do if one ever got
 * through. The policy still allows inline scripts and styles, because AdminLTE
 * and these pages are written that way, but it blocks loading or exfiltrating
 * through any third-party origin, and blocks <base> and plugin content outright.
 *
 * The one third-party origin is OpenStreetMap's tile server, and only as an
 * image source: the listing map and the landlord's pin picker draw its tiles.
 * Leaflet itself is served from assets/vendor, so no script leaves 'self'.
 */
function send_security_headers()
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header(
        'Content-Security-Policy: '
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self' data:; "
        . "img-src 'self' data: https://tile.openstreetmap.org; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'"
    );

    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Best-effort client IP. Only the direct peer is trusted; headers are not. */
function client_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Tear down the current session and send the visitor to the login page with an
 * explanation. Used when a session ages out or the account behind it stops
 * being valid.
 */
function force_logout($message)
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'],
            $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    flash_set($message, 'error');
    redirect('auth/login.php');
}

/**
 * Run on every request once a session exists.
 *
 * Two things happen here that a login-time check cannot do. Sessions age out,
 * idle and absolute, so a signed-in browser left open on a shared computer
 * stops being a way in. And the account is re-read from the database, so an
 * administrator deactivating a user, deleting them, or changing their role
 * takes effect on that user's very next click instead of whenever they happen
 * to log out.
 */
function enforce_session_policy()
{
    global $pdo;

    if (!is_logged_in()) {
        return;
    }

    $now = time();

    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        force_logout('You were signed out after 30 minutes of inactivity. Please log in again.');
    }
    $_SESSION['last_activity'] = $now;

    if (!isset($_SESSION['session_started_at'])) {
        $_SESSION['session_started_at'] = $now;
    } elseif (($now - $_SESSION['session_started_at']) > SESSION_ABSOLUTE_LIFETIME) {
        force_logout('Your session has expired. Please log in again.');
    }

    // Pages that never touch the database (logout, the router) have no $pdo;
    // the ageing checks above still apply to them.
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT role, is_active, deleted_at FROM users WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $account = $stmt->fetch();
    } catch (PDOException $e) {
        return; // A database blip must not sign the whole site out.
    }

    if (!$account) {
        force_logout('That account no longer exists.');
    }
    // An archived account is checked before the active flag, so archiving
    // takes effect on the user's very next request even if is_active was
    // left set.
    if (!empty($account['deleted_at'])) {
        force_logout('That account has been removed. Please contact support.');
    }
    if (empty($account['is_active'])) {
        force_logout('Your account has been deactivated. Please contact support.');
    }

    // The session is a cache of the account, never the source of truth for it.
    $_SESSION['role'] = $account['role'];
}

/* ---------------------------------------------------------------------------
 * Throttling
 *
 * Login and password-reset are the two endpoints an outsider can hammer, and
 * neither costs an attacker anything to retry. Attempts are recorded in the
 * login_attempts table (database/migration_login_throttle.sql) and counted
 * over a rolling window.
 * ------------------------------------------------------------------------ */

/**
 * True when the throttle table is present. If the migration has not been run
 * the app keeps working rather than locking everyone out, but it says so in
 * the PHP error log so the gap does not stay invisible.
 */
function throttle_available()
{
    global $pdo;
    static $available = null;

    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM login_attempts LIMIT 1');
            $available = true;
        } catch (PDOException $e) {
            $available = false;
            error_log('RoomEase: login_attempts table missing - brute-force throttling is OFF. '
                . 'Import database/migration_login_throttle.sql to enable it.');
        }
    }

    return $available;
}

/** Record one failed attempt against both the identifier and the caller's IP. */
function record_failed_attempt($kind, $identifier)
{
    global $pdo;
    if (!throttle_available()) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO login_attempts (kind, identifier, ip_address) VALUES (?, ?, ?)')
            ->execute([$kind, mb_strtolower(trim($identifier)), client_ip()]);

        // Opportunistic housekeeping so the table cannot grow without bound.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        }
    } catch (PDOException $e) {
        error_log('RoomEase: could not record a failed attempt - ' . $e->getMessage());
    }
}

/** Forget an identifier's failures, called after a successful login. */
function clear_failed_attempts($kind, $identifier)
{
    global $pdo;
    if (!throttle_available()) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE kind = ? AND identifier = ?')
            ->execute([$kind, mb_strtolower(trim($identifier))]);
    } catch (PDOException $e) {
        // Nothing useful to do here; the rows expire on their own.
    }
}

/**
 * Seconds the caller must wait, or 0 if they may proceed.
 *
 * Both the account and the source address are counted. The per-account limit
 * stops one password being guessed; the per-IP limit stops one attacker
 * spraying a single common password across many accounts, which would never
 * trip a per-account counter.
 */
function throttle_retry_after($kind, $identifier)
{
    global $pdo;
    if (!throttle_available()) {
        return 0;
    }

    $limits = [
        'login' => ['account' => LOGIN_MAX_PER_ACCOUNT, 'ip' => LOGIN_MAX_PER_IP],
        'reset' => ['account' => RESET_MAX_PER_ACCOUNT, 'ip' => LOGIN_MAX_PER_IP],
    ];
    $limit = $limits[$kind] ?? $limits['login'];

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS hits, UNIX_TIMESTAMP(MIN(attempted_at)) AS oldest
               FROM login_attempts
              WHERE kind = ? AND identifier = ? AND attempted_at > NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$kind, mb_strtolower(trim($identifier)), LOGIN_WINDOW_SECONDS]);
        $byAccount = $stmt->fetch();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS hits, UNIX_TIMESTAMP(MIN(attempted_at)) AS oldest
               FROM login_attempts
              WHERE kind = ? AND ip_address = ? AND attempted_at > NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$kind, client_ip(), LOGIN_WINDOW_SECONDS]);
        $byIp = $stmt->fetch();
    } catch (PDOException $e) {
        return 0;
    }

    $wait = 0;
    foreach ([[$byAccount, $limit['account']], [$byIp, $limit['ip']]] as $pair) {
        list($row, $max) = $pair;
        if ($row && (int) $row['hits'] >= $max && $row['oldest']) {
            $wait = max($wait, ((int) $row['oldest'] + LOGIN_WINDOW_SECONDS) - time());
        }
    }

    return max(0, $wait);
}

/** "3 minutes" / "45 seconds", for telling someone how long they must wait. */
function format_wait($seconds)
{
    if ($seconds >= 60) {
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    return max(1, (int) $seconds) . ' seconds';
}
