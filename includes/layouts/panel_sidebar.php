<?php
/**
 * AdminLTE sidebar for the RoomEase management panel.
 * The menu items come from panel_config(), so each role gets its own.
 */
require_once __DIR__ . '/panel.php';
$panel = $panel ?? panel_config();

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$panelUser   = $_SESSION['full_name'] ?? $panel['badge']['label'];

// The signed-in account as the avatar renderer wants it. The photo comes from
// the session, which security.php refreshes from the database on every
// request, so changing it shows here on the very next page.
$panelAccount = ['full_name' => $panelUser, 'avatar_path' => $_SESSION['avatar_path'] ?? null];

// Work out the highlighted item once. Two entries can share a page and differ
// only by a query string (Manage Listings vs Pending Approvals), so the most
// specific match wins: an item whose query parameters all match the current
// request beats the same page listed without them. An item's 'also' pages,
// such as a listing's edit page, highlight it too.
$activeItem = null;
$bestScore  = -1;
foreach ($panel['menu'] as $idx => $item) {
    if (basename(parse_url($item['url'], PHP_URL_PATH)) !== $currentPage) {
        if ($activeItem === null && in_array($currentPage, $item['also'] ?? [], true)) {
            $activeItem = $idx;
        }
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
<aside class="main-sidebar sidebar-dark-primary">
  <!-- Brand: the RoomEase wordmark, as on the public site. The single letter
       is what stays visible when the sidebar is collapsed. -->
  <a href="<?= base_url($panel['home']) ?>" class="brand-link">
    <span class="brand-image brand-mark" aria-hidden="true">R</span>
    <span class="brand-text">RoomEase</span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Sidebar user panel (optional) -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <?= avatar_html($panelAccount, 34, 'panel-avatar') ?>
      </div>
      <div class="info">
        <a href="<?= base_url($panel['home']) ?>" class="d-block text-truncate panel-user" title="<?= h($panelUser) ?>">
          <?= h($panelUser) ?>
        </a>
        <small class="panel-role"><?= h($panel['badge']['label']) ?></small>
      </div>
    </div>

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu"
        data-accordion="false">

        <?php foreach ($panel['menu'] as $idx => $item): ?>
          <li class="nav-item">
            <a href="<?= base_url($item['url']) ?>"
              class="nav-link <?= $activeItem === $idx ? 'active' : '' ?>">
              <i class="nav-icon fas <?= h($item['icon']) ?>"></i>
              <p>
                <?= h($item['label']) ?>
                <?php if (!empty($item['count'])): ?>
                  <span class="right nav-count"><?= (int) $item['count'] ?></span>
                <?php endif; ?>
              </p>
            </a>
          </li>
        <?php endforeach; ?>

        <li class="nav-divider" role="separator"></li>

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
          <a href="<?= base_url('auth/logout.php') ?>" class="nav-link">
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
