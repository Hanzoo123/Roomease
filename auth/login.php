<?php
/**
 * RoomEase Admin Portal Login (AdminLTE styled)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// If already logged in as admin, redirect to admin dashboard
if (is_logged_in() && current_role() === 'admin') {
    redirect('admin/dashboard.php');
}

$error = '';
$loginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $loginId = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($loginId) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :login_id LIMIT 1");
        $stmt->execute([':login_id' => $loginId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = "Invalid email or password.";
        } elseif ($user['role'] !== 'administrator') {
            $error = "Access denied. Only administrators are allowed to access this portal.";
        } elseif (empty($user['is_active'])) {
            $error = "This administrator account is deactivated. Please contact support.";
        } else {
            // Valid admin login
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];
            $_SESSION['full_name']  = trim($user['first_name'] . ' ' . $user['last_name']);
            $_SESSION['email']      = $user['email'];

            flash_set("Welcome back, " . h($user['first_name']) . "!", "success");
            redirect('admin/dashboard.php');
        }
    }
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Portal Login | RoomEase</title>

  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/fontawesome-free/css/all.min.css') ?>">
  <!-- icheck bootstrap -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/icheck-bootstrap/icheck-bootstrap.min.css') ?>">
  <!-- Toastr CSS -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/plugins/toastr/toastr.min.css') ?>">
  <!-- Theme style -->
  <link rel="stylesheet" href="<?= base_url('AdminLTE/dist/css/adminlte.min.css') ?>">
  <style>
    body.login-page {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      min-height: 100vh;
    }
    .login-box {
      width: 400px;
    }
    .login-card-body {
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    }
    .login-logo a {
      color: #f8fafc !important;
      font-size: 1.8rem;
      letter-spacing: -0.5px;
    }
    .login-logo small {
      display: block;
      font-size: 0.85rem;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: #94a3b8;
      margin-top: 4px;
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
    <small><i class="fas fa-shield-alt mr-1"></i> </small>
  </div>
  <!-- /.login-logo -->

  <div class="card card-outline card-primary">
    <div class="card-body login-card-body">
      <p class="login-box-msg font-weight-bold text-secondary pb-1">Sign in</p>
      

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-circle mr-1"></i> <?= h($error) ?>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <form action="" method="post">
        <?= csrf_field() ?>
        <div class="input-group mb-3">
          <input type="email" name="login_id" class="form-control" placeholder="Email Address" required autofocus
                 value="<?= h($loginId) ?>">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-envelope"></span>
            </div>
          </div>
        </div>

        <div class="input-group mb-3">
          <input type="password" name="password" class="form-control" placeholder="Password" required>
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-lock"></span>
            </div>
          </div>
        </div>

        <div class="row mt-4">
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-block shadow-sm">
              <i class="fas fa-sign-in-alt mr-1"></i> Log In
            </button>
          </div>
        </div>
      </form>

      <div class="text-center mt-4 pt-3 border-top">
        <a href="<?= base_url('index.php') ?>" class="text-sm text-secondary">
          <i class="fas fa-arrow-left mr-1"></i> Return to Main Website
        </a>
      </div>
    </div>
    <!-- /.login-card-body -->
  </div>
  <!-- /.card -->
</div>
<!-- /.login-box -->

<!-- jQuery -->
<script src="<?= base_url('AdminLTE/plugins/jquery/jquery.min.js') ?>"></script>
<!-- Bootstrap 4 -->
<script src="<?= base_url('AdminLTE/plugins/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<!-- Toastr JS -->
<script src="<?= base_url('AdminLTE/plugins/toastr/toastr.min.js') ?>"></script>
<!-- AdminLTE App -->
<script src="<?= base_url('AdminLTE/dist/js/adminlte.min.js') ?>"></script>

<?php if ($flash): ?>
<script>
  $(function() {
    toastr.options = {
      "closeButton": true,
      "progressBar": true,
      "positionClass": "toast-top-center",
      "timeOut": "5000"
    };
    <?php if ($flash['type'] === 'error'): ?>
      toastr.error(<?= json_encode($flash['message']) ?>);
    <?php else: ?>
      toastr.success(<?= json_encode($flash['message']) ?>);
    <?php endif; ?>
  });
</script>
<?php endif; ?>

</body>
</html>
