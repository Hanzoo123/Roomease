<?php
/**
 * Edit Profile: the photo, the name, the email address and the phone number.
 *
 * The password has its own page (auth/change_password.php), so saving a new
 * phone number can never trip over a browser's autofilled password, and a
 * password change is never buried under a form of unrelated fields.
 */
require __DIR__ . '/../includes/init.php';
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

$old = [
  'first_name' => $user['first_name'],
  'last_name' => $user['last_name'],
  'email' => $user['email'],
  'phone_number' => $user['phone_number'] ?? '',
];

$googleLinked = !empty($user['google_id']);

// A photo can be larger than post_max_size, which makes PHP throw away $_POST
// and $_FILES entirely. Without this the page would report a CSRF failure
// rather than the real problem, exactly as admin/appearance.php guards against.
if (post_too_large()) {
  flash_set('That photo is larger than this server accepts in one upload (about '
    . format_bytes(ini_bytes(ini_get('post_max_size'))) . ').', 'error');
  redirect('auth/edit_profile.php');
}

// Removing the photo is its own small form, so it does not have to travel
// through the profile form's validation to take effect.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_photo') {
  verify_csrf();
  delete_avatar($user['avatar_path'] ?? null);
  $pdo->prepare('UPDATE users SET avatar_path = NULL, updated_by = ? WHERE user_id = ?')->execute([$userId, $userId]);
  $_SESSION['avatar_path'] = null;
  flash_set('Your photo was removed.', 'success');
  redirect('auth/edit_profile.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $old['first_name'] = trim($_POST['first_name'] ?? '');
  $old['last_name'] = trim($_POST['last_name'] ?? '');
  // A Google account's email is the one Google verified, and where its
  // password code is sent, so it is kept as it is whatever the form says.
  $old['email'] = $googleLinked ? $user['email'] : trim($_POST['email'] ?? '');
  $old['phone_number'] = trim($_POST['phone_number'] ?? '');

  if ($old['first_name'] === '') {
    $errors[] = 'First name is required.';
  }
  if ($old['last_name'] === '') {
    $errors[] = 'Last name is required.';
  }
  if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
  }
  $errors = array_merge($errors, array_filter([
    too_long($old['first_name'], 100, 'First name'),
    too_long($old['last_name'], 100, 'Last name'),
    too_long($old['email'], 150, 'Email'),
    $old['phone_number'] !== '' ? phone_problem($old['phone_number']) : null,
  ]));

  if (!$errors) {
    $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ?');
    $check->execute([$old['email'], $userId]);
    if ($check->fetch()) {
      $errors[] = 'That email address is already in use by another account.';
    }
  }

  // The photo is stored last, once the rest of the form is known to be good,
  // so a rejected form never leaves a file behind on disk.
  $newAvatar = null;
  if (!$errors) {
    try {
      $newAvatar = handle_avatar_upload('avatar', $userId);
    } catch (RuntimeException $e) {
      $errors[] = $e->getMessage();
    }
  }

  if (!$errors) {
    $columns = ['first_name = ?', 'last_name = ?', 'email = ?', 'phone_number = ?', 'updated_by = ?'];
    $values = [
      $old['first_name'],
      $old['last_name'],
      $old['email'],
      $old['phone_number'] !== '' ? $old['phone_number'] : null,
      $userId,
    ];
    if ($newAvatar !== null) {
      $columns[] = 'avatar_path = ?';
      $values[] = $newAvatar;
    }
    $values[] = $userId;

    $pdo->prepare('UPDATE users SET ' . implode(', ', $columns) . ' WHERE user_id = ?')->execute($values);

    // The old file goes only once the new path is safely saved, so a
    // failure above leaves the account with the photo it already had.
    if ($newAvatar !== null) {
      delete_avatar($user['avatar_path'] ?? null);
      $_SESSION['avatar_path'] = $newAvatar;
    }

    $_SESSION['first_name'] = $old['first_name'];
    $_SESSION['last_name'] = $old['last_name'];
    $_SESSION['full_name'] = trim($old['first_name'] . ' ' . $old['last_name']);
    $_SESSION['email'] = $old['email'];

    flash_set('Your profile was saved.', 'success');
    redirect('auth/profile.php');
  }
}

// avatar_html reads the row straight from the database rather than the
// session, so the picture below is the stored one.
$hasPhoto = is_avatar_path($user['avatar_path'] ?? null)
  && is_file(__DIR__ . '/../' . $user['avatar_path']);

$pageTitle = 'Edit Profile';
$profileSubtitle = 'Your photo, your name, and how people can reach you.';
$profileBack = ['href' => 'auth/profile.php', 'label' => 'Back to my profile'];
require __DIR__ . '/../includes/layouts/profile_top.php';
?>

