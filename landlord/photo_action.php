<?php
/** Delete a photo, or make it the cover (house photos) or main photo (room photos). */
require __DIR__ . '/../includes/init.php';
require_login('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
verify_csrf();

$imageId = (int) ($_POST['image_id'] ?? 0);
$action  = $_POST['action'] ?? '';

// Ownership check lives in the join: a landlord can only touch photos that
// hang off one of their own boarding houses.
$stmt = $pdo->prepare(
    'SELECT i.image_id, i.boarding_house_id, i.room_id, i.image_path, i.is_primary,
            bh.name AS house_name, r.name AS room_name
       FROM images i
       JOIN boarding_houses bh ON bh.boarding_house_id = i.boarding_house_id
       LEFT JOIN rooms r ON r.room_id = i.room_id
      WHERE i.image_id = ? AND bh.landlord_id = ? AND bh.deleted_at IS NULL'
);
$stmt->execute([$imageId, $_SESSION['user_id']]);
$image = $stmt->fetch();

if (!$image) {
    flash_set('Photo not found, or you do not have permission to change it.', 'error');
    redirect('landlord/dashboard.php');
}

$boardingHouseId = (int) $image['boarding_house_id'];
$roomId = $image['room_id'] === null ? null : (int) $image['room_id'];

// "The same set of photos": the house photos, or one room's photos.
$scope = $roomId === null ? 'boarding_house_id = ? AND room_id IS NULL' : 'room_id = ?';
$scopeParam = $roomId === null ? $boardingHouseId : $roomId;

if ($action === 'delete') {
    // Only ever unlink inside the uploads folder, whatever the stored path says.
    $uploadRoot = realpath(__DIR__ . '/../assets/uploads');
    $target     = realpath(__DIR__ . '/../' . $image['image_path']);
    if ($uploadRoot && $target && strpos($target, $uploadRoot) === 0 && is_file($target)) {
        @unlink($target);
    }

    $pdo->prepare('DELETE FROM images WHERE image_id = ?')->execute([$imageId]);
    audit_log('photo_remove', $boardingHouseId, $image['house_name'],
        $roomId === null ? 'A house photo' : 'A photo of ' . $image['room_name']);

    // Promote the next photo in the same set when its cover was removed, so a
    // set with photos always has one.
    if ($image['is_primary']) {
        $next = $pdo->prepare("SELECT image_id FROM images WHERE $scope ORDER BY image_id ASC LIMIT 1");
        $next->execute([$scopeParam]);
        $nextId = $next->fetchColumn();
        if ($nextId) {
            $pdo->prepare('UPDATE images SET is_primary = 1, updated_by = ? WHERE image_id = ?')
                ->execute([current_user_id(), $nextId]);
        }
    }

    flash_set('Photo removed.', 'success');
} elseif ($action === 'set_primary') {
    $pdo->prepare("UPDATE images SET is_primary = 0, updated_by = ? WHERE $scope")->execute([current_user_id(), $scopeParam]);
    $pdo->prepare('UPDATE images SET is_primary = 1, updated_by = ? WHERE image_id = ?')->execute([current_user_id(), $imageId]);
    flash_set($roomId === null ? 'Cover photo updated.' : 'Main room photo updated.', 'success');
} else {
    flash_set('Unknown photo action.', 'error');
}

redirect($roomId === null
    ? 'landlord/edit_listing.php?id=' . $boardingHouseId
    : 'landlord/room_form.php?id=' . $roomId . '#photos');
