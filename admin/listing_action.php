<?php
/**
 * Administrator actions on a listing: approve it, reject it with a reason,
 * or remove it entirely.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

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

$stmt = $pdo->prepare('SELECT boarding_house_id, name FROM boarding_houses WHERE boarding_house_id = ?');
$stmt->execute([$boardingHouseId]);
$listing = $stmt->fetch();

if (!$listing) {
    flash_set('Listing not found.', 'error');
    redirect($returnTo);
}

if ($action === 'approve') {
    $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'approved', rejection_reason = NULL, moderated_at = NOW()
          WHERE boarding_house_id = ?"
    )->execute([$boardingHouseId]);
    flash_set('"' . $listing['name'] . '" is now approved and visible to boarders.', 'success');

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
    flash_set('"' . $listing['name'] . '" was rejected and the reason was sent to the landlord.', 'success');

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
