<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$errors = [];
$old = ['full_name' => '', 'username' => '', 'email' => '', 'contact_number' => '', 'role' => 'boarder'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['full_name']      = trim($_POST['full_name'] ?? '');
    $old['username']       = trim($_POST['username'] ?? '');
    $old['email']          = trim($_POST['email'] ?? '');
    $old['contact_number'] = trim($_POST['contact_number'] ?? '');
    $old['role']           = $_POST['role'] ?? 'boarder';
    $password              = $_POST['password'] ?? '';
    $confirm               = $_POST['confirm_password'] ?? '';

    if ($old['full_name'] === '') $errors[] = 'Full name is required.';
    if ($old['username'] === '' || !preg_match('/^[a-zA-Z0-9_]{4,30}$/', $old['username'])) {
        $errors[] = 'Username must be 4-30 characters (letters, numbers, underscore only).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!in_array($old['role'], ['landlord', 'boarder'], true)) $errors[] = 'Invalid role selected.';

    if (!$errors) {
        $check = $pdo->prepare('SELECT user_id FROM users WHERE username = ? OR email = ?');
        $check->execute([$old['username'], $old['email']]);
        if ($check->fetch()) {
            $errors[] = 'That username or email is already registered.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (role, full_name, username, email, password_hash, contact_number, status)
             VALUES (?, ?, ?, ?, ?, ?, "active")'
        );
        $stmt->execute([
            $old['role'],
            $old['full_name'],
            $old['username'],
            $old['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $old['contact_number'],
        ]);
        flash_set('Account created! You can now log in.', 'success');
        redirect('auth/login.php');
    }
}

$pageTitle = 'Sign Up';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad">
  <h1>Create your account</h1>
  <p class="auth-sub">Join RoomEase as a landlord to list rooms, or as a boarder to browse them.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e) echo h($e) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>

    <label>I am a...</label>
    <div class="checkbox-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:16px;">
      <label><input type="radio" name="role" value="boarder" <?= $old['role']==='boarder'?'checked':'' ?>> Prospective Boarder</label>
      <label><input type="radio" name="role" value="landlord" <?= $old['role']==='landlord'?'checked':'' ?>> Landlord</label>
    </div>

    <label for="full_name">Full name</label>
    <input type="text" id="full_name" name="full_name" value="<?= h($old['full_name']) ?>" required>

    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= h($old['username']) ?>" required>

    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= h($old['email']) ?>" required>

    <label for="contact_number">Contact number</label>
    <input type="tel" id="contact_number" name="contact_number" value="<?= h($old['contact_number']) ?>">

    <div class="field-row">
      <div>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <div>
        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" required>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Create account</button>
  </form>

  <div class="auth-switch">Already have an account? <a href="<?= base_url('auth/login.php') ?>">Log in</a></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
