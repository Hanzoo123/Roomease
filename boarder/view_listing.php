<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require_once __DIR__ . '/../includes/components/icons.php';

$listingId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
  "SELECT bh.*,
            u.first_name AS landlord_first_name,
            u.last_name AS landlord_last_name,
            CONCAT(u.first_name, ' ', u.last_name) AS landlord_name,
            u.phone_number AS landlord_phone,
            u.email AS landlord_email,
            u.is_active AS landlord_active,
            u.deleted_at AS landlord_deleted_at,
            u.avatar_path AS landlord_avatar
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id
     WHERE bh.boarding_house_id = ?"
);
$stmt->execute([$listingId]);
$listing = $stmt->fetch();

// A listing that has not been approved yet is visible only to the landlord who
// owns it and to administrators, so they can preview it. To everyone else it
// simply does not exist.
$isOwner = $listing && is_logged_in() && current_role() === 'landlord'
    && (int) $listing['landlord_id'] === (int) $_SESSION['user_id'];
$canPreview = $isOwner || is_admin();

if ($listing && $listing['moderation_status'] !== 'approved' && !$canPreview) {
    $listing = false;
}

// A listing whose landlord has been deactivated or removed is off the site for
// everyone except an administrator, who still needs to be able to review it.
$landlordLive = $listing && $listing['landlord_deleted_at'] === null && (int) $listing['landlord_active'] === 1;
if ($listing && !$landlordLive && !$isOwner && !is_admin()) {
    $listing = false;
}

// A listing an administrator removed is archived. Only administrators can still
// open it, to review it or restore it; for everyone else, its landlord included,
// it is gone.
if ($listing && $listing['deleted_at'] !== null && !is_admin()) {
    $listing = false;
}

if (!$listing) {
  $pageTitle = 'Listing not found';
  $band = [
    'back' => ['href' => base_url('boarder/browse.php'), 'label' => 'All boarding houses'],
    'title' => 'Listing not found',
  ];
  require __DIR__ . '/../includes/layouts/header.php';
  echo '<p class="rooms-empty on-seam">This listing does not exist or has been removed. <a href="'
    . base_url('boarder/browse.php') . '">Browse other rooms</a></p>';
  require __DIR__ . '/../includes/layouts/footer.php';
  exit;
}

// House photos first (cover, then the rest), then each room's photos.
$photosStmt = $pdo->prepare(
  'SELECT * FROM images WHERE boarding_house_id = ?
    ORDER BY room_id IS NULL DESC, room_id, is_primary DESC, image_id ASC'
);
$photosStmt->execute([$listingId]);
$housePhotos = [];
$roomPhotos = [];
foreach ($photosStmt->fetchAll() as $p) {
  if ($p['room_id'] === null) {
    $housePhotos[] = $p;
  } else {
    $roomPhotos[(int) $p['room_id']][] = $p;
  }
}
// A listing with only room photos still gets a gallery at the top.
$photos = $housePhotos ?: array_merge([], ...array_values($roomPhotos));

// Rooms: available first, then full, then closed; in the order added within each.
$roomStmt = $pdo->prepare(
  'SELECT r.*, rt.room_type_name
     FROM rooms r
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
    WHERE r.boarding_house_id = ?
    ORDER BY r.room_id ASC'
);
$roomStmt->execute([$listingId]);
$rooms = $roomStmt->fetchAll();
usort($rooms, function ($a, $b) {
  return [room_state_rank($a), (int) $a['room_id']] <=> [room_state_rank($b), (int) $b['room_id']];
});

$roomTypeCounts = array_count_values(array_column($rooms, 'room_type_name'));
$availableRooms = array_filter($rooms, fn($r) => room_state($r)['key'] === 'available');
$avail = listing_availability([
  'room_count' => count($rooms),
  'rooms_available' => count($availableRooms),
  'rooms_open' => count(array_filter($rooms, fn($r) => !empty($r['is_open']))),
  'rent_from_available' => $availableRooms ? min(array_column($availableRooms, 'monthly_rent')) : null,
  'rent_from_all' => $rooms ? min(array_column($rooms, 'monthly_rent')) : null,
]);

$amenStmt = $pdo->prepare(
  'SELECT a.amenity_name
     FROM boarding_house_amenities bha
     JOIN amenities a ON bha.amenity_id = a.amenity_id
     WHERE bha.boarding_house_id = ? AND bha.is_available = 1'
);
$amenStmt->execute([$listingId]);
$amenities = $amenStmt->fetchAll(PDO::FETCH_COLUMN);

