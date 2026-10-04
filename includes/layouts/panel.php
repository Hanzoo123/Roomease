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
                ['url' => 'admin/add_user.php', 'icon' => 'fa-user-plus',   'label' => 'Add User'],
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

    // Listings an administrator sent back, counted beside Needs Changes.
    $listingCounts = landlord_listing_counts($_SESSION['user_id'] ?? 0);

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
        ],
    ];
}
