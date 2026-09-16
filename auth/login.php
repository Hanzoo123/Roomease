<?php
/**
 * RoomEase login — one sign-in for every role, on the standalone sign-in layout.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/core/google_auth.php';

// An administrator can open ?preview=1 from Appearance to see this page with
// the chosen background. Anyone else who is logged in goes to their own
// landing page; index.php routes each role, so that rule lives in one place.
$preview = is_logged_in() && is_admin() && isset($_GET['preview']);
if (is_logged_in() && !$preview) {
    redirect('index.php');
}

$error = '';
$loginId = '';
$remember = false;

if (!$preview && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $loginId = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = ($_POST['remember'] ?? '') === '1';

    // Counted per account and per source address, so neither guessing one
    // account's password nor spraying one common password across many
    // accounts is free to an attacker.
    $retryAfter = $loginId !== '' ? throttle_retry_after('login', $loginId) : 0;

    if (empty($loginId) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif ($retryAfter > 0) {
        $error = "Too many failed sign-in attempts. Please try again in "
            . format_wait($retryAfter) . ".";
    } else {
        // Administrators sign in at admin/login.php. Their accounts are left
        // out here, so this page answers an admin email exactly as it answers
        // an unknown one and never reveals that an admin account exists.
        $stmt = $pdo->prepare(
            "SELECT * FROM users
              WHERE email = :login_id AND deleted_at IS NULL AND role <> 'administrator'
              LIMIT 1"
        );
        $stmt->execute([':login_id' => $loginId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_failed_attempt('login', $loginId);
            $error = "Invalid email or password.";
        } elseif (empty($user['is_active'])) {
            record_failed_attempt('login', $loginId);
            $error = "Your account is deactivated. Please contact support.";
        } else {
            clear_failed_attempts('login', $loginId);
            start_user_session($user);
            if ($remember) {
                remember_login((int) $user['user_id']);
            }

            // No h() here: the flash is escaped where it is rendered, and
            // escaping twice would show the raw entities to the user.
            flash_set("Welcome back, " . $user["first_name"] . "!", "success");
            redirect('index.php');
        }
    }
}

$pageTitle = 'Log in';
$authHeading = 'Sign in to your account';
$authPreview = $preview;
$authSwitch = ['text' => 'New to RoomEase?', 'href' => base_url('auth/register.php'), 'label' => 'Create an account'];
require __DIR__ . '/../includes/layouts/auth_header.php';
?>

<?php /* Always shown. Until this server has Google credentials, auth/google_start.php
     brings the visitor back here with a message saying so. */ ?>
<?php if ($preview): ?>
  <span class="btn-google" aria-disabled="true"><?= google_logo_svg() ?> Continue with Google</span>
<?php else: ?>
  <a class="btn-google" href="<?= base_url('auth/google_start.php') ?>" data-google-start>
    <?= google_logo_svg() ?> Continue with Google
  </a>
<?php endif; ?>
<p class="auth-fineprint">
  By continuing, you agree to our <a href="<?= base_url('terms.php') ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>
  and <a href="<?= base_url('privacy.php') ?>" target="_blank" rel="noopener">Privacy Policy</a>.
  If you're new, you'll choose boarder or landlord next.
</p>
<hr class="auth-rule">

<?php if ($error): ?>
  <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" novalidate>
  <?php /* A disabled fieldset turns the whole form off in preview mode. */ ?>
  <fieldset class="auth-fields" <?= $preview ? 'disabled' : '' ?>>
    <?= csrf_field() ?>

    <label for="login_id">Email address</label>
    <input type="email" id="login_id" name="login_id" value="<?= h($loginId) ?>" autocomplete="username" required
      <?= $preview ? '' : 'autofocus' ?>>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <div class="auth-row">
      <label class="check-inline" for="remember">
        <input type="checkbox" id="remember" name="remember" value="1" <?= $remember ? 'checked' : '' ?>>
        Remember me
      </label>
      <a href="<?= base_url('auth/forgot_password.php') ?>">Forgot password?</a>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-auth">Sign in</button>
  </fieldset>
</form>

<script>
  // "Remember me" applies to Google sign-in too: it rides along on the link.
  (function () {
    var box = document.getElementById('remember');
    var google = document.querySelector('[data-google-start]');
    if (!box || !google) return;
    var base = google.getAttribute('href');
    function sync() {
      google.setAttribute('href', base + '?remember=' + (box.checked ? '1' : '0'));
    }
    box.addEventListener('change', sync);
    sync();
  })();
</script>

<?php require __DIR__ . '/../includes/layouts/auth_footer.php'; ?>
