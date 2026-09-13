<?php
/**
 * Step 1 of the password reset: ask for an email address and issue a token.
 *
 * The response is deliberately the same whether or not the address belongs to
 * an account, so this page cannot be used to find out who is registered.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$submitted = false;
$email = '';
$localLink = null;   // only ever populated for requests from this machine
$mailSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');

    // Issuing a reset sends mail and writes a token, so it is throttled the
    // same way login is. The limit is counted before the account is looked
    // up, so a locked-out requester learns nothing about who is registered.
    $retryAfter = $email !== '' ? throttle_retry_after('reset', $email) : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($retryAfter > 0) {
        $error = 'Too many reset requests for that address. Please try again in '
            . format_wait($retryAfter) . '.';
    } else {
        $submitted = true;
        record_failed_attempt('reset', $email);

        // Deactivated accounts get no token, but the page says the same thing
        // either way so nothing about the account is revealed.
        $stmt = $pdo->prepare('SELECT user_id, first_name, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['is_active']) {
            $token = create_password_reset($user['user_id']);
            $url = password_reset_url($token);
            $mailSent = send_password_reset_email($email, $user['first_name'], $url);

            // A stock WAMP install has no mail server. Rather than leave the
            // flow dead, show the link directly, but only to someone sitting at
            // this machine. A remote visitor never sees it.
            if (!$mailSent && is_local_request()) {
                $localLink = $url;
            }
        }
    }
}

$pageTitle = 'Forgot Password';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad">
  <h1>Forgot your password?</h1>

  <?php if ($submitted): ?>
    <div class="alert alert-success">
      If an account exists for <strong><?= h($email) ?></strong>, a reset link has been sent to it.
      The link is valid for <?= password_reset_ttl_minutes() ?> minutes.
    </div>

    <?php if ($localLink !== null): ?>
      <div class="alert alert-error">
        <strong>No mail server on this machine.</strong>
        <p class="alert-note">This server could not send email, so the link is shown here instead. It only
          ever appears for someone browsing from the server itself &mdash; a remote visitor sees only the
          message above.</p>
        <div class="reset-link-box"><a href="<?= h($localLink) ?>"><?= h($localLink) ?></a></div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <p class="auth-sub">Enter the email address on your account and we will send you a link to choose a new
      password.</p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" value="<?= h($email) ?>" autocomplete="username" required autofocus>
      <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
    </form>
  <?php endif; ?>

  <div class="auth-switch"><a href="<?= base_url('auth/login.php') ?>">&larr; Back to log in</a></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
