<?php
/**
 * Amenities, utilities and room types: the lists landlords pick from.
 */

/** Read a lookup table. If it fails, log it and return an empty list (the page says so). */

function lookup_options($sql, $mode = PDO::FETCH_COLUMN)
{
    global $pdo;
    try {
        return $pdo->query($sql)->fetchAll($mode);
    } catch (PDOException $e) {
        error_log('RoomEase: lookup query failed - ' . $e->getMessage());
        return [];
    }
}

/** Room types as id => name, for the room form and the browse filter. */

function room_type_options()
{
    $rows = lookup_options(
        'SELECT room_type_id, room_type_name FROM room_types ORDER BY room_type_id ASC',
        PDO::FETCH_ASSOC
    );

    $options = [];
    foreach ($rows as $row) {
        $options[(int) $row['room_type_id']] = $row['room_type_name'];
    }
    return $options;
}

/* ---------------------------------------------------------------------------
 * Utilities and amenities. landlord_id NULL = made by the admin, usable by
 * every landlord. Otherwise only that landlord can use it.
 * ------------------------------------------------------------------------ */

/** Table and column names for 'amenity' or 'utility'. */

function lookup_kind($kind)
{
    static $kinds = [
        'utility' => ['table' => 'utilities', 'id' => 'utility_id', 'name' => 'utility_name',
            'junction' => 'boarding_house_utilities', 'singular' => 'utility', 'plural' => 'utilities',
            'Singular' => 'Utility', 'Plural' => 'Utilities'],
        'amenity' => ['table' => 'amenities', 'id' => 'amenity_id', 'name' => 'amenity_name',
            'junction' => 'boarding_house_amenities', 'singular' => 'amenity', 'plural' => 'amenities',
            'Singular' => 'Amenity', 'Plural' => 'Amenities'],
    ];
    if (!isset($kinds[$kind])) {
        throw new InvalidArgumentException('Unknown list: ' . $kind);
    }
    return $kinds[$kind];
}

/** A typed name with extra spaces removed. */

function normalise_lookup_name($name)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $name));
}

/** Items a landlord can use: the admin's, then their own. Rows of ['id', 'name', 'own']. */

function lookup_choices($kind, $landlordId = null)
{
    global $pdo;
    $k = lookup_kind($kind);
    try {
        $stmt = $pdo->prepare(
            "SELECT {$k['id']} AS id, {$k['name']} AS name, landlord_id IS NOT NULL AS own
               FROM {$k['table']}
              WHERE landlord_id IS NULL OR landlord_id = ?
              ORDER BY landlord_id IS NOT NULL, (CASE WHEN landlord_id IS NULL THEN {$k['id']} END), {$k['name']}"
        );
        $stmt->execute([$landlordId === null ? 0 : (int) $landlordId]);
        return array_map(function ($row) {
            return ['id' => (int) $row['id'], 'name' => $row['name'], 'own' => (bool) $row['own']];
        }, $stmt->fetchAll());
    } catch (PDOException $e) {
        error_log('RoomEase: ' . $k['plural'] . ' lookup failed - ' . $e->getMessage());
        return [];
    }
}

/** An existing item with the same name (ignoring case), or null. */

