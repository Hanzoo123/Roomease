<?php
/**
 * RoomEase Landlord Dashboard (AdminLTE Panel)
 * Shares the panel chrome with the admin area; see includes/layouts/panel.php.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require_login('landlord');

$landlordId = $_SESSION['user_id'];

// Summary figures for this landlord only.
$countStmt = $pdo->prepare(
  "SELECT COUNT(*) AS total,
          SUM(availability_status = 'available')   AS available,
          SUM(availability_status = 'unavailable') AS unavailable,
          SUM(moderation_status = 'approved')      AS approved,
          SUM(moderation_status = 'pending')       AS pending,
          SUM(moderation_status = 'rejected')      AS rejected
     FROM boarding_houses
    WHERE landlord_id = ? AND deleted_at IS NULL"
);
$countStmt->execute([$landlordId]);
$counts = $countStmt->fetch();

$listings = landlord_listings($landlordId);

// What an administrator decided about this landlord's listings lately,
// including any listing that was removed and so is no longer in the table.
$decisions = landlord_recent_decisions($landlordId);
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
    'subtitle' => 'Your boarding houses, where each one stands with approval, and what an administrator decided lately.',
    'actions' => '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-sm btn-primary">'
      . '<i class="fas fa-plus mr-1"></i> Add listing</a>',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <?php /* The same tiles as the administrator's dashboard. This used to be
           AdminLTE's own small-box, which was the clearest sign that the two
           panels had been built at different times: same job, two components,
           two sets of spacing. */ ?>
      <div class="stat-row">
        <a class="stat stat--filled stat--teal" href="<?= base_url('landlord/listings.php') ?>">
          <i class="fas fa-home stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['total'] ?></span>
          <span class="stat-label">My boarding houses</span>
          <span class="stat-more">View all <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--green" href="#myListings">
          <i class="fas fa-check-circle stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['approved'] ?></span>
          <span class="stat-label">Approved &amp; listed</span>
          <span class="stat-more">Visible to boarders <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--attention" href="#myListings">
          <i class="fas fa-clock stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['pending'] ?></span>
          <span class="stat-label">Awaiting approval</span>
          <span class="stat-more">Under admin review <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled <?= (int) $counts['rejected'] > 0 ? 'stat--danger' : 'stat--terracotta' ?>" href="#myListings">
          <i class="fas fa-exclamation-triangle stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['rejected'] ?></span>
          <span class="stat-label">Needs fixing</span>
          <span class="stat-more">
            <?= (int) $counts['rejected'] > 0 ? 'See why' : 'Nothing rejected' ?>
            <i class="fas fa-arrow-circle-right" aria-hidden="true"></i>
          </span>
        </a>
      </div>

      <?php if ($decisions): ?>
        <div class="card card-outline card-secondary shadow-sm">
          <div class="card-header">
            <h3 class="card-title">Updates from RoomEase</h3>
              <span class="card-subtitle">What an administrator decided about your listings lately.</span>
          </div>
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

      <?php require __DIR__ . '/../includes/components/landlord_listings_table.php'; ?>

    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
