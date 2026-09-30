<?php
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/components/listing_card.php';
require __DIR__ . '/../includes/components/search_bar.php';

// Every filter is rebuilt from known keys: an unrecognised room type or a
// budget of nothing is simply no filter, never "no results".
$roomTypes = room_type_options();
$filters = browse_filters($_GET, $roomTypes);
$q = $filters['q'] ?? '';
$roomType = $filters['room_type'] ?? '';
$maxRent = $filters['max_rent'] ?? '';
$vacant = !empty($filters['vacant']);
$amenityOptions = filter_amenity_options();
$amenityIds = $filters['amenities'] ?? [];
$searchFilters = array_diff_key($filters, ['page' => 1]);
$filtered = (bool) $searchFilters;

$totalCount = browse_count($searchFilters);

// Rooms come in groups of $perPage. ?page=N shows every group up to N, so the
// "Show more" link works with JavaScript off, and a saved heart that reloads
// the page brings back everything the boarder had already opened.
$perPage = 6;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = max(1, min($filters['page'] ?? 1, $totalPages));

$listings = browse_listings($searchFilters, $perPage * $page);
if ($roomType !== '') {
  foreach ($listings as &$row) {
    $row['match_type'] = $roomTypes[$roomType];
  }
  unset($row);
}

$savedIds = can_save_listings() ? saved_listing_ids($_SESSION['user_id']) : [];
$shown = count($listings);
$nextCount = min($perPage, $totalCount - $shown);
$moreUrl = base_url(browse_path($searchFilters + ['page' => $page + 1], 'chunk-' . ($page + 1)));

/* ---------------------------------------------------------------------------
 * The search in words, each part removable, and what to try when nothing
 * matched. The suggestions only ever quote real counts and real rents.
 * ------------------------------------------------------------------------ */
// The search without one of its parts, for a chip's link and the suggestions.
$without = function ($key, $amenityId = null) use ($searchFilters) {
  if ($amenityId === null) {
    return array_diff_key($searchFilters, [$key => 1]);
  }
  $rest = array_values(array_diff($searchFilters['amenities'], [$amenityId]));
  return $rest ? ['amenities' => $rest] + $searchFilters : array_diff_key($searchFilters, ['amenities' => 1]);
};

$chips = [];
if ($q !== '') {
  $chips[] = ['label' => '“' . $q . '”', 'without' => $without('q')];
}
if ($roomType !== '') {
  $chips[] = ['label' => $roomTypes[$roomType], 'without' => $without('room_type')];
}
if ($maxRent !== '') {
  $chips[] = ['label' => 'Up to ' . peso_round($maxRent), 'without' => $without('max_rent')];
}
if ($vacant) {
  $chips[] = ['label' => 'Has a free slot', 'without' => $without('vacant')];
}
foreach ($amenityIds as $amenityId) {
  $chips[] = ['label' => $amenityOptions[$amenityId]['name'], 'without' => $without('amenities', $amenityId)];
}

$emptyMessage = '';
$suggestions = [];
if (!$listings && $filtered) {
  $typeName = $roomType !== '' ? $roomTypes[$roomType] : '';
  $budget = $maxRent !== '' ? peso_round($maxRent) : '';
  $roomPhrase = ($typeName !== '' || $budget !== '' || $vacant)
    ? ($vacant ? 'a ' : 'an open ') . ($typeName !== '' ? $typeName . ' ' : '') . 'room'
      . ($vacant ? ' with a free slot' : '')
      . ($budget !== '' ? ' for ' . $budget . ' or less' : '')
    : '';

  // "Wi-Fi, Laundry Area and Kitchen Access"
  $amenityNames = array_map(function ($id) use ($amenityOptions) {
    return $amenityOptions[$id]['name'];
  }, $amenityIds);
  $lastAmenity = array_pop($amenityNames);
  $amenityPhrase = $lastAmenity === null ? ''
    : ($amenityNames ? implode(', ', $amenityNames) . ' and ' : '') . $lastAmenity;

  if ($roomPhrase !== '') {
    $wants = 'has ' . $roomPhrase . ($amenityPhrase !== '' ? ' and offers ' . $amenityPhrase : '');
  } else {
    $wants = $amenityPhrase !== '' ? 'offers ' . $amenityPhrase : '';
  }

  if ($q !== '' && $wants !== '') {
    $emptyMessage = 'No boarding house matching “' . $q . '” ' . $wants . '.';
  } elseif ($q !== '') {
    $emptyMessage = 'No boarding house name or address matches “' . $q . '”.';
  } else {
    $emptyMessage = 'No boarding house ' . $wants . ' right now.';
  }

  // The lowest rent that would match everything else the boarder asked for.
  if ($maxRent !== '') {
    $rentFilters = array_diff_key($searchFilters, ['max_rent' => 1]);
    $lowest = browse_lowest_rent($rentFilters);
    if ($lowest !== null) {
      $raised = $rentFilters + ['max_rent' => (int) ceil((float) $lowest)];
      $n = browse_count($raised);
      if ($n > 0) {
        $suggestions[] = [
          'href' => browse_path($raised, 'results'),
          'label' => 'Rooms up to ' . peso_round(ceil((float) $lowest)),
          'note' => 'the lowest ' . ($typeName !== '' ? $typeName . ' ' : '') . 'rent listed',
          'count' => $n,
        ];
      }
    }
  }
  if ($roomType !== '' && count($chips) > 1) {
    $anyType = array_diff_key($searchFilters, ['room_type' => 1]);
    $n = browse_count($anyType);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($anyType, 'results'), 'label' => 'Any room type', 'note' => '', 'count' => $n];
    }
  }
  if ($vacant && count($chips) > 1) {
    $withFull = $without('vacant');
    $n = browse_count($withFull);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($withFull, 'results'), 'label' => 'Include full rooms',
        'note' => 'a slot may open up soon', 'count' => $n];
    }
  }
  if (count($chips) > 1) {
    foreach (array_slice($amenityIds, 0, 3) as $amenityId) {
      $lessOne = $without('amenities', $amenityId);
      $n = browse_count($lessOne);
      if ($n > 0) {
        $suggestions[] = ['href' => browse_path($lessOne, 'results'),
          'label' => 'Without ' . $amenityOptions[$amenityId]['name'], 'note' => '', 'count' => $n];
      }
    }
  }
  if ($q !== '' && count($chips) > 1) {
    $noWords = array_diff_key($searchFilters, ['q' => 1]);
    $n = browse_count($noWords);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($noWords, 'results'), 'label' => 'Without “' . $q . '”', 'note' => '', 'count' => $n];
    }
  }
  $all = browse_count([]);
  if ($all > 0) {
    $suggestions[] = ['href' => browse_path([], 'results'), 'label' => 'Every boarding house', 'note' => '', 'count' => $all];
  }
}

