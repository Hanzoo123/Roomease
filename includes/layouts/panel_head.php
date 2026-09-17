<?php
/**
 * AdminLTE head for the RoomEase management panel (admin and landlord).
 * Role-specific wording comes from panel_config().
 */
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

  <link rel="preload" href="<?= base_url('assets/fonts/ibm-plex-sans-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css') ?>">
  <!-- DataTables -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') ?>">
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
  <div class="wrapper">
