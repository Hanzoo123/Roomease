<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$stmt = $pdo->query(
    "SELECT bh.*,
        (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
            ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
     FROM boarding_houses bh
     WHERE bh.availability_status = 'available'
     ORDER BY bh.created_at DESC
     LIMIT 6"
);
$featured = $stmt->fetchAll();

$pageTitle = 'Find Your Next Room';
require __DIR__ . '/includes/header.php';
?>

<div class="hero" style="margin: -26px calc(-50vw + 50%) 0; padding-left: calc(50vw - 50%); padding-right: calc(50vw - 50%);">
  <div class="container">
    <div class="eyebrow">Boarding House</div>
    <div class="hero-actions">
      <a href="<?= base_url('boarder/browse.php') ?>" class="btn btn-brass">Browse Listings</a>
      <?php if (!is_logged_in()): ?>
        <a href="<?= base_url('auth/register.php') ?>" class="btn" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.4);">List Your Property</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="search-bar container">
  <form method="get" action="<?= base_url('boarder/browse.php') ?>" style="display:contents;">
    <input type="text" name="q" placeholder="Search by boarding house name or address...">
    <select name="room_type">
      <option value="">Any room type</option>
      <option value="Single">Single</option>
      <option value="Double">Double</option>
      <option value="Dormitory">Dormitory</option>
      <option value="Private Room">Private Room</option>
      <option value="Bed Spacer">Bed Spacer</option>
    </select>
    <input type="number" name="max_rent" placeholder="Max monthly rent">
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
</div>

<div class="section-head">
  <h2>Recently Listed</h2>
  <span class="count-tag"><?= count($featured) ?> available</span>
</div>

<?php if (!$featured): ?>
  <div class="empty-state">
    No listings yet. <?= is_logged_in() ? '' : '<a href="' . base_url('auth/register.php') . '">Sign up as a landlord</a> to be the first.' ?>
  </div>
<?php else: ?>
  <div class="listing-grid">
    <?php foreach ($featured as $l): ?>
      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" class="listing-card" style="text-decoration:none;color:inherit;">
        <div class="listing-photo" style="<?= $l['cover_photo'] ? "background-image:url('" . h(base_url($l['cover_photo'])) . "')" : '' ?>">
          <span class="status-badge status-available">Available</span>
        </div>
        <div class="listing-body">
          <h3><?= h($l['name']) ?></h3>
          <div class="listing-addr"><?= h($l['address']) ?></div>
          <div class="listing-rent"><?= peso($l['monthly_rent']) ?> <span>/ month</span></div>
          <div class="tag-row">
            <?php if ($l['room_type']): ?>
              <span class="tag"><?= h($l['room_type']) ?></span>
            <?php endif; ?>
            <span class="tag"><?= (int)$l['room_capacity'] ?> pax</span>
          </div>
          <span class="btn btn-ghost btn-block">View Details</span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
