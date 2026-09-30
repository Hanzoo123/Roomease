<?php
/**
 * A landlord deleting one of their own listings.
 *
 * The listing is archived, not erased: deleted_at and deleted_by are set, and
 * its rooms, photos and boarders' saves stay where they are. Every public,
 * boarder and landlord page already leaves archived listings out, so to
 * everyone but an administrator it is gone. An administrator sees it under
 * Manage Listings, Removed, marked as deleted by the landlord, and can
 * restore it if the landlord deleted it by mistake.
 */
require __DIR__ . '/../../includes/init.php';
require_login('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
$landlordId = (int) $_SESSION['user_id'];

// Its name, for the audit log.
$nameStmt = $pdo->prepare('SELECT name FROM boarding_houses WHERE boarding_house_id = ? AND landlord_id = ?');
$nameStmt->execute([$boardingHouseId, $landlordId]);
$listingName = (string) $nameStmt->fetchColumn();

// Ownership check inside the WHERE clause itself. A listing an administrator
// already removed is not the landlord's to delete again.
$stmt = $pdo->prepare(
    'UPDATE boarding_houses SET deleted_at = NOW(), deleted_by = ?, updated_by = ?
      WHERE boarding_house_id = ? AND landlord_id = ? AND deleted_at IS NULL'
);
$stmt->execute([$landlordId, $landlordId, $boardingHouseId, $landlordId]);

if ($stmt->rowCount() > 0) {
    audit_log('listing_delete', $boardingHouseId, $listingName);
    flash_set('"' . $listingName . '" was deleted. Boarders no longer see it. If this was a mistake, an administrator can restore it.', 'success');
} else {
    flash_set('Listing not found, or you do not have permission to delete it.', 'error');
}

redirect('landlord/dashboard.php');
