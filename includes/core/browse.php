<?php
/**
 * Browse: the filters a boarder can set, and the queries that apply them.
 */

/* ---------------------------------------------------------------------------
 * Browse filters
 *
 * Browse, its "try instead" suggestions and the heart forms on its cards all
 * rebuild the filters from known keys here, so nothing from the request is
 * echoed into a URL unchecked.
 * ------------------------------------------------------------------------ */

/**
 * The amenities a boarder can filter by, as id => ['name', 'extra'].
 *
 * The administrator's amenities come first, in the order they were made,
 * because every landlord picks from them. After them come the amenities
 * landlords added themselves ('extra' => true), but only those on a listing
 * boarders can see, since any other would find nothing. The same name added by
 * two landlords is one choice, keyed by its lowest id: the filter matches by
 * name (browse_where()), so ticking it finds both listings. The column
 * collation ignores case, so "rooftop" and "Rooftop" are one choice too.
 */

function filter_amenity_options()
{
    global $pdo;
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = [];
    try {
        $stmt = $pdo->query(
            'SELECT MIN(a.amenity_id) AS id, a.amenity_name AS name, MAX(a.landlord_id IS NULL) AS standard
               FROM amenities a
              WHERE a.landlord_id IS NULL
                 OR EXISTS (SELECT 1 FROM boarding_house_amenities fa
                              JOIN boarding_houses bh ON bh.boarding_house_id = fa.boarding_house_id
                              ' . LIVE_LANDLORD_JOIN . '
                             WHERE fa.amenity_id = a.amenity_id AND fa.is_available = 1 AND ' . LIVE_LISTING_WHERE . ')
              GROUP BY a.amenity_name
              ORDER BY standard DESC, (CASE WHEN standard = 1 THEN id END), name'
        );
        foreach ($stmt as $row) {
            $options[(int) $row['id']] = ['name' => $row['name'], 'extra' => !$row['standard']];
        }
    } catch (PDOException $e) {
        error_log('RoomEase: amenity filter options failed - ' . $e->getMessage());
    }
    return $options;
}

/**
 * The browse filters that actually apply, from $_GET or a heart form's POST.
 * Returns only the keys that are set: q, room_type, max_rent, vacant,
 * amenities (a sorted list of ids from filter_amenity_options()), page.
 */

function browse_filters(array $src, array $roomTypes)
{
    $filters = [];

    // A list sent as q[] is no search, rather than a PHP warning.
    $q = is_string($src['q'] ?? null) ? trim($src['q']) : '';
    if ($q !== '') {
        $filters['q'] = mb_substr($q, 0, 100);
    }

    // An unrecognised room type is "no filter", not "no results".
    $type = (int) ($src['room_type'] ?? 0);
    if ($type > 0 && isset($roomTypes[$type])) {
        $filters['room_type'] = $type;
    }

    // A budget of nothing, or less, is no budget at all.
    $rent = $src['max_rent'] ?? '';
    if (is_numeric($rent) && (float) $rent > 0) {
        $filters['max_rent'] = (int) ceil((float) $rent);
    }

    // Only a listing with a room open and not yet full.
    if (($src['vacant'] ?? '') === '1') {
        $filters['vacant'] = 1;
    }

    // An id that is not a choice any more is dropped, not an error: a saved
    // link can outlive the amenity it named.
    $ticked = array_filter((array) ($src['amenities'] ?? []), function ($id) {
        return is_scalar($id) && ctype_digit((string) $id);
    });
    $ticked = array_intersect(array_unique(array_map('intval', $ticked)), array_keys(filter_amenity_options()));
    if ($ticked) {
        sort($ticked);
        $filters['amenities'] = array_slice($ticked, 0, 30);
    }

    $page = (int) ($src['page'] ?? 1);
    if ($page > 1) {
        $filters['page'] = min($page, 500);
    }

    return $filters;
}

/** Browse at the given filters, as a path for base_url() or redirect(). */

function browse_path(array $filters, $fragment = '')
{
    return 'boarder/browse.php' . ($filters ? '?' . http_build_query($filters) : '')
        . ($fragment !== '' ? '#' . $fragment : '');
}

/* ---------------------------------------------------------------------------
 * Browse queries
 *
 * All take the filters from browse_filters() and share browse_where(), so a
 * "try instead" suggestion's number is exactly what following it shows.
 * ------------------------------------------------------------------------ */

/**
 * The WHERE clause, on listing alias `bh`, and its named parameters.
 *
 * Room type, budget and "has a free slot" must all be met by one open room in
 * the listing, not by the listing as a whole. Without "has a free slot", full
 * rooms still count: a full listing that fits is found, sorted after those
 * with space. Amenities belong to the listing, which must offer every one
 * ticked; each is matched by name, so a choice that stands for the same name
 * added by two landlords finds both (filter_amenity_options()).
 */
