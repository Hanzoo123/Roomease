<?php
/**
 * AdminLTE Sidebar Include for RoomEase Admin
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$adminName = $_SESSION['full_name'] ?? 'Administrator';
$adminUser = $_SESSION['username'] ?? 'admin';
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <!-- Brand Logo -->
  <a href="<?= base_url('admin/dashboard.php') ?>" class="brand-link">
    <img src="<?= base_url('AdminLTE/dist/img/AdminLTELogo.png') ?>" alt="RoomEase Logo" class="brand-image img-circle elevation-3" style="opacity: .8">
    <span class="brand-text font-weight-light"><strong>Room</strong>Ease</span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Sidebar user panel (optional) -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <img src="<?= base_url('AdminLTE/dist/img/avatar5.png') ?>" class="img-circle elevation-2" alt="User Image">
      </div>
      <div class="info">
        <a href="<?= base_url('admin/dashboard.php') ?>" class="d-block font-weight-bold text-truncate" style="max-width: 160px;" title="<?= h($adminName) ?>">
          <?= h($adminName) ?>
        </a>
        <small class="text-success"><i class="fas fa-circle text-xs mr-1"></i> Online (Admin)</small>
      </div>
    </div>

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu" data-accordion="false">
        
        <li class="nav-header">NAVIGATION</li>
        
        <li class="nav-item">
          <a href="<?= base_url('admin/dashboard.php') ?>" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Dashboard</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="<?= base_url('admin/manage_users.php') ?>" class="nav-link <?= $currentPage === 'manage_users.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-users"></i>
            <p>Manage Users</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="<?= base_url('admin/manage_listings.php') ?>" class="nav-link <?= $currentPage === 'manage_listings.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-home"></i>
            <p>Manage Listings</p>
          </a>
        </li>

        <li class="nav-header">PORTAL</li>

        <li class="nav-item">
          <a href="<?= base_url('boarder/browse.php') ?>" target="_blank" class="nav-link">
            <i class="nav-icon fas fa-globe"></i>
            <p>
              View Main Site
              <i class="fas fa-external-link-alt right text-xs"></i>
            </p>
          </a>
        </li>

        <li class="nav-item">
          <a href="<?= base_url('admin/logout.php') ?>" class="nav-link text-danger">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <p>Logout</p>
          </a>
        </li>

      </ul>
    </nav>
    <!-- /.sidebar-menu -->
  </div>
  <!-- /.sidebar -->
</aside>
