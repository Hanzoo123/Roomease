<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require_login('landlord');

$landlordId = (int) $_SESSION['user_id'];
$boardingHouseId = (int) ($_GET['id'] ?? $_POST['boarding_house_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM boarding_houses WHERE boarding_house_id = ? AND landlord_id = ? AND deleted_at IS NULL');
$stmt->execute([$boardingHouseId, $landlordId]);
$listing = $stmt->fetch();

if (!$listing) {
    flash_set('Boarding house not found, or you do not have permission to edit it.', 'error');
    redirect('landlord/dashboard.php');
}

$errors = [];

// Kept before the POST handler overwrites $listing with submitted values.
$storedModeration = $listing['moderation_status'];
$storedReason     = $listing['rejection_reason'];

// Selected amenities, by id
$amenStmt = $pdo->prepare('SELECT amenity_id FROM boarding_house_amenities WHERE boarding_house_id = ?');
$amenStmt->execute([$boardingHouseId]);
$selectedAmens = array_map('intval', $amenStmt->fetchAll(PDO::FETCH_COLUMN));

// Selected utilities + policies
$utilStmt = $pdo->prepare(
    'SELECT utility_id, billing_policy
     FROM boarding_house_utilities
     WHERE boarding_house_id = ?'
);
$utilStmt->execute([$boardingHouseId]);
$selectedUtils = $utilStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$newAmenityNames = [];
$newUtilityRows = [];

// House photos only; each room's photos are managed on its own page.
$imgStmt = $pdo->prepare(
    'SELECT * FROM images WHERE boarding_house_id = ? AND room_id IS NULL ORDER BY is_primary DESC, image_id ASC'
);
$imgStmt->execute([$boardingHouseId]);
$existingImages = $imgStmt->fetchAll();

// Rooms, in the order they were added
$roomStmt = $pdo->prepare(
    'SELECT r.*, rt.room_type_name,
            (SELECT COUNT(*) FROM images i WHERE i.room_id = r.room_id) AS photo_count
       FROM rooms r
       JOIN room_types rt ON rt.room_type_id = r.room_type_id
      WHERE r.boarding_house_id = ?
      ORDER BY r.room_id ASC'
);
$roomStmt->execute([$boardingHouseId]);
$rooms = $roomStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PHP drops $_POST and $_FILES wholesale when the body exceeds
    // post_max_size, which would otherwise look like a CSRF failure.
    if (post_too_large()) {
        flash_set('Those photos were too large to send in one submission (the server accepts about '
            . format_bytes(ini_bytes(ini_get('post_max_size'))) . ' at a time). Please upload fewer photos at once.', 'error');
        redirect('landlord/edit_listing.php?id=' . $boardingHouseId);
    }

    verify_csrf();

    foreach (['name', 'address', 'reservation_fee', 'availability_status', 'description', 'contact_number',
              'house_rules'] as $key) {
        $listing[$key] = trim($_POST[$key] ?? '');
    }
    [$stayTerms, $stayErrors, $stayEcho] = stay_terms_from_post($_POST);
    $listing = array_merge($listing, $stayEcho);

    $lookups = listing_lookups_from_post($_POST, $landlordId);
    $selectedAmens = $lookups['amenity_ids'];
    $selectedUtils = $lookups['utilities'];
    $newAmenityNames = $lookups['new_amenities'];
    $newUtilityRows = $lookups['new_utilities'];

    if ($listing['name'] === '') $errors[] = 'Boarding house name is required.';
    if ($listing['address'] === '') $errors[] = 'Complete address is required.';
    if ($listing['reservation_fee'] !== '' &&
        (!is_numeric($listing['reservation_fee']) || (float)$listing['reservation_fee'] < 0)) {
        $errors[] = 'Reservation fee must be a valid amount, or left blank if none is required.';
    }
    if ($listing['contact_number'] === '') $errors[] = 'Contact number is required.';
    if (!in_array($listing['availability_status'], ['available', 'unavailable'], true)) {
        $listing['availability_status'] = 'available';
    }
    $errors = array_merge($errors, $stayErrors, $lookups['errors']);

    if (!$errors) {
        $stayAssignments = implode(', ', array_map(function ($column) {
            return $column . '=?';
        }, STAY_TERM_COLUMNS));
        $stmt = $pdo->prepare(
            'UPDATE boarding_houses SET name=?, address=?, reservation_fee=?, availability_status=?, description=?,
             contact_number=?, house_rules=?, ' . $stayAssignments . '
             WHERE boarding_house_id=? AND landlord_id=?'
        );
        $stmt->execute(array_merge([
            $listing['name'], $listing['address'],
            $listing['reservation_fee'] !== '' ? $listing['reservation_fee'] : null,
            $listing['availability_status'],
            $listing['description'], $listing['contact_number'], $listing['house_rules'],
        ], array_values($stayTerms), [
            $boardingHouseId, $landlordId,
        ]));

        // A rejected listing has presumably just been corrected, so put it
        // back in the queue for another look. Approved listings stay approved.
        if ($storedModeration === 'rejected') {
            $pdo->prepare(
                "UPDATE boarding_houses
                    SET moderation_status = 'pending', rejection_reason = NULL, moderated_at = NULL
                  WHERE boarding_house_id = ? AND landlord_id = ?"
            )->execute([$boardingHouseId, $landlordId]);
        }

        save_listing_lookups($boardingHouseId, $landlordId, $lookups, true);

        // House photos. If the house has no cover yet, the first photo in
        // this batch becomes it.
        try {
            $paths = handle_photo_uploads('photos', $boardingHouseId);
            if ($paths) {
                $hasPrimary = $pdo->prepare(
                    'SELECT COUNT(*) FROM images WHERE boarding_house_id = ? AND room_id IS NULL AND is_primary = 1'
                );
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

$summary = listing_availability([
    'room_count' => count($rooms),
    'rooms_available' => count(array_filter($rooms, fn($r) => room_state($r)['key'] === 'available')),
    'rooms_open' => count(array_filter($rooms, fn($r) => !empty($r['is_open']))),
]);

$pageTitle = 'Edit Listing';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
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
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/listings.php') ?>">My Boarding Houses</a></li>
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
        <div class="col-lg-10">

          <!-- Rooms: first, because slot counts are what changes most often -->
          <div class="card card-primary card-outline shadow-sm" id="rooms">
            <div class="card-header d-flex flex-wrap align-items-center" style="gap: 8px;">
              <h3 class="card-title font-weight-bold mb-0">
                <i class="fas fa-door-open mr-1"></i> Rooms
              </h3>
              <span class="text-muted small" data-rooms-summary><?= h($summary['summary']) ?></span>
              <a href="<?= base_url('landlord/room_form.php?house=' . $boardingHouseId) ?>" class="btn btn-sm btn-primary ml-auto">
                <i class="fas fa-plus mr-1"></i> Add room
              </a>
            </div>

            <?php if (!$rooms): ?>
              <div class="card-body text-center py-5">
                <i class="fas fa-door-closed fa-3x text-secondary mb-3 d-block"></i>
                <h5 class="font-weight-bold">Add your first room</h5>
                <p class="text-muted mb-3" style="max-width: 460px; margin: 0 auto;">
                  Boarders see this listing once it has at least one room<?= $storedModeration === 'approved' ? '' : ' and an administrator approves it' ?>.
                  Each room has its own type, rent, capacity, and photos.
                </p>
                <a href="<?= base_url('landlord/room_form.php?house=' . $boardingHouseId) ?>" class="btn btn-primary">
                  <i class="fas fa-plus mr-1"></i> Add a room
                </a>
              </div>
            <?php else: ?>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover mb-0">
                    <thead>
                      <tr>
                        <th>Room</th>
                        <th>Type</th>
                        <th>Rent</th>
                        <th>Slots taken</th>
                        <th>Status</th>
                        <th>Photos</th>
                        <th class="text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rooms as $room): ?>
                        <?php $state = room_state($room); ?>
                        <tr data-room-row="<?= (int) $room['room_id'] ?>">
                          <td class="font-weight-bold align-middle"><?= h($room['name']) ?></td>
                          <td class="align-middle"><?= h($room['room_type_name']) ?></td>
                          <td class="align-middle">&#8369;<?= number_format((float) $room['monthly_rent'], 2) ?></td>
                          <td class="align-middle">
                            <div class="d-inline-flex align-items-center" style="gap: 6px;">
                              <form method="post" action="<?= base_url('landlord/room_action.php') ?>" data-room-action>
                                <?= csrf_field() ?>
                                <input type="hidden" name="room_id" value="<?= (int) $room['room_id'] ?>">
                                <input type="hidden" name="action" value="slots">
                                <input type="hidden" name="delta" value="-1">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-slot-minus
                                  aria-label="One fewer tenant in <?= h($room['name']) ?>"
                                  <?= (int) $room['slots_taken'] <= 0 ? 'disabled' : '' ?>>
                                  <i class="fas fa-minus"></i>
                                </button>
                              </form>
                              <span class="font-weight-bold text-nowrap" style="min-width: 48px; text-align: center;"
                                data-slots-text aria-live="polite">
                                <?= (int) $room['slots_taken'] ?> / <?= (int) $room['capacity'] ?>
                              </span>
                              <form method="post" action="<?= base_url('landlord/room_action.php') ?>" data-room-action>
                                <?= csrf_field() ?>
                                <input type="hidden" name="room_id" value="<?= (int) $room['room_id'] ?>">
                                <input type="hidden" name="action" value="slots">
                                <input type="hidden" name="delta" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-slot-plus
                                  aria-label="One more tenant in <?= h($room['name']) ?>"
                                  <?= (int) $room['slots_taken'] >= (int) $room['capacity'] ? 'disabled' : '' ?>>
                                  <i class="fas fa-plus"></i>
                                </button>
                              </form>
                            </div>
                          </td>
                          <td class="align-middle">
                            <span class="badge <?= $state['badge'] ?> px-2 py-1" data-room-state><?= h($state['label']) ?></span>
                          </td>
                          <td class="align-middle">
                            <a href="<?= base_url('landlord/room_form.php?id=' . (int) $room['room_id']) ?>#photos">
                              <i class="fas fa-images mr-1"></i><?= (int) $room['photo_count'] ?>
                            </a>
                          </td>
                          <td class="align-middle text-right text-nowrap">
                            <form method="post" action="<?= base_url('landlord/room_action.php') ?>" class="d-inline" data-room-action>
                              <?= csrf_field() ?>
                              <input type="hidden" name="room_id" value="<?= (int) $room['room_id'] ?>">
                              <input type="hidden" name="action" value="toggle_open">
                              <button type="submit" class="btn btn-xs btn-outline-secondary" data-toggle-open
                                title="<?= $room['is_open'] ? 'Stop taking tenants in this room' : 'Take tenants in this room again' ?>">
                                <?= $room['is_open'] ? 'Close' : 'Reopen' ?>
                              </button>
                            </form>
                            <a href="<?= base_url('landlord/room_form.php?id=' . (int) $room['room_id']) ?>"
                              class="btn btn-xs btn-outline-primary" title="Edit room">
                              <i class="fas fa-edit"></i>
                            </a>
                            <form method="post" action="<?= base_url('landlord/room_action.php') ?>" class="d-inline"
                              data-room-action data-confirm="Delete <?= h($room['name']) ?> and its photos? This cannot be undone.">
                              <?= csrf_field() ?>
                              <input type="hidden" name="room_id" value="<?= (int) $room['room_id'] ?>">
                              <input type="hidden" name="action" value="delete">
                              <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete room">
                                <i class="fas fa-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <div class="card-footer small text-muted">
                <i class="fas fa-info-circle mr-1"></i>
                Tap <strong>+</strong> when a tenant moves in and <strong>&minus;</strong> when one moves out. It saves
                straight away. Boarders only see how many slots are left, never who lives there.
              </div>
            <?php endif; ?>
          </div>

          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-clipboard-list mr-1"></i> House Details
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
                <?php require __DIR__ . '/../includes/components/listing_form.php'; ?>
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
                  <i class="fas fa-images mr-1"></i> Current House Photos
                </h3>
              </div>
              <div class="card-body">
                <p class="text-muted small">The cover photo is the one boarders see on the listing cards.</p>
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

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
<?php require __DIR__ . '/../includes/scripts/room_actions_js.php'; ?>
