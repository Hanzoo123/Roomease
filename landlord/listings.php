<?php
/** My Boarding Houses: this landlord's listings (same table as on the dashboard). */
require __DIR__ . '/../includes/init.php';
require_login('landlord');

$listings = landlord_listings($_SESSION['user_id']);

$pageTitle = 'My Boarding Houses';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <?php panel_page_header('My Boarding Houses', [
    'subtitle' => 'Every listing you have posted, its rooms, and whether boarders can see it yet.',
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
