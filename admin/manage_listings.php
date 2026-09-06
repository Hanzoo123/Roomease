<?php
/**
 * RoomEase Admin - Manage Listings (AdminLTE Theme)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
  redirect('admin/login.php');
}

// Fetch all boarding houses with landlord name
$listings = $pdo->query(
  "SELECT bh.*,
            CONCAT(u.first_name, ' ', u.last_name) AS landlord_name,
            u.email AS landlord_email
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id
     ORDER BY bh.created_at DESC"
)->fetchAll();

$totalCount = count($listings);

$pageTitle = 'Manage Listings';
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/navbar.php';
require __DIR__ . '/includes/sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-home text-primary mr-2"></i>Manage Boarding House Listings
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('admin/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Manage Listings</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <!-- /.content-header -->

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <div class="card card-primary card-outline shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title font-weight-bold">
            <i class="fas fa-clipboard-list mr-1"></i> All Registered Boarding Houses
          </h3>
          <span class="badge badge-primary px-3 py-2 ml-auto"><?= $totalCount ?> total</span>
        </div>

        <div class="card-body">
          <table id="listingsTable" class="table table-bordered table-striped table-hover">
            <thead>
              <tr>
                <th>Boarding House</th>
                <th>Landlord</th>
                <th>Address</th>
                <th>Monthly Rent</th>
                <th>Room Info</th>
                <th>Status</th>
                <th>Posted Date</th>
                <th style="width: 110px;">Actions</th>
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
                    <span class="font-weight-bold"><?= h($l['landlord_name']) ?></span>
                    <br>
                    <small class="text-muted"><?= h($l['contact_number']) ?></small>
                  </td>
                  <td>
                    <i class="fas fa-map-marker-alt text-danger mr-1"></i>
                    <?= h($l['address']) ?>
                  </td>
                  <td>
                    <span
                      class="text-success font-weight-bold">&#8369;<?= number_format((float) $l['monthly_rent'], 2) ?></span>
                  </td>
                  <td>
                    <span class="badge badge-info"><?= h($l['room_type'] ?? 'N/A') ?></span>
                    <br>
                    <small class="text-muted"><i class="fas fa-user-friends mr-1"></i>Cap:
                      <?= (int) $l['room_capacity'] ?></small>
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
                      <!-- View Button -->
                      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank"
                        class="btn btn-xs btn-outline-info" title="Preview on Website">
                        <i class="fas fa-eye"></i>
                      </a>

                      <!-- Remove Button -->
                      <form method="post" action="<?= base_url('admin/listing_action.php') ?>" class="d-inline"
                        onsubmit="return confirm('Permanently remove \'<?= h(addslashes($l['name'])) ?>\' from RoomEase?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Remove Listing">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- /.card-body -->
      </div>
      <!-- /.card -->

    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/includes/footer.php'; ?>

<!-- Initialize DataTables for listingsTable -->
<script>
  $(function () {
    $("#listingsTable").DataTable({
      "responsive": true,
      "lengthChange": true,
      "autoWidth": false,
      "order": [[6, "desc"]],
      "pageLength": 10
    });
  });
</script>