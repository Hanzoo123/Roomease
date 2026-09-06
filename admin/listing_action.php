<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
    redirect('admin/manage_listings.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_listings.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);

$stmt = $pdo->prepare('DELETE FROM boarding_houses WHERE boarding_house_id = ?');
$stmt->execute([$boardingHouseId]);

if ($stmt->rowCount() > 0) {
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

redirect('admin/manage_listings.php');
