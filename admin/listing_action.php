<?php
/**
 * Administrator actions on a listing: approve it, reject it with a reason,
 * remove it, or restore it.
 *
 * Removing archives the listing rather than deleting it. It used to run
 * DELETE, which erased the listing, its rooms, every boarder's saved copy,
 * and its photos from disk, with no way back. An archived listing is off the
 * site for everyone but administrators and comes back whole when restored.
 *
 * Every action is written to the activity log, and the landlord hears about it
 * by email and on their dashboard.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_listings.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Send the admin back to where they were working: the listing's own review
// page, the Removed tab, or the approval tab they had open.
$returnStatus = $_POST['return_status'] ?? '';
if (($_POST['return_to'] ?? '') === 'review' && $boardingHouseId > 0) {
    $returnTo = 'admin/listing.php?id=' . $boardingHouseId;
} elseif (($_POST['return_view'] ?? '') === 'removed') {
    $returnTo = 'admin/manage_listings.php?view=removed';
} elseif (in_array($returnStatus, ['pending', 'approved', 'rejected'], true)) {
    $returnTo = 'admin/manage_listings.php?status=' . $returnStatus;
} else {
    $returnTo = 'admin/manage_listings.php';
}

$stmt = $pdo->prepare(
    'SELECT bh.boarding_house_id, bh.name, bh.moderation_status, bh.deleted_at,
            u.is_active AS landlord_active, u.deleted_at AS landlord_deleted_at
       FROM boarding_houses bh
       JOIN users u ON u.user_id = bh.landlord_id
      WHERE bh.boarding_house_id = ?'
);
$stmt->execute([$boardingHouseId]);
$listing = $stmt->fetch();

if (!$listing) {
    flash_set('Listing not found.', 'error');
    redirect(strpos($returnTo, 'listing.php') !== false ? 'admin/manage_listings.php' : $returnTo);
}

// The name is whatever the landlord typed. The toast escapes its message, but
// stripping tags here as well keeps it safe anywhere a flash is ever rendered.
$name = strip_tags($listing['name']);
$adminId = (int) $_SESSION['user_id'];
$archived = $listing['deleted_at'] !== null;

/** What the flash message adds about the landlord's email. */
$emailNote = function ($sent) {
    if ($sent === null) {
        return ' The landlord\'s account is off, so no email was sent.';
    }
    if ($sent) {
        return ' The landlord was emailed.';
    }
    return mail_enabled()
        ? ' The email to the landlord could not be sent (see storage/mail.log); they will still see it on their dashboard.'
        : ' No email was sent because Gmail is not set up; the landlord will see it on their dashboard.';
};

if ($action === 'approve' || $action === 'reject') {
    if ($archived) {
        flash_set('"' . $name . '" is removed. Restore it before changing its approval.', 'error');
        redirect($returnTo);
    }
}

if ($action === 'approve') {
    // A listing with no rooms has nothing for a boarder to see, and the public
    // site would not show it anyway, so it is not approved yet.
    $rooms = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE boarding_house_id = ?');
    $rooms->execute([$boardingHouseId]);
    if ((int) $rooms->fetchColumn() === 0) {
        flash_set('"' . $name . '" has no rooms yet. The landlord needs to add at least one before it can be approved.', 'error');
        redirect($returnTo);
    }

    // Approving would change nothing a boarder sees while the landlord's
    // account is off, and would quietly publish the listing the moment the
    // account came back, so it waits until the account is restored.
    if ($listing['landlord_deleted_at'] !== null || (int) $listing['landlord_active'] !== 1) {
        flash_set('"' . $name . '" cannot be approved while its landlord\'s account is removed or deactivated. Restore the account first.', 'error');
        redirect($returnTo);
    }

    $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'approved', rejection_reason = NULL, moderated_at = NOW(), moderated_by = ?
          WHERE boarding_house_id = ?"
    )->execute([$adminId, $boardingHouseId]);
    log_admin_action('listing_approve', $boardingHouseId, $listing['name']);
    $sent = notify_landlord_of_decision($boardingHouseId, 'listing_approve');
    flash_set('"' . $name . '" is now approved and visible to boarders.' . $emailNote($sent), 'success');

} elseif ($action === 'reject') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    if ($reason === '') {
        flash_set('Please give a reason so the landlord knows what to fix.', 'error');
        redirect($returnTo);
    }
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }
    $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'rejected', rejection_reason = ?, moderated_at = NOW(), moderated_by = ?
          WHERE boarding_house_id = ?"
    )->execute([$reason, $adminId, $boardingHouseId]);
    log_admin_action('listing_reject', $boardingHouseId, $listing['name'], $reason);
    $sent = notify_landlord_of_decision($boardingHouseId, 'listing_reject', $reason);
    flash_set('"' . $name . '" was rejected.' . $emailNote($sent), 'success');

} elseif ($action === 'remove' || $action === 'delete') {
    // 'delete' is what older copies of the Manage Listings page post.
    if ($archived) {
        flash_set('"' . $name . '" is already removed.', 'error');
        redirect('admin/manage_listings.php?view=removed');
    }
    $reason = mb_substr(trim($_POST['removal_reason'] ?? ''), 0, 500);

    $pdo->prepare('UPDATE boarding_houses SET deleted_at = NOW() WHERE boarding_house_id = ? AND deleted_at IS NULL')
        ->execute([$boardingHouseId]);
    log_admin_action('listing_remove', $boardingHouseId, $listing['name'], $reason);
    $sent = notify_landlord_of_decision($boardingHouseId, 'listing_remove', $reason);
    flash_set('"' . $name . '" was removed from the site. It can be restored from the Removed tab.' . $emailNote($sent), 'success');
    if (strpos($returnTo, 'listing.php') === false) {
        $returnTo = 'admin/manage_listings.php?view=removed';
    }

} elseif ($action === 'restore') {
    if (!$archived) {
        flash_set('"' . $name . '" is not removed.', 'error');
        redirect($returnTo);
    }

    $pdo->prepare('UPDATE boarding_houses SET deleted_at = NULL WHERE boarding_house_id = ?')
        ->execute([$boardingHouseId]);
    log_admin_action('listing_restore', $boardingHouseId, $listing['name']);
    $sent = notify_landlord_of_decision($boardingHouseId, 'listing_restore');
    $where = $listing['moderation_status'] === 'approved'
        ? ' It is visible to boarders again.'
        : ' It is back with the ' . $listing['moderation_status'] . ' listings.';
    flash_set('"' . $name . '" was restored.' . $where . $emailNote($sent), 'success');
    if (strpos($returnTo, 'listing.php') === false) {
        $returnTo = 'admin/manage_listings.php';
    }

} else {
    flash_set('Unknown listing action.', 'error');
}

redirect($returnTo);
