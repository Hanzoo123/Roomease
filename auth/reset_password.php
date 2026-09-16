<?php
/**
 * Step 3 of the password reset: set a new password.
 *
 * auth/verify_code.php puts a token in the session once the emailed code is
 * right; the token never appears in a URL or a form. It is validated on both
 * the GET (showing the form) and the POST (saving), so an expired or used
 * reset cannot be replayed by holding the page open. Using it marks it used,
 * and every other outstanding reset for that account is discarded.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$errors = [];
$done = false;

$state = $_SESSION['password_reset'] ?? null;

// A code was sent but not entered yet: that comes first.
if (is_array($state) && empty($state['token'])) {
    redirect('auth/verify_code.php');
}

$scope = is_array($state) ? $state['scope'] : 'public';
$reset = is_array($state) ? find_valid_reset($state['token'] ?? '') : null;

// Someone signed in can reset their own account: it is how an account made
// with Google sets its first password, from the profile page. Anyone signed
// in with another account's reset goes home.
$signedIn = is_logged_in();
if ($signedIn && $reset && (int) $reset['user_id'] !== (int) $_SESSION['user_id']) {
    redirect('index.php');
}

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

        // Burn this reset, and any other that was outstanding for the account,
        // so it can never be reused.
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?')
            ->execute([$reset['reset_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND reset_id <> ?')
            ->execute([$reset['user_id'], $reset['reset_id']]);
        unset($_SESSION['password_reset']);

        // A new password signs the account out of every remembered device, so
        // whoever prompted the reset loses any "Remember me" cookie they held.
        $rememberedHere = $signedIn && isset($_COOKIE[REMEMBER_COOKIE]);
        forget_all_remembered_logins($reset['user_id']);

        // Signed in, it counts as a password change: a new session id, and
        // this device stays remembered if it was (as on the profile page).
        if ($signedIn) {
            session_regenerate_id(true);
            if ($rememberedHere) {
                remember_login((int) $reset['user_id']);
            }
        }

        $done = true;
    }
}

// An administrator's reset came from the admin sign-in page, so it leads back
// there; everyone else goes to the public login.
$forAdmin = $reset ? $reset['role'] === 'administrator' : $scope === 'admin';
$loginPath = $forAdmin ? ADMIN_LOGIN_PATH : 'auth/login.php';

$pageTitle = 'Reset password';
$authHeading = $done ? 'Password changed' : (!$reset ? 'Code no longer valid' : 'Choose a new password');
$authAdmin = $forAdmin;
$authSwitch = $signedIn ? null : ['text' => 'Remembered your password?', 'href' => base_url($loginPath), 'label' => 'Log in'];
require __DIR__ . '/../includes/auth_header.php';
?>

<?php if ($done && $signedIn): ?>
  <div class="alert alert-success">Your password is saved. You can sign in with it from now on.</div>
  <a href="<?= base_url('auth/profile.php') ?>" class="btn btn-primary btn-block btn-auth">Back to your profile</a>

<?php elseif ($done): ?>
  <div class="alert alert-success">Your password has been changed. You can log in with it now.</div>
  <a href="<?= base_url($loginPath) ?>" class="btn btn-primary btn-block btn-auth">Go to log in</a>

<?php elseif (!$reset): ?>
  <div class="alert alert-error">
    This reset has expired or was already used. After the code is entered, there are
    <?= password_reset_ttl_minutes() ?> minutes to choose the new password.
  </div>
  <?php if ($signedIn): ?>
    <a href="<?= base_url('auth/profile.php') ?>" class="btn btn-primary btn-block btn-auth">Get a new code from your profile</a>
  <?php else: ?>
    <a href="<?= base_url(password_reset_start_path($forAdmin ? 'admin' : 'public')) ?>" class="btn btn-primary btn-block btn-auth">Get a new code</a>
  <?php endif; ?>

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

    <label for="new_password">New password</label>
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required autofocus>

    <label for="confirm_password">Confirm new password</label>
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>

    <button type="submit" class="btn btn-primary btn-block btn-auth">Set new password</button>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/auth_footer.php'; ?>
