<?php
/**
 * Add or edit one room in a landlord's boarding house.
 *
 *   ?house=ID   add a room to that listing
 *   ?id=ROOM    edit that room
 *
 * A room has its own name, type, rent, capacity, slots taken, open/closed
 * switch, short description, and photos. Room photos live in the listing's
 * upload folder, tagged with the room's id.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$landlordId = (int) $_SESSION['user_id'];
$roomId = (int) ($_GET['id'] ?? $_POST['room_id'] ?? 0);
$room = null;

if ($roomId) {
    $room = find_landlord_room($roomId, $landlordId);
    if (!$room) {
        flash_set('Room not found, or it is not one of yours.', 'error');
        redirect('landlord/dashboard.php');
    }
    $houseId = (int) $room['boarding_house_id'];
    $houseName = $room['house_name'];
} else {
    $houseId = (int) ($_GET['house'] ?? $_POST['boarding_house_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT boarding_house_id, name FROM boarding_houses WHERE boarding_house_id = ? AND landlord_id = ?');
    $stmt->execute([$houseId, $landlordId]);
    $house = $stmt->fetch();
    if (!$house) {
        flash_set('Boarding house not found, or you do not have permission to edit it.', 'error');
        redirect('landlord/dashboard.php');
    }
    $houseName = $house['name'];
}

$roomTypes = room_type_options();

// Suggest the next free "Room N" for a new room.
$suggestedName = '';
if (!$room) {
    $names = $pdo->prepare('SELECT name FROM rooms WHERE boarding_house_id = ?');
    $names->execute([$houseId]);
    $taken = array_map('mb_strtolower', $names->fetchAll(PDO::FETCH_COLUMN));
    $n = count($taken) + 1;
    while (in_array('room ' . $n, $taken, true)) {
        $n++;
    }
    $suggestedName = 'Room ' . $n;
}

$form = $room ? [
    'name' => $room['name'],
    'room_type_id' => (string) $room['room_type_id'],
    'monthly_rent' => $room['monthly_rent'],
    'capacity' => (string) $room['capacity'],
    'slots_taken' => (string) $room['slots_taken'],
    'is_open' => (bool) $room['is_open'],
    'description' => (string) $room['description'],
] : blank_room($suggestedName);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = $room ? 'landlord/room_form.php?id=' . $roomId : 'landlord/room_form.php?house=' . $houseId;
    if (post_too_large()) {
        flash_set('Those photos were too large to send in one submission (the server accepts about '
            . format_bytes(ini_bytes(ini_get('post_max_size'))) . ' at a time). Please upload fewer photos at once.', 'error');
        redirect($back);
    }
    verify_csrf();

    [$form, $errors] = room_from_input($_POST, $roomTypes);

    if ($form['name'] !== '') {
        $dup = $pdo->prepare('SELECT room_id FROM rooms WHERE boarding_house_id = ? AND name = ? AND room_id <> ?');
        $dup->execute([$houseId, $form['name'], $roomId]);
        if ($dup->fetch()) {
            $errors[] = 'This listing already has a room called "' . $form['name'] . '".';
        }
    }

    if (!$errors) {
        if ($room) {
            $pdo->prepare(
                'UPDATE rooms SET name = ?, room_type_id = ?, monthly_rent = ?, capacity = ?, slots_taken = ?,
                                  is_open = ?, description = ?
                  WHERE room_id = ?'
            )->execute(array_merge(room_values($form), [$roomId]));
        } else {
            $roomId = insert_room($houseId, $form);
        }

        try {
            attach_room_photos($houseId, $roomId, 'photos');
        } catch (RuntimeException $e) {
            flash_set('Room saved, but the photos could not be uploaded: ' . $e->getMessage(), 'error');
            redirect('landlord/room_form.php?id=' . $roomId . '#photos');
        }

        flash_set('"' . $form['name'] . '" saved.', 'success');
        if (($_POST['next'] ?? '') === 'another') {
            redirect('landlord/room_form.php?house=' . $houseId);
        }
        redirect('landlord/edit_listing.php?id=' . $houseId . '#rooms');
    }
}

$photos = [];
if ($room) {
    $photoStmt = $pdo->prepare('SELECT * FROM images WHERE room_id = ? ORDER BY is_primary DESC, image_id ASC');
    $photoStmt->execute([$roomId]);
    $photos = $photoStmt->fetchAll();
}

$pageTitle = $room ? 'Edit Room' : 'Add Room';
require __DIR__ . '/../includes/panel_head.php';
require __DIR__ . '/../includes/panel_navbar.php';
require __DIR__ . '/../includes/panel_sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-door-open text-primary mr-2"></i><?= $room ? 'Edit Room' : 'Add a Room' ?>
          </h1>
          <p class="text-muted mb-0 mt-1"><?= h($houseName) ?></p>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/listings.php') ?>">My Boarding Houses</a></li>
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/edit_listing.php?id=' . $houseId) ?>#rooms"><?= h($houseName) ?></a></li>
            <li class="breadcrumb-item active"><?= $room ? h($room['name']) : 'Add room' ?></li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="row justify-content-center">
        <div class="col-lg-8">

          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-clipboard-list mr-1"></i> Room Details
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
                <?= csrf_field() ?>
                <?php if ($room): ?>
                  <input type="hidden" name="room_id" value="<?= (int) $roomId ?>">
                <?php else: ?>
                  <input type="hidden" name="boarding_house_id" value="<?= (int) $houseId ?>">
                <?php endif; ?>

                <div class="form-row">
                  <div class="col-md-6 form-group">
                    <label for="name">Room name</label>
                    <input type="text" class="form-control" id="name" name="name" maxlength="60" required
                      value="<?= h($form['name']) ?>" placeholder="e.g. Room 1, 2nd floor front">
                  </div>
                  <div class="col-md-6 form-group">
                    <label for="room_type_id">Room type</label>
                    <?php if (!$roomTypes): ?>
                      <?= lookup_unavailable_notice('room types', 'room_types') ?>
                    <?php endif; ?>
                    <select class="form-control" id="room_type_id" name="room_type_id" required>
                      <option value="">Choose a type</option>
                      <?php foreach ($roomTypes as $rtId => $rtName): ?>
                        <option value="<?= (int) $rtId ?>" <?= (string) $form['room_type_id'] === (string) $rtId ? 'selected' : '' ?>><?= h($rtName) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="form-row">
                  <div class="col-md-4 form-group">
                    <label for="monthly_rent">Monthly rent (&#8369;)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="monthly_rent" name="monthly_rent"
                      value="<?= h($form['monthly_rent']) ?>" placeholder="e.g. 2500" required>
                    <small class="form-text text-muted">Per person for bed spacers and dorms.</small>
                  </div>
                  <div class="col-md-4 form-group">
                    <label for="capacity">Capacity (people)</label>
                    <input type="number" step="1" min="1" max="100" class="form-control" id="capacity" name="capacity"
                      value="<?= h($form['capacity']) ?>" required>
                  </div>
                  <div class="col-md-4 form-group">
                    <label for="slots_taken">Slots taken</label>
                    <input type="number" step="1" min="0" max="100" class="form-control" id="slots_taken" name="slots_taken"
                      value="<?= h($form['slots_taken']) ?>" required>
                    <small class="form-text text-muted">How many tenants live here now.</small>
                  </div>
                </div>

                <div class="form-group">
                  <div class="custom-control custom-switch">
                    <input type="checkbox" class="custom-control-input" id="is_open" name="is_open" value="1"
                      <?= $form['is_open'] ? 'checked' : '' ?>>
                    <label class="custom-control-label" for="is_open">Open to new tenants</label>
                  </div>
                  <small class="form-text text-muted">
                    Turn off while the room is being repaired or kept for someone. Boarders see it as "Not available".
                  </small>
                </div>

                <div class="form-group">
                  <label for="description">Short description <span class="text-muted font-weight-normal">(optional)</span></label>
                  <textarea class="form-control" id="description" name="description" rows="2" maxlength="500"
                    placeholder="e.g. Window facing the garden, own cabinet, near the bathroom."><?= h($form['description']) ?></textarea>
                </div>

                <hr>

                <h5 class="font-weight-bold text-primary mb-1" id="photos">
                  <i class="fas fa-images mr-1"></i> Room Photos
                </h5>
                <p class="text-muted small mb-3">
                  Photos of this room only. JPG, PNG or WEBP, up to <?= format_bytes(max_upload_bytes()) ?> each and
                  <?= max_photos_per_upload() ?> per upload.
                  <?php if (!$photos): ?>The first photo becomes the room's main photo.<?php endif; ?>
                </p>
                <div class="form-group mb-0">
                  <label for="photos-input"><?= $photos ? 'Add more photos' : 'Upload photos' ?></label>
                  <input type="file" class="form-control-file" id="photos-input" name="photos[]"
                    accept="image/jpeg,image/png,image/webp" multiple>
                </div>
              </div>
              <div class="card-footer d-flex flex-wrap justify-content-between" style="gap: 8px;">
                <a href="<?= base_url('landlord/edit_listing.php?id=' . $houseId) ?>#rooms" class="btn btn-default">
                  <i class="fas fa-arrow-left mr-1"></i> Back to rooms
                </a>
                <div class="d-flex flex-wrap" style="gap: 8px;">
                  <?php if (!$room): ?>
                    <button type="submit" name="next" value="another" class="btn btn-outline-primary">
                      Save and add another
                    </button>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save room
                  </button>
                </div>
              </div>
            </form>
          </div>

          <?php if ($photos): ?>
            <div class="card card-outline card-secondary shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-images mr-1"></i> Current Room Photos
                </h3>
              </div>
              <div class="card-body">
                <p class="text-muted small">The main photo is shown first on the room's card on your listing page.</p>
                <div class="row">
                  <?php foreach ($photos as $img): ?>
                    <div class="col-md-3 col-sm-4 col-6 mb-3">
                      <div class="position-relative border rounded overflow-hidden <?= $img['is_primary'] ? 'border-success' : '' ?>"
                        style="height:110px; background:#f1f5f9;">
                        <img src="<?= h(base_url($img['image_path'])) ?>" alt="Photo of <?= h($room['name']) ?>"
                          style="width:100%; height:100%; object-fit:cover;">
                        <?php if ($img['is_primary']): ?>
                          <span class="badge badge-success position-absolute" style="top:4px; left:4px;">Main</span>
                        <?php endif; ?>
                      </div>
                      <div class="btn-group btn-group-sm d-flex mt-2" role="group">
                        <?php if (!$img['is_primary']): ?>
                          <form method="post" action="<?= base_url('landlord/photo_action.php') ?>" class="w-100">
                            <?= csrf_field() ?>
                            <input type="hidden" name="image_id" value="<?= (int) $img['image_id'] ?>">
                            <input type="hidden" name="action" value="set_primary">
                            <button type="submit" class="btn btn-sm btn-outline-primary btn-block" title="Make main photo">
                              <i class="fas fa-star"></i> Main
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
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>
