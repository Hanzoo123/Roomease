<?php
/**
 * RoomEase Admin - Manage Users (AdminLTE Theme)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$roleFilter = $_GET['role'] ?? '';

// Removed accounts are archived rather than destroyed, so they need somewhere
// to be seen and restored from. The directory shows live accounts by default.
$showArchived = ($_GET['view'] ?? '') === 'archived';

$where = "role != 'administrator' AND deleted_at IS " . ($showArchived ? 'NOT NULL' : 'NULL');
$params = [];
if (in_array($roleFilter, ['landlord', 'boarder'], true)) {
  $where .= ' AND role = ?';
  $params[] = $roleFilter;
}

// Counts for the filter pills, taken from the view being shown: live accounts
// normally, removed ones in the Removed view. The archived total also labels
// the Removed button.
$counts = $pdo->query(
  "SELECT SUM(deleted_at IS NULL) AS live_total,
          SUM(deleted_at IS NOT NULL) AS archived_total,
          SUM(role = 'landlord' AND deleted_at IS " . ($showArchived ? 'NOT NULL' : 'NULL') . ") AS landlords,
          SUM(role = 'boarder'  AND deleted_at IS " . ($showArchived ? 'NOT NULL' : 'NULL') . ") AS boarders
     FROM users
    WHERE role != 'administrator'"
)->fetch();
$totalNonAdmin  = (int) $counts['live_total'];
$totalArchived  = (int) $counts['archived_total'];
$totalLandlords = (int) $counts['landlords'];
$totalBoarders  = (int) $counts['boarders'];

// Fetch users for DataTable
$stmt = $pdo->prepare("SELECT *, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-users text-primary mr-2"></i>Manage Users
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('admin/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Manage Users</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <!-- /.content-header -->

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <!-- Filter Buttons & Controls -->
      <div class="card card-primary card-outline shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
          <h3 class="card-title font-weight-bold">
            <i class="fas <?= $showArchived ? 'fa-archive' : 'fa-list' ?> mr-1"></i>
            <?= $showArchived ? 'Removed Accounts' : 'User Directory' ?>
          </h3>
          <div class="btn-group mt-2 mt-sm-0" role="group" aria-label="Filter users">
            <?php $q = $showArchived ? '?view=archived' : ''; $sep = $showArchived ? '&' : '?'; ?>
            <a href="manage_users.php<?= $q ?>"
              class="btn btn-sm btn-outline-primary <?= $roleFilter === '' ? 'active' : '' ?>">
              All <span class="badge badge-light ml-1"><?= $showArchived ? $totalArchived : $totalNonAdmin ?></span>
            </a>
            <a href="manage_users.php<?= $q . $sep ?>role=landlord"
              class="btn btn-sm btn-outline-info <?= $roleFilter === 'landlord' ? 'active' : '' ?>">
              Landlords <span class="badge badge-light ml-1"><?= $totalLandlords ?></span>
            </a>
            <a href="manage_users.php<?= $q . $sep ?>role=boarder"
              class="btn btn-sm btn-outline-secondary <?= $roleFilter === 'boarder' ? 'active' : '' ?>">
              Boarders <span class="badge badge-light ml-1"><?= $totalBoarders ?></span>
            </a>
          </div>
          <div class="mt-2 mt-sm-0 ml-sm-2">
            <?php
            $exportQuery = array_filter(['type' => 'users', 'view' => $showArchived ? 'archived' : '',
              'role' => in_array($roleFilter, ['landlord', 'boarder'], true) ? $roleFilter : '']);
            ?>
            <a href="<?= base_url('admin/export.php?' . http_build_query($exportQuery)) ?>" class="btn btn-sm btn-outline-secondary"
              title="Download these accounts as a spreadsheet">
              <i class="fas fa-file-csv mr-1"></i> Export CSV
            </a>
            <?php if ($showArchived): ?>
              <a href="manage_users.php" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-arrow-left mr-1"></i> Back to active users
              </a>
            <?php else: ?>
              <a href="manage_users.php?view=archived" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-archive mr-1"></i> Removed
                <span class="badge badge-light ml-1"><?= $totalArchived ?></span>
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($showArchived): ?>
          <div class="card-body pb-0">
            <div class="alert alert-info mb-0 py-2">
              <i class="fas fa-info-circle mr-1"></i>
              These accounts are hidden from the site and cannot sign in, and their listings do not
              appear in browse. Nothing has been deleted &mdash; restoring an account brings its
              listings back with it.
            </div>
          </div>
        <?php endif; ?>

        <div class="card-body">
          <table id="usersTable" class="table table-bordered table-striped table-hover">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
                <th style="width: 140px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td class="font-weight-bold">
                    <i
                      class="fas <?= $u['role'] === 'landlord' ? 'fa-user-tie text-info' : 'fa-user text-secondary' ?> mr-1"></i>
                    <a href="<?= base_url('admin/user.php?id=' . (int) $u['user_id']) ?>"><?= h($u['full_name']) ?></a>
                  </td>
                  <td>
                    <a href="mailto:<?= h($u['email']) ?>" class="text-muted"><?= h($u['email']) ?></a>
                  </td>
                  <td><?= h($u['phone_number'] ?: '—') ?></td>
                  <td>
                    <?php if ($u['role'] === 'landlord'): ?>
                      <span class="badge badge-info px-2 py-1"><i class="fas fa-user-tie mr-1"></i> Landlord</span>
                    <?php else: ?>
                      <span class="badge badge-secondary px-2 py-1"><i class="fas fa-user mr-1"></i> Boarder</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($u['deleted_at'] !== null): ?>
                      <span class="badge badge-dark px-2 py-1"><i class="fas fa-archive mr-1"></i> Removed</span>
                      <div class="text-muted text-sm mt-1">
                        <?= h(date('M j, Y', strtotime($u['deleted_at']))) ?>
                      </div>
                    <?php elseif ($u['is_active']): ?>
                      <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>
                    <?php else: ?>
                      <span class="badge badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm text-muted">
                    <?= h(date('M j, Y', strtotime($u['created_at']))) ?>
                  </td>
                  <td>
                    <div class="d-flex align-items-center" style="gap: 5px;">
                      <?php if ($u['deleted_at'] !== null): ?>
                        <!-- Restore Button -->
                        <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                          <input type="hidden" name="action" value="restore">
                          <button type="submit" class="btn btn-xs btn-outline-success" title="Restore Account">
                            <i class="fas fa-trash-restore mr-1"></i> Restore
                          </button>
                        </form>
                      <?php else: ?>
                        <!-- Status Toggle Button -->
                        <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                          <input type="hidden" name="action" value="toggle_status">
                          <?php if ($u['is_active']): ?>
                            <button type="submit" class="btn btn-xs btn-outline-warning" title="Deactivate Account">
                              <i class="fas fa-user-slash"></i>
                            </button>
                          <?php else: ?>
                            <button type="submit" class="btn btn-xs btn-outline-success" title="Activate Account">
                              <i class="fas fa-user-check"></i>
                            </button>
                          <?php endif; ?>
                        </form>

                        <!-- Remove (archive) Button -->
                        <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="d-inline"
                          onsubmit="return confirm('Remove <?= h(addslashes($u['full_name'])) ?>? Their account and listings will be hidden from the site. Nothing is deleted, and you can restore it from the Removed tab.');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                          <input type="hidden" name="action" value="delete">
                          <button type="submit" class="btn btn-xs btn-outline-danger" title="Remove User">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- /.card-body -->
      </div>
      <!-- /.card -->

    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>

<!-- Initialize DataTables for usersTable -->
<script>
  $(function () {
    $("#usersTable").DataTable({
      "responsive": true,
      "lengthChange": true,
      "autoWidth": false,
      "order": [[6, "desc"]],
      "pageLength": 10
    });
  });
</script>