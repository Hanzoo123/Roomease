<?php
/**
 * RoomEase Admin - Manage Listings (AdminLTE Theme)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
  redirect('auth/login.php');
}

// Optional moderation filter, e.g. ?status=pending for the approval queue.
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
  $statusFilter = '';
}

$sql = "SELECT bh.*,
            CONCAT(u.first_name, ' ', u.last_name) AS landlord_name,
            u.email AS landlord_email
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id";
$params = [];
if ($statusFilter !== '') {
  $sql .= " WHERE bh.moderation_status = ?";
  $params[] = $statusFilter;
}
$sql .= " ORDER BY bh.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();

$totalCount = count($listings);

// Counts for the filter tabs.
$tally = $pdo->query(
  "SELECT COUNT(*) AS all_listings,
          SUM(moderation_status = 'pending')  AS pending,
          SUM(moderation_status = 'approved') AS approved,
          SUM(moderation_status = 'rejected') AS rejected
     FROM boarding_houses"
)->fetch();

$pageTitle = 'Manage Listings';
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
            <i class="fas fa-clipboard-list mr-1"></i> Registered Boarding Houses
          </h3>
          <div class="btn-group mt-2 mt-sm-0 ml-auto" role="group" aria-label="Filter listings by approval status">
            <a href="<?= base_url('admin/manage_listings.php') ?>"
              class="btn btn-sm btn-outline-primary <?= $statusFilter === '' ? 'active' : '' ?>">
              All <span class="badge badge-light ml-1"><?= (int) $tally['all_listings'] ?></span>
            </a>
            <a href="<?= base_url('admin/manage_listings.php?status=pending') ?>"
              class="btn btn-sm btn-outline-warning <?= $statusFilter === 'pending' ? 'active' : '' ?>">
              Pending <span class="badge badge-light ml-1"><?= (int) $tally['pending'] ?></span>
            </a>
            <a href="<?= base_url('admin/manage_listings.php?status=approved') ?>"
              class="btn btn-sm btn-outline-success <?= $statusFilter === 'approved' ? 'active' : '' ?>">
              Approved <span class="badge badge-light ml-1"><?= (int) $tally['approved'] ?></span>
            </a>
            <a href="<?= base_url('admin/manage_listings.php?status=rejected') ?>"
              class="btn btn-sm btn-outline-danger <?= $statusFilter === 'rejected' ? 'active' : '' ?>">
              Rejected <span class="badge badge-light ml-1"><?= (int) $tally['rejected'] ?></span>
            </a>
          </div>
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
                <th>Approval</th>
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
                    <?= moderation_badge($l['moderation_status']) ?>
                    <?php if ($l['moderation_status'] === 'rejected' && $l['rejection_reason']): ?>
                      <br>
                      <small class="text-muted" title="<?= h($l['rejection_reason']) ?>">
                        <?= h(mb_strimwidth($l['rejection_reason'], 0, 40, '...')) ?>
                      </small>
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
                    <div class="d-flex align-items-center flex-wrap" style="gap: 5px;">
                      <?php if ($l['moderation_status'] !== 'approved'): ?>
                        <!-- Approve Button -->
                        <form method="post" action="<?= base_url('admin/listing_action.php') ?>" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
                          <input type="hidden" name="action" value="approve">
                          <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
                          <button type="submit" class="btn btn-xs btn-outline-success" title="Approve Listing">
                            <i class="fas fa-check"></i>
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if ($l['moderation_status'] !== 'rejected'): ?>
                        <!-- Reject Button (opens the reason dialog) -->
                        <button type="button" class="btn btn-xs btn-outline-warning js-reject"
                          data-id="<?= (int) $l['boarding_house_id'] ?>" data-name="<?= h($l['name']) ?>"
                          title="Reject Listing">
                          <i class="fas fa-ban"></i>
                        </button>
                      <?php endif; ?>

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
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
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

<!-- Reject dialog: one modal reused for every row, filled in on click -->
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel"
  aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" action="<?= base_url('admin/listing_action.php') ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="rejectModalLabel">
            <i class="fas fa-ban text-warning mr-1"></i> Reject Listing
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
          <input type="hidden" name="boarding_house_id" id="rejectListingId" value="">
          <p class="mb-3">Rejecting <strong id="rejectListingName"></strong>. It stays hidden from boarders
            until the landlord fixes it and it is approved.</p>
          <div class="form-group mb-0">
            <label for="rejection_reason">Reason for the landlord</label>
            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" maxlength="500"
              placeholder="e.g. The address is incomplete, or the photos do not show the actual room." required></textarea>
            <small class="form-text text-muted">This is shown to the landlord on their dashboard.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="fas fa-ban mr-1"></i> Reject Listing
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>

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

<!-- Fill the shared reject dialog with whichever row was clicked -->
<script>
  $(function () {
    $(document).on("click", ".js-reject", function () {
      $("#rejectListingId").val($(this).data("id"));
      $("#rejectListingName").text($(this).data("name"));
      $("#rejection_reason").val("");
      $("#rejectModal").modal("show");
    });
  });
</script>
