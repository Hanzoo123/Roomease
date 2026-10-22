<?php
/**
 * Request a room: a boarder asks the landlord to hold one room from a move-in
 * date. A request, not a payment; the landlord accepts or declines it on
 * landlord/reservations.php. ?room=ID
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/components/icons.php';

$roomId = (int) ($_GET['room'] ?? $_POST['room_id'] ?? 0);

// A guest logs in first and comes straight back here.
if (!is_logged_in()) {
    redirect('auth/login.php?next=' . rawurlencode('boarder/reserve.php?room=' . $roomId));
}
if (current_role() !== 'boarder') {
    flash_set('Only boarder accounts can request a room.', 'error');
    redirect('index.php');
}
require_login('boarder');

$boarderId = (int) $_SESSION['user_id'];
$room = reservable_room($roomId);
if (!$room) {
    flash_set('That room is no longer on RoomEase, so it cannot be requested.', 'error');
    redirect('boarder/browse.php');
}
$listingUrl = 'boarder/view_listing.php?id=' . (int) $room['boarding_house_id'];
if (room_state($room)['key'] !== 'available') {
    flash_set($room['name'] . ' is full or not taking tenants right now, so it cannot be requested.', 'error');
    redirect($listingUrl . '#rooms');
}

[$earliest, $latest] = reservation_date_range();
$old = ['move_in_date' => '', 'message' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['move_in_date'] = is_string($_POST['move_in_date'] ?? null) ? trim($_POST['move_in_date']) : '';
    $old['message'] = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';

    $errors = reservation_errors($room, $boarderId, $old['move_in_date'], $old['message']);

    if (!$errors) {
        $reservationId = create_reservation($room, $boarderId, $old['move_in_date'], $old['message']);
        audit_log('reservation_request', $room['boarding_house_id'], $room['house_name'], reservation_log_detail([
            'room_name' => $room['name'],
            'boarder_name' => $_SESSION['full_name'] ?? 'A boarder',
            'move_in_date' => $old['move_in_date'],
        ]));
        flash_set('Request sent. ' . $room['landlord_name'] . ' will accept or decline it, and the answer shows here.', 'success');
        // Straight to the new request, which is marked for a moment.
        redirect('boarder/reservations.php#request-' . $reservationId);
    }
}

$capacity = max(1, (int) $room['capacity']);
$slotsLeft = room_state($room)['slots_left'];

$pageTitle = 'Request ' . $room['name'];
$band = [
    'back' => ['href' => base_url($listingUrl . '#rooms'), 'label' => $room['house_name']],
    'title' => 'Request ' . $room['name'],
    'lede' => 'Ask ' . $room['landlord_name'] . ' to hold this room for you. They accept or decline, '
        . 'and you see the answer on My Reservations.',
];
require __DIR__ . '/../includes/layouts/header.php';
?>

<div class="reserve-layout">
  <div class="panel panel-pad">
    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $e)
          echo h($e) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= base_url('boarder/reserve.php') ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="room_id" value="<?= (int) $room['room_id'] ?>">

      <label for="move_in_date">When do you want to move in?</label>
      <input type="date" id="move_in_date" name="move_in_date" value="<?= h($old['move_in_date']) ?>"
        min="<?= h($earliest) ?>" max="<?= h($latest) ?>" required aria-describedby="move-in-hint">
      <p class="field-hint" id="move-in-hint">Any day from today up to <?= RESERVATION_DAYS_AHEAD ?> days ahead.</p>

      <label for="message">A note to the landlord <span class="label-optional">(optional)</span></label>
      <textarea id="message" name="message" rows="4" maxlength="500"
        placeholder="e.g. I'm a first-year student at VSU and can visit the room this weekend."><?= h($old['message']) ?></textarea>

      <p class="reserve-note">
        <?= icon('info', 16) ?>
        <span>This is a request, not a payment. RoomEase does not take money. Agree any reservation fee or
          deposit with the landlord directly.</span>
      </p>

      <div class="reserve-actions">
        <button type="submit" class="btn btn-primary">Send request</button>
        <a href="<?= base_url($listingUrl . '#rooms') ?>" class="btn btn-ghost">Back to the listing</a>
      </div>
    </form>
  </div>

  <aside class="panel panel-pad reserve-summary" aria-label="The room you are requesting">
    <p class="reserve-summary-house"><?= h($room['house_name']) ?></p>
    <h2><?= h($room['name']) ?></h2>
    <ul class="quick-list">
      <li><?= icon('door', 16) ?><span>Type</span><strong><?= h($room['room_type_name']) ?></strong></li>
      <li><?= icon('users', 16) ?><span>Fits</span><strong><?= $capacity ?> <?= $capacity === 1 ? 'person' : 'people' ?></strong></li>
      <li><?= icon('card', 16) ?><span>Rent</span><strong><?= peso_round($room['monthly_rent']) ?> <?= rent_unit(rent_per($capacity)) ?></strong></li>
      <li><?= icon('check', 16) ?><span>Free now</span><strong><?= (int) $slotsLeft ?> <?= $slotsLeft === 1 ? 'slot' : 'slots' ?></strong></li>
    </ul>
    <p class="reserve-summary-foot">Landlord: <strong><?= h($room['landlord_name']) ?></strong></p>
  </aside>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
