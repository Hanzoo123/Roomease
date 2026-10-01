<?php
/**
 * Administrators list (super admins only). Add, deactivate, remove, promote
 * or reset the password of any admin except yourself.
 */
require __DIR__ . '/../includes/init.php';

require_super_admin();

$myId = (int) $_SESSION['user_id'];

// Super admins first, then by name. Removed ones stay listed, so they can be
// restored from here.
$admins = $pdo->query(
    "SELECT *, " . account_name_sql('users') . " AS full_name
       FROM users
      WHERE role = 'administrator'
      ORDER BY deleted_at IS NOT NULL, is_super_admin DESC, full_name"
)->fetchAll();

// When each one last signed in, from the audit log. Sign-ins are kept for 90
// days (audit_purge_old_signins()), so an older one shows as a dash.
$lastSignin = $pdo->query(
    "SELECT actor_id, MAX(created_at) FROM audit_logs
      WHERE action = 'signin' AND actor_role = 'administrator'
      GROUP BY actor_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Administrators';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';

/** One small form that posts an action for an administrator. */
$actionButton = function ($adminId, $action, $class, $icon, $title, $confirm = '') {
    return '<form method="post" action="' . h(base_url('admin/admin_action.php')) . '" class="d-inline'
        . ($confirm !== '' ? ' js-confirm" data-confirm="' . h($confirm) : '') . '">'
        . csrf_field()
        . '<input type="hidden" name="user_id" value="' . (int) $adminId . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<button type="submit" class="btn btn-xs ' . h($class) . '" title="' . h($title) . '" aria-label="' . h($title) . '">'
        . '<i class="fas ' . h($icon) . '"></i></button></form>';
};
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">

  <?php panel_page_header('Administrators', [
    'subtitle' => 'Everyone who can sign in to this panel. Only a super admin sees this page.',
    'actions' => '<a href="' . base_url('admin/add_user.php') . '" class="btn btn-sm btn-primary">'
      . '<i class="fas fa-user-plus mr-1"></i> Add User</a>',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">

      <div class="card card-primary card-outline shadow-sm">
        <?php panel_card_header('Administrator accounts',
          'A super admin can also add and manage administrators, and change the site\'s Appearance.'); ?>

        <div class="card-body table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last sign-in</th>
                <th>Added</th>
                <th style="width: 160px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($admins as $a): ?>
                <?php
                $id = (int) $a['user_id'];
                $isMe = $id === $myId;
                $name = $a['full_name'];
                // Added with only a username, and has not yet signed in to add a
                // name and email.
                $settingUp = trim($a['first_name'] . $a['last_name']) === '' || (string) $a['email'] === '';
                ?>
                <tr>
                  <td class="font-weight-bold">
                    <span class="d-flex align-items-center" style="gap: 8px;">
                      <?= avatar_html($a, 32) ?>
                      <span>
                        <?= h($name) ?>
                        <?php if ($isMe): ?><span class="badge badge-light">You</span><?php endif; ?>
                        <?php if ($settingUp): ?><span class="badge badge-warning">Setting up</span><?php endif; ?>
                        <?php if ((string) $a['username'] !== ''): ?>
                          <span class="d-block text-muted text-sm font-weight-normal">@<?= h($a['username']) ?></span>
                        <?php endif; ?>
                      </span>
                    </span>
                  </td>
                  <td>
                    <?php if ((string) $a['email'] !== ''): ?>
                      <a href="mailto:<?= h($a['email']) ?>" class="text-muted"><?= h($a['email']) ?></a>
                    <?php else: ?>
                      <span class="text-muted">&mdash;</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($a['is_super_admin']): ?>
                      <span class="badge badge-primary px-2 py-1"><i class="fas fa-user-shield mr-1"></i> Super admin</span>
                    <?php else: ?>
                      <span class="badge badge-info px-2 py-1"><i class="fas fa-user-cog mr-1"></i> Administrator</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($a['deleted_at'] !== null): ?>
                      <span class="badge badge-dark px-2 py-1"><i class="fas fa-archive mr-1"></i> Removed</span>
                    <?php elseif ($a['is_active']): ?>
                      <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>
                    <?php else: ?>
                      <span class="badge badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm text-muted">
                    <?= isset($lastSignin[$id]) ? h(time_ago($lastSignin[$id])) : '&mdash;' ?>
                  </td>
                  <td class="text-sm text-muted"><?= h(date('M j, Y', strtotime($a['created_at']))) ?></td>
                  <td>
                    <?php if ($isMe): ?>
                      <?php /* Nobody manages their own account from here; the rules
                               in admin_action.php refuse it anyway. */ ?>
                      <span class="text-muted text-sm">Your account</span>
                    <?php elseif ($a['deleted_at'] !== null): ?>
                      <?= $actionButton($id, 'restore', 'btn-outline-success', 'fa-trash-restore', 'Restore ' . $name) ?>
                    <?php else: ?>
                      <div class="d-flex align-items-center" style="gap: 5px;">
                        <?= $a['is_active']
                          ? $actionButton($id, 'deactivate', 'btn-outline-warning', 'fa-user-slash', 'Deactivate ' . $name)
                          : $actionButton($id, 'activate', 'btn-outline-success', 'fa-user-check', 'Activate ' . $name) ?>
                        <?= $a['is_super_admin']
                          ? $actionButton($id, 'demote', 'btn-outline-secondary', 'fa-user-minus', 'Remove super admin from ' . $name,
                              'Take super admin away from ' . $name . '? They stay an administrator.')
                          : $actionButton($id, 'promote', 'btn-outline-primary', 'fa-user-shield', 'Make ' . $name . ' a super admin',
                              'Make ' . $name . ' a super admin? They will be able to add and manage administrators.') ?>
                        <a href="<?= base_url('admin/reset_admin_password.php?id=' . $id) ?>" class="btn btn-xs btn-outline-dark"
                          title="<?= h('Set a new temporary password for ' . $name) ?>" aria-label="<?= h('Set a new temporary password for ' . $name) ?>">
                          <i class="fas fa-key"></i></a>
                        <?= $actionButton($id, 'remove', 'btn-outline-danger', 'fa-trash', 'Remove ' . $name,
                          'Remove ' . $name . '? They can no longer sign in. Nothing is deleted, and you can restore them here.') ?>
                      </div>
                    <?php endif; ?>
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
