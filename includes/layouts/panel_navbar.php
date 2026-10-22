<?php
/** Panel top bar, with the account menu (profile, logout) and the dark mode switch. */
require_once __DIR__ . '/panel.php';
$panel = $panel ?? panel_config();

$navUser    = $_SESSION['full_name'] ?? $panel['badge']['label'];
// A new administrator with no email yet is shown by their username.
$navEmail   = ($_SESSION['email'] ?? '') ?: (!empty($_SESSION['username']) ? '@' . $_SESSION['username'] : '');
$navAccount = ['full_name' => $navUser, 'avatar_path' => $_SESSION['avatar_path'] ?? null];
?>
<?php /* The first stop for the Tab key: past the top bar and the sidebar,
     straight to the page. The content area gets id="main" here or from
     panel_footer.php. */ ?>
<a class="skip-link" href="#main">Skip to content</a>

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
    <?php /* The bell: what is waiting on this person, on every panel page
         (panel_notifications() in panel.php). The number counts only what
         they can act on; it is hidden at zero. data-display="static" lets CSS
         place the menu, so on a phone it spans the screen instead of running
         off its left edge. */ ?>
    <?php $panelNotify = panel_notifications(); ?>
    <li class="nav-item dropdown panel-notify">
      <a href="#" class="nav-link panel-notify-toggle" data-toggle="dropdown" data-display="static" role="button"
        aria-haspopup="true" aria-expanded="false" title="Notifications"
        aria-label="<?= $panelNotify['count'] ? 'Notifications, ' . (int) $panelNotify['count'] . ' waiting on you' : 'Notifications' ?>">
        <i class="far fa-bell" aria-hidden="true"></i>
        <?php if ($panelNotify['count']): ?>
          <span class="panel-notify-count" aria-hidden="true"><?= $panelNotify['count'] > 99 ? '99+' : (int) $panelNotify['count'] ?></span>
        <?php endif; ?>
      </a>
      <div class="dropdown-menu dropdown-menu-right panel-notify-menu">
        <div class="panel-notify-head">
          <strong>Notifications</strong>
          <span><?= $panelNotify['count'] ? (int) $panelNotify['count'] . ' waiting on you' : 'All caught up' ?></span>
        </div>
        <?php if (!$panelNotify['groups']): ?>
          <p class="panel-notify-empty">
            <i class="fas fa-check-circle mr-1" aria-hidden="true"></i> Nothing is waiting on you right now.
          </p>
        <?php endif; ?>
        <?php foreach ($panelNotify['groups'] as $notifyGroup): ?>
          <div class="panel-notify-group">
            <p class="panel-notify-title"><?= h($notifyGroup['title']) ?></p>
            <?php foreach ($notifyGroup['items'] as $notifyItem): ?>
              <a class="dropdown-item panel-notify-item" href="<?= base_url($notifyItem['link']) ?>">
                <span class="panel-notify-label"><?= h($notifyItem['label']) ?></span>
                <span class="panel-notify-meta"><?= h($notifyItem['meta']) ?></span>
              </a>
            <?php endforeach; ?>
            <?php if (!empty($notifyGroup['more'])): ?>
              <a class="dropdown-item panel-notify-more" href="<?= base_url($notifyGroup['more']['link']) ?>">
                <?= h($notifyGroup['more']['label']) ?> <i class="fas fa-chevron-right" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </li>

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
