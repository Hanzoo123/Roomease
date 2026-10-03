<?php
/**
 * Change Password. Needs the current password. Google accounts (whose
 * password nobody knows) get an emailed code instead.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
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

  // If the email fails, say so (unless the dev setting shows the code on screen).
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
  if ($problem = password_problem($newPassword, 'New password')) {
    $errors[] = $problem;
  }
  if ($newPassword !== $confirmPassword) {
    $errors[] = 'New passwords do not match.';
  }
  // Otherwise a temporary password could be "replaced" with itself.
  if (!$errors && password_verify($newPassword, $user['password_hash'])) {
    $errors[] = 'Choose a new password that is different from your current one.';
  }

  if (!$errors) {
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ?, updated_by = ? WHERE user_id = ?')->execute([$newHash, $userId, $userId]);
    // Their own password now, so the panel opens up again (require_login()).
    set_password_change_required($userId, false);
    $_SESSION['must_change_password'] = false;
    audit_log('password_change', $userId, $user['email'], 'Other devices signed out');

    // New session id, so a stolen old one stops working.
    session_regenerate_id(true);

    // Other sessions are signed out on their next page; this one stays signed in.
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
  class="<?= $usePanel ? 'card profile-card profile-card--narrow' : 'panel panel-pad profile-card profile-card--narrow' ?>">
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
          <button type="submit" class="<?= $cls['btn'] ?>">Email me a code</button>
        </div>
      </form>
    <?php else: ?>
      <?php if (password_change_required()): ?>
        <div class="alert alert-info">
          Your password was set by a super admin. Choose your own to continue, using the temporary one as your
          current password.
        </div>
      <?php endif; ?>

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
          <button type="submit" class="<?= $cls['btn'] ?>">Change password</button>
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