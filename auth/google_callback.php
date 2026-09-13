<?php
/**
 * Step 2 of "Continue with Google": Google sends the visitor back here.
 *
 * The account is matched by Google account id first, then by the email Google
 * has verified. A matching RoomEase account is linked to the Google account;
 * with no match a new account is created in the role chosen on the sign-up
 * page (boarder from the login page). Deactivated and removed accounts are
 * refused exactly as the password login refuses them.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/google_auth.php';

// The attempt is single use: taken out of the session before anything else.
$pending = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);

$back = (is_array($pending) && ($pending['from'] ?? '') === 'register') ? 'auth/register.php' : 'auth/login.php';

$fail = function ($message) use ($back) {
    flash_set($message, 'error');
    redirect($back);
};

if (is_logged_in()) {
    redirect('index.php');
}

$state = $_GET['state'] ?? '';
if (!is_array($pending) || !is_string($state) || !hash_equals($pending['state'], $state)
    || time() - (int) $pending['started_at'] > 600) {
    $fail('That Google sign-in expired or was not started here. Please try again.');
}

if (isset($_GET['error'])) {
    $fail('Google sign-in was cancelled.');
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '') {
    $fail('Google did not complete the sign-in. Please try again.');
}

if (!google_enabled()) {
    $fail('Google sign-in is not set up on this server yet. Please use your email and password.');
}

try {
    $claims = google_exchange_code($code, $pending['verifier']);
} catch (RuntimeException $e) {
    error_log('RoomEase: Google sign-in failed - ' . $e->getMessage());
    $fail('Google sign-in could not be completed. Please try again, or use your email and password.');
}

$googleId = $claims['sub'];
$email = mb_strtolower(trim($claims['email']));
$created = false;

$findByGoogle = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
$findByGoogle->execute([$googleId]);
$user = $findByGoogle->fetch();

if (!$user) {
    $findByEmail = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $findByEmail->execute([$email]);
    $user = $findByEmail->fetch();

    if ($user) {
        if ($user['google_id'] !== null && $user['google_id'] !== $googleId) {
            $fail('That email address is already linked to a different Google account.');
        }
        // Google has verified this address belongs to whoever just signed in,
        // which is the same proof a password reset email relies on.
        $pdo->prepare('UPDATE users SET google_id = ? WHERE user_id = ? AND google_id IS NULL')
            ->execute([$googleId, $user['user_id']]);
    }
}

if (!$user) {
    $first = trim((string) ($claims['given_name'] ?? ''));
    $last = trim((string) ($claims['family_name'] ?? ''));
    if ($first === '') {
        $first = trim((string) ($claims['name'] ?? '')) ?: strstr($email, '@', true);
    }

    // The account has no password anyone knows. Its owner signs in with
    // Google, or sets a password through "Forgot password".
    $pdo->prepare(
        'INSERT INTO users (role, first_name, last_name, email, google_id, password_hash, phone_number, is_active)
         VALUES (?, ?, ?, ?, ?, ?, NULL, 1)'
    )->execute([
        $pending['role'],
        mb_substr($first, 0, 100),
        mb_substr($last, 0, 100),
        $email,
        $googleId,
        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
    ]);

    $findByGoogle->execute([$googleId]);
    $user = $findByGoogle->fetch();
    $created = true;
}

if (!$user) {
    $fail('Google sign-in could not be completed. Please try again.');
}
if (!empty($user['deleted_at'])) {
    $fail('That account has been removed. Please contact support.');
}
if (empty($user['is_active'])) {
    $fail('Your account is deactivated. Please contact support.');
}

start_user_session($user);
if (!empty($pending['remember'])) {
    remember_login((int) $user['user_id']);
}

flash_set(($created ? 'Welcome to RoomEase, ' : 'Welcome back, ') . $user['first_name'] . '!', 'success');
redirect('index.php');