function browse_where(array $filters)
{
    $where = [LIVE_LISTING_WHERE]; // also requires at least one room
    $params = [];
    $bind = function ($value) use (&$params) {
        $name = ':w' . count($params);
        $params[$name] = $value;
        return $name;
    };

    if (isset($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(bh.name LIKE ' . $bind($like) . ' OR bh.address LIKE ' . $bind($like) . ')';
    }

    $roomMatch = [];
    if (isset($filters['room_type'])) {
        $roomMatch[] = 'fr.room_type_id = ' . $bind($filters['room_type']);
    }
    if (isset($filters['max_rent'])) {
        $roomMatch[] = 'fr.monthly_rent <= ' . $bind($filters['max_rent']);
    }
    if (!empty($filters['vacant'])) {
        $roomMatch[] = 'fr.slots_taken < fr.capacity';
    }
    if ($roomMatch) {
        $where[] = 'EXISTS (SELECT 1 FROM rooms fr WHERE fr.boarding_house_id = bh.boarding_house_id AND fr.is_open = 1 AND '
            . implode(' AND ', $roomMatch) . ')';
    }

    $amenityOptions = filter_amenity_options();
    foreach ($filters['amenities'] ?? [] as $amenityId) {
        $where[] = 'EXISTS (SELECT 1 FROM boarding_house_amenities fa
                              JOIN amenities a ON a.amenity_id = fa.amenity_id
                             WHERE fa.boarding_house_id = bh.boarding_house_id AND fa.is_available = 1
                               AND a.amenity_name = ' . $bind($amenityOptions[$amenityId]['name']) . ')';
    }

    return [implode(' AND ', $where), $params];
}

/** How many live listings match the filters. */
function browse_count(array $filters)
{
    global $pdo;
    [$where, $params] = browse_where($filters);
    // LIVE_LANDLORD_JOIN keeps a deactivated or removed landlord's listings out.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM boarding_houses bh ' . LIVE_LANDLORD_JOIN . ' WHERE ' . $where);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * The first $limit matching listings: those with a room available first, then
 * fully occupied ones, newest first within each.
 *
 * With a room filter on, each listing also carries match_rent and match_count,
 * for the room that matched, so a card quotes the Double Sharing room the
 * boarder asked for rather than the listing's cheapest room of any kind. A
 * room with a slot free is quoted before a full one.
 */
function browse_listings(array $filters, $limit)
{
    global $pdo;
    [$where, $params] = browse_where($filters);

    $matchConds = [];
    if (isset($filters['room_type'])) {
        $matchConds[] = 'mr.room_type_id = :mtype';
        $params[':mtype'] = $filters['room_type'];
    }
    if (isset($filters['max_rent'])) {
        $matchConds[] = 'mr.monthly_rent <= :mrent';
        $params[':mrent'] = $filters['max_rent'];
    }
    // On its own, "has a free slot" quotes the listing's rent as usual; with a
    // room filter it narrows the quoted room to one with space.
    if ($matchConds && !empty($filters['vacant'])) {
        $matchConds[] = 'mr.slots_taken < mr.capacity';
    }

    $matchSelect = '';
    $matchJoin = '';
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

    $stmt = $pdo->prepare(
        'SELECT bh.*, ' . ROOM_SUMMARY_COLUMNS . ', ' . COVER_PHOTO_SELECT . $matchSelect . '
           FROM boarding_houses bh
           ' . LIVE_LANDLORD_JOIN . '
           ' . room_summary_join(true) . '
           ' . $matchJoin . '
          WHERE ' . $where . '
          ORDER BY rs.rooms_available > 0 DESC, bh.created_at DESC, bh.boarding_house_id DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * The lowest rent of an open room in a matching listing, or null when nothing
 * matches. Ask with the budget left out, and it is the lowest budget that
 * would match everything else the boarder asked for.
 */
function browse_lowest_rent(array $filters)
{
    global $pdo;
    [$where, $params] = browse_where($filters);
    $sql = 'SELECT MIN(r.monthly_rent) FROM rooms r
              JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id ' . LIVE_LANDLORD_JOIN . '
             WHERE r.is_open = 1 AND ' . $where;
    if (isset($filters['room_type'])) {
        $sql .= ' AND r.room_type_id = :rtype';
        $params[':rtype'] = $filters['room_type'];
    }
    if (!empty($filters['vacant'])) {
        $sql .= ' AND r.slots_taken < r.capacity';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lowest = $stmt->fetchColumn();
    return ($lowest === false || $lowest === null) ? null : $lowest;
}