<div class="<?= $usePanel ? 'card profile-card' : 'panel panel-pad on-seam profile-card' ?>">
  <?php if ($usePanel): ?>
    <div class="card-header">
      <h3 class="card-title">Your details</h3>
      <span class="card-subtitle">Changes take effect as soon as you save.</span>
    </div>
    <div class="card-body">
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="<?= $cls['alert'] ?>">
        <?php foreach ($errors as $e)
          echo h($e) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <div class="profile-photo">
        <div class="profile-photo-figure">
          <?= avatar_html($user, 96, $cls['avatar']) ?>
          <?php /* Hidden until a file is chosen, when the script below points it
        at the chosen file so the crop is not a surprise after saving. */ ?>
          <img class="profile-photo-preview" id="avatarPreview" alt="" hidden>
        </div>
        <div class="profile-photo-actions">
          <p class="profile-photo-title">Profile photo</p>
          <p class="profile-photo-hint">
            JPG, PNG or WEBP, up to <?= h(format_bytes(max_upload_bytes())) ?>.
            It is cropped to a square, so a head-and-shoulders photo works best.
          </p>
          <div class="profile-photo-buttons">
            <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp"
              class="profile-photo-input">
            <label class="<?= $cls['btn_small'] ?> profile-photo-pick" for="avatar">
              <?= $hasPhoto ? 'Change photo' : 'Choose photo' ?>
            </label>
            <?php if ($hasPhoto): ?>
              <?php /* Submits the separate form at the foot of the page, so this
              button cannot be tripped by pressing Enter in a text field. */ ?>
              <button type="submit" form="remove-photo-form" class="<?= $cls['btn_small'] ?>">
                Remove photo
              </button>
            <?php endif; ?>
            <span class="profile-photo-chosen" id="avatarChosen" hidden></span>
          </div>
        </div>
      </div>

      <div class="<?= $cls['row'] ?>">
        <div class="<?= $cls['col'] ?>">
          <label for="first_name">First name</label>
          <input type="text" class="<?= $cls['input'] ?>" id="first_name" name="first_name"
            value="<?= h($old['first_name']) ?>" autocomplete="given-name" required>
        </div>
        <div class="<?= $cls['col'] ?>">
          <label for="last_name">Last name</label>
          <input type="text" class="<?= $cls['input'] ?>" id="last_name" name="last_name"
            value="<?= h($old['last_name']) ?>" autocomplete="family-name" required>
        </div>
      </div>

      <div class="<?= $cls['group'] ?>">
        <label for="email">Email address</label>
        <?php if ($googleLinked): ?>
          <?php /* Read-only, not disabled: it is still announced and can be selected
          and copied, but it is not sent, and the server ignores it anyway. */ ?>
          <input type="email" class="<?= $cls['input'] ?> profile-locked" id="email" value="<?= h($user['email']) ?>"
            readonly aria-describedby="email-google-note">
          <p class="<?= $cls['hint'] ?> profile-google-note" id="email-google-note">
            <?= google_logo_svg(14) ?> Managed by your Google account, so it cannot be changed here.
          </p>
        <?php else: ?>
          <input type="email" class="<?= $cls['input'] ?>" id="email" name="email" value="<?= h($old['email']) ?>"
            autocomplete="email" required>
        <?php endif; ?>
      </div>

      <div class="<?= $cls['group'] ?>">
        <label for="phone_number">Phone number</label>
        <input type="tel" class="<?= $cls['input'] ?>" id="phone_number" name="phone_number"
          value="<?= h($old['phone_number']) ?>" placeholder="e.g. 09171234567" autocomplete="tel">
      </div>

      <div class="profile-form-actions">
        <a href="<?= base_url('auth/profile.php') ?>" class="<?= $cls['btn_quiet'] ?>">Cancel</a>
        <button type="submit" class="<?= $usePanel ? 'btn btn-primary' : 'btn btn-accent' ?>">Save changes</button>
      </div>
    </form>

    <?php if ($usePanel): ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($hasPhoto): ?>
  <?php /* The confirm is the only thing standing between a slip of the finger and
    a photo gone for good, so it asks in plain words. */ ?>
  <form method="post" id="remove-photo-form" hidden
    onsubmit="return confirm('Remove your photo? Your initials will show in its place.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_photo">
  </form>
<?php endif; ?>

<script>
  // Show the chosen file in place of the current photo before it is uploaded.
  // Purely a preview: the square crop still happens on the server.
  (function () {
    var input = document.getElementById('avatar');
    var preview = document.getElementById('avatarPreview');
    var chosen = document.getElementById('avatarChosen');
    if (!input || !preview) return;

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      if (preview.src) URL.revokeObjectURL(preview.src);
      preview.src = URL.createObjectURL(file);
      preview.hidden = false;
      preview.previousElementSibling.hidden = true;
      chosen.textContent = 'Ready to save';
      chosen.hidden = false;
    });
  })();
</script>

<?php require __DIR__ . '/../includes/layouts/profile_bottom.php'; ?>