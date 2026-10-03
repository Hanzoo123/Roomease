<?php
/**
 * Home page. Admins go to the admin panel, landlords to their dashboard;
 * guests and boarders see the home page. Also where require_login() sends
 * someone with the wrong role.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/config/db.php';
require __DIR__ . '/includes/components/listing_card.php';

// The header's Home link asks for the home page itself (?view=home), so a
// landlord or administrator who clicks it sees the site, not their panel.
if (is_logged_in() && ($_GET['view'] ?? '') !== 'home') {
    if (is_admin()) {
        redirect('admin/dashboard.php');
    }
    if (current_role() === 'landlord') {
        redirect('landlord/dashboard.php');
    }
}

$stats = live_listing_stats();
$liveCount = $stats['listings'];
$typeCounts = room_type_counts();

// The room type tiles follow the design's order, which sets the wide tile
// and the photos to their places; a type added later goes at the end.
$tileOrder = ['Single Room', 'Double Sharing', 'Private Room', 'Bed Spacer', 'Dormitory'];
usort($typeCounts, function ($a, $b) use ($tileOrder) {
    $ia = array_search($a['room_type_name'], $tileOrder, true);
    $ib = array_search($b['room_type_name'], $tileOrder, true);
    return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
});

// Newest listings that have a room available, so the home page leads with
// places a boarder can actually move into.
$newestStmt = $pdo->query(
    "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ", " . COVER_PHOTO_SELECT . "
       FROM boarding_houses bh
       " . LIVE_LANDLORD_JOIN . "
       " . room_summary_join(true) . "
      WHERE " . LIVE_STATUS_WHERE . "
      ORDER BY rs.rooms_available > 0 DESC, bh.created_at DESC
      LIMIT 6"
);
$newest = $newestStmt->fetchAll();

$savedIds = can_save_listings() ? saved_listing_ids($_SESSION['user_id']) : [];

// The hero's photo, to the right of the forest panel: assets/img/hero.webp,
// or hero.jpg. The hero.webp there now is a temporary stand-in until the team
// has a real photo of Baybay City or one of its boarding houses. Replacing the
// file is all it takes: the diagonal edge is drawn by style.css, not the photo.
// With no photo at all, the forest panel runs the full width.
$heroPhoto = null;
foreach (['assets/img/hero.webp', 'assets/img/hero.jpg'] as $candidate) {
    if (is_file(__DIR__ . '/' . $candidate)) {
        $heroPhoto = $candidate;
        break;
    }
}

$pageTitle = 'Rooms for rent in Baybay City';
$metaDescription = 'Find boarding houses, bedspaces and dorm rooms for rent in Baybay City, Leyte. '
  . 'Compare rooms by price and type, see photos and locations, and contact the landlord yourself.';
$bleed = true;
require __DIR__ . '/includes/layouts/header.php';
?>

<section class="hero">
  <div class="container hero-inner">
    <div class="hero-copy">
      <h1 class="hero-title">Find your next room <span>in Baybay City</span></h1>
      <p class="hero-lede">Compare boarding houses by rent, room type, and what's included.</p>
      <?php /* One field for what a boarder already knows: a name, a barangay or
               a street. Room type, rent and amenities are on the browse page,
               where the results land. */ ?>
      <form class="hero-search" method="get" action="<?= base_url('boarder/browse.php') ?>#results" role="search">
        <label for="hero-q">Search by name, barangay, or street</label>
        <div class="hero-search-field">
          <input type="text" id="hero-q" name="q" placeholder="e.g. Pangasugan">
          <button type="submit" class="btn btn-accent"><?= icon('search', 18) ?><span>Search</span></button>
        </div>
      </form>
      <?php if ($liveCount > 0): ?>
        <a class="hero-browse" href="<?= base_url('boarder/browse.php') ?>">
          <?= $liveCount > 1 ? 'or browse all ' . $liveCount . ' boarding houses' : 'or browse the boarding house' ?> &rarr;
        </a>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($heroPhoto): ?>
    <?php /* Atmosphere rather than information, so it is passed over by
             screen readers. Behind the copy on a wide screen, under it on a
             narrow one. */ ?>
    <div class="hero-photo">
      <img src="<?= base_url($heroPhoto) ?>?v=<?= @filemtime(__DIR__ . '/' . $heroPhoto) ?: 0 ?>" alt="" fetchpriority="high">
    </div>
  <?php endif; ?>
