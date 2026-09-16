<?php
/**
 * Step 2 of the password reset: enter the 6-digit code from the email.
 *
 * The attempt this page works on lives in $_SESSION['password_reset'], set by
 * the page that sent the code: "Forgot password" (public or admin) or the
 * profile page. A right code is exchanged for a token kept in the session, and
 * the visitor moves on to choose the new password.
 *
 * Nothing here says whether the address has an account. Wrong guesses are
 * counted in the session as well as against the code, so a made-up address
 * runs out of tries exactly like a real one.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

$state = $_SESSION['password_reset'] ?? null;

if (!is_array($state)) {
    redirect('auth/forgot_password.php');
}
if (!empty($state['token'])) {
    redirect('auth/reset_password.php');
}

$scope = $state['scope'];
$startPath = password_reset_start_path($scope);

// A profile code belongs to the signed-in account; the other two to someone
// signed out, as on "Forgot password" itself.
if ($scope === 'profile') {
    if (!is_logged_in() || (int) $state['user_id'] !== (int) $_SESSION['user_id']) {
        unset($_SESSION['password_reset']);
        redirect(is_logged_in() ? 'auth/profile.php' : 'auth/login.php');
    }
} elseif (is_logged_in()) {
    unset($_SESSION['password_reset']);
    redirect('index.php');
}

$error = '';
$resendWait = max(0, RESET_CODE_RESEND_SECONDS - (time() - (int) $state['sent_at']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['action'] ?? '') === 'resend') {
        $retryAfter = throttle_retry_after('reset', $state['email']);

        if ($resendWait > 0) {
            $error = 'Please wait ' . format_wait($resendWait) . ' before asking for another code.';
        } elseif ($retryAfter > 0) {
            $error = 'Too many codes were requested for this address. Please try again in '
                . format_wait($retryAfter) . '.';
        } else {
            record_failed_attempt('reset', $state['email']);
            $sent = issue_password_reset_code($state['email'], $scope);

            // Only the profile page may admit that sending failed: there the
            // address is the visitor's own account.
            if ($scope === 'profile' && $sent === false && !is_local_request()) {
                flash_set('The email could not be sent. Please try again later.', 'error');
            } else {
                flash_set('A new code is on its way. Only the newest code works.');
            }
            redirect('auth/verify_code.php');
        }
    } else {
        // People paste codes with spaces or dashes; only the digits count.
        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));

        if (strlen($code) !== 6) {
            $error = 'Enter the 6-digit code from the email.';
        } elseif (time() - (int) $state['sent_at'] > password_reset_ttl_minutes() * 60) {
            $error = 'This code has expired. Send yourself a new one below.';
        } else {
            $account = password_reset_account($state['email'], $scope);
            $token = $account ? redeem_password_reset_code($account['user_id'], $code) : null;

            if ($token !== null) {
                $_SESSION['password_reset']['token'] = $token;
                $_SESSION['password_reset']['local_code'] = null;
                redirect('auth/reset_password.php');
            }

            $tries = (int) $state['tries'] + 1;
            if ($tries >= RESET_CODE_MAX_TRIES) {
                unset($_SESSION['password_reset']);
                flash_set('That code was entered wrong too many times, so it no longer works. Ask for a new one.', 'error');
                redirect($startPath);
            }

            $_SESSION['password_reset']['tries'] = $tries;
            $left = RESET_CODE_MAX_TRIES - $tries;
            $error = 'That code is not right. You have ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.';
        }
    }
}

$minutes = password_reset_ttl_minutes();

$pageTitle = 'Enter your code';
$authHeading = 'Check your email';
$authAdmin = $scope === 'admin';
$authSwitch = $scope === 'profile'
    ? ['text' => 'Changed your mind?', 'href' => base_url('auth/profile.php'), 'label' => 'Back to your profile']
    : ['text' => 'Remembered it?', 'href' => base_url($scope === 'admin' ? ADMIN_LOGIN_PATH : 'auth/login.php'), 'label' => 'Log in'];
require __DIR__ . '/../includes/layouts/auth_header.php';
?>

  <?php if ($scope === 'profile'): ?>
    <p class="auth-sub">We sent a 6-digit code to <strong><?= h($state['email']) ?></strong>.
      It expires in <?= $minutes ?> minutes.</p>
  <?php else: ?>
    <p class="auth-sub">If an account exists for <strong><?= h($state['email']) ?></strong>, we sent it a 6-digit
      code. It expires in <?= $minutes ?> minutes.</p>
  <?php endif; ?>

  <?php if (!empty($state['local_code'])): ?>
    <div class="alert alert-error">
      <strong><?= mail_enabled() ? 'The email could not be sent.' : 'Gmail is not set up on this server.' ?></strong>
      <p class="alert-note">
        So the code is shown here instead. It only ever appears for someone browsing from the server itself;
        a remote visitor sees only the message above.
        <?php if (mail_enabled()): ?>
          Gmail's reason is in <code>storage/mail.log</code>.
        <?php else: ?>
          To send real emails, copy <code>config/mail.local.example.php</code> to <code>config/mail.local.php</code>
          and fill it in.
        <?php endif; ?>
      </p>
      <div class="reset-code-box"><?= h($state['local_code']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <label for="code">6-digit code</label>
    <input type="text" id="code" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code"
      maxlength="12" spellcheck="false" required autofocus>
    <button type="submit" class="btn btn-primary btn-block btn-auth">Continue</button>
  </form>

  <form method="post" class="code-resend">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="resend">
    Didn&rsquo;t get it? Check your spam folder, or
    <button type="submit" class="link-button" data-resend-wait="<?= (int) $resendWait ?>">send a new code</button>.
    <?php if ($scope !== 'profile'): ?>
      <br><a href="<?= base_url($startPath) ?>">Use a different email address</a>
    <?php endif; ?>
  </form>

  <script>
    (function () {
      // Keep only digits as they are typed or pasted.
      var input = document.getElementById('code');
      input.addEventListener('input', function () {
        var digits = input.value.replace(/\D+/g, '').slice(0, 6);
        if (input.value !== digits) input.value = digits;
      });

      // "Send a new code" stays off until the server would accept it.
      var resend = document.querySelector('[data-resend-wait]');
      var wait = parseInt(resend.getAttribute('data-resend-wait'), 10) || 0;
      var label = resend.textContent;
      function tick() {
        if (wait <= 0) {
          resend.disabled = false;
          resend.textContent = label;
          return;
        }
        resend.disabled = true;
        resend.textContent = label + ' (' + wait + 's)';
        wait -= 1;
        setTimeout(tick, 1000);
      }
      tick();
    })();
  </script>

<?php require __DIR__ . '/../includes/layouts/auth_footer.php'; ?>
