<?php
/**
 * The administrators' own sign-in page.
 *
 * Only administrator accounts can sign in here, and the public login refuses
 * them, so each page only ever lets in the accounts it is for. A landlord or
 * boarder who tries this page gets the same "Invalid username, email or
 * password" a wrong password gets, so the page does not reveal which emails
 * have which role. No sign-up, Google, or "Remember me" here.
 *
 * An administrator signs in with their username or their email. A new one
 * may have only a username until they add their email (admin/add_user.php).
 */
require __DIR__ . '/../includes/init.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/dashboard.php' : 'index.php');
}

$error = '';
$loginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $loginId = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    $retryAfter = $loginId !== '' ? throttle_retry_after('login', $loginId) : 0;

    if ($loginId === '' || $password === '') {
        $error = 'Please enter both your username or email and your password.';
    } elseif ($retryAfter > 0) {
        audit_log('signin_failed', null, audit_typed_login($loginId), 'Admin sign-in blocked: too many attempts', []);
        $error = 'Too many failed sign-in attempts. Please try again in ' . format_wait($retryAfter) . '.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM users
              WHERE (email = ? OR username = ?) AND deleted_at IS NULL AND role = 'administrator'
              LIMIT 1"
        );
        $stmt->execute([$loginId, $loginId]);
        $user = $stmt->fetch();
        // How the account is named in the log: its email, or its username
        // while it has no email yet.
        $accountLabel = $user ? (string) ($user['email'] ?: $user['username']) : '';

        if (!check_login_password($password, $user)) {
            record_failed_attempt('login', $loginId);
            // An address with no account behind it is logged with no actor, so
            // the log shows what was typed without inventing who typed it.
            audit_log('signin_failed', $user ? $user['user_id'] : null, $user ? $accountLabel : audit_typed_login($loginId),
                'Admin sign-in: ' . ($user ? 'wrong password' : 'no such account'), $user ?: []);
            $error = 'Invalid username, email or password.';
        } elseif (empty($user['is_active'])) {
            record_failed_attempt('login', $loginId);
            audit_log('signin_failed', $user['user_id'], $accountLabel, 'Admin sign-in: account deactivated', $user);
            $error = 'Your account is deactivated. Please contact another administrator.';
        } else {
            clear_failed_attempts('login', $loginId);
            // The session must start from the hash stored now, which the
            // upgrade may just have replaced; see start_user_session().
            $user['password_hash'] = upgrade_password_hash($user, $password);
            start_user_session($user);
            audit_log('signin', $user['user_id'], $accountLabel, 'Password');
            flash_set('Welcome back, ' . account_display_name($user) . '!', 'success');
            redirect('admin/dashboard.php');
        }
    }
}

// The page is AdminLTE's own login page, as the panel behind it is AdminLTE:
// its grey background, text logo, card and olive button, with every message
// shown as a toastr pop-up at the top. It stands alone rather than using the
// public sign-in layout (includes/layouts/auth_header.php).
//
// A message to show: this page's own error, or one carried over from another
// page, such as "You have been logged out" or a finished password reset.
$toasts = [];
if ($error !== '') {
    $toasts[] = ['type' => 'error', 'message' => $error];
}
if ($flash = flash_get()) {
    $toasts[] = [
        'type' => in_array($flash['type'], ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info',
        'message' => $flash['message'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RoomEase | Log in</title>
  <meta name="robots" content="noindex, nofollow">
  <?php $metaSocial = false; require __DIR__ . '/../includes/components/head_meta.php'; ?>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css') ?>">
  <!-- Theme style -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
  <!-- Toastr -->
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/toastr/toastr.min.css') ?>">
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <a href="<?= base_url('admin/login.php') ?>"><b>Room</b>Ease</a>
  </div>
  <!-- /.login-logo -->
  <div class="card">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Sign in to start your session</p>

      <form action="" method="post">
        <?= csrf_field() ?>
        <div class="input-group mb-3">
          <input type="text" name="login_id" class="form-control" placeholder="Username or Email" aria-label="Username or Email"
                 value="<?= h($loginId) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-user"></span>
            </div>
          </div>
        </div>
        <div class="input-group mb-3">
          <input type="password" name="password" class="form-control" placeholder="Password" aria-label="Password"
                 autocomplete="current-password" required>
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-lock"></span>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-12">
            <button type="submit" class="btn bg-olive btn-block">Sign In</button>
          </div>
          <!-- /.col -->
        </div>
      </form>

      <p class="mb-0 mt-3">
        <a href="<?= base_url('admin/forgot_password.php') ?>">I forgot my password</a>
      </p>
    </div>
    <!-- /.login-card-body -->
  </div>
</div>
<!-- /.login-box -->

<!-- jQuery -->
<script src="<?= base_url('assets/adminlte/plugins/jquery/jquery.min.js') ?>"></script>
<!-- Bootstrap 4 -->
<script src="<?= base_url('assets/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<!-- AdminLTE App -->
<script src="<?= base_url('assets/adminlte/dist/js/adminlte.min.js') ?>"></script>
<!-- Toastr -->
<script src="<?= base_url('assets/adminlte/plugins/toastr/toastr.min.js') ?>"></script>

<?php if ($toasts): ?>
<script>
  $(document).ready(function () {
    toastr.options = {
      "closeButton": true,
      "progressBar": true,
      "positionClass": "toast-top-center",
      "timeOut": "5000"
    };
    <?php /* json_encode with the HEX flags keeps any quote or tag in a message
             from ever closing the string or the script. */ ?>
    <?php foreach ($toasts as $toast): ?>
    toastr[<?= json_encode($toast['type']) ?>](<?= json_encode($toast['message'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    <?php endforeach; ?>
  });
</script>
<?php endif; ?>

</body>
</html>
