<?php
/**
 * Transport- and session-level hardening for RoomEase.
 *
 * This file is required from the very top of includes/core/functions.php, BEFORE
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
 *
 * An administrator goes back to the administrators' sign-in page and everyone
 * else to the public one. $wasAdmin is taken from the session unless the
 * caller has already cleared the session and passes it in.
 */
function force_logout($message, $wasAdmin = null)
{
    if ($wasAdmin === null) {
        $wasAdmin = is_admin();
    }

    // A remembered device would otherwise sign straight back in on the next
    // request, which is exactly what force_logout exists to prevent.
    forget_remembered_login();
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
    redirect($wasAdmin ? ADMIN_LOGIN_PATH : 'auth/login.php');
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

    // No session, but a "Remember me" cookie: sign the device back in quietly.
    if (!is_logged_in() && !restore_remembered_login()) {
        return;
    }

    $now = time();
    $idle = isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT;
    $aged = isset($_SESSION['session_started_at']) && ($now - $_SESSION['session_started_at']) > SESSION_ABSOLUTE_LIFETIME;

    // A session that has aged out still ends. A remembered device then gets a
    // brand new session from its cookie; anyone else is signed out.
    if ($idle || $aged) {
        $wasAdmin = is_admin();
        $_SESSION = [];
        if (!restore_remembered_login()) {
            force_logout($idle
                ? 'You were signed out after 30 minutes of inactivity. Please log in again.'
                : 'Your session has expired. Please log in again.', $wasAdmin);
        }
        $now = time();
    }

    $_SESSION['last_activity'] = $now;
    if (!isset($_SESSION['session_started_at'])) {
        $_SESSION['session_started_at'] = $now;
    }

    // Pages that never touch the database (logout, the router) have no $pdo;
    // the ageing checks above still apply to them.
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT role, is_active, deleted_at, avatar_path FROM users WHERE user_id = ?');
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
    // left set. Either way every remembered device goes with the session.
    if (!empty($account['deleted_at'])) {
        forget_all_remembered_logins($_SESSION['user_id']);
        force_logout('That account has been removed. Please contact support.');
    }
    if (empty($account['is_active'])) {
        forget_all_remembered_logins($_SESSION['user_id']);
        force_logout('Your account has been deactivated. Please contact support.');
    }

    // The session is a cache of the account, never the source of truth for it.
    $_SESSION['role'] = $account['role'];
    $_SESSION['avatar_path'] = $account['avatar_path'];
}

/**
 * Begin a signed-in session for a user row (user_id, role, first_name,
 * last_name, email, and avatar_path where the caller selected it). Used by the
 * password login, Google sign-in, and "Remember me", so all three start a
 * session the same way: a brand new session id and nothing carried over from
 * whatever session came before.
 *
 * A caller that selected only the columns above still gets a working session:
 * the avatar falls back to null here and is filled in by the per-request
 * refresh above, which reads it from the account on the very next page.
 */
function start_user_session(array $user)
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['session_started_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['email'] = $user['email'];
    $_SESSION['avatar_path'] = $user['avatar_path'] ?? null;
}

/* ---------------------------------------------------------------------------
 * Remember me
 *
 * Ticking "Remember me" issues a cookie holding selector:validator. The
 * selector finds the row; only a SHA-256 hash of the validator is stored, so
 * the table alone cannot be replayed as a login. Every sign-in from a cookie
 * deletes its row and issues a new one, so a copied cookie stops working the
 * moment the real device uses it. Logging out, changing or resetting the
 * password, and deactivation all delete the rows.
 * ------------------------------------------------------------------------ */

/** How long "Remember me" keeps a device signed in. */
const REMEMBER_LIFETIME = 2592000; // 30 days

const REMEMBER_COOKIE = 'roomease_remember';

/** True when the remember_tokens table exists (database/boardinghouse.sql). */
function remember_available()
{
    global $pdo;
    static $available = null;

    if ($available === null) {
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return false;
        }
        try {
            $pdo->query('SELECT 1 FROM remember_tokens LIMIT 1');
            $available = true;
        } catch (PDOException $e) {
            $available = false;
            error_log('RoomEase: remember_tokens table missing - "Remember me" is OFF. '
                . 'Import database/boardinghouse.sql to enable it.');
        }
    }

    return $available;
}

