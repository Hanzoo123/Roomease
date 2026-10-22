<?php
/**
 * Reservations: a boarder asks for one room, and its landlord accepts or
 * declines. A request, not a payment. Accepting takes one of the room's slots.
 *
 *   pending  -> accepted   the landlord said yes (rooms.slots_taken + 1)
 *            -> declined   the landlord said no, with an optional reason
 *            -> cancelled  the boarder withdrew it
 *
 * Used by: boarder/reserve.php, boarder/reservations.php,
 * boarder/reservation_action.php, boarder/view_listing.php,
 * landlord/reservations.php, landlord/reservation_action.php,
 * landlord/dashboard.php and the landlord sidebar (includes/layouts/panel.php).
 */

/** Pending requests one boarder may have at a time. */

const MAX_PENDING_RESERVATIONS = 3;

/** How far ahead a move-in date may be, in days. */

const RESERVATION_DAYS_AHEAD = 90;

/** Label, public-site pill and panel badge for each status. */

function reservation_status_info($status)
{
    $info = [
        'pending'   => ['label' => 'Pending',   'pill' => 'pill--pending',     'badge' => 'badge-warning'],
        'accepted'  => ['label' => 'Accepted',  'pill' => 'pill--available',   'badge' => 'badge-success'],
        'declined'  => ['label' => 'Declined',  'pill' => 'pill--rejected',    'badge' => 'badge-danger'],
        'cancelled' => ['label' => 'Cancelled', 'pill' => 'pill--unavailable', 'badge' => 'badge-secondary'],
    ];
    return $info[$status] ?? ['label' => ucfirst((string) $status), 'pill' => 'pill--unavailable', 'badge' => 'badge-secondary'];
}

/** Logged when the reservations table is missing, so the fix is in the log. */

function reservations_table_missing(PDOException $e)
{
    error_log('RoomEase: reservations failed - ' . $e->getMessage()
        . '. If the table is missing, run database/add_reservations.sql.');
}

/**
 * A room a boarder may request: in a public listing (approved, not removed,
 * landlord active), with its listing and landlord. False otherwise.
 */

function reservable_room($roomId)
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT r.*, rt.room_type_name, bh.boarding_house_id, bh.name AS house_name, bh.landlord_id,
                ' . account_name_sql('lu') . ' AS landlord_name
           FROM rooms r
           JOIN room_types rt ON rt.room_type_id = r.room_type_id
           JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
           ' . LIVE_LANDLORD_JOIN . '
          WHERE r.room_id = ? AND ' . LIVE_STATUS_WHERE
    );
    $stmt->execute([(int) $roomId]);
    return $stmt->fetch();
}

/** The earliest and latest move-in dates allowed, as 'Y-m-d' (the database's today). */

function reservation_date_range()
{
    $today = substr(db_now(), 0, 10);
    return [$today, date('Y-m-d', strtotime($today . ' +' . RESERVATION_DAYS_AHEAD . ' days'))];
}

/**
 * What stops this boarder requesting this room, as error messages. Empty
 * means the request can be saved. $room comes from reservable_room().
 */

function reservation_errors(array $room, $boarderId, $moveIn, $message)
{
    global $pdo;
    $errors = [];

    if (room_state($room)['key'] !== 'available') {
        $errors[] = 'This room is full or not taking tenants right now, so it cannot be requested.';
    }

    [$earliest, $latest] = reservation_date_range();
    $date = DateTime::createFromFormat('!Y-m-d', (string) $moveIn);
    if (!$date || $date->format('Y-m-d') !== $moveIn) {
        $errors[] = 'Choose the date you want to move in.';
    } elseif ($moveIn < $earliest) {
        $errors[] = 'The move-in date cannot be in the past.';
    } elseif ($moveIn > $latest) {
        $errors[] = 'Choose a move-in date within the next ' . RESERVATION_DAYS_AHEAD . ' days.';
    }

    if ($problem = too_long($message, 500, 'Your note')) {
        $errors[] = $problem;
    }

    $same = $pdo->prepare(
        "SELECT status FROM reservations
          WHERE boarder_id = ? AND room_id = ? AND status IN ('pending', 'accepted') LIMIT 1"
    );
    $same->execute([(int) $boarderId, (int) $room['room_id']]);
    $existing = $same->fetchColumn();
    if ($existing === 'pending') {
        $errors[] = 'You already asked for this room. The landlord has not answered yet.';
    } elseif ($existing === 'accepted') {
        $errors[] = 'The landlord already accepted your request for this room.';
    }

    $pending = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE boarder_id = ? AND status = 'pending'");
    $pending->execute([(int) $boarderId]);
    if ($existing === false && (int) $pending->fetchColumn() >= MAX_PENDING_RESERVATIONS) {
        $errors[] = 'You already have ' . MAX_PENDING_RESERVATIONS . ' requests waiting for an answer. '
            . 'Cancel one, or wait for a landlord to answer, before asking for another room.';
    }

    return $errors;
}

