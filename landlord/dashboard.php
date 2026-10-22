<?php
/** Landlord dashboard. Uses the same panel layout as admin (includes/layouts/panel.php). */
require __DIR__ . '/../includes/init.php';
require_login('landlord');

$landlordId = $_SESSION['user_id'];
$today = substr(db_now(), 0, 10);

// Summary figures for this landlord only.
$counts = landlord_listing_counts($landlordId);

$listings = landlord_listings($landlordId);
$saves = array_sum(array_column($listings, 'save_count'));
$beds = landlord_beds($landlordId);

// What needs doing, split in two: a listing an administrator sent back is
// waiting on the landlord, so it goes to the top with the room requests;
// a missing photo or map pin is worth doing but can wait.
$sentBack = [];
$todo = [];
foreach (landlord_todo($listings) as $item) {
  if ($item['reason'] !== null) {
    $sentBack[] = $item;
  } else {
    $todo[] = $item;
  }
}

// Room requests waiting for an answer, newest first.
$pendingRequests = landlord_reservations($landlordId, 'pending', 5);
$pendingRequestCount = landlord_reservation_counts($landlordId)['pending'];

// What an administrator decided about this landlord's listings lately,
// including any listing that was removed and so is no longer in the table.
// A rejection still waiting to be fixed is left out: Needs you now shows it,
// with its reason.
$stillRejected = [];
$roomCounts = [];
foreach ($listings as $l) {
  $roomCounts[(int) $l['boarding_house_id']] = (int) ($l['room_count'] ?? 0);
  if ($l['moderation_status'] === 'rejected') {
    $stillRejected[(int) $l['boarding_house_id']] = true;
  }
}
$decisions = array_filter(landlord_recent_decisions($landlordId), function ($d) use ($stillRejected) {
  return !($d['action'] === 'listing_reject' && isset($stillRejected[(int) $d['boarding_house_id']]));
});
$decisionWords = [
  'listing_approve' => ['Approved', 'badge-success', 'is approved and visible to boarders.'],
  'listing_reject'  => ['Needs changes', 'badge-danger', 'was not approved yet. Edit it and it goes back for review.'],
  'listing_remove'  => ['Removed', 'badge-danger', 'was removed from RoomEase by an administrator.'],
  'listing_restore' => ['Restored', 'badge-info', 'was restored and is back in your listings.'],
];

