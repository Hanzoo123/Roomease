<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$errors = [];
$listing = [
    'boarding_house_name' => '', 'address' => '', 'city' => '',
    'monthly_rent' => '', 'reservation_fee' => '', 'room_type' => 'Private Room',
    'room_capacity' => 1, 'electricity_payment' => '', 'water_payment' => '',
    'house_rules' => '', 'contact_info' => '',
];
$selectedAmens = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (array_keys($listing) as $key) {
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

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO listings (landlord_id, boarding_house_name, address, city, monthly_rent,
                reservation_fee, room_type, room_capacity, electricity_payment, water_payment,
                house_rules, contact_info, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "available")'
        );
        $stmt->execute([
            $_SESSION['user_id'],
            $listing['boarding_house_name'],
            $listing['address'],
            $listing['city'],
            $listing['monthly_rent'],
            $listing['reservation_fee'] !== '' ? $listing['reservation_fee'] : null,
            $listing['room_type'],
            (int)$listing['room_capacity'],
            $listing['electricity_payment'],
            $listing['water_payment'],
            $listing['house_rules'],
            $listing['contact_info'],
        ]);
        $newId = $pdo->lastInsertId();

        if (!empty($selectedAmens)) {
            $mapStmt = $pdo->query('SELECT amenity_id, amenity_name FROM amenities');
            $amenityMap = $mapStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $insAmen = $pdo->prepare('INSERT INTO listing_amenities (listing_id, amenity_id) VALUES (?, ?)');
            foreach ($selectedAmens as $amenName) {
                if (isset($amenityMap[$amenName])) {
                    $insAmen->execute([$newId, $amenityMap[$amenName]]);
                }
            }
        }

        if (!empty($_FILES['photo']['name'])) {
            try {
                $path = handle_photo_upload('photo', $newId);
                if ($path) {
                    $pdo->prepare('INSERT INTO listing_photos (listing_id, photo_path, is_primary) VALUES (?, ?, 1)')
                        ->execute([$newId, $path]);
                }
            } catch (RuntimeException $e) {
                // Listing is already saved; just flag the photo issue.
                flash_set('Listing saved, but photo upload failed: ' . $e->getMessage(), 'error');
                redirect('landlord/dashboard.php');
            }
        }

        flash_set('Listing created successfully.', 'success');
        redirect('landlord/dashboard.php');
    }
}

$pageTitle = 'Add Listing';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel panel-pad" style="max-width:680px; margin:0 auto;">
  <h1>Add a New Listing</h1>
  <p class="auth-sub">Fill in complete details so boarders can decide before contacting you.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?php require __DIR__ . '/../includes/listing_form.php'; ?>
    <button type="submit" class="btn btn-brass btn-block">Publish Listing</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
