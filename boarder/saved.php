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

$pageTitle = 'Saved Listings';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="page-head">Saved Listings</h1>
<p class="auth-sub">Boarding houses you have shortlisted. Saving is private to your account.</p>

<div class="section-head">
  <h2>Your shortlist</h2>
  <span class="count-tag" id="saved-count"><?= count($listings) ?> saved</span>
</div>
<?php if (!$listings): ?>
  <div class="board">
    <p class="board-empty">
      You haven't saved any listings yet. Browse the
      <a href="<?= base_url('boarder/browse.php') ?>">available boarding houses</a>
      and tap the heart on any that interest you.
    </p>
  </div>
<?php else: ?>
  <div class="board">
    <?php foreach ($listings as $l): ?>
      <?php
      $isAvail = $l['availability_status'] === 'available';
      $plateNo = str_pad((string) $l['boarding_house_id'], 3, '0', STR_PAD_LEFT);
      ?>
      <div class="board-row">
        <a class="board-link" href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>">
          <?php if ($l['cover_photo']): ?>
            <span class="plate plate--photo"
              style="background-image:url('<?= h(base_url($l['cover_photo'])) ?>')"></span>
          <?php else: ?>
            <span class="plate">
              <span class="plate-no"><?= $plateNo ?></span>
              <span class="plate-kind"><?= h($l['room_type'] ?: 'Room') ?></span>
            </span>
          <?php endif; ?>

          <span class="board-main">
            <span class="board-name"><?= h($l['name']) ?></span>
            <span class="board-addr"><?= h($l['address']) ?></span>
            <span class="board-meta">
              <span>
                <span class="state-dot state-dot--<?= $isAvail ? 'available' : 'unavailable' ?>"></span>
                <?= $isAvail ? 'Available' : 'Unavailable' ?>
              </span>
              <span class="sep">&middot;</span>
              <span><?= (int) $l['room_capacity'] ?> pax</span>
            </span>
          </span>

          <span class="board-rent"><?= peso_round($l['monthly_rent']) ?><span>per month</span></span>
        </a>

        <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>" class="save-form"
          data-drop-on-unsave="1">
          <?= csrf_field() ?>
          <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
          <input type="hidden" name="action" value="unsave">
          <input type="hidden" name="return" value="saved">
          <button type="submit" class="save-btn is-saved" title="Remove from saved"
            aria-label="Remove from saved" aria-pressed="true">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
          </button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
