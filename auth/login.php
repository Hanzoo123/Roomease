<?php
/**
 * RoomEase login — one sign-in for every role, on the public theme.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// If already logged in, send them to their own landing page. index.php routes
// each role (admin panel, landlord panel, or browse), so that rule lives in
// exactly one place.
if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$loginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $loginId = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

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
        $stmt = $pdo->prepare(
            "SELECT * FROM users WHERE email = :login_id AND deleted_at IS NULL LIMIT 1"
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
            // A clean slate on every sign-in: a brand new session id, and
            // nothing carried over from whatever session existed before it.
            clear_failed_attempts('login', $loginId);
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['session_started_at'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
            $_SESSION['email'] = $user['email'];

            // No h() here: the toast escapes its own message now, and escaping
            // twice would show the raw entities to the user.
            flash_set("Welcome back, " . $user["first_name"] . "!", "success");
            // index.php sends each role to its own landing page.
            redirect('index.php');
        }
    }
}

/* The flash is rendered by includes/header.php, which calls flash_get() itself.
   Reading it here as well would consume the message before the header saw it. */
$pageTitle = 'Log in';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad on-seam">
  <h1>Log in</h1>
  <p class="auth-sub">Sign in to save rooms you like, manage your listings, or open your panel.</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>

    <label for="login_id">Email address</label>
    <input type="email" id="login_id" name="login_id" value="<?= h($loginId) ?>" autocomplete="username" required
      autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit" class="btn btn-primary btn-block">Log in</button>
  </form>

  <div class="auth-switch"><a href="<?= base_url('auth/forgot_password.php') ?>">Forgot your password?</a></div>
  <div class="auth-switch">New to RoomEase? <a href="<?= base_url('auth/register.php') ?>">Create an account</a></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
