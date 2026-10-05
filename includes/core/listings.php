<?php
/**
 * Listing queries and rules: what is public, room availability, browse
 * filters, stay terms, moderation and saved listings.
 */

/** JOIN that hides listings whose landlord is deactivated or removed. */

const LIVE_LANDLORD_JOIN =
    'JOIN users lu ON lu.user_id = bh.landlord_id AND lu.is_active = 1 AND lu.deleted_at IS NULL';

/** A listing is public when approved and not removed. */

const LIVE_STATUS_WHERE = "bh.moderation_status = 'approved' AND bh.deleted_at IS NULL";

/** LIVE_STATUS_WHERE plus at least one room. Use with LIVE_LANDLORD_JOIN. */

const LIVE_LISTING_WHERE = LIVE_STATUS_WHERE
    . ' AND EXISTS (SELECT 1 FROM rooms hr WHERE hr.boarding_house_id = bh.boarding_house_id)';

/** Cover photo: the house cover, else any house photo, else a room photo. */

const COVER_PHOTO_SELECT = '(SELECT img.image_path FROM images img
       WHERE img.boarding_house_id = bh.boarding_house_id
       ORDER BY img.room_id IS NULL DESC, img.is_primary DESC, img.image_id ASC
       LIMIT 1) AS cover_photo';

/** Cover photo thumbnail for admin tables, or a camera icon if there is none. */

function listing_thumb_html(array $l, $class = 'queue-thumb')
{
    if (!empty($l['cover_photo'])) {
        return '<img class="' . h($class) . '" src="' . h(base_url($l['cover_photo']))
            . '" alt="" loading="lazy" decoding="async">';
    }
    return '<span class="' . h($class) . ' ' . h($class) . '--empty" aria-hidden="true">'
        . '<i class="fas fa-camera"></i></span>';
}

/* ---------------------------------------------------------------------------
 * Rooms. Availability is never stored; it is worked out from is_open,
 * capacity and slots_taken.
 * ------------------------------------------------------------------------ */

/** Columns that room_summary_join() adds, for a SELECT list. */

const ROOM_SUMMARY_COLUMNS = 'rs.room_count, rs.rooms_available, rs.rooms_open,
       rs.rent_from_available, rs.rent_from_all, rs.room_types, rs.open_room_types, rs.slots_left';

/**
 * Room totals per listing, joined as `rs`. $inner = true drops listings with no rooms.
 * Boarders see open_room_types (closed rooms left out); the panels see room_types.
 */

function room_summary_join($inner = false)
{
    return ($inner ? 'JOIN' : 'LEFT JOIN') . " (
        SELECT r.boarding_house_id,
               COUNT(*) AS room_count,
               SUM(r.is_open = 1 AND r.slots_taken < r.capacity) AS rooms_available,
               SUM(r.is_open = 1) AS rooms_open,
               MIN(CASE WHEN r.is_open = 1 AND r.slots_taken < r.capacity THEN r.monthly_rent END) AS rent_from_available,
               MIN(r.monthly_rent) AS rent_from_all,
               GROUP_CONCAT(DISTINCT rt.room_type_name ORDER BY rt.room_type_id SEPARATOR ', ') AS room_types,
               GROUP_CONCAT(DISTINCT CASE WHEN r.is_open = 1 THEN rt.room_type_name END
                            ORDER BY rt.room_type_id SEPARATOR ', ') AS open_room_types,
               SUM(CASE WHEN r.is_open = 1 THEN GREATEST(r.capacity - r.slots_taken, 0) ELSE 0 END) AS slots_left
          FROM rooms r
          JOIN room_types rt ON rt.room_type_id = r.room_type_id
         GROUP BY r.boarding_house_id
    ) rs ON rs.boarding_house_id = bh.boarding_house_id";
}

/**
 * A room's status: available, full or closed. Returns key, label, pill
 * (public CSS class), badge (panel CSS class), slots_left and note.
 */

