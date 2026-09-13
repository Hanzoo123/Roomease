<?php
/**
 * RoomEase entry point.
 *
 * Admins go to the admin panel and landlords to their listings. Guests and
 * boarders get the home page: the search form on the band's seam, the newest
 * rooms, the room-type index, how RoomEase works, and a word for landlords.
 *
 * require_login() also lands here on a role mismatch, so this doubles as
 * the "you don't belong on that page" fallback.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/listing_card.php';
require __DIR__ . '/includes/search_bar.php';

if (is_logged_in()) {
    if (is_admin()) {
        redirect('admin/dashboard.php');
    }
    if (current_role() === 'landlord') {
        redirect('landlord/dashboard.php');
    }
}

$liveCount = live_listing_count();
$typeCounts = room_type_counts();

$newestStmt = $pdo->query(
    "SELECT bh.*, " . ROOM_TYPE_SELECT . ",
            (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
                ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
       FROM boarding_houses bh
       " . LIVE_LANDLORD_JOIN . "
       " . ROOM_TYPE_JOIN . "
      WHERE " . LIVE_LISTING_WHERE . "
      ORDER BY bh.created_at DESC
      LIMIT 6"
);
$newest = $newestStmt->fetchAll();

$savedIds = can_save_listings() ? saved_listing_ids($_SESSION['user_id']) : [];

$pageTitle = 'Rooms for rent in Baybay City';
$bleed = true;
require __DIR__ . '/includes/header.php';
?>

<section class="band band--hero">
  <div class="container">
    <h1 class="hero-title">Find your next room <span>in Baybay City</span></h1>
    <p class="hero-lede">
      Compare boarding houses by rent, room type, and what's included.
      <?php if ($liveCount > 0): ?>
        <strong><?= $liveCount ?> <?= $liveCount === 1 ? 'room' : 'rooms' ?></strong> open right now.
      <?php endif; ?>
    </p>
  </div>
</section>

<div class="container seam">
  <?php render_search_bar([
      'action' => base_url('boarder/browse.php'),
      'room_types' => room_type_options(),
  ]); ?>
</div>

<section class="section section--after-seam">
  <div class="container">
    <div class="section-head">
      <h2>Newest rooms</h2>
      <?php if ($liveCount > count($newest)): ?>
        <a href="<?= base_url('boarder/browse.php') ?>" class="section-link">See all <?= $liveCount ?> rooms &rarr;</a>
      <?php endif; ?>
    </div>

    <?php if (!$newest): ?>
      <p class="rooms-empty">No rooms are open right now. New listings appear here once an administrator approves them.</p>
    <?php else: ?>
      <div class="card-grid">
        <?php foreach ($newest as $l): ?>
          <?php render_listing_card($l, can_save_listings() ? [
              'saved' => isset($savedIds[$l['boarding_house_id']]),
              'return' => 'home',
          ] : null); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($typeCounts): ?>
      <div class="type-index-wrap">
        <h2>By room type</h2>
        <ul class="type-index">
          <?php foreach ($typeCounts as $type): ?>
            <li>
              <?php if ((int) $type['listings'] > 0): ?>
                <a href="<?= base_url('boarder/browse.php?room_type=' . (int) $type['room_type_id']) ?>">
                  <?= h($type['room_type_name']) ?><span class="type-count"><?= (int) $type['listings'] ?></span>
                </a>
              <?php else: ?>
                <span class="is-empty"><?= h($type['room_type_name']) ?><span class="type-count">0</span></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section section--white">
  <div class="container">
    <h2>How RoomEase works</h2>
    <ol class="steps">
      <li>
        <span class="step-no" aria-hidden="true">1</span>
        <h3>Search</h3>
        <p>Filter rooms by name, room type, and the most you want to pay each month.</p>
      </li>
      <li>
        <span class="step-no" aria-hidden="true">2</span>
        <h3>Save</h3>
        <p>Log in as a boarder and tap the heart to keep a shortlist you can come back to.</p>
      </li>
      <li>
        <span class="step-no" aria-hidden="true">3</span>
        <h3>Contact the landlord</h3>
        <p>Call or copy the landlord's number straight from the listing page.</p>
      </li>
    </ol>
  </div>
</section>

<?php if (!is_logged_in()): ?>
  <section class="section">
    <div class="container split">
      <div>
        <h2>Have rooms to rent?</h2>
        <p>List them with photos, rent, utilities, and house rules. Every listing is reviewed before boarders can
          see it, and your dashboard shows where each one stands.</p>
        <a href="<?= base_url('auth/register.php?role=landlord') ?>" class="btn btn-primary">List your property</a>
      </div>

      <ul class="review-states" aria-label="What happens after you submit a listing">
        <li>
          <span class="pill pill--pending">In review</span>
          <p>An administrator checks the details before the listing goes live.</p>
        </li>
        <li>
          <span class="pill pill--available">Live</span>
          <p>Boarders can find it, save it, and call you.</p>
        </li>
        <li>
          <span class="pill pill--rejected">Needs changes</span>
          <p>You see the reason, edit the listing, and it goes back for review.</p>
        </li>
      </ul>
    </div>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
