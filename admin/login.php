<?php
/**
 * The administrators' own sign-in page.
 *
 * Only administrator accounts can sign in here, and the public login refuses
 * them, so each page only ever lets in the accounts it is for. A landlord or
 * boarder who tries this page gets the same "Invalid email or password" a
 * wrong password gets, so the page does not reveal which emails have which
 * role. No sign-up, Google, or "Remember me" here.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

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
        $error = 'Please enter both email and password.';
    } elseif ($retryAfter > 0) {
        $error = 'Too many failed sign-in attempts. Please try again in ' . format_wait($retryAfter) . '.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM users
              WHERE email = ? AND deleted_at IS NULL AND role = 'administrator'
              LIMIT 1"
        );
        $stmt->execute([$loginId]);
        $user = $stmt->fetch();

        if (!check_login_password($password, $user)) {
            record_failed_attempt('login', $loginId);
            $error = 'Invalid email or password.';
        } elseif (empty($user['is_active'])) {
            record_failed_attempt('login', $loginId);
            $error = 'Your account is deactivated. Please contact another administrator.';
        } else {
            clear_failed_attempts('login', $loginId);
            upgrade_password_hash($user, $password);
            start_user_session($user);
            flash_set('Welcome back, ' . $user['first_name'] . '!', 'success');
            redirect('admin/dashboard.php');
        }
    }
}

$pageTitle = 'Admin sign in';
$authHeading = 'Sign in to the admin panel';
$authAdmin = true;
require __DIR__ . '/../includes/layouts/auth_header.php';
?>

<?php if ($error): ?>
  <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <label for="login_id">Email address</label>
  <input type="email" id="login_id" name="login_id" value="<?= h($loginId) ?>" autocomplete="username" required autofocus>

  <label for="password">Password</label>
  <input type="password" id="password" name="password" autocomplete="current-password" required>

  <div class="auth-row auth-row--end">
    <a href="<?= base_url('admin/forgot_password.php') ?>">Forgot password?</a>
  </div>

  <button type="submit" class="btn btn-primary btn-block btn-auth">Sign in</button>
</form>

<?php require __DIR__ . '/../includes/layouts/auth_footer.php'; ?>
