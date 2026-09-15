<?php
/**
 * Shared page header for the public theme.
 *
 * Every page opens on the forest band, and the first thing in the page body
 * sits across the band's lower seam. Set these before including this file:
 *
 *   $pageTitle  string, optional.
 *   $band       array, optional. Leave unset for a short band with no heading
 *               (the auth forms). Keys, all optional:
 *                 'title', 'lede'  heading and one line under it
 *                 'back'           ['href' => ..., 'label' => ...]
 *                 'pill'           ['class' => 'pill--available', 'label' => ...]
 *                 'notice'         ['type' => 'error'|'success', 'strong' => ..., 'text' => ...]
 *   $bleed      bool, optional. True when the page draws its own bands and
 *               containers, as the home page does.
 */
$pageTitle = $pageTitle ?? 'RoomEase';
$band = $band ?? [];
$bleed = $bleed ?? false;
$flash = flash_get();

/** aria-current for the nav link that matches the page being viewed. */
$navCurrent = function ($path) {
    return base_url($path) === ($_SERVER['SCRIPT_NAME'] ?? '') ? ' aria-current="page"' : '';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> · RoomEase</title>
  <link rel="preload" href="<?= base_url('assets/fonts/fraunces-soft-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <?php /* filemtime stamp: a stylesheet edit shows up on the next load instead of
       sitting behind a stale browser cache. */ ?>
  <link rel="stylesheet"
    href="<?= base_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 0 ?>">
  <script src="<?= base_url('assets/js/site-header.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/site-header.js') ?: 0 ?>" defer></script>
  <?php if ($flash): ?>
    <script src="<?= base_url('assets/js/flash.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/flash.js') ?: 0 ?>" defer></script>
  <?php endif; ?>
</head>

<body>

  <header class="site-header" data-site-header>
    <div class="container">
      <div class="nav-tab">
        <a href="<?= base_url('index.php') ?>" class="brand">RoomEase</a>

        <nav class="nav" aria-label="Main">
          <a href="<?= base_url('boarder/browse.php') ?>"<?= $navCurrent('boarder/browse.php') ?>>Browse rooms</a>
          <?php if (is_logged_in()): ?>
            <?php if (current_role() === 'landlord'): ?>
              <a href="<?= base_url('landlord/dashboard.php') ?>">My listings</a>
            <?php elseif (is_admin()): ?>
              <a href="<?= base_url('admin/dashboard.php') ?>">Admin panel</a>
            <?php elseif (current_role() === 'boarder'): ?>
              <a href="<?= base_url('boarder/saved.php') ?>"<?= $navCurrent('boarder/saved.php') ?>>Saved</a>
            <?php endif; ?>
            <?php if (current_role() !== 'boarder'): ?>
              <span class="role-tag"><?= h(current_role()) ?></span>
            <?php endif; ?>
            <a href="<?= base_url('auth/profile.php') ?>" class="nav-extra"<?= $navCurrent('auth/profile.php') ?>>Profile</a>
            <a href="<?= base_url('auth/logout.php') ?>">Log out</a>
            <?php if (current_role() === 'landlord'): ?>
              <a href="<?= base_url('landlord/add_listing.php') ?>" class="btn-nav">Add listing</a>
            <?php endif; ?>
          <?php else: ?>
            <a href="<?= base_url('auth/register.php?role=landlord') ?>" class="nav-extra">List a property</a>
            <a href="<?= base_url('auth/login.php') ?>"<?= $navCurrent('auth/login.php') ?>>Log in</a>
            <a href="<?= base_url('auth/register.php') ?>" class="btn-nav">Sign up</a>
          <?php endif; ?>
        </nav>
      </div>
    </div>
  </header>

  <?php /* Outside the header, which stays on screen as the page scrolls: a
       message should scroll away with the band it sits in. */ ?>
  <?php if ($flash): ?>
    <div class="flash-band"<?= $flash['type'] === 'error' ? '' : ' data-autohide' ?>>
      <div class="container">
        <div class="flash-slot" role="status">
          <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
            <?= h($flash['message']) ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php if ($bleed): ?>
  <main class="page">
<?php else: ?>
  <section class="band<?= empty($band['title']) ? ' band--short' : '' ?>">
    <div class="container">
      <?php if (!empty($band['back'])): ?>
        <a href="<?= h($band['back']['href']) ?>" class="back-link">&larr; <?= h($band['back']['label']) ?></a>
      <?php endif; ?>

      <?php if (!empty($band['notice'])): ?>
        <div class="alert alert-<?= $band['notice']['type'] === 'error' ? 'error' : 'success' ?>">
          <?php if (!empty($band['notice']['strong'])): ?><strong><?= h($band['notice']['strong']) ?></strong><?php endif; ?>
          <?= h($band['notice']['text']) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($band['title'])): ?>
        <div class="band-head">
          <div>
            <h1 class="band-title"><?= h($band['title']) ?></h1>
            <?php if (!empty($band['lede'])): ?>
              <p class="band-lede"><?= h($band['lede']) ?></p>
            <?php endif; ?>
          </div>
          <?php if (!empty($band['pill'])): ?>
            <span class="pill <?= h($band['pill']['class']) ?>"><?= h($band['pill']['label']) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <main class="container page-body page-body--seam">
<?php endif; ?>