$utilStmt = $pdo->prepare(
  'SELECT ut.utility_name, bhu.billing_policy
     FROM boarding_house_utilities bhu
     JOIN utilities ut ON ut.utility_id = bhu.utility_id
     WHERE bhu.boarding_house_id = ?
     ORDER BY ut.utility_name'
);
$utilStmt->execute([$listingId]);
$utilities = $utilStmt->fetchAll();

$isSaved = can_save_listings()
  && isset(saved_listing_ids($_SESSION['user_id'])[$listingId]);

/* ---------------------------------------------------------------------------
 * Stay terms. Each is shown only when the landlord has stated it: NULL means
 * "not stated", and the page says nothing rather than guessing. `?? null`
 * keeps the page working on a database that has not run
 * migration_stay_terms.sql yet.
 * ------------------------------------------------------------------------ */
$genderLabel = gender_policy_options()[$listing['gender_policy'] ?? ''] ?? null;

$livingRules = [];
foreach ([
  'visitors_allowed' => ['Visitors allowed', 'No visitors'],
  'pets_allowed' => ['Pets allowed', 'No pets'],
  'cooking_allowed' => ['Cooking allowed', 'No cooking'],
] as $column => [$yesLabel, $noLabel]) {
  $answer = $listing[$column] ?? null;
  if ($answer !== null) {
    $livingRules[] = (int) $answer === 1 ? ['yes', $yesLabel] : ['no', $noLabel];
  }
}

$terms = [];
if (($listing['curfew'] ?? null) !== null) {
  $terms[] = ['clock', 'Curfew hours', $listing['curfew']];
}
if (($listing['security_deposit'] ?? null) !== null) {
  $terms[] = ['shield', 'Security deposit',
    (float) $listing['security_deposit'] == 0 ? 'No deposit required' : peso_round($listing['security_deposit'])];
}
if (!empty($listing['minimum_stay_months'])) {
  $months = (int) $listing['minimum_stay_months'];
  $terms[] = ['calendar', 'Minimum stay', $months . ' ' . ($months === 1 ? 'month' : 'months')];
}
$paymentLabel = payment_methods_label($listing['payment_methods'] ?? '');
if ($paymentLabel !== '') {
  $terms[] = ['card', 'Payment options', $paymentLabel];
}
$terms[] = ['key', 'Reservation fee',
  ($listing['reservation_fee'] === null || $listing['reservation_fee'] === '') ? 'Not required' : peso_round($listing['reservation_fee'])];

// House rules are free text, one rule per line; list markers a landlord typed
// ("-", "*", "•") are dropped so every line gets the same tick.
$houseRules = array_values(array_filter(array_map(function ($line) {
  return trim(preg_replace('/^[\s\-\*\x{2022}\x{00B7}]+/u', '', $line));
}, preg_split('/\R/u', (string) $listing['house_rules']))));

$hasMap = ($listing['latitude'] ?? null) !== null && ($listing['longitude'] ?? null) !== null;
if ($hasMap) {
  $lat = (float) $listing['latitude'];
  $lng = (float) $listing['longitude'];
  $osmUrl = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=17/' . $lat . '/' . $lng;
}

/* The number the boarder should actually use: the one the landlord put on
   this listing, falling back to the account phone for signed-in users. */
$shownPhone = trim((string) $listing['contact_number']);
if ($shownPhone === '' && is_logged_in()) {
  $shownPhone = trim((string) $listing['landlord_phone']);
}
$dialPhone = preg_replace('/[^0-9+]/', '', $shownPhone);

// The landlord as the shared avatar renderer wants them, so their photo is
// drawn here exactly as it is inside the panel.
$landlordAvatar = [
  'full_name'   => $listing['landlord_name'],
  'avatar_path' => $listing['landlord_avatar'],
];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$pageUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url('boarder/view_listing.php?id=' . $listingId);

$galleryPhotos = [];
foreach ($photos as $i => $p) {
  $galleryPhotos[] = [
    'src' => base_url($p['image_path']),
    'alt' => 'Photo ' . ($i + 1) . ' of ' . $listing['name'],
  ];
}

