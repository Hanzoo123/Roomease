<?php
/**
 * A new listing: the house and its rooms in one form. A listing is not shown
 * to boarders until it has at least one room, so at least one is required
 * here. More rooms, photos, and slot counts can be changed later from the
 * listing's Rooms card.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

/** Rooms accepted in one submission; a larger house adds the rest afterwards. */
const MAX_ROOMS_PER_FORM = 30;

$landlordId = (int) $_SESSION['user_id'];
$roomTypes = room_type_options();
$formRooms = [0 => blank_room('Room 1')];
$formRoomsPosted = false;
$errors = [];
$listing = [
    'name'                => '',
    'address'             => '',
    'reservation_fee'     => '',
    'availability_status' => 'available',
    'description'         => '',
    'contact_number'      => '',
    'house_rules'         => '',
];
$baseKeys = array_keys($listing);
$listing += array_fill_keys(STAY_TERM_COLUMNS, '');
$selectedAmens = [];
$selectedUtils = [];
$newAmenityNames = [];
$newUtilityRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PHP drops $_POST and $_FILES wholesale when the body exceeds
    // post_max_size, which would otherwise look like a CSRF failure.
    if (post_too_large()) {
        flash_set('Those photos were too large to send in one submission (the server accepts about '
            . format_bytes(ini_bytes(ini_get('post_max_size'))) . ' at a time). Please upload fewer photos at once.', 'error');
        redirect('landlord/add_listing.php');
    }

    verify_csrf();

    foreach ($baseKeys as $key) {
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
    if ($listing['address'] === '') $errors[] = 'Complete address in Baybay City is required.';
    if ($listing['reservation_fee'] !== '' &&
        (!is_numeric($listing['reservation_fee']) || (float)$listing['reservation_fee'] < 0)) {
        $errors[] = 'Reservation fee must be a valid amount, or left blank if none is required.';
    }
    if ($listing['contact_number'] === '') $errors[] = 'Landlord contact number is required.';
    if (!in_array($listing['availability_status'], ['available', 'unavailable'], true)) {
        $listing['availability_status'] = 'available';
    }

    // Rooms arrive as rooms[<index>][field], each with its photos in
    // room_photos_<index>. The index only pairs the two; it is checked to be
    // a plain number so it is safe to build the photo field name from.
    $formRoomsPosted = true;
    $formRooms = [];
    $roomErrors = [];
    $seenNames = [];
    $position = 0;
    foreach ((array) ($_POST['rooms'] ?? []) as $idx => $input) {
        if (!ctype_digit((string) $idx) || !is_array($input)) {
            continue;
        }
        if (++$position > MAX_ROOMS_PER_FORM) {
            $roomErrors[] = 'Add at most ' . MAX_ROOMS_PER_FORM . ' rooms at once. You can add the rest from the listing page after saving.';
            break;
        }
        $typedName = normalise_lookup_name(is_string($input['name'] ?? null) ? $input['name'] : '');
        [$room, $problems] = room_from_input($input, $roomTypes, $typedName !== '' ? $typedName : 'Room ' . $position);

        $key = mb_strtolower($room['name']);
        if ($room['name'] !== '' && isset($seenNames[$key])) {
            $problems[] = 'Two rooms are called "' . $room['name'] . '". Give each room its own name.';
        }
        $seenNames[$key] = true;

        $formRooms[(int) $idx] = $room;
        $roomErrors = array_merge($roomErrors, $problems);
    }
    if (!$formRooms) {
        $roomErrors[] = 'Add at least one room, with its type and rent, so boarders have something to see.';
        $formRooms = [0 => blank_room('Room 1')];
    }

    $errors = array_merge($errors, $stayErrors, $lookups['errors'], array_unique($roomErrors));

    if (!$errors) {
        // The house, its utilities and amenities, and its rooms are saved
        // together, so a failure part-way never leaves a listing with no rooms.
        $pdo->beginTransaction();
        try {
        $columns = array_merge(
            ['landlord_id', 'name', 'address', 'reservation_fee',
             'availability_status', 'description', 'contact_number', 'house_rules'],
            STAY_TERM_COLUMNS
        );
        $stmt = $pdo->prepare(
            'INSERT INTO boarding_houses (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_merge([
            $landlordId,
            $listing['name'],
            $listing['address'],
            $listing['reservation_fee'] !== '' ? $listing['reservation_fee'] : null,
            $listing['availability_status'],
            $listing['description'],
            $listing['contact_number'],
            $listing['house_rules'],
        ], array_values($stayTerms)));
        $newId = (int)$pdo->lastInsertId();

        save_listing_lookups($newId, $landlordId, $lookups, false);

        $roomIds = [];
        foreach ($formRooms as $idx => $room) {
            $roomIds[$idx] = insert_room($newId, $room);
        }

        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Photos are moved into place only once the listing and its rooms are
        // saved. A refused photo does not undo the listing: the landlord is sent
        // to it with the reason, and can upload again from there.
        $photoErrors = [];
        try {
            $paths = handle_photo_uploads('photos', $newId);
            if ($paths) {
                $insImg = $pdo->prepare('INSERT INTO images (boarding_house_id, image_path, is_primary) VALUES (?, ?, ?)');
                foreach ($paths as $i => $path) {
                    $insImg->execute([$newId, $path, $i === 0 ? 1 : 0]);
                }
            }
        } catch (RuntimeException $e) {
            $photoErrors[] = 'House photos: ' . $e->getMessage();
        }
        foreach ($roomIds as $idx => $roomId) {
            try {
                attach_room_photos($newId, $roomId, 'room_photos_' . $idx);
            } catch (RuntimeException $e) {
                $photoErrors[] = $formRooms[$idx]['name'] . ' photos: ' . $e->getMessage();
            }
        }

        $roomCount = count($roomIds);
        $saved = 'Listing saved with ' . $roomCount . ' ' . ($roomCount === 1 ? 'room' : 'rooms') . '.';
        if ($photoErrors) {
            flash_set($saved . ' Some photos could not be uploaded. ' . implode(' ', $photoErrors), 'error');
            redirect('landlord/edit_listing.php?id=' . $newId . '#rooms');
        }

        flash_set($saved . ' Boarders will see it once an administrator approves it.', 'success');
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
                <p class="text-muted">Fill in the property details, its rooms, utilities, and house rules for
                  boarders in Baybay City.</p>
                <?= csrf_field() ?>
                <?php require __DIR__ . '/../includes/listing_form.php'; ?>
              </div>
              <div class="card-footer d-flex justify-content-between">
                <a href="<?= base_url('landlord/dashboard.php') ?>" class="btn btn-default">
                  <i class="fas fa-arrow-left mr-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-check mr-1"></i> Submit listing
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
