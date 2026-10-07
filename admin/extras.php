<?php
/**
 * Amenities and utilities for all landlords. Items in use can't be deleted.
 * Landlords' own items are listed below and can be shared with everyone.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_login('admin');

// Five rows per card. Each card keeps its own page number in the URL,
// and actions return to the same pages.
$perPage = 5;
$pageParams = ['utilities_page', 'amenities_page', 'utilities_added_page', 'amenities_added_page'];

$pageUrl = function (array $overrides = []) use ($pageParams) {
    $query = [];
    foreach ($pageParams as $param) {
        $query[$param] = (int) ($overrides[$param] ?? $_GET[$param] ?? 1);
    }
    $query = array_filter($query, function ($page) { return $page > 1; });
    return 'admin/extras.php' . ($query ? '?' . http_build_query($query) : '');
};

// Returns [rows on this page, page, page count]. A page past the end
// (e.g. after deleting its last row) falls back to the last page.
$paginate = function (array $items, $param) use ($perPage) {
    $pages = max(1, (int) ceil(count($items) / $perPage));
    $page = min($pages, max(1, (int) ($_GET[$param] ?? 1)));
    return [array_slice($items, ($page - 1) * $perPage, $perPage), $page, $pages];
};

// Prev / Page x of y / Next, shown only when a list has more than one page.
$pager = function ($param, $page, $pages, $total, $anchor, $class) use ($perPage, $pageUrl) {
    if ($pages < 2) {
        return;
    }
    ?>
    <div class="<?= $class ?> d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
      <span class="text-muted small">
        <?= (($page - 1) * $perPage) + 1 ?>&ndash;<?= min($total, $page * $perPage) ?> of <?= $total ?>
      </span>
      <ul class="pagination pagination-sm m-0">
        <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= h(base_url($pageUrl([$param => $page - 1])) . '#' . $anchor) ?>">Prev</a>
        </li>
        <li class="page-item disabled"><span class="page-link">Page <?= $page ?> of <?= $pages ?></span></li>
        <li class="page-item <?= $page === $pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= h(base_url($pageUrl([$param => $page + 1])) . '#' . $anchor) ?>">Next</a>
        </li>
      </ul>
    </div>
    <?php
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $kind = ($_POST['kind'] ?? '') === 'amenity' ? 'amenity' : 'utility';
    $k = lookup_kind($kind);
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';

    if ($action === 'add') {
        [$newId, $error] = create_lookup($kind, $name, null);
        flash_set($error ?: $k['Singular'] . ' added for every landlord.', $error ? 'error' : 'success');
    } elseif ($action === 'rename') {
        $error = rename_lookup($kind, $id, $name, null);
        flash_set($error ?: 'Renamed.', $error ? 'error' : 'success');
    } elseif ($action === 'delete') {
        $usage = lookup_usage_counts($kind)[$id] ?? 0;
        if ($usage > 0) {
            flash_set('That ' . $k['singular'] . ' is used by ' . $usage . ' listing' . ($usage === 1 ? '' : 's')
                . ', so it cannot be deleted. Rename it instead if the wording is wrong.', 'error');
        } else {
            $pdo->prepare("DELETE FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id IS NULL")->execute([$id]);
            flash_set($k['Singular'] . ' deleted.', 'success');
        }
    } elseif ($action === 'promote') {
        $error = promote_lookup($kind, $id);
        flash_set($error ?: 'It is now available to every landlord.', $error ? 'error' : 'success');
    } else {
        flash_set('Unknown action.', 'error');
    }

    redirect($pageUrl() . '#' . $k['plural']);
}

$pageTitle = 'Utilities & Amenities';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <?php panel_page_header('Utilities & Amenities', [
    'subtitle' => 'Items here are available to every landlord. Landlords can also add their own, '
      . 'which only they see, and you can make any of those available to everyone.',
  ]); ?>

  <section class="content">
    <div class="container-fluid">

      <!-- One wrapping row: shared cards line up on top, landlord cards below.
           order-lg-* keeps each kind together when the columns stack on small screens. -->
      <div class="row">
        <?php foreach (['utility', 'amenity'] as $i => $kind): ?>
          <?php
          $k = lookup_kind($kind);
          $shared = lookup_choices($kind, null);
          $used = lookup_usage_counts($kind);
          $landlordItems = $pdo->query(
              "SELECT t.{$k['id']} AS id, t.{$k['name']} AS name, CONCAT(u.first_name, ' ', u.last_name) AS landlord
                 FROM {$k['table']} t
                 JOIN users u ON u.user_id = t.landlord_id
                ORDER BY t.{$k['name']}, landlord"
          )->fetchAll();
          $sharedParam = $k['plural'] . '_page';
          $addedParam = $k['plural'] . '_added_page';
          [$sharedRows, $sharedPage, $sharedPages] = $paginate($shared, $sharedParam);
          [$addedRows, $addedPage, $addedPages] = $paginate($landlordItems, $addedParam);
          ?>
          <div class="col-lg-6 d-flex flex-column order-lg-<?= $i + 1 ?>" id="<?= $k['plural'] ?>">
            <div class="card card-primary card-outline shadow-sm flex-fill">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas <?= $kind === 'utility' ? 'fa-bolt' : 'fa-concierge-bell' ?> mr-1"></i>
                  <?= $k['Plural'] ?> for every landlord
                </h3>
                <span class="card-subtitle">Available to every landlord on RoomEase.</span>
              </div>
              <div class="card-body d-flex flex-column">
                <?php if (!$shared): ?>
                  <p class="text-muted small">None yet.</p>
                <?php else: ?>
                  <ul class="list-group mb-3">
                    <?php foreach ($sharedRows as $item): ?>
                      <?php $n = (int) ($used[$item['id']] ?? 0); ?>
                      <li class="list-group-item py-2">
                        <div class="d-flex flex-wrap align-items-center" style="gap: 6px;">
                          <form method="post" class="d-flex flex-grow-1" style="gap: 6px; min-width: 200px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= $kind ?>">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <input type="text" name="name" value="<?= h($item['name']) ?>" maxlength="100"
                              class="form-control form-control-sm" aria-label="Name of <?= h($item['name']) ?>" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Rename</button>
                          </form>
                          <form method="post" class="js-confirm"
                            data-confirm="Delete &quot;<?= h($item['name']) ?>&quot; for every landlord?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= $kind ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                              title="<?= $n > 0 ? 'In use, cannot be deleted' : 'Delete' ?>" <?= $n > 0 ? 'disabled' : '' ?>>
                              <i class="fas fa-trash"></i>
                            </button>
                          </form>
                        </div>
                        <small class="text-muted"><?= $n === 0 ? 'Not used by any listing' : 'Used by ' . $n . ' listing' . ($n === 1 ? '' : 's') ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <?php $pager($sharedParam, $sharedPage, $sharedPages, count($shared), $k['plural'], 'mb-3'); ?>
                <?php endif; ?>

                <form method="post" class="d-flex mt-auto" style="gap: 6px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="kind" value="<?= $kind ?>">
                  <input type="hidden" name="action" value="add">
                  <input type="text" name="name" maxlength="100" class="form-control"
                    placeholder="<?= $kind === 'utility' ? 'e.g. Parking fee' : 'e.g. Rooftop deck' ?>"
                    aria-label="New <?= $k['singular'] ?> name" required>
                  <button type="submit" class="btn btn-primary text-nowrap">
                    <i class="fas fa-plus mr-1"></i> Add for everyone
                  </button>
                </form>
              </div>
            </div>
          </div>

          <div class="col-lg-6 d-flex flex-column order-lg-<?= $i + 3 ?>" id="<?= $k['plural'] ?>-added">
            <div class="card card-outline card-secondary shadow-sm flex-fill">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-user-tie mr-1"></i> <?= $k['Plural'] ?> landlords added
                </h3>
                <span class="card-subtitle">Their own items. Make one available to everyone to merge same-name copies.</span>
              </div>
              <div class="card-body p-0">
                <?php if (!$landlordItems): ?>
                  <?= re_empty('Nothing to review', 'No landlord has added a ' . $k['singular'] . ' of their own yet.', 'fa-user-tie') ?>
                <?php else: ?>
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr><th class="pl-3">Name</th><th>Landlord</th><th>Listings</th><th></th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($addedRows as $item): ?>
                        <tr>
                          <td class="pl-3 align-middle"><?= h($item['name']) ?></td>
                          <td class="align-middle"><?= h($item['landlord']) ?></td>
                          <td class="align-middle"><?= (int) ($used[$item['id']] ?? 0) ?></td>
                          <td class="text-right pr-3">
                            <form method="post" class="d-inline">
                              <?= csrf_field() ?>
                              <input type="hidden" name="kind" value="<?= $kind ?>">
                              <input type="hidden" name="action" value="promote">
                              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                              <button type="submit" class="btn btn-xs btn-outline-success"
                                title="Available to every landlord; copies with the same name are merged">
                                <i class="fas fa-share-alt mr-1"></i> Make available to everyone
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
              <?php $pager($addedParam, $addedPage, $addedPages, count($landlordItems), $k['plural'] . '-added', 'card-footer'); ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
