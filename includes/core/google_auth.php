<?php
/**
 * "Continue with Google" — OpenID Connect sign-in without a library.
 *
 * Flow: auth/google_start.php sends the visitor to Google with a random state
 * and a PKCE challenge kept in the session. Google sends them back to
 * auth/google_callback.php with a one-time code, which is exchanged for an ID
 * token over a direct, certificate-checked HTTPS request to Google.
 *
 * Credentials never live in a tracked file. They come from the environment
 * (ROOMEASE_GOOGLE_CLIENT_ID / ROOMEASE_GOOGLE_CLIENT_SECRET), or from
 * config/google.local.php, which .gitignore excludes; copy
 * config/google.local.example.php to start. With neither, the Google button
 * still shows, and auth/google_start.php answers it with "not set up yet".
 */

function google_config()
{
    static $config = null;

    if ($config === null) {
        $config = [
            'client_id'     => (string) (getenv('ROOMEASE_GOOGLE_CLIENT_ID') ?: ''),
            'client_secret' => (string) (getenv('ROOMEASE_GOOGLE_CLIENT_SECRET') ?: ''),
            'ca_bundle'     => '',
        ];

        $local = __DIR__ . '/../../config/google.local.php';
        if (is_file($local)) {
            $file = require $local;
            if (is_array($file)) {
                foreach (['client_id', 'client_secret', 'ca_bundle'] as $key) {
                    if ($config[$key] === '' && isset($file[$key]) && is_string($file[$key])) {
                        $config[$key] = trim($file[$key]);
                    }
                }
            }
        }
    }

    return $config;
}

/** True once a client id and secret are configured. */
function google_enabled()
{
    $config = google_config();
    return $config['client_id'] !== '' && $config['client_secret'] !== '';
}

/**
 * The callback URL. It must match an "Authorized redirect URI" in the Google
 * Cloud console exactly, scheme and path included.
 */
function google_redirect_uri()
{
    $scheme = is_https_request() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_url('auth/google_callback.php');
}

function base64url_encode($bytes)
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64url_decode($text)
{
    $text = strtr((string) $text, '-_', '+/');
    return (string) base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
}

/**
 * Remember this attempt in the session and return the Google URL to send the
 * visitor to. $role applies only if Google sign-in ends up creating a new
 * account; $from is the page to return to if anything goes wrong.
 */
function google_begin($role, $remember, $from)
{
    $verifier = base64url_encode(random_bytes(48));
    $state = bin2hex(random_bytes(24));

    $_SESSION['google_oauth'] = [
        'state'      => $state,
        'verifier'   => $verifier,
        'role'       => in_array($role, ['boarder', 'landlord'], true) ? $role : 'boarder',
        'remember'   => (bool) $remember,
        'from'       => $from === 'register' ? 'register' : 'login',
        'started_at' => time(),
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'             => google_config()['client_id'],
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'code_challenge'        => base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ]);
}

/**
 * POST a form to Google over HTTPS with certificate checks on. Returns
 * ['status' => int, 'body' => string]. Throws RuntimeException if the request
 * could not be made at all.
 */
function google_http_post($url, array $fields)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('the PHP curl extension is not enabled');
    }

    $options = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];

    // A stock WAMP php.ini names no CA bundle, which makes every HTTPS call
    // fail certificate checks. The operating system's certificate store is
    // used instead, unless config/google.local.php names a bundle file.
    $caBundle = google_config()['ca_bundle'];
    if ($caBundle !== '') {
        $options[CURLOPT_CAINFO] = $caBundle;
    } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);

    if ($body === false) {
        throw new RuntimeException('request to Google failed: ' . curl_error($ch));
    }

    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string) $body];
}

/**
 * Exchange the code from the callback for the signed-in Google account's
 * verified claims (sub, email, given_name, family_name, ...).
 */
function google_exchange_code($code, $verifier)
{
    $config = google_config();

    $response = google_http_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
        'code_verifier' => $verifier,
    ]);

    $data = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($data) || empty($data['id_token'])) {
        // Google's error body names the problem (redirect_uri_mismatch,
        // invalid_client, ...) and holds no secret, so it goes to the log.
        throw new RuntimeException('token endpoint answered HTTP ' . $response['status'] . ': '
            . substr($response['body'], 0, 300));
    }

    return google_id_token_claims($data['id_token'], $config['client_id']);
}

/**
 * Read and check the claims in an ID token.
 *
 * The token arrives straight from Google's token endpoint over an HTTPS
 * connection whose certificate was verified, and OpenID Connect Core 1.0
 * (section 3.1.3.7) allows that TLS check to stand in for verifying the
 * token's signature in exactly this case. Every claim that matters is still
 * checked here.
 */
