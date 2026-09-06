<?php
/**
 * AdminLTE Top Navbar for RoomEase Admin
 */
$adminName = $_SESSION['full_name'] ?? 'Administrator';
?>
<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <!-- Left navbar links -->
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button" title="Toggle Sidebar"><i
          class="fas fa-bars"></i></a>
    </li>

  </ul>

  <!-- Right navbar links -->
  <ul class="navbar-nav ml-auto">
    <li class="nav-item d-flex align-items-center mr-3">
      <span class="badge badge-success px-2 py-1"><i class="fas fa-shield-alt mr-1"></i> Administrator</span>
    </li>
    <li class="nav-item">
      <a href="<?= base_url('auth/logout.php') ?>" class="btn btn-sm btn-outline-danger" title="Log out from Admin">
        <i class="fas fa-sign-out-alt mr-1"></i> Logout
      </a>
    </li>
  </ul>
</nav>
<!-- /.navbar -->