<?php
/**
 * "Still accurate": the landlord confirms a listing's rooms are as shown,
 * without changing any of them. Moves every room's updated_at to now, so the
 * listing shows "Updated just now" (see availability_freshness()). Not a
 * change boarders see, so it does not send the listing back for review.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_login('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
$landlordId = (int) $_SESSION['user_id'];
// Back to the page the button was on; only these two.
$back = ($_POST['return'] ?? '') === 'edit'
    ? 'landlord/edit_listing.php?id=' . $boardingHouseId . '#rooms'
    : 'landlord/dashboard.php';

// Ownership check inside the WHERE clause itself.
$nameStmt = $pdo->prepare(
    'SELECT name FROM boarding_houses WHERE boarding_house_id = ? AND landlord_id = ? AND deleted_at IS NULL'
);
$nameStmt->execute([$boardingHouseId, $landlordId]);
$listingName = $nameStmt->fetchColumn();
if ($listingName === false) {
    flash_set('Listing not found, or it is not one of yours.', 'error');
    redirect('landlord/dashboard.php');
}

$stmt = $pdo->prepare('UPDATE rooms SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE boarding_house_id = ?');
$stmt->execute([$landlordId, $boardingHouseId]);

if ($stmt->rowCount() > 0) {
    audit_log('rooms_confirm', $boardingHouseId, $listingName);
    flash_set('Thanks. "' . $listingName . '" now shows its availability was updated just now.', 'success');
} else {
    flash_set('"' . $listingName . '" has no rooms to confirm yet.', 'error');
}

redirect($back);
