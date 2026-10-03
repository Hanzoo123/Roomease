<?php
/**
 * Layout for sign-in pages: a card on the background chosen in Appearance.
 * Set: $pageTitle, $authHeading. Optional: $authWide (wider card),
 * $authPreview (admin preview), $authAdmin (admin pages: dark, not indexed).
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
  <?php /* Icons only: a sign-in page is not something anyone shares. */ ?>
  <?php $metaSocial = false; require __DIR__ . '/../components/head_meta.php'; ?>
  <?php if ($authAdmin): ?>
    <meta name="robots" content="noindex, nofollow">
  <?php endif; ?>
  <link rel="preload" href="<?= base_url('assets/fonts/bricolage-grotesque-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet"
    href="<?= base_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/css/style.css') ?: 0 ?>">
  <?php if ($flash): ?>
    <script src="<?= base_url('assets/js/flash.js') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/js/flash.js') ?: 0 ?>" defer></script>
  <?php endif; ?>
</head>

<body class="auth-page auth-page--<?= h($background['tone']) ?><?= $authAdmin ? ' auth-page--admin' : '' ?>" style="<?= h($background['style']) ?>">

  <main class="auth-shell">
    <a href="<?= base_url('index.php') ?>" class="auth-brand"><img class="brand-logo" src="<?= base_url('assets/img/logo-mark-96.png') ?>" alt="" width="44" height="44"> RoomEase</a>
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
