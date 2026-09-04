<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$listingId = (int) ($_GET['id'] ?? $_POST['listing_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM listings WHERE listing_id = ? AND landlord_id = ?');
$stmt->execute([$listingId, $_SESSION['user_id']]);
$listing = $stmt->fetch();

if (!$listing) {
    flash_set('Listing not found, or you do not have permission to edit it.', 'error');
    redirect('landlord/dashboard.php');
}

$errors = [];
$amenStmt = $pdo->prepare(
    'SELECT a.amenity_name 
     FROM listing_amenities la 
     JOIN amenities a ON la.amenity_id = a.amenity_id 
     WHERE la.listing_id = ?'
);
$amenStmt->execute([$listingId]);
$selectedAmens = $amenStmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (['boarding_house_name', 'address', 'city', 'monthly_rent', 'reservation_fee',
              'room_type', 'room_capacity', 'electricity_payment', 'water_payment',
              'house_rules', 'contact_info', 'status'] as $key) {
        $listing[$key] = trim($_POST[$key] ?? '');
    }
    $selectedAmens = $_POST['amenities'] ?? [];

    if ($listing['boarding_house_name'] === '') $errors[] = 'Boarding house name is required.';
    if ($listing['address'] === '') $errors[] = 'Address is required.';
    if ($listing['city'] === '') $errors[] = 'City is required.';
    if (!is_numeric($listing['monthly_rent']) || $listing['monthly_rent'] < 0) $errors[] = 'Enter a valid monthly rent.';
    if ($listing['reservation_fee'] !== '' && (!is_numeric($listing['reservation_fee']) || $listing['reservation_fee'] < 0)) {
        $errors[] = 'Enter a valid reservation fee, or leave it blank.';
    }
    if (!in_array($listing['room_type'], ['Private Room', 'Bed Spacer'], true)) $errors[] = 'Invalid room type.';
    if (!ctype_digit((string)$listing['room_capacity']) || (int)$listing['room_capacity'] < 1) $errors[] = 'Room capacity must be at least 1.';
    if ($listing['contact_info'] === '') $errors[] = 'Contact information is required.';
    if (!in_array($listing['status'], ['available', 'full', 'inactive'], true)) $errors[] = 'Invalid status.';

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE listings SET boarding_house_name=?, address=?, city=?, monthly_rent=?, reservation_fee=?,
                room_type=?, room_capacity=?, electricity_payment=?, water_payment=?,
                house_rules=?, contact_info=?, status=?
             WHERE listing_id=? AND landlord_id=?'
        );
        $stmt->execute([
            $listing['boarding_house_name'], $listing['address'], $listing['city'],
            $listing['monthly_rent'], $listing['reservation_fee'] !== '' ? $listing['reservation_fee'] : null,
            $listing['room_type'], (int)$listing['room_capacity'],
            $listing['electricity_payment'], $listing['water_payment'],
            $listing['house_rules'], $listing['contact_info'],
            $listing['status'], $listingId, $_SESSION['user_id'],
        ]);

        // Clear existing associations
        $del = $pdo->prepare('DELETE FROM listing_amenities WHERE listing_id = ?');
        $del->execute([$listingId]);

        // Insert new associations
        if (!empty($selectedAmens)) {
            $mapStmt = $pdo->query('SELECT amenity_id, amenity_name FROM amenities');
            $amenityMap = $mapStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $insAmen = $pdo->prepare('INSERT INTO listing_amenities (listing_id, amenity_id) VALUES (?, ?)');
            foreach ($selectedAmens as $amenName) {
                if (isset($amenityMap[$amenName])) {
                    $insAmen->execute([$listingId, $amenityMap[$amenName]]);
                }
            }
        }

        if (!empty($_FILES['photo']['name'])) {
            try {
                $path = handle_photo_upload('photo', $listingId);
                if ($path) {
                    $hasPrimary = $pdo->prepare('SELECT COUNT(*) c FROM listing_photos WHERE listing_id=? AND is_primary=1');
                    $hasPrimary->execute([$listingId]);
                    $isPrimary = ((int)$hasPrimary->fetch()['c'] === 0) ? 1 : 0;
                    $pdo->prepare('INSERT INTO listing_photos (listing_id, photo_path, is_primary) VALUES (?, ?, ?)')
                        ->execute([$listingId, $path, $isPrimary]);
                }
            } catch (RuntimeException $e) {
                flash_set('Listing updated, but photo upload failed: ' . $e->getMessage(), 'error');
                redirect('landlord/dashboard.php');
            }
        }

        flash_set('Listing updated successfully.', 'success');
        redirect('landlord/dashboard.php');
    }
}

$photosStmt = $pdo->prepare('SELECT * FROM listing_photos WHERE listing_id = ? ORDER BY is_primary DESC, photo_id ASC');
$photosStmt->execute([$listingId]);
$photos = $photosStmt->fetchAll();

$pageTitle = 'Edit Listing';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel panel-pad" style="max-width:680px; margin:0 auto;">
  <h1>Edit Listing</h1>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div>
  <?php endif; ?>

  <?php if ($photos): ?>
    <label>Current photos</label>
    <div class="detail-thumbs" style="margin-bottom:18px;">
      <?php foreach ($photos as $p): ?>
        <img src="<?= h(base_url($p['photo_path'])) ?>" alt="Listing photo">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="listing_id" value="<?= (int)$listingId ?>">
    <?php require __DIR__ . '/../includes/listing_form.php'; ?>
    <button type="submit" class="btn btn-brass btn-block">Save Changes</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
