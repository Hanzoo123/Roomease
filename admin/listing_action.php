<?php
/**
 * Admin actions on a listing: approve, reject (with a reason), remove or
 * restore. Remove hides it (deleted_at) instead of deleting, so it can be
 * restored. Each action is logged and the landlord is emailed.
 */
require __DIR__ . '/../includes/init.php';

require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_listings.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Where to go afterwards: back where the admin was. 'next' is worked out
// at the end, after this action.
$returnStatus = $_POST['return_status'] ?? '';
$goToNext = ($_POST['return_to'] ?? '') === 'next';
if ($goToNext || (($_POST['return_to'] ?? '') === 'review' && $boardingHouseId > 0)) {
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
            bh.deleted_by, bh.landlord_id,
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

    // Not while the landlord's account is off: it would go public when restored.
    if ($listing['landlord_deleted_at'] !== null || (int) $listing['landlord_active'] !== 1) {
        flash_set('"' . $name . '" cannot be approved while its landlord\'s account is removed or deactivated. Restore the account first.', 'error');
        redirect($returnTo);
    }

    $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'approved', rejection_reason = NULL, moderated_at = NOW(), moderated_by = ?,
                updated_by = ?
          WHERE boarding_house_id = ?"
    )->execute([$adminId, $adminId, $boardingHouseId]);
    audit_log('listing_approve', $boardingHouseId, $listing['name']);
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
            SET moderation_status = 'rejected', rejection_reason = ?, moderated_at = NOW(), moderated_by = ?,
                updated_by = ?
          WHERE boarding_house_id = ?"
    )->execute([$reason, $adminId, $adminId, $boardingHouseId]);
    audit_log('listing_reject', $boardingHouseId, $listing['name'], $reason);
    $sent = notify_landlord_of_decision($boardingHouseId, 'listing_reject', $reason);
    flash_set('"' . $name . '" was rejected.' . $emailNote($sent), 'success');

} elseif ($action === 'remove' || $action === 'delete') {
    // 'delete' is what older copies of the Manage Listings page post.
    if ($archived) {
        flash_set('"' . $name . '" is already removed.', 'error');
        redirect('admin/manage_listings.php?view=removed');
    }
    $reason = mb_substr(trim($_POST['removal_reason'] ?? ''), 0, 500);

    $pdo->prepare('UPDATE boarding_houses SET deleted_at = NOW(), deleted_by = ?, updated_by = ? WHERE boarding_house_id = ? AND deleted_at IS NULL')
        ->execute([current_user_id(), current_user_id(), $boardingHouseId]);
    audit_log('listing_remove', $boardingHouseId, $listing['name'], $reason);
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

    // If the landlord deleted it, restore as pending: the rooms may no longer be for rent.
    $deletedByLandlord = $listing['deleted_by'] !== null
        && (int) $listing['deleted_by'] === (int) $listing['landlord_id'];
    $backToPending = $deletedByLandlord && $listing['moderation_status'] === 'approved';

    $pdo->prepare('UPDATE boarding_houses SET deleted_at = NULL, deleted_by = NULL, updated_by = ?'
        . ($backToPending
            ? ", moderation_status = 'pending', rejection_reason = NULL, moderated_at = NULL, moderated_by = NULL"
            : '')
        . ' WHERE boarding_house_id = ?')
        ->execute([current_user_id(), $boardingHouseId]);
    audit_log('listing_restore', $boardingHouseId, $listing['name'],
        $backToPending ? 'Back to pending, because its landlord had deleted it' : null);
    $sent = notify_landlord_of_decision($boardingHouseId, 'listing_restore');
    if ($backToPending) {
        $where = ' Its landlord had deleted it, so it is back with the pending listings,'
            . ' and boarders will see it once it is approved again.';
    } elseif ($listing['moderation_status'] === 'approved') {
        $where = ' It is visible to boarders again.';
    } else {
        $where = ' It is back with the ' . $listing['moderation_status'] . ' listings.';
    }
    flash_set('"' . $name . '" was restored.' . $where . $emailNote($sent), 'success');
    if (strpos($returnTo, 'listing.php') === false) {
        $returnTo = 'admin/manage_listings.php';
    }

} else {
    flash_set('Unknown listing action.', 'error');
    $goToNext = false;
}

// "Approve & next": go to the oldest pending listing, or the empty Pending tab.
if ($goToNext) {
    $next = pending_queue_after($boardingHouseId)['next'];
    $returnTo = $next === null
        ? 'admin/manage_listings.php?status=pending'
        : 'admin/listing.php?id=' . (int) $next['boarding_house_id'];
}

redirect($returnTo);
