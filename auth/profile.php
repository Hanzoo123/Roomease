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
    'full_name'      => $user['full_name'],
    'username'       => $user['username'],
    'email'          => $user['email'],
    'contact_number' => $user['contact_number']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['full_name']      = trim($_POST['full_name'] ?? '');
    $old['username']       = trim($_POST['username'] ?? '');
    $old['email']          = trim($_POST['email'] ?? '');
    $old['contact_number'] = trim($_POST['contact_number'] ?? '');
    
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Standard profile validation
    if ($old['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if ($old['username'] === '' || !preg_match('/^[a-zA-Z0-9_]{4,30}$/', $old['username'])) {
        $errors[] = 'Username must be 4-30 characters (letters, numbers, underscore only).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }

    // Check unique username & email
    if (!$errors) {
        $check = $pdo->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
        $check->execute([$old['username'], $old['email'], $userId]);
        if ($check->fetch()) {
            $errors[] = 'That username or email is already in use by another account.';
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
                 SET full_name = ?, username = ?, email = ?, contact_number = ?, password_hash = ?
                 WHERE user_id = ?'
            );
            $update->execute([
                $old['full_name'],
                $old['username'],
                $old['email'],
                $old['contact_number'],
                password_hash($newPassword, PASSWORD_DEFAULT),
                $userId
            ]);
        } else {
            $update = $pdo->prepare(
                'UPDATE users 
                 SET full_name = ?, username = ?, email = ?, contact_number = ?
                 WHERE user_id = ?'
            );
            $update->execute([
                $old['full_name'],
                $old['username'],
                $old['email'],
                $old['contact_number'],
                $userId
            ]);
        }

        // Update session name
        $_SESSION['full_name'] = $old['full_name'];
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

    <label for="full_name">Full name</label>
    <input type="text" id="full_name" name="full_name" value="<?= h($old['full_name']) ?>" required>

    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= h($old['username']) ?>" required>

    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= h($old['email']) ?>" required>

    <label for="contact_number">Contact number</label>
    <input type="tel" id="contact_number" name="contact_number" value="<?= h($old['contact_number']) ?>">

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
