<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // Password change validation
    $changePassword = false;
    if ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '') {
        $changePassword = true;
        if ($currentPassword === '') {
            $errors[] = 'Current password is required to change password.';
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
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad" style="max-width: 500px; margin: 32px auto;">
  <h1>Edit Profile</h1>
  <p class="auth-sub">Manage your account information and change your password.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e) echo h($e) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="field-row">
      <div>
        <label for="first_name">First name</label>
        <input type="text" id="first_name" name="first_name" value="<?= h($old['first_name']) ?>" required>
      </div>
      <div>
        <label for="last_name">Last name</label>
        <input type="text" id="last_name" name="last_name" value="<?= h($old['last_name']) ?>" required>
      </div>
    </div>

    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= h($old['email']) ?>" required>

    <label for="phone_number">Phone number</label>
    <input type="tel" id="phone_number" name="phone_number" value="<?= h($old['phone_number']) ?>" placeholder="e.g. 09171234567">

    <hr style="border:0; border-top:1px dashed var(--line); margin: 24px 0;">
    
    <h3 style="font-size:16px; margin-bottom:12px;">Change Password</h3>
    <p class="field-hint" style="margin-top:-8px; margin-bottom:16px;">Leave password fields blank if you do not wish to change your password.</p>

    <label for="current_password">Current password</label>
    <input type="password" id="current_password" name="current_password">

    <div class="field-row">
      <div>
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password">
      </div>
      <div>
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password">
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">Save Profile Changes</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
