<?php
/** Landlord dashboard. Uses the same panel layout as admin (includes/layouts/panel.php). */
require __DIR__ . '/../includes/init.php';
require_login('landlord');

$landlordId = $_SESSION['user_id'];

// Summary figures for this landlord only.
$counts = landlord_listing_counts($landlordId);

$listings = landlord_listings($landlordId);
$saves = array_sum(array_column($listings, 'save_count'));
$beds = landlord_beds($landlordId);
$todo = landlord_todo($listings);

// What an administrator decided about this landlord's listings lately,
// including any listing that was removed and so is no longer in the table.
// A rejection still waiting to be fixed is left out: To do shows it, with
// its reason.
$stillRejected = [];
foreach ($listings as $l) {
  if ($l['moderation_status'] === 'rejected') {
    $stillRejected[(int) $l['boarding_house_id']] = true;
  }
}
$decisions = array_filter(landlord_recent_decisions($landlordId), function ($d) use ($stillRejected) {
  return !($d['action'] === 'listing_reject' && isset($stillRejected[(int) $d['boarding_house_id']]));
});
$decisionWords = [
  'listing_approve' => ['Approved', 'badge-success', 'is approved and visible to boarders.'],
  'listing_reject'  => ['Needs changes', 'badge-warning', 'was not approved yet. Edit it and it goes back for review.'],
  'listing_remove'  => ['Removed', 'badge-danger', 'was removed from RoomEase by an administrator.'],
  'listing_restore' => ['Restored', 'badge-info', 'was restored and is back in your listings.'],
];

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <?php panel_page_header('Dashboard', [
    'subtitle' => 'Your listings, what needs doing, and what RoomEase decided.',
    'actions' => '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-sm btn-primary">'
      . '<i class="fas fa-plus mr-1"></i> Add listing</a>',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <?php /* Same stat tiles as the admin dashboard, six in two rows of three. */ ?>
      <div class="stat-row stat-row--six">
        <a class="stat stat--filled stat--teal" href="<?= base_url('landlord/listings.php') ?>">
          <i class="fas fa-home stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['total'] ?></span>
          <span class="stat-label">My boarding houses</span>
          <span class="stat-more">View all <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <?php /* The other three open My Boarding Houses filtered to their status. */ ?>
        <a class="stat stat--filled stat--green" href="<?= base_url('landlord/listings.php?status=approved') ?>">
          <i class="fas fa-check-circle stat-icon" aria-hidden="true"></i>
          <?php /* Only what boarders can see: approved, with at least one room. */ ?>
          <span class="stat-value"><?= (int) $counts['live'] ?></span>
          <span class="stat-label">Visible to boarders</span>
          <span class="stat-more">
            <?= $counts['approved'] > $counts['live'] ? ($counts['approved'] - $counts['live']) . ' approved need a room' : 'Approved &amp; listed' ?>
            <i class="fas fa-arrow-circle-right" aria-hidden="true"></i>
          </span>
        </a>
        <a class="stat stat--filled stat--attention" href="<?= base_url('landlord/listings.php?status=pending') ?>">
          <i class="fas fa-clock stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['pending'] ?></span>
          <span class="stat-label">Awaiting approval</span>
          <span class="stat-more">Under admin review <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled <?= (int) $counts['rejected'] > 0 ? 'stat--danger' : 'stat--terracotta' ?>" href="<?= base_url('landlord/listings.php?status=rejected') ?>">
          <i class="fas fa-exclamation-triangle stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['rejected'] ?></span>
          <span class="stat-label">Needs fixing</span>
          <span class="stat-more">
            <?= (int) $counts['rejected'] > 0 ? 'See why' : 'Nothing rejected' ?>
            <i class="fas fa-arrow-circle-right" aria-hidden="true"></i>
          </span>
        </a>
        <?php /* How many boarders saved a listing; who they are stays private. */ ?>
        <a class="stat stat--filled stat--green" href="#yourListings">
          <i class="fas fa-heart stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $saves ?></span>
          <span class="stat-label">Saved by boarders</span>
          <span class="stat-more">Per listing below <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--teal" href="<?= base_url('landlord/listings.php') ?>">
          <i class="fas fa-bed stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $beds['taken'] ?><small> / <?= (int) $beds['capacity'] ?></small></span>
          <span class="stat-label">Beds taken</span>
          <span class="stat-more">
            <?= $beds['capacity'] > $beds['taken'] ? ($beds['capacity'] - $beds['taken']) . ' free in open rooms' : ($beds['capacity'] ? 'Every bed is taken' : 'No open rooms') ?>
            <i class="fas fa-arrow-circle-right" aria-hidden="true"></i>
          </span>
        </a>
      </div>

      <?php /* Two columns on a laptop: what to act on, then the listings. On
               a tablet or phone it is one column, in that order. */ ?>
      <?php $side = $todo || $decisions; ?>
      <div class="row">
        <?php if ($side): ?>
          <div class="col-lg-7">
            <?php if ($todo): ?>
              <?php /* Only shown while there is something to do. */ ?>
              <div class="card card-warning card-outline shadow-sm">
                <?php panel_card_header('To do', 'Small things that help boarders find and trust your listings.'); ?>
                <ul class="list-group list-group-flush">
                  <?php foreach ($todo as $item): ?>
                    <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between" style="gap: 10px;">
                      <div style="min-width: 0;">
                        <strong><?= h($item['name']) ?></strong>
                        <?php if ($item['reason'] !== null): ?>
                          <span class="d-block"><span class="badge badge-warning mr-1">Needs changes</span><?= h($item['reason']) ?></span>
                        <?php endif; ?>
                        <?php if ($item['missing']): ?>
                          <small class="text-muted d-block"><?= h(implode(' · ', $item['missing'])) ?></small>
                        <?php endif; ?>
                      </div>
                      <div class="d-flex flex-wrap" style="gap: 6px;">
                        <?php foreach ($item['fixes'] as $fix): ?>
                          <a href="<?= base_url($fix['link']) ?>" class="btn btn-sm btn-outline-primary"><?= h($fix['label']) ?></a>
                        <?php endforeach; ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if ($decisions): ?>
              <div class="card card-outline card-secondary shadow-sm">
                <?php panel_card_header('Updates from RoomEase', 'What an administrator decided about your listings lately.'); ?>
                <ul class="list-group list-group-flush decision-list">
                  <?php foreach ($decisions as $d): ?>
                    <?php [$word, $badge, $sentence] = $decisionWords[$d['action']]; ?>
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
                   is on My Boarding Houses. */ ?>
          <div class="card card-primary card-outline shadow-sm" id="yourListings">
            <?php panel_card_header(
              'Your boarding houses',
              'The newest first. Rooms, photos and every other change are on My Boarding Houses.',
              $listings ? '<a href="' . base_url('landlord/listings.php') . '" class="btn btn-tool">All ' . count($listings) . '</a>' : ''
            ); ?>
            <?php if (!$listings): ?>
              <?= re_empty(
                'No boarding houses yet',
                'Post your first listing and an administrator will review it before boarders can see it.',
                'fa-house-user',
                '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-primary btn-sm">'
                  . '<i class="fas fa-plus mr-1"></i> Create your first listing</a>'
              ) ?>
            <?php else: ?>
              <ul class="list-group list-group-flush">
                <?php foreach (array_slice($listings, 0, 5) as $l): ?>
                  <?php $avail = listing_availability($l); ?>
                  <li class="list-group-item d-flex align-items-center" style="gap: 12px;">
                    <?= listing_thumb_html($l) ?>
                    <div class="flex-grow-1" style="min-width: 0;">
                      <a class="font-weight-bold" href="<?= base_url('landlord/edit_listing.php?id=' . (int) $l['boarding_house_id']) ?>"><?= h($l['name']) ?></a>
                      <small class="text-muted d-block">
                        <?= h($avail['summary']) ?> &middot;
                        <i class="fas fa-heart" aria-hidden="true"></i>
                        <?= (int) $l['save_count'] ?> <?= (int) $l['save_count'] === 1 ? 'save' : 'saves' ?>
                      </small>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-end" style="gap: 8px;">
                      <?= moderation_badge($l['moderation_status']) ?>
                      <a href="<?= base_url('landlord/edit_listing.php?id=' . (int) $l['boarding_house_id']) ?>" class="btn btn-xs btn-outline-primary">Edit</a>
                    </div>
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

    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
