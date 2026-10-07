<?php
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/components/listing_card.php';
require __DIR__ . '/../includes/components/filter_panel.php';

// Every filter is rebuilt from known keys: an unrecognised room type or a
// budget of nothing is simply no filter, never "no results".
$roomTypes = room_type_options();
$filters = browse_filters($_GET, $roomTypes);

// Find places near me is on while the link says near=1 and the session still
// has the visitor's location. If it has run out, the page says so and shows
// the usual order. Any page without near=1 forgets the location, so turning
// Near me off (its chip, or Turn off) also forgets where the visitor is.
$near = null;
$nearExpired = false;
if (isset($filters['near'])) {
  $near = near_location();
  if (!$near) {
    $nearExpired = true;
    unset($filters['near'], $filters['within']);
    if (($filters['sort'] ?? '') === 'nearest') {
      unset($filters['sort']);
    }
  }
} else {
  forget_near_location();
}
[$distanceSql, $distanceParams] = $near ? distance_km_sql($near['lat'], $near['lng']) : [null, []];
$within = $filters['within'] ?? '';

$q = $filters['q'] ?? '';
$roomType = $filters['room_type'] ?? '';
$minRent = $filters['min_rent'] ?? '';
$maxRent = $filters['max_rent'] ?? '';
$vacant = !empty($filters['vacant']);
$amenityOptions = filter_amenity_options();
$amenityIds = $filters['amenities'] ?? [];
$sort = $filters['sort'] ?? '';
// The search as the boarder set it, kept by every link on the page: the
// filters, Near me and the order. Sorting is not filtering, and neither is
// Near me on its own: it measures every listing, until a distance is chosen.
$searchFilters = array_diff_key($filters, ['page' => 1]);
$filtered = (bool) array_diff_key($searchFilters, ['sort' => 1, 'near' => 1]);

/**
 * WHERE clause + parameters for a set of filters (also used to count the
 * "try instead" suggestions). Room type, rent range and free slot must all
 * match the same open room. The listing must have every amenity ticked, and
 * with a distance chosen, a map pin that close to the visitor.
 */
$whereFor = function (array $f) use ($amenityOptions, $distanceSql, $distanceParams) {
  // LIVE_LISTING_WHERE also requires at least one room.
  $where = [LIVE_LISTING_WHERE];
  $params = [];
  if (isset($f['q'])) {
    $where[] = '(bh.name LIKE ? OR bh.address LIKE ?)';
    $like = '%' . $f['q'] . '%';
    $params[] = $like;
    $params[] = $like;
  }
  $roomMatch = [];
  if (isset($f['room_type'])) {
    $roomMatch[] = 'fr.room_type_id = ?';
    $params[] = $f['room_type'];
  }
  if (isset($f['min_rent'])) {
    $roomMatch[] = 'fr.monthly_rent >= ?';
    $params[] = $f['min_rent'];
  }
  if (isset($f['max_rent'])) {
    $roomMatch[] = 'fr.monthly_rent <= ?';
    $params[] = $f['max_rent'];
  }
  if (!empty($f['vacant'])) {
    $roomMatch[] = 'fr.slots_taken < fr.capacity';
  }
  if ($roomMatch) {
    $where[] = 'EXISTS (SELECT 1 FROM rooms fr WHERE fr.boarding_house_id = bh.boarding_house_id AND fr.is_open = 1 AND '
      . implode(' AND ', $roomMatch) . ')';
  }
  // After the room clause, so the parameters stay in the order of their ?s.
  foreach ($f['amenities'] ?? [] as $amenityId) {
    $where[] = 'EXISTS (SELECT 1 FROM boarding_house_amenities fa
                          JOIN amenities a ON a.amenity_id = fa.amenity_id
                         WHERE fa.boarding_house_id = bh.boarding_house_id AND a.amenity_name = ?)';
    $params[] = $amenityOptions[$amenityId]['name'];
  }
  if (isset($f['within']) && $distanceSql !== null) {
    $where[] = 'bh.latitude IS NOT NULL AND ' . $distanceSql . ' <= ?';
    $params = array_merge($params, $distanceParams, [$f['within']]);
  }
  return [implode(' AND ', $where), $params];
};