/** One room's photos, shaped for the full-screen viewer. */
$photoSet = function (array $images, $label) {
  $set = [];
  foreach ($images as $i => $p) {
    $set[] = ['src' => base_url($p['image_path']), 'alt' => 'Photo ' . ($i + 1) . ' of ' . $label];
  }
  return $set;
};

$notice = null;
if (!$rooms && $canPreview) {
  $notice = 'This listing has no rooms yet, so boarders cannot see it. Add a room from the landlord panel.';
}
if ($listing['moderation_status'] === 'pending') {
  $notice = 'This listing is waiting for administrator approval, so boarders cannot see it yet.';
} elseif ($listing['moderation_status'] === 'rejected') {
  $notice = 'This listing was rejected and is hidden from boarders.'
    . ($listing['rejection_reason'] ? ' Reason: ' . $listing['rejection_reason'] : '');
}
if ($listing['deleted_at'] !== null) {
  $notice = 'This listing was removed by an administrator, so it is hidden from everyone else. It can be restored from Manage Listings.';
}

$pageTitle = $listing['name'];
$bleed = true;
require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="band band--listing">
  <div class="container">
    <a href="<?= base_url('boarder/browse.php') ?>" class="back-link">&larr; All boarding houses</a>

    <?php if ($notice): ?>
      <div class="alert alert-error"><strong>Preview only.</strong> <?= h($notice) ?></div>
    <?php endif; ?>

    <h1 class="band-title"><?= h($listing['name']) ?></h1>
    <p class="listing-addr"><?= icon('pin', 16) ?><?= h($listing['address']) ?></p>

    <ul class="listing-tags">
      <?php if ($listing['availability_status'] !== 'available'): ?>
        <li class="pill pill--unavailable">Hidden by landlord</li>
      <?php else: ?>
        <li class="pill <?= h($avail['pill']) ?>"><?= h($avail['label']) ?></li>
      <?php endif; ?>
      <?php if ($rooms): ?>
        <li class="tag-light"><?= icon('door', 14) ?><?= h($avail['summary']) ?></li>
      <?php endif; ?>
      <?php if ($genderLabel): ?>
        <li class="tag-light"><?= icon('users', 14) ?><?= h($genderLabel) ?></li>
      <?php endif; ?>
      <?php if (($listing['visitors_allowed'] ?? null) !== null && (int) $listing['visitors_allowed'] === 1): ?>
        <li class="tag-light"><?= icon('visitor', 14) ?>Visitors allowed</li>
      <?php endif; ?>
    </ul>
  </div>
</section>

