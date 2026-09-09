<?php
/**
 * AdminLTE sidebar for the RoomEase management panel.
 * The menu items come from panel_config(), so each role gets its own.
 */
require_once __DIR__ . '/panel.php';
$panel = $panel ?? panel_config();

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$panelUser   = $_SESSION['full_name'] ?? $panel['badge']['label'];

// Work out the highlighted item once. Two entries can share a page and differ
// only by a query string (Manage Listings vs Pending Approvals), so the most
// specific match wins: an item whose query parameters all match the current
// request beats the same page listed without them.
$activeItem = null;
$bestScore  = -1;
foreach ($panel['menu'] as $idx => $item) {
    if (basename(parse_url($item['url'], PHP_URL_PATH)) !== $currentPage) {
        continue;
    }
    $query = parse_url($item['url'], PHP_URL_QUERY);
    $score = 0;
    $matches = true;
    if ($query !== null) {
        parse_str($query, $wanted);
        foreach ($wanted as $key => $value) {
            if (($_GET[$key] ?? null) !== $value) {
                $matches = false;
                break;
            }
            $score++;
        }
    }
    if ($matches && $score > $bestScore) {
        $bestScore  = $score;
        $activeItem = $idx;
    }
}
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <!-- Brand Logo -->
  <a href="<?= base_url($panel['home']) ?>" class="brand-link">
    <i class="fas fa-house-user brand-image img-circle elevation-3 text-center"
      style="opacity:.8; background:#007bff; color:#fff; width:33px; height:33px; line-height:33px; font-size:15px;"></i>
    <span class="brand-text font-weight-light"><strong>Room</strong>Ease</span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Sidebar user panel (optional) -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <i class="fas <?= h($panel['avatar']) ?> img-circle elevation-2 text-center"
          style="background:#007bff; color:#fff; width:34px; height:34px; line-height:34px; font-size:15px;"></i>
      </div>
      <div class="info">
        <a href="<?= base_url($panel['home']) ?>" class="d-block font-weight-bold text-truncate"
          style="max-width: 160px;" title="<?= h($panelUser) ?>">
          <?= h($panelUser) ?>
        </a>
        <small class="text-success"><i class="fas fa-circle text-xs mr-1"></i> Online
          (<?= h($panel['name']) ?>)</small>
      </div>
    </div>

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu"
        data-accordion="false">

        <li class="nav-header">NAVIGATION</li>

        <?php foreach ($panel['menu'] as $idx => $item): ?>
          <li class="nav-item">
            <a href="<?= base_url($item['url']) ?>"
              class="nav-link <?= $activeItem === $idx ? 'active' : '' ?>">
              <i class="nav-icon fas <?= h($item['icon']) ?>"></i>
              <p><?= h($item['label']) ?></p>
            </a>
          </li>
        <?php endforeach; ?>

        <li class="nav-header">PORTAL</li>

        <li class="nav-item">
          <a href="<?= base_url('boarder/browse.php') ?>" target="_blank" class="nav-link">
            <i class="nav-icon fas fa-globe"></i>
            <p>
              View Main Site
              <i class="fas fa-external-link-alt right text-xs"></i>
            </p>
          </a>
        </li>

        <li class="nav-item">
          <a href="<?= base_url('auth/logout.php') ?>" class="nav-link text-danger">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <p>Logout</p>
          </a>
        </li>

      </ul>
    </nav>
    <!-- /.sidebar-menu -->
  </div>
  <!-- /.sidebar -->
</aside>