// LIVE_LANDLORD_JOIN hides listings of deactivated or removed landlords.
$countFor = function (array $f) use ($pdo, $whereFor) {
  [$where, $params] = $whereFor($f);
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM boarding_houses bh ' . LIVE_LANDLORD_JOIN . ' WHERE ' . $where);
  $stmt->execute($params);
  return (int) $stmt->fetchColumn();
};

$totalCount = $countFor($searchFilters);

// "Show more": ?page=N shows all results up to page N (works without JavaScript).
$perPage = 12;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = max(1, min($filters['page'] ?? 1, $totalPages));

// With a room filter (room type or rent), the card shows the rent of the
// matching room, not the listing's cheapest room. Rooms with a free slot come
// first.
$matchJoin = '';
$matchSelect = '';
$matchParams = [];
$matchConds = [];
if ($roomType !== '') {
  $matchConds[] = 'mr.room_type_id = ?';
  $matchParams[] = $roomType;
}
if ($minRent !== '') {
  $matchConds[] = 'mr.monthly_rent >= ?';
  $matchParams[] = $minRent;
}
if ($maxRent !== '') {
  $matchConds[] = 'mr.monthly_rent <= ?';
  $matchParams[] = $maxRent;
}
// "Has a free slot" on its own quotes the listing's rent as usual; with a room
// filter it narrows the quoted room to one with space, as it narrowed the match.
if ($matchConds && $vacant) {
  $matchConds[] = 'mr.slots_taken < mr.capacity';
}
if ($matchConds) {
  $matchSelect = ', m.match_rent, m.match_count';
  $matchJoin = 'LEFT JOIN (
      SELECT mr.boarding_house_id,
             COALESCE(MIN(CASE WHEN mr.slots_taken < mr.capacity THEN mr.monthly_rent END), MIN(mr.monthly_rent)) AS match_rent,
             COUNT(*) AS match_count
        FROM rooms mr
       WHERE mr.is_open = 1 AND ' . implode(' AND ', $matchConds) . '
       GROUP BY mr.boarding_house_id
    ) m ON m.boarding_house_id = bh.boarding_house_id';
}

// With Near me on, each listing's distance from the visitor, for the cards
// and for Nearest (listings without a pin last).
$distanceSelect = $distanceSql !== null ? ', ' . $distanceSql . ' AS distance_km' : '';

// The order (Sort by). By default, listings with a room available first, then
// fully occupied ones, newest first within each. "Lowest rent" goes by the
// rent each card quotes. Every order ends on the id, so "Show more" never
// repeats or skips a listing.
$quotedRent = $matchConds ? 'm.match_rent' : 'COALESCE(rs.rent_from_available, rs.rent_from_all)';
$orderBy = [
  'nearest' => 'distance_km IS NULL, distance_km ASC, bh.created_at DESC',
  'rent' => $quotedRent . ' ASC, bh.created_at DESC',
  'newest' => 'bh.created_at DESC',
][$sort] ?? 'rs.rooms_available > 0 DESC, bh.created_at DESC';

[$where, $whereParams] = $whereFor($searchFilters);
$sql = "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ", " . COVER_PHOTO_SELECT . $matchSelect . $distanceSelect . "
        FROM boarding_houses bh
        " . LIVE_LANDLORD_JOIN . "
        " . room_summary_join(true) . "
        " . $matchJoin . "
        WHERE " . $where . "
        ORDER BY " . $orderBy . ", bh.boarding_house_id DESC
        LIMIT " . (int) ($perPage * $page);
$stmt = $pdo->prepare($sql);
// In the order of the ?s: the distance in the SELECT, the room match join, the WHERE.
$stmt->execute(array_merge($distanceSelect !== '' ? $distanceParams : [], $matchParams, $whereParams));
$listings = $stmt->fetchAll();
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
 * Filter chips (each removable), and suggestions when nothing matched.
 * ------------------------------------------------------------------------ */
