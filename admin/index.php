<?php
/**
 * RoomEase Admin Index Entry
 */
require __DIR__ . '/../includes/functions.php';

if (is_logged_in() && is_admin()) {
    redirect('admin/dashboard.php');
} else {
    redirect('auth/login.php');
}
