<?php
/**
 * A landlord's own utilities and amenities.
 *
 * The administrator's items are listed read-only, because every landlord
 * shares them. Below them are the items this landlord added, which only they
 * see on their listing form: add, rename, or delete. Deleting one also takes
 * it off any of their listings that used it.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require_login('landlord');

$landlordId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $kind = ($_POST['kind'] ?? '') === 'amenity' ? 'amenity' : 'utility';
    $k = lookup_kind($kind);
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';

    if ($action === 'add') {
        [$newId, $error] = create_lookup($kind, $name, $landlordId);
        flash_set($error ?: $k['Singular'] . ' added to your list.', $error ? 'error' : 'success');
    } elseif ($action === 'rename' || $action === 'delete') {
        $own = $pdo->prepare("SELECT {$k['name']} FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id = ?");
        $own->execute([$id, $landlordId]);
        $current = $own->fetchColumn();

        if ($current === false) {
            flash_set('You can only change ' . $k['plural'] . ' you added yourself.', 'error');
        } elseif ($action === 'rename') {
            $error = rename_lookup($kind, $id, $name, $landlordId);
            flash_set($error ?: 'Renamed.', $error ? 'error' : 'success');
        } else {
            $pdo->prepare("DELETE FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id = ?")->execute([$id, $landlordId]);
            flash_set('"' . strip_tags($current) . '" was deleted and taken off your listings.', 'success');
        }
    } else {
        flash_set('Unknown action.', 'error');
    }

    redirect('landlord/extras.php#' . $k['plural']);
}

// How many of this landlord's own listings use each item.
$usage = function ($kind) use ($pdo, $landlordId) {
    $k = lookup_kind($kind);
    $stmt = $pdo->prepare(
        "SELECT j.{$k['id']}, COUNT(*) FROM {$k['junction']} j
           JOIN boarding_houses bh ON bh.boarding_house_id = j.boarding_house_id
          WHERE bh.landlord_id = ?
          GROUP BY j.{$k['id']}"
    );
    $stmt->execute([$landlordId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
};

$pageTitle = 'Utilities & Amenities';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
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
            <li class="breadcrumb-item"><a href="<?= base_url('landlord/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Utilities &amp; Amenities</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <p class="text-muted">
        Items from the administrator are available to every landlord. Items you add are only on your own list, and
        boarders see them on any listing you tick them for.
      </p>

      <div class="row">
        <?php foreach (['utility', 'amenity'] as $kind): ?>
          <?php
          $k = lookup_kind($kind);
          $choices = lookup_choices($kind, $landlordId);
          $shared = array_filter($choices, fn($c) => !$c['own']);
          $mine = array_filter($choices, fn($c) => $c['own']);
          $used = $usage($kind);
          ?>
          <div class="col-lg-6" id="<?= $k['plural'] ?>">
            <div class="card card-primary card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas <?= $kind === 'utility' ? 'fa-bolt' : 'fa-concierge-bell' ?> mr-1"></i> <?= $k['Plural'] ?>
                </h3>
              </div>
              <div class="card-body">
                <h6 class="font-weight-bold text-muted text-uppercase small mb-2">From the administrator</h6>
                <?php if (!$shared): ?>
                  <p class="text-muted small">None yet.</p>
                <?php else: ?>
                  <div class="mb-3">
                    <?php foreach ($shared as $item): ?>
                      <span class="badge badge-light border px-2 py-1 mr-1 mb-1" style="font-size: 90%; font-weight: 500;"><?= h($item['name']) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <h6 class="font-weight-bold text-muted text-uppercase small mb-2 mt-3">Yours</h6>
                <?php if (!$mine): ?>
                  <p class="text-muted small">You have not added any <?= $k['plural'] ?> of your own.</p>
                <?php else: ?>
                  <ul class="list-group mb-3">
                    <?php foreach ($mine as $item): ?>
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
                            onsubmit="return confirm('Delete &quot;<?= h(addslashes($item['name'])) ?>&quot;? It is removed from any listing that uses it.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= $kind ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                              <i class="fas fa-trash"></i>
                            </button>
                          </form>
                        </div>
                        <small class="text-muted">
                          <?php $n = (int) ($used[$item['id']] ?? 0); ?>
                          <?= $n === 0 ? 'Not on any of your listings' : 'On ' . $n . ' of your listings' ?>
                        </small>
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
                    <i class="fas fa-plus mr-1"></i> Add
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
