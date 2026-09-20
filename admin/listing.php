<?php
/**
 * RoomEase Admin - Review a listing
 *
 * Everything an administrator needs to decide on a listing, on one page: its
 * photos, rooms and rents, stay terms, amenities and utilities, the map, the
 * landlord's account and other listings, and every decision made on it
 * before. Approve, reject, remove and restore all work from here and come
 * back here.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$listingId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
  "SELECT bh.*, " . COVER_PHOTO_SELECT . ",
          u.user_id AS landlord_id, u.first_name AS landlord_first_name, u.last_name AS landlord_last_name,
          u.email AS landlord_email, u.phone_number AS landlord_phone, u.is_active AS landlord_active,
          u.deleted_at AS landlord_deleted_at, u.created_at AS landlord_joined, u.google_id AS landlord_google_id,
          u.avatar_path AS landlord_avatar,
          CONCAT(m.first_name, ' ', m.last_name) AS moderator_name
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id
     LEFT JOIN users m ON m.user_id = bh.moderated_by
    WHERE bh.boarding_house_id = ?"
);
$stmt->execute([$listingId]);
$listing = $stmt->fetch();

if (!$listing) {
  flash_set('Listing not found.', 'error');
  redirect('admin/manage_listings.php');
}

$archived = $listing['deleted_at'] !== null;
$landlordLive = $listing['landlord_deleted_at'] === null && (int) $listing['landlord_active'] === 1;
$landlordName = trim($listing['landlord_first_name'] . ' ' . $listing['landlord_last_name']);

// Photos: the house's own first, then each room's.
$photoStmt = $pdo->prepare(
  'SELECT i.*, r.name AS room_name FROM images i
     LEFT JOIN rooms r ON r.room_id = i.room_id
    WHERE i.boarding_house_id = ?
    ORDER BY i.room_id IS NULL DESC, i.room_id, i.is_primary DESC, i.image_id ASC'
);
$photoStmt->execute([$listingId]);
$photos = $photoStmt->fetchAll();

$roomStmt = $pdo->prepare(
  'SELECT r.*, rt.room_type_name FROM rooms r
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
    WHERE r.boarding_house_id = ?
    ORDER BY r.room_id ASC'
);
$roomStmt->execute([$listingId]);
$rooms = $roomStmt->fetchAll();

$amenStmt = $pdo->prepare(
  'SELECT a.amenity_name FROM boarding_house_amenities bha
     JOIN amenities a ON a.amenity_id = bha.amenity_id
    WHERE bha.boarding_house_id = ? AND bha.is_available = 1
    ORDER BY a.amenity_name'
);
$amenStmt->execute([$listingId]);
$amenities = $amenStmt->fetchAll(PDO::FETCH_COLUMN);

$utilStmt = $pdo->prepare(
  'SELECT ut.utility_name, bhu.billing_policy FROM boarding_house_utilities bhu
     JOIN utilities ut ON ut.utility_id = bhu.utility_id
    WHERE bhu.boarding_house_id = ?
    ORDER BY ut.utility_name'
);
$utilStmt->execute([$listingId]);
$utilities = $utilStmt->fetchAll();

// The landlord's other listings, removed ones included: a pattern of removed
// or rejected listings is part of what an administrator is weighing.
$otherStmt = $pdo->prepare(
  'SELECT boarding_house_id, name, moderation_status, deleted_at FROM boarding_houses
    WHERE landlord_id = ? AND boarding_house_id <> ?
    ORDER BY created_at DESC'
);
$otherStmt->execute([$listing['landlord_id'], $listingId]);
$otherListings = $otherStmt->fetchAll();

$history = admin_actions_for('listing', $listingId);
$types = admin_action_types();

// Stay terms, only the ones the landlord stated.
$terms = [];
if (($listing['curfew'] ?? null) !== null) {
  $terms['Curfew'] = $listing['curfew'];
}
if (($listing['security_deposit'] ?? null) !== null) {
  $terms['Security deposit'] = (float) $listing['security_deposit'] == 0 ? 'None' : peso_round($listing['security_deposit']);
}
if (!empty($listing['minimum_stay_months'])) {
  $months = (int) $listing['minimum_stay_months'];
  $terms['Minimum stay'] = $months . ' ' . ($months === 1 ? 'month' : 'months');
}
$paymentLabel = payment_methods_label($listing['payment_methods'] ?? '');
if ($paymentLabel !== '') {
  $terms['Payment'] = $paymentLabel;
}
$terms['Reservation fee'] = ($listing['reservation_fee'] === null || $listing['reservation_fee'] === '')
  ? 'Not required' : peso_round($listing['reservation_fee']);
$genderLabel = gender_policy_options()[$listing['gender_policy'] ?? ''] ?? null;
if ($genderLabel !== null) {
  $terms['Who can stay'] = $genderLabel;
}
foreach (['visitors_allowed' => 'Visitors', 'pets_allowed' => 'Pets', 'cooking_allowed' => 'Cooking'] as $column => $label) {
  if (($listing[$column] ?? null) !== null) {
    $terms[$label] = (int) $listing[$column] === 1 ? 'Allowed' : 'Not allowed';
  }
}

$hasMap = ($listing['latitude'] ?? null) !== null && ($listing['longitude'] ?? null) !== null;

// The rest of the approval queue, so a decision here can lead straight to the
// next listing instead of back to the table. Both come from the one helper
// that listing_action.php uses after a decision, so the count in the button
// and the listing it actually goes to can never disagree.
$pendingQueue = pending_queue_after($listingId);

// Where approving or rejecting lands. Removing is deliberately left out: it is
// the heaviest of the three, and whoever does it should see the result rather
// than be carried off to another listing.
$decisionReturn = $pendingQueue['next'] !== null ? 'next' : 'review';

$canApprove = !$archived && $listing['moderation_status'] !== 'approved' && $rooms && $landlordLive;
$approveBlocked = '';
if (!$archived && $listing['moderation_status'] !== 'approved') {
  if (!$rooms) {
    $approveBlocked = 'It needs at least one room before it can be approved.';
  } elseif (!$landlordLive) {
    $approveBlocked = 'The landlord\'s account is removed or deactivated, so it cannot be approved until the account is restored.';
  }
}

$pageTitle = 'Review: ' . $listing['name'];
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <?php panel_page_header($listing['name'], [
    'subtitle' => $listing['address'],
    'back' => 'admin/manage_listings.php' . ($archived ? '?view=removed' : ''),
    'backLabel' => 'Back to Manage Listings',
    'lead' => listing_thumb_html($listing, 'queue-thumb'),
  ]); ?>

  <section class="content">
    <div class="container-fluid">

      <!-- Decision bar: where the listing stands, and what can be done about it -->
      <div class="card shadow-sm review-decision">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between" style="gap: 12px;">
          <div>
            <div class="mb-1">
              <?= moderation_badge($listing['moderation_status']) ?>
              <?php if ($archived): ?>
                <span class="badge badge-dark px-2 py-1"><i class="fas fa-archive mr-1"></i> Removed</span>
              <?php elseif ($listing['availability_status'] !== 'available'): ?>
                <span class="badge badge-secondary px-2 py-1"><i class="fas fa-eye-slash mr-1"></i> Hidden by landlord</span>
              <?php endif; ?>
              <?php if (!$landlordLive): ?>
                <span class="badge badge-secondary px-2 py-1">
                  Landlord <?= $listing['landlord_deleted_at'] !== null ? 'removed' : 'deactivated' ?>
                </span>
              <?php endif; ?>
            </div>
            <small class="text-muted">
              Posted <?= h(date('M j, Y', strtotime($listing['created_at']))) ?>
              &middot; last changed <?= h(time_ago($listing['updated_at'])) ?>
              <?php if ($listing['moderated_at']): ?>
                &middot; <?= h(ucfirst($listing['moderation_status'])) ?>
                <?= $listing['moderator_name'] ? 'by ' . h($listing['moderator_name']) : '' ?>
                <?= h(time_ago($listing['moderated_at'])) ?>
              <?php endif; ?>
              <?php if ($archived): ?>
                &middot; removed <?= h(time_ago($listing['deleted_at'])) ?>
              <?php endif; ?>
            </small>
            <?php if ($listing['moderation_status'] === 'rejected' && $listing['rejection_reason']): ?>
              <div class="mt-2"><strong>Rejected because:</strong> <?= h($listing['rejection_reason']) ?></div>
            <?php endif; ?>
            <?php if ($approveBlocked !== ''): ?>
              <div class="mt-2 text-muted"><i class="fas fa-info-circle mr-1"></i><?= h($approveBlocked) ?></div>
            <?php endif; ?>
          </div>

          <div class="d-flex flex-wrap" style="gap: 8px;">
            <?php if ($archived): ?>
              <form method="post" action="<?= base_url('admin/listing_action.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="boarding_house_id" value="<?= (int) $listingId ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="return_to" value="review">
                <button type="submit" class="btn btn-success"><i class="fas fa-trash-restore mr-1"></i> Restore</button>
              </form>
            <?php else: ?>
              <?php if ($listing['moderation_status'] !== 'approved'): ?>
                <form method="post" action="<?= base_url('admin/listing_action.php') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="boarding_house_id" value="<?= (int) $listingId ?>">
                  <input type="hidden" name="action" value="approve">
                  <?php /* With a queue behind this one, deciding moves on to the next
                       listing and the outcome arrives as a notification there. On the
                       last one there is nowhere to go, so the page stays put. */ ?>
                  <input type="hidden" name="return_to" value="<?= $decisionReturn ?>">
                  <button type="submit" class="btn btn-success" <?= $canApprove ? '' : 'disabled' ?>>
                    <i class="fas fa-check mr-1"></i>
                    <?= $decisionReturn === 'next' ? 'Approve &amp; next' : 'Approve' ?>
                  </button>
                </form>
              <?php endif; ?>
              <?php if ($listing['moderation_status'] !== 'rejected'): ?>
                <button type="button" class="btn btn-outline-warning js-reject"
                  data-id="<?= (int) $listingId ?>" data-name="<?= h($listing['name']) ?>">
                  <i class="fas fa-ban mr-1"></i> Reject
                </button>
              <?php endif; ?>
              <button type="button" class="btn btn-outline-danger js-remove"
                data-id="<?= (int) $listingId ?>" data-name="<?= h($listing['name']) ?>">
                <i class="fas fa-trash mr-1"></i> Remove
              </button>
            <?php endif; ?>
            <a href="<?= base_url('boarder/view_listing.php?id=' . $listingId) ?>" target="_blank" class="btn btn-outline-secondary">
              <i class="fas fa-external-link-alt mr-1"></i> Public page
            </a>
            <?php if ($pendingQueue['next'] !== null): ?>
              <?php /* Skip this one for now. The queue is oldest first, so this is
                   always the listing that has been waiting longest after this one. */ ?>
              <a href="<?= base_url('admin/listing.php?id=' . (int) $pendingQueue['next']['boarding_house_id']) ?>"
                class="btn btn-outline-primary" title="<?= h('Next: ' . $pendingQueue['next']['name']) ?>">
                Next pending <span class="badge badge-light ml-1"><?= (int) $pendingQueue['count'] ?></span>
                <i class="fas fa-arrow-right ml-1"></i>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-8">

          <div class="card shadow-sm">
            <div class="card-header">
              <h3 class="card-title">Photos <span class="text-muted font-weight-normal">(<?= count($photos) ?>)</span></h3>
              <span class="card-subtitle">House photos first, then each room's. Select one to see it full size.</span>
            </div>
            <div class="card-body">
              <?php if (!$photos): ?>
                <p class="text-muted mb-0">No photos uploaded.</p>
              <?php else: ?>
                <div class="review-photos">
                  <?php foreach ($photos as $p): ?>
                    <a href="<?= base_url($p['image_path']) ?>" target="_blank" class="review-photo"
                      title="<?= h($p['room_name'] ? 'Room: ' . $p['room_name'] : 'House photo') ?>">
                      <img src="<?= base_url($p['image_path']) ?>" alt="<?= h($p['room_name'] ? 'Photo of ' . $p['room_name'] : 'House photo') ?>" loading="lazy">
                      <span><?= h($p['room_name'] ?: ($p['is_primary'] ? 'Cover' : 'House')) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-header">
              <h3 class="card-title">Rooms <span class="text-muted font-weight-normal">(<?= count($rooms) ?>)</span></h3>
              <span class="card-subtitle">A listing needs at least one room before it can be approved.</span>
            </div>
            <div class="card-body p-0 table-responsive">
              <?php if (!$rooms): ?>
                <p class="text-muted m-3">No rooms yet, so this listing cannot be approved.</p>
              <?php else: ?>
                <table class="table mb-0">
                  <thead>
                    <tr><th>Room</th><th>Type</th><th class="text-right">Rent / month</th><th>Slots</th><th>Status</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rooms as $r): ?>
                      <?php $state = room_state($r); ?>
                      <tr>
                        <td class="font-weight-bold"><?= h($r['name']) ?></td>
                        <td><?= h($r['room_type_name']) ?></td>
                        <td class="text-right tabular"><?= h(peso($r['monthly_rent'])) ?></td>
                        <td class="tabular"><?= (int) $r['slots_taken'] ?> of <?= (int) $r['capacity'] ?> taken</td>
                        <td><span class="badge <?= h($state['badge']) ?>"><?= h($state['label']) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Details</h3>
              <span class="card-subtitle">What the landlord wrote about the property.</span></div>
            <div class="card-body">
              <dl class="review-terms">
                <dt>Contact number</dt>
                <dd><?= $listing['contact_number'] !== null && trim($listing['contact_number']) !== '' ? h($listing['contact_number']) : '<span class="text-muted">Not given; the account phone is used</span>' ?></dd>
                <?php foreach ($terms as $label => $value): ?>
                  <dt><?= h($label) ?></dt>
                  <dd><?= h($value) ?></dd>
                <?php endforeach; ?>
              </dl>

              <h4 class="review-subhead">Description</h4>
              <p class="review-prose"><?= $listing['description'] ? nl2br(h($listing['description'])) : '<span class="text-muted">No description.</span>' ?></p>

              <h4 class="review-subhead">House rules</h4>
              <p class="review-prose"><?= $listing['house_rules'] ? nl2br(h($listing['house_rules'])) : '<span class="text-muted">No house rules given.</span>' ?></p>

              <h4 class="review-subhead">Amenities</h4>
              <p class="review-prose"><?= $amenities ? h(implode(', ', $amenities)) : '<span class="text-muted">None listed.</span>' ?></p>

              <h4 class="review-subhead">Utilities</h4>
              <?php if (!$utilities): ?>
                <p class="review-prose text-muted">None listed.</p>
              <?php else: ?>
                <ul class="review-prose pl-3 mb-0">
                  <?php foreach ($utilities as $u): ?>
                    <li><?= h($u['utility_name']) ?> &mdash; <?= h($u['billing_policy'] ?: 'Not stated') ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Landlord</h3>
              <span class="card-subtitle">Who posted this, and what else they have on RoomEase.</span></div>
            <div class="card-body">
              <div class="d-flex align-items-center mb-2" style="gap: 12px;">
                <?php /* The one place in the admin area a landlord's own photo is
                     worth the room: deciding on their listing is where knowing
                     who they are actually helps. */ ?>
                <?= avatar_html(['full_name' => $landlordName, 'avatar_path' => $listing['landlord_avatar']], 44) ?>
                <div style="min-width: 0;">
                  <p class="mb-0 font-weight-bold">
                    <a href="<?= base_url('admin/user.php?id=' . (int) $listing['landlord_id']) ?>"><?= h($landlordName) ?></a>
                  </p>
                  <p class="mb-0 text-truncate"><a href="mailto:<?= h($listing['landlord_email']) ?>"><?= h($listing['landlord_email']) ?></a></p>
                  <p class="mb-0 text-muted"><?= h($listing['landlord_phone'] ?: 'No phone number') ?></p>
                </div>
              </div>
              <p class="mb-0">
                <?php if ($listing['landlord_deleted_at'] !== null): ?>
                  <span class="badge badge-dark">Removed</span>
                <?php elseif (!(int) $listing['landlord_active']): ?>
                  <span class="badge badge-danger">Deactivated</span>
                <?php else: ?>
                  <span class="badge badge-success">Active</span>
                <?php endif; ?>
                <?php if ($listing['landlord_google_id']): ?>
                  <span class="badge badge-light border">Signs in with Google</span>
                <?php endif; ?>
                <small class="text-muted d-block mt-2">Joined <?= h(date('M j, Y', strtotime($listing['landlord_joined']))) ?></small>
              </p>

              <?php if ($otherListings): ?>
                <h4 class="review-subhead mt-3">Other listings (<?= count($otherListings) ?>)</h4>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($otherListings as $o): ?>
                    <li class="mb-1">
                      <a href="<?= base_url('admin/listing.php?id=' . (int) $o['boarding_house_id']) ?>"><?= h($o['name']) ?></a>
                      <?php if ($o['deleted_at'] !== null): ?>
                        <span class="badge badge-dark">Removed</span>
                      <?php else: ?>
                        <?= moderation_badge($o['moderation_status']) ?>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Location</h3>
              <span class="card-subtitle">The pin the landlord placed on the map.</span></div>
            <div class="card-body">
              <?php if ($hasMap): ?>
                <div id="review-map" class="review-map" data-lat="<?= h($listing['latitude']) ?>"
                  data-lng="<?= h($listing['longitude']) ?>" data-label="<?= h($listing['name']) ?>">
                  The map needs JavaScript.
                </div>
                <p class="text-muted small mb-0 mt-2" data-map-offline hidden>The map could not load. It needs an internet connection.</p>
                <p class="small mb-0 mt-2">
                  <a href="https://www.openstreetmap.org/?mlat=<?= (float) $listing['latitude'] ?>&amp;mlon=<?= (float) $listing['longitude'] ?>#map=17/<?= (float) $listing['latitude'] ?>/<?= (float) $listing['longitude'] ?>"
                    target="_blank" rel="noopener">Open in OpenStreetMap</a>
                </p>
              <?php else: ?>
                <p class="text-muted mb-0">The landlord has not placed a pin on the map.</p>
              <?php endif; ?>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">History</h3>
              <span class="card-subtitle">Every decision made on this listing.</span></div>
            <div class="card-body">
              <?php if (!$history): ?>
                <p class="text-muted mb-0">No administrator has acted on this listing since the activity log started.</p>
              <?php else: ?>
                <ul class="review-history">
                  <?php foreach ($history as $e): ?>
                    <?php $type = $types[$e['action']] ?? ['label' => $e['action'], 'badge' => 'badge-secondary']; ?>
                    <li>
                      <span class="badge <?= h($type['badge']) ?>"><?= h(preg_replace('/ listing$/', '', $type['label'])) ?></span>
                      by <?= $e['admin_name'] !== null ? h($e['admin_name']) : 'an administrator' ?>
                      <small class="text-muted d-block"><?= h(date('M j, Y g:i A', strtotime($e['created_at']))) ?></small>
                      <?php if ($e['detail']): ?>
                        <div class="mt-1"><?= h($e['detail']) ?></div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<?php
$returnTo = 'review';
$rejectReturnTo = $decisionReturn;
require __DIR__ . '/../includes/components/listing_decision_modals.php';
require __DIR__ . '/../includes/layouts/panel_footer.php';
?>

<?php if ($hasMap): ?>
  <link rel="stylesheet" href="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>">
  <script src="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
  <script>
    (function () {
      var el = document.getElementById('review-map');
      var lat = parseFloat(el.getAttribute('data-lat'));
      var lng = parseFloat(el.getAttribute('data-lng'));
      if (!window.L || !isFinite(lat) || !isFinite(lng)) return;
      el.textContent = '';
      var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 16);
      var tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);
      // Built as a text node: the name is typed by a landlord.
      var label = document.createElement('strong');
      label.textContent = el.getAttribute('data-label') || '';
      L.marker([lat, lng]).addTo(map).bindPopup(label);
      tiles.once('tileerror', function () {
        var note = document.querySelector('[data-map-offline]');
        if (note) note.hidden = false;
      });
    })();
  </script>
<?php endif; ?>