/** Save a checked request. Returns the new reservation_id. */

function create_reservation(array $room, $boarderId, $moveIn, $message)
{
    global $pdo;
    $pdo->prepare(
        'INSERT INTO reservations (room_id, boarder_id, move_in_date, message, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        (int) $room['room_id'], (int) $boarderId, $moveIn,
        trim((string) $message) !== '' ? trim((string) $message) : null,
        (int) $boarderId, (int) $boarderId,
    ]);
    return (int) $pdo->lastInsertId();
}

/** "Room 2 · Juan Dela Cruz · moving in Nov 1, 2026", for the audit log. */

function reservation_log_detail(array $r)
{
    return $r['room_name'] . ' · ' . $r['boarder_name'] . ' · moving in ' . date('M j, Y', strtotime($r['move_in_date']));
}

/** Columns shared by the boarder's and the landlord's lists. */

const RESERVATION_COLUMNS = 'res.*, r.name AS room_name, r.monthly_rent, r.capacity, r.slots_taken,
        rt.room_type_name, bh.boarding_house_id, bh.name AS house_name, bh.landlord_id,
        bh.moderation_status, bh.deleted_at AS house_deleted_at';

const RESERVATION_JOINS = 'JOIN rooms r ON r.room_id = res.room_id
       JOIN room_types rt ON rt.room_type_id = r.room_type_id
       JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id';

/** A boarder's requests, newest first, with the room, listing and landlord's contact. */

function boarder_reservations($boarderId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            'SELECT ' . RESERVATION_COLUMNS . ',
                    ' . account_name_sql('lu') . ' AS landlord_name, lu.phone_number AS landlord_phone
               FROM reservations res
               ' . RESERVATION_JOINS . '
               JOIN users lu ON lu.user_id = bh.landlord_id
              WHERE res.boarder_id = ?
              ORDER BY res.created_at DESC, res.reservation_id DESC'
        );
        $stmt->execute([(int) $boarderId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        reservations_table_missing($e);
        return [];
    }
}

/** This boarder's pending or accepted requests in one listing, as room_id => status. */

function boarder_room_reservations($boarderId, $houseId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT res.room_id, res.status
               FROM reservations res
               JOIN rooms r ON r.room_id = res.room_id
              WHERE res.boarder_id = ? AND r.boarding_house_id = ? AND res.status IN ('pending', 'accepted')"
        );
        $stmt->execute([(int) $boarderId, (int) $houseId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        reservations_table_missing($e);
        return [];
    }
}

/**
 * Requests for a landlord's rooms, newest first, with the boarder's contact.
 * $status ('pending', 'accepted', 'declined' or 'cancelled') keeps only that one.
 * Requests in a deleted listing are left out.
 */

function landlord_reservations($landlordId, $status = '', $limit = 0)
{
    global $pdo;
    $params = [(int) $landlordId];
    $statusWhere = '';
    if ($status !== '') {
        $statusWhere = ' AND res.status = ?';
        $params[] = $status;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT ' . RESERVATION_COLUMNS . ',
                    ' . account_name_sql('bu') . ' AS boarder_name, bu.phone_number AS boarder_phone,
                    bu.email AS boarder_email, bu.avatar_path AS boarder_avatar
               FROM reservations res
               ' . RESERVATION_JOINS . '
               JOIN users bu ON bu.user_id = res.boarder_id
              WHERE bh.landlord_id = ? AND bh.deleted_at IS NULL' . $statusWhere . '
              ORDER BY res.status = \'pending\' DESC, res.created_at DESC, res.reservation_id DESC'
            . ($limit > 0 ? ' LIMIT ' . (int) $limit : '')
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        reservations_table_missing($e);
        return [];
    }
}

/** How many requests a landlord has in each status, plus total. For the filter and the sidebar. */

function landlord_reservation_counts($landlordId)
{
    global $pdo;
    $counts = ['total' => 0, 'pending' => 0, 'accepted' => 0, 'declined' => 0, 'cancelled' => 0];
    try {
        $stmt = $pdo->prepare(
            'SELECT res.status, COUNT(*)
               FROM reservations res
               JOIN rooms r ON r.room_id = res.room_id
               JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
              WHERE bh.landlord_id = ? AND bh.deleted_at IS NULL
              GROUP BY res.status'
        );
        $stmt->execute([(int) $landlordId]);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $n) {
            $counts[$status] = (int) $n;
            $counts['total'] += (int) $n;
        }
    } catch (PDOException $e) {
        reservations_table_missing($e);
    }
    return $counts;
}

/**
 * One of this landlord's requests, still pending, with what the log needs.
 * False if it is not theirs, is gone, or was already answered.
 */

