<?php
/**
 * RoomEase Admin Index Entry: the dashboard for a signed-in administrator,
 * otherwise the administrators' own sign-in page.
 */
require __DIR__ . '/../includes/functions.php';

if (is_logged_in() && is_admin()) {
    redirect('admin/dashboard.php');
} else {
    redirect(ADMIN_LOGIN_PATH);
}
