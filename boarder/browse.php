<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$q = trim($_GET['q'] ?? '');
// The filter is a room_type_id now that the column is a foreign key. An
// unrecognised value is treated as "no filter" rather than as no results.
$roomTypes = room_type_options();
$roomType = $_GET['room_type'] ?? '';
$roomType = ($roomType !== '' && isset($roomTypes[(int) $roomType])) ? (int) $roomType : '';
$maxRent = $_GET['max_rent'] ?? '';

$where = ["bh.availability_status = 'available'", "bh.moderation_status = 'approved'"];
$params = [];

if ($q !== '') {
  $where[] = '(bh.name LIKE ? OR bh.address LIKE ?)';
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
}
if ($roomType !== '') {
  $where[] = 'bh.room_type_id = ?';
  $params[] = $roomType;
}
if ($maxRent !== '' && is_numeric($maxRent)) {
  $where[] = 'bh.monthly_rent <= ?';
  $params[] = $maxRent;
}

// LIVE_LANDLORD_JOIN keeps listings out of browse when their landlord has been
// deactivated or removed. Browse used to look only at the listing, so a
// deactivated landlord's rooms stayed advertised.
$countSql = "SELECT COUNT(*) FROM boarding_houses bh " . LIVE_LANDLORD_JOIN
  . " WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();

$perPage = 6;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$sql = "SELECT bh.*, " . ROOM_TYPE_SELECT . ",
            (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
                ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
        FROM boarding_houses bh
        " . LIVE_LANDLORD_JOIN . "
        " . ROOM_TYPE_JOIN . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bh.created_at DESC
        LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

$savedIds  = can_save_listings() ? saved_listing_ids($_SESSION['user_id']) : [];

$pageTitle = 'Browse Listings';
require __DIR__ . '/../includes/header.php';
?>
<div class="hero hero--bleed">
  <div class="container">
    <h1>Find Your Next Room in Baybay City</h1>
    <p>Find a place that feels like home. Explore comfortable boarding houses and rooms that match your needs and
      budget.</p>
    <div class="hero-actions">
      <a href="#listings" class="btn btn-brass">Explore Boarding Houses</a>
      <?php if (!is_logged_in()): ?>
        <a href="<?= base_url('auth/register.php') ?>" class="btn btn-hero-ghost">List Your Property</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php /* The hero already titles this page. A second "Browse Boarding Houses"
         heading repeated it and cost a phone most of a screen before the first
         room came into view, so the filter bar carries the #listings anchor. */ ?>
<form method="get" class="search-bar" id="listings">
  <div>
    <label for="q">Search</label>
    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name or address">
  </div>
  <?php /* Presentation only, and deliberately without a name attribute so it
           never reaches the query string. Hidden entirely above 720px. */ ?>
  <input type="checkbox" id="more-filters" class="filter-toggle">
  <label for="more-filters" class="filter-toggle-label">More filters</label>
  <div class="filter-fields">
    <div>
      <label for="room_type">Room type</label>
      <select id="room_type" name="room_type">
        <option value="">Any</option>
        <?php foreach ($roomTypes as $rtId => $rtName): ?>
          <option value="<?= (int) $rtId ?>" <?= $roomType === $rtId ? 'selected' : '' ?>><?= h($rtName) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="max_rent">Max rent (₱)</label>
      <input type="number" id="max_rent" name="max_rent" value="<?= h($maxRent) ?>">
    </div>
  </div>
  <button type="submit" class="btn btn-primary">Filter</button>
</form>

<div class="section-head">
  <h2>Results</h2>
  <span class="count-tag"><?= $totalCount ?> listing<?= $totalCount === 1 ? '' : 's' ?> found</span>
</div>
<?php if (!$listings): ?>
  <div class="board">
    <p class="board-empty">No listings match your search. Try widening your filters.</p>
  </div>
<?php else: ?>
  <div class="board">
    <?php foreach ($listings as $l): ?>
      <?php
      $isSaved = isset($savedIds[$l['boarding_house_id']]);
      $plateNo = str_pad((string) $l['boarding_house_id'], 3, '0', STR_PAD_LEFT);
      ?>
      <div class="board-row">
        <a class="board-link" href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>">
          <?php /* The plate keeps one footprint either way: a cover photo fills
                   it, and without one it carries the room type instead. */ ?>
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
              <span><span class="state-dot state-dot--available"></span>Available</span>
              <span class="sep">&middot;</span>
              <span><?= (int) $l['room_capacity'] ?> pax</span>
            </span>
          </span>

          <span class="board-rent"><?= peso_round($l['monthly_rent']) ?><span>per month</span></span>
        </a>

        <?php if (can_save_listings()): ?>
          <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>" class="save-form">
            <?= csrf_field() ?>
            <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
            <input type="hidden" name="action" value="<?= $isSaved ? 'unsave' : 'save' ?>">
            <input type="hidden" name="return" value="browse">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="room_type" value="<?= h($roomType) ?>">
            <input type="hidden" name="max_rent" value="<?= h($maxRent) ?>">
            <input type="hidden" name="page" value="<?= (int) $page ?>">
            <button type="submit" class="save-btn <?= $isSaved ? 'is-saved' : '' ?>"
              title="<?= $isSaved ? 'Remove from saved' : 'Save this listing' ?>"
              aria-label="<?= $isSaved ? 'Remove from saved' : 'Save this listing' ?>"
              aria-pressed="<?= $isSaved ? 'true' : 'false' ?>">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="<?= $isSaved ? 'currentColor' : 'none' ?>"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
              </svg>
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php render_pagination($page, $totalPages); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>