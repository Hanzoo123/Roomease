<?php
/**
 * Who is signed in, and gating pages by role.
 * Also the return-to-page-after-login helpers and administrator usernames.
 */

/** True if a user is currently logged in. */

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

/**
 * The signed-in user's id, or null. What a save writes into created_by and
 * updated_by, the "who" columns on every table people create and edit rows
 * in (database/roomease.sql). NULL there means the system did it.
 */

function current_user_id()
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Mark a just-created account as created, and so far changed, by itself:
 * the id a sign-up needs for its own created_by only exists once the row is
 * in. Called by both kinds of sign-up (auth/register.php, Google).
 */

function mark_self_created($userId)
{
    global $pdo;
    $pdo->prepare('UPDATE users SET created_by = user_id, updated_by = user_id WHERE user_id = ?')
        ->execute([(int) $userId]);
}

/** Get the logged-in user's role, or null. */

function current_role()
{
    return $_SESSION['role'] ?? null;
}

/**
 * Force login; optionally restrict to specific roles. Redirects otherwise.
 *
 * Administrators have their own sign-in page, so a signed-out visitor to a
 * page only administrators can use is sent there; every other page sends them
 * to the public login.
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
        // Support 'admin' alias for 'administrator'
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

    // A new administrator adds their name and email before anything else.
    // Their own profile pages stay open, Change Password included, so the
    // temporary password can be replaced too.
    $profilePages = ['profile.php', 'edit_profile.php', 'change_password.php'];
    if (profile_incomplete() && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $profilePages, true)) {
        flash_set('Welcome! Please add your name and email to finish setting up your account.', 'info');
        redirect('auth/edit_profile.php');
    }
}

/** The administrators' sign-in page, relative to the app root. */

const ADMIN_LOGIN_PATH = 'admin/login.php';

/** Check if current user is an administrator. */

function is_admin()
{
    return in_array(current_role(), ['administrator', 'admin'], true);
}

/**
 * True for a super admin: an administrator with users.is_super_admin set, who
 * can also add and manage the other administrators (admin/admins.php) and
 * change the site's Appearance. Everything else is the same for every
 * administrator. The session copy is refreshed on every request by
 * enforce_session_policy(), so a demotion takes effect on the next click.
 */

function is_super_admin()
{
    return is_admin() && !empty($_SESSION['is_super_admin']);
}

/** For pages only a super admin may open: anyone else goes to the dashboard. */

function require_super_admin()
{
    require_login('admin');
    if (!is_super_admin()) {
        flash_set('Only a super admin can open that page.', 'error');
        redirect('admin/dashboard.php');
    }
}

/* ---------------------------------------------------------------------------
 * Administrator usernames
 *
 * An administrator can sign in with a username as well as an email. A super
 * admin creates the account with only a username, a temporary password and a
 * role (admin/add_user.php); the administrator then adds their own name and
 * email, which they must do before using the panel (require_login()). Until
 * then the username stands in wherever a name would be shown. Landlords and
 * boarders have no username.
 * ------------------------------------------------------------------------ */

/** The shape of a username: 3 to 30 letters, digits, dots and underscores. */
const USERNAME_PATTERN = '/^[A-Za-z0-9._]{3,30}$/';

/**
 * What is wrong with a username for a new administrator, or null. There is
 * no @, so a username can never be mistaken for an email. The column ignores
 * capitals, so "Juan" is taken when "juan" is.
 */
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

/** The name to show for an account: its full name, else its username, else its email. */
function account_display_name(array $user)
{
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return (string) (($user['username'] ?? '') !== '' ? $user['username'] : ($user['email'] ?? ''));
}

/**
 * account_display_name() in SQL, for a users table joined as $alias: the full
 * name, else the username, else the email.
 */
function account_name_sql($alias)
{
    return "COALESCE(NULLIF(TRIM(CONCAT({$alias}.first_name, ' ', {$alias}.last_name)), ''), {$alias}.username, {$alias}.email)";
}

/**
 * True for an administrator who has not yet added their name and email. They
 * are kept on Edit Profile (and Change Password, to replace the temporary
 * one) until they do. Set per request by enforce_session_policy().
 */
function profile_incomplete()
{
    return !empty($_SESSION['profile_incomplete']);
}

/* ---------------------------------------------------------------------------
 * After sign-in: back where they were, with the save that sent them there
 *
 * A guest who taps Save is sent to log in. The page they were on, and the
 * listing they meant to save, wait in the session; start_user_session()
 * empties the session, so they are taken out before it runs and acted on
 * after. Only pages on this short list can be returned to, so the address
 * cannot be pointed anywhere else.
 * ------------------------------------------------------------------------ */

/** A path a sign-in may return to, or '' when it is not one of ours. */

function safe_return_path($path)
{
    $path = (string) $path;
    return preg_match('#^(index\.php|boarder/(view_listing|browse|saved)\.php)(\?[\w\-=&%.+~]*)?$#', $path) ? $path : '';
}

/** Remember where to go, and what to save, once the visitor has signed in. */

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

/** Take the pending return out of the session, before it is emptied. */

function take_after_login()
{
    $after = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    // Half an hour is plenty to sign in; older than that, it is forgotten.
    if (!is_array($after) || time() - (int) ($after['set_at'] ?? 0) > 1800) {
        return null;
    }
    return $after;
}

/**
 * Finish what the visitor came to sign in for, and say where to send them.
 * The save only happens for a boarder, and only for a listing that is live.
 */

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
