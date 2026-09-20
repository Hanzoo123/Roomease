<?php
/**
 * AdminLTE top navbar for the RoomEase management panel.
 *
 * The account menu lives here and nowhere else: it is where a signed-in person
 * finds their own profile and the way out. It is in the navbar rather than the
 * sidebar so it stays reachable when the sidebar is collapsed to icons or
 * hidden on a phone.
 *
 * There is no bell. One was tried here, carrying the approval queue's count
 * and linking straight to it, but a bell that navigates rather than opening
 * anything is a worse version of the sidebar item it duplicated. What is
 * waiting is still on the Pending Approvals item in the sidebar and on the
 * dashboard's own queue card.
 */
require_once __DIR__ . '/panel.php';
$panel = $panel ?? panel_config();

$navUser    = $_SESSION['full_name'] ?? $panel['badge']['label'];
$navEmail   = $_SESSION['email'] ?? '';
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
