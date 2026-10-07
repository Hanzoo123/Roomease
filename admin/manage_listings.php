<?php
/** Manage Listings: all listings by status, plus a Removed tab to restore from. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/components/admin_listing_row.php';

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
  admin_listings_select() . "
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . ($showRemoved ? 'bh.deleted_at DESC' : 'bh.created_at DESC')
);
$stmt->execute($params);
$listings = $stmt->fetchAll();

// Counts for the filter tabs. The approval tabs count listings still on the
// books; removed ones are counted separately.
$tally = admin_listing_tally();

$pageTitle = $showRemoved ? 'Removed Listings' : 'Manage Listings';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <?php
  $exportQuery = ['type' => 'listings'];
  if ($showRemoved) {
    $exportQuery['view'] = 'removed';
  } elseif ($statusFilter !== '') {
    $exportQuery['status'] = $statusFilter;
  }

  $pageActions = '<a href="' . base_url('admin/export.php?' . http_build_query($exportQuery))
    . '" class="btn btn-sm btn-outline-secondary" title="Download these listings as a spreadsheet">'
    . '<i class="fas fa-file-csv mr-1"></i> Export CSV</a>';

  $pageActions .= $showRemoved
    ? '<a href="' . base_url('admin/manage_listings.php') . '" class="btn btn-sm btn-outline-dark">'
      . '<i class="fas fa-home mr-1"></i> All listings</a>'
    : '<a href="' . base_url('admin/manage_listings.php?view=removed') . '" class="btn btn-sm btn-outline-dark">'
      . '<i class="fas fa-archive mr-1"></i> Removed <span class="badge badge-light ml-1" data-tally="removed">'
      . (int) $tally['removed'] . '</span></a>';

  // Pending is the one worth going straight to, so it is the page's primary
  // action — but only when something is actually waiting. (The script hides
  // it once a decision empties the queue.)
  if (!$showRemoved && (int) $tally['pending'] > 0 && $statusFilter !== 'pending') {
    $pageActions = '<a href="' . base_url('admin/manage_listings.php?status=pending')
      . '" class="btn btn-sm btn-primary" data-review-pending><i class="fas fa-clipboard-check mr-1"></i> Review '
      . '<span data-tally="pending">' . (int) $tally['pending'] . '</span> pending</a>' . $pageActions;
  }

  panel_page_header($showRemoved ? 'Removed listings' : 'Manage Listings', [
    'subtitle' => $showRemoved
      ? 'Listings an administrator removed or a landlord deleted. Both are archived, not erased: restoring one brings back its rooms, photos and approval.'
      : 'Every boarding house on RoomEase, by where it stands with approval.',
    'back' => $showRemoved ? 'admin/manage_listings.php' : null,
    'backLabel' => 'Back to all listings',
    'actions' => $pageActions,
  ]);
  ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <div class="card card-primary card-outline shadow-sm">
        <div class="card-header card-header--split">
          <div style="min-width: 0;">
            <h3 class="card-title"><?= $showRemoved ? 'Removed listings' : 'Registered boarding houses' ?></h3>
            <span class="card-subtitle">
              <?= $showRemoved ? 'Hidden from boarders and from their landlords until restored.'
                : 'Newest first. Select a name to review everything about that listing.' ?>
            </span>
          </div>
          <?php if (!$showRemoved): ?>
            <div class="btn-group" role="group" aria-label="Filter listings by approval status">
              <a href="<?= base_url('admin/manage_listings.php') ?>"
                class="btn btn-sm btn-outline-primary <?= $statusFilter === '' ? 'active' : '' ?>">
                All <span class="badge badge-light ml-1" data-tally="all_listings"><?= (int) $tally['all_listings'] ?></span>
              </a>
              <a href="<?= base_url('admin/manage_listings.php?status=pending') ?>"
                class="btn btn-sm btn-outline-primary <?= $statusFilter === 'pending' ? 'active' : '' ?>">
                Pending <span class="badge badge-light ml-1" data-tally="pending"><?= (int) $tally['pending'] ?></span>
              </a>
              <a href="<?= base_url('admin/manage_listings.php?status=approved') ?>"
                class="btn btn-sm btn-outline-primary <?= $statusFilter === 'approved' ? 'active' : '' ?>">
                Approved <span class="badge badge-light ml-1" data-tally="approved"><?= (int) $tally['approved'] ?></span>
              </a>
              <a href="<?= base_url('admin/manage_listings.php?status=rejected') ?>"
                class="btn btn-sm btn-outline-primary <?= $statusFilter === 'rejected' ? 'active' : '' ?>">
                Rejected <span class="badge badge-light ml-1" data-tally="rejected"><?= (int) $tally['rejected'] ?></span>
              </a>
            </div>
          <?php endif; ?>
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
                <?php /* Min width so the name doesn't wrap beside the thumbnail. */ ?>
                <th style="min-width: 215px;">Boarding House</th>
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
                <?= admin_listing_row($l, $showRemoved, $statusFilter) ?>
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
// Reject and Remove from this table are sent without a reload (listing_actions_js.php).
$decisionsInPlace = true;
require __DIR__ . '/../includes/components/listing_decision_modals.php';
require __DIR__ . '/../includes/layouts/panel_footer.php';
require __DIR__ . '/../includes/scripts/listing_actions_js.php';
?>

<!-- Initialize DataTables for listingsTable -->
<script>
  $(function () {
    $("#listingsTable").DataTable({
      "responsive": true,
      "lengthChange": true,
      "autoWidth": false,
      "order": [[6, "desc"]],
      "columnDefs": [{ "orderable": false, "targets": 7 }],
      "pageLength": 10
    });
  });
</script>
