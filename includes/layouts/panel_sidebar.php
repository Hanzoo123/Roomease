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
//
// A group's pages are checked like any other item, keyed "group-page" (such
// as "1-0"), so the group can be drawn open around its highlighted page.
$links = [];
foreach ($panel['menu'] as $idx => $item) {
    if (!empty($item['children'])) {
        foreach ($item['children'] as $childIdx => $child) {
            $links[$idx . '-' . $childIdx] = $child;
        }
    } else {
        $links[(string) $idx] = $item;
    }
}
$activeItem = null;
$bestScore  = -1;
foreach ($links as $idx => $item) {
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
// PHP turns a key such as "3" into the number 3; compare as text throughout.
$activeItem = $activeItem === null ? null : (string) $activeItem;
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-light-primary">
  <!-- Brand: the RoomEase mark and wordmark, as on the public site. The mark
       is what stays visible when the sidebar is collapsed. -->
  <a href="<?= base_url($panel['home']) ?>" class="brand-link">
    <span class="brand-image brand-mark" aria-hidden="true"><img src="<?= base_url('assets/img/logo-mark-96.png') ?>" alt="" width="28" height="28"></span>
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
          <?php if (!empty($item['children'])): ?>
            <?php $groupOpen = $activeItem !== null && strpos($activeItem, $idx . '-') === 0; ?>
            <?php /* AdminLTE's treeview: the heading opens and closes the pages
                     under it, and starts open on one of those pages. */ ?>
            <li class="nav-item has-treeview<?= $groupOpen ? ' menu-open' : '' ?>">
              <a href="#" class="nav-link nav-group<?= $groupOpen ? ' is-open' : '' ?>" role="button"
                aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>">
                <i class="nav-icon fas <?= h($item['icon']) ?>"></i>
                <p>
                  <?= h($item['label']) ?>
                  <i class="right fas fa-angle-left"></i>
                </p>
              </a>
              <ul class="nav nav-treeview">
                <?php foreach ($item['children'] as $childIdx => $child): ?>
                  <li class="nav-item">
                    <a href="<?= base_url($child['url']) ?>"
                      class="nav-link <?= $activeItem === $idx . '-' . $childIdx ? 'active' : '' ?>">
                      <i class="nav-icon fas <?= h($child['icon']) ?>"></i>
                      <p><?= h($child['label']) ?></p>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php else: ?>
            <li class="nav-item">
              <a href="<?= base_url($item['url']) ?>"
                class="nav-link <?= $activeItem === (string) $idx ? 'active' : '' ?>">
                <i class="nav-icon fas <?= h($item['icon']) ?>"></i>
                <p>
                  <?= h($item['label']) ?>
                  <?php if (!empty($item['count'])): ?>
                    <span class="right nav-count"><?= (int) $item['count'] ?></span>
                  <?php endif; ?>
                </p>
              </a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>

        <li class="nav-divider" role="separator"></li>

        <li class="nav-item">
          <a href="<?= base_url('boarder/browse.php') ?>" target="_self" class="nav-link">
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
    <script>
      // AdminLTE opens and closes a group by its menu-open class; keep what a
      // screen reader is told ("expanded" or "collapsed") in step with it.
      document.querySelectorAll('.nav-sidebar .has-treeview').forEach(function (group) {
        var heading = group.querySelector('.nav-group');
        new MutationObserver(function () {
          heading.setAttribute('aria-expanded', group.classList.contains('menu-open') ? 'true' : 'false');
        }).observe(group, { attributes: true, attributeFilter: ['class'] });
      });
    </script>
  </div>
  <!-- /.sidebar -->
</aside>
