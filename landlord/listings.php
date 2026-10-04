<?php
/**
 * My Boarding Houses: this landlord's listings (same table as on the dashboard).
 * ?status=approved, pending or rejected shows only those; the sidebar's Needs
 * Changes opens ?status=rejected.
 */
require __DIR__ . '/../includes/init.php';
require_login('landlord');

$landlordId = (int) $_SESSION['user_id'];

// Optional approval filter, checked against the three statuses, as on the
// admin's Manage Listings.
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['approved', 'pending', 'rejected'], true)) {
    $statusFilter = '';
}

$listings = landlord_listings($landlordId, $statusFilter);
$counts = landlord_listing_counts($landlordId);

// Each filter: its button, the line under the page title, and what the table
// says when no listing has that status.
$filters = [
    '' => [
        'label'    => 'All',
        'count'    => $counts['total'],
        'subtitle' => 'Every listing you have posted, its rooms, and whether boarders can see it yet.',
    ],
    'approved' => [
        'label'    => 'Approved',
        'count'    => $counts['approved'],
        'subtitle' => 'Listings an administrator approved. Boarders can see these on the website.',
        'empty'    => [
            'title' => 'No approved listings yet',
            'text'  => 'An administrator checks each listing before boarders can see it.',
            'icon'  => 'fa-hourglass-half',
        ],
    ],
    'pending' => [
        'label'    => 'Pending',
        'count'    => $counts['pending'],
        'subtitle' => 'Listings waiting for an administrator. Boarders see them once approved.',
        'empty'    => [
            'title' => 'Nothing waiting for approval',
            'text'  => 'A listing you add or edit waits here until an administrator checks it.',
            'icon'  => 'fa-clock',
        ],
    ],
    'rejected' => [
        'label'    => 'Needs changes',
        'count'    => $counts['rejected'],
        'subtitle' => 'Listings an administrator sent back. Edit one, and it goes back for review.',
        'empty'    => [
            'title' => 'Nothing needs changes',
            'text'  => 'When an administrator sends a listing back, it appears here with what to change.',
            'icon'  => 'fa-check-circle',
        ],
    ],
];

// For the table (includes/components/landlord_listings_table.php): the filter
// buttons on the right of its header, and the empty message for this filter.
// A landlord with no listings at all still gets "Create your first listing".
$listingsFilter = '<div class="btn-group" role="group" aria-label="Filter listings by approval status">';
foreach ($filters as $filterStatus => $filter) {
    $listingsFilter .= '<a href="' . base_url('landlord/listings.php' . ($filterStatus !== '' ? '?status=' . $filterStatus : ''))
        . '" class="btn btn-sm btn-outline-primary' . ($filterStatus === $statusFilter ? ' active' : '') . '">'
        . h($filter['label']) . ' <span class="badge badge-light ml-1">' . (int) $filter['count'] . '</span></a>';
}
$listingsFilter .= '</div>';
$listingsEmpty = $statusFilter !== '' && $counts['total'] > 0 ? $filters[$statusFilter]['empty'] : null;

$pageTitle = 'My Boarding Houses';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <?php panel_page_header('My Boarding Houses', [
    'subtitle' => $filters[$statusFilter]['subtitle'],
    'actions' => '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-sm btn-primary">'
      . '<i class="fas fa-plus mr-1"></i> Add listing</a>',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <?php require __DIR__ . '/../includes/components/landlord_listings_table.php'; ?>
    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
