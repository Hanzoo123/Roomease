<?php
/**
 * RoomEase Landlord Dashboard (AdminLTE Panel)
 * Shares the panel chrome with the admin area; see includes/panel.php.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
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
    WHERE landlord_id = ?"
);
$countStmt->execute([$landlordId]);
$counts = $countStmt->fetch();

// This landlord's listings, with a cover photo and a photo count each.
$stmt = $pdo->prepare(
  "SELECT bh.*,
          (SELECT COUNT(*) FROM images img
             WHERE img.boarding_house_id = bh.boarding_house_id) AS photo_count,
          (SELECT image_path FROM images img
             WHERE img.boarding_house_id = bh.boarding_house_id
             ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS cover_photo
     FROM boarding_houses bh
    WHERE bh.landlord_id = ?
    ORDER BY bh.created_at DESC"
);
$stmt->execute([$landlordId]);
$listings = $stmt->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/panel_head.php';
require __DIR__ . '/../includes/panel_navbar.php';
require __DIR__ . '/../includes/panel_sidebar.php';
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
            <a href="#myListings" class="small-box-footer">
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

      <div class="card card-primary card-outline shadow-sm" id="myListings">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title font-weight-bold">
            <i class="fas fa-clipboard-list mr-1"></i> My Boarding Houses
          </h3>
          <a href="<?= base_url('landlord/add_listing.php') ?>" class="btn btn-sm btn-primary ml-auto">
            <i class="fas fa-plus mr-1"></i> Add Listing
          </a>
        </div>

        <div class="card-body">
          <?php if (!$listings): ?>
            <div class="text-center text-muted py-5">
              <i class="fas fa-house-user fa-3x mb-3 d-block text-secondary"></i>
              <p class="mb-3">You haven't posted any boarding houses yet.</p>
              <a href="<?= base_url('landlord/add_listing.php') ?>" class="btn btn-primary">
                <i class="fas fa-plus mr-1"></i> Create your first listing
              </a>
            </div>
          <?php else: ?>
            <table id="listingsTable" class="table table-bordered table-striped table-hover">
              <thead>
                <tr>
                  <th>Boarding House</th>
                  <th>Address</th>
                  <th>Monthly Rent</th>
                  <th>Room Info</th>
                  <th>Photos</th>
                  <th>Approval</th>
                  <th>Status</th>
                  <th>Posted Date</th>
                  <th style="width: 130px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($listings as $l): ?>
                  <tr>
                    <td class="font-weight-bold">
                      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank"
                        class="text-dark" title="View Listing Details">
                        <i class="fas fa-external-link-alt text-xs text-primary mr-1"></i>
                        <?= h($l['name']) ?>
                      </a>
                    </td>
                    <td>
                      <i class="fas fa-map-marker-alt text-danger mr-1"></i>
                      <?= h($l['address']) ?>
                    </td>
                    <td>
                      <span
                        class="text-success font-weight-bold">&#8369;<?= number_format((float) $l['monthly_rent'], 2) ?></span>
                      <br>
                      <small class="text-muted">
                        <?php if ($l['reservation_fee'] === null || $l['reservation_fee'] === ''): ?>
                          No reservation fee
                        <?php else: ?>
                          Reservation: &#8369;<?= number_format((float) $l['reservation_fee'], 2) ?>
                        <?php endif; ?>
                      </small>
                    </td>
                    <td>
                      <span class="badge badge-info"><?= h($l['room_type'] ?? 'N/A') ?></span>
                      <br>
                      <small class="text-muted"><i class="fas fa-user-friends mr-1"></i>Cap:
                        <?= (int) $l['room_capacity'] ?></small>
                    </td>
                    <td>
                      <?php if ((int) $l['photo_count'] === 0): ?>
                        <span class="badge badge-warning px-2 py-1"><i class="fas fa-image mr-1"></i> None</span>
                      <?php else: ?>
                        <span class="badge badge-secondary px-2 py-1"><i class="fas fa-images mr-1"></i>
                          <?= (int) $l['photo_count'] ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= moderation_badge($l['moderation_status']) ?>
                      <?php if ($l['moderation_status'] === 'rejected' && $l['rejection_reason']): ?>
                        <br>
                        <small class="text-danger d-inline-block mt-1" style="max-width:220px;">
                          <i class="fas fa-info-circle mr-1"></i><?= h($l['rejection_reason']) ?>
                        </small>
                      <?php elseif ($l['moderation_status'] === 'pending'): ?>
                        <br>
                        <small class="text-muted">Hidden until approved</small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($l['availability_status'] === 'available'): ?>
                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Available</span>
                      <?php else: ?>
                        <span class="badge badge-secondary px-2 py-1"><i class="fas fa-times-circle mr-1"></i>
                          Unavailable</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-sm text-muted">
                      <?= h(date('M j, Y', strtotime($l['created_at']))) ?>
                    </td>
                    <td>
                      <div class="d-flex align-items-center" style="gap: 5px;">
                        <!-- Edit Button -->
                        <a href="<?= base_url('landlord/edit_listing.php?id=' . $l['boarding_house_id']) ?>"
                          class="btn btn-xs btn-outline-primary" title="Edit Listing">
                          <i class="fas fa-edit"></i>
                        </a>

                        <!-- View Button -->
                        <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank"
                          class="btn btn-xs btn-outline-info" title="Preview on Website">
                          <i class="fas fa-eye"></i>
                        </a>

                        <!-- Delete Button -->
                        <form method="post" action="<?= base_url('landlord/delete_listing.php') ?>" class="d-inline"
                          onsubmit="return confirm('Delete \'<?= h(addslashes($l['name'])) ?>\'? This cannot be undone.');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
                          <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete Listing">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <!-- /.card-body -->
      </div>
      <!-- /.card -->

    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>

<?php if ($listings): ?>
  <!-- Initialize DataTables for listingsTable -->
  <script>
    $(function () {
      $("#listingsTable").DataTable({
        "responsive": true,
        "lengthChange": true,
        "autoWidth": false,
        "order": [[7, "desc"]],
        "pageLength": 10
      });
    });
  </script>
<?php endif; ?>
