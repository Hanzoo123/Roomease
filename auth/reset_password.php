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

        // A new password signs the account out of every remembered device, so
        // whoever prompted the reset loses any "Remember me" cookie they held.
        forget_all_remembered_logins($reset['user_id']);

        $done = true;
    }
}

// An administrator's link came from the admin sign-in page, so it leads back
// there; everyone else goes to the public login.
$forAdmin = $reset && $reset['role'] === 'administrator';
$loginPath = $forAdmin ? ADMIN_LOGIN_PATH : 'auth/login.php';

$pageTitle = 'Reset password';
$authHeading = $done ? 'Password changed' : (!$reset ? 'Link no longer valid' : 'Choose a new password');
$authAdmin = $forAdmin;
$authSwitch = ['text' => 'Remembered your password?', 'href' => base_url($loginPath), 'label' => 'Log in'];
require __DIR__ . '/../includes/auth_header.php';
?>

<?php if ($done): ?>
  <div class="alert alert-success">Your password has been changed. You can log in with it now.</div>
  <a href="<?= base_url($loginPath) ?>" class="btn btn-primary btn-block btn-auth">Go to log in</a>

<?php elseif (!$reset): ?>
  <div class="alert alert-error">
    This reset link is invalid, has already been used, or has expired.
    Reset links last <?= password_reset_ttl_minutes() ?> minutes.
  </div>
  <a href="<?= base_url('auth/forgot_password.php') ?>" class="btn btn-primary btn-block btn-auth">Request a new link</a>

<?php else: ?>
  <p class="auth-sub">Setting a new password for <strong><?= h($reset['email']) ?></strong>.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e)
        echo h($e) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">

    <label for="new_password">New password</label>
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required autofocus>

    <label for="confirm_password">Confirm new password</label>
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>

    <button type="submit" class="btn btn-primary btn-block btn-auth">Set new password</button>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/auth_footer.php'; ?>
