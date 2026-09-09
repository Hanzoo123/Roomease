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
                ['url' => 'admin/manage_listings.php?status=pending', 'icon' => 'fa-clipboard-check', 'label' => 'Pending Approvals'],
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
            ['url' => 'landlord/add_listing.php', 'icon' => 'fa-plus-square',    'label' => 'Add Listing'],
            ['url' => 'auth/profile.php',         'icon' => 'fa-user-cog',       'label' => 'My Profile'],
        ],
    ];
}
