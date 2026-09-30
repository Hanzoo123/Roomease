<?php
/**
 * All Users: one directory of everyone who can sign in (administrators,
 * landlords and boarders), for super admins only.
 *
 * Super admins are never listed, and neither are removed accounts, which stay
 * in the Removed views of Manage Users and Administrators where they can be
 * restored. It is a directory to look at: selecting a name opens the page
 * where that account is managed.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_super_admin();

$roles = ['administrator' => 'Administrators', 'landlord' => 'Landlords', 'boarder' => 'Boarders'];
$roleFilter = array_key_exists($_GET['role'] ?? '', $roles) ? $_GET['role'] : '';

// Who belongs here: live accounts, super admins left out.
$base = "deleted_at IS NULL AND NOT (role = 'administrator' AND is_super_admin = 1)";

$counts = $pdo->query("SELECT role, COUNT(*) FROM users WHERE $base GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
$total = array_sum($counts);

$stmt = $pdo->prepare(
    "SELECT *, " . account_name_sql('users') . " AS full_name FROM users
      WHERE $base" . ($roleFilter !== '' ? ' AND role = ?' : '') . '
      ORDER BY created_at DESC'
);
$stmt->execute($roleFilter !== '' ? [$roleFilter] : []);
$users = $stmt->fetchAll();

$roleBadges = [
    'administrator' => ['badge-primary', 'fa-user-cog', 'Administrator'],
    'landlord'      => ['badge-info', 'fa-user-tie', 'Landlord'],
    'boarder'       => ['badge-secondary', 'fa-user', 'Boarder'],
];

$pageTitle = 'All Users';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">

  <?php panel_page_header('All Users', [
    'subtitle' => 'Everyone who can sign in: administrators, landlords and boarders. Super admins are not listed.',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <div class="card card-primary card-outline shadow-sm">
        <div class="card-header card-header--split">
          <div style="min-width: 0;">
            <h3 class="card-title">User directory</h3>
            <span class="card-subtitle">Newest first. Select a name to open the page where that account is managed.</span>
          </div>
          <div class="btn-group" role="group" aria-label="Filter by role">
            <a href="all_users.php" class="btn btn-sm btn-outline-primary <?= $roleFilter === '' ? 'active' : '' ?>">
              All <span class="badge badge-light ml-1"><?= (int) $total ?></span>
            </a>
            <?php foreach ($roles as $key => $label): ?>
              <a href="all_users.php?role=<?= h($key) ?>"
                class="btn btn-sm btn-outline-primary <?= $roleFilter === $key ? 'active' : '' ?>">
                <?= h($label) ?> <span class="badge badge-light ml-1"><?= (int) ($counts[$key] ?? 0) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card-body">
          <table id="allUsersTable" class="table table-bordered table-striped table-hover">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                [$badge, $icon, $roleLabel] = $roleBadges[$u['role']];
                // Administrators have no page of their own; they are managed
                // from the Administrators list.
                $href = $u['role'] === 'administrator'
                    ? base_url('admin/admins.php')
                    : base_url('admin/user.php?id=' . (int) $u['user_id']);
                ?>
                <tr>
                  <td class="font-weight-bold">
                    <span class="d-flex align-items-center" style="gap: 8px;">
                      <?= avatar_html($u, 32) ?>
                      <span>
                        <a href="<?= h($href) ?>"><?= h($u['full_name']) ?></a>
                        <?php if ((string) $u['username'] !== ''): ?>
                          <span class="d-block text-muted text-sm font-weight-normal">@<?= h($u['username']) ?></span>
                        <?php endif; ?>
                      </span>
                    </span>
                  </td>
                  <td>
                    <?php if ((string) $u['email'] !== ''): ?>
                      <a href="mailto:<?= h($u['email']) ?>" class="text-muted"><?= h($u['email']) ?></a>
                    <?php else: ?>
                      <span class="text-muted">&mdash;</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($u['phone_number'] ?: '—') ?></td>
                  <td>
                    <span class="badge <?= $badge ?> px-2 py-1"><i class="fas <?= $icon ?> mr-1"></i> <?= $roleLabel ?></span>
                  </td>
                  <td>
                    <?php if ($u['is_active']): ?>
                      <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>
                    <?php else: ?>
                      <span class="badge badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Inactive</span>
                    <?php endif; ?>
                  </td>
                  <?php /* data-order sorts by the real date, not the words. */ ?>
                  <td class="text-sm text-muted" data-order="<?= h($u['created_at']) ?>">
                    <?= h(date('M j, Y', strtotime($u['created_at']))) ?>
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

<script>
  $(function () {
    $("#allUsersTable").DataTable({
      "responsive": true,
      "lengthChange": true,
      "autoWidth": false,
      "order": [[5, "desc"]],
      "pageLength": 10
    });
  });
</script>
