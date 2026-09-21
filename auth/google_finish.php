<?php
/**
 * Step 3 of "Continue with Google", only for someone new who started on the
 * sign-in page. Google has confirmed who they are, but not whether they are
 * looking for a room or renting rooms out, so the account is created once they
 * say. The sign-up page asks for the role before going to Google, so its
 * visitors never see this page.
 *
 * auth/google_callback.php leaves the confirmed Google profile in
 * $_SESSION['google_signup']. It lasts GOOGLE_SIGNUP_TTL and is used once.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/core/google_auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$pending = $_SESSION['google_signup'] ?? null;
if (!is_array($pending) || empty($pending['google_id'])
    || time() - (int) ($pending['started_at'] ?? 0) > GOOGLE_SIGNUP_TTL) {
    unset($_SESSION['google_signup']);
    flash_set('That Google sign-in expired. Please continue with Google again.', 'error');
    redirect('auth/login.php');
}

$errors = [];
$role = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $phone = trim(is_string($_POST['phone_number'] ?? null) ? $_POST['phone_number'] : '');

    if (!in_array($role, ['boarder', 'landlord'], true)) {
        $errors[] = 'Choose whether you are looking for a room or have rooms to rent.';
        $role = '';
    }
    if (mb_strlen($phone) > 30) {
        $errors[] = 'Phone number must be 30 characters or fewer.';
    }

    if (!$errors) {
        unset($_SESSION['google_signup']);

        // The same person may have finished in another tab meanwhile. Then
        // there is an account already, and they are signed in to it instead.
        [$user, $error, $linked] = google_find_account($pending['google_id'], $pending['email']);
        $created = false;
        if (!$user && !$error) {
            try {
                $user = google_create_account($pending, $role, $phone !== '' ? $phone : null);
                $created = true;
            } catch (PDOException $e) {
                [$user, $error, $linked] = google_find_account($pending['google_id'], $pending['email']);
                if (!$user && !$error) {
                    error_log('RoomEase: Google account creation failed - ' . $e->getMessage());
                    $error = 'Your account could not be created. Please continue with Google again.';
                }
            }
        }
        if ($user) {
            $error = google_sign_in($user, !empty($pending['remember']), $created, $linked);
        }

        flash_set($error, 'error');
        redirect('auth/login.php');
    }
}

$pageTitle = 'One more step';
$authHeading = 'One more step';
$authWide = true;
$authSwitch = ['text' => 'Not you?', 'href' => base_url('auth/google_start.php'), 'label' => 'Use a different Google account'];
require __DIR__ . '/../includes/layouts/auth_header.php';
?>

<p class="auth-sub">
  Google confirmed you as <strong><?= h($pending['email']) ?></strong>. Tell us how you will use RoomEase and
  your account is ready.
</p>

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
      <label><input type="radio" name="role" value="boarder" required <?= $role === 'boarder' ? 'checked' : '' ?>> Boarder looking for a room</label>
      <label><input type="radio" name="role" value="landlord" required <?= $role === 'landlord' ? 'checked' : '' ?>> Landlord with rooms to rent</label>
    </div>
  </fieldset>

  <label for="phone_number">Phone number (optional)</label>
  <input type="tel" id="phone_number" name="phone_number" value="<?= h($phone) ?>" maxlength="30"
    autocomplete="tel" placeholder="e.g. 09171234567">

  <button type="submit" class="btn btn-primary btn-block btn-auth">Create my account</button>
</form>

<?php require __DIR__ . '/../includes/layouts/auth_footer.php'; ?>
