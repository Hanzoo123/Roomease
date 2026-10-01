<?php
/** /admin/: the dashboard if signed in as an admin, else the admin login. */
require __DIR__ . '/../includes/init.php';

if (is_logged_in() && is_admin()) {
    redirect('admin/dashboard.php');
} else {
    redirect(ADMIN_LOGIN_PATH);
}