// The sentence under the title: what matters right now, in plain words,
// with the counts in bold. Every number is an integer, so the HTML is safe.
$plural = function ($n, $one, $many) {
  return '<strong>' . (int) $n . ' ' . ($n == 1 ? $one : $many) . '</strong>';
};
$waiting = [];
if ($pendingRequestCount > 0) {
  $waiting[] = $plural($pendingRequestCount, 'room request is', 'room requests are') . ' waiting for your answer';
}
if ($counts['rejected'] > 0) {
  $waiting[] = $plural($counts['rejected'], 'listing needs changes', 'listings need changes');
}
if ($counts['total'] === 0) {
  $status = 'Add your first boarding house, and an administrator will review it before boarders can see it.';
} elseif ($waiting) {
  $status = implode(', and ', $waiting) . '.';
} elseif ($counts['pending'] > 0) {
  $status = 'You&rsquo;re all caught up. ' . $plural($counts['pending'], 'listing is', 'listings are')
    . ' with an administrator for review.';
} elseif ($beds['capacity'] > 0) {
  $status = 'You&rsquo;re all caught up. <strong>' . (int) $beds['taken'] . ' of ' . (int) $beds['capacity']
    . ' beds</strong> in your open rooms are taken.';
} else {
  $status = 'You&rsquo;re all caught up.';
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<main class="content-wrapper" id="main" tabindex="-1">
  <?php panel_page_header('Dashboard', [
    'subtitleHtml' => $status,
    'actions' => '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-sm btn-primary">'
      . '<i class="fas fa-plus mr-1" aria-hidden="true"></i> Add listing</a>',
  ]); ?>

  <section class="content">
    <div class="container-fluid dash">

      <?php /* The figures first, a compact strip of six. Marigold means something
               is waiting right now; at zero the same tile turns to a pale wash.
               A landlord with no listings yet skips them: six zeros would
               push "Create your first listing" off a phone's first screen. */ ?>
      <?php if ($counts['total'] > 0): ?>
        <div class="stat-row stat-row--six stat-row--dash">
          <a class="stat stat--filled <?= $pendingRequestCount > 0 ? 'stat--attention' : 'stat--calm' ?>"
            href="<?= base_url('landlord/reservations.php' . ($pendingRequestCount > 0 ? '?status=pending' : '')) ?>">
            <i class="fas fa-calendar-check stat-icon" aria-hidden="true"></i>
            <span class="stat-value"><?= (int) $pendingRequestCount ?></span>
            <span class="stat-label">Room requests</span>
            <span class="stat-more">
              <?= $pendingRequestCount > 0 ? 'Waiting on you' : 'None waiting' ?>
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </span>
          </a>
          <?php /* Red only while there is something to change: a permanent red
                   nought would read as a fault. */ ?>
          <a class="stat stat--filled <?= (int) $counts['rejected'] > 0 ? 'stat--danger' : 'stat--terracotta' ?>" href="<?= base_url('landlord/listings.php?status=rejected') ?>">
            <i class="fas fa-pen stat-icon" aria-hidden="true"></i>
            <span class="stat-value"><?= (int) $counts['rejected'] ?></span>
            <span class="stat-label">Needs changes</span>
            <span class="stat-more">
              <?= (int) $counts['rejected'] > 0 ? 'See what to change' : 'Nothing sent back' ?>
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </span>
          </a>
          <a class="stat stat--filled <?= (int) $counts['pending'] > 0 ? 'stat--attention' : 'stat--calm' ?>" href="<?= base_url('landlord/listings.php?status=pending') ?>">
            <i class="fas fa-clock stat-icon" aria-hidden="true"></i>
            <span class="stat-value"><?= (int) $counts['pending'] ?></span>
            <span class="stat-label">Awaiting approval</span>
            <span class="stat-more">
              <?= (int) $counts['pending'] > 0 ? 'Under admin review' : 'Nothing waiting' ?>
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </span>
          </a>
          <a class="stat stat--filled stat--green" href="<?= base_url('landlord/listings.php?status=approved') ?>">
            <i class="fas fa-check-circle stat-icon" aria-hidden="true"></i>
            <?php /* Only what boarders can see: approved, with at least one room. */ ?>
            <span class="stat-value"><?= (int) $counts['live'] ?></span>
            <span class="stat-label">Visible to boarders</span>
            <span class="stat-more">
              <?= $counts['approved'] > $counts['live'] ? ($counts['approved'] - $counts['live']) . ' approved need a room' : 'Approved &amp; listed' ?>
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </span>
          </a>
          <a class="stat stat--filled stat--teal" href="<?= base_url('landlord/listings.php') ?>">
            <i class="fas fa-bed stat-icon" aria-hidden="true"></i>
            <span class="stat-value"><?= (int) $beds['taken'] ?><small> / <?= (int) $beds['capacity'] ?></small></span>
            <span class="stat-label">Beds taken</span>
            <span class="stat-more">
              <?= $beds['capacity'] > $beds['taken'] ? ($beds['capacity'] - $beds['taken']) . ' free in open rooms' : ($beds['capacity'] ? 'Every bed is taken' : 'No open rooms') ?>
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </span>
          </a>
          <?php /* How many boarders saved a listing; who they are stays private. */ ?>
          <a class="stat stat--filled stat--green" href="#yourListings">
            <i class="fas fa-heart stat-icon" aria-hidden="true"></i>
            <span class="stat-value"><?= (int) $saves ?></span>
            <span class="stat-label">Saved by boarders</span>
            <span class="stat-more">Per listing below <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
          </a>
        </div>
      <?php endif; ?>

      <?php /* Then the people and listings waiting on this landlord, also in the
               bell in the top bar. Only drawn while there are some. Each row
               leads with who or what is waiting: the boarder's picture, or the
               listing's cover photo. */ ?>
      <?php if ($pendingRequests || $sentBack): ?>
        <div class="card card--waiting shadow-sm">
          <?php panel_card_header(
            'Needs you now', '',
            $pendingRequestCount > count($pendingRequests)
              ? panel_card_link(base_url('landlord/reservations.php?status=pending'), 'See all ' . (int) $pendingRequestCount)
              : ''
          ); ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($pendingRequests as $r): ?>
              <?php $overdue = $r['move_in_date'] < $today; ?>
              <li class="list-group-item d-flex align-items-center" style="gap: 12px;">
                <span class="row-lead"><?= avatar_html(['full_name' => $r['boarder_name'], 'avatar_path' => $r['boarder_avatar']], 40) ?></span>
                <div class="d-flex flex-wrap flex-grow-1 align-items-center justify-content-between" style="gap: 10px; min-width: 0;">
                  <div style="min-width: 0;">
                    <strong><?= h($r['boarder_name']) ?></strong> wants <?= h($r['room_name']) ?>
                    <small class="text-muted d-block">
                      <?= h($r['house_name']) ?> &middot;
                      <?php if ($overdue): ?>
                        <span class="re-overdue">the move-in date (<?= h(date('M j', strtotime($r['move_in_date']))) ?>) has passed</span>
                      <?php else: ?>
                        moving in <?= h(date('M j', strtotime($r['move_in_date']))) ?>
                      <?php endif; ?>
                      &middot; asked <?= h(time_ago($r['created_at'])) ?>
                    </small>
                  </div>
                  <a href="<?= base_url('landlord/reservations.php?status=pending#request-' . (int) $r['reservation_id']) ?>" class="btn btn-sm btn-primary">
                    Answer<span class="sr-only"> <?= h($r['boarder_name']) ?></span>
                  </a>
                </div>
              </li>
            <?php endforeach; ?>
            <?php foreach ($sentBack as $item): ?>
              <li class="list-group-item d-flex align-items-start" style="gap: 12px;">
                <span class="row-lead"><?= listing_thumb_html($item['listing']) ?></span>
                <div class="d-flex flex-wrap flex-grow-1 align-items-center justify-content-between" style="gap: 10px; min-width: 0;">
                  <div style="min-width: 0;">
                    <strong><?= h($item['name']) ?></strong>
                    <span class="badge badge-danger ml-1">Needs changes</span>
                    <span class="d-block mt-1"><?= h($item['reason']) ?></span>
                    <small class="text-muted d-block">
                      Reviewed again after you save.
                      <?php if ($item['missing']): ?>&middot; <?= h(implode(' · ', $item['missing'])) ?><?php endif; ?>
                    </small>
                  </div>
                  <div class="d-flex flex-wrap" style="gap: 6px;">
                    <?php foreach ($item['fixes'] as $fix): ?>
                      <a href="<?= base_url($fix['link']) ?>" class="btn btn-sm <?= empty($fix['primary']) ? 'btn-outline-primary' : 'btn-primary' ?>">
                        <?= h($fix['label']) ?><span class="sr-only"> (<?= h($item['name']) ?>)</span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php /* Two columns on a laptop: what would help, then the listings. On
               a tablet or phone it is one column, in that order. */ ?>
      <?php $side = $todo || $decisions; ?>
      <div class="row">
        <?php if ($side): ?>
          <div class="col-lg-7">
            <?php if ($todo): ?>
              <div class="card card-outline card-secondary shadow-sm">
                <?php panel_card_header('To do'); ?>
                <ul class="list-group list-group-flush">
                  <?php foreach ($todo as $item): ?>
                    <li class="list-group-item d-flex align-items-center" style="gap: 12px;">
                      <span class="row-lead"><?= listing_thumb_html($item['listing']) ?></span>
                      <div class="d-flex flex-wrap flex-grow-1 align-items-center justify-content-between" style="gap: 10px; min-width: 0;">
                        <div style="min-width: 0;">
                          <strong><?= h($item['name']) ?></strong>
                          <small class="text-muted d-block"><?= h(implode(' · ', $item['missing'])) ?></small>
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 6px;">
                          <?php foreach ($item['fixes'] as $fix): ?>
                            <a href="<?= base_url($fix['link']) ?>" class="btn btn-sm btn-outline-primary">
                              <?= h($fix['label']) ?><span class="sr-only"> (<?= h($item['name']) ?>)</span>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if ($decisions): ?>
              <div class="card card-outline card-secondary shadow-sm">
                <?php panel_card_header('Updates from RoomEase'); ?>
                <ul class="list-group list-group-flush decision-list">
                  <?php foreach ($decisions as $d): ?>
                    <?php
                    [$word, $badge, $sentence] = $decisionWords[$d['action']];
                    // Approved but roomless: boarders cannot see it yet, so do not say they can.
                    if ($d['action'] === 'listing_approve' && ($roomCounts[(int) $d['boarding_house_id']] ?? 1) === 0) {
                      $sentence = 'is approved. Add a room and boarders can see it.';
                    }
                    ?>
                    <li class="list-group-item">
                      <span class="badge <?= $badge ?> mr-2"><?= h($word) ?></span>
                      <strong><?= h($d['name']) ?></strong> <?= h($sentence) ?>
                      <?php if ($d['detail']): ?>
                        <div class="text-muted mt-1"><?= $d['action'] === 'listing_reject' ? 'What to change: ' : 'Reason: ' ?><?= h($d['detail']) ?></div>
                      <?php endif; ?>
                      <small class="text-muted d-block mt-1"><?= h(time_ago($d['created_at'])) ?></small>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="<?= $side ? 'col-lg-5' : 'col-12' ?>">
          <?php /* A short look at the listings; the full table, with every action,
                   is on My Boarding Houses. Each row is one link to its listing. */ ?>
          <div class="card card-primary card-outline shadow-sm" id="yourListings">
            <?php panel_card_header(
              'Your boarding houses', '',
              $listings ? panel_card_link(base_url('landlord/listings.php'), 'See all') : ''
            ); ?>
            <?php if (!$listings): ?>
              <?= re_empty(
                'No boarding houses yet',
                'Post your first listing and an administrator will review it before boarders can see it.',
                'fa-house-user',
                '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-primary btn-sm">'
                  . '<i class="fas fa-plus mr-1" aria-hidden="true"></i> Create your first listing</a>'
              ) ?>
            <?php else: ?>
              <ul class="list-group list-group-flush">
                <?php foreach (array_slice($listings, 0, 5) as $l): ?>
                  <?php $avail = listing_availability($l); ?>
                  <li class="list-group-item row-link d-flex align-items-center" style="gap: 12px;">
                    <?= listing_thumb_html($l) ?>
                    <div class="flex-grow-1" style="min-width: 0;">
                      <a class="font-weight-bold stretched-link" href="<?= base_url('landlord/edit_listing.php?id=' . (int) $l['boarding_house_id']) ?>"><?= h($l['name']) ?></a>
                      <small class="text-muted d-block">
                        <?= h($avail['summary']) ?> &middot;
                        <i class="fas fa-heart" aria-hidden="true"></i>
                        <?= (int) $l['save_count'] ?> <?= (int) $l['save_count'] === 1 ? 'save' : 'saves' ?>
                      </small>
                    </div>
                    <?= moderation_badge($l['moderation_status'], true) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php if (count($listings) > 5): ?>
                <div class="card-footer small">
                  <a href="<?= base_url('landlord/listings.php') ?>">See all <?= count($listings) ?> boarding houses</a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
