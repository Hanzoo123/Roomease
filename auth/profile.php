<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';
require __DIR__ . '/../includes/core/google_auth.php';

require_login();

$userId = $_SESSION['user_id'];
$errors = [];
$success = '';

// Retrieve current user details
$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die('User not found.');
}

$old = [
    'first_name'   => $user['first_name'],
    'last_name'    => $user['last_name'],
    'email'        => $user['email'],
    'phone_number' => $user['phone_number'] ?? ''
];

$googleLinked = !empty($user['google_id']);

// An account made with Google has a password nobody knows, so "Change
// password" (which asks for the current one) is no use to it. Its owner gets
// the same emailed code as "Forgot password", sent to this account's own
// address. That code is the proof, exactly as it is for a reset.
$wantsPasswordCode = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password_code';
if ($wantsPasswordCode) {
    verify_csrf();

    if (!$googleLinked) {
        redirect('auth/profile.php');
    }

    $retryAfter = throttle_retry_after('reset', $user['email']);
    if ($retryAfter > 0) {
        flash_set('A code was already requested several times. Please try again in ' . format_wait($retryAfter) . '.', 'error');
        redirect('auth/profile.php');
    }
    record_failed_attempt('reset', $user['email']);

    // If sending fails, someone at this machine still gets the code on the
    // next page, as on "Forgot password"; anyone else is told it failed.
    if (issue_password_reset_code($user['email'], 'profile') === false && !is_local_request()) {
        unset($_SESSION['password_reset']);
        flash_set('The email could not be sent. Please try again later.', 'error');
        redirect('auth/profile.php');
    }
    redirect('auth/verify_code.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$wantsPasswordCode) {
    verify_csrf();

    $old['first_name']   = trim($_POST['first_name'] ?? '');
    $old['last_name']    = trim($_POST['last_name'] ?? '');
    $old['email']        = trim($_POST['email'] ?? '');
    $old['phone_number'] = trim($_POST['phone_number'] ?? '');
    
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Standard profile validation
    if ($old['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($old['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }

    // Check unique email
    if (!$errors) {
        $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ?');
        $check->execute([$old['email'], $userId]);
        if ($check->fetch()) {
            $errors[] = 'That email address is already in use by another account.';
        }
    }

    // Password change validation. The trigger is deliberately the NEW password
    // fields, not the current one: browsers autofill saved credentials into
    // "Current password", and that alone must not turn an ordinary profile
    // edit into a failed password change.
    $changePassword = false;
    if ($newPassword !== '' || $confirmPassword !== '') {
        $changePassword = true;
        if ($currentPassword === '') {
            $errors[] = 'Enter your current password to set a new one.';
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'Incorrect current password.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }
    }

    if (!$errors) {
        if ($changePassword) {
            $update = $pdo->prepare(
                'UPDATE users 
                 SET first_name = ?, last_name = ?, email = ?, phone_number = ?, password_hash = ?
                 WHERE user_id = ?'
            );
            $update->execute([
                $old['first_name'],
                $old['last_name'],
                $old['email'],
                $old['phone_number'] !== '' ? $old['phone_number'] : null,
                password_hash($newPassword, PASSWORD_DEFAULT),
                $userId
            ]);
        } else {
            $update = $pdo->prepare(
                'UPDATE users 
                 SET first_name = ?, last_name = ?, email = ?, phone_number = ?
                 WHERE user_id = ?'
            );
            $update->execute([
                $old['first_name'],
                $old['last_name'],
                $old['email'],
                $old['phone_number'] !== '' ? $old['phone_number'] : null,
                $userId
            ]);
        }

        // A password change invalidates every other copy of this session, so
        // anyone who had already got hold of the old session id loses it. This
        // is the whole point of changing the password after a scare.
        if ($changePassword) {
            session_regenerate_id(true);

            // Same for "Remember me": every remembered device is forgotten.
            // This device keeps being remembered if it already was.
            $rememberedHere = isset($_COOKIE[REMEMBER_COOKIE]);
            forget_all_remembered_logins($userId);
            if ($rememberedHere) {
                remember_login((int) $userId);
            }
        }

        // Update session info
        $_SESSION['first_name'] = $old['first_name'];
        $_SESSION['last_name']  = $old['last_name'];
        $_SESSION['full_name']  = trim($old['first_name'] . ' ' . $old['last_name']);
        $_SESSION['email']      = $old['email'];

        flash_set('Profile updated successfully.', 'success');
        redirect('auth/profile.php');
    }
}

$pageTitle = 'Edit Profile';

// Admins and landlords work inside the management panel, so their profile page
// renders there too. Boarders only ever see the public site, so theirs stays on
// the public theme. The form below is written once; only the class names differ.
$usePanel = is_admin() || current_role() === 'landlord';

$cls = $usePanel
    ? ['row' => 'form-row', 'col' => 'col-md-6 form-group', 'group' => 'form-group',
       'input' => 'form-control', 'hint' => 'form-text text-muted',
       'alert' => 'alert alert-danger', 'btn' => 'btn btn-primary',
       'note' => 'alert alert-light border', 'btn_small' => 'btn btn-sm btn-outline-secondary']
    : ['row' => 'field-row', 'col' => '', 'group' => '',
       'input' => '', 'hint' => 'field-hint',
       'alert' => 'alert alert-error', 'btn' => 'btn btn-primary btn-block',
       'note' => 'alert alert-success', 'btn_small' => 'btn btn-ghost btn-sm'];

if ($usePanel) {
    require __DIR__ . '/../includes/layouts/panel_head.php';
    require __DIR__ . '/../includes/layouts/panel_navbar.php';
    require __DIR__ . '/../includes/layouts/panel_sidebar.php';
} else {
    require __DIR__ . '/../includes/layouts/header.php';
}
?>

<?php if ($usePanel): ?>
  <div class="content-wrapper">
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 font-weight-bold">
              <i class="fas fa-user-cog text-primary mr-2"></i>My Profile
            </h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="<?= base_url(panel_config()['home']) ?>">Home</a></li>
              <li class="breadcrumb-item active">My Profile</li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <section class="content">
      <div class="container-fluid">
        <div class="row justify-content-center">
          <div class="col-lg-7">
            <div class="card card-primary card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-id-card mr-1"></i> Account Information
                </h3>
              </div>
              <div class="card-body">
<?php else: ?>
  <div class="auth-wrap auth-wrap--wide panel panel-pad on-seam">
    <h1>Edit Profile</h1>
    <p class="auth-sub">Manage your account information and change your password.</p>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="<?= $cls['alert'] ?>">
    <?php foreach ($errors as $e)
      echo h($e) . '<br>'; ?>
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <div class="<?= $cls['row'] ?>">
    <div class="<?= $cls['col'] ?>">
      <label for="first_name">First name</label>
      <input type="text" class="<?= $cls['input'] ?>" id="first_name" name="first_name"
        value="<?= h($old['first_name']) ?>" required>
    </div>
    <div class="<?= $cls['col'] ?>">
      <label for="last_name">Last name</label>
      <input type="text" class="<?= $cls['input'] ?>" id="last_name" name="last_name"
        value="<?= h($old['last_name']) ?>" required>
    </div>
  </div>

  <div class="<?= $cls['group'] ?>">
    <label for="email">Email address</label>
    <input type="email" class="<?= $cls['input'] ?>" id="email" name="email" value="<?= h($old['email']) ?>" required>
    <?php if ($googleLinked): ?>
      <?php /* Inline layout: this page renders on both the panel and the public theme. */ ?>
      <p class="<?= $cls['hint'] ?>" style="display:flex; align-items:center; gap:6px;">
        <?= google_logo_svg(14) ?> Connected to Google. You can sign in with &ldquo;Continue with Google&rdquo;.
      </p>
    <?php endif; ?>
  </div>

  <div class="<?= $cls['group'] ?>">
    <label for="phone_number">Phone number</label>
    <input type="tel" class="<?= $cls['input'] ?>" id="phone_number" name="phone_number"
      value="<?= h($old['phone_number']) ?>" placeholder="e.g. 09171234567">
  </div>

  <hr>

  <h5 class="font-weight-bold">Change Password</h5>

  <?php if ($googleLinked): ?>
    <div class="<?= $cls['note'] ?>">
      Signed up with Google? Then you have no password yet, and one would let you sign in without Google too.
      <div style="margin-top:8px;">
        <?php /* Submits the separate form below the profile form, so pressing Enter in a
             profile field still saves the profile rather than sending this code. */ ?>
        <button type="submit" form="password-code-form" class="<?= $cls['btn_small'] ?>">
          Email me a code to set a password
        </button>
      </div>
    </div>
  <?php endif; ?>

  <p class="<?= $cls['hint'] ?> mb-3">Leave these blank if you do not wish to change your password.</p>

  <div class="<?= $cls['group'] ?>">
    <label for="current_password">Current password</label>
    <input type="password" class="<?= $cls['input'] ?>" id="current_password" name="current_password"
      autocomplete="new-password">
  </div>

  <div class="<?= $cls['row'] ?>">
    <div class="<?= $cls['col'] ?>">
      <label for="new_password">New password</label>
      <input type="password" class="<?= $cls['input'] ?>" id="new_password" name="new_password"
      autocomplete="new-password">
    </div>
    <div class="<?= $cls['col'] ?>">
      <label for="confirm_password">Confirm new password</label>
      <input type="password" class="<?= $cls['input'] ?>" id="confirm_password" name="confirm_password"
      autocomplete="new-password">
    </div>
  </div>

  <button type="submit" class="<?= $cls['btn'] ?>" style="margin-top:8px;">
    <i class="fas fa-save mr-1"></i> Save Profile Changes
  </button>
</form>

<?php if ($googleLinked): ?>
  <form method="post" id="password-code-form" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password_code">
  </form>
<?php endif; ?>

<?php if ($usePanel): ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
  <?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
<?php else: ?>
  </div>
  <?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
<?php endif; ?>
