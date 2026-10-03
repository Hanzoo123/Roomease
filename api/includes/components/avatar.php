<?php
/**
 * A user's avatar: their photo, or their initials. Used everywhere a face is shown.
 * $user needs a name (full_name, landlord_name, name, or first/last name) and
 * avatar_path if any. $size is in pixels; $class picks the CSS style.
 */

/** The name to take initials from, whichever shape the row is in. */
function avatar_name(array $user)
{
    foreach (['full_name', 'landlord_name', 'admin_name', 'name'] as $key) {
        if (!empty($user[$key])) {
            return (string) $user[$key];
        }
    }
    return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}

/** The avatar HTML. Hidden from screen readers, since the name is always shown beside it. */
function avatar_html(array $user, $size = 32, $class = 're-avatar')
{
    $name = avatar_name($user);
    $path = $user['avatar_path'] ?? null;
    $style = 'width:' . (int) $size . 'px;height:' . (int) $size . 'px;';

    if (is_avatar_path($path) && is_file(__DIR__ . '/../../' . $path)) {
        return '<img class="' . h($class) . '" style="' . $style . '" src="'
            . h(base_url($path)) . '" alt="" loading="lazy" decoding="async">';
    }

    // The initials shrink with the circle; 40% of the diameter keeps two
    // letters inside it at every size this is used at.
    $style .= 'font-size:' . round($size * 0.4) . 'px;';
    return '<span class="' . h($class) . ' ' . h($class) . '--initials" style="' . $style
        . '" aria-hidden="true">' . h(avatar_initials($name)) . '</span>';
}
