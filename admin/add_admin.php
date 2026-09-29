<?php
/**
 * Add an administrator. Super admins only.
 *
 * The super admin types a temporary password and gives it to the new
 * administrator privately; the new administrator signs in at the admin login
 * and changes it in My Profile, Change Password. The account is created
 * active, and as a super admin only when that box is ticked.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_super_admin();

$errors = [];
$form = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone_number' => '', 'is_super_admin' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $text = function ($key) {
        return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    };
    $form = [
        'first_name' => $text('first_name'),
        'last_name' => $text('last_name'),
        'email' => $text('email'),
        'phone_number' => $text('phone_number'),
        'is_super_admin' => ($_POST['is_super_admin'] ?? '') === '1',
    ];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if ($form['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($form['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    $errors = array_merge($errors, array_filter([
        too_long($form['first_name'], 100, 'First name'),
        too_long($form['last_name'], 100, 'Last name'),
        too_long($form['email'], 150, 'Email'),
        $form['phone_number'] !== '' ? phone_problem($form['phone_number']) : null,
        password_problem($password, 'Temporary password'),
    ]));
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    // Any account, removed ones included, keeps its email: the column is unique.
    if (!$errors) {
        $check = $pdo->prepare('SELECT role, deleted_at FROM users WHERE email = ?');
        $check->execute([$form['email']]);
        if ($taken = $check->fetch()) {
            $errors[] = $taken['role'] === 'administrator'
                ? 'That email already belongs to an administrator' . ($taken['deleted_at'] !== null ? ' who was removed. Restore them instead.' : '.')
                : 'That email already belongs to a ' . $taken['role'] . ' account. Use a different email for the administrator.';
        }
    }

    if (!$errors) {
        $pdo->prepare(
            "INSERT INTO users (role, is_super_admin, first_name, last_name, email, password_hash, phone_number,
                                is_active, created_by, updated_by)
             VALUES ('administrator', ?, ?, ?, ?, ?, ?, 1, ?, ?)"
        )->execute([
            $form['is_super_admin'] ? 1 : 0,
            $form['first_name'],
            $form['last_name'],
            $form['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $form['phone_number'] !== '' ? $form['phone_number'] : null,
            current_user_id(),
            current_user_id(),
        ]);
        $newId = (int) $pdo->lastInsertId();
        $name = trim($form['first_name'] . ' ' . $form['last_name']);

        audit_log('admin_add', $newId, $name, 'As ' . ($form['is_super_admin'] ? 'a super admin' : 'an administrator')
            . ' (' . $form['email'] . ')');
        flash_set($name . ' was added. Give them the temporary password privately, and ask them to change it in '
            . 'My Profile, Change Password, after they sign in.', 'success');
        redirect('admin/admins.php');
    }
}

$pageTitle = 'Add Administrator';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">

  <?php panel_page_header('Add Administrator', [
    'subtitle' => 'A new account that can sign in to this panel.',
    'back' => 'admin/admins.php',
    'backLabel' => 'Back to administrators',
  ]); ?>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row justify-content-center">
        <div class="col-lg-8">

          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header">
              <h3 class="card-title font-weight-bold">
                <i class="fas fa-user-plus mr-1"></i> Administrator details
              </h3>
              <span class="card-subtitle">They sign in at the admin login with this email and the temporary password.</span>
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

                <div class="form-row">
                  <div class="col-md-6 form-group">
                    <label for="first_name">First name</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" maxlength="100" required
                      value="<?= h($form['first_name']) ?>" autocomplete="off" autofocus>
                  </div>
                  <div class="col-md-6 form-group">
                    <label for="last_name">Last name</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" maxlength="100" required
                      value="<?= h($form['last_name']) ?>" autocomplete="off">
                  </div>
                </div>

                <div class="form-row">
                  <div class="col-md-6 form-group">
                    <label for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="150" required
                      value="<?= h($form['email']) ?>" autocomplete="off">
                  </div>
                  <div class="col-md-6 form-group">
                    <label for="phone_number">Phone <span class="text-muted font-weight-normal">(optional)</span></label>
                    <input type="tel" class="form-control" id="phone_number" name="phone_number" maxlength="30"
                      value="<?= h($form['phone_number']) ?>" placeholder="e.g. 0917 123 4567" autocomplete="off">
                  </div>
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
                  <div class="custom-control custom-switch">
                    <input type="checkbox" class="custom-control-input" id="is_super_admin" name="is_super_admin" value="1"
                      <?= $form['is_super_admin'] ? 'checked' : '' ?>>
                    <label class="custom-control-label" for="is_super_admin">Make this a super admin</label>
                  </div>
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
                  <i class="fas fa-user-plus mr-1"></i> Add administrator
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
