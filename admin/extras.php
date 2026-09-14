<?php
/**
 * Utilities and amenities for every landlord.
 *
 * Items made here are available to all landlords. An item cannot be deleted
 * while a listing uses it. Items landlords made for themselves are listed
 * underneath, and any of them can be made available to everyone: a landlord
 * copy with the same name as a shared item is merged into it, and the
 * listings that used the copy keep it.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('admin');

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

    redirect('admin/extras.php#' . $k['plural']);
}

$pageTitle = 'Utilities & Amenities';
require __DIR__ . '/../includes/panel_head.php';
require __DIR__ . '/../includes/panel_navbar.php';
require __DIR__ . '/../includes/panel_sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-bolt text-primary mr-2"></i>Utilities &amp; Amenities
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('admin/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Utilities &amp; Amenities</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <p class="text-muted">
        Items here are available to every landlord. Landlords can also add their own, which only they see; you can
        make any of those available to everyone.
      </p>

      <div class="row">
        <?php foreach (['utility', 'amenity'] as $kind): ?>
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
          ?>
          <div class="col-lg-6" id="<?= $k['plural'] ?>">
            <div class="card card-primary card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas <?= $kind === 'utility' ? 'fa-bolt' : 'fa-concierge-bell' ?> mr-1"></i>
                  <?= $k['Plural'] ?> for every landlord
                </h3>
              </div>
              <div class="card-body">
                <?php if (!$shared): ?>
                  <p class="text-muted small">None yet.</p>
                <?php else: ?>
                  <ul class="list-group mb-3">
                    <?php foreach ($shared as $item): ?>
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
                          <form method="post"
                            onsubmit="return confirm('Delete &quot;<?= h(addslashes($item['name'])) ?>&quot; for every landlord?');">
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
                <?php endif; ?>

                <form method="post" class="d-flex" style="gap: 6px;">
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

            <div class="card card-outline card-secondary shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-user-tie mr-1"></i> <?= $k['Plural'] ?> landlords added
                </h3>
              </div>
              <div class="card-body p-0">
                <?php if (!$landlordItems): ?>
                  <p class="text-muted small p-3 mb-0">No landlord has added their own <?= $k['plural'] ?> yet.</p>
                <?php else: ?>
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr><th class="pl-3">Name</th><th>Landlord</th><th>Listings</th><th></th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($landlordItems as $item): ?>
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
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>