// The filters without one of them.
$without = function ($key, $amenityId = null) use ($searchFilters) {
  if ($amenityId === null) {
    return array_diff_key($searchFilters, [$key => 1]);
  }
  $rest = array_values(array_diff($searchFilters['amenities'], [$amenityId]));
  return $rest ? ['amenities' => $rest] + $searchFilters : array_diff_key($searchFilters, ['amenities' => 1]);
};

// Turning Near me off also drops its distance and its order.
$offNear = array_diff_key($searchFilters, ['near' => 1, 'within' => 1]);
if ($sort === 'nearest') {
  unset($offNear['sort']);
}

$chips = [];
if ($near) {
  $chips[] = ['label' => 'Near you', 'without' => $offNear];
}
if ($within !== '') {
  $chips[] = ['label' => 'Within ' . $within . ' km', 'without' => $without('within')];
}
if ($q !== '') {
  $chips[] = ['label' => '“' . $q . '”', 'without' => $without('q')];
}
if ($roomType !== '') {
  $chips[] = ['label' => $roomTypes[$roomType], 'without' => $without('room_type')];
}
if ($minRent !== '') {
  $chips[] = ['label' => 'From ' . peso_round($minRent), 'without' => $without('min_rent')];
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
  if ($minRent !== '' && $maxRent !== '') {
    $budget = ' for ' . peso_round($minRent) . ' to ' . peso_round($maxRent);
  } elseif ($maxRent !== '') {
    $budget = ' for ' . peso_round($maxRent) . ' or less';
  } elseif ($minRent !== '') {
    $budget = ' for ' . peso_round($minRent) . ' or more';
  } else {
    $budget = '';
  }
  // "an open Bed Spacer room", but "an open Single Room": "room" only once.
  $roomNoun = $typeName === '' ? 'room'
    : (preg_match('/\broom$/i', $typeName) ? $typeName : $typeName . ' room');
  $roomPhrase = ($typeName !== '' || $budget !== '' || $vacant)
    ? ($vacant ? 'a ' : 'an open ') . $roomNoun . ($vacant ? ' with a free slot' : '') . $budget
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

  // "within 2 km of you", once a distance is chosen.
  $nearPhrase = $within !== '' ? ' within ' . $within . ' km of you' : '';
  if ($q !== '' && $wants !== '') {
    $emptyMessage = 'No boarding house matching “' . $q . '”' . $nearPhrase . ' ' . $wants . '.';
  } elseif ($q !== '') {
    $emptyMessage = $nearPhrase !== ''
      ? 'No boarding house matching “' . $q . '”' . $nearPhrase . '.'
      : 'No boarding house name or address matches “' . $q . '”.';
  } else {
    $emptyMessage = 'No boarding house' . $nearPhrase . ($wants !== '' ? ' ' . $wants : '') . ' right now.';
  }

  // Too close: a wider circle that has something, then no limit at all.
  if ($within !== '') {
    foreach (NEAR_RADII_KM as $km) {
      if ($km > $within) {
        $wider = ['within' => $km] + $searchFilters;
        $n = $countFor($wider);
        if ($n > 0) {
          $suggestions[] = ['href' => browse_path($wider, 'results'), 'label' => 'Within ' . $km . ' km', 'note' => '', 'count' => $n];
          break;
        }
      }
    }
    $anyDistance = $without('within');
    $n = $countFor($anyDistance);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($anyDistance, 'results'), 'label' => 'Any distance', 'note' => '', 'count' => $n];
    }
  }

  // The lowest rent that would match everything else the boarder asked for.
  if ($maxRent !== '') {
    $rentFilters = array_diff_key($searchFilters, ['max_rent' => 1]);
    [$rentWhere, $rentParams] = $whereFor($rentFilters);
    $rentSql = 'SELECT MIN(r.monthly_rent) FROM rooms r
                  JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id ' . LIVE_LANDLORD_JOIN . '
                 WHERE r.is_open = 1 AND ' . $rentWhere
      . ($roomType !== '' ? ' AND r.room_type_id = ?' : '')
      . ($minRent !== '' ? ' AND r.monthly_rent >= ?' : '')
      . ($vacant ? ' AND r.slots_taken < r.capacity' : '');
    $rentStmt = $pdo->prepare($rentSql);
    $rentStmt->execute(array_merge(
      $rentParams,
      $roomType !== '' ? [$roomType] : [],
      $minRent !== '' ? [$minRent] : []
    ));
    $lowest = $rentStmt->fetchColumn();
    if ($lowest !== false && $lowest !== null) {
      $raised = $rentFilters + ['max_rent' => (int) ceil((float) $lowest)];
      $n = $countFor($raised);
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
  if ($minRent !== '' && count($chips) > 1) {
    $noMin = $without('min_rent');
    $n = $countFor($noMin);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($noMin, 'results'),
        'label' => 'Rooms under ' . peso_round($minRent) . ' too', 'note' => '', 'count' => $n];
    }
  }
  if ($roomType !== '' && count($chips) > 1) {
    $anyType = array_diff_key($searchFilters, ['room_type' => 1]);
    $n = $countFor($anyType);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($anyType, 'results'), 'label' => 'Any room type', 'note' => '', 'count' => $n];
    }
  }
  if ($vacant && count($chips) > 1) {
    $withFull = $without('vacant');
    $n = $countFor($withFull);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($withFull, 'results'), 'label' => 'Include full rooms',
        'note' => 'a slot may open up soon', 'count' => $n];
    }
  }
  if (count($chips) > 1) {
    foreach (array_slice($amenityIds, 0, 3) as $amenityId) {
      $lessOne = $without('amenities', $amenityId);
      $n = $countFor($lessOne);
      if ($n > 0) {
        $suggestions[] = ['href' => browse_path($lessOne, 'results'),
          'label' => 'Without ' . $amenityOptions[$amenityId]['name'], 'note' => '', 'count' => $n];
      }
    }
  }
  if ($q !== '' && count($chips) > 1) {
    $noWords = array_diff_key($searchFilters, ['q' => 1]);
    $n = $countFor($noWords);
    if ($n > 0) {
      $suggestions[] = ['href' => browse_path($noWords, 'results'), 'label' => 'Without “' . $q . '”', 'note' => '', 'count' => $n];
    }
  }
  $all = $countFor([]);
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
?>

