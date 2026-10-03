<?php
/** <head> for the admin and landlord panel. */
require_once __DIR__ . '/panel.php';
$panel = $panel ?? panel_config();
$pageTitle = $pageTitle ?? $panel['name'] . ' Dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?> | RoomEase <?= h($panel['name']) ?></title>
  <?php
  /* Set light/dark mode before the page draws, so it doesn't flash white.
     Uses the saved choice (navbar switch), else the device setting. */ ?>
  <script>
    (function () {
      var theme = null;
      try { theme = localStorage.getItem('re-panel-theme'); } catch (e) { }
      if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
  <?php /* Icons only: the panel is behind a sign-in. */ ?>
  <?php $metaSocial = false;
  require __DIR__ . '/../components/head_meta.php'; ?>

  <link rel="preload" href="<?= base_url('assets/fonts/ibm-plex-sans-var-latin.woff2') ?>" as="font" type="font/woff2"
    crossorigin>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css') ?>">
  <!-- DataTables -->
  <link rel="stylesheet"
    href="<?= base_url('assets/adminlte/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') ?>">
  <link rel="stylesheet"
    href="<?= base_url('assets/adminlte/plugins/datatables-responsive/css/responsive.bootstrap4.min.css') ?>">
  <!-- Toastr -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/toastr/toastr.min.css') ?>">
  <!-- Theme style -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
  <!-- RoomEase panel styles: self-hosted fonts and theme, after AdminLTE so they win -->
  <link rel="stylesheet"
    href="<?= base_url('assets/css/panel.css') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/css/panel.css') ?: 0 ?>">
</head>

<body class="hold-transition sidebar-mini layout-fixed">
  <?php /* AdminLTE's own dark styles hang off body.dark-mode, so it is set here,
  before anything inside the body is drawn. */ ?>
  <script>
    if (document.documentElement.getAttribute('data-theme') === 'dark') {
      document.body.classList.add('dark-mode');
    }
  </script>
  <div class="wrapper">