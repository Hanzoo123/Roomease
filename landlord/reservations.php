<?php
/**
 * Reservations: boarders' requests for this landlord's rooms. Pending ones are
 * accepted (taking one of the room's slots) or declined, with an optional
 * reason. ?status=pending, accepted, declined or cancelled shows only those.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_login('landlord');

$landlordId = (int) $_SESSION['user_id'];

$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['pending', 'accepted', 'declined', 'cancelled'], true)) {
    $statusFilter = '';
}

$reservations = landlord_reservations($landlordId, $statusFilter);
$today = substr(db_now(), 0, 10);
$counts = landlord_reservation_counts($landlordId);

// Each filter: its button and what the table says when nothing has that status.
$filters = [
    ''          => ['label' => 'All'],
    'pending'   => ['label' => 'Pending',   'empty' => ['Nothing waiting for an answer', 'New requests from boarders appear here.', 'fa-inbox']],
    'accepted'  => ['label' => 'Accepted',  'empty' => ['No accepted requests yet', 'A request you accept takes one of the room\'s slots.', 'fa-check-circle']],
    'declined'  => ['label' => 'Declined',  'empty' => ['No declined requests', 'Requests you decline are kept here, with your reason.', 'fa-times-circle']],
    'cancelled' => ['label' => 'Cancelled', 'empty' => ['No cancelled requests', 'A boarder can withdraw a request until you answer it.', 'fa-ban']],
];

$filterButtons = '<div class="btn-group flex-wrap" role="group" aria-label="Filter requests by status">';
foreach ($filters as $filterStatus => $filter) {
    $count = $filterStatus === '' ? $counts['total'] : $counts[$filterStatus];
    $filterButtons .= '<a href="' . base_url('landlord/reservations.php' . ($filterStatus !== '' ? '?status=' . $filterStatus : ''))
        . '" class="btn btn-sm btn-outline-primary' . ($filterStatus === $statusFilter ? ' active' : '') . '">'
        . h($filter['label']) . ' <span class="badge badge-light ml-1">' . (int) $count . '</span></a>';
}
$filterButtons .= '</div>';

$pageTitle = 'Reservations';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <?php panel_page_header('Reservations', [
    'subtitle' => 'Boarders asking for your rooms. Accepting one takes a slot in that room; '
      . 'arrange the move-in and any fee with the boarder directly.',
  ]); ?>

  <section class="content">
    <div class="container-fluid">
      <div class="card card-primary card-outline shadow-sm">
        <?php panel_card_header('Room requests', 'Pending requests first, then the newest.', $filterButtons); ?>

        <div class="card-body<?= $reservations ? ' p-0 table-responsive' : '' ?>">
          <?php if (!$reservations && $statusFilter !== '' && $counts['total'] > 0): ?>
            <?php [$emptyTitle, $emptyText, $emptyIcon] = $filters[$statusFilter]['empty']; ?>
            <?= re_empty($emptyTitle, $emptyText, $emptyIcon) ?>
          <?php elseif (!$reservations): ?>
            <?= re_empty(
              'No requests yet',
              'When a boarder asks for one of your available rooms, the request appears here for you to accept or decline.',
              'fa-inbox'
            ) ?>
          <?php else: ?>
            <table class="table table-hover mb-0 reservation-table">
              <thead>
                <tr>
                  <th>Boarder</th>
                  <th>Room</th>
                  <th>Move-in</th>
                  <th>Note</th>
                  <th>Status</th>
                  <th class="text-right">Answer</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reservations as $r): ?>
                  <?php
                  $status = reservation_status_info($r['status']);
                  $full = (int) $r['slots_taken'] >= (int) $r['capacity'];
                  $overdue = $r['status'] === 'pending' && $r['move_in_date'] < $today;
                  ?>
                  <?php /* The id lets the dashboard's Answer button open this very request. */ ?>
                  <tr id="request-<?= (int) $r['reservation_id'] ?>">
                    <td>
                      <strong><?= h($r['boarder_name']) ?></strong>
                      <?php if (!empty($r['boarder_phone'])): ?>
                        <a class="d-block small" href="tel:<?= h(preg_replace('/[^\d+]/', '', $r['boarder_phone'])) ?>"><?= h($r['boarder_phone']) ?></a>
                      <?php endif; ?>
                      <a class="d-block small text-muted" href="mailto:<?= h($r['boarder_email']) ?>"><?= h($r['boarder_email']) ?></a>
                    </td>
                    <td>
                      <strong><?= h($r['room_name']) ?></strong>
                      <a class="d-block small" href="<?= base_url('landlord/edit_listing.php?id=' . (int) $r['boarding_house_id']) ?>#rooms"><?= h($r['house_name']) ?></a>
                      <small class="text-muted d-block"><?= (int) $r['slots_taken'] ?> of <?= (int) $r['capacity'] ?> slots taken</small>
                    </td>
                    <td class="text-nowrap">
                      <?= h(date('M j, Y', strtotime($r['move_in_date']))) ?>
                      <?php if ($overdue): ?>
                        <small class="re-overdue d-block">Date has passed</small>
                      <?php endif; ?>
                    </td>
                    <td class="reservation-note-cell"><?= $r['message'] !== null ? h($r['message']) : '<span class="text-muted">None</span>' ?></td>
                    <td>
                      <span class="badge <?= h($status['badge']) ?>"><?= h($status['label']) ?></span>
                      <small class="text-muted d-block">Asked <?= h(time_ago($r['created_at'])) ?></small>
                      <?php if ($r['status'] === 'declined' && $r['landlord_note'] !== null): ?>
                        <small class="d-block">Your reason: <?= h($r['landlord_note']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td class="text-right text-nowrap">
                      <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" action="<?= base_url('landlord/reservation_action.php') ?>" class="d-inline js-confirm"
                          data-confirm="Accept <?= h($r['boarder_name']) ?>'s request for <?= h($r['room_name']) ?>? It takes one of the room's slots.">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="accept">
                          <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                          <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
                          <button type="submit" class="btn btn-sm btn-success"<?= $full ? ' disabled title="The room is full. Free a slot first, or decline."' : '' ?>>
                            <i class="fas fa-check mr-1"></i> Accept
                          </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-toggle="modal" data-target="#declineModal"
                          data-id="<?= (int) $r['reservation_id'] ?>" data-who="<?= h($r['boarder_name'] . ' · ' . $r['room_name']) ?>">
                          Decline
                        </button>
                      <?php else: ?>
                        <span class="text-muted small"><?= $r['decided_at'] ? h(time_ago($r['decided_at'])) : '' ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<div class="modal fade" id="declineModal" tabindex="-1" role="dialog" aria-labelledby="declineModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" action="<?= base_url('landlord/reservation_action.php') ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="declineModalLabel">Decline request</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="decline">
          <input type="hidden" name="reservation_id" value="" data-decline-id>
          <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
          <p class="mb-3">Declining <strong data-decline-who></strong>. The room's slots do not change.</p>
          <div class="form-group mb-0">
            <label for="landlord_note">Reason <span class="text-muted font-weight-normal">(optional)</span></label>
            <textarea class="form-control" id="landlord_note" name="landlord_note" rows="3" maxlength="500"
              placeholder="e.g. The room was taken by someone who visited first."></textarea>
            <small class="form-text text-muted">The boarder sees this on My Reservations.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Decline request</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  // The Decline button tells the dialog which request it is for.
  document.addEventListener('DOMContentLoaded', function () {
    window.jQuery('#declineModal').on('show.bs.modal', function (event) {
      var button = event.relatedTarget;
      this.querySelector('[data-decline-id]').value = button.getAttribute('data-id');
      this.querySelector('[data-decline-who]').textContent = button.getAttribute('data-who');
      this.querySelector('#landlord_note').value = '';
    });
  });
</script>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
