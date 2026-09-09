<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$q = trim($_GET['q'] ?? '');
$roomType = $_GET['room_type'] ?? '';
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
  $where[] = 'bh.room_type = ?';
  $params[] = $roomType;
}
if ($maxRent !== '' && is_numeric($maxRent)) {
  $where[] = 'bh.monthly_rent <= ?';
  $params[] = $maxRent;
}

$countSql = "SELECT COUNT(*) FROM boarding_houses bh WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();

$perPage = 6;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$sql = "SELECT bh.*,
            (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
                ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
        FROM boarding_houses bh
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bh.created_at DESC
        LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

$roomTypes = room_type_options();
$savedIds  = can_save_listings() ? saved_listing_ids($_SESSION['user_id']) : [];

$pageTitle = 'Browse Listings';
require __DIR__ . '/../includes/header.php';
?>
<div class="hero"
  style="margin: -26px calc(-50vw + 50%) 0; padding-left: calc(50vw - 50%); padding-right: calc(50vw - 50%);">
  <div class="container">
    <div class="eyebrow">Boarding House</div>
    <h1>Find Your Next Room in Baybay City</h1>
    <p>Find a place that feels like home. Explore comfortable boarding houses and rooms that match your needs and
      budget.</p>
    <div class="hero-actions">
      <a href="#listings" class="btn btn-brass">Explore Boarding Houses</a>
      <?php if (!is_logged_in()): ?>
        <a href="<?= base_url('auth/register.php') ?>" class="btn"
          style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.4);">List Your Property</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<section id="listings">
  <h1 style="margin-bottom:4px;">Browse Boarding Houses</h1>
  <p class="auth-sub">Filter by name, address, room type, or budget.</p>
</section>
<form method="get" class="panel panel-pad"
  style="display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap:12px; align-items:end;">
  <div>
    <label for="q">Search</label>
    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name or address" style="margin-bottom:0;">
  </div>
  <div>
    <label for="room_type">Room type</label>
    <select id="room_type" name="room_type" style="margin-bottom:0;">
      <option value="">Any</option>
      <?php foreach ($roomTypes as $rt): ?>
        <option value="<?= h($rt) ?>" <?= $roomType === $rt ? 'selected' : '' ?>><?= h($rt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="max_rent">Max rent (₱)</label>
    <input type="number" id="max_rent" name="max_rent" value="<?= h($maxRent) ?>" style="margin-bottom:0;">
  </div>
  <button type="submit" class="btn btn-primary">Filter</button>
</form>

<div class="section-head">
  <h2>Results</h2>
  <span class="count-tag"><?= $totalCount ?> listing<?= $totalCount === 1 ? '' : 's' ?> found</span>
</div>

<?php if (!$listings): ?>
  <div class="empty-state panel panel-pad">No listings match your search. Try widening your filters.</div>
<?php else: ?>
  <div class="listing-grid">
    <?php foreach ($listings as $l): ?>
      <?php $isSaved = isset($savedIds[$l['boarding_house_id']]); ?>
      <div style="position:relative;">
      <?php if (can_save_listings()): ?>
        <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>"
          style="position:absolute; top:10px; right:10px; z-index:2; margin:0;">
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
            aria-label="<?= $isSaved ? 'Remove from saved' : 'Save this listing' ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="<?= $isSaved ? 'currentColor' : 'none' ?>"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
          </button>
        </form>
      <?php endif; ?>
      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" class="listing-card"
        style="text-decoration:none;color:inherit;">
        <div class="listing-photo"
          style="<?= $l['cover_photo'] ? "background-image:url('" . h(base_url($l['cover_photo'])) . "')" : '' ?>">
          
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
            <span class="tag"><?= (int) $l['room_capacity'] ?> pax</span>
          </div>
          <span class="btn btn-ghost btn-block">View Details</span>
        </div>
      </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php render_pagination($page, $totalPages); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>