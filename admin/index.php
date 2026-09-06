<?php
/**
 * RoomEase Admin Index Entry
 */
require __DIR__ . '/../includes/functions.php';

if (is_logged_in() && current_role() === 'admin') {
    redirect('admin/dashboard.php');
} else {
    redirect('admin/login.php');
}
