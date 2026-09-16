<?php
/**
 * RoomEase logout, for every role.
 *
 * The database is needed only to forget this device's "Remember me" token;
 * without that, the cookie would sign the visitor straight back in.
 *
 * An administrator lands back on the administrators' sign-in page; everyone
 * else goes to the home page.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

$wasAdmin = is_admin();
forget_remembered_login();

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
session_destroy();

session_start();
flash_set('You have been logged out.', 'success');
redirect($wasAdmin ? ADMIN_LOGIN_PATH : 'index.php');
