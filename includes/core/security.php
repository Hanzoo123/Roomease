<?php
/**
 * Session and security settings: cookies, headers, session timeouts,
 * "Remember me" and sign-in throttling. Loaded by includes/init.php before
 * the session starts.
 */

const SESSION_IDLE_TIMEOUT = 1800;       // 30 minutes idle signs you out
const SESSION_ABSOLUTE_LIFETIME = 43200; // 12 hours at most per session

const LOGIN_MAX_PER_ACCOUNT = 5;         // failed logins per account...
const LOGIN_MAX_PER_IP = 20;             // ...and per IP address...
const LOGIN_WINDOW_SECONDS = 900;        // ...within 15 minutes, then a pause

const RESET_MAX_PER_ACCOUNT = 3;         // reset codes per email per 15 minutes

/** True when the current request arrived over HTTPS. */
function is_https_request()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Behind a proxy, only this header says the request was HTTPS.
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * The app's URL path, e.g. /roomease/. Cookies are limited to it so other
 * apps on the same server can't read them.
 */
function app_cookie_path()
{
    // Same folder list as base_url(). Add new page folders to both.
    $script  = $_SERVER['SCRIPT_NAME'] ?? '/';
    $appRoot = preg_replace('#/(auth|landlord|boarder|admin|legal)/[^/]*$#', '', $script);
    if ($appRoot === $script) {
        $appRoot = rtrim(dirname($script), '/');
    }
    return ($appRoot === '' ? '/' : $appRoot . '/');
}

/**
 * Session cookie settings. Must run before session_start().
 * use_strict_mode stops PHP accepting a made-up session id (session fixation).
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
 * Security headers sent with every page. The Content-Security-Policy only
 * allows files from this site, plus OpenStreetMap map tiles as images.
 * Inline scripts are allowed because AdminLTE and our pages use them.
 * The browser may ask for the visitor's location on RoomEase's own pages
 * (the landlord's "Use my current location"), never inside a frame from
 * another site; camera, microphone and payment stay off.
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
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=(), payment=()');
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

/** The visitor's IP. Headers like X-Forwarded-For are ignored: they can be faked. */
function client_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * End the session and send the visitor to the right login page with $message.
 * Pass $wasAdmin if the session was already cleared.
 */
function force_logout($message, $wasAdmin = null)
{
    if ($wasAdmin === null) {
        $wasAdmin = is_admin();
    }

    // Otherwise "Remember me" would sign them straight back in.
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
 * Runs on every request. Times out old sessions, and re-reads the account
 * from the database, so deactivating a user, changing their role or changing
 * their password takes effect on their very next click.
 */
function enforce_session_policy()
{
    global $pdo;

    // Not signed in, but has a "Remember me" cookie: sign them back in.
    if (!is_logged_in() && !restore_remembered_login()) {
        return;
    }

    $now = time();
    $idle = isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT;
    $aged = isset($_SESSION['session_started_at']) && ($now - $_SESSION['session_started_at']) > SESSION_ABSOLUTE_LIFETIME;

    // Timed out: a remembered device gets a fresh session, anyone else is signed out.
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

    // No database (a script run outside the site): only the timeouts apply.
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT role, is_active, deleted_at, avatar_path, password_hash,
                    first_name, last_name, email, username
               FROM users WHERE user_id = ?'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $account = $stmt->fetch();
    } catch (PDOException $e) {
        return; // A database hiccup should not sign everyone out.
    }

    if (!$account) {
        force_logout('That account no longer exists.');
    }
    // Removed or deactivated: sign out, on every remembered device too.
    if (!empty($account['deleted_at'])) {
        forget_all_remembered_logins($_SESSION['user_id']);
        force_logout('That account has been removed. Please contact support.');
    }
    if (empty($account['is_active'])) {
        forget_all_remembered_logins($_SESSION['user_id']);
        force_logout('Your account has been deactivated. Please contact support.');
    }

    // Password changed since this session signed in: sign it out. The device
    // that made the change saved the new fingerprint, so it stays signed in.
    $fingerprint = password_fingerprint($account['password_hash']);
    if (!isset($_SESSION['password_fingerprint'])) {
        $_SESSION['password_fingerprint'] = $fingerprint;
    } elseif (!hash_equals($_SESSION['password_fingerprint'], $fingerprint)) {
        force_logout('Your password was changed, so you were signed out. Please log in again.');
    }

    // Refresh the session from the database, which is always the truth.
    $_SESSION['role'] = $account['role'];
    $_SESSION['full_name'] = account_display_name($account);
    $_SESSION['email'] = (string) ($account['email'] ?? '');
    $_SESSION['username'] = $account['username'] ?? null;
    $_SESSION['profile_incomplete'] = is_admin_role($account['role'])
        && (trim((string) $account['first_name']) === '' || trim((string) $account['last_name']) === ''
            || (string) ($account['email'] ?? '') === '');
    $_SESSION['avatar_path'] = $account['avatar_path'];

    // Separate query, so an older database without this column only loses this check.
    $_SESSION['must_change_password'] = is_admin_role($account['role'])
        && account_must_change_password($_SESSION['user_id']);
}

/**
 * A fingerprint of the password hash, kept in the session to notice a password
 * change. Not the hash itself, so a leaked session file can't be used to guess
 * the password.
 */
function password_fingerprint($passwordHash)
{
    return hash('sha256', $passwordHash);
}

