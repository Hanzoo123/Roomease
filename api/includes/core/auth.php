<?php
/**
 * Who is signed in, role checks, admin usernames, temporary passwords, and
 * returning to the right page after login.
 */

/** True if a user is logged in. */

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

/** The signed-in user's id, or null. Saved into created_by / updated_by. */

function current_user_id()
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** After sign-up, mark the new account as created by itself. */

function mark_self_created($userId)
{
    global $pdo;
    $pdo->prepare('UPDATE users SET created_by = user_id, updated_by = user_id WHERE user_id = ?')
        ->execute([(int) $userId]);
}

/** The logged-in user's role, or null. */

function current_role()
{
    return $_SESSION['role'] ?? null;
}

/**
 * Require login, and optionally one of $roles. Otherwise redirect.
 * Admin-only pages send signed-out visitors to the admin login page.
 */

function require_login($roles = null)
{
    if (!is_logged_in()) {
        $adminOnly = $roles !== null
            && !array_diff((array) $roles, ['admin', 'administrator']);
        redirect($adminOnly ? ADMIN_LOGIN_PATH : 'auth/login.php');
    }
    if ($roles !== null) {
        $roles = (array) $roles;
        // 'admin' and 'administrator' mean the same.
        if (in_array('admin', $roles, true) && !in_array('administrator', $roles, true)) {
            $roles[] = 'administrator';
        }
        if (in_array('administrator', $roles, true) && !in_array('admin', $roles, true)) {
            $roles[] = 'admin';
        }
        if (!in_array(current_role(), $roles, true)) {
            redirect('index.php');
        }
    }

    // A new admin must first add their name and email...
    $profilePages = ['profile.php', 'edit_profile.php', 'change_password.php'];
    if (profile_incomplete() && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $profilePages, true)) {
        flash_set('Welcome! Please add your name and email to finish setting up your account.', 'info');
        redirect('auth/edit_profile.php');
    }

    // ...and replace a temporary password, which the super admin also knows.
    if (password_change_required() && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $profilePages, true)) {
        flash_set('Please choose your own password to replace the temporary one.', 'info');
        redirect('auth/change_password.php');
    }
}

const ADMIN_LOGIN_PATH = 'admin/login.php';

/** True if the current user is an administrator. */

function is_admin()
{
    return in_array(current_role(), ['administrator', 'admin'], true);
}

/** True for a super admin: an admin who can also manage other admins and Appearance. */

function is_super_admin()
{
    return is_admin() && !empty($_SESSION['is_super_admin']);
}

/** For super-admin-only pages. Anyone else goes to the dashboard. */

function require_super_admin()
{
    require_login('admin');
    if (!is_super_admin()) {
        flash_set('Only a super admin can open that page.', 'error');
        redirect('admin/dashboard.php');
    }
}

/* ---------------------------------------------------------------------------
 * Admin usernames. A super admin creates an admin with only a username and a
 * temporary password; the admin adds their name and email on first login.
 * Landlords and boarders have no username.
 * ------------------------------------------------------------------------ */

const USERNAME_PATTERN = '/^[A-Za-z0-9._]{3,30}$/';

/** What is wrong with a new username, or null. No @ allowed, so it can't look like an email. */
function username_problem($username)
{
    global $pdo;
    $username = (string) $username;
    if ($username === '') {
        return 'Username is required.';
    }
    if (!preg_match(USERNAME_PATTERN, $username)) {
        return 'Username must be 3 to 30 characters: letters, numbers, dots (.) and underscores (_), with no spaces.';
    }
    $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $taken->execute([$username]);
    return $taken->fetchColumn() ? 'That username is already taken.' : null;
}

/** The name to show: full name, else username, else email. */
function account_display_name(array $user)
{
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return (string) (($user['username'] ?? '') !== '' ? $user['username'] : ($user['email'] ?? ''));
}

/** account_display_name() as SQL, for a users table joined as $alias. */
function account_name_sql($alias)
{
    return "COALESCE(NULLIF(TRIM(CONCAT({$alias}.first_name, ' ', {$alias}.last_name)), ''), {$alias}.username, {$alias}.email)";
}

/** True for an admin who hasn't added their name and email yet. */
function profile_incomplete()
{
    return !empty($_SESSION['profile_incomplete']);
}

/* ---------------------------------------------------------------------------
 * Temporary passwords. A password a super admin sets (Add User, Reset
 * Password) is marked with users.must_change_password, and the admin must
 * replace it before using the panel.
 * ------------------------------------------------------------------------ */

/** True when the signed-in admin must replace a temporary password. */
function password_change_required()
{
    return !empty($_SESSION['must_change_password']);
}

/** Whether the account's password is temporary. False if the column is missing. */
function account_must_change_password($userId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT must_change_password FROM users WHERE user_id = ?');
        $stmt->execute([(int) $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/** Mark the account's password as temporary (true) or the owner's own (false). */
function set_password_change_required($userId, $required)
{
    global $pdo;
    try {
        $pdo->prepare('UPDATE users SET must_change_password = ? WHERE user_id = ?')
            ->execute([$required ? 1 : 0, (int) $userId]);
    } catch (PDOException $e) {
        error_log('RoomEase: users.must_change_password is missing, so temporary passwords are not enforced. '
            . 'Import database/roomease.sql or add the column. ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------------------
 * After login: go back to the page the guest was on, and save the listing
 * they tapped Save on. Only pages on a short list are allowed as a return
 * address, so it can't be used to send people to another site.
 * ------------------------------------------------------------------------ */

/** $path if it is a page we allow returning to, otherwise ''. */

function safe_return_path($path)
{
    $path = (string) $path;
    return preg_match('#^(index\.php|boarder/(view_listing|browse|saved)\.php)(\?[\w\-=&%.+~]*)?$#', $path) ? $path : '';
}

/** Remember where to go, and what to save, after login. */

function remember_after_login($next, $saveListingId = 0)
{
    $next = safe_return_path($next);
    if ($next === '') {
        return;
    }
    $_SESSION['after_login'] = [
        'next' => $next,
        'save' => (int) $saveListingId,
        'set_at' => time(),
    ];
}

/** Take the saved return out of the session (login clears the session). */

function take_after_login()
{
    $after = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    // Forgotten after 30 minutes.
    if (!is_array($after) || time() - (int) ($after['set_at'] ?? 0) > 1800) {
        return null;
    }
    return $after;
}

/** Save the listing (boarders only, live listings only) and return where to go. */

function complete_after_login($after, $welcome)
{
    global $pdo;
    if (!$after) {
        flash_set($welcome, 'success');
        return 'index.php';
    }

    $message = $welcome;
    if (!empty($after['save']) && can_save_listings()) {
        $check = $pdo->prepare(
            "SELECT name FROM boarding_houses
              WHERE boarding_house_id = ? AND moderation_status = 'approved' AND deleted_at IS NULL"
        );
        $check->execute([(int) $after['save']]);
        $name = $check->fetchColumn();
        if ($name !== false) {
            $pdo->prepare('INSERT IGNORE INTO favorites (user_id, boarding_house_id) VALUES (?, ?)')
                ->execute([$_SESSION['user_id'], (int) $after['save']]);
            $message = $welcome . ' ' . $name . ' is in your saved listings.';
        }
    }
    flash_set($message, 'success');
    return safe_return_path($after['next']) ?: 'index.php';
}