function room_state(array $room)
{
    $capacity = max(1, (int) $room['capacity']);
    $taken = min($capacity, max(0, (int) $room['slots_taken']));
    $left = $capacity - $taken;

    if (empty($room['is_open'])) {
        return ['key' => 'closed', 'label' => 'Not available', 'pill' => 'pill--rejected', 'badge' => 'badge-secondary',
            'slots_left' => $left, 'note' => 'Not taking tenants right now'];
    }
    if ($left === 0) {
        return ['key' => 'full', 'label' => 'Full', 'pill' => 'pill--unavailable', 'badge' => 'badge-warning',
            'slots_left' => 0, 'note' => $capacity === 1 ? 'Occupied' : 'Fully occupied'];
    }
    return ['key' => 'available', 'label' => 'Available', 'pill' => 'pill--available', 'badge' => 'badge-success',
        'slots_left' => $left,
        'note' => $capacity === 1 ? 'Vacant' : $left . ' of ' . $capacity . ' slots left'];
}

/** Sort order: available, then full, then closed. */

function room_state_rank(array $room)
{
    return ['available' => 0, 'full' => 1, 'closed' => 2][room_state($room)['key']];
}

/**
 * A whole listing's status from the room totals: available, full, closed or
 * none. Also returns a summary like "3 of 5 rooms available" and rent_from.
 */

function listing_availability(array $listing)
{
    $count = (int) ($listing['room_count'] ?? 0);
    $available = (int) ($listing['rooms_available'] ?? 0);
    $open = (int) ($listing['rooms_open'] ?? 0);
    $rentFrom = $listing['rent_from_available'] ?? null;
    if ($rentFrom === null) {
        $rentFrom = $listing['rent_from_all'] ?? null;
    }

    $base = ['room_count' => $count, 'rent_from' => $rentFrom];

    if ($count === 0) {
        return $base + ['key' => 'none', 'label' => 'No rooms yet', 'pill' => 'pill--unavailable',
            'summary' => 'No rooms added yet'];
    }
    if ($available > 0) {
        return $base + ['key' => 'available', 'label' => 'Available', 'pill' => 'pill--available',
            'summary' => $count === 1 ? '1 room, available' : $available . ' of ' . $count . ' rooms available'];
    }
    if ($open > 0) {
        // "All rooms taken" only if none are closed.
        $summary = $count === 1 ? '1 room, occupied'
            : ($open === $count ? 'All ' . $count . ' rooms taken' : '0 of ' . $count . ' rooms available');
        return $base + ['key' => 'full', 'label' => 'Fully occupied', 'pill' => 'pill--unavailable',
            'summary' => $summary];
    }
    return $base + ['key' => 'closed', 'label' => 'Not available', 'pill' => 'pill--rejected',
        'summary' => 'Not taking tenants right now'];
}

/* ---------------------------------------------------------------------------
 * Browse filters. Filters are rebuilt from known keys only, so nothing from
 * the URL is passed on unchecked.
 * ------------------------------------------------------------------------ */

/**
 * Amenities boarders can filter by, as id => ['name', 'extra']. The admin's
 * list comes first, then landlords' own ones that appear on a public listing.
 * Matching is by name, so the same name from two landlords is one choice.
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
                             WHERE fa.amenity_id = a.amenity_id AND ' . LIVE_LISTING_WHERE . ')
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
 * The orders browse can show its results in, as value => label. '' is the
 * default. Nearest is only there while "Find places near me" is on.
 */

function browse_sort_options($near = false)
{
    return ($near ? ['nearest' => 'Nearest'] : [])
        + ['' => 'Available first', 'rent' => 'Lowest rent', 'newest' => 'Newest'];
}

/** The distances, in kilometres, that "Find places near me" can be narrowed to. */

const NEAR_RADII_KM = [1, 2, 5];

/**
 * The valid filters from $_GET or $_POST: q, room_type, min_rent, max_rent,
 * vacant, amenities, near, within, sort, page. near=1 only asks for Near me;
 * browse turns it on when the session has the visitor's location.
 */

