<?php
/**
 * Set a new temporary password for another administrator. Super admins only.
 *
 * For an administrator who has forgotten their password and cannot use "I
 * forgot my password", such as one who has not added an email yet. The super
 * admin gives them the new password privately. Saving it signs the
 * administrator out everywhere: every session checks the password it was
 * signed in under (enforce_session_policy()), and remembered devices are
 * forgotten here.
 */
require __DIR__ . '/../includes/init.php';

require_super_admin();

$targetId = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'administrator' AND deleted_at IS NULL");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('Administrator not found.', 'error');
    redirect('admin/admins.php');
}
// Your own password is changed in My Profile, where the current one is asked.
if ($targetId === (int) $_SESSION['user_id']) {
    flash_set('Change your own password in My Profile, Change Password.', 'error');
    redirect('admin/admins.php');
}

$name = account_display_name($target);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $errors = array_values(array_filter([
        password_problem($password, 'Temporary password'),
        $password !== $confirm ? 'The two passwords do not match.' : null,
    ]));

    if (!$errors) {
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_by = ? WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), current_user_id(), $targetId]);
        forget_all_remembered_logins($targetId);
        audit_log('admin_password', $targetId, $name, 'Signed out everywhere');
        flash_set($name . ' has a new temporary password and was signed out everywhere. Give it to them privately.', 'success');
        redirect('admin/admins.php');
    }
}

$pageTitle = 'Reset Password';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">

  <?php panel_page_header('Reset Password', [
    'subtitle' => 'A new temporary password for ' . $name . '.',
    'back' => 'admin/admins.php',
    'backLabel' => 'Back to administrators',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row justify-content-center">
        <div class="col-lg-6">

          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-key mr-1"></i> <?= h($name) ?>
              </h3>
              <span class="card-subtitle">
                <?= (string) $target['username'] !== '' ? '@' . h($target['username']) : h((string) $target['email']) ?>
                &middot; They are signed out everywhere, and sign in again with the new password.
              </span>
            </div>

            <?php if ($errors): ?>
              <div class="card-body pb-0">
                <div class="alert alert-danger mb-0">
                  <h6 class="font-weight-bold mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Please fix the
                    following:</h6>
                  <ul class="mb-0 pl-3">
                    <?php foreach ($errors as $e): ?>
                      <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <form method="post" novalidate>
              <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int) $targetId ?>">

                <div class="form-group">
                  <label for="password">New temporary password</label>
                  <input type="password" class="form-control" id="password" name="password" required
                    autocomplete="new-password" autofocus>
                  <small class="form-text text-muted">8 to 72 characters. Ask them to change it after they sign in.</small>
                </div>
                <div class="form-group mb-0">
                  <label for="confirm_password">Confirm password</label>
                  <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                    autocomplete="new-password">
                </div>
              </div>
              <div class="card-footer d-flex flex-wrap justify-content-between" style="gap: 8px;">
                <a href="<?= base_url('admin/admins.php') ?>" class="btn btn-default">
                  <i class="fas fa-arrow-left mr-1"></i> Back to administrators
                </a>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-key mr-1"></i> Set password
                </button>
              </div>
            </form>
          </div>

        </div>
      </div>
    </div><!-- /.container-fluid -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
