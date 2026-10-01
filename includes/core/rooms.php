<?php
/**
 * Rooms inside a listing: reading them from a form and saving them.
 */

/**
 * A landlord's own room, joined to its listing, or false. Ownership is part of
 * the query, so a room id from another landlord's listing simply is not found.
 */

function find_landlord_room($roomId, $landlordId)
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT r.*, bh.name AS house_name, bh.landlord_id, bh.moderation_status AS house_moderation
           FROM rooms r
           JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
          WHERE r.room_id = ? AND bh.landlord_id = ? AND bh.deleted_at IS NULL'
    );
    $stmt->execute([(int) $roomId, (int) $landlordId]);
    return $stmt->fetch();
}

/** The fields of a room that has not been filled in yet. */

function blank_room($name = '')
{
    return ['name' => $name, 'room_type_id' => '', 'monthly_rent' => '', 'capacity' => '1',
        'slots_taken' => '0', 'is_open' => true, 'description' => ''];
}

/**
 * Read and check one room's fields as submitted, from the room page or from a
 * row of the Add Listing form. Returns [$room, $errors]: $room keeps what was
 * typed so the form can be shown again, and $errors are ready to display.
 * $label, such as "Room 2", starts each message when several rooms are
 * checked at once. Whether the name is already used is up to the caller,
 * since that depends on which listing the room belongs to.
 */

function room_from_input(array $input, array $roomTypes, $label = '')
{
    $text = function ($key) use ($input) {
        return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    };
    $room = [
        'name' => normalise_lookup_name($text('name')),
        'room_type_id' => $text('room_type_id'),
        'monthly_rent' => $text('monthly_rent'),
        'capacity' => $text('capacity'),
        'slots_taken' => $text('slots_taken'),
        'is_open' => ($input['is_open'] ?? '') === '1',
        'description' => $text('description'),
    ];
    $p = $label !== '' ? $label . ': ' : '';
    $errors = [];

    if ($room['name'] === '') {
        $errors[] = $p . 'Give the room a name, such as "Room 1" or "2nd floor front".';
    } elseif (mb_strlen($room['name']) > 60) {
        $errors[] = $p . 'Room names must be 60 characters or fewer.';
    }
    if (!isset($roomTypes[(int) $room['room_type_id']])) {
        $errors[] = $p . 'Choose a room type from the list.';
    }
    if (!is_numeric($room['monthly_rent']) || (float) $room['monthly_rent'] < 0 || (float) $room['monthly_rent'] > 1000000) {
        $errors[] = $p . 'Enter a valid monthly rent.';
    }
    if (!ctype_digit($room['capacity']) || (int) $room['capacity'] < 1 || (int) $room['capacity'] > 100) {
        $errors[] = $p . 'Capacity must be between 1 and 100 people.';
    }
    if (!ctype_digit($room['slots_taken'])) {
        $errors[] = $p . 'Slots taken must be a whole number, 0 if the room is empty.';
    } elseif (ctype_digit($room['capacity']) && (int) $room['slots_taken'] > (int) $room['capacity']) {
        $errors[] = $p . 'Slots taken cannot be more than the room\'s capacity.';
    }
    if (mb_strlen($room['description']) > 500) {
        $errors[] = $p . 'The room description must be 500 characters or fewer.';
    }

    return [$room, $errors];
}

/** The values room_from_input() checked, in the order insert and update use. */

function room_values(array $room)
{
    return [
        $room['name'], (int) $room['room_type_id'], $room['monthly_rent'], (int) $room['capacity'],
        (int) $room['slots_taken'], $room['is_open'] ? 1 : 0,
        $room['description'] !== '' ? $room['description'] : null,
    ];
}

/** Add a checked room to a listing. Returns the new room_id. */

function insert_room($houseId, array $room)
{
    global $pdo;
    $pdo->prepare(
        'INSERT INTO rooms (name, room_type_id, monthly_rent, capacity, slots_taken, is_open, description, boarding_house_id,
                            created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(array_merge(room_values($room), [(int) $houseId, current_user_id(), current_user_id()]));
    return (int) $pdo->lastInsertId();
}

/**
 * Store the photos uploaded in $fileField as a room's photos. The first one
 * becomes the room's main photo when it has none. Returns how many were
 * saved; throws RuntimeException, from handle_photo_uploads(), if a file is
 * refused.
 */

function attach_room_photos($houseId, $roomId, $fileField)
{
    global $pdo;
    $paths = handle_photo_uploads($fileField, (int) $houseId);
    if (!$paths) {
        return 0;
    }

    $hasMain = $pdo->prepare('SELECT COUNT(*) FROM images WHERE room_id = ? AND is_primary = 1');
    $hasMain->execute([(int) $roomId]);
    $needsMain = (int) $hasMain->fetchColumn() === 0;

    $insImg = $pdo->prepare(
        'INSERT INTO images (boarding_house_id, room_id, image_path, is_primary, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($paths as $i => $path) {
        $insImg->execute([(int) $houseId, (int) $roomId, $path, ($needsMain && $i === 0) ? 1 : 0,
            current_user_id(), current_user_id()]);
    }
    return count($paths);
}
