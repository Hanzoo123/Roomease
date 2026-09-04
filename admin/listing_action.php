<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_listings.php');
}
verify_csrf();

$listingId = (int) ($_POST['listing_id'] ?? 0);

$stmt = $pdo->prepare('DELETE FROM listings WHERE listing_id = ?');
$stmt->execute([$listingId]);

if ($stmt->rowCount() > 0) {
    $dir = __DIR__ . '/../assets/uploads/listings/' . $listingId;
    if (is_dir($dir)) {
        array_map('unlink', glob("$dir/*.*") ?: []);
        rmdir($dir);
    }
    flash_set('Listing removed.', 'success');
} else {
    flash_set('Listing not found.', 'error');
}

redirect('admin/manage_listings.php');