<div class="container listing-layout seam">
  <div class="listing-main">
    <?php if ($galleryPhotos): ?>
      <div class="gallery on-seam" data-gallery data-photos="<?= h(json_encode($galleryPhotos)) ?>">
        <a class="gallery-stage" href="<?= h($galleryPhotos[0]['src']) ?>"
          aria-label="View photo 1 of <?= count($galleryPhotos) ?> full screen">
          <img class="gallery-main" src="<?= h($galleryPhotos[0]['src']) ?>" alt="<?= h($galleryPhotos[0]['alt']) ?>">
          <span class="gallery-count"><?= icon('expand', 14) ?><span class="gallery-count-text">1 / <?= count($galleryPhotos) ?></span></span>
        </a>
        <?php if (count($galleryPhotos) > 1): ?>
          <ul class="gallery-thumbs">
            <?php foreach ($galleryPhotos as $i => $photo): ?>
              <li>
                <a class="gallery-thumb<?= $i === 0 ? ' is-active' : '' ?>" href="<?= h($photo['src']) ?>"
                  <?= $i === 0 ? 'aria-current="true"' : '' ?> aria-label="Show photo <?= $i + 1 ?>">
                  <img src="<?= h($photo['src']) ?>" alt="" loading="lazy">
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="detail-card<?= $galleryPhotos ? '' : ' on-seam' ?>">
      <?php if (!empty($listing['description'])): ?>
        <section class="detail-section">
          <h2>About this place</h2>
          <p class="prose"><?= h($listing['description']) ?></p>
        </section>
      <?php endif; ?>

      <section class="detail-section" id="rooms" data-rooms>
        <div class="rooms-head">
          <h2>Rooms</h2>
          <?php if ($rooms): ?>
            <span class="rooms-count"><?= h($avail['summary']) ?></span>
          <?php endif; ?>
        </div>

        <?php if (!$rooms): ?>
          <p class="detail-intro">No rooms have been added to this listing yet.</p>
        <?php else: ?>
          <?php if (count($roomTypeCounts) > 1): ?>
            <?php /* Filter chips: shown only when there is more than one type to pick
                     between. They are buttons that listing.js wires up; without it,
                     every room simply stays listed. */ ?>
            <div class="room-filter" role="group" aria-label="Filter rooms by type" data-room-filter hidden>
              <button type="button" class="chip is-active" data-room-type="" aria-pressed="true">
                All rooms <span><?= count($rooms) ?></span>
              </button>
              <?php foreach ($roomTypeCounts as $typeName => $typeCount): ?>
                <button type="button" class="chip" data-room-type="<?= h($typeName) ?>" aria-pressed="false">
                  <?= h($typeName) ?> <span><?= (int) $typeCount ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <ul class="room-grid">
            <?php foreach ($rooms as $room): ?>
              <?php
              $state = room_state($room);
              $capacity = max(1, (int) $room['capacity']);
              $taken = min($capacity, (int) $room['slots_taken']);
              $set = $photoSet($roomPhotos[(int) $room['room_id']] ?? [], $room['name']);
              ?>
              <li class="room-tile room-tile--<?= h($state['key']) ?>" data-room-type-name="<?= h($room['room_type_name']) ?>">
                <?php if ($set): ?>
                  <button type="button" class="room-tile-media" data-photo-set="<?= h(json_encode($set)) ?>"
                    aria-label="View <?= count($set) ?> photo<?= count($set) === 1 ? '' : 's' ?> of <?= h($room['name']) ?>">
                    <img src="<?= h($set[0]['src']) ?>" alt="" loading="lazy">
                    <?php if (count($set) > 1): ?>
                      <span class="room-tile-count"><?= icon('expand', 12) ?><?= count($set) ?></span>
                    <?php endif; ?>
                  </button>
                <?php else: ?>
                  <div class="room-tile-media room-tile-media--empty"><?= icon('door', 28) ?></div>
                <?php endif; ?>
                <span class="pill pill--on-photo <?= h($state['pill']) ?>"><?= h($state['label']) ?></span>

                <div class="room-tile-body">
                  <h3><?= h($room['name']) ?></h3>
                  <p class="room-tile-type"><?= h($room['room_type_name']) ?> &middot; <?= $capacity ?> <?= $capacity === 1 ? 'person' : 'people' ?></p>
                  <p class="room-tile-rent"><?= peso_round($room['monthly_rent']) ?> <span>/ month</span></p>
                  <?php if (!empty($room['description'])): ?>
                    <p class="room-tile-desc"><?= h($room['description']) ?></p>
                  <?php endif; ?>
                  <?php if ($capacity > 1 && $state['key'] !== 'closed'): ?>
                    <div class="slots" role="img" aria-label="<?= $taken ?> of <?= $capacity ?> slots taken">
                      <span class="slots-fill" style="width: <?= round($taken / $capacity * 100) ?>%"></span>
                    </div>
                  <?php endif; ?>
                  <p class="room-tile-note room-tile-note--<?= h($state['key']) ?>"><?= h($state['note']) ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <?php if ($utilities): ?>
        <section class="detail-section">
          <h2>What's included in your stay</h2>
          <p class="detail-intro">How each utility is billed. For anything not listed here, ask the landlord.</p>
          <ul class="util-grid">
            <?php foreach ($utilities as $util): ?>
              <?php [$utilIcon, $utilTone] = utility_style($util['utility_name']); ?>
              <li class="util-tile <?= $utilTone ?>">
                <span class="util-icon"><?= icon($utilIcon, 18) ?></span>
                <span>
                  <strong><?= h($util['utility_name']) ?></strong>
                  <span class="util-policy"><?= h($util['billing_policy'] ?: 'Ask the landlord') ?></span>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($genderLabel || $livingRules || $amenities): ?>
        <section class="detail-section">
          <h2>Living here</h2>
          <?php if ($genderLabel || $livingRules): ?>
            <ul class="rule-chips">
              <?php if ($genderLabel): ?>
                <li class="rule-chip"><?= icon('users', 15) ?><?= h($genderLabel) ?></li>
              <?php endif; ?>
              <?php foreach ($livingRules as [$kind, $ruleLabel]): ?>
                <li class="rule-chip rule-chip--<?= $kind ?>"><?= icon($kind === 'yes' ? 'check' : 'x', 15) ?><?= h($ruleLabel) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($amenities): ?>
            <?php if ($genderLabel || $livingRules): ?>
              <h3 class="detail-subhead">Amenities</h3>
            <?php endif; ?>
            <ul class="rule-chips">
              <?php foreach ($amenities as $a): ?>
                <li class="rule-chip rule-chip--yes"><?= icon('check', 15) ?><?= h($a) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="detail-section">
        <h2>What to expect during your stay</h2>
        <ul class="term-grid">
          <?php foreach ($terms as [$termIcon, $termLabel, $termValue]): ?>
            <li class="term-tile">
              <span class="term-icon"><?= icon($termIcon, 18) ?></span>
              <span>
                <span class="term-label"><?= h($termLabel) ?></span>
                <strong><?= h($termValue) ?></strong>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <?php if ($houseRules): ?>
        <section class="detail-section">
          <h2>House rules</h2>
          <ul class="check-list">
            <?php foreach ($houseRules as $rule): ?>
              <li><?= icon('check', 16) ?><span><?= h($rule) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($hasMap): ?>
        <section class="detail-section">
          <h2>Explore the neighbourhood</h2>
          <div class="listing-map" id="listing-map" data-lat="<?= h($lat) ?>" data-lng="<?= h($lng) ?>"
            data-label="<?= h($listing['name']) ?>">
            Turn on JavaScript to see the map.
          </div>
          <p class="alert alert-error alert-note map-offline" data-map-offline hidden>
            The map could not load. It needs an internet connection.
          </p>
          <p class="map-note">
            <span>Map data from OpenStreetMap.</span>
            <a href="<?= h($osmUrl) ?>" target="_blank" rel="noopener">Open in OpenStreetMap <?= icon('external', 14) ?></a>
          </p>
        </section>
      <?php endif; ?>

      <section class="detail-section">
        <h2>Worth asking the landlord</h2>
        <ul class="check-list">
          <li><?= icon('info', 16) ?><span>Whether the rent already covers water and electricity</span></li>
          <li><?= icon('info', 16) ?><span>What the reservation fee holds, and whether it is refundable</span></li>
          <li><?= icon('info', 16) ?><span>When the room frees up, and if you can visit before deciding</span></li>
        </ul>
      </section>
    </div>
  </div>

  <aside class="listing-side" aria-label="Quick info">
    <div class="quick-card on-seam">
      <?php if ($avail['rent_from'] !== null): ?>
        <p class="price">
          <?php if (count($rooms) > 1): ?><span class="price-from">From</span><?php endif; ?>
          <?= peso_round($avail['rent_from']) ?> <span>/ month</span>
        </p>
      <?php endif; ?>
      <?php if ($rooms): ?>
        <a href="#rooms" class="quick-rooms quick-rooms--<?= h($avail['key']) ?>"><?= h($avail['summary']) ?></a>
      <?php endif; ?>

      <?php if (can_save_listings()): ?>
        <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>" class="save-form">
          <?= csrf_field() ?>
          <input type="hidden" name="boarding_house_id" value="<?= (int) $listingId ?>">
          <input type="hidden" name="action" value="<?= $isSaved ? 'unsave' : 'save' ?>">
          <input type="hidden" name="return" value="view">
          <button type="submit" class="save-btn save-btn-wide <?= $isSaved ? 'is-saved' : '' ?>"
            aria-pressed="<?= $isSaved ? 'true' : 'false' ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="<?= $isSaved ? 'currentColor' : 'none' ?>"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <span class="save-btn-text"><?= $isSaved ? 'Saved' : 'Save this listing' ?></span>
          </button>
        </form>
      <?php elseif (!is_logged_in()): ?>
        <p class="field-hint field-hint--block">
          <a href="<?= base_url('auth/login.php') ?>">Log in</a> as a boarder to save this listing.
        </p>
      <?php endif; ?>

      <div class="quick-host">
        <?= avatar_html($landlordAvatar, 48, 'avatar') ?>
        <div>
          <strong><?= h($listing['landlord_name']) ?></strong>
          <span>Landlord</span>
        </div>
      </div>

      <ul class="quick-list">
        <li><?= icon('pin', 16) ?><span>Location</span><strong><?= h($listing['address']) ?></strong></li>
        <?php if ($rooms): ?>
          <li><?= icon('door', 16) ?><span>Room types</span><strong><?= h(implode(', ', array_keys($roomTypeCounts))) ?></strong></li>
          <li><?= icon('users', 16) ?><span>Rooms</span><strong><?= count($availableRooms) ?> of <?= count($rooms) ?> available</strong></li>
        <?php endif; ?>
        <li><?= icon('phone', 16) ?><span>Contact</span>
          <strong>
            <?php if ($shownPhone !== ''): ?>
              <a href="tel:<?= h($dialPhone) ?>"><?= h($shownPhone) ?></a>
            <?php elseif (!is_logged_in()): ?>
              <a href="<?= base_url('auth/login.php') ?>">Log in to see</a>
            <?php else: ?>
              Not published
            <?php endif; ?>
          </strong>
        </li>
      </ul>

      <?php if ($shownPhone !== ''): ?>
        <div class="contact-actions">
          <a class="btn btn-accent" href="tel:<?= h($dialPhone) ?>"><?= icon('phone', 16) ?> Call</a>
          <button type="button" class="btn btn-ghost js-copy-number" data-number="<?= h($shownPhone) ?>">Copy number</button>
        </div>
      <?php endif; ?>

      <button type="button" class="btn btn-ghost btn-block copy-link js-copy" data-copy="<?= h($pageUrl) ?>">Copy link to this listing</button>
    </div>
  </aside>
