<?php
/**
 * The boarder's shortlist of saved listings.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('boarder');

$userId = $_SESSION['user_id'];

// Only approved listings are shown. A listing pulled from the site after it was
// saved stays in the favorites table but is not advertised here.
$stmt = $pdo->prepare(
  "SELECT bh.*,
          f.created_at AS saved_at,
          (SELECT image_path FROM images img
             WHERE img.boarding_house_id = bh.boarding_house_id
             ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
     FROM favorites f
     JOIN boarding_houses bh ON bh.boarding_house_id = f.boarding_house_id
    WHERE f.user_id = ? AND bh.moderation_status = 'approved'
    ORDER BY f.created_at DESC"
);
$stmt->execute([$userId]);
$listings = $stmt->fetchAll();

$pageTitle = 'Saved Listings';
require __DIR__ . '/../includes/header.php';
?>

<h1 style="margin-bottom:4px;">Saved Listings</h1>
<p class="auth-sub">Boarding houses you have shortlisted. Saving is private to your account.</p>

<div class="section-head">
  <h2>Your shortlist</h2>
  <span class="count-tag"><?= count($listings) ?> saved</span>
</div>

<?php if (!$listings): ?>
  <div class="empty-state panel panel-pad">
    You haven't saved any listings yet. Browse the
    <a href="<?= base_url('boarder/browse.php') ?>">available boarding houses</a>
    and tap the heart on any that interest you.
  </div>
<?php else: ?>
  <div class="listing-grid">
    <?php foreach ($listings as $l): ?>
      <div style="position:relative;">
        <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>"
          style="position:absolute; top:10px; right:10px; z-index:2; margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
          <input type="hidden" name="action" value="unsave">
          <input type="hidden" name="return" value="saved">
          <button type="submit" class="save-btn is-saved" title="Remove from saved"
            aria-label="Remove from saved">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
          </button>
        </form>

        <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" class="listing-card"
          style="text-decoration:none;color:inherit;">
          <div class="listing-photo"
            style="<?= $l['cover_photo'] ? "background-image:url('" . h(base_url($l['cover_photo'])) . "')" : '' ?>">
            <span class="status-badge <?= $l['availability_status'] === 'available' ? 'status-available' : 'status-inactive' ?>">
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
              <span class="tag"><?= (int) $l['room_capacity'] ?> pax</span>
            </div>
            <span class="btn btn-ghost btn-block">View Details</span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
