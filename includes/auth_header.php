<?php
/**
 * Standalone layout for the sign-in pages: log in, sign up, forgot password,
 * and reset password. No site header, band, or footer: the brand, a heading,
 * and one card on the background an administrator picks in Appearance.
 *
 * Set before including:
 *   $pageTitle    browser tab title
 *   $authHeading  the page's <h1>, shown under the brand
 *   $authWide     optional; true for the wider sign-up card
 *   $authPreview  optional; true when an administrator is previewing the page
 *   $authAdmin    optional; true on the administrators' sign-in pages, which
 *                 keep a fixed dark background and stay out of search results
 */
$pageTitle = $pageTitle ?? 'Sign in';
$authHeading = $authHeading ?? 'Sign in to your account';
$authWide = $authWide ?? false;
$authPreview = $authPreview ?? false;
$authAdmin = $authAdmin ?? false;
// The Appearance background is for the public pages only.
$background = $authAdmin
    ? ['style' => 'background-color: #184A3F;', 'tone' => 'dark']
    : auth_background();
$flash = $authPreview ? null : flash_get();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> · RoomEase<?= $authAdmin ? ' Admin' : '' ?></title>
  <?php if ($authAdmin): ?>
    <meta name="robots" content="noindex, nofollow">
  <?php endif; ?>
  <link rel="preload" href="<?= base_url('assets/fonts/fraunces-soft-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet"
    href="<?= base_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 0 ?>">
  <?php if ($flash): ?>
    <script src="<?= base_url('assets/js/flash.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/flash.js') ?: 0 ?>" defer></script>
  <?php endif; ?>
</head>

<body class="auth-page auth-page--<?= h($background['tone']) ?><?= $authAdmin ? ' auth-page--admin' : '' ?>" style="<?= h($background['style']) ?>">

  <main class="auth-shell">
    <a href="<?= base_url('index.php') ?>" class="auth-brand">RoomEase</a>
    <?php if ($authAdmin): ?>
      <span class="auth-brand-tag">Admin</span>
    <?php endif; ?>
    <h1 class="auth-heading"><?= h($authHeading) ?></h1>

    <?php if ($authPreview): ?>
      <p class="auth-preview" role="note">Preview only. Sign-in is turned off here because you are logged in.</p>
    <?php endif; ?>

    <div class="auth-card<?= $authWide ? ' auth-card--wide' : '' ?>">
      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="status"<?= $flash['type'] === 'error' ? '' : ' data-autohide' ?>>
          <?= h($flash['message']) ?>
        </div>
      <?php endif; ?>
