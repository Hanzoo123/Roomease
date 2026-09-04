<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
verify_csrf();

$listingId = (int) ($_POST['listing_id'] ?? 0);

// Ownership check happens in the WHERE clause itself.
$stmt = $pdo->prepare('DELETE FROM listings WHERE listing_id = ? AND landlord_id = ?');
$stmt->execute([$listingId, $_SESSION['user_id']]);

if ($stmt->rowCount() > 0) {
    // Clean up uploaded photo files for this listing.
    $dir = __DIR__ . '/../assets/uploads/listings/' . $listingId;
    if (is_dir($dir)) {
        array_map('unlink', glob("$dir/*.*") ?: []);
        rmdir($dir);
    }
    flash_set('Listing deleted.', 'success');
} else {
    flash_set('Listing not found, or you do not have permission to delete it.', 'error');
}

redirect('landlord/dashboard.php');
