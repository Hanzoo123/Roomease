<?php
/**
 * Password reset for administrator accounts, linked from the admin sign-in
 * page. It is the public reset page limited to administrators: only their
 * accounts are sent a link, and the page says the same thing either way.
 */
$resetScope = 'admin';
require __DIR__ . '/../auth/forgot_password.php';