<div class="browse-layout">
  <?php render_filter_panel([
    // Searches land on the results, which on a phone are under the panel.
    'action' => base_url('boarder/browse.php') . '#results',
    'room_types' => $roomTypes,
    'amenity_options' => $amenityOptions,
    'filters' => $searchFilters,
    'near_off' => $offNear,
    'near_outside' => $near && !in_baybay($near['lat'], $near['lng']),
  ]); ?>

  <div class="browse-results">
    <div class="section-head results-head" id="results">
      <div class="results-title">
        <h2><?= !$filtered ? 'All boarding houses' : ($totalCount ? 'Matching boarding houses' : 'No matches') ?></h2>
        
      </div>
      <?php if ($totalCount > 1): ?>
        <?php /* Part of the filter form (form="browse-filters"), so a new order
                 keeps every filter. It applies as soon as it changes, or with
                 its own button where JavaScript is off. */ ?>
        <div class="results-sort">
          <label for="sort">Sort by</label>
          <select id="sort" name="sort" form="browse-filters" data-auto-submit>
            <?php foreach (browse_sort_options((bool) $near) as $sortValue => $sortLabel): ?>
              <option value="<?= h($sortValue) ?>" <?= $sort === $sortValue ? 'selected' : '' ?>><?= h($sortLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" form="browse-filters" class="btn btn-ghost results-sort-apply">Sort</button>
        </div>
      <?php elseif ($sort !== ''): ?>
        <?php /* Nothing to sort, but the order is kept for the next search. */ ?>
        <input type="hidden" name="sort" value="<?= h($sort) ?>" form="browse-filters">
      <?php endif; ?>
    </div>

    <?php if ($nearExpired): ?>
      <p class="results-note">
        RoomEase no longer has the location you shared, so this is the usual order. Use Find places near me to
        see distances again.
      </p>
    <?php endif; ?>

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
  </div>
</div>

<?php require __DIR__ . '/../includes/scripts/show_more.php'; ?>
<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
