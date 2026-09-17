<?php
/**
 * Shell configuration for the AdminLTE management panel.
 *
 * Both the administrator and the landlord areas render the same chrome
 * (panel_head / panel_navbar / panel_sidebar / panel_footer). Everything that
 * differs between the two roles is described here, in one place, so the two
 * panels cannot drift apart.
 */

/**
 * Panel settings for the currently logged-in user.
 * Returns the title suffix, the navbar role badge, the panel's home page,
 * and the sidebar menu.
 */
function panel_config()
{
    if (is_admin()) {
        return [
            'name'  => 'Admin',
            'badge' => [
                'label' => 'Administrator',
                'class' => 'badge-success',
                'icon'  => 'fa-shield-alt',
            ],
            'avatar' => 'fa-user-shield',
            'home'   => 'admin/dashboard.php',
            'menu'  => [
                ['url' => 'admin/dashboard.php',       'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
                ['url' => 'admin/manage_users.php',    'icon' => 'fa-users',          'label' => 'Manage Users'],
                ['url' => 'admin/manage_listings.php', 'icon' => 'fa-home',           'label' => 'Manage Listings'],
                // 'count' puts a number beside the item, shown only when it is above zero.
                ['url' => 'admin/manage_listings.php?status=pending', 'icon' => 'fa-clipboard-check', 'label' => 'Pending Approvals',
                 'count' => pending_listing_count()],
                ['url' => 'admin/activity.php',        'icon' => 'fa-history',        'label' => 'Activity Log'],
                ['url' => 'admin/extras.php',          'icon' => 'fa-bolt',           'label' => 'Utilities & Amenities'],
                ['url' => 'admin/appearance.php',      'icon' => 'fa-paint-brush',    'label' => 'Appearance'],
                ['url' => 'auth/profile.php',          'icon' => 'fa-user-cog',       'label' => 'My Profile'],
            ],
        ];
    }

    return [
        'name'  => 'Landlord',
        'badge' => [
            'label' => 'Landlord',
            'class' => 'badge-info',
            'icon'  => 'fa-user-tie',
        ],
        'avatar' => 'fa-user-tie',
        'home'   => 'landlord/dashboard.php',
        'menu'  => [
            ['url' => 'landlord/dashboard.php',   'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
            // Editing a listing or one of its rooms stays under this item.
            ['url' => 'landlord/listings.php',    'icon' => 'fa-home',           'label' => 'My Boarding Houses',
             'also' => ['edit_listing.php', 'room_form.php']],
            ['url' => 'landlord/add_listing.php', 'icon' => 'fa-plus-square',    'label' => 'Add Listing'],
            ['url' => 'landlord/extras.php',      'icon' => 'fa-bolt',           'label' => 'Utilities & Amenities'],
            ['url' => 'auth/profile.php',         'icon' => 'fa-user-cog',       'label' => 'My Profile'],
        ],
    ];
}
