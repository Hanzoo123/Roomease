<?php
/**
 * Change Password, on its own page.
 *
 * An account with a password changes it by typing the current one. An account
 * made with Google has a password nobody knows, so it gets the same emailed
 * code as "Forgot password" instead, sent to the account's own address: that
 * code is the proof, exactly as it is for a reset.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/core/google_auth.php';

require_login();

$userId = $_SESSION['user_id'];
$errors = [];

$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
  die('User not found.');
}

$googleLinked = !empty($user['google_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password_code') {
  verify_csrf();

  if (!$googleLinked) {
    redirect('auth/change_password.php');
  }

  $retryAfter = throttle_retry_after('reset', $user['email']);
  if ($retryAfter > 0) {
    flash_set('A code was already requested several times. Please try again in ' . format_wait($retryAfter) . '.', 'error');
    redirect('auth/change_password.php');
  }
  record_failed_attempt('reset', $user['email']);

  // If sending fails, a developer who turned on ROOMEASE_SHOW_RESET_CODES
  // still gets the code on the next page, as on "Forgot password"; anyone
  // else is told it failed.
  if (issue_password_reset_code($user['email'], 'profile') === false && !show_reset_codes_on_screen()) {
    unset($_SESSION['password_reset']);
    flash_set('The email could not be sent. Please try again later.', 'error');
    redirect('auth/change_password.php');
  }
  redirect('auth/verify_code.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$googleLinked) {
  verify_csrf();

  $currentPassword = $_POST['current_password'] ?? '';
  $newPassword = $_POST['new_password'] ?? '';
  $confirmPassword = $_POST['confirm_password'] ?? '';

  if ($currentPassword === '') {
    $errors[] = 'Enter your current password.';
  } elseif (!password_verify($currentPassword, $user['password_hash'])) {
    $errors[] = 'Incorrect current password.';
  }
  if (strlen($newPassword) < 8) {
    $errors[] = 'New password must be at least 8 characters.';
  }
  if ($newPassword !== $confirmPassword) {
    $errors[] = 'New passwords do not match.';
  }

  if (!$errors) {
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$newHash, $userId]);

    // A password change invalidates every other copy of this session, so
    // anyone who had already got hold of the old session id loses it. This
    // is the whole point of changing the password after a scare.
    session_regenerate_id(true);

    // Every other session of the account, in any browser, is signed out
    // on its next page, because the password it was signed in under is
    // gone (see enforce_session_policy()). This one records the new
    // password, so it is the one that stays signed in.
    $_SESSION['password_fingerprint'] = password_fingerprint($newHash);

    // Same for "Remember me": every remembered device is forgotten. This
    // device keeps being remembered if it already was.
    $rememberedHere = isset($_COOKIE[REMEMBER_COOKIE]);
    forget_all_remembered_logins($userId);
    if ($rememberedHere) {
      remember_login((int) $userId);
    }

    flash_set('Your password was changed. Any other device signed in to this account has been signed out.', 'success');
    redirect('auth/profile.php');
  }
}

$pageTitle = 'Change Password';
$profileSubtitle = $googleLinked
  ? 'You sign in with Google. A password is optional.'
  : 'Choose a new password. Other devices are signed out when it changes.';
$profileBack = ['href' => 'auth/profile.php', 'label' => 'Back to my profile'];
require __DIR__ . '/../includes/layouts/profile_top.php';
?>

<div
  class="<?= $usePanel ? 'card profile-card profile-card--narrow' : 'panel panel-pad on-seam profile-card profile-card--narrow' ?>">
  <?php if ($usePanel): ?>
    <div class="card-header">
      <h3 class="card-title">Password</h3>
      <span
        class="card-subtitle"><?= $googleLinked ? 'Set one with a code sent to your email.' : 'You will stay signed in on this device.' ?></span>
    </div>
    <div class="card-body">
    <?php endif; ?>

    <?php if ($googleLinked): ?>
      <p class="profile-google-note profile-google-note--lead">
        <?= google_logo_svg(18) ?> You sign in with &ldquo;Continue with Google&rdquo;.
      </p>
      <p class="profile-text">
        There is no password to change here. If you want one as well, so you can also sign in with
        your email, we will send a code to <strong><?= h($user['email']) ?></strong>. The same code
        changes a password you set this way before.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password_code">
        <div class="profile-form-actions">
          <a href="<?= base_url('auth/profile.php') ?>" class="<?= $cls['btn_quiet'] ?>">Cancel</a>
          <button type="submit" class="<?= $usePanel ? 'btn btn-primary' : 'btn btn-accent' ?>">Email me a code</button>
        </div>
      </form>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="<?= $cls['alert'] ?>">
          <?php foreach ($errors as $e)
            echo h($e) . '<br>'; ?>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>

        <?php foreach ([
          ['current_password', 'Current password', 'current-password', ''],
          ['new_password', 'New password', 'new-password', 'At least 8 characters.'],
          ['confirm_password', 'Confirm new password', 'new-password', ''],
        ] as [$field, $label, $autocomplete, $hint]): ?>
          <div class="<?= $cls['group'] ?>">
            <label for="<?= $field ?>"><?= $label ?></label>
            <div class="password-field">
              <input type="password" class="<?= $cls['input'] ?>" id="<?= $field ?>" name="<?= $field ?>"
                autocomplete="<?= $autocomplete ?>" required<?= $hint ? ' aria-describedby="' . $field . '-hint"' : '' ?>>
              <?php /* Only shown once the script below is running, since without it
                the button would do nothing. */ ?>
              <button type="button" class="password-toggle" data-password-toggle="<?= $field ?>"
                aria-controls="<?= $field ?>" aria-pressed="false" hidden>Show</button>
            </div>
            <?php if ($hint): ?>
              <p class="<?= $cls['hint'] ?> password-hint" id="<?= $field ?>-hint"><?= $hint ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="profile-form-actions">
          <a href="<?= base_url('auth/profile.php') ?>" class="<?= $cls['btn_quiet'] ?>">Cancel</a>
          <button type="submit" class="<?= $usePanel ? 'btn btn-primary' : 'btn btn-accent' ?>">Change password</button>
        </div>
      </form>

      <script>
        // Show / Hide beside each field: a typo in a password nobody can see is
        // the usual reason "New passwords do not match".
        (function () {
          document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) return;
            button.hidden = false;
            button.addEventListener('click', function () {
              var show = input.type === 'password';
              input.type = show ? 'text' : 'password';
              button.textContent = show ? 'Hide' : 'Show';
              button.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
          });
        })();
      </script>
    <?php endif; ?>

    <?php if ($usePanel): ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layouts/profile_bottom.php'; ?>