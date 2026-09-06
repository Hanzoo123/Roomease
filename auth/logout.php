<?php
/**
 * RoomEase Admin Logout
 */
require __DIR__ . '/../includes/functions.php';

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

session_start();
flash_set('You have been logged out of the Admin Portal.', 'success');
redirect('index.php');
