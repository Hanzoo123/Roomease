<?php
/**
 * Super admin actions on another admin: activate/deactivate, remove/restore,
 * promote/demote. You can't act on yourself, and there is always at least one
 * active super admin. Changes apply on the admin's next click.
 */
require __DIR__ . '/../includes/init.php';

require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/admins.php');
}
verify_csrf();

$targetId = (int) ($_POST['user_id'] ?? 0);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$myId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'administrator'");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('Administrator not found.', 'error');
    redirect('admin/admins.php');
}
if ($targetId === $myId) {
    flash_set('You cannot change your own account here. Ask another super admin.', 'error');
    redirect('admin/admins.php');
}

$name = account_display_name($target);
$live = $target['deleted_at'] === null;

// Would this action leave no active super admin? Only a change to an active
// super admin can do that.
$losesSuperAdmin = in_array($action, ['deactivate', 'remove', 'demote'], true)
    && $target['is_super_admin'] && $target['is_active'] && $live;
if ($losesSuperAdmin) {
    $others = $pdo->prepare(
        "SELECT COUNT(*) FROM users
          WHERE role = 'administrator' AND is_super_admin = 1 AND is_active = 1 AND deleted_at IS NULL
            AND user_id <> ?"
    );
    $others->execute([$targetId]);
    if ((int) $others->fetchColumn() === 0) {
        flash_set($name . ' is the only active super admin, so that would leave nobody able to manage administrators. '
            . 'Make someone else a super admin first.', 'error');
        redirect('admin/admins.php');
    }
}

/** Save a change to the target, log it, and go back to the list. */
$apply = function ($sql, array $params, $logAction, $message) use ($pdo, $targetId, $name, $myId) {
    $pdo->prepare($sql . ', updated_by = ? WHERE user_id = ?')->execute(array_merge($params, [$myId, $targetId]));
    audit_log($logAction, $targetId, $name);
    flash_set($message, 'success');
    redirect('admin/admins.php');
};

switch ($action) {
    case 'activate':
        if ($live && !$target['is_active']) {
            $apply('UPDATE users SET is_active = 1', [], 'admin_activate', $name . ' can sign in again.');
        }
        break;
    case 'deactivate':
        if ($live && $target['is_active']) {
            forget_all_remembered_logins($targetId);
            $apply('UPDATE users SET is_active = 0', [], 'admin_deactivate', $name . ' was deactivated and can no longer sign in.');
        }
        break;
    case 'remove':
        if ($live) {
            forget_all_remembered_logins($targetId);
            $apply('UPDATE users SET deleted_at = NOW(), deleted_by = ?, is_active = 0', [$myId], 'admin_remove',
                $name . ' was removed. Nothing is deleted; you can restore them from this page.');
        }
        break;
    case 'restore':
        if (!$live) {
            $apply('UPDATE users SET deleted_at = NULL, deleted_by = NULL, is_active = 1', [], 'admin_restore',
                $name . ' was restored and can sign in again.');
        }
        break;
    case 'promote':
        if ($live && !$target['is_super_admin']) {
            $apply('UPDATE users SET is_super_admin = 1', [], 'admin_promote', $name . ' is now a super admin.');
        }
        break;
    case 'demote':
        if ($live && $target['is_super_admin']) {
            $apply('UPDATE users SET is_super_admin = 0', [], 'admin_demote', $name . ' is no longer a super admin.');
        }
        break;
}

// An unknown action, or one that no longer fits (such as restoring an account
// that is not removed, from a page left open).
flash_set('That change could not be made. The list below is up to date.', 'error');
redirect('admin/admins.php');
