<?php
/**
 * Password reset step 3: choose a new password. Uses the token that
 * verify_code.php put in the session (never in the URL). The token is checked
 * on show and on save, and works once.
 */
require __DIR__ . '/../includes/init.php';

$errors = [];
$done = false;

$state = $_SESSION['password_reset'] ?? null;

// A code was sent but not entered yet: that comes first.
if (is_array($state) && empty($state['token'])) {
    redirect('auth/verify_code.php');
}

$scope = is_array($state) ? $state['scope'] : 'public';
$reset = is_array($state) ? find_valid_reset($state['token'] ?? '') : null;

// Signed in: only your own account (how Google accounts set a first password).
$signedIn = is_logged_in();
if ($signedIn && $reset && (int) $reset['user_id'] !== (int) $_SESSION['user_id']) {
    redirect('index.php');
}

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($problem = password_problem($newPassword, 'New password')) {
        $errors[] = $problem;
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_by = ? WHERE user_id = ?')
            ->execute([$newHash, $reset['user_id'], $reset['user_id']]);
        // Chosen by the owner of the email, so no longer a temporary one.
        set_password_change_required($reset['user_id'], false);
        audit_log('password_reset', $reset['user_id'], $reset['email'],
            ($signedIn ? 'From the profile' : 'With Forgot password') . ', by emailed code',
            ['user_id' => $reset['user_id'], 'role' => $reset['role']]);

        // Burn this reset, and any other that was outstanding for the account,
        // so it can never be reused.
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?')
            ->execute([$reset['reset_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND reset_id <> ?')
            ->execute([$reset['user_id'], $reset['reset_id']]);
        unset($_SESSION['password_reset']);

        // Sign out all remembered devices; open sessions end on their next page.
        $rememberedHere = $signedIn && isset($_COOKIE[REMEMBER_COOKIE]);
        forget_all_remembered_logins($reset['user_id']);

        // If signed in: new session id, and this device stays signed in.
        if ($signedIn) {
            session_regenerate_id(true);
            $_SESSION['password_fingerprint'] = password_fingerprint($newHash);
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
require __DIR__ . '/../includes/layouts/auth_header.php';
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
    <a href="<?= base_url('auth/change_password.php') ?>" class="btn btn-primary btn-block btn-auth">Get a new code</a>
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

<?php require __DIR__ . '/../includes/layouts/auth_footer.php'; ?>