function google_id_token_claims($jwt, $clientId)
{
    $parts = explode('.', (string) $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('ID token is not a JWT');
    }

    $claims = json_decode(base64url_decode($parts[1]), true);
    if (!is_array($claims)) {
        throw new RuntimeException('ID token payload is not JSON');
    }
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
        throw new RuntimeException('ID token has the wrong issuer');
    }
    if (($claims['aud'] ?? '') !== $clientId) {
        throw new RuntimeException('ID token was issued for a different client');
    }
    if ((int) ($claims['exp'] ?? 0) < time()) {
        throw new RuntimeException('ID token has expired');
    }
    if (empty($claims['sub']) || !is_string($claims['sub']) || empty($claims['email'])) {
        throw new RuntimeException('ID token has no account id or email');
    }
    if (($claims['email_verified'] ?? false) !== true && ($claims['email_verified'] ?? '') !== 'true') {
        throw new RuntimeException('Google has not verified this email address');
    }

    return $claims;
}

/** How long the "One more step" page keeps a new Google account waiting. */
const GOOGLE_SIGNUP_TTL = 900; // 15 minutes

/** The parts of a Google sign-in a RoomEase account is made from. */
function google_profile_from_claims(array $claims)
{
    $email = mb_strtolower(trim($claims['email']));
    $first = trim((string) ($claims['given_name'] ?? ''));
    $last = trim((string) ($claims['family_name'] ?? ''));
    if ($first === '') {
        $first = trim((string) ($claims['name'] ?? '')) ?: strstr($email, '@', true);
    }

    return ['google_id' => $claims['sub'], 'email' => $email, 'first_name' => $first, 'last_name' => $last];
}

/**
 * The RoomEase account a Google sign-in belongs to. Returns [$user, $error]:
 * $user is null when there is no account yet, and $error is set when there is
 * one that this Google account must not be let into.
 *
 * Matched by Google account id first, then by the email Google has verified.
 * An account found by email is linked to the Google account on the way.
 */
function google_find_account($googleId, $email)
{
    global $pdo;

    $byGoogle = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $byGoogle->execute([$googleId]);
    $user = $byGoogle->fetch();
    if ($user) {
        return [$user, null];
    }

    $byEmail = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $byEmail->execute([$email]);
    $user = $byEmail->fetch();
    if (!$user) {
        return [null, null];
    }

    if ($user['google_id'] !== null && $user['google_id'] !== $googleId) {
        return [null, 'That email address is already linked to a different Google account.'];
    }

    // Google has verified this address belongs to whoever just signed in,
    // which is the same proof a password reset email relies on.
    $pdo->prepare('UPDATE users SET google_id = ? WHERE user_id = ? AND google_id IS NULL')
        ->execute([$googleId, $user['user_id']]);
    $user['google_id'] = $googleId;

    return [$user, null];
}

/**
 * Create the account for a Google sign-in and return its row. It has no
 * password anyone knows: its owner signs in with Google, or sets one from
 * their profile. Throws PDOException if the email was taken in the meantime.
 */
function google_create_account(array $profile, $role, $phone = null)
{
    global $pdo;

    $pdo->prepare(
        'INSERT INTO users (role, first_name, last_name, email, google_id, password_hash, phone_number, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        in_array($role, ['boarder', 'landlord'], true) ? $role : 'boarder',
        mb_substr($profile['first_name'], 0, 100),
        mb_substr($profile['last_name'], 0, 100),
        $profile['email'],
        $profile['google_id'],
        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        $phone,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
    $stmt->execute([(int) $pdo->lastInsertId()]);
    return $stmt->fetch();
}

/**
 * Sign in to the account a Google sign-in resolved to and go to its home
 * page. Deactivated and removed accounts are refused exactly as the password
 * login refuses them: the reason is returned and nothing else happens.
 */
function google_sign_in(array $user, $remember, $created)
{
    if (!empty($user['deleted_at'])) {
        return 'That account has been removed. Please contact support.';
    }
    if (empty($user['is_active'])) {
        return 'Your account is deactivated. Please contact support.';
    }

    start_user_session($user);
    if ($remember) {
        remember_login((int) $user['user_id']);
    }

    flash_set(($created ? 'Welcome to RoomEase, ' : 'Welcome back, ') . $user['first_name'] . '!', 'success');
    redirect('index.php');
}

/** The Google "G", for the sign-in button. */
function google_logo_svg($size = 20)
{
    $size = (int) $size;
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/>'
        . '<path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>'
        . '<path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2A11.9 11.9 0 0 1 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/>'
        . '<path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3a12 12 0 0 1-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/>'
        . '</svg>';
}
