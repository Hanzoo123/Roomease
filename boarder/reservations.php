<?php
/** My Reservations: the rooms this boarder asked for, and each landlord's answer. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/components/icons.php';
require_login('boarder');

$reservations = boarder_reservations($_SESSION['user_id']);

$pageTitle = 'My Reservations';
$band = [
  'title' => 'My reservations',
  'lede' => 'The rooms you asked for and each landlord\'s answer. You can cancel a request until the landlord answers it.',
];
require __DIR__ . '/../includes/layouts/header.php';
?>

<?php if (!$reservations): ?>
  <p class="rooms-empty">
    You haven't asked for a room yet.
    <a href="<?= base_url('boarder/browse.php') ?>">Browse rooms</a> and tap "Request this room" on one that is available.
  </p>
<?php else: ?>
  <ul class="reservation-list">
    <?php foreach ($reservations as $r): ?>
      <?php
      $status = reservation_status_info($r['status']);
      // Pulled from the site after the request: still listed, but not linked.
      $houseLive = $r['house_deleted_at'] === null && $r['moderation_status'] === 'approved';
      ?>
      <li class="panel reservation-item">
        <div class="reservation-main">
          <p class="reservation-house">
            <?php if ($houseLive): ?>
              <a href="<?= base_url('boarder/view_listing.php?id=' . (int) $r['boarding_house_id']) ?>"><?= h($r['house_name']) ?></a>
            <?php else: ?>
              <?= h($r['house_name']) ?> <span class="reservation-gone">(no longer on RoomEase)</span>
            <?php endif; ?>
          </p>
          <h2><?= h($r['room_name']) ?></h2>
          <p class="reservation-meta">
            <?= h($r['room_type_name']) ?> &middot; <?= peso_round($r['monthly_rent']) ?> <?= rent_unit(rent_per($r['capacity'])) ?>
            &middot; moving in <?= h(date('M j, Y', strtotime($r['move_in_date']))) ?>
          </p>
          <?php if ($r['message'] !== null): ?>
            <p class="reservation-note"><span>Your note:</span> <?= h($r['message']) ?></p>
          <?php endif; ?>

          <?php if ($r['status'] === 'accepted'): ?>
            <p class="reservation-answer reservation-answer--yes">
              <?= icon('check', 16) ?>
              <span><?= h($r['landlord_name']) ?> accepted. Contact them to arrange your move-in<?php if (!empty($r['landlord_phone'])): ?>:
                <a href="tel:<?= h(preg_replace('/[^\d+]/', '', $r['landlord_phone'])) ?>"><?= h($r['landlord_phone']) ?></a><?php endif; ?>.</span>
            </p>
          <?php elseif ($r['status'] === 'declined'): ?>
            <p class="reservation-answer reservation-answer--no">
              <?= icon('x', 16) ?>
              <span><?= h($r['landlord_name']) ?> declined<?= $r['landlord_note'] !== null ? ': ' . h($r['landlord_note']) : '.' ?></span>
            </p>
          <?php endif; ?>
        </div>

        <div class="reservation-side">
          <span class="pill <?= h($status['pill']) ?>"><?= h($status['label']) ?></span>
          <small>Asked <?= h(time_ago($r['created_at'])) ?></small>
          <?php if ($r['status'] === 'pending'): ?>
            <form method="post" action="<?= base_url('boarder/reservation_action.php') ?>" data-confirm-cancel
              data-confirm="Cancel your request for <?= h($r['room_name']) ?> at <?= h($r['house_name']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
              <input type="hidden" name="action" value="cancel">
              <button type="submit" class="btn btn-sm btn-danger">Cancel request</button>
            </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <p class="list-foot">
    <?= count($reservations) ?> <?= count($reservations) === 1 ? 'request' : 'requests' ?>
    &middot; <a href="<?= base_url('boarder/browse.php') ?>">Browse more rooms</a>
  </p>

  <script>
    // Cancelling asks first. The question comes from data-confirm, so a quote
    // in a listing's name cannot break the script.
    document.querySelectorAll('form[data-confirm-cancel]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
      });
    });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
