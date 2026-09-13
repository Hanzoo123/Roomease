<?php
/**
 * The boarder's shortlist of saved listings.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/listing_card.php';
require_login('boarder');

$userId = $_SESSION['user_id'];

// Only approved listings are shown. A listing pulled from the site after it was
// saved stays in the favorites table but is not advertised here.
$stmt = $pdo->prepare(
  "SELECT bh.*, " . ROOM_TYPE_SELECT . ",
          f.created_at AS saved_at,
          (SELECT image_path FROM images img
             WHERE img.boarding_house_id = bh.boarding_house_id
             ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
     FROM favorites f
     JOIN boarding_houses bh ON bh.boarding_house_id = f.boarding_house_id
     " . LIVE_LANDLORD_JOIN . "
     " . ROOM_TYPE_JOIN . "
    WHERE f.user_id = ? AND bh.moderation_status = 'approved'
    ORDER BY f.created_at DESC"
);
$stmt->execute([$userId]);
$listings = $stmt->fetchAll();

$pageTitle = 'Saved rooms';
$band = [
  'title' => 'Saved rooms',
  'lede' => 'Your shortlist of rooms to compare. Only you can see it.',
];
require __DIR__ . '/../includes/header.php';
?>

<?php if (!$listings): ?>
  <p class="rooms-empty on-seam">
    You haven't saved any rooms yet.
    <a href="<?= base_url('boarder/browse.php') ?>">Browse rooms</a> and tap the heart on any you like.
  </p>
<?php else: ?>
  <div class="card-grid">
    <?php foreach ($listings as $l): ?>
      <?php render_listing_card($l, ['saved' => true, 'return' => 'saved', 'drop' => true]); ?>
    <?php endforeach; ?>
  </div>

  <p class="list-foot">
    <span id="saved-count"><?= count($listings) ?> saved</span>
    &middot; <a href="<?= base_url('boarder/browse.php') ?>">Browse more rooms</a>
  </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
