<?php
/**
 * RoomEase Admin - Manage Users (AdminLTE Theme)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
    redirect('admin/login.php');
}

$roleFilter = $_GET['role'] ?? '';
$where = "role != 'administrator'";
$params = [];
if (in_array($roleFilter, ['landlord', 'boarder'], true)) {
    $where .= ' AND role = ?';
    $params[] = $roleFilter;
}

// Counts for filter pills
$totalNonAdmin  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'administrator'")->fetchColumn();
$totalLandlords = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord'")->fetchColumn();
$totalBoarders  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'boarder'")->fetchColumn();

// Fetch users for DataTable
$stmt = $pdo->prepare("SELECT *, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users';
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/navbar.php';
require __DIR__ . '/includes/sidebar.php';
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
            <i class="fas fa-list mr-1"></i> User Directory
          </h3>
          <div class="btn-group btn-group-toggle mt-2 mt-sm-0" data-toggle="buttons">
            <a href="manage_users.php" class="btn btn-sm btn-outline-primary <?= $roleFilter === '' ? 'active' : '' ?>">
              All <span class="badge badge-light ml-1"><?= $totalNonAdmin ?></span>
            </a>
            <a href="manage_users.php?role=landlord" class="btn btn-sm btn-outline-info <?= $roleFilter === 'landlord' ? 'active' : '' ?>">
              Landlords <span class="badge badge-light ml-1"><?= $totalLandlords ?></span>
            </a>
            <a href="manage_users.php?role=boarder" class="btn btn-sm btn-outline-secondary <?= $roleFilter === 'boarder' ? 'active' : '' ?>">
              Boarders <span class="badge badge-light ml-1"><?= $totalBoarders ?></span>
            </a>
          </div>
        </div>

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
                    <i class="fas <?= $u['role'] === 'landlord' ? 'fa-user-tie text-info' : 'fa-user text-secondary' ?> mr-1"></i>
                    <?= h($u['full_name']) ?>
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
                    <?php if ($u['is_active']): ?>
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
                      <!-- Status Toggle Button -->
                      <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
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

                      <!-- Delete User Button -->
                      <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="d-inline"
                            onsubmit="return confirm('Permanently delete <?= h(addslashes($u['full_name'])) ?> and all associated listings? This cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete User">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
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

<?php require __DIR__ . '/includes/footer.php'; ?>

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
