<?php
/**
 * Listing queries and rules: what is live, room availability, browse filters,
 * stay terms, moderation status and saved listings.
 */

/**
 * The JOIN that hides listings whose landlord is deactivated or archived.
 *
 * Browse never used to look at the landlord's account at all, so a
 * deactivated landlord's listings stayed on the public site. Soft deletion
 * would have inherited exactly the same hole.
 */

const LIVE_LANDLORD_JOIN =
    'JOIN users lu ON lu.user_id = bh.landlord_id AND lu.is_active = 1 AND lu.deleted_at IS NULL';

/**
 * What a listing itself needs to be on the public site: an administrator's
 * approval. A listing an administrator removed stays in the table, archived,
 * and never shows.
 */

const LIVE_STATUS_WHERE = "bh.moderation_status = 'approved' AND bh.deleted_at IS NULL";

/**
 * Everything else a listing needs to be on the public site: approved, switched
 * on, and at least one room. Pair with LIVE_LANDLORD_JOIN.
 */

const LIVE_LISTING_WHERE = LIVE_STATUS_WHERE
    . ' AND EXISTS (SELECT 1 FROM rooms hr WHERE hr.boarding_house_id = bh.boarding_house_id)';

/**
 * A listing's cover photo: its house cover first, then any house photo, and
 * only then a room photo, so a listing with only room photos still has one.
 */

const COVER_PHOTO_SELECT = '(SELECT img.image_path FROM images img
       WHERE img.boarding_house_id = bh.boarding_house_id
       ORDER BY img.room_id IS NULL DESC, img.is_primary DESC, img.image_id ASC
       LIMIT 1) AS cover_photo';

/**
 * A listing's cover photo at thumbnail size, for the administrator's queue and
 * tables. $l needs cover_photo (COVER_PHOTO_SELECT).
 *
 * A listing with no photo gets a drawn placeholder rather than an empty gap,
 * because "this one has nothing to look at" is itself something a moderator
 * wants to see at a glance. The picture is decorative: the listing's name is
 * always the link beside it.
 */

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
 * Rooms (database/roomease.sql)
 *
 * Rent, room type and capacity belong to each room. Whether a room is
 * available is never stored: it is open with a slot left, full, or closed.
 * ------------------------------------------------------------------------ */

/** The room summary columns room_summary_join() provides, for a SELECT list. */

const ROOM_SUMMARY_COLUMNS = 'rs.room_count, rs.rooms_available, rs.rooms_open,
       rs.rent_from_available, rs.rent_from_all, rs.room_types, rs.open_room_types, rs.slots_left';

/**
 * One row of room figures per listing, joined as `rs`. Pass $inner = true
 * where a listing with no rooms should drop out of the results.
 *
 * room_types names every room, which is what the panels list. A boarder's
 * card reads open_room_types instead, so a closed room is not advertised, and
 * slots_left, the beds still free across the open rooms.
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
 * Where a room stands, for any row with capacity, slots_taken and is_open.
 *
 * Returns key (available|full|closed), label, pill (public CSS class), badge
 * (Bootstrap class for the panel), slots_left, and note, the one line a
 * boarder reads under the room.
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

/** Sort order for rooms shown to boarders: available, then full, then closed. */

function room_state_rank(array $room)
{
    return ['available' => 0, 'full' => 1, 'closed' => 2][room_state($room)['key']];
}

/**
 * Where a whole listing stands, from the room_summary_join() columns.
 *
 * Returns key (available|full|closed|none), label and pill for the badge,
 * summary ("3 of 5 rooms available"), rent_from, and room_count.
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
        // "All 5 rooms taken" only when every room really is full; with some
        // closed as well, say how many are available instead.
        $summary = $count === 1 ? '1 room, occupied'
            : ($open === $count ? 'All ' . $count . ' rooms taken' : '0 of ' . $count . ' rooms available');
        return $base + ['key' => 'full', 'label' => 'Fully occupied', 'pill' => 'pill--unavailable',
            'summary' => $summary];
    }
    return $base + ['key' => 'closed', 'label' => 'Not available', 'pill' => 'pill--rejected',
        'summary' => 'Not taking tenants right now'];
}

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
 * name (browse_amenity_where()), so ticking it finds both listings. The column
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

/**
 * How many listings the public site is showing right now, and how many rooms
 * in them are available. The home page quotes both, so they have to be the
 * real figures, never rounded or padded.
 */

function live_listing_stats()
{
    global $pdo;
    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS listings, COALESCE(SUM(rs.rooms_available), 0) AS rooms_available
               FROM boarding_houses bh ' . LIVE_LANDLORD_JOIN . ' ' . room_summary_join(true) . '
              WHERE ' . LIVE_STATUS_WHERE
        )->fetch();
        return ['listings' => (int) $row['listings'], 'rooms_available' => (int) $row['rooms_available']];
    } catch (PDOException $e) {
        error_log('RoomEase: live listing stats failed - ' . $e->getMessage());
        return ['listings' => 0, 'rooms_available' => 0];
    }
}

