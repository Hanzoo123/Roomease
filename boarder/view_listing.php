<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$listingId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT bh.*,
            CONCAT(u.first_name, ' ', u.last_name) AS landlord_name,
            u.phone_number AS landlord_phone,
            u.email AS landlord_email
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id
     WHERE bh.boarding_house_id = ?"
);
$stmt->execute([$listingId]);
$listing = $stmt->fetch();

if (!$listing) {
    $pageTitle = 'Listing Not Found';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state panel panel-pad">This listing does not exist or has been removed. <a href="' . base_url('boarder/browse.php') . '">Back to browse</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$photosStmt = $pdo->prepare(
    'SELECT * FROM images WHERE boarding_house_id = ? ORDER BY is_primary DESC, image_id ASC'
);
$photosStmt->execute([$listingId]);
$photos = $photosStmt->fetchAll();

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

$isAvailable = $listing['availability_status'] === 'available';
$pageTitle = $listing['name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="<?= base_url('boarder/browse.php') ?>" style="font-size:13px;">&larr; Back to Browse</a>

<div class="section-head" style="border-bottom:none; margin-bottom:0;">
  <h2 style="font-size:26px;"><?= h($listing['name']) ?></h2>
  <span class="status-badge <?= $isAvailable ? 'status-available' : 'status-inactive' ?>" style="position:static;">
    <?= $isAvailable ? 'Available' : 'Unavailable' ?>
  </span>
</div>
<p class="auth-sub" style="margin-top:0;"><?= h($listing['address']) ?></p>

<div class="detail-grid">
  <div class="detail-photos">
    <?php if ($photos): ?>
      <img src="<?= h(base_url($photos[0]['image_path'])) ?>" alt="<?= h($listing['name']) ?>">
      <?php if (count($photos) > 1): ?>
        <div class="detail-thumbs">
          <?php foreach (array_slice($photos, 1) as $p): ?>
            <img src="<?= h(base_url($p['image_path'])) ?>" alt="Additional photo">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="listing-photo" style="height:280px;"></div>
    <?php endif; ?>

    <?php if (!empty($listing['description'])): ?>
      <div class="panel panel-pad" style="margin-top:18px;">
        <h3 style="font-size:16px;">About this place</h3>
        <p style="white-space:pre-line; margin:0; color:var(--ink-soft);"><?= h($listing['description']) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($listing['house_rules']): ?>
      <div class="panel panel-pad" style="margin-top:18px;">
        <h3 style="font-size:16px;">House Rules</h3>
        <p style="white-space:pre-line; margin:0; color:var(--ink-soft);"><?= h($listing['house_rules']) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel panel-pad">
      <div class="listing-rent" style="font-size:22px; margin-bottom:14px;"><?= peso($listing['monthly_rent']) ?> <span>/ month</span></div>

      <ul class="spec-list">
        <li><span>Room type</span><span><?= h($listing['room_type'] ?: '—') ?></span></li>
        <li><span>Capacity</span><span><?= (int)$listing['room_capacity'] ?> person(s)</span></li>
        <?php foreach ($utilities as $util): ?>
          <li><span><?= h($util['utility_name']) ?></span><span><?= h($util['billing_policy'] ?: '—') ?></span></li>
        <?php endforeach; ?>
      </ul>

      <?php if ($amenities): ?>
        <div class="tag-row" style="margin-top:14px;">
          <?php foreach ($amenities as $a): ?><span class="tag"><?= h($a) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel panel-pad" style="margin-top:16px;">
      <h3 style="font-size:16px;">Contact the Landlord</h3>
      <ul class="spec-list">
        <li><span>Name</span><span><?= h($listing['landlord_name']) ?></span></li>
        <li><span>Listed contact</span><span><?= h($listing['contact_number'] ?: '—') ?></span></li>
        <?php if (is_logged_in()): ?>
        <li><span>Phone on file</span><span><?= h($listing['landlord_phone'] ?: '—') ?></span></li>
        <?php endif; ?>
      </ul>
      <?php if (!is_logged_in()): ?>
        <p class="field-hint" style="margin-top:10px;"><a href="<?= base_url('auth/login.php') ?>">Log in</a> to see the landlord's phone number on file.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
