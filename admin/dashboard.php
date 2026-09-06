<?php
/**
 * RoomEase Admin Dashboard (AdminLTE Theme)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
    redirect('admin/login.php');
}

// Fetch counts
$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role='landlord') AS landlords,
        (SELECT COUNT(*) FROM users WHERE role='boarder') AS boarders,
        (SELECT COUNT(*) FROM boarding_houses) AS listings,
        (SELECT COUNT(*) FROM boarding_houses WHERE availability_status='available') AS available"
)->fetch();

// Fetch recently added boarding houses
$recentListings = $pdo->query(
    "SELECT bh.boarding_house_id, bh.name AS boarding_house_name, bh.address,
            bh.monthly_rent, bh.availability_status AS status, bh.created_at,
            CONCAT(u.first_name, ' ', u.last_name) AS landlord_name
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id
     ORDER BY bh.created_at DESC
     LIMIT 6"
)->fetchAll();

// Fetch recently registered users
$recentUsers = $pdo->query(
    "SELECT user_id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS full_name,
            email, role, is_active, created_at
     FROM users
     WHERE role != 'administrator'
     ORDER BY created_at DESC
     LIMIT 5"
)->fetchAll();

$pageTitle = 'Dashboard';
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
            <i class="fas fa-tachometer-alt text-primary mr-2"></i>Admin Dashboard
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('admin/dashboard.php') ?>">Home</a></li>
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
        <!-- Landlords -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-info shadow-sm">
            <div class="inner">
              <h3><?= (int)$counts['landlords'] ?></h3>
              <p>Registered Landlords</p>
            </div>
            <div class="icon">
              <i class="fas fa-user-tie"></i>
            </div>
            <a href="<?= base_url('admin/manage_users.php?role=landlord') ?>" class="small-box-footer">
              View Landlords <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>

        <!-- Boarders -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-success shadow-sm">
            <div class="inner">
              <h3><?= (int)$counts['boarders'] ?></h3>
              <p>Registered Boarders</p>
            </div>
            <div class="icon">
              <i class="fas fa-users"></i>
            </div>
            <a href="<?= base_url('admin/manage_users.php?role=boarder') ?>" class="small-box-footer">
              View Boarders <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>

        <!-- Total Listings -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-warning shadow-sm">
            <div class="inner">
              <h3><?= (int)$counts['listings'] ?></h3>
              <p>Total Boarding Houses</p>
            </div>
            <div class="icon">
              <i class="fas fa-home"></i>
            </div>
            <a href="<?= base_url('admin/manage_listings.php') ?>" class="small-box-footer">
              View All Listings <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>

        <!-- Available Listings -->
        <div class="col-lg-3 col-6">
          <div class="small-box bg-olive shadow-sm">
            <div class="inner">
              <h3><?= (int)$counts['available'] ?></h3>
              <p>Available Listings</p>
            </div>
            <div class="icon">
              <i class="fas fa-check-circle"></i>
            </div>
            <a href="<?= base_url('admin/manage_listings.php') ?>" class="small-box-footer">
              Manage Listings <i class="fas fa-arrow-circle-right"></i>
            </a>
          </div>
        </div>
      </div>
      <!-- /.row -->

      <!-- Main row -->
      <div class="row">
        <!-- Left column -->
        <div class="col-lg-8">
          <!-- Recent Listings Card -->
          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header border-0">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-clipboard-list mr-1"></i> Recently Added Listings
              </h3>
              <div class="card-tools">
                <a href="<?= base_url('admin/manage_listings.php') ?>" class="btn btn-tool btn-sm">
                  <i class="fas fa-external-link-alt"></i> View All
                </a>
              </div>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-striped table-valign-middle mb-0">
                <thead>
                  <tr>
                    <th>Boarding House</th>
                    <th>City</th>
                    <th>Landlord</th>
                    <th>Rent</th>
                    <th>Status</th>
                    <th>Date Posted</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($recentListings)): ?>
                    <?php foreach ($recentListings as $l): ?>
                      <tr>
                        <td class="font-weight-bold">
                          <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank" class="text-dark">
                            <?= h($l['boarding_house_name']) ?>
                          </a>
                        </td>
                        <td><?= h($l['address']) ?></td>
                        <td><?= h($l['landlord_name']) ?></td>
                        <td><span class="text-success font-weight-bold">&#8369;<?= number_format((float)$l['monthly_rent'], 2) ?></span></td>
                        <td>
                          <?php if ($l['status'] === 'available'): ?>
                            <span class="badge badge-success px-2 py-1">Available</span>
                          <?php else: ?>
                            <span class="badge badge-secondary px-2 py-1"><?= h(ucfirst($l['status'])) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="text-muted text-sm"><?= h(date('M j, Y', strtotime($l['created_at']))) ?></td>
                        <td>
                          <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="View Details">
                            <i class="fas fa-eye"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="7" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i> No listings posted yet.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <!-- /.card -->
        </div>
        <!-- /.col-lg-8 -->

        <!-- Right column -->
        <div class="col-lg-4">
          <!-- Quick Actions Card -->
          <div class="card card-outline card-secondary shadow-sm mb-4">
            <div class="card-header">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-bolt mr-1 text-warning"></i> Quick Management
              </h3>
            </div>
            <div class="card-body p-3">
              <div class="d-flex flex-column" style="gap: 10px;">
                <a href="<?= base_url('admin/manage_users.php') ?>" class="btn btn-outline-primary btn-block text-left">
                  <i class="fas fa-users mr-2"></i> Manage System Users
                </a>
                <a href="<?= base_url('admin/manage_listings.php') ?>" class="btn btn-outline-success btn-block text-left">
                  <i class="fas fa-building mr-2"></i> Manage House Listings
                </a>
                <a href="<?= base_url('boarder/browse.php') ?>" target="_blank" class="btn btn-outline-secondary btn-block text-left">
                  <i class="fas fa-globe mr-2"></i> Browse Site as Guest
                </a>
              </div>
            </div>
          </div>

          <!-- Recently Joined Users Card -->
          <div class="card card-outline card-info shadow-sm">
            <div class="card-header">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-user-plus mr-1"></i> New Users
              </h3>
              <div class="card-tools">
                <a href="<?= base_url('admin/manage_users.php') ?>" class="btn btn-tool btn-sm">
                  <i class="fas fa-arrow-right"></i>
                </a>
              </div>
            </div>
            <div class="card-body p-0">
              <ul class="products-list product-list-in-card pl-2 pr-2">
                <?php if (!empty($recentUsers)): ?>
                  <?php foreach ($recentUsers as $ru): ?>
                    <li class="item py-2">
                      <div class="product-img">
                        <i class="fas fa-user-circle fa-2x text-secondary"></i>
                      </div>
                      <div class="product-info">
                        <span class="product-title font-weight-bold">
                           <?= h($ru['full_name']) ?>
                           <span class="badge badge-<?= $ru['role'] === 'landlord' ? 'info' : 'secondary' ?> float-right">
                             <?= h(ucfirst($ru['role'])) ?>
                           </span>
                         </span>
                         <span class="product-description text-xs text-muted">
                           <?= h($ru['email']) ?> &middot; Joined <?= h(date('M j, Y', strtotime($ru['created_at']))) ?>
                         </span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                <?php else: ?>
                  <li class="p-3 text-center text-muted">No users found.</li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
          <!-- /.card -->
        </div>
        <!-- /.col-lg-4 -->
      </div>
      <!-- /.row -->

    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/includes/footer.php'; ?>
