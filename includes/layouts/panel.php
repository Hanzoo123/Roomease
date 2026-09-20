<?php
/**
 * Shell configuration for the AdminLTE management panel.
 *
 * Both the administrator and the landlord areas render the same chrome
 * (panel_head / panel_navbar / panel_sidebar / panel_footer). Everything that
 * differs between the two roles is described here, in one place, so the two
 * panels cannot drift apart.
 */

// The page header, card header and field helpers every panel page uses. Loaded
// with the shell rather than page by page, so a new page gets them for free.
require_once __DIR__ . '/../components/panel_page_header.php';

/**
 * Panel settings for the currently logged-in user.
 * Returns the title suffix, the role label shown in the navbar and sidebar,
 * the panel's home page, and the sidebar menu.
 *
 * My Profile is not in either menu: it lives in the navbar account menu, which
 * is on every page of the panel.
 */
function panel_config()
{
    if (is_admin()) {
        return [
            'name'  => 'Admin',
            'badge' => ['label' => 'Administrator'],
            'home'   => 'admin/dashboard.php',
            'menu'  => [
                ['url' => 'admin/dashboard.php',       'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
                // A listing's review page and an account's page stay under their list.
                ['url' => 'admin/manage_users.php',    'icon' => 'fa-users',          'label' => 'Manage Users',
                 'also' => ['user.php']],
                ['url' => 'admin/manage_listings.php', 'icon' => 'fa-home',           'label' => 'Manage Listings',
                 'also' => ['listing.php']],
                // 'count' puts a number beside the item, shown only when it is above zero.
                ['url' => 'admin/manage_listings.php?status=pending', 'icon' => 'fa-clipboard-check', 'label' => 'Pending Approvals',
                 'count' => pending_listing_count()],
                ['url' => 'admin/reports.php',         'icon' => 'fa-chart-bar',      'label' => 'Reports'],
                ['url' => 'admin/activity.php',        'icon' => 'fa-history',        'label' => 'Activity Log'],
                ['url' => 'admin/extras.php',          'icon' => 'fa-bolt',           'label' => 'Utilities & Amenities'],
                ['url' => 'admin/appearance.php',      'icon' => 'fa-paint-brush',    'label' => 'Appearance'],
            ],
        ];
    }

    return [
        'name'  => 'Landlord',
        'badge' => ['label' => 'Landlord'],
        'home'   => 'landlord/dashboard.php',
        'menu'  => [
            ['url' => 'landlord/dashboard.php',   'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
            // Editing a listing or one of its rooms stays under this item.
            ['url' => 'landlord/listings.php',    'icon' => 'fa-home',           'label' => 'My Boarding Houses',
             'also' => ['edit_listing.php', 'room_form.php']],
            ['url' => 'landlord/add_listing.php', 'icon' => 'fa-plus-square',    'label' => 'Add Listing'],
            ['url' => 'landlord/extras.php',      'icon' => 'fa-bolt',           'label' => 'Utilities & Amenities'],
        ],
    ];
}
