<?php
/**
 * Step 1 of the password reset: ask for an email address and send a code.
 *
 * The next page says the same thing whether or not the address belongs to an
 * account, so this page cannot be used to find out who is registered.
 *
 * Administrators and everyone else reset separately: admin/forgot_password.php
 * sets $resetScope = 'admin' and includes this file. The public page never
 * sends a code to an administrator account, and the admin page only ever
 * sends one to an administrator account.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

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

    // Issuing a code sends mail and writes to the database, so it is throttled
    // the same way login is. The limit is counted before the account is looked
    // up, so a locked-out requester learns nothing about who is registered.
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
