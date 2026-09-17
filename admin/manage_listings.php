<?php
/**
 * RoomEase Admin - Manage Listings (AdminLTE Theme)
 *
 * Every listing by approval status, plus a Removed tab for the listings an
 * administrator archived, where they can be restored.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

// Removed listings are archived, not deleted, and live in their own tab.
$showRemoved = ($_GET['view'] ?? '') === 'removed';

// Optional moderation filter, e.g. ?status=pending for the approval queue.
$statusFilter = $_GET['status'] ?? '';
if ($showRemoved || !in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
  $statusFilter = '';
}

$where = ['bh.deleted_at IS ' . ($showRemoved ? 'NOT NULL' : 'NULL')];
$params = [];
if ($statusFilter !== '') {
  $where[] = 'bh.moderation_status = ?';
  $params[] = $statusFilter;
}

$stmt = $pdo->prepare(
  "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ",
          CONCAT(u.first_name, ' ', u.last_name) AS landlord_name,
          u.email AS landlord_email,
          u.is_active AS landlord_active,
          u.deleted_at AS landlord_deleted_at
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id
     " . room_summary_join() . "
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . ($showRemoved ? 'bh.deleted_at DESC' : 'bh.created_at DESC')
);
$stmt->execute($params);
$listings = $stmt->fetchAll();

// Counts for the filter tabs. The approval tabs count listings still on the
// books; removed ones are counted separately.
$tally = $pdo->query(
  "SELECT SUM(deleted_at IS NULL) AS all_listings,
          SUM(deleted_at IS NULL AND moderation_status = 'pending')  AS pending,
          SUM(deleted_at IS NULL AND moderation_status = 'approved') AS approved,
          SUM(deleted_at IS NULL AND moderation_status = 'rejected') AS rejected,
          SUM(deleted_at IS NOT NULL) AS removed
     FROM boarding_houses"
)->fetch();

$pageTitle = $showRemoved ? 'Removed Listings' : 'Manage Listings';
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
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
          <h3 class="card-title font-weight-bold">
            <i class="fas <?= $showRemoved ? 'fa-archive' : 'fa-clipboard-list' ?> mr-1"></i>
            <?= $showRemoved ? 'Removed Listings' : 'Registered Boarding Houses' ?>
          </h3>
          <?php if (!$showRemoved): ?>
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
          <?php endif; ?>
          <div class="mt-2 mt-sm-0 ml-sm-2<?= $showRemoved ? ' ml-auto' : '' ?>">
            <?php if ($showRemoved): ?>
              <a href="<?= base_url('admin/manage_listings.php') ?>" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-arrow-left mr-1"></i> Back to listings
              </a>
            <?php else: ?>
              <a href="<?= base_url('admin/manage_listings.php?view=removed') ?>" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-archive mr-1"></i> Removed
                <span class="badge badge-light ml-1"><?= (int) $tally['removed'] ?></span>
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($showRemoved): ?>
          <div class="card-body pb-0">
            <div class="alert alert-info mb-0 py-2">
              <i class="fas fa-info-circle mr-1"></i>
              These listings are hidden from boarders and from their landlords. Nothing has been deleted
              &mdash; restoring a listing brings it back with its rooms, photos, and approval status.
            </div>
          </div>
        <?php endif; ?>

        <div class="card-body">
          <table id="listingsTable" class="table table-bordered table-striped table-hover">
            <thead>
              <tr>
                <th>Boarding House</th>
                <th>Landlord</th>
                <th>Address</th>
                <th>Rooms</th>
                <th>Approval</th>
                <th>Website</th>
                <th><?= $showRemoved ? 'Removed' : 'Posted Date' ?></th>
                <th style="width: 110px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($listings as $l): ?>
                <?php
                $avail = listing_availability($l);
                // A listing whose landlord is removed or deactivated is off the public
                // site whatever its approval says, so the table says so too.
                $landlordLive = $l['landlord_deleted_at'] === null && (int) $l['landlord_active'] === 1;
                ?>
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
                    <?php if ($l['landlord_deleted_at'] !== null): ?>
                      <br><span class="badge badge-dark">Landlord removed</span>
                    <?php elseif (!$landlordLive): ?>
                      <br><span class="badge badge-secondary">Landlord deactivated</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <i class="fas fa-map-marker-alt text-danger mr-1"></i>
                    <?= h($l['address']) ?>
                  </td>
                  <td data-order="<?= (int) $avail['room_count'] ?>">
                    <?php if ($avail['room_count'] === 0): ?>
                      <span class="badge badge-warning px-2 py-1">No rooms yet</span>
                      <br><small class="text-muted">Cannot be approved until it has one</small>
                    <?php else: ?>
                      <span class="font-weight-bold"><?= h($avail['summary']) ?></span>
                      <br>
                      <small class="text-muted">
                        From &#8369;<?= number_format((float) $avail['rent_from'], 2) ?> &middot; <?= h($l['room_types']) ?>
                      </small>
                    <?php endif; ?>
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
                    <?php if ($showRemoved): ?>
                      <span class="badge badge-dark px-2 py-1"><i class="fas fa-archive mr-1"></i> Removed</span>
                    <?php elseif ($l['availability_status'] === 'available'): ?>
                      <span class="badge badge-success px-2 py-1"><i class="fas fa-eye mr-1"></i> Shown</span>
                    <?php else: ?>
                      <span class="badge badge-secondary px-2 py-1"><i class="fas fa-eye-slash mr-1"></i>
                        Hidden by landlord</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm text-muted" data-order="<?= h($showRemoved ? $l['deleted_at'] : $l['created_at']) ?>">
                    <?= h(date('M j, Y', strtotime($showRemoved ? $l['deleted_at'] : $l['created_at']))) ?>
                  </td>
                  <td>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 5px;">
                      <?php if ($showRemoved): ?>
                        <form method="post" action="<?= base_url('admin/listing_action.php') ?>" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="return_view" value="removed">
                          <button type="submit" class="btn btn-xs btn-outline-success" title="Restore Listing">
                            <i class="fas fa-trash-restore mr-1"></i> Restore
                          </button>
                        </form>
                      <?php else: ?>
                        <?php if ($l['moderation_status'] !== 'approved'): ?>
                          <?php
                          // Approve only once the listing has a room to show and its landlord is live.
                          $approveBlocked = $avail['room_count'] === 0
                            ? 'Needs at least one room before it can be approved'
                            : (!$landlordLive ? 'The landlord\'s account is removed or deactivated' : '');
                          ?>
                          <form method="post" action="<?= base_url('admin/listing_action.php') ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
                            <button type="submit" class="btn btn-xs btn-outline-success"
                              title="<?= h($approveBlocked !== '' ? $approveBlocked : 'Approve Listing') ?>"
                              <?= $approveBlocked !== '' ? 'disabled' : '' ?>>
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
                      <?php endif; ?>

                      <!-- View Button -->
                      <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank"
                        class="btn btn-xs btn-outline-info" title="Preview on Website">
                        <i class="fas fa-eye"></i>
                      </a>

                      <?php if (!$showRemoved): ?>
                        <!-- Remove Button (opens the removal dialog) -->
                        <button type="button" class="btn btn-xs btn-outline-danger js-remove"
                          data-id="<?= (int) $l['boarding_house_id'] ?>" data-name="<?= h($l['name']) ?>"
                          title="Remove Listing">
                          <i class="fas fa-trash"></i>
                        </button>
                      <?php endif; ?>
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

<?php
$returnStatus = $statusFilter;
require __DIR__ . '/../includes/components/listing_decision_modals.php';
require __DIR__ . '/../includes/layouts/panel_footer.php';
?>

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
