<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/google_auth.php';

if (is_logged_in()) {
  redirect('index.php');
}

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone_number' => '', 'role' => 'boarder'];

// "List a property" links arrive with ?role=landlord so the right option is
// already chosen. Only the two self-service roles are honoured.
if (in_array($_GET['role'] ?? '', ['landlord', 'boarder'], true)) {
  $old['role'] = $_GET['role'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $old['first_name'] = trim($_POST['first_name'] ?? '');
  $old['last_name'] = trim($_POST['last_name'] ?? '');
  $old['email'] = trim($_POST['email'] ?? '');
  $old['phone_number'] = trim($_POST['phone_number'] ?? '');
  $old['role'] = $_POST['role'] ?? 'boarder';
  $password = $_POST['password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  if ($old['first_name'] === '')
    $errors[] = 'First name is required.';
  if ($old['last_name'] === '')
    $errors[] = 'Last name is required.';
  if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))
    $errors[] = 'A valid email is required.';
  if (strlen($password) < 8)
    $errors[] = 'Password must be at least 8 characters.';
  if ($password !== $confirm)
    $errors[] = 'Passwords do not match.';
  if (!in_array($old['role'], ['landlord', 'boarder'], true))
    $errors[] = 'Invalid role selected.';

  if (!$errors) {
    $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
    $check->execute([$old['email']]);
    if ($check->fetch()) {
      $errors[] = 'That email address is already registered.';
    }
  }

  if (!$errors) {
    $stmt = $pdo->prepare(
      'INSERT INTO users (role, first_name, last_name, email, password_hash, phone_number, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
      $old['role'],
      $old['first_name'],
      $old['last_name'],
      $old['email'],
      password_hash($password, PASSWORD_DEFAULT),
      $old['phone_number'] !== '' ? $old['phone_number'] : null,
    ]);
    flash_set('Account created successfully! You can now log in.', 'success');
    redirect('auth/login.php');
  }
}

$pageTitle = 'Sign up';
$authHeading = 'Create your RoomEase account';
$authWide = true;
$authSwitch = ['text' => 'Already have an account?', 'href' => base_url('auth/login.php'), 'label' => 'Log in'];
require __DIR__ . '/../includes/auth_header.php';
?>

<?php /* Always shown. Until this server has Google credentials, auth/google_start.php
     brings the visitor back here with a message saying so. */ ?>
<a class="btn-google" href="<?= base_url('auth/google_start.php?from=register') ?>" data-google-start>
  <?= google_logo_svg() ?> Sign up with Google
</a>
<p class="auth-fineprint">
  By continuing, you agree to our <a href="<?= base_url('terms.php') ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>
  and <a href="<?= base_url('privacy.php') ?>" target="_blank" rel="noopener">Privacy Policy</a>.
  Your account is created as the role you pick below.
</p>
<hr class="auth-rule">

<?php if ($errors): ?>
  <div class="alert alert-error">
    <?php foreach ($errors as $e)
      echo h($e) . '<br>'; ?>
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <fieldset class="auth-fields">
    <legend class="auth-legend">I am a...</legend>
    <div class="checkbox-grid role-choice">
      <label><input type="radio" name="role" value="boarder" <?= $old['role'] === 'boarder' ? 'checked' : '' ?>> Boarder looking for a room</label>
      <label><input type="radio" name="role" value="landlord" <?= $old['role'] === 'landlord' ? 'checked' : '' ?>> Landlord with rooms to rent</label>
    </div>
  </fieldset>

  <div class="field-row">
    <div>
      <label for="first_name">First name</label>
      <input type="text" id="first_name" name="first_name" value="<?= h($old['first_name']) ?>" required autofocus>
    </div>
    <div>
      <label for="last_name">Last name</label>
      <input type="text" id="last_name" name="last_name" value="<?= h($old['last_name']) ?>" required>
    </div>
  </div>

  <label for="email">Email address</label>
  <input type="email" id="email" name="email" value="<?= h($old['email']) ?>" autocomplete="email" required>

  <label for="phone_number">Phone number</label>
  <input type="tel" id="phone_number" name="phone_number" value="<?= h($old['phone_number']) ?>"
    autocomplete="tel" placeholder="e.g. 09171234567">

  <div class="field-row">
    <div>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required>
    </div>
    <div>
      <label for="confirm_password">Confirm password</label>
      <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
    </div>
  </div>

  <button type="submit" class="btn btn-primary btn-block btn-auth">Create account</button>
  <p class="auth-fineprint">
    By creating an account, you agree to our <a href="<?= base_url('terms.php') ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>
    and <a href="<?= base_url('privacy.php') ?>" target="_blank" rel="noopener">Privacy Policy</a>.
  </p>
</form>

<script>
  // The Google button creates the account in whichever role is picked.
  (function () {
    var google = document.querySelector('[data-google-start]');
    if (!google) return;
    var base = google.getAttribute('href');
    function sync() {
      var picked = document.querySelector('input[name="role"]:checked');
      google.setAttribute('href', base + '&role=' + (picked ? picked.value : 'boarder'));
    }
    Array.prototype.forEach.call(document.querySelectorAll('input[name="role"]'), function (radio) {
      radio.addEventListener('change', sync);
    });
    sync();
  })();
</script>

<?php require __DIR__ . '/../includes/auth_footer.php'; ?>