function set_remember_cookie($value, $expires)
{
    if (headers_sent()) {
        return;
    }
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => app_cookie_path(),
        'domain'   => '',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($value === '') {
        unset($_COOKIE[REMEMBER_COOKIE]);
    } else {
        $_COOKIE[REMEMBER_COOKIE] = $value;
    }
}

/** Remember this device for the given user. */
function remember_login($userId)
{
    global $pdo;
    if (!remember_available()) {
        return;
    }

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_LIFETIME;

    try {
        $pdo->prepare(
            'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
             VALUES (?, ?, ?, FROM_UNIXTIME(?))'
        )->execute([$userId, $selector, hash('sha256', $validator), $expires]);

        // Opportunistic housekeeping so expired devices do not pile up.
        if (random_int(1, 20) === 1) {
            $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
        }
    } catch (PDOException $e) {
        error_log('RoomEase: could not remember a device - ' . $e->getMessage());
        return;
    }

    set_remember_cookie($selector . ':' . $validator, $expires);
}

/** Split a remember cookie into [selector, validator], or null if malformed. */
function parse_remember_cookie($cookie)
{
    $parts = explode(':', (string) $cookie);
    if (count($parts) !== 2
        || !preg_match('/^[a-f0-9]{24}$/', $parts[0])
        || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        return null;
    }
    return $parts;
}

/** Forget this device: delete its row and clear the cookie. */
function forget_remembered_login()
{
    global $pdo;
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie === '') {
        return;
    }

    $parts = parse_remember_cookie($cookie);
    if ($parts && remember_available()) {
        try {
            $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$parts[0]]);
        } catch (PDOException $e) {
            // The row expires on its own; clearing the cookie still matters.
        }
    }
    set_remember_cookie('', time() - 3600);
}

/** Forget every device remembered for a user. */
function forget_all_remembered_logins($userId)
{
    global $pdo;
    if (!remember_available()) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (PDOException $e) {
        error_log('RoomEase: could not forget remembered devices - ' . $e->getMessage());
    }
}

/**
 * Sign a visitor back in from their "Remember me" cookie. Returns true when
 * the cookie was valid and a session was started.
 */
function restore_remembered_login()
{
    global $pdo;
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || !remember_available()) {
        return false;
    }

    $parts = parse_remember_cookie($cookie);
    if (!$parts) {
        set_remember_cookie('', time() - 3600);
        return false;
    }
    [$selector, $validator] = $parts;

    try {
        $stmt = $pdo->prepare(
            'SELECT rt.token_id, rt.validator_hash, rt.expires_at > NOW() AS is_live,
                    u.user_id, u.role, u.first_name, u.last_name, u.email, u.is_active, u.deleted_at
               FROM remember_tokens rt
               JOIN users u ON u.user_id = rt.user_id
              WHERE rt.selector = ?'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }

    // No row: the token was already used or forgotten. The cookie is left
    // alone, because a parallel request may just have replaced it with a new
    // one, and clearing it here could race and throw that new cookie away.
    if (!$row) {
        return false;
    }

    // Right selector, wrong validator: someone is guessing at a real token.
    // Every device for the account is signed out rather than risk it.
    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        error_log('RoomEase: remember-me validator mismatch for user ' . (int) $row['user_id']
            . ' - all remembered devices for this account were forgotten.');
        forget_all_remembered_logins($row['user_id']);
        set_remember_cookie('', time() - 3600);
        return false;
    }

    // Single use, valid or not.
    $pdo->prepare('DELETE FROM remember_tokens WHERE token_id = ?')->execute([$row['token_id']]);

    if (!$row['is_live'] || !empty($row['deleted_at']) || empty($row['is_active'])) {
        set_remember_cookie('', time() - 3600);
        return false;
    }

    start_user_session($row);
    remember_login((int) $row['user_id']);
    return true;
}

/* ---------------------------------------------------------------------------
 * Throttling
 *
 * Login and password-reset are the two endpoints an outsider can hammer, and
 * neither costs an attacker anything to retry. Attempts are recorded in the
 * login_attempts table (database/boardinghouse.sql) and counted over a
 * rolling window.
 * ------------------------------------------------------------------------ */

/**
 * True when the throttle table is present. If the schema has not been
 * imported the app keeps working rather than locking everyone out, but it
 * says so in the PHP error log so the gap does not stay invisible.
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
                . 'Import database/boardinghouse.sql to enable it.');
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