function lookup_name_clash($kind, $name, $landlordId = null, $exceptId = null)
{
    global $pdo;
    $k = lookup_kind($kind);
    $stmt = $pdo->prepare(
        "SELECT {$k['id']} AS id, {$k['name']} AS name, landlord_id
           FROM {$k['table']}
          WHERE {$k['name']} = ?
            AND (landlord_id IS NULL" . ($landlordId === null ? '' : ' OR landlord_id = ?') . ")
            AND {$k['id']} <> ?
          LIMIT 1"
    );
    $params = [$name];
    if ($landlordId !== null) {
        $params[] = (int) $landlordId;
    }
    $params[] = (int) $exceptId;
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/** Why a typed name cannot be used, or null when it can. */

function lookup_name_problem($kind, $name)
{
    $k = lookup_kind($kind);
    if ($name === '') {
        return 'Enter a name for the ' . $k['singular'] . '.';
    }
    if (mb_strlen($name) > 100) {
        return $k['Singular'] . ' names must be 100 characters or fewer.';
    }
    return null;
}

/** Merge landlords' items with the same name into the admin's item. Listings keep their ticks. */

function merge_lookup_copies($kind, $globalId)
{
    global $pdo;
    $k = lookup_kind($kind);

    $name = $pdo->prepare("SELECT {$k['name']} FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id IS NULL");
    $name->execute([(int) $globalId]);
    $globalName = $name->fetchColumn();
    if ($globalName === false) {
        return 0;
    }

    $copies = $pdo->prepare(
        "SELECT {$k['id']} FROM {$k['table']} WHERE {$k['name']} = ? AND landlord_id IS NOT NULL"
    );
    $copies->execute([$globalName]);

    $move = $pdo->prepare("UPDATE IGNORE {$k['junction']} SET {$k['id']} = ? WHERE {$k['id']} = ?");
    $drop = $pdo->prepare("DELETE FROM {$k['table']} WHERE {$k['id']} = ?");
    $merged = 0;
    foreach ($copies->fetchAll(PDO::FETCH_COLUMN) as $copyId) {
        $move->execute([(int) $globalId, (int) $copyId]);
        $drop->execute([(int) $copyId]);
        $merged++;
    }
    return $merged;
}

/** Add an item. Returns [id, error]. With $reuse, an existing name returns its id instead of an error. */

function create_lookup($kind, $name, $landlordId = null, $reuse = false)
{
    global $pdo;
    $k = lookup_kind($kind);
    $name = normalise_lookup_name($name);

    if ($problem = lookup_name_problem($kind, $name)) {
        return [null, $problem];
    }
    if ($clash = lookup_name_clash($kind, $name, $landlordId)) {
        if ($reuse) {
            return [(int) $clash['id'], null];
        }
        return [null, $clash['landlord_id'] === null
            ? '"' . $clash['name'] . '" is already on the list everyone uses.'
            : 'You already have "' . $clash['name'] . '".'];
    }

    $pdo->prepare("INSERT INTO {$k['table']} (landlord_id, {$k['name']}, created_by, updated_by) VALUES (?, ?, ?, ?)")
        ->execute([$landlordId === null ? null : (int) $landlordId, $name, current_user_id(), current_user_id()]);
    $id = (int) $pdo->lastInsertId();

    if ($landlordId === null) {
        merge_lookup_copies($kind, $id);
    }
    return [$id, null];
}

/** Rename an item. Returns an error message, or null on success. */

function rename_lookup($kind, $id, $name, $landlordId = null)
{
    global $pdo;
    $k = lookup_kind($kind);
    $name = normalise_lookup_name($name);

    if ($problem = lookup_name_problem($kind, $name)) {
        return $problem;
    }
    if ($clash = lookup_name_clash($kind, $name, $landlordId, $id)) {
        return $clash['landlord_id'] === null
            ? '"' . $clash['name'] . '" is already on the list everyone uses.'
            : 'You already have "' . $clash['name'] . '".';
    }

    $owner = $landlordId === null ? 'landlord_id IS NULL' : 'landlord_id = ' . (int) $landlordId;
    $pdo->prepare("UPDATE {$k['table']} SET {$k['name']} = ?, updated_by = ? WHERE {$k['id']} = ? AND $owner")
        ->execute([$name, current_user_id(), (int) $id]);

    if ($landlordId === null) {
        merge_lookup_copies($kind, $id);
    }
    return null;
}

/** Make a landlord's item available to everyone (merged if the admin has the same name). Error or null. */

function promote_lookup($kind, $id)
{
    global $pdo;
    $k = lookup_kind($kind);

    $stmt = $pdo->prepare("SELECT {$k['name']} AS name FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id IS NOT NULL");
    $stmt->execute([(int) $id]);
    $item = $stmt->fetch();
    if (!$item) {
        return 'That ' . $k['singular'] . ' was not found, or is already available to everyone.';
    }

    // Use the caller's transaction if there is one.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $existing = lookup_name_clash($kind, $item['name']);
        if ($existing) {
            $globalId = (int) $existing['id'];
        } else {
            $pdo->prepare("UPDATE {$k['table']} SET landlord_id = NULL, updated_by = ? WHERE {$k['id']} = ?")
                ->execute([current_user_id(), (int) $id]);
            $globalId = (int) $id;
        }
        merge_lookup_copies($kind, $globalId);
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($ownTransaction) {
            $pdo->rollBack();
        }
        error_log('RoomEase: promoting a ' . $k['singular'] . ' failed - ' . $e->getMessage());
        return 'That ' . $k['singular'] . ' could not be made available to everyone.';
    }
    return null;
}

/** How many listings use each item, as id => count. */

