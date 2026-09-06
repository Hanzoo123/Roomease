<?php
/**
 * AdminLTE-styled shell for the admin panel.
 * Expects $pageTitle and optionally $activeNav ('dashboard'|'users'|'listings')
 * to be set before including this file. Pairs with includes/admin_footer.php.
 */
$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> · RoomEase Admin</title>

<!-- Font Awesome -->
<link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css') ?>">
<!-- icheck bootstrap -->
<link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/icheck-bootstrap/icheck-bootstrap.min.css') ?>">
<!-- AdminLTE theme -->
<link rel="stylesheet" href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="<?= base_url('index.php') ?>" class="nav-link">Back to Site</a>
      </li>
    </ul>
    <ul class="navbar-nav ml-auto">
      <li class="nav-item">
        <span class="nav-link"><?= h($_SESSION['full_name'] ?? 'Admin') ?></span>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="<?= base_url('auth/logout.php') ?>"><i class="fas fa-sign-out-alt"></i> Log out</a>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="<?= base_url('admin/dashboard.php') ?>" class="brand-link">
      <span class="brand-text font-weight-light">&nbsp;RoomEase Admin</span>
    </a>

    <div class="sidebar">
      <div class="user-panel mt-3 pb-3 mb-3 d-flex">
        <div class="info">
          <a href="#" class="d-block"><?= h($_SESSION['full_name'] ?? 'Admin') ?></a>
        </div>
      </div>

      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-item">
            <a href="<?= base_url('admin/dashboard.php') ?>" class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('admin/manage_users.php') ?>" class="nav-link <?= $activeNav === 'users' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-users"></i>
              <p>Manage Users</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('admin/manage_listings.php') ?>" class="nav-link <?= $activeNav === 'listings' ? 'active' : '' ?>">
              <i class="nav-icon fas fa-list"></i>
              <p>Manage Listings</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('auth/logout.php') ?>" class="nav-link">
              <i class="nav-icon fas fa-sign-out-alt"></i>
              <p>Logout</p>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </aside>

  <!-- Content Wrapper -->
  <div class="content-wrapper">
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0"><?= h($pageTitle) ?></h1>
          </div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="container-fluid">
        <?php if ($flash): ?>
          <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
            <?= h($flash['message']) ?>
          </div>
        <?php endif; ?>
