<?php
/**
 * Quick actions on one of a landlord's rooms, from the Rooms card:
 *
 *   slots        delta=1 or delta=-1: a tenant moved in or out
 *   toggle_open  close the room to new tenants, or reopen it
 *   delete       remove the room and its photos
 *
 * The buttons post here with fetch() and get JSON back, so the table updates
 * without a reload. Without JavaScript they are ordinary form posts that
 * redirect back to the listing.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function room_action_reply($status, array $payload, $houseId = null)
{
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
    flash_set($payload['message'] ?? 'Done.', empty($payload['ok']) ? 'error' : 'success');
    redirect($houseId ? 'landlord/edit_listing.php?id=' . (int) $houseId . '#rooms' : 'landlord/dashboard.php');
}

if (!is_logged_in() || current_role() !== 'landlord') {
    if ($wantsJson) {
        room_action_reply(401, ['ok' => false, 'reload' => true, 'message' => 'Please log in again.']);
    }
    require_login('landlord');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
if ($wantsJson && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    room_action_reply(403, ['ok' => false, 'reload' => true,
        'message' => 'Your session expired. Please refresh the page and try again.']);
}
verify_csrf();

$landlordId = (int) $_SESSION['user_id'];
$room = find_landlord_room($_POST['room_id'] ?? 0, $landlordId);
if (!$room) {
    room_action_reply(404, ['ok' => false, 'message' => 'Room not found, or it is not one of yours.']);
}
$roomId = (int) $room['room_id'];
$houseId = (int) $room['boarding_house_id'];
$action = $_POST['action'] ?? '';

/** The listing's room counts after the change, for the card header. */
$houseSummary = function () use ($pdo, $houseId) {
    $stmt = $pdo->prepare(
        'SELECT ' . ROOM_SUMMARY_COLUMNS . ' FROM boarding_houses bh ' . room_summary_join()
        . ' WHERE bh.boarding_house_id = ?'
    );
    $stmt->execute([$houseId]);
    return listing_availability($stmt->fetch() ?: [])['summary'];
};

/** The room as the table shows it after the change. */
$roomPayload = function () use ($pdo, $roomId) {
    $stmt = $pdo->prepare('SELECT room_id, name, capacity, slots_taken, is_open FROM rooms WHERE room_id = ?');
    $stmt->execute([$roomId]);
    $fresh = $stmt->fetch();
    $state = room_state($fresh);
    return [
        'room_id' => (int) $fresh['room_id'],
        'slots_taken' => (int) $fresh['slots_taken'],
        'capacity' => (int) $fresh['capacity'],
        'is_open' => (bool) $fresh['is_open'],
        'state_label' => $state['label'],
        'state_badge' => $state['badge'],
    ];
};

if ($action === 'slots') {
    $delta = (int) ($_POST['delta'] ?? 0);
    if ($delta !== 1 && $delta !== -1) {
        room_action_reply(400, ['ok' => false, 'message' => 'Unknown change.'], $houseId);
    }
    // Clamped in the statement itself, so two quick taps can never push the
    // count below zero or past the room's capacity.
    $pdo->prepare(
        'UPDATE rooms SET slots_taken = LEAST(capacity, GREATEST(0, slots_taken + ?)) WHERE room_id = ?'
    )->execute([$delta, $roomId]);

    $fresh = $roomPayload();
    room_action_reply(200, [
        'ok' => true,
        'message' => $room['name'] . ': ' . $fresh['slots_taken'] . ' of ' . $fresh['capacity'] . ' slots taken.',
        'room' => $fresh,
        'summary' => $houseSummary(),
    ], $houseId);
}

if ($action === 'toggle_open') {
    $pdo->prepare('UPDATE rooms SET is_open = 1 - is_open WHERE room_id = ?')->execute([$roomId]);
    $fresh = $roomPayload();
    room_action_reply(200, [
        'ok' => true,
        'message' => $room['name'] . ($fresh['is_open'] ? ' is open to tenants again.' : ' is closed to new tenants.'),
        'room' => $fresh,
        'summary' => $houseSummary(),
    ], $houseId);
}

if ($action === 'delete') {
    // Photo files first, then the row; the images rows go with it by cascade.
    $photos = $pdo->prepare('SELECT image_path FROM images WHERE room_id = ?');
    $photos->execute([$roomId]);
    $uploadRoot = realpath(__DIR__ . '/../assets/uploads');
    foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $target = realpath(__DIR__ . '/../' . $path);
        if ($uploadRoot && $target && strpos($target, $uploadRoot) === 0 && is_file($target)) {
            @unlink($target);
        }
    }
    $pdo->prepare('DELETE FROM rooms WHERE room_id = ?')->execute([$roomId]);

    $remaining = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE boarding_house_id = ?');
    $remaining->execute([$houseId]);
    $left = (int) $remaining->fetchColumn();

    room_action_reply(200, [
        'ok' => true,
        'deleted' => true,
        'reload' => $left === 0,
        'message' => $room['name'] . ' was deleted.'
            . ($left === 0 ? ' This listing has no rooms now, so boarders cannot see it until you add one.' : ''),
        'summary' => $houseSummary(),
    ], $houseId);
}

room_action_reply(400, ['ok' => false, 'message' => 'Unknown room action.'], $houseId);
