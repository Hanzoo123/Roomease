<?php
/**
 * The boarder's shortlist of saved listings.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/components/listing_card.php';
require_login('boarder');

$userId = $_SESSION['user_id'];

// Only approved listings are shown. A listing pulled from the site after it was
// saved stays in the favorites table but is not advertised here.
$stmt = $pdo->prepare(
  "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ",
          f.created_at AS saved_at,
          " . COVER_PHOTO_SELECT . "
     FROM favorites f
     JOIN boarding_houses bh ON bh.boarding_house_id = f.boarding_house_id
     " . LIVE_LANDLORD_JOIN . "
     " . room_summary_join() . "
    WHERE f.user_id = ? AND bh.moderation_status = 'approved'
    ORDER BY f.created_at DESC"
);
$stmt->execute([$userId]);
$listings = $stmt->fetchAll();

$pageTitle = 'Saved';
$band = [
  'title' => 'Saved boarding houses',
  'lede' => 'Your shortlist to compare. Room availability here is live. Only you can see this list.',
];
require __DIR__ . '/../includes/layouts/header.php';
?>

<?php if (!$listings): ?>
  <p class="rooms-empty on-seam">
    You haven't saved any boarding houses yet.
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

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
