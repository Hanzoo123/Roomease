<?php
/**
 * Step 2 of "Continue with Google": Google sends the visitor back here.
 *
 * The account is matched by Google account id first, then by the email Google
 * has verified; a matching RoomEase account is linked to the Google account.
 * With no match, someone who came from the sign-up page gets an account in the
 * role they picked there. Someone who came from the sign-in page has not said
 * whether they are a boarder or a landlord yet, so they are asked first, on
 * auth/google_finish.php. Deactivated and removed accounts are refused exactly
 * as the password login refuses them.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/core/google_auth.php';

// The attempt is single use: taken out of the session before anything else.
$pending = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);

$fromRegister = is_array($pending) && ($pending['from'] ?? '') === 'register';
$back = $fromRegister ? 'auth/register.php' : 'auth/login.php';

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

$profile = google_profile_from_claims($claims);
$remember = !empty($pending['remember']);

[$user, $error] = google_find_account($profile['google_id'], $profile['email']);
if ($error) {
    $fail($error);
}

$created = false;
if (!$user) {
    if (!$fromRegister) {
        // Nothing is created yet: the next page asks for the role first.
        $_SESSION['google_signup'] = $profile + ['remember' => $remember, 'started_at' => time()];
        redirect('auth/google_finish.php');
    }

    try {
        $user = google_create_account($profile, $pending['role']);
        $created = true;
    } catch (PDOException $e) {
        error_log('RoomEase: Google account creation failed - ' . $e->getMessage());
    }
    if (!$user) {
        $fail('Google sign-in could not be completed. Please try again.');
    }
}

$fail(google_sign_in($user, $remember, $created));
