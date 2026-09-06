<?php
/**
 * AdminLTE Head Include for RoomEase Admin
 */
$pageTitle = $pageTitle ?? 'Admin Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?> | RoomEase Admin</title>

  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/fontawesome-free/css/all.min.css') ?>">
  <!-- DataTables -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') ?>">
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/datatables-responsive/css/responsive.bootstrap4.min.css') ?>">
  <!-- Toastr -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/toastr/toastr.min.css') ?>">
  <!-- Theme style -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/dist/css/adminlte.min.css') ?>">
  <style>
    .brand-link .brand-image {
      max-height: 33px;
    }
    .badge-landlord {
      background-color: #17a2b8;
      color: #fff;
    }
    .badge-boarder {
      background-color: #6c757d;
      color: #fff;
    }
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
