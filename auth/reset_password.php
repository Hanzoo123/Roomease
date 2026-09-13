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
$pageTitle = 'Reset Password';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad">
  <?php if ($done): ?>
    <h1>Password changed</h1>
    <div class="alert alert-success">Your password has been changed. You can log in with it now.</div>
    <a href="<?= base_url('auth/login.php') ?>" class="btn btn-primary btn-block">Go to log in</a>

  <?php elseif (!$reset): ?>
    <h1>Link no longer valid</h1>
    <div class="alert alert-error">
      This reset link is invalid, has already been used, or has expired.
      Reset links last <?= password_reset_ttl_minutes() ?> minutes.
    </div>
    <a href="<?= base_url('auth/forgot_password.php') ?>" class="btn btn-primary btn-block">Request a new link</a>
    <div class="auth-switch"><a href="<?= base_url('auth/login.php') ?>">&larr; Back to log in</a></div>

  <?php else: ?>
    <h1>Choose a new password</h1>
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

      <button type="submit" class="btn btn-primary btn-block">Set new password</button>
    </form>

    <div class="auth-switch"><a href="<?= base_url('auth/login.php') ?>">&larr; Back to log in</a></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
