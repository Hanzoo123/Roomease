<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/listing_card.php';
require __DIR__ . '/../includes/search_bar.php';

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

// Rooms come in groups of $perPage. ?page=N shows every group up to N, so the
// "Show more rooms" link works with JavaScript off, and a saved heart that
// reloads the page brings back everything the boarder had already opened.
$perPage = 6;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = max(1, min($page, $totalPages));

$sql = "SELECT bh.*, " . ROOM_TYPE_SELECT . ",
            (SELECT image_path FROM images img WHERE img.boarding_house_id = bh.boarding_house_id
                ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
        FROM boarding_houses bh
        " . LIVE_LANDLORD_JOIN . "
        " . ROOM_TYPE_JOIN . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bh.created_at DESC
        LIMIT " . (int) ($perPage * $page);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

$savedIds  = can_save_listings() ? saved_listing_ids($_SESSION['user_id']) : [];
$filtered = $q !== '' || $roomType !== '' || ($maxRent !== '' && is_numeric($maxRent));

// The filters that actually applied, carried into "Show more" and the heart forms.
$filters = array_filter([
  'q' => $q,
  'room_type' => $roomType,
  'max_rent' => is_numeric($maxRent) ? $maxRent : '',
], function ($v) {
  return $v !== '';
});
$shown = count($listings);
$moreUrl = '?' . http_build_query($filters + ['page' => $page + 1]) . '#chunk-' . ($page + 1);

$pageTitle = 'Browse rooms';
$band = [
  'title' => 'Rooms in Baybay City',
  'lede' => 'Every room here is approved and open for boarders. Filter by name, room type, or budget.',
];
require __DIR__ . '/../includes/header.php';

// The filter bar carries the #listings anchor, so links from the home page
// land with the search form and the first rooms in view.
render_search_bar([
  'id' => 'listings',
  'room_types' => $roomTypes,
  'q' => $q,
  'room_type' => $roomType,
  'max_rent' => $maxRent,
]);
?>

<div class="section-head">
  <h2><?= $filtered ? 'Matching rooms' : 'All rooms' ?></h2>
  <span class="count-tag"><?= $totalCount ?> <?= $totalCount === 1 ? 'room' : 'rooms' ?> found</span>
</div>

<?php if (!$listings): ?>
  <p class="rooms-empty">No rooms match your search. Try a higher budget or a different room type.</p>
<?php else: ?>
  <?php foreach (array_chunk($listings, $perPage) as $i => $chunk): ?>
    <?php
    $n = $i + 1;
    $from = $i * $perPage + 1;
    $to = $from + count($chunk) - 1;
    ?>
    <?php /* Each group is its own grid so "Show more rooms" can lift exactly one
             group out of the next page; the gap between grids matches the gap
             inside them, so the groups read as one continuous grid. */ ?>
    <section class="card-chunk" id="chunk-<?= $n ?>" aria-label="Rooms <?= $from ?>–<?= $to ?> of <?= $totalCount ?>">
      <div class="card-grid">
        <?php foreach ($chunk as $l): ?>
          <?php render_listing_card($l, can_save_listings() ? [
            'saved' => isset($savedIds[$l['boarding_house_id']]),
            'return' => 'browse',
            'fields' => $filters + ['page' => (int) $page],
          ] : null); ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($shown < $totalCount): ?>
    <div class="show-more">
      <a href="<?= h($moreUrl) ?>" class="btn btn-ghost js-show-more">Show more rooms</a>
      <p class="count-tag">Showing <?= $shown ?> of <?= $totalCount ?></p>
    </div>
  <?php elseif ($totalCount > $perPage): ?>
    <div class="show-more">
      <p class="count-tag">Showing all <?= $totalCount ?> rooms</p>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/show_more.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
