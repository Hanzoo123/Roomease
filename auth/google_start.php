<?php
/**
 * Step 1 of "Continue with Google": send the visitor to Google.
 *
 * ?role=landlord|boarder  role for an account Google sign-in creates (sign-up page)
 * ?remember=1             keep this device signed in afterwards
 * ?from=register          return to the sign-up page, not login, on failure
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
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
