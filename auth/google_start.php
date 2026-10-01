<?php
/**
 * Google sign-in step 1: send the visitor to Google.
 *   ?role=landlord|boarder  role for a new account
 *   ?remember=1             stay signed in
 *   ?from=register          on failure, go back to sign-up instead of login
 */
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/core/google_auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$from = ($_GET['from'] ?? '') === 'register' ? 'register' : 'login';

if (!google_enabled()) {
    flash_set('Google sign-in is not set up on this server yet. Please use your email and password.', 'error');
    redirect($from === 'register' ? 'auth/register.php' : 'auth/login.php');
}

header('Location: ' . google_begin($_GET['role'] ?? 'boarder', ($_GET['remember'] ?? '') === '1', $from));
exit;