function browse_filters(array $src, array $roomTypes)
{
    $filters = [];

    // q[] (an array) is ignored.
    $q = is_string($src['q'] ?? null) ? trim($src['q']) : '';
    if ($q !== '') {
        $filters['q'] = mb_substr($q, 0, 100);
    }

    // An unknown room type is ignored.
    $type = (int) ($src['room_type'] ?? 0);
    if ($type > 0 && isset($roomTypes[$type])) {
        $filters['room_type'] = $type;
    }

    // The rent range, in whole pesos. Zero or negative means no limit, and a
    // range typed the wrong way round is turned the right way.
    $min = $src['min_rent'] ?? '';
    if (is_numeric($min) && (float) $min > 0) {
        $filters['min_rent'] = (int) floor(min((float) $min, 1000000));
    }
    $rent = $src['max_rent'] ?? '';
    if (is_numeric($rent) && (float) $rent > 0) {
        $filters['max_rent'] = (int) ceil(min((float) $rent, 1000000));
    }
    if (isset($filters['min_rent'], $filters['max_rent']) && $filters['min_rent'] > $filters['max_rent']) {
        [$filters['min_rent'], $filters['max_rent']] = [$filters['max_rent'], $filters['min_rent']];
    }

    // Only listings with a free slot.
    if (($src['vacant'] ?? '') === '1') {
        $filters['vacant'] = 1;
    }

    // Unknown amenity ids are dropped (an old link may name a deleted one).
    $ticked = array_filter((array) ($src['amenities'] ?? []), function ($id) {
        return is_scalar($id) && ctype_digit((string) $id);
    });
    $ticked = array_intersect(array_unique(array_map('intval', $ticked)), array_keys(filter_amenity_options()));
    if ($ticked) {
        sort($ticked);
        $filters['amenities'] = array_slice($ticked, 0, 30);
    }

    // Near me, and how far to look: one of NEAR_RADII_KM, and only with near.
    if (($src['near'] ?? '') === '1') {
        $filters['near'] = 1;
        $within = is_scalar($src['within'] ?? null) ? (int) $src['within'] : 0;
        if (in_array($within, NEAR_RADII_KM, true)) {
            $filters['within'] = $within;
        }
    }

    // An unknown order is the default one.
    $sort = is_string($src['sort'] ?? null) ? $src['sort'] : '';
    if ($sort !== '' && isset(browse_sort_options(isset($filters['near']))[$sort])) {
        $filters['sort'] = $sort;
    }

    $page = (int) ($src['page'] ?? 1);
    if ($page > 1) {
        $filters['page'] = min($page, 500);
    }

    return $filters;
}

/** The browse page path for these filters. */

function browse_path(array $filters, $fragment = '')
{
    return 'boarder/browse.php' . ($filters ? '?' . http_build_query($filters) : '')
        . ($fragment !== '' ? '#' . $fragment : '');
}

/* ---------------------------------------------------------------------------
 * Find places near me. The browser gives the visitor's location, rounded to
 * about 100 m, and it stays in their session for NEAR_LOCATION_MINUTES, only
 * to sort browse by distance. It is never saved in the database or put in a
 * link, and browse forgets it as soon as Near me is turned off.
 * ------------------------------------------------------------------------ */

/** How long a location is used before the visitor is asked again. */

const NEAR_LOCATION_MINUTES = 30;

function remember_near_location($lat, $lng)
{
    $_SESSION['near'] = ['lat' => round($lat, 3), 'lng' => round($lng, 3), 'at' => time()];
}

function forget_near_location()
{
    unset($_SESSION['near']);
}

/** The visitor's location as ['lat', 'lng'], or null when there is none or it is too old. */

function near_location()
{
    $near = $_SESSION['near'] ?? null;
    if (!is_array($near) || time() - (int) ($near['at'] ?? 0) > NEAR_LOCATION_MINUTES * 60) {
        return null;
    }
    return ['lat' => (float) $near['lat'], 'lng' => (float) $near['lng']];
}

/**
 * SQL for the distance in kilometres from a point to a listing's map pin, by
 * the haversine formula, which MySQL and MariaDB both run. NULL for a listing
 * without a pin. Returns [$sql, $params]; the listing must be aliased bh.
 */

function distance_km_sql($lat, $lng)
{
    // LEAST(1, …) keeps rounding from pushing ASIN past its domain.
    return [
        '(6371 * 2 * ASIN(LEAST(1, SQRT(
            POWER(SIN(RADIANS(bh.latitude - ?) / 2), 2)
            + COS(RADIANS(?)) * COS(RADIANS(bh.latitude)) * POWER(SIN(RADIANS(bh.longitude - ?) / 2), 2)
         ))))',
        [$lat, $lat, $lng],
    ];
}

