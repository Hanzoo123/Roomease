<?php
/** Panel top bar, with the account menu (profile, logout) and the dark mode switch. */
require_once __DIR__ . '/panel.php';
$panel = $panel ?? panel_config();

$navUser    = $_SESSION['full_name'] ?? $panel['badge']['label'];
// A new administrator with no email yet is shown by their username.
$navEmail   = ($_SESSION['email'] ?? '') ?: (!empty($_SESSION['username']) ? '@' . $_SESSION['username'] : '');
$navAccount = ['full_name' => $navUser, 'avatar_path' => $_SESSION['avatar_path'] ?? null];
?>
<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <!-- Left navbar links -->
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button" title="Toggle Sidebar"><i
          class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-md-flex align-items-center">
      <span class="nav-role"><?= h($panel['badge']['label']) ?> panel</span>
    </li>
  </ul>

  <!-- Right navbar links -->
  <ul class="navbar-nav ml-auto">
    <?php /* Light and dark. The icon shows the mode a press switches TO, and
         the name says so in words. panel_head.php applies the saved choice
         before the page paints; this only changes it. */ ?>
    <li class="nav-item">
      <button type="button" class="nav-link panel-theme-toggle" data-theme-toggle
        aria-label="Switch to dark mode" title="Switch to dark mode">
        <i class="fas fa-moon" aria-hidden="true"></i>
      </button>
    </li>
    <li class="nav-item dropdown panel-account">
      <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown" role="button"
        aria-haspopup="true" aria-expanded="false">
        <?= avatar_html($navAccount, 30, 're-avatar') ?>
        <span class="d-none d-sm-inline panel-account-name"><?= h($navUser) ?></span>
      </a>
      <div class="dropdown-menu dropdown-menu-right panel-account-menu">
        <div class="panel-account-head">
          <?= avatar_html($navAccount, 44, 're-avatar') ?>
          <div class="panel-account-who">
            <strong><?= h($navUser) ?></strong>
            <?php if ($navEmail !== ''): ?>
              <span><?= h($navEmail) ?></span>
            <?php endif; ?>
            <span class="panel-account-role"><?= h($panel['badge']['label']) ?></span>
          </div>
        </div>
        <div class="dropdown-divider"></div>
        <a href="<?= base_url('auth/profile.php') ?>" class="dropdown-item">
          <i class="fas fa-user-cog fa-fw mr-2"></i> My profile
        </a>
        <a href="<?= base_url('boarder/browse.php') ?>" target="_blank" class="dropdown-item">
          <i class="fas fa-globe fa-fw mr-2"></i> View main site
          <i class="fas fa-external-link-alt text-xs ml-1"></i>
        </a>
        <div class="dropdown-divider"></div>
        <a href="<?= base_url('auth/logout.php') ?>" class="dropdown-item">
          <i class="fas fa-sign-out-alt fa-fw mr-2"></i> Log out
        </a>
      </div>
    </li>
  </ul>
</nav>
<!-- /.navbar -->
<script>
  (function () {
    var root = document.documentElement;
    var toggle = document.querySelector('[data-theme-toggle]');
    if (!toggle) return;
    var icon = toggle.querySelector('i');

    function paint() {
      var dark = root.getAttribute('data-theme') === 'dark';
      var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
      toggle.setAttribute('aria-label', label);
      toggle.title = label;
      icon.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon');
    }

    function apply(theme) {
      root.setAttribute('data-theme', theme);
      document.body.classList.toggle('dark-mode', theme === 'dark');
      paint();
    }

    toggle.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('re-panel-theme', next); } catch (e) {}
      apply(next);
    });

    // Until a choice is made here, the panel keeps following the device, even
    // when it changes at sunset with the page already open.
    if (window.matchMedia) {
      var media = window.matchMedia('(prefers-color-scheme: dark)');
      var follow = function (e) {
        var saved = null;
        try { saved = localStorage.getItem('re-panel-theme'); } catch (err) {}
        if (saved !== 'light' && saved !== 'dark') apply(e.matches ? 'dark' : 'light');
      };
      if (media.addEventListener) media.addEventListener('change', follow);
      else if (media.addListener) media.addListener(follow);
    }

    paint();
  })();
</script>
