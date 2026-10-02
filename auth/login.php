<?php
/** Login for landlords and boarders (admins use admin/login.php). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/google_auth.php';

// ?preview=1 lets an admin preview the background from Appearance.
// Anyone else already logged in goes to index.php.
$preview = is_logged_in() && is_admin() && isset($_GET['preview']);
if (is_logged_in() && !$preview) {
    redirect('index.php');
}

// A link from a listing ("Log in to see" the number) brings the visitor back
// to it afterwards. safe_return_path() inside refuses anything not ours.
if (!$preview && isset($_GET['next'])) {
    remember_after_login($_GET['next']);
}

$error = '';
$loginId = '';
$remember = false;

if (!$preview && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $loginId = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = ($_POST['remember'] ?? '') === '1';

    // Limit failed logins per account and per IP.
    $retryAfter = $loginId !== '' ? throttle_retry_after('login', $loginId) : 0;

    if (empty($loginId) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif ($retryAfter > 0) {
        audit_log('signin_failed', null, audit_typed_login($loginId), 'Public sign-in blocked: too many attempts', []);
        $error = "Too many failed sign-in attempts. Please try again in "
            . format_wait($retryAfter) . ".";
    } else {
        // Admin accounts are excluded, so an admin email looks like an unknown one.
        $stmt = $pdo->prepare(
            "SELECT * FROM users
              WHERE email = :login_id AND deleted_at IS NULL AND role <> 'administrator'
              LIMIT 1"
        );
        $stmt->execute([':login_id' => $loginId]);
        $user = $stmt->fetch();

        if (!check_login_password($password, $user)) {
            record_failed_attempt('login', $loginId);
            // An address with no account behind it is logged with no actor, so
            // the log shows what was typed without inventing who typed it.
            audit_log('signin_failed', $user ? $user['user_id'] : null, audit_typed_login($loginId),
                'Public sign-in: ' . ($user ? 'wrong password' : 'no such account'), $user ?: []);
            $error = "Invalid email or password.";
        } elseif (empty($user['is_active'])) {
            record_failed_attempt('login', $loginId);
            audit_log('signin_failed', $user['user_id'], $user['email'], 'Public sign-in: account deactivated', $user);
            $error = "Your account is deactivated. Please contact support.";
        } else {
            clear_failed_attempts('login', $loginId);
            // The session must start from the hash stored now, which the
            // upgrade may just have replaced; see start_user_session().
            $user['password_hash'] = upgrade_password_hash($user, $password);
            // Taken out first: start_user_session() empties the session.
            $after = take_after_login();
            start_user_session($user);
            audit_log('signin', $user['user_id'], $user['email'], 'Password');
            if ($remember) {
                remember_login((int) $user['user_id']);
            }

            // No h() here: the flash is escaped where it is rendered, and
            // escaping twice would show the raw entities to the user.
            redirect(complete_after_login($after, "Welcome back, " . $user["first_name"] . "!"));
        }
    }
}

// When a guest tapped Save, say so: they are here to save one listing, and
// they will be taken straight back to it.
$savingName = null;
$afterLogin = $_SESSION['after_login'] ?? null;
if (!$preview && is_array($afterLogin) && !empty($afterLogin['save'])) {
    $nameStmt = $pdo->prepare('SELECT name FROM boarding_houses WHERE boarding_house_id = ?');
    $nameStmt->execute([(int) $afterLogin['save']]);
    $savingName = $nameStmt->fetchColumn() ?: null;
}

$pageTitle = 'Log in';
$authHeading = 'Sign in to your account';
$authPreview = $preview;
$authSwitch = ['text' => 'New to RoomEase?', 'href' => base_url('auth/register.php'), 'label' => 'Create an account'];
require __DIR__ . '/../includes/layouts/auth_header.php';
?>

<?php if ($savingName): ?>
  <p class="auth-sub auth-context">
    Log in as a boarder to save <strong><?= h($savingName) ?></strong>. You'll go straight back to it.
  </p>
<?php endif; ?>

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
  By continuing, you agree to our <a href="<?= base_url('legal/terms.php') ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>
  and <a href="<?= base_url('legal/privacy.php') ?>" target="_blank" rel="noopener">Privacy Policy</a>.
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
