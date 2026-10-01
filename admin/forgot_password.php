<?php
/** Forgot password for admins: the public reset page, limited to admin accounts. */
$resetScope = 'admin';
require __DIR__ . '/../auth/forgot_password.php';