</section>

<?php if ($liveCount > 0): ?>
  <?php /* What is on offer right now, in figures straight from the database,
           the rent first. Styles: "Home figures" in style.css. */ ?>
  <section class="home-figures" aria-label="RoomEase right now">
    <div class="container">
      <dl>
        <?php if ($stats['lowest_rent'] !== null): ?>
          <div class="home-figure home-figure--rent">
            <dt>Lowest monthly rent with a free slot</dt>
            <dd><?= peso_round($stats['lowest_rent']) ?> <span>/ month</span></dd>
          </div>
        <?php endif; ?>
        <div class="home-figure">
          <dt><?= $stats['rooms_available'] === 1 ? 'Room' : 'Rooms' ?> available now</dt>
          <dd><?= $stats['rooms_available'] ?></dd>
        </div>
        <div class="home-figure">
          <dt>Boarding <?= $liveCount === 1 ? 'house' : 'houses' ?> listed</dt>
          <dd><?= $liveCount ?></dd>
        </div>
      </dl>
    </div>
  </section>
<?php endif; ?>

<section class="section section--snug">
  <div class="container">
    <div class="section-head">
      <h2>Newest boarding houses</h2>
      <?php if ($liveCount > count($newest)): ?>
        <a href="<?= base_url('boarder/browse.php') ?>" class="section-link">See all <?= $liveCount ?> &rarr;</a>
      <?php endif; ?>
    </div>

    <?php if (!$newest): ?>
      <p class="rooms-empty">No boarding houses are listed right now. New listings appear here once an administrator approves them.</p>
    <?php else: ?>
      <div class="card-grid">
        <?php foreach ($newest as $l): ?>
          <?php render_listing_card($l, shows_save_heart() ? [
              'saved' => isset($savedIds[$l['boarding_house_id']]),
              'return' => 'home',
          ] : null); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($typeCounts): ?>
  <?php /* A photo tile per room type, each opening browse filtered to it. The
           counts are live; the photos are in assets/img/room-types. Styles:
           "By room type" in style.css. */ ?>
  <section class="section section--snug room-types-section">
    <div class="container">
      <h2 class="room-types-title">By room type</h2>
      <ul class="room-types">
        <?php foreach ($typeCounts as $type): ?>
          <?php
          $typeListings = (int) $type['listings'];
          $typePhoto = room_type_photo($type['room_type_name']);
          ?>
          <li class="room-type<?= $typeListings > 0 ? '' : ' room-type--empty' ?>">
            <?php /* The whole tile is the link; a type no listing has yet is shown
                     the same way, but leads nowhere. */ ?>
            <<?= $typeListings > 0 ? 'a href="' . base_url('boarder/browse.php?room_type=' . (int) $type['room_type_id'] . '#results') . '"' : 'div' ?> class="room-type-tile">
              <?php if ($typePhoto): ?>
                <img src="<?= base_url($typePhoto) ?>" alt="" loading="lazy">
              <?php endif; ?>
              <span class="room-type-name"><?= h($type['room_type_name']) ?></span>
              <span class="room-type-count">
                <?= $typeListings > 0 ? $typeListings . ' boarding ' . ($typeListings === 1 ? 'house' : 'houses') : 'No listings yet' ?>
              </span>
            </<?= $typeListings > 0 ? 'a' : 'div' ?>>
          </li>
        <?php endforeach; ?>
      </ul>
      <a class="room-types-all" href="<?= base_url('boarder/browse.php') ?>">Browse all rooms <?= icon('arrow-right', 20) ?></a>
    </div>
  </section>
<?php endif; ?>

<section class="section section--white">
  <div class="container how">
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
        <p>Add each room with its own rent and photos, and update how many slots are taken as tenants move in
          and out. Every listing is reviewed before boarders can
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

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>
