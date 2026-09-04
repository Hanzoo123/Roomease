<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$q = trim($_GET['q'] ?? '');
$roomType = $_GET['room_type'] ?? '';
$maxRent = $_GET['max_rent'] ?? '';

$where = ["l.status = 'available'"];
$params = [];

if ($q !== '') {
    $where[] = '(l.boarding_house_name LIKE ? OR l.city LIKE ? OR l.address LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($roomType, ['Private Room', 'Bed Spacer'], true)) {
    $where[] = 'l.room_type = ?';
    $params[] = $roomType;
}
if ($maxRent !== '' && is_numeric($maxRent)) {
    $where[] = 'l.monthly_rent <= ?';
    $params[] = $maxRent;
}

$countSql = "SELECT COUNT(*) FROM listings l WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$perPage = 6;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$totalPages = ceil($totalCount / $perPage);
if ($totalPages < 1) $totalPages = 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT l.*,
            (SELECT photo_path FROM listing_photos p WHERE p.listing_id = l.listing_id
                ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS cover_photo
        FROM listings l
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.created_at DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

$pageTitle = 'Browse Listings';
require __DIR__ . '/../includes/header.php';
?>

<h1 style="margin-bottom:4px;">Browse Boarding Houses</h1>
<p class="auth-sub">Filter by name, city, room type, or budget.</p>

<form method="get" class="panel panel-pad" style="display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap:12px; align-items:end;">
  <div>
    <label for="q">Search</label>
    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name, city, or address" style="margin-bottom:0;">
  </div>
  <div>
    <label for="room_type">Room type</label>
    <select id="room_type" name="room_type" style="margin-bottom:0;">
      <option value="">Any</option>
      <option value="Private Room" <?= $roomType==='Private Room'?'selected':'' ?>>Private Room</option>
      <option value="Bed Spacer" <?= $roomType==='Bed Spacer'?'selected':'' ?>>Bed Spacer</option>
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
  <span class="count-tag"><?= count($listings) ?> listing<?= count($listings)===1?'':'s' ?></span>
</div>

<?php if (!$listings): ?>
  <div class="empty-state panel panel-pad">No listings match your search. Try widening your filters.</div>
<?php else: ?>
  <div class="listing-grid">
    <?php foreach ($listings as $l): ?>
      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['listing_id']) ?>" class="listing-card" style="text-decoration:none;color:inherit;">
        <div class="listing-photo" style="<?= $l['cover_photo'] ? "background-image:url('" . h(base_url($l['cover_photo'])) . "')" : '' ?>">
          <span class="plate-number">RM-<?= str_pad($l['listing_id'], 3, '0', STR_PAD_LEFT) ?></span>
          <span class="status-badge status-available">Available</span>
        </div>
        <div class="listing-body">
          <h3><?= h($l['boarding_house_name']) ?></h3>
          <div class="listing-addr"><?= h($l['city']) ?></div>
          <div class="listing-rent"><?= peso($l['monthly_rent']) ?> <span>/ month</span></div>
          <div class="tag-row">
            <span class="tag"><?= h($l['room_type']) ?></span>
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
