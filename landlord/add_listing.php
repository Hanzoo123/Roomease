<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$errors = [];
$listing = [
    'name'                => '',
    'address'             => '',
    'monthly_rent'        => '',
    'room_type'           => 'Private Room',
    'room_capacity'       => 1,
    'availability_status' => 'available',
    'description'         => '',
    'contact_number'      => '',
    'house_rules'         => '',
];
$selectedAmens = [];
$selectedUtils = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (array_keys($listing) as $key) {
        $listing[$key] = trim($_POST[$key] ?? '');
    }
    $selectedAmens = $_POST['amenities'] ?? [];
    $rawUtils      = $_POST['utilities'] ?? [];
    $billingPolicy = $_POST['billing_policy'] ?? [];

    foreach ($rawUtils as $uId) {
        $uId = (int)$uId;
        $selectedUtils[$uId] = trim($billingPolicy[$uId] ?? 'Included in Rent');
    }

    if ($listing['name'] === '') $errors[] = 'Boarding house name is required.';
    if ($listing['address'] === '') $errors[] = 'Complete address in Baybay City is required.';
    if (!is_numeric($listing['monthly_rent']) || (float)$listing['monthly_rent'] < 0) {
        $errors[] = 'Enter a valid monthly rent amount.';
    }
    if (!ctype_digit((string)$listing['room_capacity']) || (int)$listing['room_capacity'] < 1) {
        $errors[] = 'Room capacity must be at least 1 person.';
    }
    if ($listing['contact_number'] === '') $errors[] = 'Landlord contact number is required.';
    if (!in_array($listing['availability_status'], ['available', 'unavailable'], true)) {
        $listing['availability_status'] = 'available';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO boarding_houses (landlord_id, name, address, monthly_rent, room_type, room_capacity, availability_status, description, contact_number, house_rules)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'],
            $listing['name'],
            $listing['address'],
            $listing['monthly_rent'],
            $listing['room_type'],
            (int)$listing['room_capacity'],
            $listing['availability_status'],
            $listing['description'],
            $listing['contact_number'],
            $listing['house_rules'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        // 1. Insert Amenities
        if (!empty($selectedAmens)) {
            $mapStmt = $pdo->query('SELECT amenity_name, amenity_id FROM amenities');
            $amenityMap = $mapStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $insAmen = $pdo->prepare('INSERT IGNORE INTO boarding_house_amenities (boarding_house_id, amenity_id, is_available) VALUES (?, ?, 1)');
            foreach ($selectedAmens as $amenName) {
                if (isset($amenityMap[$amenName])) {
                    $insAmen->execute([$newId, $amenityMap[$amenName]]);
                }
            }
        }

        // 2. Insert Utilities & Billing Policies
        if (!empty($selectedUtils)) {
            $insUtil = $pdo->prepare('INSERT IGNORE INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy) VALUES (?, ?, ?)');
            foreach ($selectedUtils as $uId => $policy) {
                $insUtil->execute([$newId, $uId, $policy ?: 'Included in Rent']);
            }
        }

        // 3. Handle Cover Photo Upload
        if (!empty($_FILES['photo']['name'])) {
            try {
                $path = handle_photo_upload('photo', $newId);
                if ($path) {
                    $pdo->prepare('INSERT INTO images (boarding_house_id, image_path, is_primary) VALUES (?, ?, 1)')
                        ->execute([$newId, $path]);
                }
            } catch (RuntimeException $e) {
                flash_set('Listing saved, but photo upload failed: ' . $e->getMessage(), 'error');
                redirect('landlord/dashboard.php');
            }
        }

        flash_set('Boarding house listing created successfully.', 'success');
        redirect('landlord/dashboard.php');
    }
}

$pageTitle = 'Add Boarding House Listing';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel panel-pad" style="max-width:680px; margin:0 auto;">
  <h1>Add a New Boarding House</h1>
  <p class="auth-sub">Fill in complete property specifications, utilities, and house rules for boarders in Baybay City.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?php require __DIR__ . '/../includes/listing_form.php'; ?>
    <button type="submit" class="btn btn-brass btn-block" style="margin-top:16px;">Publish Boarding House</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
