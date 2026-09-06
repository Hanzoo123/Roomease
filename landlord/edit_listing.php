<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$boardingHouseId = (int) ($_GET['id'] ?? $_POST['boarding_house_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM boarding_houses WHERE boarding_house_id = ? AND landlord_id = ?');
$stmt->execute([$boardingHouseId, $_SESSION['user_id']]);
$listing = $stmt->fetch();

if (!$listing) {
    flash_set('Boarding house not found, or you do not have permission to edit it.', 'error');
    redirect('landlord/dashboard.php');
}

$errors = [];

// Load selected amenities
$amenStmt = $pdo->prepare(
    'SELECT a.amenity_name
     FROM boarding_house_amenities bha
     JOIN amenities a ON bha.amenity_id = a.amenity_id
     WHERE bha.boarding_house_id = ?'
);
$amenStmt->execute([$boardingHouseId]);
$selectedAmens = $amenStmt->fetchAll(PDO::FETCH_COLUMN);

// Load selected utilities + policies
$utilStmt = $pdo->prepare(
    'SELECT utility_id, billing_policy
     FROM boarding_house_utilities
     WHERE boarding_house_id = ?'
);
$utilStmt->execute([$boardingHouseId]);
$selectedUtils = $utilStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Load existing images
$imgStmt = $pdo->prepare('SELECT * FROM images WHERE boarding_house_id = ? ORDER BY is_primary DESC, image_id ASC');
$imgStmt->execute([$boardingHouseId]);
$existingImages = $imgStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (['name', 'address', 'monthly_rent', 'room_type', 'room_capacity',
              'availability_status', 'description', 'contact_number', 'house_rules'] as $key) {
        $listing[$key] = trim($_POST[$key] ?? '');
    }
    $selectedAmens = $_POST['amenities'] ?? [];
    $rawUtils      = $_POST['utilities'] ?? [];
    $billingPolicy = $_POST['billing_policy'] ?? [];

    $selectedUtils = [];
    foreach ($rawUtils as $uId) {
        $uId = (int)$uId;
        $selectedUtils[$uId] = trim($billingPolicy[$uId] ?? 'Included in Rent');
    }

    if ($listing['name'] === '') $errors[] = 'Boarding house name is required.';
    if ($listing['address'] === '') $errors[] = 'Complete address is required.';
    if (!is_numeric($listing['monthly_rent']) || (float)$listing['monthly_rent'] < 0) {
        $errors[] = 'Enter a valid monthly rent amount.';
    }
    if (!ctype_digit((string)$listing['room_capacity']) || (int)$listing['room_capacity'] < 1) {
        $errors[] = 'Room capacity must be at least 1.';
    }
    if ($listing['contact_number'] === '') $errors[] = 'Contact number is required.';
    if (!in_array($listing['availability_status'], ['available', 'unavailable'], true)) {
        $listing['availability_status'] = 'available';
    }

    if (!$errors) {
        // Update boarding house
        $stmt = $pdo->prepare(
            'UPDATE boarding_houses SET name=?, address=?, monthly_rent=?, room_type=?,
             room_capacity=?, availability_status=?, description=?, contact_number=?, house_rules=?
             WHERE boarding_house_id=? AND landlord_id=?'
        );
        $stmt->execute([
            $listing['name'], $listing['address'], $listing['monthly_rent'], $listing['room_type'],
            (int)$listing['room_capacity'], $listing['availability_status'],
            $listing['description'], $listing['contact_number'], $listing['house_rules'],
            $boardingHouseId, $_SESSION['user_id'],
        ]);

        // Sync amenities
        $pdo->prepare('DELETE FROM boarding_house_amenities WHERE boarding_house_id = ?')->execute([$boardingHouseId]);
        if (!empty($selectedAmens)) {
            $mapStmt = $pdo->query('SELECT amenity_name, amenity_id FROM amenities');
            $amenityMap = $mapStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $insAmen = $pdo->prepare('INSERT IGNORE INTO boarding_house_amenities (boarding_house_id, amenity_id, is_available) VALUES (?, ?, 1)');
            foreach ($selectedAmens as $amenName) {
                if (isset($amenityMap[$amenName])) {
                    $insAmen->execute([$boardingHouseId, $amenityMap[$amenName]]);
                }
            }
        }

        // Sync utilities
        $pdo->prepare('DELETE FROM boarding_house_utilities WHERE boarding_house_id = ?')->execute([$boardingHouseId]);
        if (!empty($selectedUtils)) {
            $insUtil = $pdo->prepare('INSERT IGNORE INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy) VALUES (?, ?, ?)');
            foreach ($selectedUtils as $uId => $policy) {
                $insUtil->execute([$boardingHouseId, $uId, $policy ?: 'Included in Rent']);
            }
        }

        // Handle photo upload
        if (!empty($_FILES['photo']['name'])) {
            try {
                $path = handle_photo_upload('photo', $boardingHouseId);
                if ($path) {
                    $hasPrimary = $pdo->prepare('SELECT COUNT(*) FROM images WHERE boarding_house_id=? AND is_primary=1');
                    $hasPrimary->execute([$boardingHouseId]);
                    $isPrimary = ((int)$hasPrimary->fetchColumn() === 0) ? 1 : 0;
                    $pdo->prepare('INSERT INTO images (boarding_house_id, image_path, is_primary) VALUES (?, ?, ?)')
                        ->execute([$boardingHouseId, $path, $isPrimary]);
                }
            } catch (RuntimeException $e) {
                flash_set('Listing updated, but photo upload failed: ' . $e->getMessage(), 'error');
                redirect('landlord/dashboard.php');
            }
        }

        flash_set('Boarding house listing updated successfully.', 'success');
        redirect('landlord/dashboard.php');
    }
}

$pageTitle = 'Edit Listing';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel panel-pad" style="max-width:680px; margin:0 auto;">
  <h1>Edit Boarding House</h1>
  <p class="auth-sub">Update property details, utilities, and amenities.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="boarding_house_id" value="<?= (int)$boardingHouseId ?>">
    <?php require __DIR__ . '/../includes/listing_form.php'; ?>
    <button type="submit" class="btn btn-brass btn-block" style="margin-top:16px;">Save Changes</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
