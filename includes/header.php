<?php
/**
 * Shared page header. Expects $pageTitle to optionally be set
 * before including this file.
 */
$pageTitle = $pageTitle ?? 'RoomEase';
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> · RoomEase</title>
  <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
</head>

<body>

  <div class="topbar">
    <div class="container">
      <div class="brand"><a href="<?= base_url('boarder/browse.php') ?>" class="brand">
          RoomEase
        </a>

      </div>
      <nav class="nav">
        
        <?php if (is_logged_in()): ?>
          <?php if (current_role() === 'landlord'): ?>
            <a href="<?= base_url('landlord/dashboard.php') ?>">My Listings</a>
            <a href="<?= base_url('landlord/add_listing.php') ?>" class="btn-nav">+ Add Listing</a>
          <?php elseif (is_admin()): ?>
            <a href="<?= base_url('admin/dashboard.php') ?>">Admin Panel</a>
          <?php elseif (current_role() === 'boarder'): ?>
            <!-- boarders just browse -->
          <?php endif; ?>
          <span class="role-tag"><?= h(current_role()) ?></span>
          <a href="<?= base_url('auth/profile.php') ?>">Profile</a>
          <a href="<?= base_url('auth/logout.php') ?>">Log out</a>
        <?php else: ?>
          <a href="<?= base_url('auth/login.php') ?>">Log in</a>
          <a href="<?= base_url('auth/register.php') ?>" class="btn-nav">Sign up</a>
        <?php endif; ?>
      </nav>
    </div>
  </div>

  <div class="container" style="padding-top:26px;">
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>