<?php
/**
 * Header for public pages. All optional:
 *   $pageTitle
 *   $band       thin forest title strip: 'title', 'lede', 'back', 'pill', 'notice'
 *   $bleed      true if the page draws its own sections (home page)
 *   $bodyClass  'page-white' for a plain white page (legal pages)
 */
require_once __DIR__ . '/../components/icons.php';

$pageTitle = $pageTitle ?? 'RoomEase';
$band = $band ?? [];
$bleed = $bleed ?? false;
$bodyClass = $bodyClass ?? '';
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <?php /* The phone's browser chrome takes the header's white, so the bar seems
       to run on up to the top of the screen. */ ?>
  <meta name="theme-color" content="#FFFFFF">
  <title><?= h($pageTitle) ?> · RoomEase</title>
  <?php require __DIR__ . '/../components/head_meta.php'; ?>
  <link rel="preload" href="<?= base_url('assets/fonts/bricolage-grotesque-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <?php /* Before the first paint, so the collapsed navigation is only ever drawn
       where the script that opens it is running. With JavaScript off the links
       stay laid out as they always were. */ ?>
  <script>document.documentElement.className += ' js';</script>
  <?php /* filemtime stamp: a stylesheet edit shows up on the next load instead of
       sitting behind a stale browser cache. */ ?>
  <link rel="stylesheet"
    href="<?= base_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/css/style.css') ?: 0 ?>">
  <script src="<?= base_url('assets/js/site-header.js') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/js/site-header.js') ?: 0 ?>" defer></script>
  <?php if ($flash): ?>
    <script src="<?= base_url('assets/js/flash.js') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/js/flash.js') ?: 0 ?>" defer></script>
  <?php endif; ?>
</head>

<body<?= $bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '' ?>>

  <?php /* The first stop for a keyboard: past the header's links, straight to
       what the page is for. Invisible until it has focus. */ ?>
  <a class="skip-link" href="#main">Skip to content</a>

  <header class="site-header" data-site-header>
    <div class="container">
      <div class="header-bar">
        <a href="<?= base_url('index.php') ?>" class="brand"><img class="brand-logo" src="<?= base_url('assets/img/logo-mark-96.png') ?>" alt="" width="30" height="30"> RoomEase</a>

        <?php /* Only ever visible on a narrow screen, where the links below drop
             out of the bar and become a sheet hanging under it. Which icon
             shows follows aria-expanded, so the button has one state to set. */ ?>
        <button type="button" class="nav-toggle" data-nav-toggle
          aria-expanded="false" aria-controls="site-nav" aria-label="Menu">
          <span class="nav-toggle-open"><?= icon('menu', 22) ?></span>
          <span class="nav-toggle-close"><?= icon('x', 22) ?></span>
        </button>

        <nav class="nav" id="site-nav" aria-label="Main">
          <?php /* ?view=home: index.php otherwise sends landlords and
                   administrators on to their panel. */ ?>
          <a href="<?= base_url('index.php') . (is_logged_in() && current_role() !== 'boarder' ? '?view=home' : '') ?>"<?= $navCurrent('index.php') ?>>Home</a>
          <a href="<?= base_url('boarder/browse.php') ?>"<?= $navCurrent('boarder/browse.php') ?>>Find Place to Stay</a>
          <?php if (is_logged_in()): ?>
            <?php if (is_admin()): ?>
              <a href="<?= base_url('admin/dashboard.php') ?>">Admin panel</a>
            <?php elseif (current_role() === 'boarder'): ?>
              <a href="<?= base_url('boarder/saved.php') ?>"<?= $navCurrent('boarder/saved.php') ?>>Saved</a>
            <?php endif; ?>
          <?php else: ?>
            <a href="<?= base_url('auth/register.php?role=landlord') ?>" class="nav-extra">List a property</a>
          <?php endif; ?>
          <a href="<?= base_url('legal/about.php') ?>"<?= $navCurrent('legal/about.php') ?>>About Us</a>
          <a href="<?= base_url('legal/contact.php') ?>"<?= $navCurrent('legal/contact.php') ?>>Contact Us</a>
          <?php if (is_logged_in()): ?>
            <?php if (current_role() !== 'boarder'): ?>
              <span class="role-tag"><?= h(strtolower(role_label(current_role()))) ?></span>
            <?php endif; ?>
            <a href="<?= base_url('auth/profile.php') ?>" class="nav-extra"<?= $navCurrent('auth/profile.php') ?>>Profile</a>
            <a href="<?= base_url('auth/logout.php') ?>">Log out</a>
            <?php if (current_role() === 'landlord'): ?>
              <a href="<?= base_url('landlord/dashboard.php') ?>" class="btn-nav btn-nav--accent">Dashboard</a>
            <?php endif; ?>
          <?php else: ?>
            <a href="<?= base_url('auth/login.php') ?>" class="btn-nav btn-nav--solid"<?= $navCurrent('auth/login.php') ?>>Log in</a>
            <a href="<?= base_url('auth/register.php') ?>" class="btn-nav btn-nav--accent">Sign up</a>
          <?php endif; ?>
        </nav>
      </div>
    </div>

    <?php /* Dims the page behind an open sheet, and a tap anywhere on it closes
         the sheet. Hidden outright the rest of the time, so it can never
         swallow a tap meant for the page. */ ?>
    <div class="nav-scrim" data-nav-scrim hidden></div>
  </header>

  <?php /* A small notice at the bottom right, over the page rather than in
       it, so it never pushes the design down. Successes fade on their own
       (assets/js/flash.js); errors stay until closed. */ ?>
  <?php if ($flash): ?>
    <?php $flashError = $flash['type'] === 'error'; ?>
    <div class="flash-toast alert alert-<?= $flashError ? 'error' : 'success' ?>"
      role="<?= $flashError ? 'alert' : 'status' ?>"<?= $flashError ? '' : ' data-autohide' ?>>
      <span><?= h($flash['message']) ?></span>
      <button type="button" class="flash-close" data-flash-close aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
  <?php endif; ?>

<?php if ($bleed): ?>
  <main class="page" id="main" tabindex="-1">
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

  <main class="container page-body" id="main" tabindex="-1">
<?php endif; ?>
