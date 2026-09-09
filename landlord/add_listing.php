<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$errors = [];
$listing = [
    'name'                => '',
    'address'             => '',
    'monthly_rent'        => '',
    'reservation_fee'     => '',
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
    // PHP drops $_POST and $_FILES wholesale when the body exceeds
    // post_max_size, which would otherwise look like a CSRF failure.
    if (post_too_large()) {
        flash_set('Those photos were too large to send in one submission (the server accepts about '
            . format_bytes(ini_bytes(ini_get('post_max_size'))) . ' at a time). Please upload fewer photos at once.', 'error');
        redirect('landlord/add_listing.php');
    }

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
    if ($listing['reservation_fee'] !== '' &&
        (!is_numeric($listing['reservation_fee']) || (float)$listing['reservation_fee'] < 0)) {
        $errors[] = 'Reservation fee must be a valid amount, or left blank if none is required.';
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
            'INSERT INTO boarding_houses (landlord_id, name, address, monthly_rent, reservation_fee, room_type, room_capacity, availability_status, description, contact_number, house_rules)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'],
            $listing['name'],
            $listing['address'],
            $listing['monthly_rent'],
            $listing['reservation_fee'] !== '' ? $listing['reservation_fee'] : null,
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

        // 3. Handle Photo Uploads. The first photo becomes the cover.
        try {
            $paths = handle_photo_uploads('photos', $newId);
            if ($paths) {
                $insImg = $pdo->prepare('INSERT INTO images (boarding_house_id, image_path, is_primary) VALUES (?, ?, ?)');
                foreach ($paths as $i => $path) {
                    $insImg->execute([$newId, $path, $i === 0 ? 1 : 0]);
                }
            }
        } catch (RuntimeException $e) {
            flash_set('Listing saved, but the photos could not be uploaded: ' . $e->getMessage(), 'error');
            redirect('landlord/edit_listing.php?id=' . $newId);
        }

        flash_set('Boarding house listing created successfully.', 'success');
        redirect('landlord/dashboard.php');
    }
}

$pageTitle = 'Add Listing';
require __DIR__ . '/../includes/panel_head.php';
require __DIR__ . '/../includes/panel_navbar.php';
require __DIR__ . '/../includes/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-plus-square text-primary mr-2"></i>Add Boarding House
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Add Listing</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <!-- /.content-header -->

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row justify-content-center">
        <div class="col-lg-9">

          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-clipboard-list mr-1"></i> New Listing Details
              </h3>
            </div>

            <?php if ($errors): ?>
              <div class="card-body pb-0">
                <div class="alert alert-danger mb-0">
                  <h6 class="font-weight-bold mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Please fix the
                    following:</h6>
                  <ul class="mb-0 pl-3">
                    <?php foreach ($errors as $e): ?>
                      <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" novalidate>
              <div class="card-body">
                <p class="text-muted">Fill in complete property specifications, utilities, and house rules for
                  boarders in Baybay City.</p>
                <?= csrf_field() ?>
                <?php require __DIR__ . '/../includes/listing_form.php'; ?>
              </div>
              <div class="card-footer d-flex justify-content-between">
                <a href="<?= base_url('landlord/dashboard.php') ?>" class="btn btn-default">
                  <i class="fas fa-arrow-left mr-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-check mr-1"></i> Publish Boarding House
                </button>
              </div>
            </form>
          </div>

        </div>
      </div>
    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>
