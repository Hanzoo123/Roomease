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
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-tachometer-alt text-primary mr-2"></i>Landlord Dashboard
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Dashboard</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <!-- /.content-header -->

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <!-- Small boxes (Stat box) -->
      <div class="row">
        <!-- Total listings -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-info shadow-sm">
            <div class="inner">
              <h3><?= (int) $counts['total'] ?></h3>

              <p>My Boarding Houses</p>
            </div>
            <div class="icon">
              <i class="fas fa-home"></i>
            </div>
            <a href="listings.php" class="small-box-footer">
              View All <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>

        <!-- Approved and live -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-success shadow-sm">
            <div class="inner">
              <h3><?= (int) $counts['approved'] ?></h3>
              <p>Approved &amp; Listed</p>
            </div>
            <div class="icon">
              <i class="fas fa-check-circle"></i>
            </div>
            <a href="#myListings" class="small-box-footer">
              Visible to Boarders <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>

        <!-- Awaiting review -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-warning shadow-sm">
            <div class="inner">
              <h3><?= (int) $counts['pending'] ?></h3>
              <p>Awaiting Approval</p>
            </div>
            <div class="icon">
              <i class="fas fa-clock"></i>
            </div>
            <a href="#myListings" class="small-box-footer">
              Under Admin Review <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>

        <!-- Rejected -->
        <div class="col-lg-3 col-6">
          <div class="small-box <?= (int) $counts['rejected'] > 0 ? 'bg-danger' : 'bg-olive' ?> shadow-sm">
            <div class="inner">
              <h3><?= (int) $counts['rejected'] ?></h3>
              <p>Needs Fixing</p>
            </div>
            <div class="icon">
              <i class="fas fa-exclamation-triangle"></i>
            </div>
            <a href="#myListings" class="small-box-footer">
              <?= (int) $counts['rejected'] > 0 ? 'See why' : 'Nothing rejected' ?>
              <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>
      </div>
      <!-- /.row -->

      <?php if ($decisions): ?>
        <div class="card card-outline card-secondary shadow-sm">
          <div class="card-header">
            <h3 class="card-title font-weight-bold">Updates from RoomEase</h3>
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
