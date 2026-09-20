<?php
/**
 * One account's avatar: their profile photo, or their initials when they have
 * none. Every place that draws a face — the panel sidebar and navbar, the
 * admin tables, the activity log, the public listing page — goes through here,
 * so a photo and a fallback can never be styled two different ways.
 *
 * $user needs a name and, if it has one, avatar_path. Both the panel and the
 * public site pass whatever row they already have, so the accepted key names
 * are deliberately loose: full_name, landlord_name, name, or first_name plus
 * last_name.
 *
 * $size is the diameter in pixels. $class is added to the element, which is how
 * a caller reaches for the public site's own .avatar styling instead of the
 * panel's .re-avatar.
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

/**
 * The avatar as HTML. A photo is decorative here — the name is always beside
 * it in the markup that calls this — so the img carries an empty alt and the
 * initials are hidden from screen readers, which keeps the name from being
 * read out twice.
 */
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
