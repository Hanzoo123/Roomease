<?php
/**
 * Step 1 of the password reset: ask for an email address and issue a token.
 *
 * The response is deliberately the same whether or not the address belongs to
 * an account, so this page cannot be used to find out who is registered.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$submitted = false;
$email = '';
$localLink = null;   // only ever populated for requests from this machine
$mailSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $submitted = true;

        // Deactivated accounts get no token, but the page says the same thing
        // either way so nothing about the account is revealed.
        $stmt = $pdo->prepare('SELECT user_id, first_name, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['is_active']) {
            $token = create_password_reset($user['user_id']);
            $url = password_reset_url($token);
            $mailSent = send_password_reset_email($email, $user['first_name'], $url);

            // A stock WAMP install has no mail server. Rather than leave the
            // flow dead, show the link directly, but only to someone sitting at
            // this machine. A remote visitor never sees it.
            if (!$mailSent && is_local_request()) {
                $localLink = $url;
            }
        }
    }
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password | RoomEase</title>

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

    .reset-link-box {
      word-break: break-all;
      font-size: 12px;
      background: #f8fafc;
      border: 1px dashed #94a3b8;
      border-radius: 6px;
      padding: 10px;
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
        <p class="login-box-msg font-weight-bold text-secondary pb-1">Forgot your password?</p>

        <?php if ($submitted): ?>
          <div class="alert alert-success">
            <i class="fas fa-check-circle mr-1"></i>
            If an account exists for <strong><?= h($email) ?></strong>, a reset link has been sent to it.
            The link is valid for <?= password_reset_ttl_minutes() ?> minutes.
          </div>

          <?php if ($localLink !== null): ?>
            <div class="alert alert-warning">
              <h6 class="font-weight-bold mb-2">
                <i class="fas fa-tools mr-1"></i> No mail server on this machine
              </h6>
              <p class="mb-2 small">
                This server could not send email, so the link is shown here instead. This only ever
                appears for someone browsing from the server itself &mdash; a remote visitor sees only the
                message above.
              </p>
              <div class="reset-link-box">
                <a href="<?= h($localLink) ?>"><?= h($localLink) ?></a>
              </div>
            </div>
          <?php endif; ?>

          <div class="text-center mt-4 pt-3 border-top">
            <a href="<?= base_url('auth/login.php') ?>" class="text-sm text-secondary">
              <i class="fas fa-arrow-left mr-1"></i> Back to Log In
            </a>
          </div>

        <?php else: ?>
          <p class="text-muted text-sm">
            Enter the email address on your account and we will send you a link to choose a new password.
          </p>

          <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
              <i class="fas fa-exclamation-circle mr-1"></i> <?= h($error) ?>
            </div>
          <?php endif; ?>

          <form action="" method="post">
            <?= csrf_field() ?>
            <div class="input-group mb-3">
              <input type="email" name="email" class="form-control" placeholder="Email Address" required autofocus
                autocomplete="username" value="<?= h($email) ?>">
              <div class="input-group-append">
                <div class="input-group-text">
                  <span class="fas fa-envelope"></span>
                </div>
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block shadow-sm">
              <i class="fas fa-paper-plane mr-1"></i> Send Reset Link
            </button>
          </form>

          <div class="text-center mt-4 pt-3 border-top">
            <a href="<?= base_url('auth/login.php') ?>" class="text-sm text-secondary">
              <i class="fas fa-arrow-left mr-1"></i> Back to Log In
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="<?= base_url('assets/adminlte/plugins/jquery/jquery.min.js') ?>"></script>
  <script src="<?= base_url('assets/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= base_url('assets/adminlte/dist/js/adminlte.min.js') ?>"></script>
</body>

</html>
