<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$errors = [];
$oldUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $oldUsername = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$oldUsername, $oldUsername]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $errors[] = 'Incorrect username/email or password.';
    } elseif ($user['status'] !== 'active') {
        $errors[] = 'This account has been deactivated. Please contact the administrator.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];

        if ($user['role'] === 'admin') {
            redirect('admin/dashboard.php');
        } elseif ($user['role'] === 'landlord') {
            redirect('landlord/dashboard.php');
        } else {
            redirect('boarder/browse.php');
        }
    }
}

$pageTitle = 'Log In';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap panel panel-pad">
  <h1>Welcome back</h1>
  <p class="auth-sub">Log in to manage your listings or continue browsing.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e) echo h($e) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <label for="username">Username or email</label>
    <input type="text" id="username" name="username" value="<?= h($oldUsername) ?>" required autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit" class="btn btn-primary btn-block">Log in</button>
  </form>

  <div class="auth-switch">No account yet? <a href="<?= base_url('auth/register.php') ?>">Sign up</a></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
