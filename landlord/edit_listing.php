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

// Kept before the POST handler overwrites $listing with submitted values.
$storedModeration = $listing['moderation_status'];
$storedReason     = $listing['rejection_reason'];

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
    // PHP drops $_POST and $_FILES wholesale when the body exceeds
    // post_max_size, which would otherwise look like a CSRF failure.
    if (post_too_large()) {
        flash_set('Those photos were too large to send in one submission (the server accepts about '
            . format_bytes(ini_bytes(ini_get('post_max_size'))) . ' at a time). Please upload fewer photos at once.', 'error');
        redirect('landlord/edit_listing.php?id=' . $boardingHouseId);
    }

    verify_csrf();

    foreach (['name', 'address', 'monthly_rent', 'reservation_fee', 'room_type', 'room_capacity',
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
    if ($listing['reservation_fee'] !== '' &&
        (!is_numeric($listing['reservation_fee']) || (float)$listing['reservation_fee'] < 0)) {
        $errors[] = 'Reservation fee must be a valid amount, or left blank if none is required.';
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
            'UPDATE boarding_houses SET name=?, address=?, monthly_rent=?, reservation_fee=?, room_type=?,
             room_capacity=?, availability_status=?, description=?, contact_number=?, house_rules=?
             WHERE boarding_house_id=? AND landlord_id=?'
        );
        $stmt->execute([
            $listing['name'], $listing['address'], $listing['monthly_rent'],
            $listing['reservation_fee'] !== '' ? $listing['reservation_fee'] : null,
            $listing['room_type'],
            (int)$listing['room_capacity'], $listing['availability_status'],
            $listing['description'], $listing['contact_number'], $listing['house_rules'],
            $boardingHouseId, $_SESSION['user_id'],
        ]);

        // A rejected listing has presumably just been corrected, so put it
        // back in the queue for another look. Approved listings stay approved.
        if ($storedModeration === 'rejected') {
            $pdo->prepare(
                "UPDATE boarding_houses
                    SET moderation_status = 'pending', rejection_reason = NULL, moderated_at = NULL
                  WHERE boarding_house_id = ? AND landlord_id = ?"
            )->execute([$boardingHouseId, $_SESSION['user_id']]);
        }

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

        // Handle photo uploads. If the listing has no cover yet, the first
        // photo in this batch becomes it.
        try {
            $paths = handle_photo_uploads('photos', $boardingHouseId);
            if ($paths) {
                $hasPrimary = $pdo->prepare('SELECT COUNT(*) FROM images WHERE boarding_house_id=? AND is_primary=1');
                $hasPrimary->execute([$boardingHouseId]);
                $needsPrimary = (int) $hasPrimary->fetchColumn() === 0;

                $insImg = $pdo->prepare('INSERT INTO images (boarding_house_id, image_path, is_primary) VALUES (?, ?, ?)');
                foreach ($paths as $i => $path) {
                    $insImg->execute([$boardingHouseId, $path, ($needsPrimary && $i === 0) ? 1 : 0]);
                }
            }
        } catch (RuntimeException $e) {
            flash_set('Listing updated, but the photos could not be uploaded: ' . $e->getMessage(), 'error');
            redirect('landlord/edit_listing.php?id=' . $boardingHouseId);
        }

        flash_set('Boarding house listing updated successfully.', 'success');
        redirect('landlord/dashboard.php');
    }
}

$pageTitle = 'Edit Listing';
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
            <i class="fas fa-edit text-primary mr-2"></i>Edit Boarding House
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active"><?= h($listing['name']) ?></li>
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
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-clipboard-list mr-1"></i> Listing Details
              </h3>
              <a href="<?= base_url('boarder/view_listing.php?id=' . $boardingHouseId) ?>" target="_blank"
                class="btn btn-sm btn-outline-info ml-auto">
                <i class="fas fa-eye mr-1"></i> Preview
              </a>
            </div>

            <?php if ($storedModeration === 'rejected'): ?>
              <div class="card-body pb-0">
                <div class="alert alert-danger mb-0">
                  <h6 class="font-weight-bold mb-1"><i class="fas fa-times-circle mr-1"></i> This listing was
                    rejected</h6>
                  <p class="mb-1"><?= h($storedReason ?: 'No reason was given.') ?></p>
                  <small class="text-muted">Fix the problem and save. It goes back to the administrator for
                    review.</small>
                </div>
              </div>
            <?php elseif ($storedModeration === 'pending'): ?>
              <div class="card-body pb-0">
                <div class="alert alert-warning mb-0">
                  <i class="fas fa-clock mr-1"></i> This listing is waiting for administrator approval and is not
                  visible to boarders yet.
                </div>
              </div>
            <?php endif; ?>

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
                <?= csrf_field() ?>
                <input type="hidden" name="boarding_house_id" value="<?= (int) $boardingHouseId ?>">
                <?php require __DIR__ . '/../includes/listing_form.php'; ?>
              </div>
              <div class="card-footer d-flex justify-content-between">
                <a href="<?= base_url('landlord/dashboard.php') ?>" class="btn btn-default">
                  <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                </a>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-save mr-1"></i> Save Changes
                </button>
              </div>
            </form>
          </div>

          <?php if ($existingImages): ?>
            <div class="card card-outline card-secondary shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-images mr-1"></i> Current Photos
                </h3>
              </div>
              <div class="card-body">
                <p class="text-muted small">The cover photo is the one boarders see on the browse page.</p>
                <div class="row">
                  <?php foreach ($existingImages as $img): ?>
                    <div class="col-md-3 col-sm-4 col-6 mb-3">
                      <div class="position-relative border rounded overflow-hidden
                        <?= $img['is_primary'] ? 'border-success' : '' ?>" style="height:110px; background:#f1f5f9;">
                        <img src="<?= h(base_url($img['image_path'])) ?>" alt="Listing photo"
                          style="width:100%; height:100%; object-fit:cover;">
                        <?php if ($img['is_primary']): ?>
                          <span class="badge badge-success position-absolute"
                            style="top:4px; left:4px;">Cover</span>
                        <?php endif; ?>
                      </div>
                      <div class="btn-group btn-group-sm d-flex mt-2" role="group">
                        <?php if (!$img['is_primary']): ?>
                          <form method="post" action="<?= base_url('landlord/photo_action.php') ?>" class="w-100">
                            <?= csrf_field() ?>
                            <input type="hidden" name="image_id" value="<?= (int) $img['image_id'] ?>">
                            <input type="hidden" name="action" value="set_primary">
                            <button type="submit" class="btn btn-sm btn-outline-primary btn-block" title="Make cover">
                              <i class="fas fa-star"></i> Cover
                            </button>
                          </form>
                        <?php endif; ?>
                        <form method="post" action="<?= base_url('landlord/photo_action.php') ?>" class="w-100"
                          onsubmit="return confirm('Remove this photo? This cannot be undone.');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="image_id" value="<?= (int) $img['image_id'] ?>">
                          <input type="hidden" name="action" value="delete">
                          <button type="submit" class="btn btn-sm btn-outline-danger btn-block" title="Remove photo">
                            <i class="fas fa-trash"></i> Remove
                          </button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>
