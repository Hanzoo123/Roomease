<?php
/**
 * Administrator actions on an account: activate or deactivate it, remove it
 * (archive), restore it, or leave and delete notes about it.
 *
 * The first four are written to the activity log. Notes are not: each note
 * already carries its author and its time, and a log entry per note would
 * bury the approvals and removals the log exists for.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_users.php');
}
verify_csrf();

$userId = (int) ($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';

// From the account's own page, go back to it; otherwise to the directory.
$fromDetail = ($_POST['return_to'] ?? '') === 'user' && $userId > 0;
$back = function ($listPath) use ($fromDetail, $userId) {
    return $fromDetail ? 'admin/user.php?id=' . $userId : $listPath;
};

// Never let an admin deactivate/archive their own account or another administrator.
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role != 'administrator'");
$stmt->execute([$userId]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('User not found or cannot be modified.', 'error');
    redirect('admin/manage_users.php');
}

$fullName = trim($target['first_name'] . ' ' . $target['last_name']);
$name = strip_tags($fullName);

// Notes always come back to the account's own page with the Notes section
// open, because that is the only place they are shown.
$notesAnchor = 'admin/user.php?id=' . $userId . '#panel-overview';

if ($action === 'add_note') {
    if (add_account_note($userId, $_POST['body'] ?? '')) {
        flash_set('Note added.', 'success');
    } else {
        flash_set('The note was empty, or could not be saved.', 'error');
    }
    redirect($notesAnchor);

} elseif ($action === 'delete_note') {
    // delete_account_note refuses a note this administrator did not write, so
    // the "not yours" case and the "already gone" case read the same here.
    if (delete_account_note((int) ($_POST['note_id'] ?? 0))) {
        flash_set('Note deleted.', 'success');
    } else {
        flash_set('That note could not be deleted. You can only delete your own notes.', 'error');
    }
    redirect($notesAnchor);

} elseif ($action === 'toggle_status') {
    if ($target['deleted_at'] !== null) {
        flash_set('Restore this account before changing its status.', 'error');
        redirect($back('admin/manage_users.php'));
    }
    $newStatus = $target['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')->execute([$newStatus, $userId]);
    if (!$newStatus) {
        // Deactivation also ends every "Remember me" device for the account.
        forget_all_remembered_logins($userId);
    }
    log_admin_action($newStatus ? 'user_activate' : 'user_deactivate', $userId, $fullName);
    flash_set('"' . $name . '" was ' . ($newStatus ? 'activated' : 'deactivated') . '.', 'success');
    redirect($back('admin/manage_users.php'));

} elseif ($action === 'delete') {
    // Archive, do not destroy.
    //
    // This used to run DELETE FROM users, and because every foreign key in
    // the schema cascades, that one statement also erased the landlord's
    // listings, every photo row attached to them, and every boarder's saved
    // copy of those listings — after deleting the photo files from disk, so
    // there was nothing to restore from either. Setting deleted_at hides the
    // account and its listings everywhere the public site looks, and can be
    // undone.
    if ($target['deleted_at'] !== null) {
        flash_set('That account is already removed.', 'error');
        redirect($back('admin/manage_users.php?view=archived'));
    }
    $pdo->prepare('UPDATE users SET deleted_at = NOW(), is_active = 0 WHERE user_id = ?')
        ->execute([$userId]);
    forget_all_remembered_logins($userId);
    log_admin_action('user_remove', $userId, $fullName);
    flash_set('"' . $name . '" was removed. Their listings are hidden, and the account can be restored.', 'success');
    redirect($back('admin/manage_users.php?view=archived'));

} elseif ($action === 'restore') {
    if ($target['deleted_at'] === null) {
        flash_set('That account is not removed.', 'error');
        redirect($back('admin/manage_users.php'));
    }
    $pdo->prepare('UPDATE users SET deleted_at = NULL, is_active = 1 WHERE user_id = ?')
        ->execute([$userId]);
    log_admin_action('user_restore', $userId, $fullName);
    flash_set('"' . $name . '" was restored, along with their listings.', 'success');
    redirect($back('admin/manage_users.php'));
}

flash_set('Unknown action.', 'error');
redirect($back('admin/manage_users.php'));
