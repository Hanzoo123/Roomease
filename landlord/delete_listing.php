<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);

// Ownership check inside the WHERE clause itself
$stmt = $pdo->prepare('DELETE FROM boarding_houses WHERE boarding_house_id = ? AND landlord_id = ?');
$stmt->execute([$boardingHouseId, $_SESSION['user_id']]);

if ($stmt->rowCount() > 0) {
    // Clean up uploaded image files for this boarding house
    $dir = __DIR__ . '/../assets/uploads/boarding_houses/' . $boardingHouseId;
    if (is_dir($dir)) {
        array_map('unlink', glob("$dir/*.*") ?: []);
        rmdir($dir);
    }
    flash_set('Boarding house listing deleted.', 'success');
} else {
    flash_set('Listing not found, or you do not have permission to delete it.', 'error');
}

redirect('landlord/dashboard.php');
