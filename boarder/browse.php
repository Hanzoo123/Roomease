<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$q        = trim($_GET['q'] ?? '');
$roomType = $_GET['room_type'] ?? '';
$maxRent  = $_GET['max_rent'] ?? '';

$where  = ["bh.availability_status = 'available'"];
$params = [];

if ($q !== '') {
    $where[] = '(bh.name LIKE ? OR bh.address LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($roomType !== '') {
    $where[]  = 'bh.room_type = ?';
    $params[] = $roomType;
}
if ($maxRent !== '' && is_numeric($maxRent)) {
    $where[]  = 'bh.monthly_rent <= ?';
    $params[] = $maxRent;
}

$countSql  = "SELECT COUNT(*) FROM boarding_houses bh WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$perPage    = 6;
$page       = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = max(1, min($page, $totalPages));
$offset     = ($page - 1) * $perPage;

$sql = "SELECT bh.*,
            (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
                ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
        FROM boarding_houses bh
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bh.created_at DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

$roomTypes = ['Single', 'Double', 'Dormitory', 'Private Room', 'Bed Spacer'];

$pageTitle = 'Browse Listings';
require __DIR__ . '/../includes/header.php';
?>

<h1 style="margin-bottom:4px;">Browse Boarding Houses</h1>
<p class="auth-sub">Filter by name, address, room type, or budget.</p>

<form method="get" class="panel panel-pad" style="display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap:12px; align-items:end;">
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
  <span class="count-tag"><?= count($listings) ?> listing<?= count($listings) === 1 ? '' : 's' ?></span>
</div>

<?php if (!$listings): ?>
  <div class="empty-state panel panel-pad">No listings match your search. Try widening your filters.</div>
<?php else: ?>
  <div class="listing-grid">
    <?php foreach ($listings as $l): ?>
      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" class="listing-card" style="text-decoration:none;color:inherit;">
        <div class="listing-photo" style="<?= $l['cover_photo'] ? "background-image:url('" . h(base_url($l['cover_photo'])) . "')" : '' ?>">
          <span class="plate-number">RM-<?= str_pad($l['boarding_house_id'], 3, '0', STR_PAD_LEFT) ?></span>
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
  <?php render_pagination($page, $totalPages); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
