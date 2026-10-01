<?php
/**
 * Password reset step 1: ask for an email and send a code. The next page
 * looks the same whether or not the email exists.
 * admin/forgot_password.php reuses this file with $resetScope = 'admin'.
 */
require __DIR__ . '/../includes/init.php';

$resetScope = ($resetScope ?? 'public') === 'admin' ? 'admin' : 'public';
$loginPath = $resetScope === 'admin' ? ADMIN_LOGIN_PATH : 'auth/login.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');

    // Limited like login, checked before looking up the account.
    $retryAfter = $email !== '' ? throttle_retry_after('reset', $email) : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($retryAfter > 0) {
        $error = 'Too many reset requests for that address. Please try again in '
            . format_wait($retryAfter) . '.';
    } else {
        record_failed_attempt('reset', $email);
        issue_password_reset_code($email, $resetScope);

        // Redirecting means a refresh of the next page cannot send another code.
        redirect('auth/verify_code.php');
    }
}

$pageTitle = $resetScope === 'admin' ? 'Admin password reset' : 'Forgot password';
$authHeading = $resetScope === 'admin' ? 'Reset your admin password' : 'Reset your password';
$authAdmin = $resetScope === 'admin';
$authSwitch = ['text' => 'Remembered it?', 'href' => base_url($loginPath), 'label' => 'Log in'];
require __DIR__ . '/../includes/layouts/auth_header.php';
?>

  <p class="auth-sub">Enter the email address on your account and we will email you a 6-digit code to choose a
    new password.</p>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= h($email) ?>" autocomplete="username" required autofocus>
    <button type="submit" class="btn btn-primary btn-block btn-auth">Send code</button>
  </form>

<?php require __DIR__ . '/../includes/layouts/auth_footer.php'; ?>