function find_pending_landlord_reservation($reservationId, $landlordId, $lock = false)
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT res.*, r.name AS room_name, r.capacity, r.slots_taken, bh.boarding_house_id, bh.name AS house_name,
                " . account_name_sql('bu') . " AS boarder_name
           FROM reservations res
           JOIN rooms r ON r.room_id = res.room_id
           JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
           JOIN users bu ON bu.user_id = res.boarder_id
          WHERE res.reservation_id = ? AND bh.landlord_id = ? AND bh.deleted_at IS NULL
            AND res.status = 'pending'" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([(int) $reservationId, (int) $landlordId]);
    return $stmt->fetch();
}

/**
 * Accept a pending request: one more slot taken in the room, and the request
 * marked accepted, together or not at all. Returns [ok, message].
 */

function accept_reservation($reservationId, $landlordId)
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        // Locked, so two clicks (or two tabs) cannot accept it twice.
        $res = find_pending_landlord_reservation($reservationId, $landlordId, true);
        if (!$res) {
            $pdo->rollBack();
            return [false, 'That request was not found, or it was already answered.'];
        }

        // The slot is only taken while the room has one free.
        $slot = $pdo->prepare(
            'UPDATE rooms SET slots_taken = slots_taken + 1, updated_by = ? WHERE room_id = ? AND slots_taken < capacity'
        );
        $slot->execute([(int) $landlordId, (int) $res['room_id']]);
        if ($slot->rowCount() === 0) {
            $pdo->rollBack();
            return [false, $res['room_name'] . ' is full, so the request cannot be accepted. '
                . 'Free a slot on the listing\'s Rooms card first, or decline it.'];
        }

        $pdo->prepare(
            "UPDATE reservations SET status = 'accepted', decided_at = NOW(), updated_by = ?
              WHERE reservation_id = ? AND status = 'pending'"
        )->execute([(int) $landlordId, (int) $reservationId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $taken = (int) $res['slots_taken'] + 1;
    audit_log('reservation_accept', $res['boarding_house_id'], $res['house_name'],
        reservation_log_detail($res) . ' · ' . $taken . ' of ' . (int) $res['capacity'] . ' slots taken');
    return [true, 'You accepted ' . $res['boarder_name'] . '\'s request for ' . $res['room_name'] . '. '
        . $taken . ' of ' . (int) $res['capacity'] . ' slots are now taken.'];
}

/** Decline a pending request, with an optional reason the boarder sees. Returns [ok, message]. */

function decline_reservation($reservationId, $landlordId, $note)
{
    global $pdo;
    $note = trim((string) $note);
    if ($problem = too_long($note, 500, 'The reason')) {
        return [false, $problem];
    }
    $res = find_pending_landlord_reservation($reservationId, $landlordId);
    if (!$res) {
        return [false, 'That request was not found, or it was already answered.'];
    }

    $stmt = $pdo->prepare(
        "UPDATE reservations SET status = 'declined', landlord_note = ?, decided_at = NOW(), updated_by = ?
          WHERE reservation_id = ? AND status = 'pending'"
    );
    $stmt->execute([$note !== '' ? $note : null, (int) $landlordId, (int) $reservationId]);
    if ($stmt->rowCount() === 0) {
        return [false, 'That request was already answered.'];
    }

    audit_log('reservation_decline', $res['boarding_house_id'], $res['house_name'],
        reservation_log_detail($res) . ($note !== '' ? ' · Reason: ' . $note : ''));
    return [true, 'You declined ' . $res['boarder_name'] . '\'s request for ' . $res['room_name'] . '.'];
}

/** The boarder withdraws one of their own pending requests. Returns [ok, message]. */

function cancel_reservation($reservationId, $boarderId)
{
    global $pdo;
    $find = $pdo->prepare(
        "SELECT res.*, r.name AS room_name, bh.boarding_house_id, bh.name AS house_name,
                " . account_name_sql('bu') . " AS boarder_name
           FROM reservations res
           JOIN rooms r ON r.room_id = res.room_id
           JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
           JOIN users bu ON bu.user_id = res.boarder_id
          WHERE res.reservation_id = ? AND res.boarder_id = ? AND res.status = 'pending'"
    );
    $find->execute([(int) $reservationId, (int) $boarderId]);
    $res = $find->fetch();
    if (!$res) {
        return [false, 'That request was not found, or the landlord already answered it.'];
    }

    $stmt = $pdo->prepare(
        "UPDATE reservations SET status = 'cancelled', updated_by = ?
          WHERE reservation_id = ? AND boarder_id = ? AND status = 'pending'"
    );
    $stmt->execute([(int) $boarderId, (int) $reservationId, (int) $boarderId]);
    if ($stmt->rowCount() === 0) {
        return [false, 'The landlord answered that request a moment ago. Refresh to see their answer.'];
    }

    audit_log('reservation_cancel', $res['boarding_house_id'], $res['house_name'], reservation_log_detail($res));
    return [true, 'Your request for ' . $res['room_name'] . ' at ' . $res['house_name'] . ' was cancelled.'];
}
