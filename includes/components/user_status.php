<?php
/**
 * A landlord's or boarder's status badge and their Activate / Deactivate
 * button. Manage Users and the account page draw them here, and so does
 * admin/user_action.php when it answers fetch(), so the swapped-in copy
 * always matches the original.
 *
 * $context is 'list' (the Manage Users table: small, icon-only button) or
 * 'user' (the account page header: labelled button).
 */

function user_status_badge(array $user, $context = 'list')
{
    if ($user['deleted_at'] !== null) {
        [$class, $icon, $label] = ['badge-dark', 'fa-archive', 'Removed'];
    } elseif ($user['is_active']) {
        [$class, $icon, $label] = ['badge-success', 'fa-check-circle', 'Active'];
    } else {
        [$class, $icon, $label] = ['badge-danger', 'fa-ban', 'Deactivated'];
    }
    return '<span class="badge ' . $class . ($context === 'list' ? ' px-2 py-1' : '') . '"'
        . ' data-user-status="' . (int) $user['user_id'] . '">'
        . '<i class="fas ' . $icon . ' mr-1"></i> ' . $label . '</span>';
}

/**
 * The form sends the state wanted (active=1 or 0), not "toggle", so a double
 * click or two admins acting at once can't flip it back. $role keeps the
 * Manage Users filter for the reload when JavaScript is off.
 */
function user_status_form(array $user, $context = 'list', $role = '')
{
    $id = (int) $user['user_id'];
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $active = (bool) $user['is_active'];

    // Deactivating signs them out everywhere, so it asks first. Activating doesn't.
    $confirm = $active
        ? 'Deactivate ' . $name . '? They will be signed out on every device and cannot sign in until an admin activates the account again.'
        : null;

    $html = '<form method="post" action="' . base_url('admin/user_action.php') . '" class="d-inline"'
        . ' data-user-action="' . $id . '"'
        . ($confirm !== null ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="user_id" value="' . $id . '">'
        . '<input type="hidden" name="action" value="set_status">'
        . '<input type="hidden" name="active" value="' . ($active ? '0' : '1') . '">'
        . ($context === 'user' ? '<input type="hidden" name="return_to" value="user">' : '')
        . (in_array($role, ['landlord', 'boarder'], true) ? '<input type="hidden" name="role" value="' . $role . '">' : '');

    $verb = $active ? 'Deactivate' : 'Activate';
    $icon = $active ? 'fa-user-slash' : 'fa-user-check';
    if ($context === 'user') {
        $html .= '<button type="submit" class="btn btn-sm ' . ($active ? 'btn-outline-warning' : 'btn-success') . '">'
            . '<i class="fas ' . $icon . ' mr-1" data-status-icon></i> ' . $verb . '</button>';
    } else {
        $html .= '<button type="submit" class="btn btn-xs ' . ($active ? 'btn-outline-warning' : 'btn-outline-success') . '"'
            . ' title="' . $verb . ' account" aria-label="' . h($verb . ' ' . $name) . '">'
            . '<i class="fas ' . $icon . '" data-status-icon></i></button>';
    }
    return $html . '</form>';
}