function lookup_usage_counts($kind)
{
    global $pdo;
    $k = lookup_kind($kind);
    try {
        return array_map('intval', $pdo->query(
            "SELECT {$k['id']}, COUNT(*) FROM {$k['junction']} GROUP BY {$k['id']}"
        )->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Read the amenities and utilities from a listing form. Ids the landlord isn't
 * allowed to use are dropped. Returns amenity_ids, utilities, new_amenities,
 * new_utilities and errors.
 */

function listing_lookups_from_post(array $post, $landlordId)
{
    $allowed = function ($kind) use ($landlordId) {
        return array_flip(array_column(lookup_choices($kind, $landlordId), 'id'));
    };
    $allowedAmenities = $allowed('amenity');
    $allowedUtilities = $allowed('utility');
    $errors = [];

    $amenityIds = [];
    foreach ((array) ($post['amenities'] ?? []) as $id) {
        if (is_scalar($id) && isset($allowedAmenities[(int) $id])) {
            $amenityIds[(int) $id] = (int) $id;
        }
    }

    // A too-long billing note is refused, not cut off mid-sentence.
    $policyTooLong = 'Each utility\'s billing note must be 150 characters or fewer.';

    $utilities = [];
    $policies = (array) ($post['billing_policy'] ?? []);
    foreach ((array) ($post['utilities'] ?? []) as $id) {
        if (is_scalar($id) && isset($allowedUtilities[(int) $id])) {
            $policy = is_string($policies[(int) $id] ?? null) ? trim($policies[(int) $id]) : '';
            if (mb_strlen($policy) > 150) {
                $errors[$policyTooLong] = $policyTooLong;
            }
            $utilities[(int) $id] = $policy;
        }
    }

    $newAmenities = [];
    foreach ((array) ($post['new_amenity_name'] ?? []) as $name) {
        $name = normalise_lookup_name(is_string($name) ? $name : '');
        if ($name === '') {
            continue;
        }
        if ($problem = lookup_name_problem('amenity', $name)) {
            $errors[] = $problem;
            continue;
        }
        $newAmenities[mb_strtolower($name)] = $name;
    }

    $newUtilities = [];
    $newPolicies = (array) ($post['new_utility_policy'] ?? []);
    foreach ((array) ($post['new_utility_name'] ?? []) as $i => $name) {
        $name = normalise_lookup_name(is_string($name) ? $name : '');
        if ($name === '') {
            continue;
        }
        if ($problem = lookup_name_problem('utility', $name)) {
            $errors[] = $problem;
            continue;
        }
        $policy = is_string($newPolicies[$i] ?? null) ? trim($newPolicies[$i]) : '';
        if (mb_strlen($policy) > 150) {
            $errors[$policyTooLong] = $policyTooLong;
        }
        $newUtilities[mb_strtolower($name)] = ['name' => $name, 'policy' => $policy];
    }

    return [
        'amenity_ids' => array_values($amenityIds),
        'utilities' => $utilities,
        'new_amenities' => array_values($newAmenities),
        'new_utilities' => array_values($newUtilities),
        'errors' => array_values($errors),
    ];
}

/** Save a listing's amenities and utilities, creating any new ones typed. $replace clears the old ones first. */

function save_listing_lookups($houseId, $landlordId, array $lookups, $replace)
{
    global $pdo;
    $houseId = (int) $houseId;

    $amenityIds = $lookups['amenity_ids'];
    foreach ($lookups['new_amenities'] as $name) {
        [$id] = create_lookup('amenity', $name, $landlordId, true);
        if ($id) {
            $amenityIds[] = $id;
        }
    }

    $utilities = $lookups['utilities'];
    foreach ($lookups['new_utilities'] as $new) {
        [$id] = create_lookup('utility', $new['name'], $landlordId, true);
        if ($id && !isset($utilities[$id])) {
            $utilities[$id] = $new['policy'];
        }
    }

    if ($replace) {
        $pdo->prepare('DELETE FROM boarding_house_amenities WHERE boarding_house_id = ?')->execute([$houseId]);
        $pdo->prepare('DELETE FROM boarding_house_utilities WHERE boarding_house_id = ?')->execute([$houseId]);
    }

    $insAmen = $pdo->prepare(
        'INSERT IGNORE INTO boarding_house_amenities (boarding_house_id, amenity_id) VALUES (?, ?)'
    );
    foreach (array_unique($amenityIds) as $id) {
        $insAmen->execute([$houseId, $id]);
    }

    $insUtil = $pdo->prepare(
        'INSERT IGNORE INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy) VALUES (?, ?, ?)'
    );
    foreach ($utilities as $id => $policy) {
        $insUtil->execute([$houseId, $id, $policy !== '' ? $policy : 'Included in Rent']);
    }
}

/** Message shown when a checklist is empty because its table is missing. */

function lookup_unavailable_notice($what, $table)
{
    return '<div class="alert alert-warning py-2 px-3 small mb-3">'
        . '<i class="fas fa-exclamation-triangle mr-1"></i> '
        . 'No ' . h($what) . ' are available to choose from. The <code>' . h($table)
        . '</code> table is empty or could not be read &mdash; import <code>database/roomease.sql</code>'
        . ' and check the PHP error log.'
        . '</div>';
}
