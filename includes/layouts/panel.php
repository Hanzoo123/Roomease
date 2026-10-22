<?php
/**
 * Panel settings. Admins and landlords share the same layout files; what
 * differs between the two roles (title, menu) is set here.
 */

// The page header, card header and field helpers every panel page uses. Loaded
// with the shell rather than page by page, so a new page gets them for free.
require_once __DIR__ . '/../components/panel_page_header.php';

/** Panel settings for the logged-in user: title, role label, home page and menu. */
function panel_config()
{
    if (is_admin()) {
        // Super admins get extra menu items. Hiding links isn't the security:
        // those pages check require_super_admin() themselves.
        $super = is_super_admin();
        $userPages = array_merge(
            $super ? [['url' => 'admin/all_users.php', 'icon' => 'fa-address-book', 'label' => 'All Users']] : [],
            [['url' => 'admin/manage_users.php', 'icon' => 'fa-users', 'label' => 'Manage Users', 'also' => ['user.php']]],
            $super ? [
                ['url' => 'admin/admins.php',   'icon' => 'fa-user-shield', 'label' => 'Administrators',
                 'also' => ['reset_admin_password.php']],
                ['url' => 'admin/add_user.php', 'icon' => 'fa-user-plus',   'label' => 'Add Admin'],
            ] : []
        );

        return [
            'name'  => 'Admin',
            'badge' => ['label' => $super ? 'Super admin' : 'Administrator'],
            'home'   => 'admin/dashboard.php',
            'menu'  => array_merge([
                ['url' => 'admin/dashboard.php',       'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
                // 'children' makes a group that opens to show its pages, and
                // stays open while one of them is the current page.
                ['icon' => 'fa-users-cog', 'label' => 'User Management', 'children' => $userPages],
                // A listing's review page and an account's page stay under their list.
                ['url' => 'admin/manage_listings.php', 'icon' => 'fa-home',           'label' => 'Manage Listings',
                 'also' => ['listing.php']],
                // 'count' puts a number beside the item, shown only when it is above zero.
                ['url' => 'admin/manage_listings.php?status=pending', 'icon' => 'fa-clipboard-check', 'label' => 'Pending Approvals',
                 'count' => pending_listing_count()],
                ['url' => 'admin/reports.php',         'icon' => 'fa-chart-bar',      'label' => 'Reports'],
                ['url' => 'admin/extras.php',          'icon' => 'fa-bolt',           'label' => 'Utilities & Amenities'],
            ], $super ? [
                // The audit log shows what every administrator did, so it is
                // for super admins only (admin/activity.php checks as well).
                ['url' => 'admin/activity.php',   'icon' => 'fa-history',     'label' => 'Audit Log'],
                ['url' => 'admin/appearance.php', 'icon' => 'fa-paint-brush', 'label' => 'Appearance'],
            ] : []),
        ];
    }

    // Listings an administrator sent back, counted beside Needs Changes, and
    // room requests waiting for an answer, beside Reservations.
    $listingCounts = landlord_listing_counts($_SESSION['user_id'] ?? 0);
    $reservationCounts = landlord_reservation_counts($_SESSION['user_id'] ?? 0);

    return [
        'name'  => 'Landlord',
        'badge' => ['label' => 'Landlord'],
        'home'   => 'landlord/dashboard.php',
        'menu'  => [
            ['url' => 'landlord/dashboard.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
            // Everything a landlord does with their listings, in one group.
            ['icon' => 'fa-home', 'label' => 'Manage Listings', 'children' => [
                // Editing a listing or one of its rooms stays under this item.
                ['url' => 'landlord/listings.php',    'icon' => 'fa-list-ul',     'label' => 'My Boarding Houses',
                 'also' => ['edit_listing.php', 'room_form.php']],
                ['url' => 'landlord/add_listing.php', 'icon' => 'fa-plus-square', 'label' => 'Add Listing'],
                ['url' => 'landlord/listings.php?status=rejected', 'icon' => 'fa-exclamation-triangle', 'label' => 'Needs Changes',
                 'count' => $listingCounts['rejected']],
                ['url' => 'landlord/extras.php',      'icon' => 'fa-bolt',        'label' => 'Utilities & Amenities'],
            ]],
            ['url' => 'landlord/reservations.php', 'icon' => 'fa-calendar-check', 'label' => 'Reservations',
             'count' => $reservationCounts['pending']],
        ],
    ];
}


/**
 * What is waiting on the signed-in person, for the bell in the top bar
 * (panel_navbar.php): 'count', the number on the bell, which counts only what
 * this person can act on, and 'groups', each a title, its items (label, meta,
 * link) and an optional 'more' link to the full list. Administrators get the
 * review queue and the listings that need attention; landlords get room
 * requests and the listings an administrator sent back.
 */
function panel_notifications()
{
    $groups = [];
    $count = 0;

    if (is_admin()) {
        $pending = pending_listing_count();
        $count = $pending;

        $items = [];
        foreach (admin_review_queue(5) as $l) {
            $items[] = [
                'label' => $l['name'],
                'meta'  => trim($l['landlord_name']) . ' · waiting ' . preg_replace('/ ago$/', '', time_ago($l['updated_at'])),
                'link'  => 'admin/listing.php?id=' . (int) $l['boarding_house_id'],
            ];
        }
        if ($items) {
            $groups[] = [
                'title' => 'Waiting for your review',
                'items' => $items,
                'more'  => $pending > count($items)
                    ? ['label' => 'See all ' . $pending . ' pending', 'link' => 'admin/manage_listings.php?status=pending']
                    : null,
            ];
        }

        $a = admin_attention_counts();
        $items = [];
        if ($a['approved_hidden']) {
            $items[] = [
                'label' => $a['approved_hidden'] . ' approved ' . ($a['approved_hidden'] == 1 ? 'listing' : 'listings') . ' boarders cannot see',
                'meta'  => 'No room yet, or the landlord is deactivated',
                'link'  => 'admin/manage_listings.php?status=approved',
            ];
        }
        if ($a['rejected']) {
            $items[] = [
                'label' => $a['rejected'] . ' rejected ' . ($a['rejected'] == 1 ? 'listing' : 'listings'),
                'meta'  => 'Waiting on the landlord to fix',
                'link'  => 'admin/manage_listings.php?status=rejected',
            ];
        }
        if ($a['no_pin']) {
            $items[] = [
                'label' => $a['no_pin'] . ' approved without a map pin',
                'meta'  => 'Left out of Find places near me',
                'link'  => 'admin/manage_listings.php?status=approved',
            ];
        }
        if ($items) {
            $groups[] = ['title' => 'Needs attention', 'items' => $items, 'more' => null];
        }
    } elseif (current_role() === 'landlord') {
        $landlordId = (int) ($_SESSION['user_id'] ?? 0);
        $today = substr(db_now(), 0, 10);
        $requestCount = landlord_reservation_counts($landlordId)['pending'];

        $items = [];
        foreach (landlord_reservations($landlordId, 'pending', 5) as $r) {
            $items[] = [
                'label' => $r['boarder_name'] . ' wants ' . $r['room_name'],
                'meta'  => $r['house_name'] . ' · ' . ($r['move_in_date'] < $today
                    ? 'the move-in date has passed'
                    : 'moving in ' . date('M j', strtotime($r['move_in_date']))),
                'link'  => 'landlord/reservations.php?status=pending#request-' . (int) $r['reservation_id'],
            ];
        }
        if ($items) {
            $groups[] = [
                'title' => 'Room requests',
                'items' => $items,
                'more'  => $requestCount > count($items)
                    ? ['label' => 'See all ' . $requestCount . ' requests', 'link' => 'landlord/reservations.php?status=pending']
                    : null,
            ];
        }

        $sentBack = landlord_listings($landlordId, 'rejected');
        $items = [];
        foreach ($sentBack as $l) {
            $items[] = [
                'label' => $l['name'],
                'meta'  => 'Needs changes: ' . mb_strimwidth((string) ($l['rejection_reason'] ?: 'open it to see why'), 0, 70, '…'),
                'link'  => 'landlord/edit_listing.php?id=' . (int) $l['boarding_house_id'],
            ];
        }
        if ($items) {
            $groups[] = ['title' => 'Sent back to you', 'items' => $items, 'more' => null];
        }

        $count = $requestCount + count($sentBack);
    }

    return ['count' => $count, 'groups' => $groups];
}
