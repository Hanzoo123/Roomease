<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$listingId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT l.*, u.full_name AS landlord_name, u.contact_number AS landlord_phone, u.email AS landlord_email
     FROM listings l
     JOIN users u ON u.user_id = l.landlord_id
     WHERE l.listing_id = ?"
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

$photosStmt = $pdo->prepare('SELECT * FROM listing_photos WHERE listing_id = ? ORDER BY is_primary DESC, photo_id ASC');
$photosStmt->execute([$listingId]);
$photos = $photosStmt->fetchAll();

$amenStmt = $pdo->prepare(
    'SELECT a.amenity_name 
     FROM listing_amenities la 
     JOIN amenities a ON la.amenity_id = a.amenity_id 
     WHERE la.listing_id = ?'
);
$amenStmt->execute([$listingId]);
$amenities = $amenStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = $listing['boarding_house_name'];
require __DIR__ . '/../includes/header.php';
?>

<a href="<?= base_url('boarder/browse.php') ?>" style="font-size:13px;">&larr; Back to Browse</a>

<div class="section-head" style="border-bottom:none; margin-bottom:0;">
  <h2 style="font-size:26px;"><?= h($listing['boarding_house_name']) ?></h2>
  <span class="status-badge status-<?= $listing['status'] ?>" style="position:static;"><?= h(ucfirst($listing['status'])) ?></span>
</div>
<p class="auth-sub" style="margin-top:0;"><?= h($listing['address']) ?>, <?= h($listing['city']) ?></p>

<div class="detail-grid">
  <div class="detail-photos">
    <?php if ($photos): ?>
      <img src="<?= h(base_url($photos[0]['photo_path'])) ?>" alt="<?= h($listing['boarding_house_name']) ?>">
      <?php if (count($photos) > 1): ?>
        <div class="detail-thumbs">
          <?php foreach (array_slice($photos, 1) as $p): ?>
            <img src="<?= h(base_url($p['photo_path'])) ?>" alt="Additional photo">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="listing-photo" style="height:280px;"></div>
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
        <li><span>Room type</span><span><?= h($listing['room_type']) ?></span></li>
        <li><span>Capacity</span><span><?= (int)$listing['room_capacity'] ?> person(s)</span></li>
        <li><span>Reservation fee</span><span><?= peso($listing['reservation_fee']) ?></span></li>
        <li><span>Electricity</span><span><?= h($listing['electricity_payment'] ?: '—') ?></span></li>
        <li><span>Water</span><span><?= h($listing['water_payment'] ?: '—') ?></span></li>
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
        <li><span>Listed contact</span><span><?= h($listing['contact_info']) ?></span></li>
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
