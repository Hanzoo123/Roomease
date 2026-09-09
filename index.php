<?php
/**
 * RoomEase entry point. Sends each visitor to the right starting page:
 * admins to the admin panel, landlords to their listings, and everyone
 * else (including guests) to the public browse page.
 *
 * require_login() also lands here on a role mismatch, so this doubles as
 * the "you don't belong on that page" fallback.
 */
require __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    if (is_admin()) {
        redirect('admin/dashboard.php');
    }
    if (current_role() === 'landlord') {
        redirect('landlord/dashboard.php');
    }
}

redirect('boarder/browse.php');