$pageTitle = 'Browse rooms';
$metaDescription = 'Browse every approved boarding house in Baybay City, Leyte. '
  . 'Filter by name, room type, budget, free slots and amenities, and see what each room includes before you visit.';
$band = [
  'title' => 'Rooms in Baybay City',
  'lede' => 'Every boarding house here is approved. Filter by name, room type, budget, free slots, or the amenities you need.',
];
require __DIR__ . '/../includes/layouts/header.php';

// The filter bar carries the #listings anchor, so links from the home page
// land with the search form and the first rooms in view. A search submitted
// from it lands on #results, the first thing that changed.
render_search_bar([
  'id' => 'listings',
  'anchor' => 'results',
  'room_types' => $roomTypes,
  'q' => $q,
  'room_type' => $roomType,
  'max_rent' => $maxRent,
  'vacant' => $vacant,
  'amenity_options' => $amenityOptions,
  'amenities' => $amenityIds,
]);
?>

<div class="section-head" id="results">
  <h2><?= !$filtered ? 'All boarding houses' : ($totalCount ? 'Matching boarding houses' : 'No matches') ?></h2>
  <?php if ($totalCount): ?>
    <span class="count-tag"><?= $totalCount ?> <?= $filtered ? 'found' : 'listed' ?></span>
  <?php endif; ?>
</div>

<?php if ($chips): ?>
  <ul class="filter-summary" aria-label="Your search">
    <?php foreach ($chips as $chip): ?>
      <li>
        <a class="filter-chip" href="<?= h(base_url(browse_path($chip['without'], 'results'))) ?>"
          aria-label="Remove <?= h($chip['label']) ?> from your search">
          <?= h($chip['label']) ?><?= icon('x', 14) ?>
        </a>
      </li>
    <?php endforeach; ?>
    <li><a class="filter-clear" href="<?= h(base_url(browse_path([], 'results'))) ?>">Clear all</a></li>
  </ul>
<?php endif; ?>

<?php if (!$listings): ?>
  <div class="rooms-empty rooms-empty--search">
    <p class="rooms-empty-lead"><?= h($emptyMessage ?: 'No boarding houses are listed right now.') ?></p>
    <?php if ($suggestions): ?>
      <p class="rooms-empty-try">Try instead:</p>
      <ul class="empty-suggestions">
        <?php foreach ($suggestions as $s): ?>
          <li>
            <a href="<?= h(base_url($s['href'])) ?>">
              <span><?= h($s['label']) ?><?php if ($s['note'] !== ''): ?>, <em><?= h($s['note']) ?></em><?php endif; ?></span>
              <span class="empty-count"><?= (int) $s['count'] ?> <?= $s['count'] === 1 ? 'boarding house' : 'boarding houses' ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php foreach (array_chunk($listings, $perPage) as $i => $chunk): ?>
    <?php
    $n = $i + 1;
    $from = $i * $perPage + 1;
    $to = $from + count($chunk) - 1;
    ?>
    <?php /* Each group is its own grid so "Show more" can lift exactly one
             group out of the next page; the gap between grids matches the gap
             inside them, so the groups read as one continuous grid. */ ?>
    <section class="card-chunk" id="chunk-<?= $n ?>" aria-label="Boarding houses <?= $from ?>–<?= $to ?> of <?= $totalCount ?>">
      <div class="card-grid">
        <?php foreach ($chunk as $l): ?>
          <?php render_listing_card($l, shows_save_heart() ? [
            'saved' => isset($savedIds[$l['boarding_house_id']]),
            'return' => 'browse',
            'fields' => $searchFilters + ['page' => (int) $page],
          ] : null); ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($shown < $totalCount): ?>
    <div class="show-more">
      <a href="<?= h($moreUrl) ?>" class="btn btn-ghost js-show-more">Show <?= $nextCount ?> more</a>
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
