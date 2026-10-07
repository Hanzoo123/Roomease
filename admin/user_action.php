<?php
/**
 * Admin actions on a landlord or boarder, all logged:
 *   set_status  active=1 / 0: activate or deactivate (any admin)
 *   delete      remove, i.e. archive (super admin only)
 *   restore     undo a removal (super admin only)
 * Replies with JSON for fetch() (includes/scripts/user_actions_js.php), or
 * with a redirect back to the same page and filter when JavaScript is off.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/components/user_status.php';

$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$userId = (int) ($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$fromDetail = ($_POST['return_to'] ?? '') === 'user' && $userId > 0;
$role = in_array($_POST['role'] ?? '', ['landlord', 'boarder'], true) ? $_POST['role'] : '';

// From the account's own page, go back to it; otherwise to the same Manage
// Users filter (Removed view when $archived).
$back = function ($archived = false) use ($fromDetail, $userId, $role) {
    if ($fromDetail) {
        return 'admin/user.php?id=' . $userId;
    }
    $query = array_filter(['view' => $archived ? 'archived' : '', 'role' => $role]);
    return 'admin/manage_users.php' . ($query ? '?' . http_build_query($query) : '');
};

/** JSON for fetch() (no flash, or it would pop up on the next page), else flash and redirect. */
function user_action_reply($status, array $payload, $path)
{
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
    flash_set($payload['message'] ?? 'Done.', empty($payload['ok']) ? 'error' : 'success');
    redirect($path);
}

// Signed out (or no longer an admin) while the page was open: fetch() is sent
// to the sign-in page instead of getting its HTML.
if ($wantsJson && (!is_logged_in() || !in_array(current_role(), ADMIN_ROLES, true))) {
    user_action_reply(401, ['ok' => false, 'redirect' => base_url(ADMIN_LOGIN_PATH),
        'message' => 'Please sign in again.'], ADMIN_LOGIN_PATH);
}
require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_users.php');
}
if ($wantsJson && !csrf_ok()) {
    user_action_reply(403, ['ok' => false, 'reload' => true,
        'message' => 'Your session expired. Please refresh the page and try again.'], $back());
}
verify_csrf();

// Never let an admin deactivate/archive their own account or another administrator.
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role NOT IN " . ADMIN_ROLES_SQL);
$stmt->execute([$userId]);
$target = $stmt->fetch();

if (!$target) {
    user_action_reply(404, ['ok' => false, 'message' => 'User not found or cannot be modified.'], 'admin/manage_users.php');
}

// Any admin can deactivate an account; removing and restoring are for a super admin.
if (in_array($action, ['delete', 'restore'], true) && !is_super_admin()) {
    user_action_reply(403, ['ok' => false,
        'message' => 'Only a super admin can remove or restore accounts. You can deactivate it instead.'], $back());
}

$fullName = trim($target['first_name'] . ' ' . $target['last_name']);
$name = strip_tags($fullName);

if ($action === 'set_status') {
    if ($target['deleted_at'] !== null) {
        user_action_reply(409, ['ok' => false, 'message' => 'Restore this account before changing its status.'], $back(true));
    }
    if (!in_array($_POST['active'] ?? '', ['0', '1'], true)) {
        user_action_reply(400, ['ok' => false, 'message' => 'Unknown action.'], $back());
    }
    $active = (int) $_POST['active'];

    // Only a row that actually changes counts, so a double click or two admins
    // at once log it once, and the second just hears it's already done.
    $update = $pdo->prepare(
        'UPDATE users SET is_active = ?, updated_by = ?
          WHERE user_id = ? AND is_active <> ? AND deleted_at IS NULL'
    );
    $update->execute([$active, current_user_id(), $userId, $active]);
    $verb = $active ? 'activated' : 'deactivated';

    if ($update->rowCount() > 0) {
        if (!$active) {
            // Deactivation also ends every "Remember me" device for the account.
            forget_all_remembered_logins($userId);
        }
        audit_log($active ? 'user_activate' : 'user_deactivate', $userId, $fullName);
        $message = '"' . $name . '" was ' . $verb . '.';
    } else {
        $message = '"' . $name . '" is already ' . $verb . '.';
    }

    $target['is_active'] = $active;
    $context = $fromDetail ? 'user' : 'list';
    user_action_reply(200, [
        'ok'        => true,
        'message'   => $message,
        'is_active' => (bool) $active,
        'badge'     => user_status_badge($target, $context),
        'form'      => user_status_form($target, $context, $role),
    ], $back());

} elseif ($action === 'delete') {
    // Hide, don't delete: deleting would also erase their listings and
    // photos (foreign key cascade). Setting deleted_at can be undone.
    $update = $pdo->prepare(
        'UPDATE users SET deleted_at = NOW(), deleted_by = ?, is_active = 0, updated_by = ?
          WHERE user_id = ? AND deleted_at IS NULL'
    );
    $update->execute([current_user_id(), current_user_id(), $userId]);
    if ($update->rowCount() === 0) {
        user_action_reply(409, ['ok' => false, 'message' => 'That account is already removed.'], $back(true));
    }
    forget_all_remembered_logins($userId);
    audit_log('user_remove', $userId, $fullName);
    user_action_reply(200, ['ok' => true,
        'message' => '"' . $name . '" was removed. Their listings are hidden, and the account can be restored.'], $back(true));

} elseif ($action === 'restore') {
    $update = $pdo->prepare(
        'UPDATE users SET deleted_at = NULL, deleted_by = NULL, is_active = 1, updated_by = ?
          WHERE user_id = ? AND deleted_at IS NOT NULL'
    );
    $update->execute([current_user_id(), $userId]);
    if ($update->rowCount() === 0) {
        user_action_reply(409, ['ok' => false, 'message' => 'That account is not removed.'], $back());
    }
    audit_log('user_restore', $userId, $fullName);
    user_action_reply(200, ['ok' => true,
        'message' => '"' . $name . '" was restored, along with their listings.'], $back());
}

// toggle_status came from pages opened before set_status replaced it.
user_action_reply(400, ['ok' => false, 'reload' => $action === 'toggle_status',
    'message' => $action === 'toggle_status'
        ? 'This page was out of date, so nothing changed. Please try again.'
        : 'Unknown action.'], $back());
