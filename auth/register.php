<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone_number' => '', 'role' => 'boarder'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['first_name']   = trim($_POST['first_name'] ?? '');
    $old['last_name']    = trim($_POST['last_name'] ?? '');
    $old['email']        = trim($_POST['email'] ?? '');
    $old['phone_number'] = trim($_POST['phone_number'] ?? '');
    $old['role']         = $_POST['role'] ?? 'boarder';
    $password            = $_POST['password'] ?? '';
    $confirm             = $_POST['confirm_password'] ?? '';

    if ($old['first_name'] === '') $errors[] = 'First name is required.';
    if ($old['last_name'] === '') $errors[] = 'Last name is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!in_array($old['role'], ['landlord', 'boarder'], true)) $errors[] = 'Invalid role selected.';

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

$pageTitle = 'Sign Up';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad">
  <h1>Create your account</h1>
  <p class="auth-sub">Join RoomEase as a landlord to list rooms, or as a boarder to browse them in Baybay City.</p>

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
    <input type="email" id="email" name="email" value="<?= h($old['email']) ?>" required>

    <label for="phone_number">Phone number</label>
    <input type="tel" id="phone_number" name="phone_number" value="<?= h($old['phone_number']) ?>" placeholder="e.g. 09171234567">

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