</div>

<?php if ($shownPhone !== ''): ?>
  <?php /* Phones only (.call-bar is display:none above 720px). Quick Info is
           hoisted above the long read on a narrow screen, so without this the
           number is left far behind by the time anyone has read the listing.
           The heart repeats the one in Quick Info; favorite_toggle.js repaints
           every heart for the listing, so the two never disagree. */ ?>
  <div class="call-bar">
    <div class="call-bar-inner">
      <?php if ($avail['rent_from'] !== null): ?>
        <p class="call-bar-price">
          <?php if (count($rooms) > 1): ?><span>From</span> <?php endif; ?><?= peso_round($avail['rent_from']) ?>
          <span>/ month</span>
        </p>
      <?php else: ?>
        <p class="call-bar-price"><?= h($listing['landlord_name']) ?></p>
      <?php endif; ?>

      <?php if (can_save_listings()): ?>
        <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>" class="save-form">
          <?= csrf_field() ?>
          <input type="hidden" name="boarding_house_id" value="<?= (int) $listingId ?>">
          <input type="hidden" name="action" value="<?= $isSaved ? 'unsave' : 'save' ?>">
          <input type="hidden" name="return" value="view">
          <button type="submit" class="save-btn <?= $isSaved ? 'is-saved' : '' ?>"
            title="<?= $isSaved ? 'Remove from saved' : 'Save this listing' ?>"
            aria-label="<?= $isSaved ? 'Remove from saved' : 'Save this listing' ?>"
            aria-pressed="<?= $isSaved ? 'true' : 'false' ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="<?= $isSaved ? 'currentColor' : 'none' ?>"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
          </button>
        </form>
      <?php endif; ?>

      <a class="btn btn-accent" href="tel:<?= h($dialPhone) ?>"><?= icon('phone', 16) ?> Call</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($galleryPhotos || $roomPhotos): ?>
  <?php /* One viewer for the house gallery and every room's photos. listing.js
           hides the arrows when the set it is showing has a single photo. */ ?>
  <dialog class="lightbox" aria-label="Photos of <?= h($listing['name']) ?>">
    <div class="lightbox-inner">
      <img class="lightbox-img" src="" alt="">
      <p class="lightbox-count" aria-live="polite"></p>
      <button type="button" class="lightbox-btn lightbox-prev" aria-label="Previous photo"><?= icon('chevron-left', 22) ?></button>
      <button type="button" class="lightbox-btn lightbox-next" aria-label="Next photo"><?= icon('chevron-right', 22) ?></button>
      <button type="button" class="lightbox-btn lightbox-close" aria-label="Close"><?= icon('x', 22) ?></button>
    </div>
  </dialog>
<?php endif; ?>

<?php if ($hasMap): ?>
  <link rel="stylesheet" href="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>">
  <script src="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<?php endif; ?>
<script src="<?= base_url('assets/js/listing.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/listing.js') ?: 0 ?>"></script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