/**
 * A distance in words, no finer than the rounded location allows: "400 m
 * away" to the nearest 100 m, then "1.2 km away", then "12 km away".
 */

function distance_label($km)
{
    $metres = (int) round($km * 10) * 100;
    if ($metres < 1000) {
        return max($metres, 100) . ' m away';
    }
    $tenths = round($km, 1);
    return ($tenths < 10 ? number_format($tenths, 1) : number_format($km)) . ' km away';
}

/**
 * Number of public listings, the rooms available in them, and the lowest rent
 * among those rooms (null when none has a free slot), for the home and About
 * pages.
 */

function live_listing_stats()
{
    global $pdo;
    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS listings, COALESCE(SUM(rs.rooms_available), 0) AS rooms_available,
                    MIN(rs.rent_from_available) AS lowest_rent
               FROM boarding_houses bh ' . LIVE_LANDLORD_JOIN . ' ' . room_summary_join(true) . '
              WHERE ' . LIVE_STATUS_WHERE
        )->fetch();
        return [
            'listings' => (int) $row['listings'],
            'rooms_available' => (int) $row['rooms_available'],
            'lowest_rent' => $row['lowest_rent'] !== null ? (float) $row['lowest_rent'] : null,
        ];
    } catch (PDOException $e) {
        error_log('RoomEase: live listing stats failed - ' . $e->getMessage());
        return ['listings' => 0, 'rooms_available' => 0, 'lowest_rent' => null];
    }
}

/**
 * The photo for a room type's tile on the home page: assets/img/room-types/
 * <room type>.webp, for example bed-spacer.webp for "Bed Spacer". Null when
 * there is none, as for a type an administrator added later.
 */

function room_type_photo($roomTypeName)
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $roomTypeName)), '-');
    $path = 'assets/img/room-types/' . $slug . '.webp';
    return $slug !== '' && is_file(__DIR__ . '/../../' . $path) ? $path : null;
}

/** Each room type with how many public listings have an open room of that type (0 included). */

function room_type_counts()
{
    return lookup_options(
        'SELECT rt.room_type_id, rt.room_type_name, COUNT(DISTINCT bh.boarding_house_id) AS listings
           FROM room_types rt
           LEFT JOIN rooms r ON r.room_type_id = rt.room_type_id AND r.is_open = 1
           LEFT JOIN (boarding_houses bh ' . LIVE_LANDLORD_JOIN . ')
                  ON bh.boarding_house_id = r.boarding_house_id AND ' . LIVE_STATUS_WHERE . '
          GROUP BY rt.room_type_id, rt.room_type_name
          ORDER BY rt.room_type_id ASC',
        PDO::FETCH_ASSOC
    );
}

/**
 * A landlord's listings, newest first, with room totals and photo counts.
 * $status ('approved', 'pending' or 'rejected') keeps only that approval status.
 */

function landlord_listings($landlordId, $status = '')
{
    global $pdo;
    $params = [(int) $landlordId];
    $statusWhere = '';
    if ($status !== '') {
        $statusWhere = ' AND bh.moderation_status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare(
        'SELECT bh.*, ' . ROOM_SUMMARY_COLUMNS . ',
                (SELECT COUNT(*) FROM images img
                   WHERE img.boarding_house_id = bh.boarding_house_id) AS photo_count
           FROM boarding_houses bh
           ' . room_summary_join() . '
          WHERE bh.landlord_id = ? AND bh.deleted_at IS NULL' . $statusWhere . '
          ORDER BY bh.created_at DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * How many listings a landlord has: total, approved, pending and rejected,
 * and live: approved with at least one room, which is what boarders see.
 * For the dashboard's tiles, the filter on My Boarding Houses and the
 * sidebar's Needs Changes.
 */

function landlord_listing_counts($landlordId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(moderation_status = 'approved'), 0) AS approved,
                    COALESCE(SUM(moderation_status = 'pending'), 0)  AS pending,
                    COALESCE(SUM(moderation_status = 'rejected'), 0) AS rejected,
                    COALESCE(SUM(moderation_status = 'approved' AND EXISTS (
                        SELECT 1 FROM rooms r WHERE r.boarding_house_id = bh.boarding_house_id)), 0) AS live
               FROM boarding_houses bh
              WHERE landlord_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([(int) $landlordId]);
        return array_map('intval', $stmt->fetch());
    } catch (PDOException $e) {
        error_log('RoomEase: landlord listing counts failed - ' . $e->getMessage());
        return ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'live' => 0];
    }
}

