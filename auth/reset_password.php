<?php
/**
 * Step 2 of the password reset: verify the token and set a new password.
 *
 * The token is validated on both the GET (showing the form) and the POST
 * (saving), so an expired or already-used link cannot be replayed by holding
 * the page open. Using a token marks it used, and every other outstanding
 * token for that account is discarded at the same time.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$errors = [];
$done = false;

// The token travels in the query string on the emailed link, and in a hidden
// field once the form is submitted.
$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['token'] ?? '')
    : ($_GET['token'] ?? '');

$reset = find_valid_reset($token);

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $reset['user_id']]);

        // Burn this token, and any other link that was outstanding for the
        // account, so a reset link can never be reused.
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?')
            ->execute([$reset['reset_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND reset_id <> ?')
            ->execute([$reset['user_id'], $reset['reset_id']]);

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password | RoomEase</title>

  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
  <style>
    body.login-page {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      min-height: 100vh;
    }

    .login-box {
      width: 420px;
    }

    .login-card-body {
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    }

    .login-logo a {
      color: #f8fafc !important;
      font-size: 1.8rem;
      letter-spacing: -0.5px;
    }
  </style>
</head>

<body class="hold-transition login-page">
  <div class="login-box">
    <div class="login-logo text-center mb-4">
      <a href="<?= base_url('index.php') ?>">
        <i class="fas fa-building text-primary mr-1"></i>
        <b>Room</b>Ease
      </a>
    </div>

    <div class="card card-outline card-primary">
      <div class="card-body login-card-body">

        <?php if ($done): ?>
          <div class="alert alert-success">
            <i class="fas fa-check-circle mr-1"></i>
            Your password has been changed. You can log in with it now.
          </div>
          <a href="<?= base_url('auth/login.php') ?>" class="btn btn-primary btn-block shadow-sm">
            <i class="fas fa-sign-in-alt mr-1"></i> Go to Log In
          </a>

        <?php elseif (!$reset): ?>
          <p class="login-box-msg font-weight-bold text-secondary pb-1">Link no longer valid</p>
          <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle mr-1"></i>
            This reset link is invalid, has already been used, or has expired.
            Reset links last <?= password_reset_ttl_minutes() ?> minutes.
          </div>
          <a href="<?= base_url('auth/forgot_password.php') ?>" class="btn btn-primary btn-block shadow-sm">
            <i class="fas fa-redo mr-1"></i> Request a New Link
          </a>
          <div class="text-center mt-4 pt-3 border-top">
            <a href="<?= base_url('auth/login.php') ?>" class="text-sm text-secondary">
              <i class="fas fa-arrow-left mr-1"></i> Back to Log In
            </a>
          </div>

        <?php else: ?>
          <p class="login-box-msg font-weight-bold text-secondary pb-1">Choose a new password</p>
          <p class="text-muted text-sm">
            Setting a new password for <strong><?= h($reset['email']) ?></strong>.
          </p>

          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <?php foreach ($errors as $e)
                echo '<i class="fas fa-exclamation-circle mr-1"></i> ' . h($e) . '<br>'; ?>
            </div>
          <?php endif; ?>

          <form action="" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <div class="form-group">
              <label for="new_password">New password</label>
              <input type="password" class="form-control" id="new_password" name="new_password"
                autocomplete="new-password" required>
              <small class="form-text text-muted">At least 8 characters.</small>
            </div>

            <div class="form-group">
              <label for="confirm_password">Confirm new password</label>
              <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block shadow-sm">
              <i class="fas fa-key mr-1"></i> Set New Password
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <script src="<?= base_url('assets/adminlte/plugins/jquery/jquery.min.js') ?>"></script>
  <script src="<?= base_url('assets/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= base_url('assets/adminlte/dist/js/adminlte.min.js') ?>"></script>
  <?php require __DIR__ . '/../includes/password_toggle.php'; ?>
</body>

</html>