/**
 * Every room type with the number of live listings that have an open room of
 * that type, which is exactly what browse returns for that filter. Types with
 * none are kept, with a count of 0, in the same order as the browse filter.
 */

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
 * Every listing a landlord owns, newest first, with its room figures and a
 * photo count each. Shown on the dashboard and on My Boarding Houses.
 */

function landlord_listings($landlordId)
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT bh.*, ' . ROOM_SUMMARY_COLUMNS . ',
                (SELECT COUNT(*) FROM images img
                   WHERE img.boarding_house_id = bh.boarding_house_id) AS photo_count
           FROM boarding_houses bh
           ' . room_summary_join() . '
          WHERE bh.landlord_id = ? AND bh.deleted_at IS NULL
          ORDER BY bh.created_at DESC'
    );
    $stmt->execute([(int) $landlordId]);
    return $stmt->fetchAll();
}

/**
 * Listings waiting for an administrator's decision. A listing whose landlord
 * is removed or deactivated is not counted: it cannot be approved until the
 * account is back, so it is not waiting on anyone.
 */

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
 * What is still waiting for a decision once $exceptId is set aside, so an
 * administrator can go from one review straight to the next.
 *
 * Returns ['count' => int, 'next' => ['boarding_house_id' => int, 'name' =>
 * string]|null]. Ordered oldest change first, matching the dashboard's queue
 * and the sidebar's count, so "next" is genuinely the one that has waited
 * longest rather than whichever the database happened to return.
 *
 * $exceptId is excluded whatever its state: called before a decision it skips
 * the listing being looked at, and called after one it skips a listing whose
 * new state may not have landed in this connection's view yet.
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
 * Stay terms (database/roomease.sql)
 *
 * What a boarder asks before visiting: curfew, minimum stay, how rent
 * is paid, who the house accepts, and whether visitors, pets and cooking are
 * allowed, plus the listing's map pin. Every one is optional, and NULL means
 * the landlord has not said, so the listing page leaves it out rather than
 * guessing.
 * ------------------------------------------------------------------------ */

/** The stay-term columns on boarding_houses, in the order the forms write them. */

const STAY_TERM_COLUMNS = [
    'curfew', 'minimum_stay_months', 'payment_methods', 'gender_policy',
    'visitors_allowed', 'pets_allowed', 'cooking_allowed', 'latitude', 'longitude',
];

/** Payment methods a landlord can tick, as stored value => label. */

function payment_method_options()
{
    return ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'];
}

/** Who a listing accepts, as stored value => label. */

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

/**
 * What is wrong with a listing's own fields as typed on Add Listing or Edit
 * Listing, as messages ready to show. Both pages call this, so the two forms
 * always accept exactly the same listing. Stay terms, amenities and utilities,
 * and rooms are checked by their own functions.
 */

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
 * Read and validate the stay-term fields from a submitted listing form.
 *
 * Returns [$values, $errors, $echo]:
 *   $values  exactly STAY_TERM_COLUMNS, normalised for the database: blanks
 *            become NULL, yes/no rules become 1, 0 or NULL, payment methods
 *            are filtered to the known list, and coordinates are kept only as
 *            a valid pair.
 *   $errors  messages for the form.
 *   $echo    what to put back in the form if it is shown again, so a typo is
 *            corrected rather than silently cleared.
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
 * The listing fields an administrator's approval vouches for, as column =>
 * how a message names it: the text a boarder reads. Changing one of them, or
 * adding a photo, sends an approved listing back for review. Rents, slots and
 * stay terms are left to change freely, because a landlord updates them as
 * rooms fill and empty.
 */

const REVIEWED_LISTING_FIELDS = [
    'name' => 'name', 'address' => 'address', 'description' => 'description', 'house_rules' => 'house rules',
];

/**
 * Put one of a landlord's listings back in the approval queue, if it is in
 * $fromStatus now. Returns true when it moved. Used when a rejected listing is
 * corrected, and when an approved one changes what its approval covered.
 */

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

/**
 * Bootstrap badge for a listing's moderation state. Used by the admin queue
 * and the landlord dashboard so both describe a listing the same way.
 */

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

/**
 * The boarding_house_id values the given user has saved, as a lookup set.
 * Fetched once per page rather than queried per listing.
 */

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

/**
 * True when the current user may save listings. Saving is a boarder feature;
 * landlords and administrators manage listings instead.
 */

function can_save_listings()
{
    return is_logged_in() && current_role() === 'boarder';
}

/**
 * True when the heart should be drawn: for a boarder, and for a guest, whose
 * tap sends them to log in and then saves the listing. Landlords and
 * administrators cannot save, so they are never shown a heart that refuses.
 */

function shows_save_heart()
{
    return !is_logged_in() || can_save_listings();
}