/**
 * Sign a user in: a new session id, with nothing kept from the old session.
 * Used by password login, Google sign-in and "Remember me".
 *
 * $user['password_hash'] must be the current hash, or the session is signed
 * out on the next page (see enforce_session_policy()).
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
    $_SESSION['full_name'] = account_display_name($user);
    $_SESSION['email'] = (string) ($user['email'] ?? '');
    $_SESSION['username'] = $user['username'] ?? null;
    $_SESSION['avatar_path'] = $user['avatar_path'] ?? null;
    $_SESSION['password_fingerprint'] = isset($user['password_hash'])
        ? password_fingerprint($user['password_hash'])
        : null;
}

/* ---------------------------------------------------------------------------
 * Remember me
 *
 * The cookie holds selector:validator. Only a hash of the validator is stored,
 * so a leaked table can't be used to sign in. Each use replaces the token, so
 * a copied cookie stops working once the real device uses it.
 * ------------------------------------------------------------------------ */

const REMEMBER_LIFETIME = 2592000; // 30 days

const REMEMBER_COOKIE = 'roomease_remember';

/** True when the remember_tokens table exists (database/roomease.sql). */
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
                . 'Import database/roomease.sql to enable it.');
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

        // Now and then, clear out expired tokens.
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
            // The row expires anyway; the cookie is still cleared below.
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

/** Sign a visitor back in from their "Remember me" cookie. True on success. */
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
                    u.user_id, u.role, u.first_name, u.last_name, u.email, u.password_hash,
                    u.is_active, u.deleted_at
               FROM remember_tokens rt
               JOIN users u ON u.user_id = rt.user_id
              WHERE rt.selector = ?'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }

    // Token already used. The cookie is left alone: another request may have
    // just replaced it with a new one.
    if (!$row) {
        return false;
    }

    // Right selector, wrong validator: someone may be guessing. Sign out every device.
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
    audit_log('signin', $row['user_id'], $row['email'], 'Remembered device');
    remember_login((int) $row['user_id']);
    return true;
}

/* ---------------------------------------------------------------------------
 * Throttling: limits on failed logins and reset requests, counted in the
 * login_attempts table over the last 15 minutes.
 * ------------------------------------------------------------------------ */

/** True when the login_attempts table exists. If not, throttling is off and the error log says so. */
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
                . 'Import database/roomease.sql to enable it.');
        }
    }

    return $available;
}

/** Record one failed attempt for this email (or username) and this IP. */
function record_failed_attempt($kind, $identifier)
{
    global $pdo;
    if (!throttle_available()) {
        return;
    }
    // Cut to the column's 190 characters, so a very long typed value is still counted.
    $identifier = mb_substr(mb_strtolower(trim($identifier)), 0, 190);
    try {
        $pdo->prepare(
            'INSERT INTO login_attempts (kind, identifier, user_id, ip_address)
             VALUES (?, ?, (SELECT u.user_id FROM users u WHERE u.email = ? OR u.username = ? LIMIT 1), ?)'
        )->execute([$kind, $identifier, $identifier, $identifier, client_ip()]);

        // Now and then, delete attempts older than a day.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        }
    } catch (PDOException $e) {
        error_log('RoomEase: could not record a failed attempt - ' . $e->getMessage());
    }
}

/** Clear the failed attempts for this email, after a successful login. */
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
        // Not important: old rows are cleared anyway.
    }
}

/**
 * Seconds to wait before trying again, or 0. Counts per account (stops
 * guessing one password) and per IP (stops one person trying many accounts).
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

/* ---------------------------------------------------------------------------
 * Checking passwords on the sign-in pages
 *
 * An unknown email is checked against a dummy hash, so it takes as long as a
 * real one. Otherwise the response time would reveal which emails exist.
 * ------------------------------------------------------------------------ */

/** Dummy hashes of thrown-away passwords, one per bcrypt cost (10 before PHP 8.4, 12 after). */
const DUMMY_PASSWORD_HASHES = [
    10 => '$2y$10$JUoyE50X7TaGZNYaVHc7BOpQUUE9r/XMmsHGJ.Wl8iCOdp.ui2/hS',
    12 => '$2y$12$k3ieTaYZzIOp/UNoS2s84eqsAafSe7Qm54VLUGyZ.n0TzYns0xTau',
];

/** True if $password matches $user. No user: false, after the same delay. */
function check_login_password($password, $user)
{
    if ($user) {
        return password_verify($password, $user['password_hash']);
    }
    $dummy = DUMMY_PASSWORD_HASHES[PASSWORD_BCRYPT_DEFAULT_COST] ?? DUMMY_PASSWORD_HASHES[12];
    password_verify($password, $dummy);
    return false;
}

/**
 * After a successful login, re-hash the password if it uses an older bcrypt
 * cost, so every account takes the same time to check. Returns the hash to
 * pass to start_user_session(). Side effect: other open sessions of the
 * account are signed out, as if the password had changed.
 */
function upgrade_password_hash(array $user, $password)
{
    global $pdo;
    if (!password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        return $user['password_hash'];
    }
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        // Not a real edit, so updated_at is kept as it was.
        $update = $pdo->prepare(
            'UPDATE users SET password_hash = ?, updated_at = updated_at WHERE user_id = ? AND password_hash = ?'
        );
        $update->execute([$newHash, $user['user_id'], $user['password_hash']]);
        if ($update->rowCount() === 1) {
            return $newHash;
        }
    } catch (PDOException $e) {
        error_log('RoomEase: could not upgrade a password hash - ' . $e->getMessage());
    }
    return $user['password_hash'];
}

/** "3 minutes" or "45 seconds". */
function format_wait($seconds)
{
    if ($seconds >= 60) {
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    return max(1, (int) $seconds) . ' seconds';
}
