<?php
/**
 * Administrator actions on a listing: approve it, reject it with a reason,
 * or remove it entirely.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_listings.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
// Older forms posted no action at all and always meant "delete".
$action = $_POST['action'] ?? 'delete';

// Send the admin back to the tab they were working in.
$returnStatus = $_POST['return_status'] ?? '';
$returnTo = in_array($returnStatus, ['pending', 'approved', 'rejected'], true)
    ? 'admin/manage_listings.php?status=' . $returnStatus
    : 'admin/manage_listings.php';

$stmt = $pdo->prepare(
    'SELECT bh.boarding_house_id, bh.name, u.is_active AS landlord_active, u.deleted_at AS landlord_deleted_at
       FROM boarding_houses bh
       JOIN users u ON u.user_id = bh.landlord_id
      WHERE bh.boarding_house_id = ?'
);
$stmt->execute([$boardingHouseId]);
$listing = $stmt->fetch();

if (!$listing) {
    flash_set('Listing not found.', 'error');
    redirect($returnTo);
}

if ($action === 'approve') {
    // A listing with no rooms has nothing for a boarder to see, and the public
    // site would not show it anyway, so it is not approved yet.
    $rooms = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE boarding_house_id = ?');
    $rooms->execute([$boardingHouseId]);
    if ((int) $rooms->fetchColumn() === 0) {
        flash_set('"' . strip_tags($listing['name']) . '" has no rooms yet. The landlord needs to add at least one before it can be approved.', 'error');
        redirect($returnTo);
    }

    // Approving would change nothing a boarder sees while the landlord's
    // account is off, and would quietly publish the listing the moment the
    // account came back, so it waits until the account is restored.
    if ($listing['landlord_deleted_at'] !== null || (int) $listing['landlord_active'] !== 1) {
        flash_set('"' . strip_tags($listing['name']) . '" cannot be approved while its landlord\'s account is removed or deactivated. Restore the account first.', 'error');
        redirect($returnTo);
    }

    $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'approved', rejection_reason = NULL, moderated_at = NOW()
          WHERE boarding_house_id = ?"
    )->execute([$boardingHouseId]);
    // The name is whatever the landlord typed. The toast escapes its message,
    // but stripping tags here as well keeps this safe even if that flash is
    // ever rendered somewhere that does not.
    flash_set('"' . strip_tags($listing['name']) . '" is now approved and visible to boarders.', 'success');

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
            SET moderation_status = 'rejected', rejection_reason = ?, moderated_at = NOW()
          WHERE boarding_house_id = ?"
    )->execute([$reason, $boardingHouseId]);
    flash_set('"' . strip_tags($listing['name']) . '" was rejected and the reason was sent to the landlord.', 'success');

} elseif ($action === 'delete') {
    $del = $pdo->prepare('DELETE FROM boarding_houses WHERE boarding_house_id = ?');
    $del->execute([$boardingHouseId]);

    if ($del->rowCount() > 0) {
        // Clean up uploaded images from disk
        $dir = __DIR__ . '/../assets/uploads/boarding_houses/' . $boardingHouseId;
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*.*") ?: []);
            rmdir($dir);
        }
        flash_set('Boarding house listing removed.', 'success');
    } else {
        flash_set('Listing not found.', 'error');
    }

} else {
    flash_set('Unknown listing action.', 'error');
}

redirect($returnTo);