/** Number of pending listings (not counting those whose landlord is deactivated or removed). */

function pending_listing_count()
{
    global $pdo;
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM boarding_houses bh
               JOIN users u ON u.user_id = bh.landlord_id AND u.is_active = 1 AND u.deleted_at IS NULL
              WHERE bh.moderation_status = 'pending' AND bh.deleted_at IS NULL"
        )->fetchColumn();
    } catch (PDOException $e) {
        error_log('RoomEase: pending listing count failed - ' . $e->getMessage());
        return 0;
    }
}

/**
 * The pending queue without $exceptId: ['count' => int, 'next' => listing|null].
 * "next" is the one waiting longest, so the admin can review them in order.
 */

function pending_queue_after($exceptId = 0)
{
    global $pdo;
    $empty = ['count' => 0, 'next' => null];
    try {
        $stmt = $pdo->prepare(
            "SELECT bh.boarding_house_id, bh.name
               FROM boarding_houses bh
               JOIN users u ON u.user_id = bh.landlord_id AND u.is_active = 1 AND u.deleted_at IS NULL
              WHERE bh.moderation_status = 'pending' AND bh.deleted_at IS NULL
                AND bh.boarding_house_id <> ?
              ORDER BY bh.updated_at ASC"
        );
        $stmt->execute([(int) $exceptId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('RoomEase: pending queue lookup failed - ' . $e->getMessage());
        return $empty;
    }

    return ['count' => count($rows), 'next' => $rows[0] ?? null];
}

/* ---------------------------------------------------------------------------
 * Stay terms: curfew, minimum stay, payment, gender policy, visitors, pets,
 * cooking and the map pin. All optional; NULL means "not stated" and the
 * listing page leaves it out.
 * ------------------------------------------------------------------------ */

/** The stay-term columns, in form order. */

const STAY_TERM_COLUMNS = [
    'curfew', 'minimum_stay_months', 'payment_methods', 'gender_policy',
    'visitors_allowed', 'pets_allowed', 'cooking_allowed', 'latitude', 'longitude',
];

/**
 * The box Baybay City fits in, as [south, west, north, east]: its
 * OpenStreetMap boundary with about a kilometre to spare. Every listing is in
 * Baybay City, so a map pin outside the box is refused.
 */

const BAYBAY_BOUNDS = [10.54, 124.64, 10.88, 124.94];

/** True when a point lies inside BAYBAY_BOUNDS. */

function in_baybay($lat, $lng)
{
    [$south, $west, $north, $east] = BAYBAY_BOUNDS;
    return $lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east;
}

/** Payment methods, as stored value => label. */

function payment_method_options()
{
    return ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'];
}

/** Gender policies, as stored value => label. */

function gender_policy_options()
{
    return ['any' => 'All genders', 'female' => 'Female only', 'male' => 'Male only'];
}

/** "cash,gcash" as "Cash, GCash". Unknown values are dropped. */

function payment_methods_label($stored)
{
    $options = payment_method_options();
    $labels = [];
    foreach (explode(',', (string) $stored) as $value) {
        if (isset($options[$value])) {
            $labels[] = $options[$value];
        }
    }
    return implode(', ', $labels);
}

/** Errors in a listing's main fields. Used by both Add and Edit Listing, so they match. */

function listing_errors(array $listing)
{
    $errors = [];
    if ($listing['name'] === '') {
        $errors[] = 'Boarding house name is required.';
    }
    if ($listing['address'] === '') {
        $errors[] = 'Complete address in Baybay City is required.';
    }
    if ($listing['contact_number'] === '') {
        $errors[] = 'Landlord contact number is required.';
    } else {
        $errors[] = phone_problem($listing['contact_number'], 'Contact number');
    }
    $errors[] = too_long($listing['name'], 150, 'Boarding house name');
    $errors[] = too_long($listing['address'], 500, 'Address');
    $errors[] = too_long($listing['description'], 2000, 'Description');
    $errors[] = too_long($listing['house_rules'], 2000, 'House rules');

    return array_values(array_filter($errors));
}

/**
 * Read and check the stay terms from a form. Returns [$values, $errors, $echo]:
 * $values ready to save (blanks become NULL), and $echo, what was typed, to
 * refill the form after an error.
 */

function stay_terms_from_post(array $post)
{
    $errors = [];
    $values = array_fill_keys(STAY_TERM_COLUMNS, null);
    $text = function ($key) use ($post) {
        return is_string($post[$key] ?? null) ? trim($post[$key]) : '';
    };

    $curfew = $text('curfew');
    if (mb_strlen($curfew) > 60) {
        $errors[] = 'Curfew must be 60 characters or fewer.';
    } elseif ($curfew !== '') {
        $values['curfew'] = $curfew;
    }

    $stay = $text('minimum_stay_months');
    if ($stay !== '') {
        if (!ctype_digit($stay) || (int) $stay < 1 || (int) $stay > 60) {
            $errors[] = 'Minimum stay must be between 1 and 60 months, or left blank.';
        } else {
            $values['minimum_stay_months'] = (int) $stay;
        }
    }

    $ticked = array_filter((array) ($post['payment_methods'] ?? []), 'is_string');
    $methods = array_values(array_intersect(array_keys(payment_method_options()), $ticked));
    $values['payment_methods'] = $methods ? implode(',', $methods) : null;

    $gender = $text('gender_policy');
    $values['gender_policy'] = isset(gender_policy_options()[$gender]) ? $gender : null;

    foreach (['visitors_allowed', 'pets_allowed', 'cooking_allowed'] as $rule) {
        $answer = $text($rule);
        $values[$rule] = $answer === '1' ? 1 : ($answer === '0' ? 0 : null);
    }

    $lat = $text('latitude');
    $lng = $text('longitude');
    if ($lat !== '' || $lng !== '') {
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            $errors[] = 'Place the map pin by clicking the map, or clear the location.';
        } elseif (!in_baybay((float) $lat, (float) $lng)) {
            $errors[] = 'The map pin is outside Baybay City. Move it onto the boarding house, or clear the location.';
        } else {
            $values['latitude'] = round((float) $lat, 6);
            $values['longitude'] = round((float) $lng, 6);
        }
    }

    $echo = $values;
    foreach (['curfew', 'minimum_stay_months', 'latitude', 'longitude'] as $typed) {
        $echo[$typed] = $text($typed);
    }

    return [$values, $errors, $echo];
}

/**
 * Changing one of these fields (or adding photos) sends an approved listing
 * back for review. Rents, slots and stay terms can change freely.
 */

const REVIEWED_LISTING_FIELDS = [
    'name' => 'name', 'address' => 'address', 'description' => 'description', 'house_rules' => 'house rules',
];

/** Set a listing back to pending if it is currently $fromStatus. True if it changed. */

function return_listing_to_queue($houseId, $landlordId, $fromStatus)
{
    global $pdo;
    $stmt = $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'pending', rejection_reason = NULL, moderated_at = NULL, moderated_by = NULL
          WHERE boarding_house_id = ? AND landlord_id = ? AND moderation_status = ? AND deleted_at IS NULL"
    );
    $stmt->execute([(int) $houseId, (int) $landlordId, $fromStatus]);
    return $stmt->rowCount() === 1;
}

/** Badge for pending / approved / rejected. */

function moderation_badge($status)
{
    switch ($status) {
        case 'approved':
            return '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Approved</span>';
        case 'rejected':
            return '<span class="badge badge-danger px-2 py-1"><i class="fas fa-times-circle mr-1"></i> Rejected</span>';
        default:
            return '<span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i> Pending</span>';
    }
}

/** The listing ids this user has saved, as a lookup set. One query per page. */

function saved_listing_ids($userId)
{
    global $pdo;
    if (!$userId) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT boarding_house_id FROM favorites WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** True for boarders: only they can save listings. */

function can_save_listings()
{
    return is_logged_in() && current_role() === 'boarder';
}

/** Show the save heart to boarders and guests (guests are asked to log in first). */

function shows_save_heart()
{
    return !is_logged_in() || can_save_listings();
}
