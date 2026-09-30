<?php
/**
 * Add User: a new administrator account. Super admins only.
 *
 * Landlords and boarders sign up for themselves, so the only accounts made
 * here are administrators, with a Role of Administrator or Super admin. The
 * super admin gives only a username, a temporary password and the role. The
 * new administrator signs in with the username and adds their own name and
 * email before anything else (require_login() keeps them on Edit Profile
 * until they do), then changes the password in My Profile, Change Password.
 */
require __DIR__ . '/../includes/init.php';

require_super_admin();

$errors = [];
$form = ['username' => '', 'role' => 'administrator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form = [
        'username' => is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '',
        'role' => is_string($_POST['role'] ?? null) ? $_POST['role'] : '',
    ];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $errors = array_values(array_filter([
        username_problem($form['username']),
        password_problem($password, 'Temporary password'),
        $password !== $confirm ? 'The two passwords do not match.' : null,
    ]));
    if (!in_array($form['role'], ['administrator', 'super_admin'], true)) {
        $errors[] = 'Choose a role from the list.';
        $form['role'] = 'administrator';
    }
    $isSuper = $form['role'] === 'super_admin';

    if (!$errors) {
        // No name or email yet: the new administrator adds both on first
        // sign-in. email stays NULL until then, which its unique key allows.
        $pdo->prepare(
            "INSERT INTO users (role, is_super_admin, username, first_name, last_name, email, password_hash,
                                is_active, created_by, updated_by)
             VALUES ('administrator', ?, ?, '', '', NULL, ?, 1, ?, ?)"
        )->execute([
            $isSuper ? 1 : 0,
            $form['username'],
            password_hash($password, PASSWORD_DEFAULT),
            current_user_id(),
            current_user_id(),
        ]);
        $newId = (int) $pdo->lastInsertId();

        audit_log('admin_add', $newId, $form['username'], 'As ' . ($isSuper ? 'a super admin' : 'an administrator'));
        flash_set($form['username'] . ' was added. Give them the username and temporary password privately. '
            . 'When they first sign in, they will add their name and email.', 'success');
        redirect('admin/admins.php');
    }
}

$pageTitle = 'Add User';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">

  <?php panel_page_header('Add User', [
    'subtitle' => 'A new administrator who can sign in to this panel. Landlords and boarders sign up for themselves.',
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
                <i class="fas fa-user-plus mr-1"></i> User details
              </h3>
              <span class="card-subtitle">They add their own name and email the first time they sign in.</span>
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

                <div class="form-group">
                  <label for="username">Username</label>
                  <input type="text" class="form-control" id="username" name="username" maxlength="30" required
                    value="<?= h($form['username']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false"
                    placeholder="e.g. juan.delacruz" autofocus>
                  <small class="form-text text-muted">3 to 30 letters, numbers, dots or underscores. They sign in with it.</small>
                </div>

                <div class="form-row">
                  <div class="col-md-6 form-group">
                    <label for="password">Temporary password</label>
                    <input type="password" class="form-control" id="password" name="password" required
                      autocomplete="new-password">
                    <small class="form-text text-muted">8 to 72 characters. Give it to them privately.</small>
                  </div>
                  <div class="col-md-6 form-group">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                      autocomplete="new-password">
                  </div>
                </div>

                <div class="form-group mb-0">
                  <label for="role">Role</label>
                  <select class="form-control" id="role" name="role" required>
                    <option value="administrator" <?= $form['role'] === 'administrator' ? 'selected' : '' ?>>Administrator</option>
                    <option value="super_admin" <?= $form['role'] === 'super_admin' ? 'selected' : '' ?>>Super admin</option>
                  </select>
                  <small class="form-text text-muted">
                    A super admin can also add and manage administrators, and change the site's Appearance.
                  </small>
                </div>
              </div>
              <div class="card-footer d-flex flex-wrap justify-content-between" style="gap: 8px;">
                <a href="<?= base_url('admin/admins.php') ?>" class="btn btn-default">
                  <i class="fas fa-arrow-left mr-1"></i> Back to administrators
                </a>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-user-plus mr-1"></i> Add user
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
