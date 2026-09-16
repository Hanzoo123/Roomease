<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/components/listing_card.php';
require __DIR__ . '/../includes/components/search_bar.php';

$q = trim($_GET['q'] ?? '');
// The filter is a room_type_id. An unrecognised value is treated as "no
// filter" rather than as no results.
$roomTypes = room_type_options();
$roomType = $_GET['room_type'] ?? '';
$roomType = ($roomType !== '' && isset($roomTypes[(int) $roomType])) ? (int) $roomType : '';
$maxRent = $_GET['max_rent'] ?? '';

// LIVE_LISTING_WHERE also requires at least one room.
$where = [LIVE_LISTING_WHERE];
$params = [];

if ($q !== '') {
  $where[] = '(bh.name LIKE ? OR bh.address LIKE ?)';
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
}

// Room type and budget match a room inside the listing, not the listing as a
// whole: a listing appears when at least one of its open rooms fits. Full
// rooms still count, so a fully occupied listing that fits is still found;
// it is simply sorted after listings with space.
$roomMatch = [];
if ($roomType !== '') {
  $roomMatch[] = 'fr.room_type_id = ?';
  $params[] = $roomType;
}
if ($maxRent !== '' && is_numeric($maxRent)) {
  $roomMatch[] = 'fr.monthly_rent <= ?';
  $params[] = $maxRent;
}
if ($roomMatch) {
  $where[] = 'EXISTS (SELECT 1 FROM rooms fr WHERE fr.boarding_house_id = bh.boarding_house_id AND fr.is_open = 1 AND '
    . implode(' AND ', $roomMatch) . ')';
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

// Listings with a room available first, then fully occupied ones, newest
// first within each.
$sql = "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ", " . COVER_PHOTO_SELECT . "
        FROM boarding_houses bh
        " . LIVE_LANDLORD_JOIN . "
        " . room_summary_join(true) . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY rs.rooms_available > 0 DESC, bh.created_at DESC, bh.boarding_house_id DESC
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
  'lede' => 'Every boarding house here is approved. Filter by name, or by the room type and budget you need.',
];
require __DIR__ . '/../includes/layouts/header.php';

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
  <h2><?= $filtered ? 'Matching boarding houses' : 'All boarding houses' ?></h2>
  <span class="count-tag"><?= $totalCount ?> found</span>
</div>

<?php if (!$listings): ?>
  <p class="rooms-empty">No boarding house has an open room that matches. Try a higher budget or a different room type.</p>
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
    <section class="card-chunk" id="chunk-<?= $n ?>" aria-label="Boarding houses <?= $from ?>–<?= $to ?> of <?= $totalCount ?>">
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
      <a href="<?= h($moreUrl) ?>" class="btn btn-ghost js-show-more">Show more</a>
      <p class="count-tag">Showing <?= $shown ?> of <?= $totalCount ?></p>
    </div>
  <?php elseif ($totalCount > $perPage): ?>
    <div class="show-more">
      <p class="count-tag">Showing all <?= $totalCount ?></p>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/scripts/show_more.php'; ?>
<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
