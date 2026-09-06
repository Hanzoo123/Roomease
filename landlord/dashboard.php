<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('landlord');

$stmt = $pdo->prepare(
    "SELECT bh.*,
        (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
            ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
     FROM boarding_houses bh
     WHERE bh.landlord_id = ?
     ORDER BY bh.created_at DESC"
);
$stmt->execute([$_SESSION['user_id']]);
$listings = $stmt->fetchAll();

$pageTitle = 'My Listings';
require __DIR__ . '/../includes/header.php';
?>

<div class="section-head">
  <h2>My Boarding Houses</h2>
  <a href="<?= base_url('landlord/add_listing.php') ?>" class="btn btn-brass">+ Add Listing</a>
</div>

<?php if (!$listings): ?>
  <div class="empty-state panel panel-pad">
    You haven't posted any listings yet. <a href="<?= base_url('landlord/add_listing.php') ?>">Create your first listing</a> to get started.
  </div>
<?php else: ?>
  <div class="listing-grid">
    <?php foreach ($listings as $l): ?>
      <div class="listing-card">
        <div class="listing-photo" style="<?= $l['cover_photo'] ? "background-image:url('" . h(base_url($l['cover_photo'])) . "')" : '' ?>">
          <span class="plate-number">RM-<?= str_pad($l['boarding_house_id'], 3, '0', STR_PAD_LEFT) ?></span>
          <span class="status-badge status-<?= $l['availability_status'] === 'available' ? 'available' : 'unavailable' ?>">
            <?= $l['availability_status'] === 'available' ? 'Available' : 'Unavailable' ?>
          </span>
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
          <div style="display:flex; gap:8px; margin-top:auto;">
            <a href="<?= base_url('landlord/edit_listing.php?id=' . $l['boarding_house_id']) ?>" class="btn btn-ghost btn-sm" style="flex:1;">Edit</a>
            <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" class="btn btn-ghost btn-sm" style="flex:1;">View</a>
          </div>
          <form method="post" action="<?= base_url('landlord/delete_listing.php') ?>" onsubmit="return confirm('Delete this boarding house listing? This cannot be undone.');" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="boarding_house_id" value="<?= (int)$l['boarding_house_id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm btn-block">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
